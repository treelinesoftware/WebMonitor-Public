<?php
require_once __DIR__ . '/../includes/devicescan.php';
require_once __DIR__ . '/../includes/discovery.php';
require_once __DIR__ . '/../includes/auth.php';
header('Content-Type: application/json');
require_api_auth_admin_writes();

$pdo = db();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $subnet = (string) ($input['subnet'] ?? '');
    if (!preg_match('#^\d{1,3}\.\d{1,3}\.\d{1,3}\.0/24$#', $subnet)
        || !filter_var(substr($subnet, 0, -3), FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        http_response_code(400);
        echo json_encode(['error' => 'not a /24 subnet']);
        exit;
    }
    $now = (int) round(microtime(true) * 1000);
    $stmt = $pdo->prepare('UPDATE subnet_scans SET requested_at = GREATEST(COALESCE(requested_at, 0) + 1, ?), priority_at = ? WHERE subnet = ?');
    $stmt->execute([$now, next_scan_priority(), $subnet]);
    if (!$stmt->rowCount()) {
        http_response_code(404);
        echo json_encode(['error' => 'subnet scan settings not found']);
        exit;
    }
    echo json_encode(['ok' => true]);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'method not allowed']);
    exit;
}

$active = active_scans();
$deviceRequests = $pdo->query('SELECT ip, mac, requested_at, priority_at FROM device_scans
                               WHERE requested_at IS NOT NULL ORDER BY priority_at DESC, requested_at, dkey')->fetchAll();
$subnets = subnet_scan_rows();
$result = ['active' => $active, 'requested' => count($deviceRequests) + count(array_filter($subnets, fn($r) => $r['requested_at'] !== null))];
if (!empty($_GET['details'])) {
    $result['devices'] = array_map(fn($r) => ['ip' => $r['ip'], 'mac' => $r['mac'], 'requestedAt' => (int) $r['requested_at'],
                                              'priority' => $r['priority_at'] !== null], $deviceRequests);
    $result['dueDevices'] = array_map(fn($r) => ['ip' => $r[2], 'mac' => $r[1], 'name' => $r[3], 'scannedAt' => $r[0] ?: null], due_device_scans());
    $names = subnet_names();
    $result['subnets'] = [];
    foreach ($subnets as $subnet => $r) {
        if (!$r['enabled'] && $r['requested_at'] === null) continue;
        $result['subnets'][] = [
            'subnet' => $subnet, 'name' => $names[$subnet] ?? null,
            'requestedAt' => $r['requested_at'] !== null ? (int) $r['requested_at'] : null,
            'priority' => $r['priority_at'] !== null ? (int) $r['priority_at'] : null,
            'dueAt' => $r['enabled'] ? ($r['last_scan_at'] ? (int) $r['last_scan_at'] + (int) $r['every_min'] * 60000 : 0) : null,
        ];
    }
    usort($result['subnets'], fn($a, $b) => ($b['priority'] ?? 0) <=> ($a['priority'] ?? 0) ?: ($a['dueAt'] ?? PHP_INT_MAX) <=> ($b['dueAt'] ?? PHP_INT_MAX));
}
echo json_encode($result);
