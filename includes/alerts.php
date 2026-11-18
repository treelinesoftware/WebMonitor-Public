<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/agents.php';
require_once __DIR__ . '/notify.php';
require_once __DIR__ . '/hostdetails.php';   // NAS checks (nas.php)
require_once __DIR__ . '/services.php';
require_once __DIR__ . '/speedtest.php';

/**
 * Alert evaluation, run every minute by cli/cron.php.
 *
 * Problems (host down, agent offline, disk, failed services) stay open
 * while the condition holds and are resolved when it clears. Events
 * (crash, reboot) are recorded already resolved. Anything not yet notified
 * is sent to every enabled channel as one digest per run, so a network
 * outage that takes 20 hosts down is one message, not 20.
 */

const ALERT_DEFAULTS = [
    'down_after_min' => 3,
    'agent_offline_after_min' => 5,
    'disk_warn_pct' => 85,
    'disk_crit_pct' => 95,
    'alert_failed_services' => true,
    'alert_antivirus' => true,     // antivirus off, out of date, or missing (Windows)
    'alert_services' => true,      // a monitored service is down or its certificate expires soon (Services tab)
    'alert_reboots' => true,       // planned reboots; crashes always alert
    'alert_config_changes' => true, // a switch configuration backup differs from the last one
    'notify_resolved' => true,
    'remind_every_hours' => 0,     // 0 = no reminders for long-open problems
    // A device using a lot of bandwidth (from NetFlow, Reports -> Traffic).
    'bw_mbps' => 50,               // average above this many Mbit/s ...
    'bw_minutes' => 15,            // ... over this many minutes (0 Mbit/s = off)
    'bw_daily_gb' => 50,           // or more than this many GB in a day (0 = off)
    'bw_internal' => false,        // count traffic between your own networks too (default: internet only)
];

// Data older than this is too stale to open or resolve alerts on.
const ALERT_STALE_CHECKS_SEC = 300;
const ALERT_STALE_METRICS_SEC = 600;
const ALERT_STALE_INVENTORY_SEC = 3 * 3600;
const ALERT_STALE_SNMP_SEC = 20 * 60;

function alert_settings(): array {
    $raw = db()->query("SELECT v FROM settings WHERE k = 'alerts'")->fetchColumn();
    $saved = $raw ? (json_decode($raw, true) ?: []) : [];
    $out = ALERT_DEFAULTS;
    foreach (ALERT_DEFAULTS as $k => $default) {
        if (!array_key_exists($k, $saved)) continue;
        $out[$k] = is_bool($default) ? (bool) $saved[$k] : max(0, (int) $saved[$k]);
    }
    return $out;
}

function save_alert_settings(array $input): array {
    $current = alert_settings();
    foreach (ALERT_DEFAULTS as $k => $default) {
        if (!array_key_exists($k, $input)) continue;
        $current[$k] = is_bool($default) ? (bool) $input[$k] : max(0, min(100000, (int) $input[$k]));
    }
    $current['disk_crit_pct'] = max($current['disk_crit_pct'], $current['disk_warn_pct']);
    db()->prepare("INSERT INTO settings (k, v) VALUES ('alerts', ?) ON DUPLICATE KEY UPDATE v = VALUES(v)")
        ->execute([json_encode($current)]);
    return $current;
}

function host_label(array $h): string {
    return $h['name'] === $h['ip_address'] ? $h['ip_address'] : "{$h['name']} ({$h['ip_address']})";
}

/**
 * Records a one-off event (crash/reboot) for notification on the next run,
 * or just for the history if $silent.
 */
function record_event(int $hostId, string $type, string $severity, string $message, bool $silent = false): void {
    $now = now_ms();
    db()->prepare('INSERT INTO alerts (host_id, type, subject, severity, message, started_at, resolved_at, notified_at)
                   VALUES (?, ?, \'\', ?, ?, ?, ?, ?)')
        ->execute([$hostId, $type, $severity, substr($message, 0, 500), $now, $now, $silent ? $now : null]);
}

/**
 * Called when an agent reports a boot time. Records a reboot/crash event
 * when it differs from the last one we saw.
 *
 * @param ?bool $cleanShutdown from the agent: whether the previous boot
 *              ended cleanly (null = unknown)
 */
function note_boot_time(int $hostId, int $bootMs, ?bool $cleanShutdown = null): void {
    $pdo = db();
    $stmt = $pdo->prepare('SELECT a.boot_time, h.name, h.ip_address, h.muted_until FROM agents a JOIN hosts h ON h.id = a.host_id WHERE a.host_id = ?');
    $stmt->execute([$hostId]);
    $row = $stmt->fetch();
    if (!$row) return;
    $prev = $row['boot_time'] !== null ? (int) $row['boot_time'] : null;
    // Boot time from uptime jitters by a second or two; 2 minutes is a reboot.
    if ($prev !== null && abs($bootMs - $prev) < 120000) return;
    $pdo->prepare('UPDATE agents SET boot_time = ? WHERE host_id = ?')->execute([$bootMs, $hostId]);
    if ($prev === null || $bootMs < $prev) return;

    $when = date('Y-m-d H:i', intdiv($bootMs, 1000));
    $label = host_label($row);
    // Muted hosts (e.g. maintenance) keep the history but send nothing.
    $muted = (int) $row['muted_until'] > now_ms();
    if ($cleanShutdown === false) {
        record_event($hostId, 'crash', 'critical', "$label crashed or lost power, and came back up at $when", $muted);
    } else {
        // Recorded even when reboot alerts are off, so it can still be
        // upgraded to a crash if the agent's inventory says so.
        record_event($hostId, 'reboot', 'info', "$label rebooted (up again at $when)", $muted || !alert_settings()['alert_reboots']);
    }
}

/**
 * The agent's inventory can arrive just after its first metrics; if it
 * says the last shutdown was unclean, upgrade a fresh reboot event.
 */
function upgrade_reboot_to_crash(int $hostId): void {
    $pdo = db();
    $muted = $pdo->prepare('SELECT muted_until > ? FROM hosts WHERE id = ?');
    $muted->execute([now_ms(), $hostId]);
    $silent = (bool) $muted->fetchColumn();
    $stmt = $pdo->prepare("SELECT id, message FROM alerts WHERE host_id = ? AND type = 'reboot' AND started_at > ?");
    $stmt->execute([$hostId, now_ms() - 15 * 60000]);
    foreach ($stmt->fetchAll() as $a) {
        $msg = preg_replace('/ rebooted \(up again at ([^)]*)\)$/', ' crashed or lost power, and came back up at $1', $a['message']);
        $pdo->prepare("UPDATE alerts SET type = 'crash', severity = 'critical', notified_at = ?, message = ? WHERE id = ?")
            ->execute([$silent ? now_ms() : null, $msg, $a['id']]);
    }
}

/** Desired open problems right now: [key => [host_id, type, subject, severity, message]]. */
function current_problems(array $s, array $hosts, array &$frozenHosts): array {
    $pdo = db();
    $now = now_ms();
    $problems = [];
    $add = function (?int $hostId, string $type, string $subject, string $severity, string $msg) use (&$problems) {
        $problems["$hostId|$type|$subject"] = [$hostId, $type, $subject, $severity, substr($msg, 0, 500)];
    };

    // --- Host down: every check for the last N minutes failed.
    $latest = $pdo->query('SELECT c.host_id, c.ts, c.up,
            (SELECT MAX(ts) FROM checks u WHERE u.host_id = c.host_id AND u.up = 1) AS last_up,
            (SELECT MIN(ts) FROM checks f WHERE f.host_id = c.host_id) AS first_ts
        FROM checks c JOIN (SELECT host_id, MAX(ts) ts FROM checks GROUP BY host_id) m
          ON m.host_id = c.host_id AND m.ts = c.ts')->fetchAll();
    $down = [];
    foreach ($latest as $r) {
        $hid = (int) $r['host_id'];
        if (!isset($hosts[$hid]) || $hosts[$hid]['muted']) continue;
        if ($now - (int) $r['ts'] > ALERT_STALE_CHECKS_SEC * 1000) {
            $frozenHosts[$hid]['host_down'] = true;   // no fresh checks: leave as is
            continue;
        }
        if ((int) $r['up'] === 1) continue;
        // Consoles and TVs are switched off or asleep much of the time: show it, don't alert.
        if (in_array($hosts[$hid]['device'] ?? '', ['console', 'tv'], true)) continue;
        $since = $r['last_up'] !== null
            ? (int) $pdo->query('SELECT MIN(ts) FROM checks WHERE host_id = ' . $hid . ' AND ts > ' . (int) $r['last_up'])->fetchColumn()
            : (int) $r['first_ts'];
        if ($now - $since >= $s['down_after_min'] * 60000) {
            $down[$hid] = true;
            $add($hid, 'host_down', '', 'critical', host_label($hosts[$hid]) . ' is not responding (down since ' . date('H:i', intdiv($since, 1000)) . ')');
        }
    }

    // --- Agent-based checks.
    $agents = $pdo->query('SELECT host_id, last_seen_at, metrics, metrics_at, inventory_at,
            JSON_EXTRACT(inventory, \'$.failed_services\') AS failed, JSON_EXTRACT(inventory, \'$.antivirus\') AS av,
            JSON_UNQUOTE(JSON_EXTRACT(inventory, \'$.os.id\')) AS os_id FROM agents')->fetchAll();
    foreach ($agents as $a) {
        $hid = (int) $a['host_id'];
        if (!isset($hosts[$hid]) || $hosts[$hid]['muted']) continue;
        $label = host_label($hosts[$hid]);

        $silentMin = $a['last_seen_at'] !== null ? ($now - (int) $a['last_seen_at']) / 60000 : INF;
        if ($silentMin >= $s['agent_offline_after_min'] && empty($down[$hid])) {
            $add($hid, 'agent_offline', '', 'critical', "$label: agent hasn't reported for " . (int) $silentMin
                . ' min — the system may be hung or the agent stopped');
        }

        if ($a['metrics_at'] !== null && $now - (int) $a['metrics_at'] < ALERT_STALE_METRICS_SEC * 1000) {
            $m = json_decode((string) $a['metrics'], true) ?: [];
            foreach (($m['disks'] ?? []) as $d) {
                $pct = $d['used_pct'] ?? null;
                if (!is_numeric($pct) || !isset($d['mount'])) continue;
                $mount = substr((string) $d['mount'], 0, 190);
                $free = isset($d['available']) ? ', ' . human_bytes((float) $d['available']) . ' free' : '';
                if ($pct >= $s['disk_crit_pct']) {
                    $add($hid, 'disk', $mount, 'critical', "$label: disk $mount is {$pct}% full$free");
                } elseif ($pct >= $s['disk_warn_pct']) {
                    $add($hid, 'disk', $mount, 'warning', "$label: disk $mount is {$pct}% full$free");
                }
            }
        } else {
            $frozenHosts[$hid]['disk'] = true;
        }

        if ($s['alert_failed_services']) {
            if ($a['inventory_at'] !== null && $now - (int) $a['inventory_at'] < ALERT_STALE_INVENTORY_SEC * 1000) {
                $failed = json_decode((string) $a['failed'], true);
                if (is_array($failed) && $failed) {
                    $add($hid, 'failed_services', '', 'warning', "$label: failed services: " . implode(', ', array_slice($failed, 0, 10)));
                }
            } else {
                $frozenHosts[$hid]['failed_services'] = true;
            }
        }

        if ($s['alert_antivirus']) {
            if ($a['inventory_at'] !== null && $now - (int) $a['inventory_at'] < ALERT_STALE_INVENTORY_SEC * 1000) {
                $av = antivirus_status(json_decode((string) $a['av'], true), $a['os_id'] === 'windows' ? 'windows' : 'linux');
                if ($av && in_array($av['state'], ['off', 'warn'], true)) {
                    $add($hid, 'antivirus', '', $av['state'] === 'off' ? 'critical' : 'warning', "$label: antivirus — {$av['text']}");
                }
            } else {
                $frozenHosts[$hid]['antivirus'] = true;
            }
        }
    }
    // --- NAS hardware and volumes (SNMP, polled by cron).
    $nasRows = $pdo->query("SELECT d.host_id, d.collected_at, JSON_EXTRACT(d.data, '$.nas') AS nas
                            FROM host_details d JOIN hosts h ON h.id = d.host_id WHERE h.device = 'nas'")->fetchAll();
    foreach ($nasRows as $r) {
        $hid = (int) $r['host_id'];
        if (!isset($hosts[$hid]) || $hosts[$hid]['muted']) continue;
        $nas = json_decode((string) $r['nas'], true);
        if (!is_array($nas) || $now - (int) $r['collected_at'] > ALERT_STALE_SNMP_SEC * 1000) {
            $frozenHosts[$hid]['disk'] = true;          // no fresh data: leave as is
            $frozenHosts[$hid]['nas_health'] = true;
            continue;
        }
        foreach (nas_problems($nas, host_label($hosts[$hid]), $s['disk_warn_pct'], $s['disk_crit_pct']) as [$type, $subject, $sev, $msg]) {
            $add($hid, $type, $subject, $sev, $msg);
        }
    }

    // --- High bandwidth: a device averaging more than bw_mbps over bw_minutes, or more than
    // bw_daily_gb today (NetFlow). Devices without a card are alerted on by IP.
    bandwidth_problems($s, $add, $frozenHosts);

    // --- Monitored services (Services tab): down, or certificate expiring soon.
    if ($s['alert_services']) service_problems($hosts, $add, $frozenHosts);

    // --- Internet speed (Network tab): the last two tests slower than the set minimum.
    speedtest_problems($add, $frozenHosts);
    return $problems;
}

/**
 * Services that are down (confirmed: see record_service_result()) or whose
 * certificate expires within its warning window. Subject 'svc:ID'; services
 * without a host are alerted with host_id null.
 */
function service_problems(array $hosts, callable $add, array &$frozenHosts): void {
    $pdo = db();
    if (!(int) $pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'services'")->fetchColumn()) return;
    $now = now_ms();
    foreach ($pdo->query("SELECT * FROM services WHERE enabled = 1 AND alert = 1") as $svc) {
        $hid = $svc['host_id'] !== null ? (int) $svc['host_id'] : null;
        if ($hid !== null && (!isset($hosts[$hid]) || $hosts[$hid]['muted'])) continue;
        $subject = 'svc:' . $svc['id'];
        $where = $hid !== null ? ' on ' . host_label($hosts[$hid]) : '';
        if ($svc['status'] === 'unknown' || $svc['status'] === 'pending') {
            $frozenHosts[$hid ?? 0]["service_down|$subject"] = true;   // no fresh data: leave as is
            continue;
        }
        if ($svc['status'] === 'down') {
            $since = $svc['changed_at'] ? ' since ' . date('H:i', intdiv((int) $svc['changed_at'], 1000)) : '';
            $add($hid, 'service_down', $subject, 'critical', "{$svc['name']}$where is down$since: {$svc['message']}");
        }
        $o = service_options($svc['options']);
        $warnDays = (int) ($o['cert_warn_days'] ?? 0);
        if ($svc['kind'] === 'http' && $svc['cert_expires_at'] !== null && $warnDays > 0) {
            $days = (int) floor(((int) $svc['cert_expires_at'] - $now) / 86400000);
            if ($days <= $warnDays) {
                $add($hid, 'service_cert', $subject, $days < 0 ? 'critical' : 'warning',
                     "{$svc['name']}$where: TLS certificate " . ($days < 0 ? 'expired on ' : "expires in $days day" . ($days === 1 ? '' : 's') . ', on ')
                     . date('Y-m-d', intdiv((int) $svc['cert_expires_at'], 1000)));
            }
        }
    }
}

function human_bytes(float $b): string {
    $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
    $i = 0;
    while ($b >= 1024 && $i < count($units) - 1) { $b /= 1024; $i++; }
    return round($b, $b >= 100 ? 0 : 1) . ' ' . $units[$i];
}

/** Opens/updates/resolves problems and sends notifications. Returns a summary. */
function evaluate_alerts(): array {
    $pdo = db();
    if (!(int) $pdo->query("SELECT GET_LOCK('webmonitor_alerts', 0)")->fetchColumn()) return ['skipped' => true];
    try {
        $s = alert_settings();
        $now = now_ms();
        $hosts = [];
        foreach ($pdo->query('SELECT id, name, ip_address, muted_until, device FROM hosts')->fetchAll() as $h) {
            $h['muted'] = (int) $h['muted_until'] > $now;
            $hosts[(int) $h['id']] = $h;
        }

        $frozen = [];
        $wanted = current_problems($s, $hosts, $frozen);

        $open = [];
        foreach ($pdo->query("SELECT * FROM alerts WHERE resolved_at IS NULL AND type IN ('host_down','agent_offline','disk','failed_services','antivirus','nas_health','bandwidth','service_down','service_cert','speedtest')")->fetchAll() as $a) {
            $open["{$a['host_id']}|{$a['type']}|{$a['subject']}"] = $a;
        }

        $changes = ['opened' => [], 'resolved' => [], 'reminders' => []];
        foreach ($wanted as $key => [$hid, $type, $subject, $sev, $msg]) {
            if (!isset($open[$key])) {
                $pdo->prepare('INSERT INTO alerts (host_id, type, subject, severity, message, started_at) VALUES (?, ?, ?, ?, ?, ?)')
                    ->execute([$hid, $type, $subject, $sev, $msg, $now]);
            } elseif ($open[$key]['severity'] !== $sev) {
                // e.g. disk warning -> critical: tell people again.
                $pdo->prepare('UPDATE alerts SET severity = ?, message = ?, notified_at = NULL WHERE id = ?')
                    ->execute([$sev, $msg, $open[$key]['id']]);
            } else {
                $pdo->prepare('UPDATE alerts SET message = ? WHERE id = ?')->execute([$msg, $open[$key]['id']]);
            }
        }
        foreach ($open as $key => $a) {
            $hid = $a['host_id'] === null ? null : (int) $a['host_id'];   // null: a device without a card (bandwidth)
            if (isset($wanted[$key]) || ($hid !== null && !isset($hosts[$hid]))) continue;
            $muted = $hid !== null && $hosts[$hid]['muted'];
            // No fresh data: don't guess (but muting always closes problems).
            // A service that no longer exists or was paused: close it quietly.
            if (str_starts_with($a['type'], 'service_') && !service_alert_live($a)) {
                $pdo->prepare('UPDATE alerts SET resolved_at = ?, notified_at = COALESCE(notified_at, ?) WHERE id = ?')->execute([$now, $now, $a['id']]);
                continue;
            }
            if (!$muted && (!empty($frozen[(int) $a['host_id']][$a['type']])
                            || !empty($frozen[(int) $a['host_id']][$a['type'] . '|' . $a['subject']]))) continue;
            $pdo->prepare('UPDATE alerts SET resolved_at = ?, notified_at = COALESCE(notified_at, ?) WHERE id = ?')
                ->execute([$now, $now, $a['id']]);
            if (!$muted && $s['notify_resolved'] && $a['notified_at'] !== null) $changes['resolved'][] = $a;
        }

        // Anything still pending for a muted host is history only.
        $mutedIds = array_keys(array_filter($hosts, fn($h) => $h['muted']));
        if ($mutedIds) {
            $pdo->prepare('UPDATE alerts SET notified_at = ? WHERE notified_at IS NULL AND host_id IN ('
                . implode(',', array_map('intval', $mutedIds)) . ')')->execute([$now]);
        }

        // New problems, severity changes and events not yet notified.
        $changes['opened'] = $pdo->query('SELECT * FROM alerts WHERE notified_at IS NULL ORDER BY started_at')->fetchAll();
        if ($s['remind_every_hours'] > 0) {
            $stmt = $pdo->prepare('SELECT * FROM alerts WHERE resolved_at IS NULL AND notified_at IS NOT NULL AND notified_at < ?');
            $stmt->execute([$now - $s['remind_every_hours'] * 3600000]);
            $changes['reminders'] = $stmt->fetchAll();
        }

        $sent = 0;
        if ($changes['opened'] || $changes['resolved'] || $changes['reminders']) {
            $sent = send_alert_digest($changes, $hosts);
            $ids = array_merge(array_column($changes['opened'], 'id'), array_column($changes['reminders'], 'id'));
            if ($ids) {
                $pdo->prepare('UPDATE alerts SET notified_at = ? WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')')
                    ->execute(array_merge([$now], $ids));
            }
        }

        // Keep history bounded.
        $pdo->prepare('DELETE FROM alerts WHERE resolved_at IS NOT NULL AND resolved_at < ?')->execute([$now - 90 * 86400000]);
        $pdo->prepare('DELETE FROM notification_log WHERE ts < ?')->execute([$now - 30 * 86400000]);

        return ['open' => count($wanted), 'opened' => count($changes['opened']),
                'resolved' => count($changes['resolved']), 'reminders' => count($changes['reminders']), 'sent' => $sent];
    } finally {
        $pdo->query("SELECT RELEASE_LOCK('webmonitor_alerts')");
    }
}

const SEVERITY_ICON = ['critical' => '🔴', 'warning' => '🟠', 'info' => '🔵'];

/** Builds one message for all changes and sends it to every enabled channel. */
function send_alert_digest(array $changes, array $hosts): int {
    $lines = [];
    $worst = 'info';
    foreach ($changes['opened'] as $a) {
        $icon = SEVERITY_ICON[$a['severity']] ?? '•';
        $lines[] = "$icon " . strtoupper(alert_type_label($a['type'])) . ": {$a['message']}";
        if ($a['severity'] === 'critical' || ($a['severity'] === 'warning' && $worst === 'info')) $worst = $a['severity'];
    }
    foreach ($changes['reminders'] as $a) {
        $lines[] = '⏰ STILL OPEN: ' . $a['message'];
    }
    foreach ($changes['resolved'] as $a) {
        $lines[] = '✅ RESOLVED: ' . $a['message'];
    }

    $n = count($changes['opened']);
    if ($n === 1 && !$changes['resolved'] && !$changes['reminders']) {
        $title = 'WebMonitor: ' . alert_type_label($changes['opened'][0]['type']) . ' — ' . alert_host_short($hosts, $changes['opened'][0]);
    } elseif (!$n && count($changes['resolved']) === 1 && !$changes['reminders']) {
        $title = 'WebMonitor: resolved — ' . alert_host_short($hosts, $changes['resolved'][0]);
    } else {
        $parts = [];
        if ($n) $parts[] = "$n new";
        if ($changes['reminders']) $parts[] = count($changes['reminders']) . ' still open';
        if ($changes['resolved']) $parts[] = count($changes['resolved']) . ' resolved';
        $title = 'WebMonitor: ' . implode(', ', $parts);
    }
    $priority = $n ? $worst : 'info';
    $alertId = $n === 1 ? (int) $changes['opened'][0]['id'] : null;
    return notify_all($title, implode("\n", $lines), $priority, $alertId);
}

/** Short name for an alert's host; devices without a card are named in the alert itself. */
function alert_host_short(array $hosts, array $a): string {
    if (str_starts_with((string) $a['subject'], 'svc:')) {
        $st = db()->prepare('SELECT name FROM services WHERE id = ?');
        $st->execute([(int) substr($a['subject'], 4)]);
        $name = $st->fetchColumn() ?: 'service';
        return $a['host_id'] === null ? $name : "$name on " . host_short($hosts, (int) $a['host_id']);
    }
    return $a['host_id'] === null ? explode('|', (string) $a['subject'])[0] : host_short($hosts, (int) $a['host_id']);
}

/** Whether a service alert's service still exists with alerts on (else it's closed without a "resolved" message). */
function service_alert_live(array $a): bool {
    $st = db()->prepare('SELECT 1 FROM services WHERE id = ? AND enabled = 1 AND alert = 1');
    $st->execute([(int) substr((string) $a['subject'], 4)]);
    return (bool) $st->fetchColumn();
}

function host_short(array $hosts, int $id): string {
    if (!isset($hosts[$id])) return "host #$id";
    return $hosts[$id]['name'] === $hosts[$id]['ip_address'] ? $hosts[$id]['ip_address'] : $hosts[$id]['name'];
}

function alert_type_label(string $type): string {
    return [
        'host_down' => 'Down',
        'agent_offline' => 'Agent offline',
        'disk' => 'Disk space',
        'failed_services' => 'Failed services',
        'antivirus' => 'Antivirus',
        'nas_health' => 'NAS health',
        'crash' => 'Crash',
        'reboot' => 'Reboot',
        'config_change' => 'Config changed',
        'bandwidth' => 'High bandwidth',
        'service_down' => 'Service down',
        'service_cert' => 'Certificate',
        'speedtest' => 'Internet speed',
    ][$type] ?? $type;
}

/** High-bandwidth problems from the per-minute flow data (see current_problems()). */
function bandwidth_problems(array $s, callable $add, array &$frozenHosts): void {
    if ($s['bw_mbps'] <= 0 && $s['bw_daily_gb'] <= 0) return;
    $pdo = db();
    if (!(int) $pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'flow_hosts'")->fetchColumn()) return;
    $lastMinute = (int) $pdo->query('SELECT MAX(ts) FROM flow_totals')->fetchColumn();
    if ($lastMinute < time() - 300) {   // no fresh flow data: keep what's open, don't guess
        $frozenHosts[0]['bandwidth'] = true;
        return;
    }
    $sumIn = $s['bw_internal'] ? 'bytes_in' : 'inet_in';
    $sumOut = $s['bw_internal'] ? 'bytes_out' : 'inet_out';
    $what = $s['bw_internal'] ? 'traffic' : 'internet traffic';
    $offenders = [];
    if ($s['bw_mbps'] > 0 && $s['bw_minutes'] > 0) {
        $from = $lastMinute - ($s['bw_minutes'] - 1) * 60;
        $q = $pdo->prepare("SELECT ip, SUM($sumIn) i, SUM($sumOut) o FROM flow_hosts WHERE ts >= ? GROUP BY ip
                            HAVING (SUM($sumIn) + SUM($sumOut)) * 8 / ? > ?");
        $q->execute([$from, $s['bw_minutes'] * 60, $s['bw_mbps'] * 1e6]);
        foreach ($q->fetchAll() as $r) $offenders[] = ['rate', $r['ip'], (int) $r['i'], (int) $r['o'], $from];
    }
    if ($s['bw_daily_gb'] > 0) {
        $today = strtotime('today');
        $q = $pdo->prepare("SELECT ip, SUM($sumIn) i, SUM($sumOut) o FROM flow_hosts WHERE ts >= ? GROUP BY ip HAVING SUM($sumIn) + SUM($sumOut) > ?");
        $q->execute([$today, $s['bw_daily_gb'] * 1e9]);
        foreach ($q->fetchAll() as $r) $offenders[] = ['day', $r['ip'], (int) $r['i'], (int) $r['o'], $today];
    }
    if (!$offenders) return;

    // Names: the card, else what device identification knows.
    $cards = [];
    foreach ($pdo->query('SELECT id, name, ip_address, bw_exempt FROM hosts') as $h) $cards[$h['ip_address']] = $h;
    // Hosts marked "expected high traffic" (Edit) don't alert.
    $offenders = array_values(array_filter($offenders, fn($o) => empty($cards[$o[1]]['bw_exempt'])));
    if (!$offenders) return;
    $inventory = [];
    try {
        require_once __DIR__ . '/devices.php';
        require_once __DIR__ . '/checker.php';
        foreach (device_inventory()['devices'] as $d) if ($d['ip']) $inventory[$d['ip']] ??= $d;
    } catch (Throwable $e) { /* names are a nicety */ }
    require_once __DIR__ . '/flows.php';
    foreach ($offenders as [$kind, $ip, $in, $out, $from]) {
        $card = $cards[$ip] ?? null;
        $inv = $inventory[$ip] ?? null;
        $name = $card && $card['name'] !== $ip ? $card['name'] : ($inv['name'] ?? null);
        $label = $name ? "$name ($ip" . ($inv ? ', ' . strtolower($inv['label']) : '') . ')' : ($inv ? "{$inv['label']} $ip" : $ip);
        $top = $pdo->prepare('SELECT remote_ip, proto, port, SUM(bytes_in + bytes_out) b FROM flow_conv WHERE local_ip = ? AND ts >= ?
                              GROUP BY remote_ip, proto, port ORDER BY b DESC LIMIT 1');
        $top->execute([$ip, $from]);
        $t = $top->fetch();
        $mostly = $t ? ' — mostly ' . flow_service_name((int) $t['proto'], (int) $t['port']) . ' with ' . $t['remote_ip'] : '';
        if ($kind === 'rate') {
            $mbps = fn($b) => round($b * 8 / ($s['bw_minutes'] * 60) / 1e6, 1);
            $msg = "$label averaged " . $mbps($in + $out) . " Mbit/s of $what over the last {$s['bw_minutes']} minutes ("
                . $mbps($in) . ' down / ' . $mbps($out) . " up, limit {$s['bw_mbps']})$mostly";
            $add($card ? (int) $card['id'] : null, 'bandwidth', $card ? 'rate' : "$ip|rate", 'warning', $msg);
        } else {
            $gb = fn($b) => round($b / 1e9, 1);
            $msg = "$label has used " . $gb($in + $out) . " GB of $what today (" . $gb($in) . ' down / ' . $gb($out) . " up, limit {$s['bw_daily_gb']} GB)$mostly";
            $add($card ? (int) $card['id'] : null, 'bandwidth', $card ? 'day' : "$ip|day", 'warning', $msg);
        }
    }
}
