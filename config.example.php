<?php
// Database connection. Copy this file to config.php and set DB_PASS;
// rocky-install.sh does this automatically with a generated password.
define('DB_HOST', '127.0.0.1');
define('DB_NAME', 'webmonitor');
define('DB_USER', 'webmonitor');
define('DB_PASS', 'change-me');

// Minimum seconds between checks for a given host (used by the lazy
// check-on-poll trigger in api/hosts.php).
define('CHECK_INTERVAL_SEC', 10);

// How long to wait for a host to respond before marking it down.
define('CHECK_TIMEOUT_SEC', 5);

// Number of most recent check results kept per host for the graph.
define('HISTORY_LIMIT', 60);

// Server TLS certificate. Agents are given its public-key fingerprint so
// they can trust this server's self-signed certificate.
define('TLS_CERT_FILE', '/etc/pki/tls/certs/webmonitor.crt');

// Timezone for times shown in alerts. Defaults to the server's system
// timezone; uncomment to override.
// define('TIMEZONE', 'America/New_York');
