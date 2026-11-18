<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/agents.php';   // now_ms()

/**
 * What runs where: Proxmox VE guests and Docker/Podman containers reported
 * by agents, with guests matched to the hosts WebMonitor monitors.
 *
 * A guest is matched to a host by, in order: an IP the hypervisor knows for
 * it (guest agent, LXC config, its ARP table), then its MAC address via
 * this server's own ARP table (cached by cron) or MACs other agents report.
 */

const WORKLOADS_STALE_SEC = 30 * 60;

/** Caches this server's ARP table ({mac: ip}). Called by cron every minute. */
function refresh_arp_cache(): void {
    $raw = @file_get_contents('/proc/net/arp');
    if ($raw === false) return;
    $map = [];
    foreach (array_slice(explode("\n", trim($raw)), 1) as $line) {
        $f = preg_split('/\s+/', trim($line));
        if (count($f) >= 4 && $f[3] !== '00:00:00:00:00:00' && $f[2] !== '0x0') $map[strtolower($f[3])] = $f[0];
    }
    db()->prepare("INSERT INTO settings (k, v) VALUES ('arp_cache', ?) ON DUPLICATE KEY UPDATE v = VALUES(v)")
        ->execute([json_encode($map)]);
    // A complete ARP entry means the device answered recently: it counts as seen
    // (phones and other devices with no open ports never pass a check).
    if ($map) {
        $ips = array_values(array_unique($map));
        db()->prepare('UPDATE hosts SET last_seen_at = ? WHERE ip_address IN (' . implode(',', array_fill(0, count($ips), '?')) . ')')
            ->execute(array_merge([now_ms()], $ips));
    }
}

function store_agent_workloads(int $hostId, array $w): void {
    db()->prepare('UPDATE agents SET workloads = ?, workloads_at = ?, last_seen_at = ? WHERE host_id = ?')
        ->execute([json_encode($w), now_ms(), now_ms(), $hostId]);
    // Guests WebMonitor only knows by IP take their Proxmox name.
    if (is_array($w['proxmox'] ?? null)) {
        foreach (build_topology()['parents'] as $guestId => $p) {
            if ($p['hostId'] === $hostId) adopt_host_name($guestId, $p['name']);
        }
    }
}

/**
 * Returns [
 *   'parents'     => [hostId => ['hostId' => hypervisor id, 'kind' => qemu|lxc, 'vmid', 'name', 'status']],
 *   'hypervisors' => [hostId => ['version', 'stale', 'guests' => [[vmid, type, name, status, ips, hostId|null]...]]],
 *   'docker'      => [hostId => ['engine', 'stale', 'containers' => [...]]],
 * ]
 */
function build_topology(): array {
    $pdo = db();
    $hostsByIp = [];
    foreach ($pdo->query('SELECT id, ip_address FROM hosts')->fetchAll() as $h) $hostsByIp[$h['ip_address']] = (int) $h['id'];

    $macToIp = json_decode((string) $pdo->query("SELECT v FROM settings WHERE k = 'arp_cache'")->fetchColumn(), true) ?: [];
    $macToHost = [];
    $rows = $pdo->query('SELECT host_id, metrics, workloads, workloads_at FROM agents')->fetchAll();
    foreach ($rows as $r) {
        foreach ((json_decode((string) $r['metrics'], true)['network'] ?? []) as $n) {
            if (!empty($n['mac'])) $macToHost[strtolower($n['mac'])] = (int) $r['host_id'];
        }
    }

    $out = ['parents' => [], 'hypervisors' => [], 'docker' => []];
    $now = (int) round(microtime(true) * 1000);
    foreach ($rows as $r) {
        if ($r['workloads'] === null) continue;
        $w = json_decode($r['workloads'], true) ?: [];
        $hid = (int) $r['host_id'];
        $stale = $now - (int) $r['workloads_at'] > WORKLOADS_STALE_SEC * 1000;

        if (is_array($w['proxmox'] ?? null)) {
            $guests = [];
            foreach (($w['proxmox']['guests'] ?? []) as $g) {
                $match = null;
                // Link-local addresses (169.254.x.x) mean nothing outside the guest.
                $ips = array_values(array_filter((array) ($g['ips'] ?? []), fn($ip) => !str_starts_with((string) $ip, '169.254.')));
                foreach ($ips as $ip) if (isset($hostsByIp[$ip])) { $match = $hostsByIp[$ip]; break; }
                foreach ((array) ($g['macs'] ?? []) as $mac) {
                    if ($match !== null) break;
                    if (isset($macToIp[$mac])) {
                        $ips[] = $macToIp[$mac];
                        if (isset($hostsByIp[$macToIp[$mac]])) $match = $hostsByIp[$macToIp[$mac]];
                    }
                    if ($match === null && isset($macToHost[$mac])) $match = $macToHost[$mac];
                }
                if ($match === $hid) $match = null;   // never its own parent
                $guest = [
                    'vmid' => (int) ($g['vmid'] ?? 0), 'type' => $g['type'] === 'lxc' ? 'lxc' : 'qemu',
                    'name' => (string) ($g['name'] ?? ''), 'status' => (string) ($g['status'] ?? ''),
                    'ips' => array_values(array_unique($ips)), 'hostId' => $match,
                ];
                $guests[] = $guest;
                if ($match !== null) {
                    $out['parents'][$match] = ['hostId' => $hid, 'kind' => $guest['type'], 'vmid' => $guest['vmid'],
                                               'name' => $guest['name'], 'status' => $guest['status']];
                }
            }
            $out['hypervisors'][$hid] = ['version' => $w['proxmox']['version'] ?? null, 'stale' => $stale, 'guests' => $guests];
        }
        if (is_array($w['containers'] ?? null)) {
            $out['docker'][$hid] = [
                'engine' => $w['containers']['engine'] ?? 'docker', 'stale' => $stale,
                'containers' => array_map(fn($c) => [
                    'name' => (string) ($c['name'] ?? ''), 'image' => (string) ($c['image'] ?? ''),
                    'state' => (string) ($c['state'] ?? ''), 'status' => (string) ($c['status'] ?? ''),
                    'health' => $c['health'] ?? null, 'ports' => (string) ($c['ports'] ?? ''),
                    'project' => $c['project'] ?? null,
                ], (array) ($w['containers']['containers'] ?? [])),
            ];
        }
    }
    return $out;
}
