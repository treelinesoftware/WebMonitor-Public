<?php
// Switch/router configuration backups (admins only: configs hold secrets).
//   GET    ?host=N                 -> {settings, versions, vendors, publicKey, schedules}
//   PUT    ?host=N  {vendor, port, username, auth, password?, enablePassword?, command?, schedule, legacySsh}
//                                   -> save settings (blank passwords keep the saved ones)
//   POST   ?host=N&backup=1        -> back up now (runs within a minute)
//   POST   ?host=N&forget_key=1    -> forget the device's SSH host key
//   GET    ?version=V              -> {version, content}; &download=1 -> the file
//   GET    ?version=V&diff=1       -> {version, previous, diff: [[op, line], ...]}
//   DELETE ?version=V              -> delete one version
require_once __DIR__ . '/../includes/configbackup.php';
require_once __DIR__ . '/../includes/auth.php';
header('Content-Type: application/json');
require_api_auth('admin');

function fail(int $code, string $msg): void {
    http_response_code($code);
    echo json_encode(['error' => $msg]);
    exit;
}

function version_row(array $v): array {
    return ['id' => (int) $v['id'], 'fetchedAt' => (int) $v['fetched_at'], 'source' => $v['source'],
            'bytes' => (int) $v['bytes'], 'changed' => (bool) $v['changed'], 'sha256' => $v['sha256']];
}

$pdo = db();
$method = $_SERVER['REQUEST_METHOD'];

// ---- one version ----
if (isset($_GET['version'])) {
    $stmt = $pdo->prepare('SELECT v.*, h.name, h.ip_address FROM switch_configs v JOIN hosts h ON h.id = v.host_id WHERE v.id = ?');
    $stmt->execute([(int) $_GET['version']]);
    $v = $stmt->fetch();
    if (!$v) fail(404, 'not found');
    if ($method === 'DELETE') {
        $pdo->prepare('DELETE FROM switch_configs WHERE id = ?')->execute([$v['id']]);
        http_response_code(204);
        exit;
    }
    if ($method !== 'GET') fail(405, 'method not allowed');
    if (!empty($_GET['download'])) {
        $name = preg_replace('/[^A-Za-z0-9._-]+/', '_', $v['name'] === $v['ip_address'] ? $v['ip_address'] : $v['name']);
        $file = $name . '_' . date('Y-m-d_His', intdiv((int) $v['fetched_at'], 1000)) . '.cfg';
        header('Content-Type: text/plain; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $file . '"');
        header('X-Content-Type-Options: nosniff');
        echo $v['content'];
        exit;
    }
    if (!empty($_GET['diff'])) {
        $prev = $pdo->prepare('SELECT * FROM switch_configs WHERE host_id = ? AND id < ? ORDER BY id DESC LIMIT 1');
        $prev->execute([$v['host_id'], $v['id']]);
        $p = $prev->fetch();
        echo json_encode(['version' => version_row($v), 'previous' => $p ? version_row($p) : null,
                          'diff' => $p ? config_diff($p['content'], $v['content']) : null]);
        exit;
    }
    echo json_encode(['version' => version_row($v), 'content' => $v['content']]);
    exit;
}

// ---- a device ----
$hostId = (int) ($_GET['host'] ?? 0);
$stmt = $pdo->prepare('SELECT * FROM hosts WHERE id = ?');
$stmt->execute([$hostId]);
$host = $stmt->fetch();
if (!$host) fail(404, 'host not found');
$cfgStmt = $pdo->prepare('SELECT * FROM config_backup_settings WHERE host_id = ?');
$cfgStmt->execute([$hostId]);
$cfg = $cfgStmt->fetch() ?: null;

if ($method === 'GET') {
    $versions = $pdo->prepare('SELECT id, fetched_at, source, bytes, changed, sha256 FROM switch_configs WHERE host_id = ? ORDER BY id DESC LIMIT 200');
    $versions->execute([$hostId]);
    echo json_encode([
        'settings' => $cfg ? [
            'vendor' => $cfg['vendor'], 'port' => (int) $cfg['port'], 'username' => $cfg['username'], 'auth' => $cfg['auth'],
            'hasPassword' => $cfg['secret_enc'] !== null, 'hasEnablePassword' => $cfg['enable_enc'] !== null,
            'command' => $cfg['command'], 'schedule' => $cfg['schedule'], 'legacySsh' => (bool) $cfg['legacy_ssh'],
            'requestedAt' => $cfg['requested_at'] ? (int) $cfg['requested_at'] : null,
            'lastAttemptAt' => $cfg['last_attempt_at'] ? (int) $cfg['last_attempt_at'] : null,
            'lastOkAt' => $cfg['last_ok_at'] ? (int) $cfg['last_ok_at'] : null,
            'lastError' => $cfg['last_error'],
        ] : null,
        'versions' => array_map('version_row', $versions->fetchAll()),
        'vendors' => array_map(fn($k, $v) => ['id' => $k, 'label' => $v['label'], 'needsCommand' => $k === 'generic',
                                               'enable' => !empty($v['enable'])], array_keys(CONFIG_VENDORS), CONFIG_VENDORS),
        'schedules' => array_keys(CONFIG_SCHEDULES),
        'publicKey' => ssh_public_key(),
    ]);
    exit;
}

if ($method === 'PUT') {
    $in = json_decode(file_get_contents('php://input'), true) ?? [];
    $vendor = (string) ($in['vendor'] ?? '');
    if (!isset(CONFIG_VENDORS[$vendor])) fail(400, 'choose the device type');
    $username = trim((string) ($in['username'] ?? ''));
    if ($username === '' || strlen($username) > 64 || !preg_match('/^[^\s@:]+$/', $username)) fail(400, 'enter a valid username');
    $port = (int) ($in['port'] ?? 22);
    if ($port < 1 || $port > 65535) fail(400, 'port must be 1-65535');
    $auth = ($in['auth'] ?? 'key') === 'password' ? 'password' : 'key';
    $schedule = (string) ($in['schedule'] ?? 'off');
    if (!isset(CONFIG_SCHEDULES[$schedule])) fail(400, 'unknown schedule');
    $command = $vendor === 'generic' ? trim((string) ($in['command'] ?? '')) : null;
    if ($vendor === 'generic' && ($command === '' || strlen($command) > 255)) fail(400, 'enter the command that prints the configuration');

    $secret = $cfg['secret_enc'] ?? null;
    if ($auth === 'password') {
        $pw = (string) ($in['password'] ?? '');
        if ($pw !== '') $secret = encrypt_secret($pw);
        if ($secret === null) fail(400, 'enter the password');
    } else {
        $secret = null;
    }
    $enable = $cfg['enable_enc'] ?? null;
    if (array_key_exists('enablePassword', $in)) {
        $ep = (string) $in['enablePassword'];
        if ($ep !== '') $enable = encrypt_secret($ep);
    }
    if (!empty($in['clearEnablePassword'])) $enable = null;

    $legacy = !empty($in['legacySsh']) ? 1 : 0;
    $pdo->prepare('INSERT INTO config_backup_settings (host_id, vendor, port, username, auth, secret_enc, enable_enc, command, schedule, legacy_ssh)
                   VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                   ON DUPLICATE KEY UPDATE vendor = VALUES(vendor), port = VALUES(port), username = VALUES(username), auth = VALUES(auth),
                     secret_enc = VALUES(secret_enc), enable_enc = VALUES(enable_enc), command = VALUES(command), schedule = VALUES(schedule),
                     legacy_ssh = VALUES(legacy_ssh)')
        ->execute([$hostId, $vendor, $port, $username, $auth, $secret, $enable, $command, $schedule, $legacy]);
    echo json_encode(['ok' => true]);
    exit;
}

if ($method === 'POST' && !empty($_GET['backup'])) {
    if (!$cfg) fail(400, 'set up the login first');
    $pdo->prepare('UPDATE config_backup_settings SET requested_at = ? WHERE host_id = ?')->execute([now_ms_cfg(), $hostId]);
    echo json_encode(['ok' => true]);
    exit;
}

if ($method === 'POST' && !empty($_GET['forget_key'])) {
    // known_hosts lives in /var/lib/webmonitor (not writable by the web server): the next backup does it.
    $pdo->prepare("INSERT INTO settings (k, v) VALUES ('ssh_forget_hosts', JSON_ARRAY(?))
                   ON DUPLICATE KEY UPDATE v = JSON_ARRAY_APPEND(v, '$', ?)")->execute([$host['ip_address'], $host['ip_address']]);
    echo json_encode(['ok' => true]);
    exit;
}

fail(405, 'method not allowed');
