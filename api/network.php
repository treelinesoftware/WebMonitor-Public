<?php
// Network tab.
//   GET ?live=1[&hosts=1,2][&names=1]       -> {at, devices: {hostId: {at, error, rates: {ifIndex: [inBps, outBps]}}}, ports?}
//                                              ports (names=1): {hostId: [{i, name, speed, status, lag, desc, ips}]}
//   GET ?port=NAME&host=ID&range=24h        -> {in: [[ts, avg, min, max], ...], out: [...]} port history
//   GET ?portflows=1&host=ID&if=IFINDEX&range=1h -> what's using a port, from NetFlow (port_flow_report())
//   GET ?flowifaces=1&host=ID&range=1h      -> NetFlow the device exports, per interface
//   GET ?speedtests=1&days=30               -> settings, state, latest and recent results
//   POST ?action=speedtest                  -> run a speed test now (starts within a minute); admins
//   PUT ?action=speedtest-settings {...}    -> change the schedule/engine/alert minimums; admins
require_once __DIR__ . '/../includes/netlive.php';
require_once __DIR__ . '/../includes/speedtest.php';
require_once __DIR__ . '/../includes/portflows.php';
require_once __DIR__ . '/../includes/auth.php';
header('Content-Type: application/json');
require_api_auth_admin_writes();

function fail(int $code, string $msg): void {
    http_response_code($code);
    echo json_encode(['error' => $msg]);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];
$action = (string) ($_GET['action'] ?? '');

if ($method === 'GET') {
    if (isset($_GET['live'])) {
        set_time_limit(30);
        $only = isset($_GET['hosts']) && $_GET['hosts'] !== '' ? array_map('intval', explode(',', (string) $_GET['hosts'])) : null;
        $out = ['at' => (int) round(microtime(true) * 1000), 'devices' => (object) live_network_rates($only)];
        if (!empty($_GET['names'])) $out['ports'] = (object) network_port_index();
        echo json_encode($out);
        exit;
    }
    if (isset($_GET['port'])) {
        $range = (string) ($_GET['range'] ?? '24h');
        if (!isset(HISTORY_RANGES[$range])) fail(400, 'unknown range');
        echo json_encode(port_history((int) ($_GET['host'] ?? 0), (string) $_GET['port'], $range));
        exit;
    }
    if (isset($_GET['portflows']) || isset($_GET['flowifaces'])) {
        $range = (string) ($_GET['range'] ?? '1h');
        if (!isset(PORTFLOW_RANGES[$range])) fail(400, 'unknown range');
        set_time_limit(30);
        try {
            echo json_encode(isset($_GET['portflows'])
                ? port_flow_report_cached((int) ($_GET['host'] ?? 0), (int) ($_GET['if'] ?? 0), $range)
                : device_flow_interfaces((int) ($_GET['host'] ?? 0), $range));
        } catch (RuntimeException $e) {
            fail(404, $e->getMessage());
        }
        exit;
    }
    if (isset($_GET['speedtests'])) {
        echo json_encode(speedtest_overview(max(1, min(400, (int) ($_GET['days'] ?? 30)))));
        exit;
    }
    fail(400, 'unknown request');
}

if ($method === 'POST' && $action === 'speedtest') {
    if (speedtest_running()) fail(409, 'A speed test is already running.');
    request_speedtest();
    echo json_encode(['ok' => true]);
    exit;
}

if ($method === 'PUT' && $action === 'speedtest-settings') {
    $in = json_decode(file_get_contents('php://input'), true);
    if (!is_array($in)) fail(400, 'invalid JSON');
    try {
        echo json_encode(['settings' => save_speedtest_settings($in)]);
    } catch (InvalidArgumentException $e) {
        fail(400, $e->getMessage());
    }
    exit;
}

fail(405, 'method not allowed');
