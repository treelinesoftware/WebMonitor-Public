<?php
require_once __DIR__ . '/../includes/checker.php';
require_once __DIR__ . '/../includes/auth.php';
header('Content-Type: application/json');
require_api_auth();

// POST ?id=N -> check the host right now (TCP reachability, same as the
// periodic monitor), record it in the history, and return the result.

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'method not allowed']);
    exit;
}

$id = (int) ($_GET['id'] ?? 0);
if (!$id) {
    http_response_code(400);
    echo json_encode(['error' => 'id required']);
    exit;
}

$stmt = db()->prepare('SELECT * FROM hosts WHERE id = ?');
$stmt->execute([$id]);
$host = $stmt->fetch();
if (!$host) {
    http_response_code(404);
    echo json_encode(['error' => 'not found']);
    exit;
}

echo json_encode(check_host($host) + ['timeoutSec' => CHECK_TIMEOUT_SEC]);
