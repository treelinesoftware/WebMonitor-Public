<?php
// Switch port map (see includes/portmap.php).
//   GET  ?host=N            -> stored map with names resolved (collects it first if there is none)
//   POST ?host=N&refresh=1  -> collect again now
require_once __DIR__ . '/../includes/portmap.php';
require_once __DIR__ . '/../includes/auth.php';
header('Content-Type: application/json');
require_api_auth_admin_writes();

$stmt = db()->prepare('SELECT * FROM hosts WHERE id = ?');
$stmt->execute([(int) ($_GET['host'] ?? 0)]);
$host = $stmt->fetch();
if (!$host) {
    http_response_code(404);
    echo json_encode(['error' => 'not found']);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];
$view = $method === 'GET' ? port_map_view((int) $host['id']) : null;
if ($view === null || ($method === 'POST' && !empty($_GET['refresh']))) {
    if (!snmp_available()) {
        http_response_code(503);
        echo json_encode(['error' => 'SNMP is not available on this server']);
        exit;
    }
    set_time_limit(90);
    try {
        save_port_map((int) $host['id'], collect_port_map($host));
        update_port_summaries();
    } catch (Throwable $e) {
        http_response_code(502);
        echo json_encode(['error' => $e->getMessage()]);
        exit;
    }
    $view = port_map_view((int) $host['id']);
}
echo json_encode($view);
