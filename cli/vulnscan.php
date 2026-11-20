<?php
// Vulnerability scan worker (WebMonitor Pro: pro/cli/vulnscan.php). Run every
// minute by the webmonitor-vuln timer; does nothing when Pro or the feature is off.
//   php cli/vulnscan.php
require_once __DIR__ . '/../includes/pro.php';

if (PHP_SAPI !== 'cli') exit(1);
if (pro_feature('vulns')) require PRO_DIR . '/cli/vulnscan.php';
