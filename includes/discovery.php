<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/scanner.php';
require_once __DIR__ . '/notify.php';
require_once __DIR__ . '/scanqueue.php';

/**
 * Subnet discovery (Settings -> Subnets). Per subnet:
 *   - scan every N minutes: sweep the /24, mark monitored hosts as seen,
 *     and add new devices (or just list them, when auto-add is off);
 *   - remove cards not seen for N days.
 * Hosts with an agent, the subnet's gateway and muted hosts are never
 * removed. "Seen" (hosts.last_seen_at) also comes from successful checks
 * (checker.php) and the server's ARP table (topology.php).
 *
 * Run by cli/poll-snmp.php every 5 minutes; "Scan now" runs one directly.
 */

const SCAN_EVERY_CHOICES = [15, 30, 60, 180, 360, 720, 1440];   // minutes
const DISCOVERY_MAX_ADDS = 15;          // per subnet per run (SNMP-probing new devices takes time)
const DISCOVERY_REMOVE_MAX_SHARE = 0.25; // more than this vanishing at once may be an outage (see looks_like_outage)
const DISCOVERY_OUTAGE_WINDOW_SEC = 6 * 3600;   // ...when they all went quiet within this window

function discovery_now_ms(): int {
    return (int) round(microtime(true) * 1000);
}

function subnet_scan_rows(): array {
    $rows = [];
    foreach (db()->query('SELECT * FROM subnet_scans') as $r) $rows[$r['subnet']] = $r;
    return $rows;
}

/** Public form of a subnet_scans row. */
function subnet_scan_public(array $r): array {
    return [
        'enabled' => (bool) $r['enabled'],
        'everyMin' => (int) $r['every_min'],
        'autoAdd' => (bool) $r['auto_add'],
        'removeDays' => $r['remove_after_days'] !== null ? (int) $r['remove_after_days'] : null,
        'removeScanOnly' => (bool) $r['remove_scan_only'],
        'autoName' => (bool) ($r['auto_name'] ?? false),
        'lastScanAt' => $r['last_scan_at'] !== null ? (int) $r['last_scan_at'] : null,
        'lastResult' => $r['last_result'] ? json_decode($r['last_result'], true) : null,
    ];
}

function log_discovery(string $subnet, string $action, string $ip, string $name, ?string $detail = null): void {
    db()->prepare('INSERT INTO discovery_log (ts, subnet, action, ip, name, detail) VALUES (?, ?, ?, ?, ?, ?)')
        ->execute([discovery_now_ms(), $subnet, $action, $ip, mb_substr($name, 0, 60), $detail !== null ? mb_substr($detail, 0, 255) : null]);
}

/** Marks hosts with these IPs as seen now. */
function mark_ips_seen(array $ips): void {
    if (!$ips) return;
    $ips = array_values(array_unique($ips));
    db()->prepare('UPDATE hosts SET last_seen_at = ? WHERE ip_address IN (' . implode(',', array_fill(0, count($ips), '?')) . ')')
        ->execute(array_merge([discovery_now_ms()], $ips));
}

/** The SNMP community most hosts in the subnet use (new devices get the same). */
function subnet_community(string $prefix): string {
    $stmt = db()->prepare("SELECT community FROM hosts WHERE ip_address LIKE ? AND community <> 'public'
                           GROUP BY community ORDER BY COUNT(*) DESC LIMIT 1");
    $stmt->execute([$prefix . '%']);
    return (string) ($stmt->fetchColumn() ?: 'public');
}

/**
 * Sweeps one subnet: marks hosts seen, adds new devices when auto-add is on.
 * Returns the result stored in subnet_scans.last_result.
 */
function scan_subnet(string $subnet, bool $autoAdd): array {
    $started = microtime(true);
    $pdo = db();
    $prefix = substr($subnet, 0, -4);   // "192.168.10."
    $ips = parse_networks($subnet);
    $sweep = network_sweep($ips, NETCHECK_PORTS, 1.5);
    $upIps = array_keys(array_filter($sweep, fn($r) => $r['up']));

    $known = [];
    $stmt = $pdo->prepare('SELECT ip_address FROM hosts WHERE ip_address LIKE ?');
    $stmt->execute([$prefix . '%']);
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $ip) $known[$ip] = true;

    mark_ips_seen(array_values(array_filter($upIps, fn($ip) => isset($known[$ip]))));
    $new = array_values(array_filter($upIps, fn($ip) => !isset($known[$ip])));
    usort($new, fn($a, $b) => ip2long($a) <=> ip2long($b));

    $added = [];
    if ($autoAdd && $new) {
        $community = subnet_community($prefix);
        $snmpOk = snmp_available();
        $insert = $pdo->prepare("INSERT INTO hosts (name, ip_address, os, device, community, added_by, last_seen_at)
                                 VALUES (?, ?, ?, ?, ?, 'scan', ?)");
        foreach (array_slice($new, 0, DISCOVERY_MAX_ADDS) as $ip) {
            $snmp = $snmpOk ? snmp_probe($ip, $community, 1.0) : null;
            $class = classify_device($sweep[$ip]['port'], $sweep[$ip]['open'], $snmp);
            $name = substr(explode('.', trim((string) ($snmp['sysName'] ?? '')))[0], 0, 60);
            if ($name === '' || filter_var($name, FILTER_VALIDATE_IP)) $name = $ip;
            $insert->execute([$name, $ip, $class['os'], $class['device'], $community, discovery_now_ms()]);
            log_discovery($subnet, 'added', $ip, $name, $class['label']);
            $added[] = $name === $ip ? $ip : "$name ($ip)";
        }
    }

    return [
        'at' => discovery_now_ms(),
        'ms' => (int) round((microtime(true) - $started) * 1000),
        'scanned' => count($ips),
        'up' => count($upIps),
        'added' => $added,
        'new' => $autoAdd ? array_slice($new, DISCOVERY_MAX_ADDS) : $new,   // seen but not (yet) added
    ];
}

/**
 * Many hosts unseen at once: an outage when nothing on the subnet answers now, or
 * when they all went quiet together. Hosts that dropped off one by one over days
 * (devices that moved to a new DHCP address) are just gone.
 */
function looks_like_outage(array $lastSeenMs, int $seenInLastHour): bool {
    if ($seenInLastHour === 0) return true;
    $seen = array_filter($lastSeenMs);   // never seen: no time to compare
    return count($seen) >= 2 && max($seen) - min($seen) < DISCOVERY_OUTAGE_WINDOW_SEC * 1000;
}

/**
 * Removes cards in the subnet not seen for $days days. Never: hosts with
 * an agent, the subnet's gateway, muted hosts, or (when $scanOnly) hosts a
 * person added. Returns [removed labels, skipped reason or null].
 */
function remove_unseen(string $subnet, int $days, bool $scanOnly): array {
    $pdo = db();
    $prefix = substr($subnet, 0, -4);
    $cutoffMs = discovery_now_ms() - $days * 86400000;
    $gateway = subnet_gateways()[$subnet] ?? '';

    $total = $pdo->prepare('SELECT COUNT(*) FROM hosts WHERE ip_address LIKE ?');
    $total->execute([$prefix . '%']);
    $total = (int) $total->fetchColumn();

    $stmt = $pdo->prepare('SELECT h.id, h.name, h.ip_address, h.last_seen_at FROM hosts h
        LEFT JOIN agents a ON a.host_id = h.id
        WHERE h.ip_address LIKE ? AND a.host_id IS NULL AND h.ip_address <> ?
          AND (h.muted_until IS NULL OR h.muted_until < ?)
          AND COALESCE(h.last_seen_at, 0) < ? AND h.created_at < FROM_UNIXTIME(?)'
        . ($scanOnly ? " AND h.added_by = 'scan'" : ''));
    $stmt->execute([$prefix . '%', $gateway, discovery_now_ms(), $cutoffMs, intdiv($cutoffMs, 1000)]);
    $stale = $stmt->fetchAll();
    if (!$stale) return [[], null];

    if (count($stale) > max(2, (int) floor($total * DISCOVERY_REMOVE_MAX_SHARE))) {
        $recent = $pdo->prepare('SELECT COUNT(*) FROM hosts WHERE ip_address LIKE ? AND last_seen_at >= ?');
        $recent->execute([$prefix . '%', discovery_now_ms() - 3600000]);
        if (looks_like_outage(array_map(fn($h) => (int) $h['last_seen_at'], $stale), (int) $recent->fetchColumn())) {
            return [[], count($stale) . " of $total hosts not seen for $days days at once — looks like an outage, so nothing was removed"];
        }
    }

    $removed = [];
    foreach ($stale as $h) {
        delete_host((int) $h['id']);
        $ago = $h['last_seen_at'] ? (int) floor((discovery_now_ms() - (int) $h['last_seen_at']) / 86400000) : null;
        log_discovery($subnet, 'removed', $h['ip_address'], $h['name'], $ago !== null ? "not seen for $ago days" : 'never seen');
        $removed[] = $h['name'] === $h['ip_address'] ? $h['ip_address'] : "{$h['name']} ({$h['ip_address']})";
    }
    return [$removed, null];
}

/**
 * Runs due scans and removals. $only + $force: scan that subnet now
 * ("Scan now"). Returns [subnet => result].
 */
function run_subnet_scans(?string $only = null, bool $force = false): array {
    $pdo = db();
    $out = [];
    $rows = subnet_scan_rows();
    if ($only !== null && !isset($rows[$only])) {
        $rows[$only] = ['subnet' => $only, 'enabled' => 0, 'every_min' => 60, 'auto_add' => 0,
                        'remove_after_days' => null, 'remove_scan_only' => 1, 'last_scan_at' => null];
    }
    while ($rows) {
        if ($only === null) {
            $current = subnet_scan_rows();
            $rows = array_intersect_key($current, $rows);
            if (!$rows) break;
        }
        uasort($rows, fn($a, $b) => (int) ($b['priority_at'] ?? 0) <=> (int) ($a['priority_at'] ?? 0));
        $subnet = array_key_first($rows);
        $r = $rows[$subnet];
        unset($rows[$subnet]);
        if ($only !== null && $subnet !== $only) continue;
        $due = $r['enabled'] && (int) $r['last_scan_at'] + (int) $r['every_min'] * 60000 <= discovery_now_ms() + 30000;
        $result = null;
        $requestedAt = isset($r['requested_at']) ? (int) $r['requested_at'] : null;
        if ($force || $due || $requestedAt !== null) {
            scan_activity_start('webmonitor_discovery', 'subnet', $subnet);
            try {
                $result = scan_subnet($subnet, (bool) $r['auto_add']);
            } finally {
                scan_activity_end('webmonitor_discovery');
            }
        }
        $removed = [];
        $skipped = null;
        if ($r['remove_after_days']) {
            [$removed, $skipped] = remove_unseen($subnet, (int) $r['remove_after_days'], (bool) $r['remove_scan_only']);
            if ($skipped) {
                // Log once per day, not every 5 minutes.
                $last = $pdo->prepare("SELECT MAX(ts) FROM discovery_log WHERE subnet = ? AND action = 'skipped'");
                $last->execute([$subnet]);
                if ((int) $last->fetchColumn() < discovery_now_ms() - 86400000) log_discovery($subnet, 'skipped', '', '', $skipped);
            }
        }
        if ($result !== null) {
            $result['removed'] = $removed;
            $result['skipped'] = $skipped;
            $pdo->prepare('INSERT INTO subnet_scans (subnet, last_scan_at, last_result) VALUES (?, ?, ?)
                           ON DUPLICATE KEY UPDATE last_scan_at = VALUES(last_scan_at), last_result = VALUES(last_result),
                             priority_at = IF(requested_at <=> ?, NULL, priority_at),
                             requested_at = IF(requested_at <=> ?, NULL, requested_at)')
                ->execute([$subnet, $result['at'], json_encode($result), $requestedAt, $requestedAt]);
        }
        $added = $result['added'] ?? [];
        if ($added || $removed) {
            $label = ($n = subnet_names()[$subnet] ?? '') !== '' ? "$n ($subnet)" : $subnet;
            $lines = array_merge(array_map(fn($x) => "Added: $x", $added), array_map(fn($x) => "Removed (not seen for {$r['remove_after_days']} days): $x", $removed));
            notify_all("WebMonitor: changes on $label", implode("\n", $lines), 'info');
        }
        $out[$subnet] = ($result ?? []) + ['removed' => $removed, 'skipped' => $skipped];
    }
    return $out;
}
