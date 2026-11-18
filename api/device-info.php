<?php
// One device in detail, Fing-style (Reports -> Devices -> click a device).
//   GET  ?mac=..&ip=..          -> {device, scan, wifi, port, traffic}
//   POST ?mac=..&ip=..&scan=1   -> scan it now (runs within a minute)
require_once __DIR__ . '/../includes/devices.php';
require_once __DIR__ . '/../includes/devicescan.php';
require_once __DIR__ . '/../includes/checker.php';
require_once __DIR__ . '/../includes/flows.php';
require_once __DIR__ . '/../includes/auth.php';
header('Content-Type: application/json');
require_api_auth_admin_writes();

function fail(int $code, string $msg): void {
    http_response_code($code);
    echo json_encode(['error' => $msg]);
    exit;
}

$mac = isset($_GET['mac']) && preg_match('/^([0-9a-f]{2}:){5}[0-9a-f]{2}$/', (string) $_GET['mac']) ? (string) $_GET['mac'] : null;
$ip = isset($_GET['ip']) && filter_var($_GET['ip'], FILTER_VALIDATE_IP) ? (string) $_GET['ip'] : null;
if (!$mac && !$ip) fail(400, 'mac or ip required');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_GET['scan'])) {
    if (!$ip) fail(400, 'the device has no IP address to scan');
    request_device_scan($mac, $ip);
    echo json_encode(['ok' => true]);
    exit;
}

$device = null;
foreach (device_inventory()['devices'] as $d) {
    if (($mac && $d['mac'] === $mac) || (!$mac && $d['ip'] === $ip)) { $device = $d; break; }
}
if (!$device && $ip) {
    $h = db()->prepare('SELECT id, name, device FROM hosts WHERE ip_address = ?');
    $h->execute([$ip]);
    if ($c = $h->fetch()) {
        [$cat, $label, $conf] = identify_device(['name' => $c['name'], 'cardDevice' => $c['device']]);
        $device = ['mac' => null, 'ip' => $ip, 'name' => $c['name'] === $ip ? null : $c['name'], 'hostId' => (int) $c['id'], 'wireless' => false,
                   'where' => ['kind' => 'unplaced'], 'category' => $cat, 'label' => $label, 'confidence' => $conf, 'vendor' => null,
                   'randomMac' => false, 'subnet' => preg_replace('/\.\d+$/', '.0/24', $ip), 'subnetName' => null, 'note' => null, 'scan' => null];
    }
}
if (!$device) fail(404, 'device not found (it may have left the network)');
$ip = $device['ip'] ?? $ip;

$row = db()->prepare('SELECT scanned_at, requested_at, data FROM device_scans WHERE dkey IN (?, ?)
                      ORDER BY (requested_at IS NOT NULL) DESC, COALESCE(scanned_at, 0) DESC, requested_at DESC LIMIT 1');
$row->execute([device_scan_key($device['mac'], $ip), "ip:$ip"]);
$scan = $row->fetch() ?: null;

// Wi-Fi detail from the controller; port detail from the switch.
$wifi = null;
if ($device['mac']) foreach (wireless_overview()['clients'] as $c) if ($c['mac'] === $device['mac']) { $wifi = $c; break; }
$port = null;
if (($device['where']['kind'] ?? '') === 'wired' && ($sw = $device['where']['switchId'] ?? null)) {
    foreach (port_map_view((int) $sw)['ports'] ?? [] as $p) {
        if ($p['name'] === $device['where']['port'] || $p['lag'] === $device['where']['port']) {
            $port = ['name' => $p['name'], 'description' => $p['description'], 'speedMbps' => $p['speedMbps'], 'vlan' => $p['vlan'],
                     'trunk' => $p['trunk'], 'lag' => $p['lag'], 'status' => $p['status'], 'devicesOnPort' => count($p['devices'])];
            break;
        }
    }
}

// Traffic, last 24 hours.
$traffic = null;
if ($ip) {
    $q = db()->prepare('SELECT SUM(bytes_in) i, SUM(bytes_out) o, SUM(inet_in) ii, SUM(inet_out) io, MAX(ts) last FROM flow_hosts WHERE ip = ? AND ts >= ?');
    $q->execute([$ip, time() - 86400]);
    $t = $q->fetch();
    if ($t && $t['last']) {
        // Both directions: between two of your own devices, the conversation is stored under whichever started it.
        $top = db()->prepare('SELECT remote_ip, proto, port, SUM(i) i, SUM(o) o FROM (
                SELECT remote_ip, proto, port, bytes_in i, bytes_out o FROM flow_conv WHERE local_ip = ? AND ts >= ?
                UNION ALL
                SELECT local_ip, proto, port, bytes_out, bytes_in FROM flow_conv WHERE remote_ip = ? AND ts >= ?) x
            GROUP BY remote_ip, proto, port ORDER BY SUM(i + o) DESC LIMIT 6');
        $top->execute([$ip, time() - 86400, $ip, time() - 86400]);
        $names = [];
        foreach (db()->query('SELECT name, ip_address FROM hosts') as $h) if ($h['name'] !== $h['ip_address']) $names[$h['ip_address']] = $h['name'];
        $traffic = ['in' => (int) $t['i'], 'out' => (int) $t['o'], 'internetIn' => (int) $t['ii'], 'internetOut' => (int) $t['io'], 'lastSeen' => (int) $t['last'],
                    'top' => array_map(fn($r) => ['remote' => isset($names[$r['remote_ip']]) ? "{$names[$r['remote_ip']]} ({$r['remote_ip']})" : $r['remote_ip'], 'service' => flow_service_name((int) $r['proto'], (int) $r['port']),
                                                  'in' => (int) $r['i'], 'out' => (int) $r['o']], $top->fetchAll())];
    }
}

echo json_encode([
    'device' => $device,
    'scan' => $scan ? ['scannedAt' => $scan['scanned_at'] ? (int) $scan['scanned_at'] : null, 'requestedAt' => $scan['requested_at'] ? (int) $scan['requested_at'] : null,
                       'data' => $scan['data'] ? json_decode($scan['data'], true) : null] : null,
    'wifi' => $wifi, 'port' => $port, 'traffic' => $traffic, 'categories' => DEVICE_CATEGORIES,
]);
