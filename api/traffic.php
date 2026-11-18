<?php
// Traffic report (see includes/flows.php).
//   GET ?range=15m|1h|6h|24h|7d
require_once __DIR__ . '/../includes/flows.php';
require_once __DIR__ . '/../includes/auth.php';
header('Content-Type: application/json');
require_api_auth();
echo json_encode(traffic_report((string) ($_GET['range'] ?? '1h')));
