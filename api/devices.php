<?php
// Device inventory report (see includes/devices.php).
//   GET -> {devices: [...], categories, generatedAt}
require_once __DIR__ . '/../includes/devices.php';
require_once __DIR__ . '/../includes/checker.php';
require_once __DIR__ . '/../includes/auth.php';
header('Content-Type: application/json');
require_api_auth();
echo json_encode(device_inventory());
