<?php
require_once __DIR__ . '/../includes/checker.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/devicescan.php';
header('Content-Type: application/json');
require_api_auth_admin_writes();

$method = $_SERVER['REQUEST_METHOD'];
$pdo = db();

if ($method === 'GET') {
    header('X-App-Version: ' . app_version());   // open pages compare this to offer a reload after updates
    $hosts = $pdo->query('SELECT * FROM hosts ORDER BY created_at ASC')->fetchAll();
    echo json_encode(host_summaries($hosts));

    // Answer from stored results right away, then run any due checks after
    // the response is sent, so slow/down hosts never hold up the page.
    // Fresh results show up on the next poll.
    if (function_exists('fastcgi_finish_request')) {
        ignore_user_abort(true);
        fastcgi_finish_request();
    }
    check_due_hosts();
    exit;
}

if ($method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $name = trim($input['name'] ?? '');
    $ip = trim($input['ip'] ?? '');
    $os = $input['os'] ?? 'other';
    $device = $input['device'] ?? 'server';
    $community = trim((string) ($input['community'] ?? ''));
    if ($community === '') $community = 'public';
    $community = substr($community, 0, 64);

    if ($name === '' || $ip === '') {
        http_response_code(400);
        echo json_encode(['error' => 'name and ip are required']);
        exit;
    }

    if (!filter_var($ip, FILTER_VALIDATE_IP)) {
        http_response_code(400);
        echo json_encode(['error' => 'ip must be a valid IPv4 or IPv6 address']);
        exit;
    }

    $allowedOs = ['windows', 'macos', 'linux', 'other'];
    $allowedDevice = ['server', 'desktop', 'laptop', 'mobile', 'router', 'switch', 'ap', 'printer', 'camera', 'nas', 'ups', 'iot', 'console', 'tv'];
    if (!in_array($os, $allowedOs, true)) $os = 'other';
    if (!in_array($device, $allowedDevice, true)) $device = 'server';

    $dup = $pdo->prepare('SELECT name FROM hosts WHERE ip_address = ? LIMIT 1');
    $dup->execute([$ip]);
    if (($existing = $dup->fetchColumn()) !== false) {
        http_response_code(409);
        echo json_encode(['error' => "$ip is already monitored as \"$existing\""]);
        exit;
    }

    $stmt = $pdo->prepare('INSERT INTO hosts (name, ip_address, os, device, community) VALUES (?, ?, ?, ?, ?)');
    $stmt->execute([substr($name, 0, 60), $ip, $os, $device, $community]);
    $id = (int) $pdo->lastInsertId();

    $stmt = $pdo->prepare('SELECT * FROM hosts WHERE id = ?');
    $stmt->execute([$id]);
    $host = $stmt->fetch();
    check_host($host); // test it right away instead of waiting for the next poll
    if (in_array($device, DEVICE_SCAN_TYPES, true)) request_device_scan(null, $ip, false);   // and find out what it is

    http_response_code(201);
    echo json_encode(host_summary($host));
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'method not allowed']);
