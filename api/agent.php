<?php
// Endpoint the agents talk to (not the dashboard). Authenticated by
// tokens in X-Enroll-Token / X-Agent-Token headers, not by login session.
//
//   GET  ?action=cert     -> server certificate (PEM), fetched by the
//                            installer over a key-pinned connection
//   POST ?action=enroll   -> exchange a one-time enrollment token for an
//                            agent token
//   POST ?action=report   -> {kind: "metrics"|"inventory", version, data}
//                            reply: {ok, intervals, watch_services: [service names to report on]}
//   POST ?action=jobs     -> {job: {id, type, params} | null}  (agent 0.3+)
//   POST ?action=job_update {id, status, output, exit_code, summary, rebooting}
require_once __DIR__ . '/../includes/agents.php';
require_once __DIR__ . '/../includes/jobs.php';
require_once __DIR__ . '/../includes/topology.php';
require_once __DIR__ . '/../includes/services.php';

function reply(int $code, array $body): void {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($body);
    exit;
}

/** Decoded JSON request body (gzip allowed), capped at $maxBytes. */
function read_json_body(int $maxBytes): array {
    $raw = file_get_contents('php://input', false, null, 0, $maxBytes + 1);
    if ($raw === false || strlen($raw) > $maxBytes) reply(413, ['error' => 'request too large']);
    if (stripos($_SERVER['HTTP_CONTENT_ENCODING'] ?? '', 'gzip') !== false) {
        $raw = @gzdecode($raw, $maxBytes + 1);
        if ($raw === false || strlen($raw) > $maxBytes) reply(400, ['error' => 'bad or oversized gzip body']);
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) reply(400, ['error' => 'invalid JSON']);
    return $data;
}

$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];
$ip = substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
$intervals = ['metrics' => AGENT_METRICS_INTERVAL_SEC, 'inventory' => AGENT_INVENTORY_INTERVAL_SEC, 'workloads' => 300];

if ($action === 'cert' && $method === 'GET') {
    $pem = @file_get_contents(tls_cert_file());
    if ($pem === false) reply(500, ['error' => 'certificate not readable']);
    header('Content-Type: application/x-pem-file');
    echo $pem;
    exit;
}

if ($method !== 'POST') reply(405, ['error' => 'method not allowed']);

if ($action === 'enroll') {
    $token = (string) ($_SERVER['HTTP_X_ENROLL_TOKEN'] ?? '');
    $body = read_json_body(64 * 1024);
    if (!preg_match('/^[0-9a-f]{64}$/', $token)) reply(401, ['error' => 'invalid enrollment token']);
    $result = redeem_enrollment($token, (string) ($body['hostname'] ?? ''), (string) ($body['version'] ?? ''), $ip);
    if ($result === null) {
        reply(401, ['error' => 'enrollment token is invalid, already used, or expired — create a new install command in the dashboard']);
    }
    [$hostId, $agentToken] = $result;
    reply(200, ['host_id' => $hostId, 'agent_token' => $agentToken, 'intervals' => $intervals]);
}

if ($action === 'report') {
    $hostId = agent_host_for_token((string) ($_SERVER['HTTP_X_AGENT_TOKEN'] ?? ''));
    if ($hostId === null) reply(401, ['error' => 'unknown agent token — the agent may have been removed or re-installed']);

    $body = read_json_body(AGENT_MAX_INVENTORY_BYTES);
    $kind = $body['kind'] ?? '';
    $data = $body['data'] ?? null;
    $version = (string) ($body['version'] ?? '');
    if (!is_array($data)) reply(400, ['error' => 'data must be an object']);

    if ($kind === 'metrics') {
        if (strlen(json_encode($data)) > AGENT_MAX_METRICS_BYTES) reply(413, ['error' => 'metrics too large']);
        store_agent_metrics($hostId, $data, $version, $ip);
        store_agent_services($hostId, $data['services'] ?? null);   // agents 0.6+ (Linux) / 0.5+ (Windows)
    } elseif ($kind === 'inventory') {
        store_agent_inventory($hostId, $data, $version, $ip);
    } elseif ($kind === 'workloads') {
        if (strlen(json_encode($data)) > AGENT_MAX_METRICS_BYTES) reply(413, ['error' => 'workloads report too large']);
        store_agent_workloads($hostId, $data);
    } else {
        reply(400, ['error' => 'kind must be metrics, inventory or workloads']);
    }
    reply(200, ['ok' => true, 'intervals' => $intervals, 'watch_services' => services_watch_list($hostId)]);
}

if ($action === 'jobs' || $action === 'job_update') {
    $hostId = agent_host_for_token((string) ($_SERVER['HTTP_X_AGENT_TOKEN'] ?? ''));
    if ($hostId === null) reply(401, ['error' => 'unknown agent token — the agent may have been removed or re-installed']);
    $body = read_json_body(JOB_OUTPUT_MAX * 2);
    db()->prepare('UPDATE agents SET last_seen_at = ? WHERE host_id = ?')->execute([now_ms(), $hostId]);

    if ($action === 'jobs') {
        reply(200, ['job' => next_job_for_host($hostId)]);
    }
    $ok = update_job($hostId, (int) ($body['id'] ?? 0), (string) ($body['status'] ?? ''),
        substr((string) ($body['output'] ?? ''), 0, JOB_OUTPUT_MAX),
        isset($body['exit_code']) && is_numeric($body['exit_code']) ? (int) $body['exit_code'] : null,
        isset($body['summary']) ? (string) $body['summary'] : null,
        !empty($body['rebooting']));
    // 409 tells the agent the job was cancelled/expired/failed server-side.
    reply($ok ? 200 : 409, ['ok' => $ok]);
}

reply(404, ['error' => 'unknown action']);
