<?php
// Update schedules (Updates view).
//   GET                         -> {schedules, conflicts, timezone}       (any user)
//   POST   ?preview=1   {id?, ...} -> conflicts this schedule would have   (admins)
//   POST                {...}   -> create                                  (admins)
//   PATCH  ?id=N        {...}   -> update (any subset, e.g. {enabled})     (admins)
//   DELETE ?id=N                -> delete                                  (admins)
//   POST   ?id=N&run=1          -> run now                                 (admins)
require_once __DIR__ . '/../includes/schedules.php';
require_once __DIR__ . '/../includes/auth.php';
header('Content-Type: application/json');
$me = require_api_auth_admin_writes();

function fail(int $code, string $msg): void {
    http_response_code($code);
    echo json_encode(['error' => $msg]);
    exit;
}

/**
 * Marks the most recent occurrence as already handled, so creating or
 * editing a schedule never fires it immediately for a time just passed.
 */
function skip_past_occurrence(int $id): void {
    $stmt = db()->prepare('SELECT * FROM update_schedules WHERE id = ?');
    $stmt->execute([$id]);
    $s = $stmt->fetch();
    $occ = $s ? schedule_last_occurrence($s, new DateTimeImmutable()) : null;
    db()->prepare('UPDATE update_schedules SET last_run_key = ? WHERE id = ?')
        ->execute([$occ ? $occ->format('Y-m-d H:i') : null, $id]);
}

$pdo = db();
$method = $_SERVER['REQUEST_METHOD'];
$input = $method === 'GET' ? [] : (json_decode(file_get_contents('php://input'), true) ?? []);
$id = (int) ($_GET['id'] ?? 0);

$serverIp = (string) ($_SERVER['SERVER_ADDR'] ?? '');

if ($method === 'GET') {
    $rows = $pdo->query('SELECT * FROM update_schedules ORDER BY at_time, name')->fetchAll();
    echo json_encode([
        'schedules' => array_map(fn($r) => schedule_row($r) + ['resolvedHostIds' => schedule_host_ids($r)], $rows),
        'conflicts' => schedule_conflicts($rows, $serverIp),
        'timezone' => date_default_timezone_get(),
    ]);
    exit;
}

// Conflicts the schedule in the form would have if saved (nothing is saved).
if ($method === 'POST' && !empty($_GET['preview'])) {
    try {
        $f = schedule_fields($input);
    } catch (InvalidArgumentException $e) {
        echo json_encode(['conflicts' => [], 'invalid' => $e->getMessage()]);
        exit;
    }
    $candidateId = (int) ($input['id'] ?? 0);
    $rows = array_values(array_filter($pdo->query('SELECT * FROM update_schedules')->fetchAll(),
        fn($r) => (int) $r['id'] !== $candidateId));
    $rows[] = $f + ['id' => $candidateId, 'enabled' => 1, 'created_by' => null];
    $mine = array_values(array_filter(schedule_conflicts($rows, $serverIp),
        fn($c) => in_array($candidateId, $c['scheduleIds'], true)));
    echo json_encode(['conflicts' => $mine]);
    exit;
}

if ($method === 'POST' && !$id) {
    try {
        $f = schedule_fields($input);
    } catch (InvalidArgumentException $e) {
        fail(400, $e->getMessage());
    }
    $pdo->prepare('INSERT INTO update_schedules (name, enabled, job_type, params, days, at_time, targets, host_ids, created_by, created_at)
                   VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute([$f['name'], $f['enabled'], $f['job_type'], $f['params'], $f['days'], $f['at_time'],
                   $f['targets'], $f['host_ids'], $me['id'], now_ms()]);
    $newId = (int) $pdo->lastInsertId();
    skip_past_occurrence($newId);
    http_response_code(201);
    echo json_encode(['id' => $newId]);
    exit;
}

$stmt = $pdo->prepare('SELECT * FROM update_schedules WHERE id = ?');
$stmt->execute([$id]);
$sched = $stmt->fetch();
if (!$sched) fail(404, 'Schedule not found.');

if ($method === 'POST' && !empty($_GET['run'])) {
    echo json_encode(run_schedule($sched, true));
    exit;
}

if ($method === 'PATCH') {
    $current = schedule_row($sched);
    try {
        $f = schedule_fields(array_merge([
            'name' => $current['name'], 'jobType' => $current['jobType'], 'params' => $current['params'],
            'days' => $current['days'], 'atTime' => $current['atTime'], 'targets' => $current['targets'],
            'hostIds' => $current['hostIds'], 'enabled' => $current['enabled'],
        ], $input));
    } catch (InvalidArgumentException $e) {
        fail(400, $e->getMessage());
    }
    $pdo->prepare('UPDATE update_schedules SET name = ?, enabled = ?, job_type = ?, params = ?, days = ?, at_time = ?,
                   targets = ?, host_ids = ? WHERE id = ?')
        ->execute([$f['name'], $f['enabled'], $f['job_type'], $f['params'], $f['days'], $f['at_time'],
                   $f['targets'], $f['host_ids'], $id]);
    skip_past_occurrence($id);
    echo json_encode(['ok' => true]);
    exit;
}

if ($method === 'DELETE') {
    $pdo->prepare('DELETE FROM update_schedules WHERE id = ?')->execute([$id]);
    http_response_code(204);
    exit;
}

fail(405, 'method not allowed');
