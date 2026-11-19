<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/jobs.php';
require_once __DIR__ . '/notify.php';
require_once __DIR__ . '/audit.php';

/**
 * Change management for updates. Every scheduled update window and every
 * manual update / reboot / agent upgrade / service restart becomes a change
 * record (CHG-000123) with:
 *   - what was planned: the hosts and, for updates, the packages pending on
 *     each when the record was made;
 *   - who requested it and, when approval is on, who approved or rejected it;
 *   - the outcome per host (job status, summary, the end of its output) and,
 *     after the next inventory, which planned packages are still pending.
 *
 * Emails go to a separate change list (Settings -> Change management)
 * through the first enabled email channel: an advance notice a set number of
 * hours before each scheduled window, a notice when a manual change starts,
 * decisions, and a completion report.
 *
 * With approval turned on, a scheduled window only runs if an admin approved
 * its change record beforehand; otherwise it's skipped. A planned change can
 * always be rejected (vetoed), approval or not. Manual changes are started by
 * an admin, so they count as approved by that admin.
 *
 * cli/cron.php -> process_schedules() -> change_process() every minute.
 */

const CHANGE_JOB_TYPES = ['updates', 'reboot', 'agent_upgrade', 'service_restart'];   // 'refresh' changes nothing
const CHANGE_DEFAULTS = ['recipients' => '', 'lead_hours' => 24, 'require_approval' => false, 'notify_manual' => true];
const CHANGE_OPEN = ['planned', 'pending_approval', 'approved'];
const CHANGE_STATUS_LABELS = [
    'planned' => 'Planned', 'pending_approval' => 'Awaiting approval', 'approved' => 'Approved', 'rejected' => 'Rejected',
    'in_progress' => 'In progress', 'completed' => 'Completed', 'partial' => 'Partly completed', 'failed' => 'Failed',
    'skipped' => 'Not run', 'cancelled' => 'Cancelled',
];
const CHANGE_VERIFY_MAX_SEC = 12 * 3600;   // stop waiting for fresh inventories after this
const CHANGE_OUTPUT_KEEP = 8000;           // end of each job's output kept on the record

function change_number(int $id): string {
    return sprintf('CHG-%06d', $id);
}

function change_settings(): array {
    $raw = db()->query("SELECT v FROM settings WHERE k = 'change_mgmt'")->fetchColumn();
    $saved = $raw ? (json_decode($raw, true) ?: []) : [];
    return [
        'recipients' => (string) ($saved['recipients'] ?? CHANGE_DEFAULTS['recipients']),
        'lead_hours' => max(0, min(720, (int) ($saved['lead_hours'] ?? CHANGE_DEFAULTS['lead_hours']))),
        'require_approval' => (bool) ($saved['require_approval'] ?? CHANGE_DEFAULTS['require_approval']),
        'notify_manual' => (bool) ($saved['notify_manual'] ?? CHANGE_DEFAULTS['notify_manual']),
    ];
}

/** Validates and saves; throws InvalidArgumentException. */
function save_change_settings(array $in): array {
    $cur = change_settings();
    if (array_key_exists('recipients', $in)) {
        $list = array_values(array_unique(array_filter(array_map('trim', preg_split('/[,;\s]+/', (string) $in['recipients'])))));
        foreach ($list as $addr) {
            if (!filter_var($addr, FILTER_VALIDATE_EMAIL)) throw new InvalidArgumentException("\"$addr\" isn't an email address.");
        }
        $cur['recipients'] = implode(', ', $list);
    }
    if (array_key_exists('lead_hours', $in)) {
        $h = (int) $in['lead_hours'];
        if ($h < 0 || $h > 720) throw new InvalidArgumentException('Notice must be 0-720 hours.');
        $cur['lead_hours'] = $h;
    }
    foreach (['require_approval', 'notify_manual'] as $k) {
        if (array_key_exists($k, $in)) $cur[$k] = (bool) $in[$k];
    }
    $before = change_settings();
    db()->prepare("INSERT INTO settings (k, v) VALUES ('change_mgmt', ?) ON DUPLICATE KEY UPDATE v = VALUES(v)")
        ->execute([json_encode($cur)]);
    // Open changes follow the approval setting: switched on, planned windows
    // now need approval; switched off, those waiting no longer do.
    if ($cur['require_approval'] !== $before['require_approval']) {
        [$from, $to] = $cur['require_approval'] ? ['planned', 'pending_approval'] : ['pending_approval', 'planned'];
        db()->prepare("UPDATE change_records SET status = ? WHERE status = ? AND source = 'schedule'")->execute([$to, $from]);
    }
    return $cur;
}

function fmt_change_time(int $ms): string {
    return date('D j M Y H:i T', intdiv($ms, 1000));
}

/** Host label for messages. */
function change_host_label(array $h): string {
    return $h['name'] === $h['ip_address'] ? $h['ip_address'] : "{$h['name']} ({$h['ip_address']})";
}

/**
 * What a change will do on each host: {hostId: {host, packages: [...] | null,
 * pendingTotal, pendingSecurity}}. Packages are the updates pending in the
 * host's last inventory (only security ones for a security-only run).
 */
function change_plan(array $hostIds, string $jobType, array $params): array {
    $hostIds = array_values(array_unique(array_filter(array_map('intval', $hostIds))));
    if (!$hostIds) return [];
    $in = implode(',', $hostIds);
    $rows = db()->query("SELECT h.id, h.name, h.ip_address, a.inventory FROM hosts h LEFT JOIN agents a ON a.host_id = h.id
                         WHERE h.id IN ($in) ORDER BY h.name")->fetchAll();
    $out = [];
    foreach ($rows as $r) {
        $entry = ['host' => change_host_label($r)];
        if ($jobType === 'updates') {
            $inv = json_decode((string) $r['inventory'], true) ?: [];
            $pk = is_array($inv['updates']['packages'] ?? null) ? $inv['updates']['packages'] : null;
            if ($pk !== null) {
                $entry['pendingTotal'] = count($pk);
                $entry['pendingSecurity'] = count(array_filter($pk, fn($p) => !empty($p['security'])));
                if (!empty($params['security_only'])) $pk = array_values(array_filter($pk, fn($p) => !empty($p['security'])));
                $entry['packages'] = array_map(fn($p) => array_filter([
                    'name' => mb_substr((string) ($p['name'] ?? '?'), 0, 200),
                    'current' => $p['current'] ?? null,
                    'new' => $p['new'] ?? null,
                    'kb' => $p['kb'] ?? null,
                    'security' => !empty($p['security']),
                ], fn($v) => $v !== null && $v !== ''), array_slice($pk, 0, 1000));
            } else {
                $entry['packages'] = null;   // no inventory yet
            }
        }
        $out[(string) $r['id']] = $entry;
    }
    return $out;
}

function change_get(int $id): ?array {
    $q = db()->prepare('SELECT * FROM change_records WHERE id = ?');
    $q->execute([$id]);
    return $q->fetch() ?: null;
}

/** Record for the browser. */
function change_row(array $c, bool $full = false): array {
    $params = json_decode($c['params'], true) ?: [];
    $planned = json_decode($c['planned'], true) ?: [];
    $row = [
        'id' => (int) $c['id'],
        'number' => change_number((int) $c['id']),
        'source' => $c['source'],
        'scheduleId' => $c['schedule_id'] !== null ? (int) $c['schedule_id'] : null,
        'title' => $c['title'],
        'jobType' => $c['job_type'],
        'action' => job_label($c['job_type'], $params),
        'plannedAt' => (int) $c['planned_at'],
        'status' => $c['status'],
        'statusLabel' => CHANGE_STATUS_LABELS[$c['status']] ?? $c['status'],
        'hostCount' => count(json_decode($c['host_ids'], true) ?: []),
        'hosts' => array_values(array_map(fn($p) => $p['host'], $planned)),
        'packageCount' => array_sum(array_map(fn($p) => is_array($p['packages'] ?? null) ? count($p['packages']) : 0, $planned)),
        'requestedBy' => $c['requested_name'],
        'decidedBy' => $c['decided_name'],
        'decidedAt' => $c['decided_at'] !== null ? (int) $c['decided_at'] : null,
        'decisionNote' => $c['decision_note'],
        'createdAt' => (int) $c['created_at'],
        'startedAt' => $c['started_at'] !== null ? (int) $c['started_at'] : null,
        'finishedAt' => $c['finished_at'] !== null ? (int) $c['finished_at'] : null,
        'verifiedAt' => $c['verified_at'] !== null ? (int) $c['verified_at'] : null,
        'noticeSentAt' => $c['notice_sent_at'] !== null ? (int) $c['notice_sent_at'] : null,
        'reportSentAt' => $c['report_sent_at'] !== null ? (int) $c['report_sent_at'] : null,
    ];
    if ($full) {
        $row['planned'] = $planned;
        $row['outcome'] = json_decode((string) $c['outcome'], true) ?: null;
        $row['skipped'] = json_decode((string) $c['skipped'], true) ?: [];
        $row['verification'] = json_decode((string) $c['verification'], true) ?: null;
        $row['jobIds'] = array_map('intval', json_decode($c['job_ids'], true) ?: []);
        $row['history'] = array_map('audit_row', db()->query(
            "SELECT * FROM audit_log WHERE action LIKE 'change.%' AND target = " . db()->quote(change_number((int) $c['id'])) . ' ORDER BY id')->fetchAll());
    }
    return $row;
}

function change_user_name(?int $userId): ?string {
    if ($userId === null) return null;
    $q = db()->prepare('SELECT username FROM users WHERE id = ?');
    $q->execute([$userId]);
    return $q->fetchColumn() ?: null;
}

function change_set(int $id, array $fields): void {
    $sets = implode(', ', array_map(fn($k) => "$k = ?", array_keys($fields)));
    db()->prepare("UPDATE change_records SET $sets WHERE id = ?")->execute([...array_values($fields), $id]);
}

// ---------------------------------------------------------------- creating

/**
 * The change record for one occurrence of a schedule, made (and announced)
 * if it doesn't exist yet. Returns the row.
 */
function change_for_occurrence(array $sched, DateTimeImmutable $occ): array {
    $key = $occ->format('Y-m-d H:i');
    $q = db()->prepare('SELECT * FROM change_records WHERE schedule_id = ? AND occurrence_key = ?');
    $q->execute([$sched['id'], $key]);
    if ($c = $q->fetch()) return $c;

    $settings = change_settings();
    $params = json_decode($sched['params'], true) ?: [];
    $hostIds = schedule_host_ids($sched);
    $requester = $sched['created_by'] !== null ? (int) $sched['created_by'] : null;
    $status = $settings['require_approval'] ? 'pending_approval' : 'planned';
    db()->prepare('INSERT IGNORE INTO change_records (source, schedule_id, occurrence_key, title, job_type, params, planned_at, status,
                   host_ids, planned, job_ids, requested_by, requested_name, created_at)
                   VALUES (\'schedule\', ?, ?, ?, ?, ?, ?, ?, ?, ?, \'[]\', ?, ?, ?)')
        ->execute([$sched['id'], $key, mb_substr($sched['name'], 0, 120), $sched['job_type'], $sched['params'],
                   $occ->getTimestamp() * 1000, $status, json_encode($hostIds),
                   json_encode((object) change_plan($hostIds, $sched['job_type'], $params)),
                   $requester, change_user_name($requester), now_ms()]);
    $q->execute([$sched['id'], $key]);
    $c = $q->fetch();
    $num = change_number((int) $c['id']);
    audit_log('change.created', "$num raised for scheduled window \"{$sched['name']}\" at " . fmt_change_time((int) $c['planned_at'])
        . ' on ' . count($hostIds) . ' host(s)' . ($status === 'pending_approval' ? ', awaiting approval' : ''),
        $num, ['scheduleId' => (int) $sched['id'], 'hostIds' => $hostIds, 'status' => $status], 'ok', null, true);
    change_send_notice($c);
    return change_get((int) $c['id']);
}

/**
 * A change record for jobs an admin (or a schedule's "Run now") just
 * queued. $res is create_jobs()'s result. Nothing is recorded for job types
 * that change nothing, or if no job was created.
 */
function change_record_manual(array $res, string $type, array $params, ?array $user, string $title,
                              ?int $scheduleId = null, ?int $runId = null): ?int {
    if (!in_array($type, CHANGE_JOB_TYPES, true) || !$res['created']) return null;
    $ids = implode(',', array_map('intval', $res['created']));
    $hostIds = array_map('intval', db()->query("SELECT host_id FROM jobs WHERE id IN ($ids)")->fetchAll(PDO::FETCH_COLUMN));
    $now = now_ms();
    $settings = change_settings();
    db()->prepare('INSERT INTO change_records (source, schedule_id, schedule_run_id, title, job_type, params, planned_at, status, host_ids,
                   planned, job_ids, skipped, requested_by, requested_name, decided_by, decided_name, decided_at, decision_note,
                   created_at, started_at) VALUES (\'manual\', ?, ?, ?, ?, ?, ?, \'in_progress\', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute([$scheduleId, $runId, mb_substr($title, 0, 120), $type, json_encode(job_params($type, $params)), $now,
                   json_encode($hostIds), json_encode((object) change_plan($hostIds, $type, $params)), json_encode($res['created']),
                   json_encode($res['skipped']), $user['id'] ?? null, $user['username'] ?? null,
                   $user['id'] ?? null, $user['username'] ?? null, $now,
                   $settings['require_approval'] ? 'Manual change: approved by the admin who started it' : null, $now, $now]);
    $id = (int) db()->lastInsertId();
    $num = change_number($id);
    audit_log('change.created', "$num: " . job_label($type, $params) . ' started on ' . count($hostIds) . ' host(s) ("' . $title . '")',
        $num, ['jobIds' => $res['created'], 'hostIds' => $hostIds, 'skipped' => $res['skipped']], 'ok', $user);
    if ($settings['notify_manual']) change_send_notice(change_get($id));
    return $id;
}

// ---------------------------------------------------------------- decisions

/**
 * Approve or reject an open change. Throws InvalidArgumentException when the
 * change can't take that decision.
 */
function change_decide(int $id, string $decision, string $note, array $user): array {
    $c = change_get($id);
    if (!$c) throw new InvalidArgumentException('Change not found.');
    $num = change_number($id);
    if ($decision === 'approve') {
        if ($c['status'] !== 'pending_approval') throw new InvalidArgumentException("$num isn't waiting for approval.");
        $status = 'approved';
    } elseif ($decision === 'reject') {
        if (!in_array($c['status'], CHANGE_OPEN, true)) throw new InvalidArgumentException("$num has already run or been closed.");
        $status = 'rejected';
    } else {
        throw new InvalidArgumentException('Unknown decision.');
    }
    if ($status === 'rejected' && trim($note) === '') throw new InvalidArgumentException('Give a reason for rejecting it.');
    $note = mb_substr(trim($note), 0, 500);
    change_set($id, ['status' => $status, 'decided_by' => $user['id'], 'decided_name' => $user['username'],
                     'decided_at' => now_ms(), 'decision_note' => $note !== '' ? $note : null]);
    $self = $c['requested_by'] !== null && (int) $c['requested_by'] === (int) $user['id'];
    audit_log('change.' . $status, "$num " . ($status === 'approved' ? 'approved' : 'rejected') . " by {$user['username']}"
        . ($self ? ' (also the requester)' : '') . ($note !== '' ? ": $note" : ''), $num, ['note' => $note ?: null], 'ok', $user);
    $c = change_get($id);
    change_email("[$num] " . ($status === 'approved' ? 'Approved' : 'Rejected — will not run') . ": {$c['title']}",
        change_header($c) . "\n" . ($status === 'approved' ? 'Approved' : 'Rejected') . " by {$user['username']}"
        . ($note !== '' ? "\nNote: $note" : '') . "\n", $num);
    return $c;
}

// ---------------------------------------------------------------- running

/**
 * Called by process_schedules() at a window's time, before running it.
 * Returns ['run' => bool, 'change' => row]: a rejected change, or one still
 * waiting for approval, doesn't run (and that is recorded and emailed).
 */
function change_gate(array $sched, DateTimeImmutable $occ): array {
    $c = change_for_occurrence($sched, $occ);
    $num = change_number((int) $c['id']);
    $reason = null;
    if ($c['status'] === 'rejected') {
        $reason = 'it was rejected' . ($c['decided_name'] ? " by {$c['decided_name']}" : '');
    } elseif ($c['status'] === 'pending_approval' || ($c['status'] === 'planned' && change_settings()['require_approval'])) {
        $reason = 'it was not approved before the window';
    }
    if ($reason === null) return ['run' => true, 'change' => $c];

    if ($c['status'] !== 'rejected') change_set((int) $c['id'], ['status' => 'skipped', 'finished_at' => now_ms()]);
    audit_log('change.not_run', "$num: window \"{$sched['name']}\" did not run because $reason", $num, null, 'ok', null, true);
    change_email("[$num] Not run: {$c['title']}", change_header(change_get((int) $c['id'])) . "\nThe window did not run because $reason.\n", $num);
    return ['run' => false, 'change' => $c];
}

/** Links a scheduled change to the jobs its window just queued. */
function change_started(array $c, int $runId, array $res): void {
    $num = change_number((int) $c['id']);
    $ids = array_map('intval', $res['created']);
    $hostIds = $ids ? array_map('intval', db()->query('SELECT host_id FROM jobs WHERE id IN (' . implode(',', $ids) . ')')->fetchAll(PDO::FETCH_COLUMN)) : [];
    $now = now_ms();
    $fields = ['schedule_run_id' => $runId, 'job_ids' => json_encode($ids), 'skipped' => json_encode($res['skipped']), 'started_at' => $now];
    // Hosts are re-resolved at run time (groups, "All Linux"); keep the plan in step.
    $planned = json_decode($c['planned'], true) ?: [];
    $missing = array_diff($hostIds, array_map('intval', array_keys($planned)));
    if ($missing) {
        $planned += change_plan($missing, $c['job_type'], json_decode($c['params'], true) ?: []);
        $fields['planned'] = json_encode((object) $planned);
    }
    $fields['host_ids'] = json_encode($hostIds);
    if ($ids) {
        $fields['status'] = 'in_progress';
    } else {
        $fields['status'] = 'skipped';
        $fields['finished_at'] = $now;
    }
    change_set((int) $c['id'], $fields);
    audit_log($ids ? 'change.started' : 'change.not_run', $ids ? "$num started on " . count($ids) . ' host(s)'
        : "$num: nothing ran (" . ($res['skipped'] ? 'every host was skipped' : 'no matching hosts') . ')', $num,
        ['jobIds' => $ids, 'skipped' => $res['skipped']], 'ok', null, true);
    if (!$ids) change_email("[$num] Not run: {$c['title']}", change_header(change_get((int) $c['id'])) . "\nNothing ran.\n"
        . change_skipped_text($res['skipped']), $num);
}

/** Cron, every minute: notices, stale windows, finished changes, verification. */
function change_process(): void {
    $pdo = db();
    $settings = change_settings();
    $now = new DateTimeImmutable();

    // 1. Advance notices: raise the record once a window is within the notice period.
    if ($settings['lead_hours'] > 0) {
        foreach ($pdo->query('SELECT * FROM update_schedules WHERE enabled = 1')->fetchAll() as $s) {
            if (!in_array($s['job_type'], CHANGE_JOB_TYPES, true)) continue;
            $next = schedule_next_run($s, $now);
            if ($next && $next->getTimestamp() - $now->getTimestamp() <= $settings['lead_hours'] * 3600) change_for_occurrence($s, $next);
        }
    }

    // 2. Open changes whose window passed without running (schedule edited, disabled or deleted, server down).
    $late = now_ms() - (SCHEDULE_GRACE_MIN + 5) * 60000;
    foreach ($pdo->query("SELECT * FROM change_records WHERE status IN ('planned', 'pending_approval', 'approved') AND planned_at < $late")->fetchAll() as $c) {
        $num = change_number((int) $c['id']);
        change_set((int) $c['id'], ['status' => 'cancelled', 'finished_at' => now_ms()]);
        audit_log('change.cancelled', "$num cancelled: its window passed without running (the schedule was changed, disabled or deleted, or the server was down)",
            $num, null, 'ok', null, true);
        change_email("[$num] Cancelled: {$c['title']}", change_header(change_get((int) $c['id']))
            . "\nThe window passed without running: the schedule was changed, disabled or deleted, or the server was down.\n", $num);
    }

    // 3. Changes whose jobs have all finished: record the outcome and send the report.
    foreach ($pdo->query("SELECT * FROM change_records WHERE status = 'in_progress'")->fetchAll() as $c) {
        $ids = array_map('intval', json_decode($c['job_ids'], true) ?: []);
        $jobs = $ids ? $pdo->query(JOB_SELECT . ' WHERE j.id IN (' . implode(',', $ids) . ')')->fetchAll() : [];
        if (array_filter($jobs, fn($j) => in_array($j['status'], ['queued', 'running'], true))) continue;
        change_finish($c, $jobs);
    }

    // 4. Verification: which planned packages are still pending in each host's next inventory.
    foreach ($pdo->query("SELECT * FROM change_records WHERE job_type = 'updates' AND status IN ('completed', 'partial')
                          AND verified_at IS NULL")->fetchAll() as $c) {
        change_verify($c);
    }
}

function change_finish(array $c, array $jobs): void {
    $num = change_number((int) $c['id']);
    $outcome = [];
    foreach ($jobs as $j) {
        $out = (string) $j['output'];
        $outcome[(string) $j['host_id']] = [
            'host' => change_host_label(['name' => $j['host_name'], 'ip_address' => $j['ip_address']]),
            'jobId' => (int) $j['id'], 'status' => $j['status'], 'summary' => $j['summary'],
            'exitCode' => $j['exit_code'] !== null ? (int) $j['exit_code'] : null,
            'startedAt' => $j['started_at'] !== null ? (int) $j['started_at'] : null,
            'finishedAt' => $j['finished_at'] !== null ? (int) $j['finished_at'] : null,
            'output' => strlen($out) > CHANGE_OUTPUT_KEEP ? "[…]\n" . substr($out, -CHANGE_OUTPUT_KEEP) : $out,
            'outputSha256' => $out !== '' ? hash('sha256', $out) : null,
        ];
    }
    $ok = count(array_filter($outcome, fn($o) => $o['status'] === 'succeeded'));
    $skipped = json_decode((string) $c['skipped'], true) ?: [];
    $status = $ok === 0 ? 'failed' : ($ok === count($outcome) && !$skipped ? 'completed' : 'partial');
    change_set((int) $c['id'], ['status' => $status, 'outcome' => json_encode((object) $outcome), 'finished_at' => now_ms()]);
    $summary = "$ok of " . count($outcome) . ' succeeded' . ($skipped ? ', ' . count($skipped) . ' skipped' : '');
    audit_log('change.' . $status, "$num " . strtolower(CHANGE_STATUS_LABELS[$status]) . ": $summary", $num,
        ['results' => array_map(fn($o) => ['host' => $o['host'], 'status' => $o['status'], 'summary' => $o['summary'],
                                           'outputSha256' => $o['outputSha256']], array_values($outcome))], 'ok', null, true);
    $c = change_get((int) $c['id']);
    change_send_report($c, $summary);
}

function change_verify(array $c): void {
    $outcome = json_decode((string) $c['outcome'], true) ?: [];
    $planned = json_decode($c['planned'], true) ?: [];
    $ver = json_decode((string) $c['verification'], true) ?: [];
    $pending = 0;
    foreach ($outcome as $hid => $o) {
        if (isset($ver[$hid]) || $o['status'] !== 'succeeded') continue;
        $q = db()->prepare('SELECT inventory, inventory_at FROM agents WHERE host_id = ?');
        $q->execute([(int) $hid]);
        $a = $q->fetch();
        if (!$a || (int) $a['inventory_at'] <= (int) $o['finishedAt']) {
            $pending++;
            continue;
        }
        $inv = json_decode((string) $a['inventory'], true) ?: [];
        $now = array_map(fn($p) => (string) ($p['name'] ?? ''), is_array($inv['updates']['packages'] ?? null) ? $inv['updates']['packages'] : []);
        $want = array_map(fn($p) => $p['name'], is_array($planned[$hid]['packages'] ?? null) ? $planned[$hid]['packages'] : []);
        $ver[$hid] = ['checkedAt' => (int) $a['inventory_at'], 'stillPending' => array_values(array_intersect($want, $now)),
                      'plannedCount' => count($want)];
    }
    $done = $pending === 0 || now_ms() - (int) $c['finished_at'] > CHANGE_VERIFY_MAX_SEC * 1000;
    $fields = ['verification' => json_encode((object) $ver)];
    if ($done) $fields['verified_at'] = now_ms();
    change_set((int) $c['id'], $fields);
    if ($done) {
        $num = change_number((int) $c['id']);
        $left = array_sum(array_map(fn($v) => count($v['stillPending']), $ver));
        audit_log('change.verified', "$num verified against " . count($ver) . " host inventor" . (count($ver) === 1 ? 'y' : 'ies')
            . ": $left planned package(s) still pending" . ($pending ? ", $pending host(s) didn't report in time" : ''), $num,
            ['verification' => $ver], 'ok', null, true);
    }
}

// ---------------------------------------------------------------- email

/** The first enabled email channel's settings, sending to the change list. */
function change_email_channel(): ?array {
    $to = change_settings()['recipients'];
    if ($to === '') return null;
    $ch = db()->query("SELECT * FROM notification_channels WHERE type = 'email' AND enabled = 1 ORDER BY id LIMIT 1")->fetch();
    if (!$ch) return null;
    $cfg = json_decode((string) $ch['config'], true) ?: [];
    $cfg['to'] = $to;
    return ['id' => (int) $ch['id'], 'cfg' => $cfg];
}

/** Sends to the change list; returns null or an error. Logged in the audit trail either way. */
function change_email(string $subject, string $body, ?string $num = null): ?string {
    $ch = change_email_channel();
    if (!$ch) return change_settings()['recipients'] === '' ? 'no change list set' : 'no enabled email channel';
    $err = smtp_send($ch['cfg'], $subject, $body . "\n-- \nSent by WebMonitor change management");
    log_notification($ch['id'], null, $err === null, ($err ?? 'sent') . ' (change list)');
    audit_log('change.email', ($err === null ? 'Emailed' : 'Could not email') . " the change list: $subject" . ($err ? " — $err" : ''),
        $num, ['to' => $ch['cfg']['to']], $err === null ? 'ok' : 'failed', null, true);
    return $err;
}

function change_header(array $c): string {
    $params = json_decode($c['params'], true) ?: [];
    $lines = [
        'Change:       ' . change_number((int) $c['id']),
        'Status:       ' . (CHANGE_STATUS_LABELS[$c['status']] ?? $c['status']),
        'Title:        ' . $c['title'],
        'Action:       ' . job_label($c['job_type'], $params),
        ($c['source'] === 'schedule' ? 'Window:       ' : 'Started:      ') . fmt_change_time((int) $c['planned_at']),
        'Requested by: ' . ($c['requested_name'] ?: 'WebMonitor') . ($c['source'] === 'schedule' ? ' (scheduled)' : ' (manual)'),
    ];
    if ($c['decided_name']) {
        $lines[] = 'Decision:     ' . ($c['status'] === 'rejected' ? 'Rejected' : 'Approved') . " by {$c['decided_name']}"
            . ($c['decided_at'] ? ' at ' . fmt_change_time((int) $c['decided_at']) : '')
            . ($c['decision_note'] ? " — {$c['decision_note']}" : '');
    }
    return implode("\n", $lines) . "\n";
}

function change_plan_text(array $c): string {
    $planned = json_decode($c['planned'], true) ?: [];
    if (!$planned) return "Hosts: none matched yet (worked out again when the window runs).\n";
    $out = 'Hosts (' . count($planned) . "):\n";
    foreach ($planned as $p) {
        $out .= "  - {$p['host']}";
        if (array_key_exists('packages', $p)) {
            if ($p['packages'] === null) {
                $out .= ": no update inventory yet\n";
                continue;
            }
            $n = count($p['packages']);
            $sec = count(array_filter($p['packages'], fn($x) => !empty($x['security'])));
            $out .= ": $n update" . ($n === 1 ? '' : 's') . ($sec ? " ($sec security)" : '') . "\n";
            foreach (array_slice($p['packages'], 0, 40) as $x) {
                $out .= '      ' . $x['name'] . (isset($x['new']) ? ' ' . (isset($x['current']) ? "{$x['current']} → " : '') . $x['new'] : '')
                    . (isset($x['kb']) ? " ({$x['kb']})" : '') . (!empty($x['security']) ? ' [security]' : '') . "\n";
            }
            if ($n > 40) $out .= '      … and ' . ($n - 40) . " more (full list in WebMonitor)\n";
        } else {
            $out .= "\n";
        }
    }
    return $out;
}

function change_skipped_text(array $skipped): string {
    return $skipped ? "Skipped:\n" . implode("\n", array_map(fn($s) => "  - {$s['host']}: {$s['reason']}", $skipped)) . "\n" : '';
}

function change_send_notice(array $c): void {
    $num = change_number((int) $c['id']);
    $when = $c['source'] === 'schedule' ? 'Planned' : 'Started';
    $body = change_header($c) . "\n" . change_plan_text($c);
    if ($c['skipped']) $body .= change_skipped_text(json_decode((string) $c['skipped'], true) ?: []);
    if ($c['status'] === 'pending_approval') {
        $body .= "\nThis change needs approval before " . fmt_change_time((int) $c['planned_at'])
            . " or it won't run. Approve or reject it in WebMonitor: Updates → Change records.\n";
    } elseif ($c['source'] === 'schedule') {
        $body .= "\nTo stop it, reject it in WebMonitor (Updates → Change records) or turn the schedule off.\n";
    }
    if (change_email("[$num] $when change: {$c['title']} — " . fmt_change_time((int) $c['planned_at']), $body, $num) === null) {
        change_set((int) $c['id'], ['notice_sent_at' => now_ms()]);
    }
}

function change_send_report(array $c, string $summary): void {
    $num = change_number((int) $c['id']);
    $outcome = json_decode((string) $c['outcome'], true) ?: [];
    $body = change_header($c) . 'Finished:     ' . fmt_change_time((int) $c['finished_at']) . "\nResult:       $summary\n\n";
    foreach ($outcome as $o) {
        $body .= ($o['status'] === 'succeeded' ? '  ✔ ' : '  ✘ ') . "{$o['host']}: " . ($o['summary'] ?: $o['status'])
            . ($o['status'] !== 'succeeded' ? " ({$o['status']})" : '') . "\n";
    }
    $body .= change_skipped_text(json_decode((string) $c['skipped'], true) ?: []);
    $body .= "\nPlanned:\n" . change_plan_text($c);
    if (change_email("[$num] " . CHANGE_STATUS_LABELS[$c['status']] . ": {$c['title']} — $summary", $body, $num) === null) {
        change_set((int) $c['id'], ['report_sent_at' => now_ms()]);
    }
}
