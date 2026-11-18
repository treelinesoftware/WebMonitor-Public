<?php
// Internet speed tests for the Network tab.
//   php cli/speedtest.php --if-due    run one if it's due or someone pressed "Run now"
//                                     (every minute, by webmonitor-speedtest.timer)
//   php cli/speedtest.php [--manual]  run one now
require_once __DIR__ . '/../includes/speedtest.php';

if (PHP_SAPI !== 'cli') exit(1);
set_time_limit(300);

$manual = in_array('--manual', $argv, true);
if (in_array('--if-due', $argv, true)) {
    $due = speedtest_due_now();
    if ($due === null) exit(0);
    $manual = $due === 'manual';
}

try {
    $r = run_speedtest($manual);
} catch (Throwable $e) {
    fwrite(STDERR, date('c') . ' speed test: ' . $e->getMessage() . "\n");
    exit(1);
}
if ($r['error']) {
    fwrite(STDERR, date('c') . " speed test failed: {$r['error']}\n");
    exit(1);
}
printf("%s speed test (%s): %.1f down / %.1f up Mbit/s, %.1f ms\n", date('c'), $r['engine'], $r['down_mbps'], $r['up_mbps'], $r['ping_ms']);
