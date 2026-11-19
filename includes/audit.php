<?php
require_once __DIR__ . '/db.php';

/**
 * Audit log: an append-only record of who did what, for audit and
 * traceability. Each row's hash covers its content and the previous row's
 * hash (a hash chain), so a row edited or deleted in the database shows up
 * as a break when the chain is verified (audit_verify()).
 *
 * Two kinds of entries:
 *  - specific events written where they happen (sign-ins, change records,
 *    schedule runs, approvals), with a readable summary;
 *  - every dashboard request that changes something (any API call other
 *    than GET), written automatically by require_api_auth() when the request
 *    ends, with the endpoint, the outcome and the request body (secrets
 *    redacted).
 */

const AUDIT_GENESIS = '0000000000000000000000000000000000000000000000000000000000000000';
const AUDIT_REDACT = '/pass|token|secret|community|private|apikey|api_key/i';
// Requests that change nothing worth recording.
const AUDIT_SKIP_ENDPOINTS = ['ping.php', 'logout.php', 'agent.php'];

/** Who is acting in this request (set by require_api_auth / sign-in); null = WebMonitor itself. */
function audit_actor(?array $user = null, bool $set = false): ?array {
    static $actor = null;
    if ($set) $actor = $user;
    return $actor;
}

/**
 * Writes one entry. $action is a dotted name ('change.approved',
 * 'auth.login_failed', 'api.write' ...). $details is stored as JSON.
 * Never throws: auditing must not break the action being audited, but a
 * failure is written to the PHP error log.
 */
function audit_log(string $action, string $summary, ?string $target = null, ?array $details = null,
                   string $outcome = 'ok', ?array $actor = null, bool $system = false): void {
    $actor = $system ? null : ($actor ?? audit_actor());
    $pdo = db();
    $locked = false;
    try {
        // One writer at a time, so each row chains onto the true previous row.
        $locked = (int) $pdo->query("SELECT GET_LOCK('webmonitor_audit', 10)")->fetchColumn() === 1;
        $prev = $pdo->query('SELECT hash FROM audit_log ORDER BY id DESC LIMIT 1')->fetchColumn() ?: AUDIT_GENESIS;
        $row = [
            'ts' => (int) round(microtime(true) * 1000),
            'user_id' => $actor ? (int) $actor['id'] : null,
            'username' => $actor ? (string) $actor['username'] : null,
            'ip' => $actor !== null && PHP_SAPI !== 'cli' ? substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45) : null,
            'action' => substr($action, 0, 40),
            'target' => $target !== null ? mb_substr($target, 0, 120) : null,
            'summary' => mb_substr($summary, 0, 500),
            'details' => $details !== null ? mb_substr(json_encode($details, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 0, 60000) : null,
            'outcome' => in_array($outcome, ['ok', 'denied', 'failed'], true) ? $outcome : 'ok',
        ];
        $hash = audit_hash($prev, $row);
        $pdo->prepare('INSERT INTO audit_log (ts, user_id, username, ip, action, target, summary, details, outcome, prev_hash, hash)
                       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$row['ts'], $row['user_id'], $row['username'], $row['ip'], $row['action'], $row['target'],
                       $row['summary'], $row['details'], $row['outcome'], $prev, $hash]);
    } catch (Throwable $e) {
        error_log('webmonitor audit: could not write "' . $action . '": ' . $e->getMessage());
    } finally {
        if ($locked) $pdo->query("SELECT RELEASE_LOCK('webmonitor_audit')");
    }
}

function audit_hash(string $prev, array $r): string {
    return hash('sha256', implode("\x1f", [$prev, $r['ts'], $r['user_id'] ?? '', $r['username'] ?? '', $r['ip'] ?? '',
        $r['action'], $r['target'] ?? '', $r['summary'], $r['details'] ?? '', $r['outcome']]));
}

/**
 * Re-computes the chain. Returns ['ok' => bool, 'checked' => n, 'brokenAt' => id|null, 'reason' => ...].
 */
function audit_verify(): array {
    $prev = AUDIT_GENESIS;
    $n = 0;
    $stmt = db()->query('SELECT * FROM audit_log ORDER BY id');
    while ($r = $stmt->fetch()) {
        $n++;
        if ($r['prev_hash'] !== $prev) {
            return ['ok' => false, 'checked' => $n, 'brokenAt' => (int) $r['id'], 'reason' => 'a row before this one was removed or changed'];
        }
        if (!hash_equals($r['hash'], audit_hash($prev, $r))) {
            return ['ok' => false, 'checked' => $n, 'brokenAt' => (int) $r['id'], 'reason' => 'this row was changed after it was written'];
        }
        $prev = $r['hash'];
    }
    return ['ok' => true, 'checked' => $n, 'brokenAt' => null, 'reason' => null];
}

/** Copy of $v with secret-looking fields replaced. */
function audit_redact($v) {
    if (!is_array($v)) return $v;
    $out = [];
    foreach ($v as $k => $x) {
        $out[$k] = is_string($k) && preg_match(AUDIT_REDACT, $k) ? '[redacted]' : audit_redact($x);
    }
    return $out;
}

const AUDIT_AREAS = [
    'schedules.php' => 'update schedule', 'jobs.php' => 'update jobs', 'changes.php' => 'change record',
    'host.php' => 'host', 'hosts.php' => 'host', 'host-agent.php' => 'host agent', 'users.php' => 'user',
    'account.php' => 'own account', 'notifications.php' => 'notifications', 'groups.php' => 'groups / screens',
    'locations.php' => 'locations', 'subnets.php' => 'subnets', 'homeassistant.php' => 'Home Assistant',
    'services.php' => 'services', 'scan.php' => 'scan', 'scan-queue.php' => 'scan queue', 'vulns.php' => 'vulnerability scan',
    'switch-config.php' => 'switch config', 'flexlm.php' => 'licence servers', 'network.php' => 'network',
    'alerts.php' => 'alerts', 'devices.php' => 'devices', 'wireless.php' => 'wireless', 'port-map.php' => 'port map',
];

/**
 * Registered by require_api_auth() for every non-GET request: when the
 * request ends, records the endpoint, what was sent and how it went.
 */
function audit_api_request(array $user): void {
    $endpoint = basename(parse_url((string) ($_SERVER['SCRIPT_NAME'] ?? ''), PHP_URL_PATH) ?: '');
    if (in_array($endpoint, AUDIT_SKIP_ENDPOINTS, true) || !empty($_GET['preview'])) return;
    $method = (string) ($_SERVER['REQUEST_METHOD'] ?? '');
    $body = json_decode((string) file_get_contents('php://input'), true);
    // Display preferences (theme, columns) aren't worth an audit entry.
    if ($endpoint === 'account.php' && $method === 'PATCH' && is_array($body) && array_keys($body) === ['prefs']) return;
    $code = http_response_code() ?: 200;
    $outcome = $code < 400 ? 'ok' : ($code === 401 || $code === 403 ? 'denied' : 'failed');
    $query = array_diff_key($_GET, ['csrf' => 1]);
    // POST creates, unless it's an action on something that exists (?id=…&run=1, ?decision=…, ?test=1 …).
    $postVerb = isset($query['decision']) ? (string) $query['decision']
        : (array_intersect_key($query, array_flip(['run', 'action', 'test', 'sync', 'scan', 'what'])) ? 'run' : 'create');
    $verb = ['POST' => $postVerb, 'PUT' => 'save', 'PATCH' => 'change', 'DELETE' => 'remove'][$method] ?? $method;
    $area = AUDIT_AREAS[$endpoint] ?? preg_replace('/\.php$/', '', $endpoint);
    $target = $area . (isset($query['id']) ? " #{$query['id']}" : '');
    audit_log('api.' . strtolower($method), ucfirst("$verb $target") . ($outcome === 'ok' ? '' : " ($code)"), $target,
        ['endpoint' => "api/$endpoint", 'query' => $query ?: null, 'body' => is_array($body) ? audit_redact($body) : null, 'status' => $code],
        $outcome, $user);
}

function audit_row(array $r): array {
    return [
        'id' => (int) $r['id'], 'ts' => (int) $r['ts'], 'username' => $r['username'], 'ip' => $r['ip'],
        'action' => $r['action'], 'target' => $r['target'], 'summary' => $r['summary'],
        'details' => $r['details'] !== null ? json_decode($r['details'], true) : null,
        'outcome' => $r['outcome'], 'hash' => $r['hash'],
    ];
}
