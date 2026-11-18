<?php
require_once __DIR__ . '/../includes/hostdetails.php';
require_once __DIR__ . '/../includes/auth.php';
header('Content-Type: application/json');
require_api_auth();

// GET  ?id=N  -> last collected details (instant, from the database)
// POST ?id=N  -> query the host over SNMP now, store and return the result

$method = $_SERVER['REQUEST_METHOD'];
if ($method !== 'GET' && $method !== 'POST') {
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

$cached = load_host_details($id);

if ($method === 'GET') {
    echo json_encode($cached ?? ['details' => null, 'collectedAt' => null]);
    exit;
}

if (!host_details_available()) {
    http_response_code(503);
    echo json_encode(['error' => 'SNMP is not available on this server (php-snmp extension not installed)'] + ($cached ?? []));
    exit;
}

$community = $host['community'] !== '' ? $host['community'] : 'public';
$details = collect_host_details($host['ip_address'], $community);

if ($details === null) {
    // Keep returning the last good result so the page can still show it.
    http_response_code(502);
    echo json_encode(['error' => "No SNMP response from {$host['ip_address']} — SNMP may not be enabled on it, the community string may be wrong, or UDP 161 is blocked"] + ($cached ?? []));
    exit;
}

$collectedAt = save_host_details($id, $details);
echo json_encode(['details' => $details, 'collectedAt' => $collectedAt]);
