<?php
// Network topology report (see network_topology() in includes/portmap.php).
//   GET -> {nodes, links, roots, unplaced, generatedAt}
require_once __DIR__ . '/../includes/portmap.php';
require_once __DIR__ . '/../includes/checker.php';   // vendor_summaries()
require_once __DIR__ . '/../includes/auth.php';
header('Content-Type: application/json');
require_api_auth();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'method not allowed']);
    exit;
}
echo json_encode(network_topology());
