<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/switchinfo.php';
require_once __DIR__ . '/history.php';

/**
 * Live port traffic of the switches and routers (Network tab).
 *
 * Each physical device's interface counters (IF-MIB ifHCInOctets /
 * ifHCOutOctets, or the 32-bit ifInOctets / ifOutOctets on older gear) are
 * read over SNMP; the rate of each port is the difference between two
 * samples. The dashboard asks for fresh rates every few seconds while the
 * Network tab is open (samples are shared: a device is read at most once per
 * NETLIVE_MIN_GAP_MS, whoever is watching), and cron reads every device once
 * a minute for the per-port history (metrics port_in / port_out, bit/s).
 *
 * Several cards for one switch (one per VLAN address) are read once: see
 * network_device_ids().
 */

const NETLIVE_MIN_GAP_MS = 2500;      // shared sample: reread a device at most this often
const NETLIVE_ERROR_BACKOFF_MS = 300000; // a device that didn't answer is retried after this (each try can take seconds)
const NETLIVE_TIMEOUT_SEC = 1.0;

const OID_IF_HC_IN = '.1.3.6.1.2.1.31.1.1.1.6';
const OID_IF_HC_OUT = '.1.3.6.1.2.1.31.1.1.1.10';
const OID_IF_IN_OCTETS = '.1.3.6.1.2.1.2.2.1.10';
const OID_IF_OUT_OCTETS = '.1.3.6.1.2.1.2.2.1.16';

/**
 * [hostId => [hostIds of the same device]]: one entry per physical switch or
 * router that has a port map. A device is identified by its ports' MAC
 * addresses; the card with a configuration backup (else the oldest) leads.
 */
function network_device_groups(): array {
    $pdo = db();
    $withBackup = array_flip(array_map('intval', $pdo->query('SELECT host_id FROM config_backup_settings')->fetchAll(PDO::FETCH_COLUMN)));
    $groups = [];
    foreach ($pdo->query('SELECT m.host_id, m.data FROM port_maps m JOIN hosts h ON h.id = m.host_id') as $r) {
        $macs = array_values(array_unique(array_filter(array_column(json_decode($r['data'], true)['ports'] ?? [], 'mac'))));
        sort($macs);
        $groups[$macs ? md5(implode(',', $macs)) : 'host' . $r['host_id']][] = (int) $r['host_id'];
    }
    $out = [];
    foreach ($groups as $ids) {
        usort($ids, fn($a, $b) => [!isset($withBackup[$a]), $a] <=> [!isset($withBackup[$b]), $b]);
        $out[$ids[0]] = $ids;
    }
    return $out;
}

/** SNMPv2c walk that keeps Counter64 values (snmprealwalk is SNMPv1). [suffix => string]. */
function snmp2_walk_raw(string $ip, string $community, string $oid, float $timeoutSec): array {
    $prefix = ltrim($oid, '.') . '.';
    $out = [];
    if (function_exists('snmp2_real_walk')) {
        snmp_set_quick_print(true);
        snmp_set_valueretrieval(SNMP_VALUE_PLAIN);
        snmp_set_oid_output_format(SNMP_OID_OUTPUT_NUMERIC);
        $walk = @snmp2_real_walk($ip, $community, $oid, (int) round($timeoutSec * 1_000_000), 0);
        if (!is_array($walk)) return [];
        foreach ($walk as $k => $v) {
            $k = ltrim((string) $k, '.');
            if (str_starts_with($k, $prefix)) $out[substr($k, strlen($prefix))] = trim((string) $v, " \t\"");
        }
        return $out;
    }
    $bin = trim((string) shell_exec('command -v snmpbulkwalk 2>/dev/null'));
    if ($bin === '') return snmp_walk($ip, $community, $oid, $timeoutSec);
    exec(escapeshellarg($bin) . ' -Oqn -v2c -c ' . escapeshellarg($community) . ' -t ' . max(1, (int) round($timeoutSec))
        . ' -r 0 ' . escapeshellarg($ip) . ' ' . escapeshellarg($oid) . ' 2>/dev/null', $lines);
    foreach ($lines as $line) {
        if (preg_match('/^\.?(\S+)\s+(\S+)/', $line, $m) && str_starts_with($m[1], $prefix)) $out[substr($m[1], strlen($prefix))] = $m[2];
    }
    return $out;
}

/**
 * Octet counters of a device's ports: ['bits' => 64|32, 'c' => [ifIndex => [in, out]]].
 * Only the ports of its port map are kept (when it has one).
 */
function read_iface_counters(array $host, ?array $portIdx): array {
    $ip = $host['ip_address'];
    $c = $host['community'] !== '' ? $host['community'] : 'public';
    $bits = 64;
    $in = snmp2_walk_raw($ip, $c, OID_IF_HC_IN, NETLIVE_TIMEOUT_SEC);
    $out = $in ? snmp2_walk_raw($ip, $c, OID_IF_HC_OUT, NETLIVE_TIMEOUT_SEC) : [];
    if (!$in) {   // no 64-bit counters (or SNMPv1 only)
        $bits = 32;
        $in = snmp_walk($ip, $c, OID_IF_IN_OCTETS, NETLIVE_TIMEOUT_SEC);
        $out = $in ? snmp_walk($ip, $c, OID_IF_OUT_OCTETS, NETLIVE_TIMEOUT_SEC) : [];
    }
    if (!$in) throw new RuntimeException('no answer to SNMP');
    $counters = [];
    foreach ($in as $idx => $v) {
        $idx = (int) $idx;
        if ($portIdx !== null && !isset($portIdx[$idx])) continue;
        if (!is_numeric($v) || !isset($out[$idx]) || !is_numeric($out[$idx])) continue;
        $counters[$idx] = [(float) $v, (float) $out[$idx]];
    }
    return ['bits' => $bits, 'c' => $counters];
}

/** bit/s per port between two counter sets; a counter that went backwards (reset) gives no rate. */
function iface_rates(array $old, array $new, float $sec): array {
    if ($sec <= 0.5) return [];
    $wrap = ($new['bits'] ?? 64) === 32 ? 4294967296.0 : null;
    $rates = [];
    foreach ($new['c'] as $idx => [$i, $o]) {
        if (!isset($old['c'][$idx])) continue;
        $d = [];
        foreach ([[$i, $old['c'][$idx][0]], [$o, $old['c'][$idx][1]]] as [$now, $then]) {
            $delta = $now - $then;
            if ($delta < 0 && $wrap) $delta += $wrap;
            $d[] = $delta < 0 ? null : round($delta * 8 / $sec);
        }
        if ($d[0] !== null && $d[1] !== null) $rates[$idx] = $d;
    }
    return $rates;
}

/** [hostId => ifIndex set] of the stored port maps. */
function port_map_ifindexes(array $ids): array {
    if (!$ids) return [];
    $out = [];
    foreach (db()->query('SELECT host_id, data FROM port_maps WHERE host_id IN (' . implode(',', array_map('intval', $ids)) . ')') as $r) {
        $out[(int) $r['host_id']] = array_flip(array_map('intval', array_column(json_decode($r['data'], true)['ports'] ?? [], 'ifIndex')));
    }
    return $out;
}

/**
 * Fresh rates of one device (sampled now unless someone did in the last
 * NETLIVE_MIN_GAP_MS). Returns the iface_samples row as an array.
 */
function sample_device(array $host, ?array $portIdx): array {
    $pdo = db();
    $id = (int) $host['id'];
    $lock = "webmonitor_iface_$id";
    $st = $pdo->prepare('SELECT GET_LOCK(?, 5)');
    $st->execute([$lock]);
    $locked = (int) $st->fetchColumn();
    try {
        $sel = $pdo->prepare('SELECT * FROM iface_samples WHERE host_id = ?');
        $sel->execute([$id]);
        $row = $sel->fetch() ?: null;
        $now = (int) round(microtime(true) * 1000);
        if ($row && $now - (int) $row['live_at'] < ($row['error'] ? NETLIVE_ERROR_BACKOFF_MS : NETLIVE_MIN_GAP_MS)) return $row;
        try {
            $new = read_iface_counters($host, $portIdx);
        } catch (Throwable $e) {
            $pdo->prepare('INSERT INTO iface_samples (host_id, live_at, live_counters, rates, error) VALUES (?, ?, ?, NULL, ?)
                           ON DUPLICATE KEY UPDATE live_at = VALUES(live_at), error = VALUES(error), rates = NULL')
                ->execute([$id, $now, $row['live_counters'] ?? '{}', mb_substr($e->getMessage(), 0, 255)]);
            return ['host_id' => $id, 'live_at' => $now, 'rates' => null, 'error' => $e->getMessage()] + ($row ?? []);
        }
        $old = $row && !$row['error'] ? json_decode($row['live_counters'], true) : null;
        // Older than 2 minutes: too long ago to call it "live".
        $rates = $old && $now - (int) $row['live_at'] < 120000 ? iface_rates($old, $new, ($now - (int) $row['live_at']) / 1000) : null;
        $pdo->prepare('INSERT INTO iface_samples (host_id, live_at, live_counters, rates, error) VALUES (?, ?, ?, ?, NULL)
                       ON DUPLICATE KEY UPDATE live_at = VALUES(live_at), live_counters = VALUES(live_counters), rates = VALUES(rates), error = NULL')
            ->execute([$id, $now, json_encode($new), $rates === null ? null : json_encode((object) $rates)]);
        $sel->execute([$id]);
        return $sel->fetch();
    } finally {
        if ($locked) $pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$lock]);
    }
}

/**
 * Live rates of every network device (or the given ones):
 * [hostId => ['at' => ms, 'error' => ?string, 'rates' => {ifIndex: [in, out]} | null]].
 */
function live_network_rates(?array $only = null): array {
    $groups = network_device_groups();
    $ids = array_keys($groups);
    if ($only !== null) $ids = array_values(array_intersect($ids, $only));
    if (!$ids) return [];
    $hosts = db()->query('SELECT * FROM hosts WHERE id IN (' . implode(',', $ids) . ')')->fetchAll();
    $portIdx = port_map_ifindexes($ids);
    $out = [];
    foreach ($hosts as $h) {
        $r = sample_device($h, $portIdx[(int) $h['id']] ?? null);
        $out[(int) $h['id']] = [
            'at' => (int) $r['live_at'],
            'error' => $r['error'] ?: null,
            'rates' => $r['rates'] ? json_decode($r['rates'], true) : null,
        ];
    }
    return $out;
}

/**
 * Cron, every minute: samples every network device and records each port's
 * average rate since the previous minute (ports that are up only).
 */
function record_port_traffic(): void {
    $pdo = db();
    if (!(int) $pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'iface_samples'")->fetchColumn()) return;
    if (!(int) $pdo->query("SELECT GET_LOCK('webmonitor_port_traffic', 0)")->fetchColumn()) return;
    try {
        $groups = network_device_groups();
        if (!$groups) return;
        $ids = implode(',', array_keys($groups));
        $hosts = $pdo->query("SELECT * FROM hosts WHERE id IN ($ids)")->fetchAll();
        $maps = [];
        foreach ($pdo->query("SELECT host_id, data FROM port_maps WHERE host_id IN ($ids)") as $r) {
            $maps[(int) $r['host_id']] = array_column(json_decode($r['data'], true)['ports'] ?? [], null, 'ifIndex');
        }
        foreach ($hosts as $h) {
            $id = (int) $h['id'];
            $ports = $maps[$id] ?? [];
            $row = sample_device($h, $ports ? array_fill_keys(array_keys($ports), true) : null);
            if (!empty($row['error'])) continue;
            $live = json_decode($row['live_counters'], true);
            $liveAt = (int) $row['live_at'];
            $prev = $row['minute_counters'] ? json_decode($row['minute_counters'], true) : null;
            $age = ($liveAt - (int) $row['minute_at']) / 1000;
            if ($prev && $age >= 30 && $age <= 600) {
                $points = [];
                foreach (iface_rates($prev, $live, $age) as $idx => [$in, $out]) {
                    $p = $ports[$idx] ?? null;
                    if ($ports && (!$p || $p['status'] !== 'up')) continue;
                    $name = $p['name'] ?? "if$idx";
                    $points[] = ['port_in', $name, 'bit/s', $in];
                    $points[] = ['port_out', $name, 'bit/s', $out];
                }
                record_points($id, $points, intdiv($liveAt, 1000));
            }
            if (!$prev || $age >= 30 || $age < 0) {
                $pdo->prepare('UPDATE iface_samples SET minute_at = ?, minute_counters = ? WHERE host_id = ?')
                    ->execute([$liveAt, $row['live_counters'], $id]);
            }
        }
    } finally {
        $pdo->query("SELECT RELEASE_LOCK('webmonitor_port_traffic')");
    }
}

/** [hostId => [{i, name, speed, status, lag, desc, ips}]] of every network device, for matching rates to port names. */
function network_port_index(): array {
    $ids = array_keys(network_device_groups());
    if (!$ids) return [];
    $out = [];
    foreach (db()->query('SELECT host_id, data FROM port_maps WHERE host_id IN (' . implode(',', $ids) . ')') as $r) {
        foreach (json_decode($r['data'], true)['ports'] ?? [] as $p) {
            $out[(int) $r['host_id']][] = ['i' => (int) $p['ifIndex'], 'name' => $p['name'], 'speed' => $p['speedMbps'],
                'status' => $p['status'], 'lag' => $p['lag'] ?? null, 'desc' => $p['description'] ?? '', 'ips' => $p['ips'] ?? []];
        }
    }
    return $out;
}

/** History of one port: ['in' => [[ts, avg, min, max], ...], 'out' => [...]]. */
function port_history(int $hostId, string $port, string $range): array {
    [$span, $bucket, $source] = HISTORY_RANGES[$range] ?? HISTORY_RANGES['24h'];
    $pdo = db();
    $st = $pdo->prepare("SELECT id, metric FROM metric_series WHERE host_id = ? AND subject = ? AND metric IN ('port_in', 'port_out')");
    $st->execute([$hostId, $port]);
    $out = ['in' => [], 'out' => []];
    foreach ($st->fetchAll() as $s) {
        $sql = $source === 'raw'
            ? "SELECT ts - MOD(ts, $bucket) t, AVG(value), MIN(value), MAX(value) FROM metric_points WHERE series_id = ? AND ts >= ? GROUP BY t ORDER BY t"
            : "SELECT ts - MOD(ts, $bucket) t, SUM(avg_value * samples) / SUM(samples), MIN(min_value), MAX(max_value) FROM metric_hourly WHERE series_id = ? AND ts >= ? GROUP BY t ORDER BY t";
        $q = $pdo->prepare($sql);
        $q->execute([(int) $s['id'], time() - $span]);
        $out[$s['metric'] === 'port_in' ? 'in' : 'out'] = array_map(fn($r) => [(int) $r[0], round((float) $r[1]), round((float) $r[2]), round((float) $r[3])], $q->fetchAll(PDO::FETCH_NUM));
    }
    return $out;
}
