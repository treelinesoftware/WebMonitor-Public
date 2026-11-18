<?php
// Wi-Fi for host Details (see includes/wireless.php).
//   GET ?host=N -> {role: 'ap'|'controller'|null, ap?, aps?, clients, collectedAt}
require_once __DIR__ . '/../includes/wireless.php';
require_once __DIR__ . '/../includes/auth.php';
header('Content-Type: application/json');
require_api_auth();

$stmt = db()->prepare('SELECT * FROM hosts WHERE id = ?');
$stmt->execute([(int) ($_GET['host'] ?? 0)]);
$host = $stmt->fetch();
if (!$host) {
    http_response_code(404);
    echo json_encode(['error' => 'not found']);
    exit;
}
$w = wireless_overview();
$id = (int) $host['id'];
if (in_array($id, $w['controllers'], true)) {
    echo json_encode(['role' => 'controller', 'aps' => array_values($w['aps']), 'clients' => $w['clients'], 'collectedAt' => $w['collectedAt']]);
    exit;
}
foreach ($w['aps'] as $a) {
    if ($a['ip'] === $host['ip_address']) {
        echo json_encode(['role' => 'ap', 'ap' => $a, 'clients' => array_values(array_filter($w['clients'], fn($c) => $c['ap'] === $a['mac'])),
                          'collectedAt' => $w['collectedAt']]);
        exit;
    }
}
echo json_encode(['role' => null, 'collectedAt' => $w['collectedAt']]);
