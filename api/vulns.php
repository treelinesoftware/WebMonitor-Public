<?php
// Vulnerability scans (Security tab).
//   GET                         -> report: {summary, hosts, findings, scans, nmap, subnets}
//   POST {scope}                -> queue a scan ('all', 'subnet:192.168.60.0/24', 'host:12'); admins
//   POST ?cancel=ID             -> cancel a queued or running scan; admins
require_once __DIR__ . '/../includes/vulnscan.php';
require_once __DIR__ . '/../includes/auth.php';
header('Content-Type: application/json');
$user = require_api_auth_admin_writes();

function fail(int $code, string $msg): void {
    http_response_code($code);
    echo json_encode(['error' => $msg]);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];
if ($method === 'GET') {
    echo json_encode(vuln_report());
    exit;
}
if ($method !== 'POST') fail(405, 'method not allowed');

if (isset($_GET['cancel'])) {
    db()->prepare("UPDATE vuln_scans SET status = 'cancelled', finished_at = ? WHERE id = ? AND status IN ('queued', 'running')")
        ->execute([now_ms(), (int) $_GET['cancel']]);
    echo json_encode(vuln_report());
    exit;
}

$input = json_decode(file_get_contents('php://input'), true) ?? [];
$scope = (string) ($input['scope'] ?? 'all');
if (!preg_match('#^(all|host:\d+|subnet:\d{1,3}\.\d{1,3}\.\d{1,3}\.0/24)$#', $scope)) fail(400, 'Unknown scan scope.');
if (!vuln_targets($scope)) fail(400, 'Nothing to scan: no devices seen in the last ' . VULN_TARGET_SEEN_DAYS . ' days in that scope.');
vuln_queue_scan($scope, isset($user['id']) ? (int) $user['id'] : null);
echo json_encode(vuln_report());
