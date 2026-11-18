<?php
// Background work, run every minute by the webmonitor-cron.timer systemd
// unit (see deploy/): checks hosts that are due and evaluates alerts, so
// monitoring and notifications don't depend on anyone having the
// dashboard open.
require_once __DIR__ . '/../includes/checker.php';
require_once __DIR__ . '/../includes/alerts.php';
require_once __DIR__ . '/../includes/jobs.php';
require_once __DIR__ . '/../includes/schedules.php';
require_once __DIR__ . '/../includes/topology.php';
require_once __DIR__ . '/../includes/history.php';
require_once __DIR__ . '/../includes/configbackup.php';
require_once __DIR__ . '/../includes/flows.php';
require_once __DIR__ . '/../includes/devicescan.php';
require_once __DIR__ . '/../includes/services.php';
require_once __DIR__ . '/../includes/netlive.php';

if (PHP_SAPI !== 'cli') exit(1);

check_due_hosts();
run_due_services();   // web, port and DNS checks (Services tab)
refresh_arp_cache();   // after checks, so the table is fresh
record_ping_points();
rollup_history();      // does work once per hour
job_housekeeping();
process_schedules();
$r = evaluate_alerts();
if (!empty($r['opened']) || !empty($r['resolved']) || !empty($r['reminders'])) {
    printf("%s alerts: %d open, %d new, %d resolved, %d reminders, sent to %d channel(s)\n",
        date('Y-m-d H:i:s'), $r['open'], $r['opened'], $r['resolved'], $r['reminders'], $r['sent']);
}
ingest_flows();        // NetFlow minutes from the webmonitor-flow collector
record_port_traffic(); // switch port rates for the Network tab's history
run_requested_scans(); // "Scan now" on a device
run_config_backups(true);   // "Back up now" requests only; last, since SSH logins can take a while
