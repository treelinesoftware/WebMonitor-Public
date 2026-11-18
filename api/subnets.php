<?php
// Subnet settings. Names are shown with the address ("Servers · 192.168.10.0/24");
// a subnet's gateway is shown in its heading instead of as a card; discovery
// scans the subnet on a schedule and adds/removes cards (includes/discovery.php).
//   GET                                     -> {names, gateways, scans, log}
//   PUT  {names, gateways, scans}           -> replace all (admins)
//        scans: {subnet: {enabled, everyMin, autoAdd, removeDays, removeScanOnly}}
//   POST ?scan=192.168.10.0/24                -> scan that subnet now (admins)
require_once __DIR__ . '/../includes/discovery.php';
require_once __DIR__ . '/../includes/auth.php';
header('Content-Type: application/json');
require_api_auth_admin_writes();

function fail(int $code, string $msg): void {
    http_response_code($code);
    echo json_encode(['error' => $msg]);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];
$isSubnet = fn($s) => (bool) preg_match('#^\d{1,3}\.\d{1,3}\.\d{1,3}\.0/24$#', (string) $s)
    && filter_var(substr((string) $s, 0, -3), FILTER_VALIDATE_IP, FILTER_FLAG_IPV4);

function subnets_state(): array {
    $log = db()->query('SELECT ts, subnet, action, ip, name, detail FROM discovery_log ORDER BY id DESC LIMIT 40')->fetchAll();
    return [
        'names' => (object) subnet_names(),
        'gateways' => (object) subnet_gateways(),
        'scans' => (object) array_map('subnet_scan_public', subnet_scan_rows()),
        'log' => array_map(fn($l) => ['ts' => (int) $l['ts']] + $l, $log),
    ];
}

if ($method === 'GET') {
    echo json_encode(subnets_state());
    exit;
}

if ($method === 'POST' && isset($_GET['scan'])) {
    $subnet = (string) $_GET['scan'];
    if (!$isSubnet($subnet)) fail(400, 'not a /24 subnet');
    set_time_limit(180);
    $pdo = db();
    if (!(int) $pdo->query("SELECT GET_LOCK('webmonitor_discovery', 60)")->fetchColumn()) fail(409, 'another scan is running, try again shortly');
    try {
        $result = run_subnet_scans($subnet, true)[$subnet] ?? null;
    } finally {
        $pdo->query("SELECT RELEASE_LOCK('webmonitor_discovery')");
    }
    echo json_encode(['result' => $result] + subnets_state());
    exit;
}
if ($method !== 'PUT') fail(405, 'method not allowed');

$input = json_decode(file_get_contents('php://input'), true) ?? [];

$names = [];
foreach ((array) ($input['names'] ?? []) as $subnet => $name) {
    if (!$isSubnet($subnet)) fail(400, "not a /24 subnet: $subnet");
    $name = trim(preg_replace('/\s+/', ' ', (string) $name));
    if ($name === '') continue;   // blank = no name
    if (mb_strlen($name) > 40) fail(400, 'names can be at most 40 characters');
    $names[$subnet] = $name;
}

$gateways = [];
foreach ((array) ($input['gateways'] ?? []) as $subnet => $ip) {
    if (!$isSubnet($subnet)) fail(400, "not a /24 subnet: $subnet");
    $ip = trim((string) $ip);
    if ($ip === '') continue;
    $prefix = substr($subnet, 0, -4);   // "192.168.10."
    if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) || !str_starts_with($ip, $prefix)) {
        fail(400, "gateway for $subnet must be an address in that subnet (e.g. {$prefix}1)");
    }
    $gateways[$subnet] = $ip;
}

$scans = [];
foreach ((array) ($input['scans'] ?? []) as $subnet => $s) {
    if (!$isSubnet($subnet)) fail(400, "not a /24 subnet: $subnet");
    $every = (int) ($s['everyMin'] ?? 60);
    if (!in_array($every, SCAN_EVERY_CHOICES, true)) fail(400, 'unsupported scan interval');
    $days = $s['removeDays'] ?? null;
    if ($days !== null && $days !== '') {
        if (!is_numeric($days) || (int) $days < 1 || (int) $days > 365) fail(400, "remove after must be 1-365 days ($subnet)");
        $days = (int) $days;
    } else {
        $days = null;
    }
    $scans[$subnet] = [!empty($s['enabled']) ? 1 : 0, $every, !empty($s['autoAdd']) ? 1 : 0, $days, ($s['removeScanOnly'] ?? true) ? 1 : 0, !empty($s['autoName']) ? 1 : 0];
}

$pdo = db();
$pdo->beginTransaction();
$save = $pdo->prepare('INSERT INTO settings (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)');
$save->execute(['subnet_names', json_encode($names)]);
$save->execute(['subnet_gateways', json_encode($gateways)]);
if (array_key_exists('scans', $input)) {
    $upsert = $pdo->prepare('INSERT INTO subnet_scans (subnet, enabled, every_min, auto_add, remove_after_days, remove_scan_only, auto_name)
                             VALUES (?, ?, ?, ?, ?, ?, ?)
                             ON DUPLICATE KEY UPDATE enabled = VALUES(enabled), every_min = VALUES(every_min), auto_add = VALUES(auto_add),
                               remove_after_days = VALUES(remove_after_days), remove_scan_only = VALUES(remove_scan_only), auto_name = VALUES(auto_name)');
    foreach ($scans as $subnet => $v) $upsert->execute(array_merge([$subnet], $v));
    // Subnets no longer listed: forget their settings (but not their scan history in the log).
    $keep = array_keys($scans);
    $pdo->prepare('DELETE FROM subnet_scans' . ($keep ? ' WHERE subnet NOT IN (' . implode(',', array_fill(0, count($keep), '?')) . ')' : ''))
        ->execute($keep);
}
$pdo->commit();
echo json_encode(subnets_state());
