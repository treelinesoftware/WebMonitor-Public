<?php
// Alerts for the dashboard (any signed-in user).
//   GET            -> {open: [...], recent: [...]}   recent = last 50 of any state
//   GET ?open=1    -> {open: [...]}
require_once __DIR__ . '/../includes/alerts.php';
require_once __DIR__ . '/../includes/auth.php';
header('Content-Type: application/json');
require_api_auth();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'method not allowed']);
    exit;
}

function alert_row(array $a): array {
    return [
        'id' => (int) $a['id'],
        'hostId' => $a['host_id'] === null ? null : (int) $a['host_id'],   // null: a device without a card (bandwidth)
        'hostName' => $a['name'] ?? explode('|', (string) $a['subject'])[0],
        'hostIp' => $a['ip_address'],
        'type' => $a['type'],
        'typeLabel' => alert_type_label($a['type']),
        'subject' => $a['subject'],
        'severity' => $a['severity'],
        'message' => $a['message'],
        'startedAt' => (int) $a['started_at'],
        'resolvedAt' => $a['resolved_at'] !== null ? (int) $a['resolved_at'] : null,
    ];
}

$pdo = db();
// Muted hosts' problems are closed on the next background run; hide them now.
$open = $pdo->query("SELECT a.*, h.name, h.ip_address FROM alerts a LEFT JOIN hosts h ON h.id = a.host_id
                     WHERE a.resolved_at IS NULL AND COALESCE(h.muted_until, 0) <= " . now_ms() . "
                     ORDER BY FIELD(a.severity, 'critical', 'warning', 'info'), a.started_at")->fetchAll();
$out = ['open' => array_map('alert_row', $open)];
if (empty($_GET['open'])) {
    $recent = $pdo->query('SELECT a.*, h.name, h.ip_address FROM alerts a LEFT JOIN hosts h ON h.id = a.host_id
                           ORDER BY a.started_at DESC LIMIT 50')->fetchAll();
    $out['recent'] = array_map('alert_row', $recent);
}
echo json_encode($out);
