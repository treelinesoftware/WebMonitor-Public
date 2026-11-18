<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/history.php';

/**
 * Network traffic from NetFlow/IPFIX. cli/flow-collector.py (the
 * webmonitor-flow service) writes one summary per minute to FLOW_DIR;
 * cli/cron.php loads them every minute with ingest_flows(). Detail is kept
 * FLOW_KEEP_DAYS; each monitored host's traffic also goes into the history
 * graphs (flow_in / flow_out, bytes per second), kept for a year+.
 */

const FLOW_DIR = '/var/lib/webmonitor/flows';
const FLOW_KEEP_DAYS = 14;
const FLOW_CONV_KEEP_DAYS = 3;

// Service names for the usual ports ([proto][port]; 6 TCP, 17 UDP).
const FLOW_SERVICES = [
    6 => [111 => 'RPC (NFS)', 873 => 'rsync', 2049 => 'NFS', 20 => 'FTP data', 21 => 'FTP', 22 => 'SSH', 23 => 'Telnet', 25 => 'SMTP', 53 => 'DNS', 80 => 'HTTP', 110 => 'POP3', 143 => 'IMAP',
          443 => 'HTTPS', 445 => 'SMB', 465 => 'SMTPS', 587 => 'SMTP submission', 853 => 'DNS over TLS', 993 => 'IMAPS', 995 => 'POP3S',
          1194 => 'OpenVPN', 1883 => 'MQTT', 1935 => 'RTMP streaming', 2049 => 'NFS', 3074 => 'Xbox Live', 3306 => 'MySQL', 3389 => 'Remote Desktop',
          3478 => 'PlayStation / STUN', 3479 => 'PlayStation', 3480 => 'PlayStation', 5222 => 'XMPP chat', 5223 => 'Apple push', 5228 => 'Google Play',
          5900 => 'VNC', 8006 => 'Proxmox', 8080 => 'HTTP alt', 8443 => 'HTTPS alt', 8883 => 'MQTT TLS', 9100 => 'Printing', 27015 => 'Steam',
          32400 => 'Plex'],
    17 => [53 => 'DNS', 67 => 'DHCP', 68 => 'DHCP', 123 => 'NTP', 137 => 'NetBIOS', 161 => 'SNMP', 443 => 'QUIC (HTTP/3)', 500 => 'IPsec',
           514 => 'Syslog', 1194 => 'OpenVPN', 1900 => 'SSDP', 2055 => 'NetFlow', 3074 => 'Xbox Live', 3478 => 'STUN / PlayStation', 3479 => 'PlayStation',
           3480 => 'PlayStation', 3544 => 'Teredo (Xbox)', 4500 => 'IPsec NAT-T', 5353 => 'mDNS', 19302 => 'Google Meet / STUN',
           27015 => 'Steam', 41641 => 'Tailscale', 51820 => 'WireGuard'],
];
const FLOW_PROTOS = [1 => 'ICMP', 2 => 'IGMP', 6 => 'TCP', 17 => 'UDP', 47 => 'GRE', 50 => 'ESP', 58 => 'ICMPv6', 112 => 'VRRP'];

function flow_service_name(int $proto, int $port): string {
    if ($port === 0) return FLOW_PROTOS[$proto] ?? "IP proto $proto";
    if (isset(FLOW_SERVICES[$proto][$port])) return FLOW_SERVICES[$proto][$port];
    if ($port >= 27000 && $port <= 27100) return 'Steam games';
    if ($port >= 16384 && $port <= 32767 && $proto === 17) return 'Voice / video (RTP)';
    return (FLOW_PROTOS[$proto] ?? "proto $proto") . " $port";
}

/**
 * [$who, $names]: $who(ip) gives {ip, name, hostId, category, label} for a local address
 * (cards first, then what the device inventory knows); $names is [ip => {name, hostId}] of the cards.
 */
function flow_name_resolver(): array {
    $names = [];
    foreach (db()->query('SELECT id, name, ip_address FROM hosts') as $h) $names[$h['ip_address']] = ['name' => $h['name'] === $h['ip_address'] ? null : $h['name'], 'hostId' => (int) $h['id']];
    $inventory = [];
    try {
        require_once __DIR__ . '/devices.php';
        require_once __DIR__ . '/checker.php';
        foreach (device_inventory()['devices'] as $dv) if ($dv['ip']) $inventory[$dv['ip']] ??= $dv;
    } catch (Throwable $e) { /* names are a nicety */ }
    $who = function (string $ip) use ($names, $inventory) {
        $inv = $inventory[$ip] ?? null;
        return ['ip' => $ip, 'name' => $names[$ip]['name'] ?? $inv['name'] ?? null, 'hostId' => $names[$ip]['hostId'] ?? null,
                'category' => $inv['category'] ?? null, 'label' => $inv['label'] ?? null];
    };
    return [$who, $names];
}

/** Loads finished minute files from the collector. cli/cron.php, every minute. */
function ingest_flows(): void {
    if (!is_dir(FLOW_DIR)) return;
    $pdo = db();
    $status = @file_get_contents(FLOW_DIR . '/status.json');
    if ($status !== false) {
        $pdo->prepare("INSERT INTO settings (k, v) VALUES ('flow_status', ?) ON DUPLICATE KEY UPDATE v = VALUES(v)")->execute([$status]);
    }
    $files = glob(FLOW_DIR . '/[0-9]*.json') ?: [];
    sort($files);
    if (!$files) return;
    $hostIds = [];
    foreach ($pdo->query('SELECT id, ip_address FROM hosts') as $h) $hostIds[$h['ip_address']] = (int) $h['id'];
    $ifTables = (bool) $pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'flow_iface_conv'")->fetchColumn();

    foreach ($files as $file) {
        $d = json_decode((string) @file_get_contents($file), true);
        if (!is_array($d) || !isset($d['ts'])) { @unlink($file); continue; }
        $ts = (int) $d['ts'];
        $t = $d['totals'];
        $pdo->beginTransaction();
        $pdo->prepare('INSERT INTO flow_totals (ts, down_bytes, up_bytes, internal_bytes, flows) VALUES (?, ?, ?, ?, ?)
                       ON DUPLICATE KEY UPDATE down_bytes = down_bytes + VALUES(down_bytes), up_bytes = up_bytes + VALUES(up_bytes),
                         internal_bytes = internal_bytes + VALUES(internal_bytes), flows = flows + VALUES(flows)')
            ->execute([$ts, $t['down'], $t['up'], $t['internal'], $t['flows']]);
        foreach (array_chunk($d['hosts'], 300, true) as $chunk) {
            $vals = [];
            foreach ($chunk as $ip => $h) array_push($vals, $ts, $ip, $h[0], $h[1], $h[4] ?? 0, $h[5] ?? 0);
            $pdo->prepare('INSERT INTO flow_hosts (ts, ip, bytes_in, bytes_out, inet_in, inet_out) VALUES ' . implode(',', array_fill(0, count($chunk), '(?, ?, ?, ?, ?, ?)'))
                . ' ON DUPLICATE KEY UPDATE bytes_in = bytes_in + VALUES(bytes_in), bytes_out = bytes_out + VALUES(bytes_out),
                    inet_in = inet_in + VALUES(inet_in), inet_out = inet_out + VALUES(inet_out)')->execute($vals);
        }
        foreach (array_chunk($d['conv'], 300) as $chunk) {
            $pdo->prepare('INSERT INTO flow_conv (ts, local_ip, remote_ip, proto, port, bytes_in, bytes_out) VALUES '
                . implode(',', array_fill(0, count($chunk), '(?, ?, ?, ?, ?, ?, ?)')))
                ->execute(array_merge(...array_map(fn($c) => [$ts, ...$c], $chunk)));
        }
        foreach (array_chunk($d['services'], 300) as $chunk) {
            $pdo->prepare('INSERT INTO flow_services (ts, proto, port, bytes_in, bytes_out) VALUES ' . implode(',', array_fill(0, count($chunk), '(?, ?, ?, ?, ?)'))
                . ' ON DUPLICATE KEY UPDATE bytes_in = bytes_in + VALUES(bytes_in), bytes_out = bytes_out + VALUES(bytes_out)')
                ->execute(array_merge(...array_map(fn($s) => [$ts, ...$s], $chunk)));
        }
        if ($ifTables) {
            // Per exporter interface (newer collectors only).
            foreach (array_chunk($d['ifaces'] ?? [], 300) as $chunk) {
                $pdo->prepare('INSERT INTO flow_ifaces (ts, exporter, if_index, dir, bytes, packets, flows) VALUES ' . implode(',', array_fill(0, count($chunk), '(?, ?, ?, ?, ?, ?, ?)'))
                    . ' ON DUPLICATE KEY UPDATE bytes = bytes + VALUES(bytes), packets = packets + VALUES(packets), flows = flows + VALUES(flows)')
                    ->execute(array_merge(...array_map(fn($f) => [$ts, ...$f], $chunk)));
            }
            foreach (array_chunk($d['ifconv'] ?? [], 300) as $chunk) {
                $pdo->prepare('INSERT INTO flow_iface_conv (ts, exporter, if_index, src_ip, dst_ip, proto, port, bytes) VALUES '
                    . implode(',', array_fill(0, count($chunk), '(?, ?, ?, ?, ?, ?, ?, ?)')))
                    ->execute(array_merge(...array_map(fn($c) => [$ts, ...$c], $chunk)));
            }
        }
        $pdo->commit();
        // Monitored hosts: into their history graphs (bytes per second over the minute).
        foreach ($d['hosts'] as $ip => [$in, $out]) {
            if (isset($hostIds[$ip])) {
                record_points($hostIds[$ip], [['flow_in', '', 'bit/s', round($in / 60, 1)], ['flow_out', '', 'bit/s', round($out / 60, 1)]], $ts);
            }
        }
        @unlink($file);
    }
    $cut = time() - FLOW_KEEP_DAYS * 86400;
    if (date('i') === '07') {   // once an hour is plenty
        foreach (['flow_totals', 'flow_hosts', 'flow_services'] as $tbl) $pdo->prepare("DELETE FROM $tbl WHERE ts < ?")->execute([$cut]);
        $pdo->prepare('DELETE FROM flow_conv WHERE ts < ?')->execute([time() - FLOW_CONV_KEEP_DAYS * 86400]);
        if ($ifTables) {
            $pdo->prepare('DELETE FROM flow_ifaces WHERE ts < ?')->execute([$cut]);
            $pdo->prepare('DELETE FROM flow_iface_conv WHERE ts < ?')->execute([time() - FLOW_CONV_KEEP_DAYS * 86400]);
        }
    }
}

// Switches report a long transfer about every 60-90 s (the flow's active timeout plus cache scan
// drift), so short buckets alternate between full and empty; 5-minute buckets show the real rate.
const TRAFFIC_RANGES = ['15m' => [900, 60], '1h' => [3600, 300], '6h' => [21600, 300], '24h' => [86400, 900], '7d' => [604800, 3600]];

/** The Traffic report: totals over time, top devices, services and conversations. */
function traffic_report(string $range): array {
    [$span, $bucket] = TRAFFIC_RANGES[$range] ?? TRAFFIC_RANGES['1h'];
    $pdo = db();
    $from = intdiv(time() - $span, 60) * 60;
    $status = json_decode((string) $pdo->query("SELECT v FROM settings WHERE k = 'flow_status'")->fetchColumn(), true);

    $q = $pdo->prepare("SELECT ts - MOD(ts, $bucket) t, SUM(down_bytes) d, SUM(up_bytes) u, SUM(internal_bytes) i FROM flow_totals
                        WHERE ts >= ? GROUP BY t ORDER BY t");
    $q->execute([$from]);
    $series = array_map(fn($r) => [(int) $r['t'], round($r['d'] * 8 / $bucket), round($r['u'] * 8 / $bucket), round($r['i'] * 8 / $bucket)], $q->fetchAll());

    // "Now": the last five complete minutes averaged, for the same reason.
    $last2 = $pdo->query('SELECT ts, down_bytes, up_bytes, internal_bytes FROM flow_totals ORDER BY ts DESC LIMIT 5')->fetchAll();
    $now = $last2 && (int) $last2[0]['ts'] >= time() - 300
        ? ['ts' => (int) $last2[0]['ts'],
           'down' => round(array_sum(array_column($last2, 'down_bytes')) * 8 / (60 * count($last2))),
           'up' => round(array_sum(array_column($last2, 'up_bytes')) * 8 / (60 * count($last2))),
           'internal' => round(array_sum(array_column($last2, 'internal_bytes')) * 8 / (60 * count($last2)))]
        : null;

    [$who, $names] = flow_name_resolver();

    $q = $pdo->prepare('SELECT ip, SUM(bytes_in) i, SUM(bytes_out) o FROM flow_hosts WHERE ts >= ? GROUP BY ip ORDER BY SUM(bytes_in) + SUM(bytes_out) DESC LIMIT 30');
    $q->execute([$from]);
    $hosts = array_map(fn($r) => $who($r['ip']) + ['in' => (int) $r['i'], 'out' => (int) $r['o']], $q->fetchAll());

    $q = $pdo->prepare('SELECT proto, port, SUM(bytes_in) i, SUM(bytes_out) o FROM flow_services WHERE ts >= ? GROUP BY proto, port ORDER BY SUM(bytes_in) + SUM(bytes_out) DESC LIMIT 20');
    $q->execute([$from]);
    $services = array_map(fn($r) => ['proto' => (int) $r['proto'], 'port' => (int) $r['port'], 'name' => flow_service_name((int) $r['proto'], (int) $r['port']),
                                     'in' => (int) $r['i'], 'out' => (int) $r['o']], $q->fetchAll());

    $q = $pdo->prepare("SELECT local_ip, remote_ip, proto, port, SUM(bytes_in) i, SUM(bytes_out) o FROM flow_conv WHERE ts >= ?
                        AND remote_ip NOT LIKE '224.%' AND remote_ip NOT LIKE '239.%' AND remote_ip <> '255.255.255.255'
                        GROUP BY local_ip, remote_ip, proto, port ORDER BY SUM(bytes_in) + SUM(bytes_out) DESC LIMIT 25");
    $q->execute([max($from, time() - FLOW_CONV_KEEP_DAYS * 86400)]);
    $conv = array_map(fn($r) => ['local' => $who($r['local_ip']), 'remote' => $r['remote_ip'], 'remoteLocal' => (bool) preg_match('/^(10\.|192\.168\.|172\.(1[6-9]|2\d|3[01])\.)/', $r['remote_ip']),
                                 'remoteName' => $names[$r['remote_ip']]['name'] ?? null, 'service' => flow_service_name((int) $r['proto'], (int) $r['port']),
                                 'in' => (int) $r['i'], 'out' => (int) $r['o']], $q->fetchAll());

    $totals = $pdo->prepare('SELECT SUM(down_bytes) d, SUM(up_bytes) u, SUM(internal_bytes) i FROM flow_totals WHERE ts >= ?');
    $totals->execute([$from]);
    $sum = $totals->fetch();
    return [
        'range' => $range, 'bucket' => $bucket, 'from' => $from, 'series' => $series, 'now' => $now,
        'totals' => ['down' => (int) $sum['d'], 'up' => (int) $sum['u'], 'internal' => (int) $sum['i']],
        'hosts' => $hosts, 'services' => $services, 'conversations' => $conv,
        // The status file is copied in once a minute by cron, so allow a few minutes before calling it stopped.
        'collector' => $status ? ['running' => isset($status['at']) && $status['at'] >= time() - 240, 'lastPacket' => $status['lastPacket'] ?? null,
                                  'exporters' => array_keys($status['exporters'] ?? []), 'records' => $status['records'] ?? 0,
                                  'noTemplate' => $status['noTemplate'] ?? 0, 'port' => $status['port'] ?? 2055] : null,
        'serverIp' => $_SERVER['SERVER_ADDR'] ?? null,
    ];
}
