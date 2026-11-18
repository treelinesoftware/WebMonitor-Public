<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/flows.php';
require_once __DIR__ . '/netlive.php';
require_once __DIR__ . '/portmap.php';

/**
 * What's using a switch port, from NetFlow (Network tab, port details).
 *
 * Two ways, picked per port:
 *   exact  the switch's flows name this port as their input interface (a
 *          Catalyst 3850 does even with the monitor on VLAN interfaces): what
 *          the port sends is exact; what it receives comes from matching by
 *          IP unless output interfaces are exported too.
 *   ip     otherwise: the flows of the devices the port map puts on this
 *          port (by IP), from whichever exporter saw them.
 * Either way, only traffic that passes a switch exporting NetFlow is seen.
 */

const PORTFLOW_RANGES = ['15m' => [900, 60], '1h' => [3600, 300], '6h' => [21600, 900], '24h' => [86400, 1800], '7d' => [604800, 3 * 3600]];

/** Addresses a device exports flows from: every card of it, plus its interface addresses. */
function device_exporter_ips(int $hostId): array {
    $ids = [$hostId];
    foreach (network_device_groups() as $main => $group) if (in_array($hostId, $group, true)) $ids = $group;
    $pdo = db();
    $ips = $pdo->query('SELECT ip_address FROM hosts WHERE id IN (' . implode(',', array_map('intval', $ids)) . ')')->fetchAll(PDO::FETCH_COLUMN);
    $st = $pdo->prepare('SELECT data FROM port_maps WHERE host_id = ?');
    $st->execute([$hostId]);
    foreach (json_decode((string) $st->fetchColumn(), true)['ports'] ?? [] as $p) {
        foreach (array_merge($p['ips'] ?? [], ...array_map(fn($x) => $x['ips'] ?? [], $p['subs'] ?? [])) as $cidr) $ips[] = explode('/', $cidr)[0];
    }
    return array_values(array_unique($ips));
}

/** [ifIndex => name] of a device: its port map, plus ifName over SNMP for the rest (VLAN interfaces), cached a day. */
function device_if_names(array $host): array {
    $pdo = db();
    $names = [];
    $st = $pdo->prepare('SELECT data FROM port_maps WHERE host_id = ?');
    $st->execute([(int) $host['id']]);
    foreach (json_decode((string) $st->fetchColumn(), true)['ports'] ?? [] as $p) $names[(int) $p['ifIndex']] = $p['name'];
    $key = 'ifnames:' . (int) $host['id'];
    $st = $pdo->prepare('SELECT v FROM settings WHERE k = ?');
    $st->execute([$key]);
    $cached = json_decode((string) $st->fetchColumn(), true);
    if (!$cached || ($cached['at'] ?? 0) < time() - 86400) {
        $walk = snmp_walk($host['ip_address'], $host['community'] !== '' ? $host['community'] : 'public', OID_IF_NAME, 2.0);
        if ($walk) {
            $cached = ['at' => time(), 'names' => array_map('strval', $walk)];
            $pdo->prepare('INSERT INTO settings (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)')->execute([$key, json_encode($cached)]);
        }
    }
    foreach ($cached['names'] ?? [] as $idx => $n) $names[(int) $idx] ??= $n;
    return $names;
}

/**
 * Where a device's flow monitors are, from its configuration backup: 'ports' (on physical
 * ports: everything entering them is seen), 'vlans' (only on VLAN interfaces: routed and
 * internet traffic only), or null when unknown.
 */
function flow_monitor_scope(int $hostId): ?string {
    static $cache = [];
    if (array_key_exists($hostId, $cache)) return $cache[$hostId];
    $st = db()->prepare('SELECT content FROM switch_configs WHERE host_id = ? ORDER BY fetched_at DESC LIMIT 1');
    $st->execute([$hostId]);
    $if = null;
    $ports = $vlans = false;
    foreach (explode("\n", (string) $st->fetchColumn()) as $line) {
        if (preg_match('/^interface (\S+)/', $line, $m)) $if = $m[1];
        elseif ($if !== null && preg_match('/^\s+(ip|ipv6) flow monitor /', $line)) {
            if (preg_match('/^(Vlan|BDI|Loopback)/i', $if)) $vlans = true; else $ports = true;
        } elseif ($line !== '' && $line[0] !== ' ') $if = null;
    }
    return $cache[$hostId] = $ports ? 'ports' : ($vlans ? 'vlans' : null);
}

function portflow_tables_ready(): bool {
    return (bool) db()->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'flow_iface_conv'")->fetchColumn();
}

// How long a report is reused (seconds): long ranges are slow to add up and change slowly.
const PORTFLOW_CACHE_SEC = ['15m' => 30, '1h' => 60, '6h' => 180, '24h' => 600, '7d' => 1800];

/** port_flow_report(), reused for a while (settings table, so every viewer shares it). */
function port_flow_report_cached(int $hostId, int $ifIndex, string $range): array {
    $pdo = db();
    $key = "pflow:$hostId:$ifIndex:$range";
    $st = $pdo->prepare('SELECT v FROM settings WHERE k = ?');
    $st->execute([$key]);
    $hit = json_decode((string) $st->fetchColumn(), true);
    if ($hit && ($hit['at'] ?? 0) > time() - (PORTFLOW_CACHE_SEC[$range] ?? 60)) return $hit['r'];
    $r = port_flow_report($hostId, $ifIndex, $range);
    $r['generatedAt'] = time();
    $pdo->prepare('INSERT INTO settings (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)')->execute([$key, json_encode(['at' => time(), 'r' => $r])]);
    if (random_int(1, 50) === 1) {   // now and then, drop old entries
        foreach ($pdo->query("SELECT k, v FROM settings WHERE k LIKE 'pflow:%'")->fetchAll() as $row) {
            if ((json_decode($row['v'], true)['at'] ?? 0) < time() - 3600) $pdo->prepare('DELETE FROM settings WHERE k = ?')->execute([$row['k']]);
        }
    }
    return $r;
}

/** The NetFlow breakdown of one port. */
function port_flow_report(int $hostId, int $ifIndex, string $range): array {
    [$span, $bucket] = PORTFLOW_RANGES[$range] ?? PORTFLOW_RANGES['1h'];
    $pdo = db();
    $from = intdiv(time() - $span, 60) * 60;
    $convFrom = max($from, time() - FLOW_CONV_KEEP_DAYS * 86400);
    $view = port_map_view($hostId);
    $port = null;
    foreach ($view['ports'] ?? [] as $p) if ((int) $p['ifIndex'] === $ifIndex) $port = $p;
    if (!$port) throw new RuntimeException('That port is not in the port map.');
    [$who, $names] = flow_name_resolver();
    $base = ['range' => $range, 'bucket' => $bucket, 'from' => $from, 'port' => $port['name'], 'role' => $port['role']];

    // --- Exact: flows that came in on this very port (their input interface). Most exporters
    // send only that, so it's the "sent from this port" side; what the port received comes
    // from matching by IP below, unless output interfaces are reported too.
    $exact = null;
    if (portflow_tables_ready()) {
        $exp = device_exporter_ips($hostId);
        $in = implode(',', array_fill(0, count($exp), '?'));
        $q = $pdo->prepare("SELECT ts - MOD(ts, $bucket) t, SUM(CASE WHEN dir = 'i' THEN bytes ELSE 0 END) bi, SUM(CASE WHEN dir = 'o' THEN bytes ELSE 0 END) bo
                            FROM flow_ifaces WHERE exporter IN ($in) AND if_index = ? AND ts >= ? GROUP BY t ORDER BY t");
        $q->execute([...$exp, $ifIndex, $from]);
        $rows = $q->fetchAll();
        if ($rows) {
            // [t, to the device(s) (out of the switch on this port), from them (into the switch)], bit/s.
            $series = array_map(fn($r) => [(int) $r['t'], round($r['bo'] * 8 / $bucket), round($r['bi'] * 8 / $bucket)], $rows);
            $q = $pdo->prepare("SELECT src_ip, dst_ip, proto, port, SUM(bytes) b FROM flow_iface_conv WHERE exporter IN ($in) AND if_index = ? AND ts >= ?
                                GROUP BY src_ip, dst_ip, proto, port ORDER BY b DESC LIMIT 500");
            $q->execute([...$exp, $ifIndex, $convFrom]);
            $exactConv = [];
            foreach ($q->fetchAll() as $r) {
                // Entering the switch on this port: sent by the device(s) behind it.
                $exactConv[] = ['local' => $r['src_ip'], 'remote' => $r['dst_ip'], 'proto' => (int) $r['proto'], 'port' => (int) $r['port'], 'in' => 0, 'out' => (int) $r['b']];
            }
            $exact = ['series' => $series, 'conv' => $exactConv, 'sent' => array_sum(array_column($rows, 'bi')), 'received' => array_sum(array_column($rows, 'bo'))];
        }
    }
    $base['scope'] = flow_monitor_scope($hostId);

    // --- By IP: the devices the port map puts on this port.
    $ips = [];
    foreach ($port['devices'] as $d) if ($d['ip']) $ips[$d['ip']] = true;
    if ($port['role'] !== 'uplink') foreach ($port['neighbors'] as $n) if (!empty($n['ip'])) $ips[$n['ip']] = true;
    $ips = array_keys($ips);
    if (!$ips) {
        if ($exact) {
            return $base + ['mode' => 'exact', 'received' => $exact['received'] ? 'exact' : null, 'series' => $exact['series'],
                'totals' => ['in' => $exact['received'], 'out' => $exact['sent'], 'inetIn' => null, 'inetOut' => null]]
                + portflow_breakdown($exact['conv'], $who, $names, []);
        }
        return $base + ['mode' => 'none', 'reason' => $port['status'] !== 'up' ? 'The port is down.' : 'No device with a known IP address is seen on this port.'];
    }

    $in = implode(',', array_fill(0, count($ips), '?'));
    $q = $pdo->prepare("SELECT ts - MOD(ts, $bucket) t, SUM(bytes_in) i, SUM(bytes_out) o FROM flow_hosts WHERE ip IN ($in) AND ts >= ? GROUP BY t ORDER BY t");
    $q->execute([...$ips, $from]);
    $series = array_map(fn($r) => [(int) $r['t'], round($r['i'] * 8 / $bucket), round($r['o'] * 8 / $bucket)], $q->fetchAll());
    $q = $pdo->prepare("SELECT ip, SUM(bytes_in) i, SUM(bytes_out) o, SUM(inet_in) ii, SUM(inet_out) io FROM flow_hosts WHERE ip IN ($in) AND ts >= ? GROUP BY ip");
    $q->execute([...$ips, $from]);
    $perIp = $q->fetchAll();
    $totals = ['in' => 0, 'out' => 0, 'inetIn' => 0, 'inetOut' => 0];
    $devices = [];
    foreach ($perIp as $r) {
        $totals['in'] += $r['i']; $totals['out'] += $r['o']; $totals['inetIn'] += $r['ii']; $totals['inetOut'] += $r['io'];
        $devices[] = $who($r['ip']) + ['in' => (int) $r['i'], 'out' => (int) $r['o']];
    }
    usort($devices, fn($a, $b) => ($b['in'] + $b['out']) <=> ($a['in'] + $a['out']));

    // A port towards the router carries the internet traffic of everything (NAT hides it behind the router's address).
    $isRouter = fn($h) => ($h['device'] ?? '') === 'router';
    $toRouter = (bool) array_filter($port['devices'], fn($d) => $isRouter($d['host'] ?? null))
        || (bool) array_filter($port['neighbors'], fn($n) => $isRouter($n['host'] ?? null))
        || (bool) array_filter($port['towards'] ?? [], $isRouter);
    if ($toRouter) {
        $q = $pdo->prepare("SELECT ts - MOD(ts, $bucket) t, SUM(down_bytes) d, SUM(up_bytes) u FROM flow_totals WHERE ts >= ? GROUP BY t");
        $q->execute([$from]);
        $byT = [];
        foreach ($series as $pt) $byT[$pt[0]] = $pt;
        foreach ($q->fetchAll() as $r) {
            $t = (int) $r['t'];
            $byT[$t] = [$t, ($byT[$t][1] ?? 0) + round($r['d'] * 8 / $bucket), ($byT[$t][2] ?? 0) + round($r['u'] * 8 / $bucket)];
            $totals['in'] += $r['d']; $totals['out'] += $r['u']; $totals['inetIn'] += $r['d']; $totals['inetOut'] += $r['u'];
        }
        ksort($byT);
        $series = array_values($byT);
    }

    // Conversations: the device as the local end, or as the far end of internal traffic.
    $conv = [];
    $q = $pdo->prepare("SELECT local_ip, remote_ip, proto, port, SUM(bytes_in) i, SUM(bytes_out) o FROM flow_conv
                        WHERE local_ip IN ($in) AND ts >= ? GROUP BY local_ip, remote_ip, proto, port ORDER BY SUM(bytes_in) + SUM(bytes_out) DESC LIMIT 500");
    $q->execute([...$ips, $convFrom]);
    foreach ($q->fetchAll() as $r) $conv[] = ['local' => $r['local_ip'], 'remote' => $r['remote_ip'], 'proto' => (int) $r['proto'], 'port' => (int) $r['port'], 'in' => (int) $r['i'], 'out' => (int) $r['o']];
    $q = $pdo->prepare("SELECT local_ip, remote_ip, proto, port, SUM(bytes_in) i, SUM(bytes_out) o FROM flow_conv
                        WHERE remote_ip IN ($in) AND local_ip NOT IN ($in) AND ts >= ? GROUP BY local_ip, remote_ip, proto, port ORDER BY SUM(bytes_in) + SUM(bytes_out) DESC LIMIT 500");
    $q->execute([...$ips, ...$ips, $convFrom]);
    // Seen from the other end: its "in" is what this device sent.
    foreach ($q->fetchAll() as $r) $conv[] = ['local' => $r['remote_ip'], 'remote' => $r['local_ip'], 'proto' => (int) $r['proto'], 'port' => (int) $r['port'], 'in' => (int) $r['o'], 'out' => (int) $r['i']];
    if ($toRouter) {
        $q = $pdo->prepare("SELECT local_ip, remote_ip, proto, port, SUM(bytes_in) i, SUM(bytes_out) o FROM flow_conv WHERE ts >= ?
                            AND remote_ip NOT LIKE '10.%' AND remote_ip NOT LIKE '192.168.%' AND remote_ip NOT REGEXP '^172\\.(1[6-9]|2[0-9]|3[01])\\.'
                            AND remote_ip NOT REGEXP '^(22[4-9]|23[0-9])\\.' AND remote_ip NOT LIKE 'f%' AND remote_ip <> '255.255.255.255'
                            GROUP BY local_ip, remote_ip, proto, port ORDER BY SUM(bytes_in) + SUM(bytes_out) DESC LIMIT 800");
        $q->execute([$convFrom]);
        $inetByIp = [];
        foreach ($q->fetchAll() as $r) {
            if (in_array($r['local_ip'], $ips, true)) continue;   // already counted above
            $conv[] = ['local' => $r['local_ip'], 'remote' => $r['remote_ip'], 'proto' => (int) $r['proto'], 'port' => (int) $r['port'], 'in' => (int) $r['i'], 'out' => (int) $r['o']];
            $inetByIp[$r['local_ip']] ??= ['in' => 0, 'out' => 0];
            $inetByIp[$r['local_ip']]['in'] += $r['i'];
            $inetByIp[$r['local_ip']]['out'] += $r['o'];
        }
        foreach ($inetByIp as $ip => $t) $devices[] = $who((string) $ip) + $t;
        usort($devices, fn($a, $b) => ($b['in'] + $b['out']) <=> ($a['in'] + $a['out']));
    }

    if ($exact) {
        // Sent: exact. Received: exact too when the exporter reports output interfaces, else by IP.
        $both = $exact['received'] > 0;
        $byT = [];
        foreach ($series as $pt) $byT[$pt[0]] = [$pt[0], $both ? 0 : $pt[1], 0];
        foreach ($exact['series'] as [$t, $rcv, $sent]) $byT[$t] = [$t, $both ? $rcv : ($byT[$t][1] ?? 0), $sent];
        ksort($byT);
        $totals['out'] = $exact['sent'];
        if ($both) $totals['in'] = $exact['received'];
        $conv = $both ? [] : array_values(array_filter(array_map(fn($c) => array_merge($c, ['out' => 0]), $conv), fn($c) => $c['in'] > 0));
        return $base + ['mode' => 'exact', 'received' => $both ? 'exact' : 'ip', 'ips' => count($ips), 'internetPath' => $toRouter,
                        'series' => array_values($byT), 'totals' => $totals]
            + portflow_breakdown(array_merge($conv, $exact['conv']), $who, $names, []);
    }
    return $base + ['mode' => 'ip', 'ips' => count($ips), 'internetPath' => $toRouter, 'series' => $series, 'totals' => $totals]
        + portflow_breakdown($conv, $who, $names, $devices);
}

/** Top services, far ends and conversations from a list of {local, remote, proto, port, in, out}. */
function portflow_breakdown(array $conv, callable $who, array $names, array $devices): array {
    $isLocal = fn(string $ip) => (bool) preg_match('/^(10\.|192\.168\.|172\.(1[6-9]|2\d|3[01])\.|f[cd]|fe80)/i', $ip);
    $isNoise = fn(string $ip) => (bool) preg_match('/^(22[4-9]|23\d)\.|^255\.255\.255\.255$|^ff/i', $ip);
    $services = [];
    $remotes = [];
    $byLocal = [];
    foreach ($conv as $c) {
        $svc = flow_service_name($c['proto'], $c['port']);
        $services[$svc] ??= ['name' => $svc, 'in' => 0, 'out' => 0];
        $services[$svc]['in'] += $c['in'];
        $services[$svc]['out'] += $c['out'];
        if (!$isNoise($c['remote'])) {
            $remotes[$c['remote']] ??= ['ip' => $c['remote'], 'local' => $isLocal($c['remote']), 'name' => $names[$c['remote']]['name'] ?? null,
                                        'hostId' => $names[$c['remote']]['hostId'] ?? null, 'in' => 0, 'out' => 0, 'services' => []];
            $remotes[$c['remote']]['in'] += $c['in'];
            $remotes[$c['remote']]['out'] += $c['out'];
            $remotes[$c['remote']]['services'][$svc] = ($remotes[$c['remote']]['services'][$svc] ?? 0) + $c['in'] + $c['out'];
        }
        if (!$devices) {
            $byLocal[$c['local']] ??= ['in' => 0, 'out' => 0];
            $byLocal[$c['local']]['in'] += $c['in'];
            $byLocal[$c['local']]['out'] += $c['out'];
        }
    }
    if (!$devices) foreach ($byLocal as $ip => $t) $devices[] = $who((string) $ip) + $t;
    $sortTotal = fn($a, $b) => ($b['in'] + $b['out']) <=> ($a['in'] + $a['out']);
    usort($devices, $sortTotal);
    $services = array_values($services);
    usort($services, $sortTotal);
    $remotes = array_values($remotes);
    usort($remotes, $sortTotal);
    foreach ($remotes as &$r) { arsort($r['services']); $r['services'] = array_slice(array_keys($r['services']), 0, 3); }
    unset($r);
    usort($conv, $sortTotal);
    $conv = array_map(fn($c) => ['local' => $who($c['local']), 'remote' => $c['remote'], 'remoteLocal' => $isLocal($c['remote']),
        'remoteName' => $names[$c['remote']]['name'] ?? null, 'remoteHostId' => $names[$c['remote']]['hostId'] ?? null,
        'service' => flow_service_name($c['proto'], $c['port']), 'in' => $c['in'], 'out' => $c['out']],
        array_values(array_filter(array_slice($conv, 0, 60), fn($c) => !$isNoise($c['remote']))));
    return ['devices' => array_slice($devices, 0, 15), 'services' => array_slice($services, 0, 10), 'remotes' => array_slice($remotes, 0, 12),
            'conversations' => array_slice($conv, 0, 20)];
}

/**
 * NetFlow a device exports, per interface: [{ifIndex, name, physical, in, out, flows}], plus
 * whether its flow monitors are on physical ports (exact per-port data) or only on VLAN interfaces.
 */
function device_flow_interfaces(int $hostId, string $range): array {
    [$span] = PORTFLOW_RANGES[$range] ?? PORTFLOW_RANGES['1h'];
    if (!portflow_tables_ready()) return ['exporter' => false, 'interfaces' => []];
    $pdo = db();
    $exp = device_exporter_ips($hostId);
    $in = implode(',', array_fill(0, count($exp), '?'));
    $q = $pdo->prepare("SELECT if_index, SUM(CASE WHEN dir = 'i' THEN bytes ELSE 0 END) bi, SUM(CASE WHEN dir = 'o' THEN bytes ELSE 0 END) bo, SUM(flows) f
                        FROM flow_ifaces WHERE exporter IN ($in) AND ts >= ? GROUP BY if_index ORDER BY SUM(bytes) DESC");
    $q->execute([...$exp, time() - $span]);
    $rows = $q->fetchAll();
    // Not exporting (yet): is it one of the collector's exporters at all?
    $status = json_decode((string) $pdo->query("SELECT v FROM settings WHERE k = 'flow_status'")->fetchColumn(), true);
    $isExporter = (bool) array_intersect($exp, array_keys($status['exporters'] ?? []));
    if (!$rows) return ['exporter' => $isExporter, 'interfaces' => [], 'physical' => false];
    $st = $pdo->prepare('SELECT * FROM hosts WHERE id = ?');
    $st->execute([$hostId]);
    $names = device_if_names($st->fetch());
    $phys = port_map_ifindexes([$hostId])[$hostId] ?? [];
    // The suggested change, from its configuration backup: the flow monitor in use, applied to its port ranges.
    $st = $pdo->prepare('SELECT content FROM switch_configs WHERE host_id = ? ORDER BY fetched_at DESC LIMIT 1');
    $st->execute([$hostId]);
    $cfg = (string) $st->fetchColumn();
    $monitor = preg_match('/^\s*ip flow monitor (\S+) input/m', $cfg, $m) ? $m[1] : (preg_match('/^flow monitor (\S+)/m', $cfg, $m) ? $m[1] : null);
    $ranges = [];
    $st = $pdo->prepare('SELECT data FROM port_maps WHERE host_id = ?');
    $st->execute([$hostId]);
    foreach (json_decode((string) $st->fetchColumn(), true)['ports'] ?? [] as $p) {
        if (preg_match('#^(.*/)(\d+)$#', $p['name'], $m)) $ranges[$m[1]][] = (int) $m[2];
    }
    $suggest = [];
    foreach ($ranges as $prefix => $nums) $suggest[] = 'interface range ' . $prefix . min($nums) . ' - ' . max($nums) . "\n ip flow monitor " . ($monitor ?? '<monitor name>') . ' input';
    $out = array_map(fn($r) => ['ifIndex' => (int) $r['if_index'], 'name' => $names[(int) $r['if_index']] ?? 'if' . $r['if_index'],
        'physical' => isset($phys[(int) $r['if_index']]), 'in' => (int) $r['bi'], 'out' => (int) $r['bo'], 'flows' => (int) $r['f']], $rows);
    return ['exporter' => true, 'interfaces' => $out, 'physical' => (bool) array_filter($out, fn($i) => $i['physical']),
            'monitor' => $monitor, 'scope' => flow_monitor_scope($hostId), 'suggest' => implode("\n", $suggest)];
}
