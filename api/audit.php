<?php
// Audit log (Reports -> Audit log). Admins only.
//   GET [?q=text&user=name&action=prefix&outcome=ok|denied|failed&from=ms&to=ms&limit=N&before=ID]
//       -> {entries, more}
//   GET ?format=csv[&same filters]   -> CSV export
//   GET ?verify=1                    -> {ok, checked, brokenAt, reason}: re-checks the hash chain
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/auth.php';
header('Content-Type: application/json');
require_api_auth('admin');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'method not allowed']);
    exit;
}

if (!empty($_GET['verify'])) {
    $r = audit_verify();
    audit_log('audit.verified', $r['ok'] ? "Audit log integrity checked: {$r['checked']} entries, chain intact"
        : "Audit log integrity check FAILED at entry #{$r['brokenAt']}: {$r['reason']}", 'audit log', $r, $r['ok'] ? 'ok' : 'failed');
    echo json_encode($r);
    exit;
}

$where = [];
$args = [];
if (($q = trim((string) ($_GET['q'] ?? ''))) !== '') {
    $where[] = '(summary LIKE ? OR target LIKE ? OR details LIKE ?)';
    array_push($args, "%$q%", "%$q%", "%$q%");
}
if (($u = trim((string) ($_GET['user'] ?? ''))) !== '') {
    if ($u === '(system)') {
        $where[] = 'username IS NULL';
    } else {
        $where[] = 'username = ?';
        $args[] = $u;
    }
}
if (($a = trim((string) ($_GET['action'] ?? ''))) !== '') {
    $where[] = 'action LIKE ?';
    $args[] = str_replace(['%', '_'], ['\%', '\_'], $a) . '%';
}
if (in_array($_GET['outcome'] ?? '', ['ok', 'denied', 'failed'], true)) {
    $where[] = 'outcome = ?';
    $args[] = $_GET['outcome'];
}
if (!empty($_GET['from'])) { $where[] = 'ts >= ?'; $args[] = (int) $_GET['from']; }
if (!empty($_GET['to'])) { $where[] = 'ts <= ?'; $args[] = (int) $_GET['to']; }
$sqlWhere = $where ? ' WHERE ' . implode(' AND ', $where) : '';

if (($_GET['format'] ?? '') === 'csv') {
    audit_log('audit.exported', 'Exported the audit log as CSV', 'audit log', ['filters' => array_diff_key($_GET, ['format' => 1])]);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="webmonitor-audit-' . date('Ymd-His') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['ID', 'Time', 'User', 'IP', 'Action', 'Target', 'Summary', 'Outcome', 'Details', 'Hash', 'Previous hash']);
    $stmt = db()->prepare("SELECT * FROM audit_log$sqlWhere ORDER BY id");
    $stmt->execute($args);
    while ($r = $stmt->fetch()) {
        fputcsv($out, [$r['id'], date('Y-m-d H:i:s', intdiv((int) $r['ts'], 1000)), $r['username'] ?? '(WebMonitor)', $r['ip'],
                       $r['action'], $r['target'], $r['summary'], $r['outcome'], $r['details'], $r['hash'], $r['prev_hash']]);
    }
    exit;
}

$limit = max(1, min(500, (int) ($_GET['limit'] ?? 100)));
if (!empty($_GET['before'])) {
    $sqlWhere .= ($sqlWhere ? ' AND' : ' WHERE') . ' id < ?';
    $args[] = (int) $_GET['before'];
}
$stmt = db()->prepare("SELECT * FROM audit_log$sqlWhere ORDER BY id DESC LIMIT " . ($limit + 1));
$stmt->execute($args);
$rows = $stmt->fetchAll();
echo json_encode([
    'entries' => array_map('audit_row', array_slice($rows, 0, $limit)),
    'more' => count($rows) > $limit,
    'users' => db()->query('SELECT DISTINCT username FROM audit_log WHERE username IS NOT NULL ORDER BY username')->fetchAll(PDO::FETCH_COLUMN),
]);
