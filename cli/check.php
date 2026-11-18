<?php
// Meant to be run from cron, e.g. every minute:
//   * * * * * /usr/bin/php /path/to/WebMonitor/cli/check.php >> /var/log/webmonitor.log 2>&1
require_once __DIR__ . '/../includes/checker.php';

$hosts = db()->query('SELECT * FROM hosts')->fetchAll();
$results = check_hosts($hosts);
foreach ($hosts as $host) {
    $result = $results[(int) $host['id']];
    printf("[%s] %s (%s) -> %s%s\n",
        date('Y-m-d H:i:s'),
        $host['name'],
        $host['ip_address'],
        $result['up'] ? 'up' : 'down',
        $result['ms'] !== null ? " ({$result['ms']}ms)" : ''
    );
}
