<?php
require_once __DIR__ . '/../includes/scanner.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/scanqueue.php';
header('Content-Type: application/json');
require_api_auth('admin');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'method not allowed']);
    exit;
}

set_time_limit(120);

$input = json_decode(file_get_contents('php://input'), true) ?? [];
$networksRaw = trim((string) ($input['networks'] ?? ''));
$doSnmp = !empty($input['snmp']);
$community = trim((string) ($input['community'] ?? 'public'));
if ($community === '') $community = 'public';
$community = substr($community, 0, 64);

if ($networksRaw === '') {
    http_response_code(400);
    echo json_encode(['error' => 'networks is required, e.g. 192.168.1.0/24']);
    exit;
}

try {
    $ips = parse_networks($networksRaw);
} catch (InvalidArgumentException $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
    exit;
}

if (!$ips) {
    http_response_code(400);
    echo json_encode(['error' => 'no addresses to scan']);
    exit;
}

$lock = 'webmonitor_adhoc_' . bin2hex(random_bytes(12));
$pdo = db();
$stmt = $pdo->prepare('SELECT GET_LOCK(?, 0)');
$stmt->execute([$lock]);
if (!(int) $stmt->fetchColumn()) {
    http_response_code(503);
    echo json_encode(['error' => 'could not start scan']);
    exit;
}
try {
scan_activity_start($lock, 'subnet', mb_substr($networksRaw, 0, 255));
$sweepResults = network_sweep($ips, NETCHECK_PORTS, 1.5);

$existing = [];
foreach (db()->query('SELECT id, ip_address FROM hosts') as $row) {
    $existing[$row['ip_address']] = (int) $row['id'];
}

$snmpAvailable = snmp_available();
$snmpActive = $doSnmp && $snmpAvailable;

$results = [];
foreach ($ips as $ip) {
    $sweep = $sweepResults[$ip];
    if (!$sweep['up']) continue;

    $snmp = $snmpActive ? snmp_probe($ip, $community, 1.0) : null;
    $classification = classify_device($sweep['port'], $sweep['open'], $snmp);

    $results[] = [
        'ip' => $ip,
        'port' => $sweep['port'],
        'open' => $sweep['open'],
        'ms' => $sweep['ms'],
        'snmp' => $snmp,
        'os' => $classification['os'],
        'device' => $classification['device'],
        'label' => $classification['label'],
        'confidence' => $classification['confidence'],
        'monitored' => isset($existing[$ip]),
        'hostId' => $existing[$ip] ?? null,
    ];
}

} finally {
    scan_activity_end($lock);
    $stmt = $pdo->prepare('SELECT RELEASE_LOCK(?)');
    $stmt->execute([$lock]);
}
echo json_encode([
    'scanned' => count($ips),
    'active' => count($results),
    'snmpRequested' => $doSnmp,
    'snmpAvailable' => $snmpAvailable,
    'results' => $results,
]);
