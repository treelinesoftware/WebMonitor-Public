<?php
// WebMonitor Pro (Settings -> WebMonitor Pro).
//   GET                        -> status: available, unlocked, features (any user)
//   POST ?action=unlock {password}  -> unlock with the Pro password (admins)
//   POST ?action=lock               -> lock again (admins)
//   PUT  {features: {name: bool}}   -> switch Pro features on/off (admins)
require_once __DIR__ . '/../includes/pro.php';
require_once __DIR__ . '/../includes/auth.php';
header('Content-Type: application/json');
$me = require_api_auth_admin_writes();

function fail(int $code, string $msg): void {
    http_response_code($code);
    echo json_encode(['error' => $msg]);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];
$input = $method === 'GET' ? [] : (json_decode(file_get_contents('php://input'), true) ?? []);
$action = (string) ($_GET['action'] ?? '');

if ($method !== 'GET') {
    if (!pro_available()) fail(404, 'WebMonitor Pro is not installed on this server.');
    require_once PRO_DIR . '/pro.php';
    if ($method === 'POST' && $action === 'unlock') {
        // Don't keep the password in the audit trail's copy of the request.
        unset($input['password']);
        $err = pro_unlock((string) (json_decode(file_get_contents('php://input'), true)['password'] ?? ''), $me);
        if ($err !== null) fail(403, $err);
    } elseif ($method === 'POST' && $action === 'lock') {
        pro_lock();
    } elseif ($method === 'PUT') {
        if (!pro_unlocked()) fail(403, 'Unlock WebMonitor Pro first.');
        pro_save_features((array) ($input['features'] ?? []));
    } else {
        fail(405, 'method not allowed');
    }
    echo json_encode(['ok' => true, 'reload' => true]);
    exit;
}
echo json_encode(pro_status());
