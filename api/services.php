<?php
// Service monitoring (Services tab).
//   GET                          -> {services: [...]}
//   GET ?host=ID                 -> {services: [...]} for one host
//   GET ?available=HOSTID        -> services the host's agent can watch (picker)
//   POST {kind, hostId, name, target, options, interval, alert}   -> add; admins
//   PUT ?id=ID {...}             -> change; admins
//   DELETE ?id=ID                -> remove; admins
//   POST ?id=ID&action=check     -> check now (network checks); admins
//   POST ?id=ID&action=restart   -> restart a watched agent service (agent job); admins
require_once __DIR__ . '/../includes/services.php';
require_once __DIR__ . '/../includes/schedules.php';   // jobs, change records
require_once __DIR__ . '/../includes/auth.php';
header('Content-Type: application/json');
$user = require_api_auth_admin_writes();
session_write_close();

function fail(int $code, string $msg): void {
    http_response_code($code);
    echo json_encode(['error' => $msg]);
    exit;
}

function load_service(int $id): array {
    $st = db()->prepare('SELECT * FROM services WHERE id = ?');
    $st->execute([$id]);
    $s = $st->fetch();
    if (!$s) fail(404, 'That check no longer exists.');
    return $s;
}

$method = $_SERVER['REQUEST_METHOD'];
$id = (int) ($_GET['id'] ?? 0);

if ($method === 'GET') {
    if (isset($_GET['available'])) {
        $a = agent_available_services((int) $_GET['available']);
        if (!$a) fail(404, 'That host has no agent.');
        echo json_encode($a);
        exit;
    }
    echo json_encode(['services' => service_list(isset($_GET['host']) ? (int) $_GET['host'] : null)]);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true) ?? [];
$pdo = db();

if ($method === 'POST' && $id && ($_GET['action'] ?? '') === 'check') {
    $s = load_service($id);
    if ($s['kind'] === 'agent') fail(400, 'Agent services are reported by the agent every minute.');
    run_service_checks([$s]);
    echo json_encode(['services' => service_list()]);
    exit;
}

if ($method === 'POST' && $id && ($_GET['action'] ?? '') === 'restart') {
    $s = load_service($id);
    if ($s['kind'] !== 'agent') fail(400, 'Only agent services can be restarted.');
    $a = agent_available_services((int) $s['host_id']);
    $min = $a ? SERVICE_RESTART_MIN_AGENT[$a['platform']] : null;
    if (!$a || !$a['version'] || version_compare($a['version'], $min, '<')) {
        fail(400, "Restarting needs agent $min or newer. Upgrade it from the Updates tab first.");
    }
    $r = create_jobs([(int) $s['host_id']], 'service_restart', ['service' => $s['target']], isset($user['id']) ? (int) $user['id'] : null);
    if (!$r['created']) fail(409, $r['skipped'][0]['reason'] ?? 'Could not queue the restart.');
    change_record_manual($r, 'service_restart', ['service' => $s['target']], $user ?? null, "Restart {$s['target']} ({$s['name']})");
    echo json_encode(['ok' => true, 'jobId' => $r['created'][0]]);
    exit;
}

if ($method === 'POST') {
    [$row, $err] = service_validate($input);
    if ($err) fail(400, $err);
    $pdo->prepare('INSERT INTO services (host_id, kind, name, target, options, interval_sec, enabled, alert, created_by, created_at)
                   VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute([$row['host_id'], $row['kind'], $row['name'], $row['target'], json_encode($row['options']), $row['interval_sec'],
                   (int) $row['enabled'], (int) $row['alert'], isset($user['id']) ? (int) $user['id'] : null, now_ms()]);
    $newId = (int) $pdo->lastInsertId();
    if ($row['kind'] !== 'agent') run_service_checks([load_service($newId)]);   // show a result right away
    echo json_encode(['id' => $newId, 'services' => service_list()]);
    exit;
}

if ($method === 'PUT' && $id) {
    $s = load_service($id);
    unset($input['kind']);   // the type of a check doesn't change; add a new one instead
    [$row, $err] = service_validate($input, $s);
    if ($err) fail(400, $err);
    $changed = $row['target'] !== $s['target'] || json_encode($row['options']) !== json_encode(service_options($s['options']));
    $pdo->prepare('UPDATE services SET host_id = ?, name = ?, target = ?, options = ?, interval_sec = ?, enabled = ?, alert = ?'
                  . ($changed ? ", status = 'pending', fail_count = 0, message = NULL, cert_expires_at = NULL" : '') . ' WHERE id = ?')
        ->execute([$row['host_id'], $row['name'], $row['target'], json_encode($row['options']), $row['interval_sec'],
                   (int) $row['enabled'], (int) $row['alert'], $id]);
    if ($changed && $row['enabled'] && $row['kind'] !== 'agent') run_service_checks([load_service($id)]);
    echo json_encode(['services' => service_list()]);
    exit;
}

if ($method === 'DELETE' && $id) {
    $pdo->prepare('DELETE FROM services WHERE id = ?')->execute([$id]);
    echo json_encode(['services' => service_list()]);
    exit;
}

fail(405, 'method not allowed');
