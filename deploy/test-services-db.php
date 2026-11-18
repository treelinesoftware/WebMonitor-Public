<?php
// Service monitoring against a real (throwaway) database: run from a scratch
// copy whose config.php points at an empty test database, after cli/migrate.php.
//   php deploy/test-services-db.php
require_once __DIR__ . '/../includes/services.php';
require_once __DIR__ . '/../includes/alerts.php';
require_once __DIR__ . '/../includes/jobs.php';
require_once __DIR__ . '/../includes/checker.php';

function check(bool $ok, string $message): void {
    if (!$ok) { fwrite(STDERR, "FAIL: $message\n"); exit(1); }
}

$pdo = db();
check(!(int) $pdo->query('SELECT COUNT(*) FROM hosts')->fetchColumn(), 'run this on an empty test database only');

$pdo->exec("INSERT INTO hosts (name, ip_address, os, device) VALUES ('web-01', '127.0.0.1', 'linux', 'server'), ('old-01', '127.0.0.2', 'linux', 'server')");
[$web, $old] = $pdo->query('SELECT id FROM hosts ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
$inv = json_encode(['os' => ['id' => 'debian'], 'services' => [['name' => 'nginx.service', 'display' => 'nginx web server', 'state' => 'active']]]);
$pdo->prepare('INSERT INTO agents (host_id, token_hash, agent_version, inventory, inventory_at, last_seen_at, enrolled_at) VALUES (?, ?, ?, ?, ?, ?, 0)')
    ->execute([$web, str_repeat('a', 64), '0.6.0', $inv, now_ms(), now_ms()]);
$pdo->prepare('INSERT INTO agents (host_id, token_hash, agent_version, inventory, inventory_at, last_seen_at, enrolled_at) VALUES (?, ?, ?, ?, ?, ?, 0)')
    ->execute([$old, str_repeat('b', 64), '0.5.0', $inv, now_ms(), now_ms()]);

// Validation.
[$row, $err] = service_validate(['kind' => 'agent', 'hostId' => $old, 'target' => 'nginx']);
check($row === null && str_contains($err, '0.6.0'), 'old agent refused: ' . $err);
[$row, $err] = service_validate(['kind' => 'agent', 'hostId' => $web, 'target' => 'nginx']);
check($err === null && $row['target'] === 'nginx.service' && $row['name'] === 'nginx', 'agent service: ' . json_encode([$row, $err]));
[, $err] = service_validate(['kind' => 'agent', 'hostId' => $web, 'target' => 'rm -rf /;']);
check($err !== null, 'bad service name refused');
[$tcp, $err] = service_validate(['kind' => 'tcp', 'hostId' => $web, 'target' => '22']);
check($err === null && $tcp['target'] === '127.0.0.1:22' && $tcp['name'] === 'SSH', 'port on host: ' . json_encode([$tcp, $err]));
[, $err] = service_validate(['kind' => 'http', 'target' => 'ftp://x']);
check($err !== null, 'non-http refused');
[$http, $err] = service_validate(['kind' => 'http', 'target' => 'example.test/status', 'options' => ['expect' => '200-299', 'verify_tls' => false]]);
check($err === null && $http['target'] === 'http://example.test/status' && $http['options']['verify_tls'] === false, 'http: ' . json_encode([$http, $err]));
[, $err] = service_validate(['kind' => 'http', 'target' => 'http://x.test', 'options' => ['expect' => 'two hundred']]);
check($err !== null, 'bad expect refused');
[$dns, $err] = service_validate(['kind' => 'dns', 'hostId' => $web, 'target' => 'nas.home.arpa']);
check($err === null && $dns['options']['server'] === '127.0.0.1', 'dns defaults to the host: ' . json_encode([$dns, $err]));

$ins = $pdo->prepare('INSERT INTO services (host_id, kind, name, target, options, interval_sec, enabled, alert, created_at) VALUES (?, ?, ?, ?, ?, ?, 1, 1, ?)');
$ins->execute([$row['host_id'], 'agent', $row['name'], $row['target'], json_encode($row['options']), 60, now_ms()]);
$agentSvc = (int) $pdo->lastInsertId();
[, $err] = service_validate(['kind' => 'agent', 'hostId' => $web, 'target' => 'nginx.service']);
check($err === 'That check already exists.', 'duplicate refused');
$ins->execute([$tcp['host_id'], 'tcp', $tcp['name'], '127.0.0.1:1', '{}', 60, now_ms()]);
$tcpSvc = (int) $pdo->lastInsertId();

// The agent is told what to watch, and its report updates the status.
check(services_watch_list($web) === ['nginx.service'], 'watch list');
store_agent_services($web, [['name' => 'nginx.service', 'state' => 'active', 'sub' => 'running', 'since' => time() - 3600, 'restarts' => 2]]);
$s = service_list($web);
$a = array_values(array_filter($s, fn($x) => $x['id'] === $agentSvc))[0];
check($a['status'] === 'up' && $a['detail']['restarts'] == 2 && $a['message'] === 'active (running)' && count($a['history']) === 1, 'agent up: ' . json_encode($a));
store_agent_services($web, [['name' => 'NGINX.service', 'state' => 'failed', 'sub' => 'failed']]);
$a = array_values(array_filter(service_list($web), fn($x) => $x['id'] === $agentSvc))[0];
check($a['status'] === 'down', 'agent down at once: ' . json_encode($a));

// Network checks need two failures in a row.
run_due_services();
$t = array_values(array_filter(service_list(), fn($x) => $x['id'] === $tcpSvc))[0];
check($t['status'] === 'down' && $t['message'] === 'Connection refused', 'first failure of a new check shows down: ' . json_encode($t));
$pdo->exec("UPDATE services SET status = 'up', fail_count = 0 WHERE id = $tcpSvc");
run_service_checks([$pdo->query("SELECT * FROM services WHERE id = $tcpSvc")->fetch()]);
check($pdo->query("SELECT status FROM services WHERE id = $tcpSvc")->fetchColumn() === 'up', 'one failure keeps up');
run_service_checks([$pdo->query("SELECT * FROM services WHERE id = $tcpSvc")->fetch()]);
check($pdo->query("SELECT status FROM services WHERE id = $tcpSvc")->fetchColumn() === 'down', 'two failures: down');

// Alerts: both down; pausing one closes its alert quietly.
$r = evaluate_alerts();
$open = $pdo->query("SELECT type, subject, severity, message FROM alerts WHERE resolved_at IS NULL AND type LIKE 'service_%' ORDER BY subject")->fetchAll();
check(count($open) === 2 && $open[0]['severity'] === 'critical' && str_contains($open[0]['message'], 'web-01'), 'service alerts: ' . json_encode($open));
check(alert_host_short([$web => ['name' => 'web-01', 'ip_address' => '127.0.0.1']], ['host_id' => $web, 'subject' => "svc:$agentSvc"]) === 'nginx on web-01', 'alert title');
$pdo->exec("UPDATE services SET enabled = 0 WHERE id = $tcpSvc");
evaluate_alerts();
check((int) $pdo->query("SELECT COUNT(*) FROM alerts WHERE resolved_at IS NULL AND subject = 'svc:$tcpSvc'")->fetchColumn() === 0, 'paused check closes its alert');

// Agent goes quiet: unknown, and the alert stays as it was.
$pdo->exec("UPDATE services SET last_check_at = " . (now_ms() - 600000) . " WHERE id = $agentSvc");
run_due_services();
check($pdo->query("SELECT status FROM services WHERE id = $agentSvc")->fetchColumn() === 'unknown', 'stale agent service unknown');
evaluate_alerts();
check((int) $pdo->query("SELECT COUNT(*) FROM alerts WHERE resolved_at IS NULL AND subject = 'svc:$agentSvc'")->fetchColumn() === 1, 'unknown keeps the alert open');

// Card summary and host summaries.
$card = service_card_summaries();
check($card[$web]['total'] === 1 && $card[$web]['other'] === 1, 'card summary: ' . json_encode($card));
$h = host_summaries($pdo->query("SELECT * FROM hosts WHERE id = $web")->fetchAll())[0];
check(isset($h['services']['total']), 'hosts API carries services');

// Restart jobs: queued for watched services; the generic jobs API refuses them.
$res = create_jobs([$web], 'service_restart', ['service' => 'nginx.service'], null);
$job = $pdo->query('SELECT type, params FROM jobs')->fetch();
check(count($res['created']) === 1 && $job['type'] === 'service_restart' && json_decode($job['params'], true) === ['service' => 'nginx.service'], 'restart job');
check(job_params('service_restart', ['service' => 'x; reboot']) === [], 'bad name dropped from job');
check(job_label('service_restart', ['service' => 'nginx.service']) === 'Restart nginx.service', 'job label');

// Deleting the host removes its services.
$pdo->exec("DELETE FROM hosts WHERE id = $web");
check((int) $pdo->query("SELECT COUNT(*) FROM services WHERE host_id = $web")->fetchColumn() === 0, 'cascade');
echo "Service database tests passed.\n";
