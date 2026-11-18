<?php
// Vulnerability scan worker (Security tab). Run every minute by the
// webmonitor-vuln timer; runs queued scans, then exits. Only one at a time.
//   php cli/vulnscan.php
require_once __DIR__ . '/../includes/vulnscan.php';

if (PHP_SAPI !== 'cli') exit(1);
run_vuln_scans();
