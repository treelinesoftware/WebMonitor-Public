<?php
// The disposable test app provides a network_sweep stub; this never scans a network.
$app = getenv('WEBMONITOR_TEST_APP');
if (!$app) throw new RuntimeException('WEBMONITOR_TEST_APP is required');
require_once $app . '/includes/discovery.php';
if (!defined('SCAN_QUEUE_TEST_STUB') || !str_starts_with(DB_NAME, 'webmonitor_test_')) {
    throw new RuntimeException('An isolated test database and scan stub are required');
}

$pdo = db();
$pdo->exec('DELETE FROM subnet_scans');
$pdo->exec("INSERT INTO subnet_scans (subnet, enabled) VALUES
    ('192.0.2.0/24', 1), ('198.51.100.0/24', 1), ('203.0.113.0/24', 1)");
$pdo->query("SELECT GET_LOCK('webmonitor_discovery', 0)");
try {
    run_subnet_scans();
} finally {
    $pdo->query("SELECT RELEASE_LOCK('webmonitor_discovery')");
}
if ($GLOBALS['test_scan_order'] !== ['192.0.2.0/24', '203.0.113.0/24', '198.51.100.0/24']) {
    throw new RuntimeException('A newly prioritized subnet was not selected next');
}
$requested = $pdo->query("SELECT requested_at FROM subnet_scans WHERE subnet = '192.0.2.0/24'")->fetchColumn();
if ($requested === null || $requested === false) throw new RuntimeException('A request made during a subnet scan was lost');
$requested = $pdo->query("SELECT requested_at FROM subnet_scans WHERE subnet = '203.0.113.0/24'")->fetchColumn();
if ($requested !== null) throw new RuntimeException('A completed subnet request was not cleared');
echo "Subnet queue integration checks passed.\n";
