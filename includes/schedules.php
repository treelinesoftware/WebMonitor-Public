<?php
require_once __DIR__ . '/jobs.php';
require_once __DIR__ . '/notify.php';
require_once __DIR__ . '/changes.php';

/**
 * Update windows: recurring jobs (e.g. security updates every Sunday at
 * 03:00, server time). cli/cron.php calls process_schedules() every minute.
 *
 * A schedule fires at most once per occurrence (last_run_key). If the
 * server was down at that moment it still fires up to SCHEDULE_GRACE_MIN
 * late; later than that the occurrence is skipped rather than running
 * updates at an unexpected hour. When every job of a run has finished, one
 * summary notification is sent.
 */

const SCHEDULE_JOB_TYPES = ['updates', 'refresh', 'reboot'];
const SCHEDULE_TARGETS = ['all', 'linux', 'windows', 'hosts', 'groups'];
const SCHEDULE_GRACE_MIN = 30;
const DAY_NAMES = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'];

function day_bit(int $isoDay): int {
    return 1 << ($isoDay - 1);   // Mon=1 ... Sun=64
}

function describe_days(int $days): string {
    if ($days === 127) return 'Every day';
    if ($days === 31) return 'Weekdays';
    if ($days === 96) return 'Weekends';
    $names = [];
    foreach (DAY_NAMES as $n => $name) if ($days & day_bit($n)) $names[] = $name;
    return implode(', ', $names);
}

/** Occurrence of $sched at $day (DateTimeImmutable, any time that day), as DateTimeImmutable. */
function occurrence_on(array $sched, DateTimeImmutable $day): DateTimeImmutable {
    [$h, $m] = array_map('intval', explode(':', $sched['at_time']));
    return $day->setTime($h, $m);
}

/** Next occurrence after now, or null if no days are set. */
function schedule_next_run(array $sched, ?DateTimeImmutable $now = null): ?DateTimeImmutable {
    $now = $now ?? new DateTimeImmutable();
    for ($d = 0; $d <= 7; $d++) {
        $day = $now->modify("+$d day");
        $occ = occurrence_on($sched, $day);
        if (($sched['days'] & day_bit((int) $day->format('N'))) && $occ > $now) return $occ;
    }
    return null;
}

/** The most recent occurrence at or before now (within the last week), or null. */
function schedule_last_occurrence(array $sched, DateTimeImmutable $now): ?DateTimeImmutable {
    for ($d = 0; $d <= 7; $d++) {
        $day = $now->modify("-$d day");
        $occ = occurrence_on($sched, $day);
        if (($sched['days'] & day_bit((int) $day->format('N'))) && $occ <= $now) return $occ;
    }
    return null;
}

/** Host ids a schedule targets right now (target groups are evaluated at run time). */
function schedule_host_ids(array $sched): array {
    if ($sched['targets'] === 'hosts') {
        return array_values(array_filter(array_map('intval', json_decode($sched['host_ids'], true) ?: [])));
    }
    if ($sched['targets'] === 'groups') {
        // Cards in the groups right now that have an agent (no agent = nothing to update).
        $agents = agent_summaries();
        $ids = array_values(array_filter(hosts_in_groups(json_decode((string) ($sched['group_ids'] ?? ''), true) ?: []),
            fn($hid) => isset($agents[$hid])));
        sort($ids);
        return $ids;
    }
    $ids = [];
    foreach (agent_summaries() as $hid => $a) {
        if ($sched['targets'] === 'all' || $a['platform'] === $sched['targets']) $ids[] = $hid;
    }
    return $ids;
}

function schedule_row(array $s): array {
    $params = json_decode($s['params'], true) ?: [];
    $next = $s['enabled'] ? schedule_next_run($s) : null;
    $run = db()->prepare('SELECT * FROM schedule_runs WHERE schedule_id = ? ORDER BY id DESC LIMIT 1');
    $run->execute([$s['id']]);
    $last = $run->fetch();
    return [
        'id' => (int) $s['id'],
        'name' => $s['name'],
        'enabled' => (bool) $s['enabled'],
        'jobType' => $s['job_type'],
        'params' => $params,
        'label' => job_label($s['job_type'], $params),
        'days' => (int) $s['days'],
        'daysLabel' => describe_days((int) $s['days']),
        'atTime' => $s['at_time'],
        'targets' => $s['targets'],
        'hostIds' => array_map('intval', json_decode($s['host_ids'], true) ?: []),
        'groupIds' => array_map('intval', json_decode((string) ($s['group_ids'] ?? ''), true) ?: []),
        'nextRun' => $next ? $next->getTimestamp() * 1000 : null,
        'lastRun' => $last ? [
            'startedAt' => (int) $last['started_at'],
            'manual' => (bool) $last['manual'],
            'finishedAt' => $last['finished_at'] !== null ? (int) $last['finished_at'] : null,
            'summary' => $last['summary'],
            'jobs' => count(json_decode($last['job_ids'], true) ?: []),
            'skipped' => json_decode($last['skipped'], true) ?: [],
        ] : null,
    ];
}

/** Validates schedule fields from the browser; throws InvalidArgumentException. */
function schedule_fields(array $in): array {
    $name = trim((string) ($in['name'] ?? ''));
    if ($name === '' || mb_strlen($name) > 60) throw new InvalidArgumentException('Give the schedule a name (up to 60 characters).');
    $type = (string) ($in['jobType'] ?? '');
    if (!in_array($type, SCHEDULE_JOB_TYPES, true)) throw new InvalidArgumentException('Choose what the schedule should do.');
    $days = (int) ($in['days'] ?? 0);
    if ($days < 1 || $days > 127) throw new InvalidArgumentException('Pick at least one day.');
    $time = (string) ($in['atTime'] ?? '');
    if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time)) throw new InvalidArgumentException('Time must be HH:MM (24-hour).');
    $targets = (string) ($in['targets'] ?? 'hosts');
    if (!in_array($targets, SCHEDULE_TARGETS, true)) throw new InvalidArgumentException('Unknown target.');
    $hostIds = array_values(array_unique(array_filter(array_map('intval', (array) ($in['hostIds'] ?? [])))));
    if ($targets === 'hosts' && !$hostIds) throw new InvalidArgumentException('Pick at least one host.');
    $groupIds = array_values(array_unique(array_filter(array_map('intval', (array) ($in['groupIds'] ?? [])))));
    if ($groupIds) {
        $known = array_map('intval', db()->query('SELECT id FROM card_groups')->fetchAll(PDO::FETCH_COLUMN));
        $groupIds = array_values(array_intersect($groupIds, $known));
    }
    if ($targets === 'groups' && !$groupIds) throw new InvalidArgumentException('Pick at least one group.');
    return [
        'name' => $name, 'job_type' => $type, 'params' => json_encode(job_params($type, (array) ($in['params'] ?? []))),
        'days' => $days, 'at_time' => $time, 'targets' => $targets,
        'host_ids' => json_encode($targets === 'hosts' ? $hostIds : []),
        'group_ids' => json_encode($targets === 'groups' ? $groupIds : []),
        'enabled' => array_key_exists('enabled', $in) ? (int) (bool) $in['enabled'] : 1,
    ];
}

/**
 * Queues the schedule's jobs now and records the run. $change: the window's
 * change record (scheduled runs); a manual "Run now" gets its own record,
 * requested by $user.
 */
function run_schedule(array $sched, bool $manual = false, ?array $change = null, ?array $user = null): array {
    $params = json_decode($sched['params'], true) ?: [];
    $res = create_jobs(schedule_host_ids($sched), $sched['job_type'], $params,
                       $sched['created_by'] !== null ? (int) $sched['created_by'] : null, (int) $sched['id']);
    $now = now_ms();
    $summary = null;
    $finished = null;
    if (!$res['created']) {   // nothing to wait for
        $finished = $now;
        $summary = $res['skipped'] ? 'Nothing ran: every host was skipped' : 'Nothing ran: no matching hosts';
    }
    db()->prepare('INSERT INTO schedule_runs (schedule_id, started_at, manual, job_ids, skipped, finished_at, summary) VALUES (?, ?, ?, ?, ?, ?, ?)')
        ->execute([$sched['id'], $now, $manual ? 1 : 0, json_encode($res['created']), json_encode($res['skipped']), $finished, $summary]);
    $runId = (int) db()->lastInsertId();
    if ($change !== null) {
        change_started($change, $runId, $res);
    } else {
        $res['change'] = change_record_manual($res, $sched['job_type'], $params, $user, "Run now: {$sched['name']}", (int) $sched['id'], $runId);
    }
    audit_log('schedule.run', ($manual ? 'Ran' : 'Window ran for') . " schedule \"{$sched['name']}\": " . count($res['created'])
        . ' job(s) queued, ' . count($res['skipped']) . ' skipped', "schedule #{$sched['id']}",
        ['jobIds' => $res['created'], 'skipped' => $res['skipped']], 'ok', $user, !$manual);
    if ($finished !== null && !$manual) {
        notify_all("WebMonitor: scheduled \"{$sched['name']}\" didn't run", $summary . "\n" . skipped_lines($res['skipped']), 'warning');
    }
    return $res;
}

function skipped_lines(array $skipped): string {
    return implode("\n", array_map(fn($s) => "⏭ {$s['host']}: {$s['reason']}", $skipped));
}

/** Fires due schedules and reports finished runs. Called every minute by cron. */
function process_schedules(): void {
    $pdo = db();
    if (!(int) $pdo->query("SELECT GET_LOCK('webmonitor_schedules', 0)")->fetchColumn()) return;
    try {
        $now = new DateTimeImmutable();
        foreach ($pdo->query('SELECT * FROM update_schedules WHERE enabled = 1')->fetchAll() as $s) {
            $occ = schedule_last_occurrence($s, $now);
            if ($occ === null) continue;
            $key = $occ->format('Y-m-d H:i');
            if ($s['last_run_key'] === $key) continue;
            // Record the occurrence first so a slow run can't fire twice.
            $pdo->prepare('UPDATE update_schedules SET last_run_key = ? WHERE id = ?')->execute([$key, $s['id']]);
            $lateMin = ($now->getTimestamp() - $occ->getTimestamp()) / 60;
            if ($lateMin > SCHEDULE_GRACE_MIN) continue;   // missed (server down); wait for the next one
            if (in_array($s['job_type'], CHANGE_JOB_TYPES, true)) {
                $gate = change_gate($s, $occ);   // change record: approved / not rejected?
                if ($gate['run']) run_schedule($s, false, $gate['change']);
            } else {
                run_schedule($s);
            }
        }
        report_finished_runs();
        change_process();
    } finally {
        $pdo->query("SELECT RELEASE_LOCK('webmonitor_schedules')");
    }
}

/** Sends one summary per schedule run once all its jobs are done. */
function report_finished_runs(): void {
    $pdo = db();
    $runs = $pdo->query('SELECT r.*, s.name FROM schedule_runs r JOIN update_schedules s ON s.id = r.schedule_id
                         WHERE r.finished_at IS NULL')->fetchAll();
    foreach ($runs as $r) {
        $ids = array_map('intval', json_decode($r['job_ids'], true) ?: []);
        if (!$ids) continue;
        $jobs = $pdo->query(JOB_SELECT . ' WHERE j.id IN (' . implode(',', $ids) . ')')->fetchAll();
        if (array_filter($jobs, fn($j) => in_array($j['status'], ['queued', 'running'], true))) continue;

        $counts = array_count_values(array_column($jobs, 'status'));
        $ok = $counts['succeeded'] ?? 0;
        $bad = count($jobs) - $ok;
        $summary = "$ok succeeded" . ($bad ? ", $bad failed or didn't run" : '');
        $skipped = json_decode($r['skipped'], true) ?: [];
        if ($skipped) $summary .= ', ' . count($skipped) . ' skipped';
        $pdo->prepare('UPDATE schedule_runs SET finished_at = ?, summary = ? WHERE id = ?')->execute([now_ms(), $summary, $r['id']]);

        $lines = [];
        foreach ($jobs as $j) {
            $host = $j['host_name'] === $j['ip_address'] ? $j['ip_address'] : $j['host_name'];
            $icon = $j['status'] === 'succeeded' ? '✅' : '❌';
            $lines[] = "$icon $host: " . ($j['summary'] ?: $j['status']);
        }
        if ($skipped) $lines[] = skipped_lines($skipped);
        notify_all("WebMonitor: \"{$r['name']}\" finished — $summary", implode("\n", $lines), $bad ? 'critical' : 'info');
    }
}

// ---------------------------------------------------------------- conflicts

const SCHEDULE_OVERLAP_MIN = 120;   // runs closer than this may still be going
const WEEK_MIN = 7 * 1440;

/** Minute-of-week (Mon 00:00 = 0) of each occurrence. */
function schedule_week_minutes(array $s): array {
    [$h, $m] = array_map('intval', explode(':', $s['at_time']));
    $out = [];
    foreach (DAY_NAMES as $n => $_) if ((int) $s['days'] & day_bit($n)) $out[] = ($n - 1) * 1440 + $h * 60 + $m;
    return $out;
}

function fmt_week_minute(int $m): string {
    return DAY_NAMES[intdiv($m, 1440) + 1] . ' ' . sprintf('%02d:%02d', intdiv($m % 1440, 60), $m % 60);
}

/** Closest pair of occurrences less than SCHEDULE_OVERLAP_MIN apart (wrapping the week), or null. */
function schedules_overlap(array $a, array $b): ?array {
    $best = null;
    foreach (schedule_week_minutes($a) as $ma) {
        foreach (schedule_week_minutes($b) as $mb) {
            $d = abs($ma - $mb);
            $d = min($d, WEEK_MIN - $d);
            if ($d < SCHEDULE_OVERLAP_MIN && ($best === null || $d < $best[2])) $best = [$ma, $mb, $d];
        }
    }
    return $best;
}

function schedule_may_reboot(array $s): bool {
    if ($s['job_type'] === 'reboot') return true;
    return $s['job_type'] === 'updates' && !empty((json_decode($s['params'], true) ?: [])['reboot']);
}

/**
 * Problems between enabled schedules:
 *   error   - the same host is in two schedules that run within 2 hours
 *             (one job per host at a time, so the later one skips it)
 *   warning - a Proxmox host may reboot while KVM guests (possibly its VMs)
 *             are being updated, or WebMonitor's own server may reboot
 *             while other hosts' jobs run
 * Each item: [level, scheduleIds, message].
 */
function schedule_conflicts(array $schedules, ?string $serverIp = null): array {
    $agents = agent_summaries();
    $hosts = [];
    foreach (db()->query('SELECT id, name, ip_address FROM hosts')->fetchAll() as $h) {
        $hosts[(int) $h['id']] = $h['name'] === $h['ip_address'] ? $h['ip_address'] : $h['name'];
        if ($serverIp !== null && $h['ip_address'] === $serverIp) $serverHost = (int) $h['id'];
    }
    $serverHost = $serverHost ?? null;
    $name = fn(int $id) => $hosts[$id] ?? "#$id";
    $list = fn(array $ids) => implode(', ', array_map($name, $ids));

    $active = array_values(array_filter($schedules, fn($s) => (int) $s['enabled'] === 1));
    $targets = [];
    foreach ($active as $s) $targets[(int) $s['id']] = schedule_host_ids($s);
    $pick = fn(array $ids, string $flag) => array_values(array_filter($ids, fn($id) => !empty($agents[$id][$flag])));

    $out = [];
    // Within one schedule: a Proxmox host rebooting alongside KVM guests.
    foreach ($active as $s) {
        $ids = $targets[(int) $s['id']];
        $hv = $pick($ids, 'hypervisor');
        $vms = array_values(array_diff($pick($ids, 'vm'), $hv));
        if ($hv && $vms && schedule_may_reboot($s)) {
            $out[] = ['warning', [(int) $s['id']], "\"{$s['name']}\" may reboot the Proxmox host {$list($hv)} while it also updates "
                . "{$list($vms)} (VMs, possibly on that host). Give the Proxmox host its own schedule, run after its VMs."];
        }
    }
    // Between schedules.
    for ($i = 0; $i < count($active); $i++) {
        for ($j = $i + 1; $j < count($active); $j++) {
            [$a, $b] = [$active[$i], $active[$j]];
            $ov = schedules_overlap($a, $b);
            if ($ov === null) continue;
            $ta = $targets[(int) $a['id']];
            $tb = $targets[(int) $b['id']];
            $whenA = fmt_week_minute($ov[0]);
            $whenB = fmt_week_minute($ov[1]);
            $ids = [(int) $a['id'], (int) $b['id']];

            $shared = array_values(array_intersect($ta, $tb));
            if ($shared) {
                [$first, $second] = $ov[0] <= $ov[1] ? [$a, $b] : [$b, $a];
                $out[] = ['error', $ids, "{$list($shared)} " . (count($shared) === 1 ? 'is' : 'are')
                    . " in both \"{$a['name']}\" ($whenA) and \"{$b['name']}\" ($whenB). A host runs one job at a time, so "
                    . "\"{$second['name']}\" will skip " . (count($shared) === 1 ? 'it' : 'them') . " if \"{$first['name']}\" is still running."];
            }
            foreach ([[$a, $ta, $b, $tb, $whenA, $whenB], [$b, $tb, $a, $ta, $whenB, $whenA]] as [$x, $tx, $y, $ty, $wx, $wy]) {
                if (!schedule_may_reboot($x)) continue;
                $hv = $pick($tx, 'hypervisor');
                $vms = array_values(array_diff($pick($ty, 'vm'), $hv, $tx));
                if ($hv && $vms) {
                    $out[] = ['warning', $ids, "\"{$x['name']}\" may reboot the Proxmox host {$list($hv)} ($wx) while \"{$y['name']}\" "
                        . "updates {$list($vms)} ($wy) — VMs, possibly on that host. Space them more than 2 hours apart."];
                }
                $others = array_values(array_diff($ty, $tx));
                if ($serverHost !== null && in_array($serverHost, $tx, true) && $others) {
                    $out[] = ['warning', $ids, "\"{$x['name']}\" may reboot WebMonitor's own server {$name($serverHost)} ($wx) while "
                        . "\"{$y['name']}\" runs ($wy). Jobs keep going, but progress and the dashboard pause until it's back."];
                }
            }
        }
    }
    return array_map(fn($c) => ['level' => $c[0], 'scheduleIds' => $c[1], 'message' => $c[2]], $out);
}
