<?php
require_once __DIR__ . '/configbackup.php';

/**
 * DHCP leases from switches that are DHCP servers (Cisco IOS "ip dhcp pool"),
 * read over the SSH login set up for config backups. A lease ties an address
 * to a MAC even when the device isn't in any ARP table right now, so devices
 * known only by address get a maker (and private-address phones are seen as such).
 * Stored in settings 'dhcp_leases': {"at": ms, "leases": {ip: mac}}.
 */

const DHCP_LEASES_EVERY_SEC = 3600;

/** "show ip dhcp binding" -> [ip => mac]. Client IDs "01" + MAC only (others are DUIDs). */
function parse_cisco_dhcp_bindings(string $out): array {
    $leases = [];
    foreach (explode("\n", $out) as $line) {
        if (!preg_match('/^(\d{1,3}(?:\.\d{1,3}){3})\s+([0-9a-f.]+)\s/i', trim($line), $m)) continue;
        if (!filter_var($m[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) continue;
        $hex = strtolower(str_replace('.', '', $m[2]));
        if (strlen($hex) === 14 && str_starts_with($hex, '01')) $hex = substr($hex, 2);   // client ID: type 01 (Ethernet) + MAC
        if (strlen($hex) === 12 && ctype_xdigit($hex)) $leases[$m[1]] = implode(':', str_split($hex, 2));
    }
    return $leases;
}

/** Hourly from cli/poll-snmp.php: devices whose backed-up config has DHCP pools. */
function refresh_dhcp_leases(bool $force = false): void {
    $pdo = db();
    $stored = json_decode((string) $pdo->query("SELECT v FROM settings WHERE k = 'dhcp_leases'")->fetchColumn(), true) ?: [];
    if (!$force && (int) ($stored['at'] ?? 0) > now_ms_cfg() - DHCP_LEASES_EVERY_SEC * 1000) return;
    $rows = $pdo->query("SELECT c.*, h.ip_address FROM config_backup_settings c JOIN hosts h ON h.id = c.host_id
                         WHERE c.vendor = 'cisco_ios' AND c.last_error IS NULL")->fetchAll();
    $latest = $pdo->prepare('SELECT content FROM switch_configs WHERE host_id = ? ORDER BY fetched_at DESC LIMIT 1');
    $leases = [];
    $ok = false;
    foreach ($rows as $r) {
        $latest->execute([$r['host_id']]);
        if (!preg_match('/^ip dhcp pool /m', (string) $latest->fetchColumn())) continue;   // not a DHCP server
        [$out, $err] = ssh_shell($r, $r['ip_address'], ['enable' => true, 'commands' => ['terminal length 0', 'show ip dhcp binding']]);
        if ($err || $out === null) {
            printf("%s DHCP leases from %s failed: %s\n", date('Y-m-d H:i:s'), $r['ip_address'], $err);
            continue;
        }
        $leases += parse_cisco_dhcp_bindings($out);
        $ok = true;
    }
    // Keep the last good table when every server failed; try again next hour either way.
    if (!$ok && $rows) $leases = $stored['leases'] ?? [];
    $pdo->prepare("INSERT INTO settings (k, v) VALUES ('dhcp_leases', ?) ON DUPLICATE KEY UPDATE v = VALUES(v)")
        ->execute([json_encode(['at' => now_ms_cfg(), 'leases' => $leases])]);
}

/** [ip => mac] from the last lease table. */
function dhcp_lease_macs(): array {
    $stored = json_decode((string) db()->query("SELECT v FROM settings WHERE k = 'dhcp_leases'")->fetchColumn(), true) ?: [];
    return is_array($stored['leases'] ?? null) ? $stored['leases'] : [];
}
