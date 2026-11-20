<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/netcheck.php';
require_once __DIR__ . '/agents.php';
require_once __DIR__ . '/topology.php';
require_once __DIR__ . '/portmap.php';
require_once __DIR__ . '/wireless.php';
require_once __DIR__ . '/devicescan.php';
require_once __DIR__ . '/services.php';
require_once __DIR__ . '/pro.php';   // pro_host_extras(): WebMonitor Pro or a stand-in

/**
 * Checks whether a host's IP address is reachable (TCP connect against a
 * handful of common ports — no raw ICMP, so no special privileges needed)
 * and records the result in the checks table.
 */
function check_host(array $host): array {
    return check_hosts([$host])[(int) $host['id']];
}

/**
 * Checks several hosts in parallel (one shared CHECK_TIMEOUT_SEC window
 * instead of one per host) and records each result.
 *
 * @return array<int, array{ts: int, up: bool, ms: ?int, port: ?int}> keyed by host id
 */
function check_hosts(array $hosts): array {
    if (!$hosts) return [];

    // Batched to cap concurrent sockets (IPs x ports) well under fd limits.
    $ips = array_values(array_unique(array_column($hosts, 'ip_address')));
    $probes = [];
    foreach (array_chunk($ips, 100) as $batch) {
        $probes += tcp_probe_hosts($batch, NETCHECK_PORTS, (float) CHECK_TIMEOUT_SEC);
    }

    $pdo = db();
    // Only while the host still exists: discovery may remove a card while it's being checked.
    $insert = $pdo->prepare('INSERT INTO checks (host_id, ts, up, ms) SELECT id, ?, ?, ? FROM hosts WHERE id = ?');
    $seen = $pdo->prepare('UPDATE hosts SET last_seen_at = ? WHERE id = ?');   // for subnet discovery
    // Keep only the most recent HISTORY_LIMIT rows for this host.
    $prune = $pdo->prepare(
        'DELETE FROM checks WHERE host_id = ? AND id NOT IN (
            SELECT id FROM (
                SELECT id FROM checks WHERE host_id = ? ORDER BY ts DESC LIMIT ' . HISTORY_LIMIT . '
            ) keep
        )'
    );

    $results = [];
    foreach ($hosts as $host) {
        $probe = $probes[$host['ip_address']];
        $up = $probe['up'];
        $ms = $up ? $probe['ms'] : null;
        $tsMs = (int) round(microtime(true) * 1000);

        $results[(int) $host['id']] = ['ts' => $tsMs, 'up' => $up, 'ms' => $ms, 'port' => $up ? $probe['port'] : null];
        $insert->execute([$tsMs, $up ? 1 : 0, $ms, $host['id']]);
        if (!$insert->rowCount()) continue;   // removed meanwhile
        $prune->execute([$host['id'], $host['id']]);
        if ($up) $seen->execute([$tsMs, $host['id']]);
    }
    return $results;
}

/**
 * Runs checks for every host whose last check is older than
 * CHECK_INTERVAL_SEC. Only one process does this at a time; if another
 * request is already checking, this returns immediately.
 */
function check_due_hosts(): void {
    $pdo = db();
    if (!(int) $pdo->query("SELECT GET_LOCK('webmonitor_check', 0)")->fetchColumn()) return;

    try {
        $cutoff = (int) round(microtime(true) * 1000) - CHECK_INTERVAL_SEC * 1000;
        $stmt = $pdo->prepare(
            'SELECT h.* FROM hosts h
             LEFT JOIN (SELECT host_id, MAX(ts) AS last_ts FROM checks GROUP BY host_id) c
               ON c.host_id = h.id
             WHERE c.last_ts IS NULL OR c.last_ts < ?'
        );
        $stmt->execute([$cutoff]);
        check_hosts($stmt->fetchAll());
    } finally {
        $pdo->query("SELECT RELEASE_LOCK('webmonitor_check')");
    }
}

/**
 * Builds the JSON-ready summary (with history + uptime %) for one host.
 */
function host_summary(array $host): array {
    return host_summaries([$host])[0];
}

/**
 * Builds summaries for many hosts, loading all their history in one query.
 */
function host_summaries(array $hosts): array {
    if (!$hosts) return [];

    $ids = array_map(fn($h) => (int) $h['id'], $hosts);
    $stmt = db()->prepare(
        'SELECT host_id, ts, up, ms FROM checks WHERE host_id IN (' .
        implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY ts ASC'
    );
    $stmt->execute($ids);

    $historyById = array_fill_keys($ids, []);
    foreach ($stmt->fetchAll() as $h) {
        $historyById[(int) $h['host_id']][] = [
            'ts' => (int) $h['ts'],
            'up' => (bool) $h['up'],
            'ms' => $h['ms'] !== null ? (int) $h['ms'] : null,
        ];
    }

    $agents = agent_summaries();
    $topo = build_topology();
    $nas = nas_summaries();
    $vendors = vendor_summaries();
    $portStrips = port_summaries();
    $wifi = wireless_summaries();
    $scanned = device_card_summaries();
    $services = service_card_summaries();
    $groups = host_group_map();
    $pro = pro_host_extras($hosts);                       // WebMonitor Pro: e.g. remote access methods
    return array_map(function ($host) use ($historyById, $agents, $topo, $nas, $vendors, $portStrips, $wifi, $scanned, $services, $groups, $pro) {
        $id = (int) $host['id'];
        return summarize_host($host, $historyById[$id]) + [
            'remote' => $pro[$id]['remote'] ?? null,            // Pro: remote access methods for the card
            'groupIds' => $groups[$id] ?? [],                   // card groups (Settings -> Groups)
            'agent' => $agents[$id] ?? null,
            'nas' => $nas[$id] ?? null,                          // NAS health (SNMP) for the card
            'parent' => $topo['parents'][$id] ?? null,          // the Proxmox host this VM/LXC runs on
            'hypervisor' => $topo['hypervisors'][$id] ?? null,  // this host's own guests
            'docker' => $topo['docker'][$id] ?? null,           // this host's containers
            'vendor' => $vendors[$id] ?? null,                  // 'cisco' | 'fortinet' (card icon)
            'portStrip' => $portStrips[$id] ?? null,            // switch cards: [[port, role code, devices], ...]
            'wifi' => $wifi[$id] ?? null,
            'services' => $services[$id] ?? null,               // monitored services: counts and what's down
            'deviceInfo' => in_array($host['device'], DEVICE_SCAN_TYPES, true) ? ($scanned[$host['ip_address']] ?? null) : null,   // model etc. from a device scan                       // Aruba APs: ['ap' => radios, uplink] / VC: ['controller' => totals]
        ];
    }, $hosts);
}

/** End of the host's mute (ms) if it's muted right now, else null. */
function host_muted_until(array $host): ?int {
    $until = isset($host['muted_until']) ? (int) $host['muted_until'] : 0;
    return $until > (int) round(microtime(true) * 1000) ? $until : null;
}

function summarize_host(array $host, array $history): array {
    $total = count($history);
    $upCount = count(array_filter($history, fn($h) => $h['up']));
    $last = $total ? $history[$total - 1] : null;

    return [
        'id' => (int) $host['id'],
        'name' => $host['name'],
        'ip' => $host['ip_address'],
        'os' => $host['os'],
        'device' => $host['device'],
        'community' => $host['community'],
        'bwExempt' => !empty($host['bw_exempt']),
        'locationId' => isset($host['location_id']) && $host['location_id'] !== null ? (int) $host['location_id'] : null,
        'history' => $history,
        'uptimePct' => $total ? round($upCount / $total * 1000) / 10 : null,
        'status' => $last ? ($last['up'] ? 'up' : 'down') : 'pending',
        'mutedUntil' => host_muted_until($host),
        'muteReason' => host_muted_until($host) !== null ? ($host['mute_reason'] ?? null) : null,
        'lastCheck' => $last ? $last['ts'] : null,
        'lastSeenAt' => isset($host['last_seen_at']) && $host['last_seen_at'] !== null ? (int) $host['last_seen_at'] : null,
        'lastMs' => $last ? $last['ms'] : null,
    ];
}

/**
 * Network gear maker for the card icon: from the SNMP sysObjectID enterprise
 * number, else the device type chosen for configuration backups.
 */
function vendor_summaries(): array {
    $byEnterprise = ['9' => 'cisco', '12356' => 'fortinet', '14823' => 'aruba'];
    $out = [];
    $rows = db()->query("SELECT host_id, JSON_UNQUOTE(JSON_EXTRACT(data, '$.system.objectId')) AS oid FROM host_details")->fetchAll();
    foreach ($rows as $r) {
        if (preg_match('/^\.?1\.3\.6\.1\.4\.1\.(\d+)\./', (string) $r['oid'], $m) && isset($byEnterprise[$m[1]])) {
            $out[(int) $r['host_id']] = $byEnterprise[$m[1]];
        }
    }
    foreach (db()->query("SELECT host_id FROM wlan_maps UNION SELECT id FROM hosts WHERE device = 'ap'") as $r) {
        $out[(int) $r['host_id']] ??= 'aruba';   // the only Wi-Fi WebMonitor reads is Aruba Instant
    }
    foreach (db()->query('SELECT host_id, vendor FROM config_backup_settings') as $r) {
        $v = str_starts_with($r['vendor'], 'cisco') ? 'cisco' : ($r['vendor'] === 'fortinet' ? 'fortinet' : null);
        if ($v && !isset($out[(int) $r['host_id']])) $out[(int) $r['host_id']] = $v;
    }
    return $out;
}

/** Card-sized NAS health for hosts whose SNMP details include a NAS: [hostId => summary]. */
function nas_summaries(): array {
    $out = [];
    $rows = db()->query("SELECT host_id, collected_at, JSON_EXTRACT(data, '$.nas') AS nas FROM host_details
                         WHERE JSON_TYPE(JSON_EXTRACT(data, '$.nas')) = 'OBJECT'")->fetchAll();
    foreach ($rows as $r) {
        $n = json_decode((string) $r['nas'], true);
        if (!is_array($n)) continue;
        $disks = $n['disks'] ?? [];
        $bad = count(array_filter($disks, fn($d) => strtoupper((string) $d['smart']) !== 'GOOD' || $d['status'] !== 0));
        $fullest = null;
        foreach ($n['volumes'] ?? [] as $v) {
            if ($v['usedPct'] !== null && ($fullest === null || $v['usedPct'] > $fullest['usedPct'])) $fullest = $v;
        }
        $out[(int) $r['host_id']] = [
            'vendor' => $n['vendor'] ?? null, 'model' => $n['model'] ?? null,
            'disks' => count($disks), 'badDisks' => $bad,
            'poolsOk' => !array_filter($n['pools'] ?? [], fn($p) => $p['status'] !== 'Ready'),
            'fullest' => $fullest ? ['name' => $fullest['name'], 'usedPct' => $fullest['usedPct'], 'status' => $fullest['status']] : null,
            'collectedAt' => (int) $r['collected_at'],
        ];
    }
    return $out;
}
