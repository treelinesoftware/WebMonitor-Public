<?php
// History for graphs (any signed-in user).
//   GET ?host=N&range=1h|6h|24h|7d|30d|90d|1y
//   -> {range, series: [{metric, subject, unit, points: [[ts, avg, min, max], ...]}]}
require_once __DIR__ . '/../includes/history.php';
require_once __DIR__ . '/../includes/auth.php';
header('Content-Type: application/json');
require_api_auth();

$range = isset(HISTORY_RANGES[$_GET['range'] ?? '']) ? $_GET['range'] : '24h';
echo json_encode(['range' => $range, 'series' => query_history((int) ($_GET['host'] ?? 0), $range)]);
