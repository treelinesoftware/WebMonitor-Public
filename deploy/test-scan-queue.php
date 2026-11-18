<?php
// Integration test against an isolated, disposable database and app directory.
$app = getenv('WEBMONITOR_TEST_APP');
if (!$app || !is_file($app . '/includes/devicescan.php')) throw new RuntimeException('WEBMONITOR_TEST_APP is required');
require_once $app . '/includes/devicescan.php';
if (!str_starts_with(DB_NAME, 'webmonitor_test_')) throw new RuntimeException('Refusing to use a non-test database');

function check(bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
}

$pdo = db();
$pdo->exec('DELETE FROM device_scans');
$pdo->exec('DELETE FROM scan_activity');
request_device_scan(null, '192.0.2.1', false);
request_device_scan(null, '192.0.2.2', false);
request_device_scan(null, '192.0.2.2');
$next = $pdo->query('SELECT ip FROM device_scans WHERE requested_at IS NOT NULL ORDER BY priority_at DESC, requested_at, dkey LIMIT 1')->fetchColumn();
check($next === '192.0.2.2', 'manual request did not take priority');
request_device_scan(null, '192.0.2.1');
$next = $pdo->query('SELECT ip FROM device_scans WHERE requested_at IS NOT NULL ORDER BY priority_at DESC, requested_at, dkey LIMIT 1')->fetchColumn();
check($next === '192.0.2.1', 'newest manual request did not move to the front');

$old = (int) $pdo->query("SELECT requested_at FROM device_scans WHERE ip = '192.0.2.2'")->fetchColumn();
request_device_scan(null, '192.0.2.2');
save_device_scan(null, '192.0.2.2', [], $old);
$pending = $pdo->query("SELECT requested_at, priority_at FROM device_scans WHERE ip = '192.0.2.2'")->fetch();
check($pending['requested_at'] !== null && $pending['priority_at'] !== null, 'request during a scan was lost');
save_device_scan(null, '192.0.2.2', [], (int) $pending['requested_at']);
$pending = $pdo->query("SELECT requested_at, priority_at FROM device_scans WHERE ip = '192.0.2.2'")->fetch();
check($pending['requested_at'] === null && $pending['priority_at'] === null, 'completed request was not cleared');

request_device_scan(null, '192.0.2.3');
save_device_scan(null, '192.0.2.3', [], null);
check($pdo->query("SELECT requested_at FROM device_scans WHERE ip = '192.0.2.3'")->fetchColumn() !== null,
    'automatic scan cleared a concurrent request');

request_device_scan('00:11:22:33:44:55', '192.0.2.4');
$old = (int) $pdo->query("SELECT requested_at FROM device_scans WHERE mac = '00:11:22:33:44:55'")->fetchColumn();
request_device_scan('00:11:22:33:44:55', '192.0.2.5');
save_device_scan('00:11:22:33:44:55', '192.0.2.4', [], $old);
check($pdo->query("SELECT ip FROM device_scans WHERE mac = '00:11:22:33:44:55'")->fetchColumn() === '192.0.2.5',
    'an older scan overwrote the newly requested address');

$pdo->query("SELECT GET_LOCK('webmonitor_device_scan', 0)");
scan_activity_start('webmonitor_device_scan', 'device', '192.0.2.2');
check(count(active_scans()) === 1, 'running scan was not visible');
$pdo->query("SELECT RELEASE_LOCK('webmonitor_device_scan')");
check(count(active_scans()) === 0, 'stale scan was shown as running');
scan_activity_end('webmonitor_device_scan');
$pdo->exec("INSERT INTO scan_activity (lock_name, connection_id, kind, target, started_at) VALUES ('webmonitor_stale_test', 999999, 'subnet', 'old', 1)");
$pdo->query("SELECT GET_LOCK('webmonitor_device_scan', 0)");
scan_activity_start('webmonitor_device_scan', 'device', '192.0.2.2');
check((int) $pdo->query("SELECT COUNT(*) FROM scan_activity WHERE lock_name = 'webmonitor_stale_test'")->fetchColumn() === 0,
    'stale activity was not cleaned up');
scan_activity_end('webmonitor_device_scan');
$pdo->query("SELECT RELEASE_LOCK('webmonitor_device_scan')");
echo "Scan queue integration checks passed.\n";
