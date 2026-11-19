<?php
// Update jobs for the dashboard.
//   GET                 -> {jobs: [recent 50], active: {hostId: job}}     (any user)
//   GET ?host=N         -> {jobs: [last 20 for the host]}                 (any user)
//   GET ?id=N           -> {job: {... with output}}                       (any user)
//   POST {hostIds: [], type, params}  -> queue jobs (admins)
//        -> {created: [ids], skipped: [{hostId, host, reason}]}
//   DELETE ?id=N        -> cancel a queued job (admins)
require_once __DIR__ . '/../includes/schedules.php';   // jobs, change records
require_once __DIR__ . '/../includes/auth.php';
header('Content-Type: application/json');
$me = require_api_auth_admin_writes();

$pdo = db();
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    if (isset($_GET['id'])) {
        $stmt = $pdo->prepare(JOB_SELECT . ' WHERE j.id = ?');
        $stmt->execute([(int) $_GET['id']]);
        $j = $stmt->fetch();
        if (!$j) {
            http_response_code(404);
            echo json_encode(['error' => 'not found']);
            exit;
        }
        echo json_encode(['job' => job_row($j, true)]);
        exit;
    }
    if (isset($_GET['host'])) {
        $stmt = $pdo->prepare(JOB_SELECT . ' WHERE j.host_id = ? ORDER BY j.id DESC LIMIT 20');
        $stmt->execute([(int) $_GET['host']]);
        echo json_encode(['jobs' => array_map('job_row', $stmt->fetchAll())]);
        exit;
    }
    $recent = $pdo->query(JOB_SELECT . ' ORDER BY j.id DESC LIMIT 50')->fetchAll();
    // Latest job per host, for the Updates table.
    $latest = $pdo->query(JOB_SELECT . ' WHERE j.id IN (SELECT MAX(id) FROM jobs GROUP BY host_id)')->fetchAll();
    $byHost = [];
    foreach ($latest as $j) $byHost[(int) $j['host_id']] = job_row($j);
    echo json_encode(['jobs' => array_map('job_row', $recent), 'latestByHost' => (object) $byHost,
                      'minAgentVersion' => JOB_MIN_AGENT_VERSION]);
    exit;
}

if ($method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $type = (string) ($input['type'] ?? '');
    $hostIds = is_array($input['hostIds'] ?? null) ? $input['hostIds'] : [];
    // Service restarts go through api/services.php, which checks the service is watched.
    if (!in_array($type, JOB_TYPES, true) || $type === 'service_restart') {
        http_response_code(400);
        echo json_encode(['error' => 'unknown job type']);
        exit;
    }
    if (!$hostIds || count($hostIds) > 500) {
        http_response_code(400);
        echo json_encode(['error' => 'choose at least one host']);
        exit;
    }
    $params = (array) ($input['params'] ?? []);
    $res = create_jobs($hostIds, $type, $params, $me['id']);
    $res['change'] = change_record_manual($res, $type, $params, $me, job_label($type, job_params($type, $params)));
    echo json_encode($res);
    exit;
}

if ($method === 'DELETE') {
    $stmt = $pdo->prepare("UPDATE jobs SET status = 'cancelled', finished_at = ?, summary = ? WHERE id = ? AND status = 'queued'");
    $stmt->execute([now_ms(), 'Cancelled by ' . $me['username'], (int) ($_GET['id'] ?? 0)]);
    if (!$stmt->rowCount()) {
        http_response_code(409);
        echo json_encode(['error' => 'Only jobs that haven\'t started yet can be cancelled.']);
        exit;
    }
    http_response_code(204);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'method not allowed']);
