<?php
// Notification settings and channels (admins only).
//   GET                              -> {settings, channels, log}
//   PUT    ?what=settings  {…}       -> save alert thresholds/options
//   POST                  {type, name, enabled, config}  -> add channel
//   PATCH  ?id=N          {name?, enabled?, config?}     -> edit channel
//   DELETE ?id=N                                         -> delete channel
//   POST   ?id=N&test=1                                  -> send a test message
require_once __DIR__ . '/../includes/alerts.php';
require_once __DIR__ . '/../includes/auth.php';
header('Content-Type: application/json');
require_api_auth('admin');

function fail(int $code, string $msg): void {
    http_response_code($code);
    echo json_encode(['error' => $msg]);
    exit;
}

$pdo = db();
$method = $_SERVER['REQUEST_METHOD'];
$input = $method === 'GET' ? [] : (json_decode(file_get_contents('php://input'), true) ?? []);
$id = (int) ($_GET['id'] ?? 0);

if ($method === 'GET') {
    $channels = array_map('channel_public', $pdo->query('SELECT * FROM notification_channels ORDER BY id')->fetchAll());
    $log = $pdo->query('SELECT l.ts, l.ok, l.detail, c.name AS channel FROM notification_log l
                        LEFT JOIN notification_channels c ON c.id = l.channel_id
                        ORDER BY l.ts DESC LIMIT 30')->fetchAll();
    echo json_encode([
        'settings' => alert_settings(),
        'channels' => $channels,
        'log' => array_map(fn($r) => ['ts' => (int) $r['ts'], 'ok' => (bool) $r['ok'],
                                      'detail' => $r['detail'], 'channel' => $r['channel']], $log),
    ]);
    exit;
}

if ($method === 'PUT' && ($_GET['what'] ?? '') === 'settings') {
    echo json_encode(['settings' => save_alert_settings($input)]);
    exit;
}

if ($method === 'POST' && $id && !empty($_GET['test'])) {
    $stmt = $pdo->prepare('SELECT * FROM notification_channels WHERE id = ?');
    $stmt->execute([$id]);
    $ch = $stmt->fetch();
    if (!$ch) fail(404, 'Channel not found.');
    $err = send_to_channel($ch, 'WebMonitor test notification',
        "This is a test from WebMonitor. If you can read this, the \"{$ch['name']}\" channel works.", 'info');
    log_notification($id, null, $err === null, $err ?? 'test sent');
    if ($err !== null) fail(502, $err);
    echo json_encode(['ok' => true]);
    exit;
}

if ($method === 'POST') {
    $type = (string) ($input['type'] ?? '');
    $name = trim((string) ($input['name'] ?? ''));
    if (!in_array($type, CHANNEL_TYPES, true)) fail(400, 'Unknown channel type.');
    if ($name === '' || mb_strlen($name) > 60) fail(400, 'Give the channel a name (up to 60 characters).');
    try {
        $cfg = channel_config($type, (array) ($input['config'] ?? []));
    } catch (InvalidArgumentException $e) {
        fail(400, $e->getMessage());
    }
    $pdo->prepare('INSERT INTO notification_channels (type, name, config, enabled) VALUES (?, ?, ?, ?)')
        ->execute([$type, $name, json_encode($cfg), empty($input['enabled']) && array_key_exists('enabled', $input) ? 0 : 1]);
    http_response_code(201);
    echo json_encode(['id' => (int) $pdo->lastInsertId()]);
    exit;
}

$stmt = $pdo->prepare('SELECT * FROM notification_channels WHERE id = ?');
$stmt->execute([$id]);
$ch = $stmt->fetch();
if (!$ch) fail(404, 'Channel not found.');

if ($method === 'PATCH') {
    $name = array_key_exists('name', $input) ? trim((string) $input['name']) : $ch['name'];
    if ($name === '' || mb_strlen($name) > 60) fail(400, 'Give the channel a name (up to 60 characters).');
    $cfg = json_decode($ch['config'], true) ?: [];
    if (array_key_exists('config', $input)) {
        try {
            $cfg = channel_config($ch['type'], (array) $input['config'], $cfg);
        } catch (InvalidArgumentException $e) {
            fail(400, $e->getMessage());
        }
    }
    $enabled = array_key_exists('enabled', $input) ? (int) (bool) $input['enabled'] : (int) $ch['enabled'];
    $pdo->prepare('UPDATE notification_channels SET name = ?, config = ?, enabled = ? WHERE id = ?')
        ->execute([$name, json_encode($cfg), $enabled, $id]);
    echo json_encode(['ok' => true]);
    exit;
}

if ($method === 'DELETE') {
    $pdo->prepare('DELETE FROM notification_channels WHERE id = ?')->execute([$id]);
    http_response_code(204);
    exit;
}

fail(405, 'method not allowed');
