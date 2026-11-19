<?php
// Change records for updates (Updates -> Change records) and their settings.
//   GET  [?status=open|closed|all&limit=N&before=ID]  -> {changes, settings, emailReady}   (any user)
//   GET  ?id=N                                        -> {change: {... planned, outcome, history}}
//   GET  ?format=csv[&status=...]                     -> CSV export (admins)
//   POST ?id=N&decision=approve|reject {note}         -> approve / reject (admins)
//   GET  ?what=settings                               -> change-management settings
//   PUT  ?what=settings {recipients, lead_hours, require_approval, notify_manual}  (admins)
//   POST ?what=test                                   -> send a test email to the change list (admins)
require_once __DIR__ . '/../includes/schedules.php';
require_once __DIR__ . '/../includes/auth.php';
header('Content-Type: application/json');
$me = require_api_auth_admin_writes();

function fail(int $code, string $msg): void {
    http_response_code($code);
    echo json_encode(['error' => $msg]);
    exit;
}

$pdo = db();
$method = $_SERVER['REQUEST_METHOD'];
$id = (int) ($_GET['id'] ?? 0);
$what = (string) ($_GET['what'] ?? '');
$input = $method === 'GET' ? [] : (json_decode(file_get_contents('php://input'), true) ?? []);
$emailReady = fn() => change_email_channel() !== null;

if ($what === 'settings') {
    if ($method === 'PUT') {
        try {
            save_change_settings($input);
        } catch (InvalidArgumentException $e) {
            fail(400, $e->getMessage());
        }
    } elseif ($method !== 'GET') {
        fail(405, 'method not allowed');
    }
    echo json_encode(['settings' => change_settings(), 'emailReady' => $emailReady()]);
    exit;
}

if ($what === 'test' && $method === 'POST') {
    $err = change_email('WebMonitor change management: test message',
        "This is a test from WebMonitor change management.\nChange notices, decisions and completion reports will be sent to this list.\n");
    if ($err !== null) fail(400, "Could not send: $err");
    echo json_encode(['ok' => true]);
    exit;
}

if ($method === 'POST' && $id) {
    try {
        $c = change_decide($id, (string) ($_GET['decision'] ?? ''), (string) ($input['note'] ?? ''), $me);
    } catch (InvalidArgumentException $e) {
        fail(400, $e->getMessage());
    }
    echo json_encode(['change' => change_row($c, true)]);
    exit;
}

if ($method !== 'GET') fail(405, 'method not allowed');

if ($id) {
    $c = change_get($id);
    if (!$c) fail(404, 'Change not found.');
    echo json_encode(['change' => change_row($c, true)]);
    exit;
}

$status = (string) ($_GET['status'] ?? 'all');
$where = match ($status) {
    'open' => "status IN ('planned', 'pending_approval', 'approved', 'in_progress')",
    'closed' => "status NOT IN ('planned', 'pending_approval', 'approved', 'in_progress')",
    default => '1',
};

if (($_GET['format'] ?? '') === 'csv') {
    if (!is_admin($me)) fail(403, 'admin access required');
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="webmonitor-changes-' . date('Ymd-His') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Change', 'Status', 'Source', 'Title', 'Action', 'Window / started', 'Requested by', 'Decision by', 'Decided at',
                   'Decision note', 'Started', 'Finished', 'Hosts', 'Planned packages', 'Results', 'Skipped', 'Still pending after verification']);
    $rows = $pdo->query("SELECT * FROM change_records WHERE $where ORDER BY id")->fetchAll();
    $t = fn($ms) => $ms ? date('Y-m-d H:i:s', intdiv((int) $ms, 1000)) : '';
    foreach ($rows as $c) {
        $planned = json_decode($c['planned'], true) ?: [];
        $outcome = json_decode((string) $c['outcome'], true) ?: [];
        $ver = json_decode((string) $c['verification'], true) ?: [];
        fputcsv($out, [
            change_number((int) $c['id']), CHANGE_STATUS_LABELS[$c['status']] ?? $c['status'], $c['source'], $c['title'],
            job_label($c['job_type'], json_decode($c['params'], true) ?: []), $t($c['planned_at']), $c['requested_name'] ?? 'WebMonitor',
            $c['decided_name'], $t($c['decided_at']), $c['decision_note'], $t($c['started_at']), $t($c['finished_at']),
            implode('; ', array_map(fn($p) => $p['host'], $planned)),
            implode('; ', array_map(fn($p) => $p['host'] . ': ' . (is_array($p['packages'] ?? null)
                ? implode(', ', array_map(fn($x) => $x['name'] . (isset($x['new']) ? ' ' . $x['new'] : ''), $p['packages'])) : '-'), $planned)),
            implode('; ', array_map(fn($o) => "{$o['host']}: {$o['status']}" . ($o['summary'] ? " ({$o['summary']})" : ''), $outcome)),
            implode('; ', array_map(fn($s) => "{$s['host']}: {$s['reason']}", json_decode((string) $c['skipped'], true) ?: [])),
            implode('; ', array_map(fn($hid, $v) => ($planned[$hid]['host'] ?? "#$hid") . ': ' . (count($v['stillPending']) ? implode(', ', $v['stillPending']) : 'none'),
                array_keys($ver), $ver)),
        ]);
    }
    exit;
}

$limit = max(1, min(500, (int) ($_GET['limit'] ?? 50)));
$before = (int) ($_GET['before'] ?? 0);
$rows = $pdo->query("SELECT * FROM change_records WHERE $where" . ($before ? " AND id < $before" : '') . " ORDER BY id DESC LIMIT $limit")->fetchAll();
echo json_encode([
    'changes' => array_map('change_row', $rows),
    'settings' => change_settings(),
    'emailReady' => $emailReady(),
    'pendingApproval' => (int) $pdo->query("SELECT COUNT(*) FROM change_records WHERE status = 'pending_approval'")->fetchColumn(),
]);
