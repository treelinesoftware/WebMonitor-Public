<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/portmap.php';   // snmp_walk(), normalize_mac(), snmp_ipv4()

/**
 * Wi-Fi from Aruba Instant (AOS 8 Instant / AOS 10 IAP) virtual controllers
 * over SNMP (AI-AP-MIB): every AP in the cluster with its radios, the SSIDs,
 * and every wireless client with the AP, radio and SSID it's on.
 *
 * The virtual controller answers for the whole cluster, so one card (the
 * VC's address, or any AP) is enough. Cards with device type "ap" are
 * tried; once one answers, the other APs in its list aren't asked again.
 */

const WLAN_MAX_AGE_SEC = 240;
const OID_AI_AP = '.1.3.6.1.4.1.14823.2.3.3.1.2.1.1';      // .1 mac .2 name .3 ip .4 serial .6 model .9 uptime .11 status .13 role
const OID_AI_RADIO = '.1.3.6.1.4.1.14823.2.3.3.1.2.2.1';   // .1 ap mac .2 index .3 radio mac .4 channel .5 tx power .6 noise floor .7 utilisation .21 clients
const OID_AI_WLAN = '.1.3.6.1.4.1.14823.2.3.3.1.2.3.1';    // .1 ap mac .2 index .3 essid .4 bssid
const OID_AI_CLIENT = '.1.3.6.1.4.1.14823.2.3.3.1.2.4.1';  // .1 mac .2 bssid .3 ip .4 ap ip .5 name .6 os .7 snr .11 tx rate .15 rx rate .16 uptime

/** Table walk grouped by index: [index => [col => value]]. */
function snmp_table_cols(string $ip, string $c, string $entry, array $cols, float $t): array {
    $rows = [];
    foreach ($cols as $col) {
        foreach (snmp_walk($ip, $c, "$entry.$col", $t) as $idx => $v) $rows[(string) $idx][$col] = is_string($v) ? $v : (string) $v;
    }
    return $rows;
}

/** Timeticks as seconds, from "(447133500) 51 days..." or a plain number. */
function ticks_to_sec(string $v): ?int {
    if (preg_match('/\((\d+)\)/', $v, $m) || preg_match('/^(\d+)$/', trim($v), $m)) return intdiv((int) $m[1], 100);
    return null;
}

function radio_band(string $channel): string {
    $n = (int) $channel;
    return $n >= 1 && $n <= 14 ? '2.4 GHz' : '5 GHz';
}

/** Reads an Aruba Instant cluster through $host (VC or AP address). Null if it doesn't answer. */
function collect_aruba_instant(array $host): ?array {
    $ip = $host['ip_address'];
    $c = $host['community'] !== '' ? $host['community'] : 'public';
    $t = 3.0;
    $apRows = snmp_table_cols($ip, $c, OID_AI_AP, [1, 2, 3, 4, 6, 9, 11, 13], $t);
    if (!$apRows) return null;

    $aps = [];
    foreach ($apRows as $r) {
        $mac = normalize_mac($r[1] ?? '');
        if (!$mac) continue;
        $aps[$mac] = [
            'mac' => $mac, 'name' => trim($r[2] ?? ''), 'ip' => snmp_ipv4($r[3] ?? '') ?? ($r[3] ?? null),
            'serial' => $r[4] ?? null, 'model' => $r[6] ?? null, 'uptimeSec' => ticks_to_sec($r[9] ?? ''),
            'up' => ($r[11] ?? '1') === '1', 'role' => $r[13] ?? null, 'radios' => [], 'clients' => 0,
        ];
    }

    $radioByBssidPrefix = [];   // radio MAC without its last nibble -> [ap mac, radio index]
    foreach (snmp_table_cols($ip, $c, OID_AI_RADIO, [1, 2, 3, 4, 5, 6, 7, 21], $t) as $r) {
        $ap = normalize_mac($r[1] ?? '');
        if (!$ap || !isset($aps[$ap])) continue;
        $rmac = normalize_mac($r[3] ?? '');
        $channel = trim($r[4] ?? '');
        $idx = (int) ($r[2] ?? 0);
        $aps[$ap]['radios'][$idx] = [
            'index' => $idx, 'band' => radio_band($channel), 'channel' => $channel,
            'txPower' => isset($r[5]) ? (int) $r[5] : null, 'noise' => isset($r[6]) ? -abs((int) $r[6]) : null,
            'utilization' => isset($r[7]) ? (int) $r[7] : null, 'clients' => isset($r[21]) ? (int) $r[21] : 0,
        ];
        if ($rmac) $radioByBssidPrefix[substr($rmac, 0, 16)] = [$ap, $idx];
    }

    $ssidByBssid = [];
    $ssids = [];
    foreach (snmp_table_cols($ip, $c, OID_AI_WLAN, [3, 4], $t) as $r) {
        $essid = trim($r[3] ?? '');
        if ($essid === '') continue;
        $ssids[$essid] = true;
        if ($b = normalize_mac($r[4] ?? '')) $ssidByBssid[$b] = $essid;
    }

    $clients = [];
    foreach (snmp_table_cols($ip, $c, OID_AI_CLIENT, [1, 2, 3, 4, 5, 6, 7, 11, 15, 16], $t) as $r) {
        $mac = normalize_mac($r[1] ?? '');
        if (!$mac) continue;
        $bssid = normalize_mac($r[2] ?? '');
        [$apMac, $radio] = $bssid ? ($radioByBssidPrefix[substr($bssid, 0, 16)] ?? [null, null]) : [null, null];
        if ($apMac === null) {
            $apIp = snmp_ipv4($r[4] ?? '');
            foreach ($aps as $a) if ($a['ip'] === $apIp) { $apMac = $a['mac']; break; }
        }
        if ($apMac !== null) $aps[$apMac]['clients']++;
        $cip = snmp_ipv4($r[3] ?? '');
        $clients[] = [
            'mac' => $mac, 'ip' => $cip && $cip !== '0.0.0.0' ? $cip : null, 'name' => trim($r[5] ?? '') ?: null,
            'os' => trim($r[6] ?? '') ?: null, 'ap' => $apMac, 'ssid' => $bssid ? ($ssidByBssid[$bssid] ?? null) : null,
            'band' => $apMac !== null && $radio !== null ? ($aps[$apMac]['radios'][$radio]['band'] ?? null) : null,
            'snr' => isset($r[7]) ? (int) $r[7] : null, 'txRate' => isset($r[11]) ? (int) $r[11] : null,
            'rxRate' => isset($r[15]) ? (int) $r[15] : null, 'uptimeSec' => ticks_to_sec($r[16] ?? ''),
        ];
    }
    foreach ($aps as &$a) {
        ksort($a['radios']);
        $a['radios'] = array_values($a['radios']);
    }
    unset($a);
    $aps = array_values($aps);
    usort($aps, fn($x, $y) => strnatcasecmp($x['name'], $y['name']));
    usort($clients, fn($x, $y) => strnatcasecmp($x['name'] ?? $x['ip'] ?? $x['mac'], $y['name'] ?? $y['ip'] ?? $y['mac']));
    return ['aps' => $aps, 'clients' => $clients, 'ssids' => array_keys($ssids)];
}

function save_wlan_map(int $hostId, array $data): void {
    db()->prepare('INSERT INTO wlan_maps (host_id, collected_at, data) VALUES (?, ?, ?)
                   ON DUPLICATE KEY UPDATE collected_at = VALUES(collected_at), data = VALUES(data)')
        ->execute([$hostId, (int) round(microtime(true) * 1000), json_encode($data)]);
}

/** Background refresh (cli/poll-snmp.php): one answer per cluster. */
function refresh_wlan_maps(): void {
    $pdo = db();
    $cut = (int) round(microtime(true) * 1000) - WLAN_MAX_AGE_SEC * 1000;
    $fresh = [];   // AP IPs covered by a fresh map
    foreach ($pdo->query('SELECT collected_at, data FROM wlan_maps') as $r) {
        if ((int) $r['collected_at'] < $cut) continue;
        foreach (json_decode($r['data'], true)['aps'] ?? [] as $a) $fresh[$a['ip']] = true;
    }
    // Cards that answered before first (the VC), then the rest.
    $hosts = $pdo->query("SELECT h.* FROM hosts h LEFT JOIN wlan_maps w ON w.host_id = h.id
                          WHERE h.device = 'ap' ORDER BY w.host_id IS NULL, h.id")->fetchAll();
    foreach ($hosts as $h) {
        if (isset($fresh[$h['ip_address']])) continue;
        $prev = $pdo->prepare('SELECT collected_at FROM wlan_maps WHERE host_id = ?');
        $prev->execute([$h['id']]);
        $at = $prev->fetchColumn();
        if ($at !== false && (int) $at >= $cut) continue;
        try {
            $data = collect_aruba_instant($h);
        } catch (Throwable $e) {
            $data = null;
        }
        if (!$data) continue;
        save_wlan_map((int) $h['id'], $data);
        foreach ($data['aps'] as $a) {
            $fresh[$a['ip']] = true;
            // AP cards known only by their address take the AP's name.
            $card = $pdo->prepare('SELECT id FROM hosts WHERE ip_address = ?');
            $card->execute([$a['ip']]);
            if ($cid = $card->fetchColumn()) adopt_host_name((int) $cid, $a['name']);
        }
        // Older maps of the same cluster from other cards: drop them.
        foreach ($pdo->query('SELECT host_id, data FROM wlan_maps WHERE host_id <> ' . (int) $h['id'])->fetchAll() as $o) {
            $theirs = array_column(json_decode($o['data'], true)['aps'] ?? [], 'mac');
            if (array_intersect($theirs, array_column($data['aps'], 'mac'))) {
                $pdo->prepare('DELETE FROM wlan_maps WHERE host_id = ?')->execute([$o['host_id']]);
            }
        }
    }
}

/**
 * Everything Wi-Fi in one place: [aps (by MAC, with card, controller and
 * switch port), clients (with card), controllers (host ids), collectedAt].
 */
function wireless_overview(): array {
    $pdo = db();
    $aps = [];
    $clients = [];
    $controllers = [];
    $at = null;
    $hostsByIp = [];
    foreach ($pdo->query('SELECT id, name, ip_address, device FROM hosts') as $h) $hostsByIp[$h['ip_address']] = $h;
    $card = fn($ip) => isset($hostsByIp[$ip]) ? ['id' => (int) $hostsByIp[$ip]['id'], 'name' => $hostsByIp[$ip]['name'], 'ip' => $ip] : null;

    foreach ($pdo->query('SELECT w.host_id, w.collected_at, w.data FROM wlan_maps w') as $r) {
        $d = json_decode($r['data'], true);
        $controllers[] = (int) $r['host_id'];
        $at = max($at ?? 0, (int) $r['collected_at']);
        foreach ($d['aps'] as $a) $aps[$a['mac']] = $a + ['controller' => (int) $r['host_id'], 'card' => $card($a['ip']), 'uplink' => null];
        foreach ($d['clients'] as $cl) $clients[] = $cl + ['card' => $cl['ip'] ? $card($cl['ip']) : null];
    }
    if (!$aps) return ['aps' => [], 'clients' => [], 'controllers' => [], 'collectedAt' => null];

    // Which switch port each AP is plugged into: the port that learned the AP's MAC with the fewest
    // other addresses (its clients' MACs show up there too, since Instant bridges them).
    $best = [];
    foreach ($pdo->query('SELECT m.host_id, m.data, h.name FROM port_maps m JOIN hosts h ON h.id = m.host_id') as $m) {
        foreach (json_decode($m['data'], true)['ports'] ?? [] as $p) {
            foreach ($p['macs'] as $x) {
                if (!isset($aps[$x['mac']])) continue;
                $n = count($p['macs']);
                if (!isset($best[$x['mac']]) || $n < $best[$x['mac']]['n']) {
                    $best[$x['mac']] = ['n' => $n, 'switch' => ['id' => (int) $m['host_id'], 'name' => $m['name']], 'port' => $p['name'],
                                        'speedMbps' => $p['speedMbps'] ?? null, 'description' => $p['description'] ?? ''];
                }
            }
        }
    }
    foreach ($best as $mac => $b) { unset($b['n']); $aps[$mac]['uplink'] = $b; }
    return ['aps' => $aps, 'clients' => $clients, 'controllers' => $controllers, 'collectedAt' => $at];
}

/** Per-card summaries for host_summaries(): [hostId => ['ap' => ..] | ['controller' => ..]]. */
function wireless_summaries(): array {
    $w = wireless_overview();
    if (!$w['aps']) return [];
    $out = [];
    foreach ($w['aps'] as $a) {
        if (!$a['card']) continue;
        $out[$a['card']['id']]['ap'] = [
            'name' => $a['name'], 'model' => $a['model'], 'up' => $a['up'], 'role' => $a['role'], 'clients' => $a['clients'],
            'radios' => array_map(fn($r) => ['band' => $r['band'], 'channel' => $r['channel'], 'clients' => $r['clients'], 'utilization' => $r['utilization']], $a['radios']),
            'uplink' => $a['uplink'] ? ['switch' => $a['uplink']['switch']['name'], 'switchId' => $a['uplink']['switch']['id'], 'port' => $a['uplink']['port'],
                                       'speedMbps' => $a['uplink']['speedMbps']] : null,
        ];
    }
    foreach ($w['controllers'] as $cid) {
        $mine = array_filter($w['aps'], fn($a) => $a['controller'] === $cid);
        $out[$cid]['controller'] = [
            'aps' => count($mine), 'apsUp' => count(array_filter($mine, fn($a) => $a['up'])),
            'clients' => count($w['clients']), 'ssids' => count(array_unique(array_filter(array_column($w['clients'], 'ssid')))),
        ];
    }
    return $out;
}
