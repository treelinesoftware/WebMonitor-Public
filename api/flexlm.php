<?php
// FlexLM licence servers (Licences tab).
//   GET                              -> {lmutil, servers: [... with features and current checkouts]}
//   GET ?id=ID&output=1              -> {output, checkedAt}: the last lmstat output
//   GET ?usage=ID&days=1|7|30|90|365 -> licences in use per feature over time
//   GET ?history=1&days=7&q=&server= -> checkout history and top users
//   POST {name, target, interval, alert, downAfterMin, fullPct, expiryWarnDays, notes}  -> add; admins
//   PUT ?id=ID {...}                 -> change; admins
//   DELETE ?id=ID                    -> remove, with its history; admins
//   POST ?id=ID&action=check         -> check now (the background job runs it within a minute); admins
require_once __DIR__ . '/../includes/flexlm.php';
require_once __DIR__ . '/../includes/auth.php';
header('Content-Type: application/json');
$user = require_api_auth_admin_writes();
session_write_close();

function fail(int $code, string $msg): void {
    http_response_code($code);
    echo json_encode(['error' => $msg]);
    exit;
}

function load_server(int $id): array {
    $st = db()->prepare('SELECT * FROM flexlm_servers WHERE id = ?');
    $st->execute([$id]);
    $s = $st->fetch();
    if (!$s) fail(404, 'That licence server no longer exists.');
    return $s;
}

function overview(): array {
    [$prefix, $problem] = flexlm_lmutil_command();
    return ['lmutil' => ['path' => flexlm_lmutil_path(), 'ok' => $prefix !== null, 'problem' => $problem,
                         'searched' => FLEXLM_LMUTIL_PATHS],
            'servers' => flexlm_list()];
}

$method = $_SERVER['REQUEST_METHOD'];
$id = (int) ($_GET['id'] ?? 0);

if ($method === 'GET') {
    if ($id && isset($_GET['output'])) {
        $s = load_server($id);
        echo json_encode(['output' => $s['last_output'], 'checkedAt' => $s['last_check_at'] !== null ? (int) $s['last_check_at'] : null]);
        exit;
    }
    if (isset($_GET['usage'])) {
        $s = load_server((int) $_GET['usage']);
        $days = (int) ($_GET['days'] ?? 1);
        if (!in_array($days, [1, 7, 30, 90, 365], true)) $days = 1;
        echo json_encode(flexlm_usage_series((int) $s['id'], $days));
        exit;
    }
    if (isset($_GET['history'])) {
        $days = max(1, min(FLEXLM_CHECKOUTS_KEEP_DAYS, (int) ($_GET['days'] ?? 7)));
        echo json_encode(flexlm_history($days, mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 100),
                                        !empty($_GET['server']) ? (int) $_GET['server'] : null));
        exit;
    }
    echo json_encode(overview());
    exit;
}

$input = json_decode(file_get_contents('php://input'), true) ?? [];
$pdo = db();

if ($method === 'POST' && $id && ($_GET['action'] ?? '') === 'check') {
    $s = load_server($id);
    if (!$s['enabled']) fail(400, 'This server is paused. Edit it and untick Paused first.');
    $pdo->prepare('UPDATE flexlm_servers SET check_requested = 1 WHERE id = ?')->execute([$id]);
    echo json_encode(overview());
    exit;
}

if ($method === 'POST') {
    [$row, $err] = flexlm_validate($input);
    if ($err) fail(400, $err);
    $pdo->prepare('INSERT INTO flexlm_servers (name, target, interval_sec, enabled, alert, down_after_min, full_pct, expiry_warn_days, notes,
                   check_requested, created_by, created_at) VALUES (?, ?, ?, 1, ?, ?, ?, ?, ?, 1, ?, ?)')
        ->execute([$row['name'], $row['target'], $row['interval_sec'], (int) $row['alert'], $row['down_after_min'], $row['full_pct'],
                   $row['expiry_warn_days'], $row['notes'], isset($user['id']) ? (int) $user['id'] : null, now_ms()]);
    echo json_encode(['id' => (int) $pdo->lastInsertId()] + overview());
    exit;
}

if ($method === 'PUT' && $id) {
    $s = load_server($id);
    [$row, $err] = flexlm_validate($input, $s);
    if ($err) fail(400, $err);
    $changed = $row['target'] !== $s['target'];
    $pdo->prepare('UPDATE flexlm_servers SET name = ?, target = ?, interval_sec = ?, enabled = ?, alert = ?, down_after_min = ?, full_pct = ?,
                   expiry_warn_days = ?, notes = ?' . ($changed ? ", status = 'pending', fail_count = 0, message = NULL, check_requested = 1" : '') . ' WHERE id = ?')
        ->execute([$row['name'], $row['target'], $row['interval_sec'], (int) $row['enabled'], (int) $row['alert'], $row['down_after_min'],
                   $row['full_pct'], $row['expiry_warn_days'], $row['notes'], $id]);
    if ($changed) {
        // A different server: its old features and open checkouts no longer apply.
        $pdo->prepare('DELETE FROM flexlm_features WHERE server_id = ?')->execute([$id]);
        $pdo->prepare('UPDATE flexlm_checkouts SET ended_at = last_seen WHERE server_id = ? AND ended_at IS NULL')->execute([$id]);
    }
    echo json_encode(overview());
    exit;
}

if ($method === 'DELETE' && $id) {
    $pdo->prepare('DELETE FROM flexlm_servers WHERE id = ?')->execute([$id]);
    echo json_encode(overview());
    exit;
}

fail(405, 'method not allowed');
