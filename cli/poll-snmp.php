<?php
// Background polls run every 5 minutes by webmonitor-snmp.timer, separately
// from cli/cron.php so slow devices never delay host checks and alerts:
//   - SNMP health of NAS hosts (QNAP agents can take 20-40 s to answer);
//   - subnet discovery: scheduled scans and removal of unseen cards;
//   - scheduled switch configuration backups;
//   - switch port maps (what's connected to each port);
//   - Wi-Fi (Aruba Instant APs, radios and clients);
//   - the MAC manufacturer list used to identify devices (when hwdata updates it);
//   - device scans (each device asked what it is, once a day), and naming cards from them.
// Alerts on the collected data are raised by cron.php.
require_once __DIR__ . '/../includes/hostdetails.php';
require_once __DIR__ . '/../includes/discovery.php';
require_once __DIR__ . '/../includes/configbackup.php';
require_once __DIR__ . '/../includes/dhcpleases.php';
require_once __DIR__ . '/../includes/homeassistant.php';
require_once __DIR__ . '/../includes/portmap.php';
require_once __DIR__ . '/../includes/wireless.php';
require_once __DIR__ . '/../includes/devices.php';
require_once __DIR__ . '/../includes/devicescan.php';

if (PHP_SAPI !== 'cli') exit(1);

$pdo = db();
if ((int) $pdo->query("SELECT GET_LOCK('webmonitor_snmp_poll', 0)")->fetchColumn()) {   // else: previous run still going
    poll_snmp_devices();
    $pdo->query("SELECT RELEASE_LOCK('webmonitor_snmp_poll')");
}
if ((int) $pdo->query("SELECT GET_LOCK('webmonitor_discovery', 0)")->fetchColumn()) {
    run_subnet_scans();
    $pdo->query("SELECT RELEASE_LOCK('webmonitor_discovery')");
}
run_config_backups(false);
refresh_dhcp_leases();
refresh_ha_devices();
refresh_port_maps();
refresh_wlan_maps();
ensure_mac_vendors();
refresh_device_scans();
auto_name_cards();
