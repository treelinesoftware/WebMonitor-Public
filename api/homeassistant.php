<?php
// Home Assistant link (admins only).
//   GET                    -> {url, hasToken, syncedAt, error, devices, matched}
//   PUT   {url, token?}    -> save (token left out: keep; "" removes the link), then sync now
//   POST  ?sync=1          -> sync now
require_once __DIR__ . '/../includes/homeassistant.php';
require_once __DIR__ . '/../includes/auth.php';
header('Content-Type: application/json');
require_api_auth('admin');

function fail(int $code, string $msg): void {
    http_response_code($code);
    echo json_encode(['error' => $msg]);
    exit;
}

function ha_state(): array {
    $cfg = ha_config();
    $stored = ha_devices_stored();
    $matched = null;
    if (!empty($stored['devices'])) {
        require_once __DIR__ . '/../includes/devices.php';
        $matched = count(array_filter(device_inventory()['devices'], fn($d) => !empty($d['ha'])));
    }
    return [
        'url' => $cfg['url'] ?? '', 'hasToken' => decrypt_secret($cfg['token_enc'] ?? null) !== null,
        'syncedAt' => isset($stored['at']) ? (int) $stored['at'] : null, 'error' => $stored['error'] ?? null,
        'devices' => count($stored['devices'] ?? []), 'matched' => $matched,
    ];
}

$method = $_SERVER['REQUEST_METHOD'];
if ($method === 'GET') {
    echo json_encode(ha_state());
    exit;
}
set_time_limit(60);
if ($method === 'PUT') {
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $cfg = ha_config();
    if (array_key_exists('token', $input) && trim((string) $input['token']) === '') {
        ha_save_config([]);   // unlink
        db()->exec("DELETE FROM settings WHERE k = 'ha_devices'");
        echo json_encode(ha_state());
        exit;
    }
    $url = ha_normalize_url((string) ($input['url'] ?? ''));
    if ($url === null) fail(400, 'Enter the address like http://192.168.1.20:8123 (no path).');
    $cfg['url'] = $url;
    if (isset($input['token'])) {
        $token = trim((string) $input['token']);
        if (strlen($token) > 1000 || !preg_match('/^[A-Za-z0-9._\-]+$/', $token)) fail(400, 'That does not look like a Home Assistant access token.');
        $cfg['token_enc'] = encrypt_secret($token);
    }
    if (empty($cfg['token_enc'])) fail(400, 'Paste a long-lived access token from Home Assistant.');
    ha_save_config($cfg);
    refresh_ha_devices(true);
    echo json_encode(ha_state());
    exit;
}
if ($method === 'POST' && !empty($_GET['sync'])) {
    refresh_ha_devices(true);
    echo json_encode(ha_state());
    exit;
}
fail(405, 'method not allowed');
