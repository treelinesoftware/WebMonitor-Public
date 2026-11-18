<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/agents.php';

/**
 * Jobs: actions that agents run on their hosts (Phase 3: updates).
 *
 * Only these types exist, and each agent implements them itself; the
 * server sends a type plus a couple of booleans, never commands.
 *   refresh        check for updates now (apt-get update / dnf makecache /
 *                  online Windows Update scan), then send a fresh inventory
 *   updates        install updates; params: security_only, reboot (if needed)
 *   reboot         reboot the host
 *   agent_upgrade  download the current agent from this server and restart
 *   service_restart restart one watched service; params: service (agents only
 *                  restart services they've been told to watch)
 *
 * Agents ask for work (POST api/agent.php?action=jobs) and stream progress
 * back (action=job_update). Hosts are muted while a job runs, and briefly
 * after a reboot, so planned work doesn't raise alerts.
 */

const JOB_TYPES = ['refresh', 'updates', 'reboot', 'agent_upgrade', 'service_restart'];
const JOB_MIN_AGENT_VERSION = '0.3.0';
const JOB_OUTPUT_MAX = 65536;            // keep the last 64 KB of output
const JOB_QUEUED_TTL_SEC = 24 * 3600;    // never picked up -> expired
const JOB_STALL_SEC = 60 * 60;           // running but silent -> failed
const JOB_REBOOT_MUTE_MIN = 20;

function job_params(string $type, array $in): array {
    if ($type === 'updates') {
        return ['security_only' => !empty($in['security_only']), 'reboot' => !empty($in['reboot'])];
    }
    if ($type === 'service_restart') {
        $name = (string) ($in['service'] ?? '');
        return preg_match('/^[A-Za-z0-9@._:\-+ ]{1,120}$/', $name) ? ['service' => $name] : [];
    }
    return [];
}

function job_label(string $type, array $params): string {
    switch ($type) {
        case 'refresh': return 'Check for updates';
        case 'reboot': return 'Reboot';
        case 'agent_upgrade': return 'Upgrade agent';
        case 'service_restart': return 'Restart ' . ($params['service'] ?? 'service');
        case 'updates':
            return (!empty($params['security_only']) ? 'Install security updates' : 'Install all updates')
                . (!empty($params['reboot']) ? ', reboot if needed' : '');
    }
    return $type;
}

function job_row(array $j, bool $withOutput = false): array {
    $params = json_decode((string) $j['params'], true) ?: [];
    $row = [
        'id' => (int) $j['id'],
        'hostId' => (int) $j['host_id'],
        'hostName' => $j['host_name'] ?? null,
        'hostIp' => $j['ip_address'] ?? null,
        'type' => $j['type'],
        'params' => $params,
        'label' => job_label($j['type'], $params),
        'status' => $j['status'],
        'createdBy' => $j['username'] ?? null,
        'scheduleName' => $j['schedule_name'] ?? null,
        'createdAt' => (int) $j['created_at'],
        'startedAt' => $j['started_at'] !== null ? (int) $j['started_at'] : null,
        'updatedAt' => $j['updated_at'] !== null ? (int) $j['updated_at'] : null,
        'finishedAt' => $j['finished_at'] !== null ? (int) $j['finished_at'] : null,
        'exitCode' => $j['exit_code'] !== null ? (int) $j['exit_code'] : null,
        'summary' => $j['summary'],
        'hasOutput' => ($j['output'] ?? '') !== '',
    ];
    if ($withOutput) $row['output'] = (string) $j['output'];
    return $row;
}

const JOB_SELECT = 'SELECT j.*, h.name AS host_name, h.ip_address, u.username, s.name AS schedule_name
                    FROM jobs j JOIN hosts h ON h.id = j.host_id LEFT JOIN users u ON u.id = j.created_by
                    LEFT JOIN update_schedules s ON s.id = j.schedule_id';

/**
 * Queues a job on each host. Returns ['created' => [jobId...],
 * 'skipped' => [['hostId', 'host', 'reason']...]]. Jobs started by a
 * schedule ($scheduleId) skip hosts a person has muted for maintenance.
 */
function create_jobs(array $hostIds, string $type, array $params, ?int $userId, ?int $scheduleId = null): array {
    $pdo = db();
    $created = [];
    $skipped = [];
    $params = job_params($type, $params);
    $agents = agent_summaries();
    foreach (array_unique(array_map('intval', $hostIds)) as $hid) {
        $stmt = $pdo->prepare('SELECT id, name, ip_address, muted_until, mute_reason FROM hosts WHERE id = ?');
        $stmt->execute([$hid]);
        $h = $stmt->fetch();
        if (!$h) continue;
        $label = $h['name'] === $h['ip_address'] ? $h['ip_address'] : $h['name'];
        if ($scheduleId !== null && (int) $h['muted_until'] > now_ms()
            && !str_starts_with((string) $h['mute_reason'], JOB_MUTE_PREFIX)) {
            $skipped[] = ['hostId' => $hid, 'host' => $label,
                          'reason' => 'muted for maintenance' . ($h['mute_reason'] ? " ({$h['mute_reason']})" : '')];
            continue;
        }
        $a = $agents[$hid] ?? null;
        if (!$a) {
            $skipped[] = ['hostId' => $hid, 'host' => $label, 'reason' => 'no agent installed'];
            continue;
        }
        if (!$a['version'] || version_compare($a['version'], JOB_MIN_AGENT_VERSION, '<')) {
            $skipped[] = ['hostId' => $hid, 'host' => $label,
                          'reason' => "agent {$a['version']} is too old for jobs — reinstall it once (Details → Agent → Reinstall)"];
            continue;
        }
        $busy = $pdo->prepare("SELECT id FROM jobs WHERE host_id = ? AND status IN ('queued', 'running') LIMIT 1");
        $busy->execute([$hid]);
        if ($busy->fetchColumn()) {
            $skipped[] = ['hostId' => $hid, 'host' => $label, 'reason' => 'another job is already queued or running'];
            continue;
        }
        $pdo->prepare('INSERT INTO jobs (host_id, type, params, created_by, schedule_id, created_at) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([$hid, $type, json_encode($params), $userId, $scheduleId, now_ms()]);
        $created[] = (int) $pdo->lastInsertId();
    }
    return ['created' => $created, 'skipped' => $skipped];
}

/** For an agent asking for work: the oldest queued job, now marked running. */
function next_job_for_host(int $hostId): ?array {
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("SELECT * FROM jobs WHERE host_id = ? AND status = 'queued' ORDER BY id LIMIT 1 FOR UPDATE");
        $stmt->execute([$hostId]);
        $job = $stmt->fetch();
        if (!$job) {
            $pdo->commit();
            return null;
        }
        $now = now_ms();
        $pdo->prepare("UPDATE jobs SET status = 'running', started_at = ?, updated_at = ? WHERE id = ?")
            ->execute([$now, $now, $job['id']]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    $params = json_decode($job['params'], true) ?: [];
    if ($job['type'] === 'reboot' || $job['type'] === 'updates') {
        // Updates restart services; reboots take the host down. Mute until
        // the job finishes (extended after a reboot, see finish).
        job_mute($hostId, (int) $job['id'], $job['type'] === 'reboot' ? JOB_REBOOT_MUTE_MIN : 180,
                 job_label($job['type'], $params));
    }
    return ['id' => (int) $job['id'], 'type' => $job['type'], 'params' => $params];
}

/**
 * Progress from the agent. $status: running | succeeded | failed.
 * Output chunks are appended (the last JOB_OUTPUT_MAX bytes are kept).
 * $rebooting: the agent is about to reboot the host.
 */
function update_job(int $hostId, int $jobId, string $status, string $chunk, ?int $exitCode, ?string $summary, bool $rebooting): bool {
    $pdo = db();
    $stmt = $pdo->prepare("SELECT * FROM jobs WHERE id = ? AND host_id = ? AND status = 'running'");
    $stmt->execute([$jobId, $hostId]);
    $job = $stmt->fetch();
    if (!$job || !in_array($status, ['running', 'succeeded', 'failed'], true)) return false;

    $output = (string) $job['output'] . $chunk;
    if (strlen($output) > JOB_OUTPUT_MAX) $output = "[…earlier output trimmed…]\n" . substr($output, -JOB_OUTPUT_MAX);
    $now = now_ms();
    $final = $status !== 'running';
    $pdo->prepare('UPDATE jobs SET status = ?, output = ?, updated_at = ?, finished_at = ?, exit_code = COALESCE(?, exit_code),
                   summary = COALESCE(?, summary) WHERE id = ?')
        ->execute([$status, $output, $now, $final ? $now : null, $exitCode,
                   $summary !== null ? mb_substr($summary, 0, 500) : null, $jobId]);
    if ($final) job_unmute($hostId, $jobId, $rebooting || $job['type'] === 'reboot');
    return true;
}

const JOB_MUTE_PREFIX = 'Job #';

/** Mutes the host for a job, unless a person muted it for longer. */
function job_mute(int $hostId, int $jobId, int $minutes, string $label): void {
    $until = now_ms() + $minutes * 60000;
    db()->prepare('UPDATE hosts SET muted_until = ?, mute_reason = ?
                   WHERE id = ? AND (muted_until IS NULL OR muted_until < ? OR mute_reason LIKE ?)')
        ->execute([$until, mb_substr(JOB_MUTE_PREFIX . "$jobId: $label", 0, 120), $hostId, $until, JOB_MUTE_PREFIX . '%']);
}

/** Ends a job's mute; after a reboot keeps it a while so the host can come back. */
function job_unmute(int $hostId, int $jobId, bool $rebooting): void {
    db()->prepare('UPDATE hosts SET muted_until = ?, mute_reason = IF(? IS NULL, NULL, mute_reason)
                   WHERE id = ? AND mute_reason LIKE ?')
        ->execute([$rebooting ? now_ms() + JOB_REBOOT_MUTE_MIN * 60000 : null,
                   $rebooting ? 1 : null, $hostId, JOB_MUTE_PREFIX . $jobId . ':%']);
}

/** Expires never-collected jobs and fails jobs whose agent went silent. Run by cron. */
function job_housekeeping(): void {
    $pdo = db();
    $now = now_ms();
    $pdo->prepare("UPDATE jobs SET status = 'expired', finished_at = ?, summary = 'Not picked up by the agent within 24 hours'
                   WHERE status = 'queued' AND created_at < ?")
        ->execute([$now, $now - JOB_QUEUED_TTL_SEC * 1000]);
    $stale = $pdo->prepare("SELECT id, host_id FROM jobs WHERE status = 'running' AND COALESCE(updated_at, started_at) < ?");
    $stale->execute([$now - JOB_STALL_SEC * 1000]);
    foreach ($stale->fetchAll() as $j) {
        $pdo->prepare("UPDATE jobs SET status = 'failed', finished_at = ?,
                       summary = 'The agent stopped reporting progress for an hour' WHERE id = ?")
            ->execute([$now, $j['id']]);
        job_unmute((int) $j['host_id'], (int) $j['id'], false);
    }
    $pdo->prepare('DELETE FROM jobs WHERE finished_at IS NOT NULL AND finished_at < ?')->execute([$now - 180 * 86400000]);
}
