<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/switchinfo.php';   // snmp_walk(), Cisco VLAN OIDs

/**
 * Switch port map: every physical port with its state, speed, VLAN, and
 * what's plugged into it.
 *
 *   - Neighbours (other switches, APs, phones) from CDP and LLDP.
 *   - Devices from the MAC address table (BRIDGE-MIB; per VLAN on Cisco,
 *     where each VLAN is its own SNMP context, "community@vlan").
 *   - MAC -> IP from the ARP tables of every switch that routes (stored
 *     with each map), this server's ARP cache and agent-reported MACs; IP
 *     -> the WebMonitor card.
 *
 * A port with a switch neighbour, trunking, or many MACs is an uplink: its
 * MACs are counted, not listed (they're devices further away).
 */

const PORTMAP_MAX_AGE_SEC = 900;      // background refresh
const PORTMAP_UPLINK_MACS = 20;       // more MACs than this on a port with no neighbour info: probably another switch

const OID_IF_TYPE = '.1.3.6.1.2.1.2.2.1.3';
const OID_IF_ADMIN = '.1.3.6.1.2.1.2.2.1.7';
const OID_IF_ALIAS = '.1.3.6.1.2.1.31.1.1.1.18';
const OID_IF_HIGH_SPEED = '.1.3.6.1.2.1.31.1.1.1.15';
const OID_BRIDGE_PORT_IFINDEX = '.1.3.6.1.2.1.17.1.4.1.2';
const OID_FDB_ADDRESS = '.1.3.6.1.2.1.17.4.3.1.1';
const OID_FDB_PORT = '.1.3.6.1.2.1.17.4.3.1.2';
const OID_LAG_MEMBER = '.1.2.840.10006.300.43.1.2.1.1.13';      // dot3adAggPortAttachedAggID: member ifIndex -> port-channel ifIndex
const OID_FDB_STATUS = '.1.3.6.1.2.1.17.4.3.1.3';
const OID_Q_PVID = '.1.3.6.1.2.1.17.7.1.4.5.1.1';
const OID_ARP = '.1.3.6.1.2.1.4.22.1.2';
const OID_IF_PHYS = '.1.3.6.1.2.1.2.2.1.6';
const OID_IP_ADDR_IF = '.1.3.6.1.2.1.4.20.1.2';
const OID_IP_ADDR_MASK = '.1.3.6.1.2.1.4.20.1.3';
const OID_CISCO_TRUNK = '.1.3.6.1.4.1.9.9.46.1.6.1.1.14';      // vlanTrunkPortDynamicStatus: 1 trunking
const OID_CISCO_VTP_STATE = '.1.3.6.1.4.1.9.9.46.1.3.1.1.2';    // vtpVlanState: 1 operational
const OID_CDP = '.1.3.6.1.4.1.9.9.23.1.2.1.1';                   // .4 address .6 device id .7 port .8 platform
const OID_LLDP_REM = '.1.0.8802.1.1.2.1.4.1.1';                  // .5 chassis .7 port id .8 port desc .9 sys name
const OID_LLDP_LOC_PORT_ID = '.1.0.8802.1.1.2.1.3.7.1.3';

/** "aa:bb:cc:dd:ee:ff" from raw bytes, hex strings or dotted decimals. */
function normalize_mac(string $v): ?string {
    if (strlen($v) === 6 && !ctype_print($v)) return implode(':', str_split(bin2hex($v), 2));
    if (preg_match('/^([0-9a-f]{1,2}[:-]){5}[0-9a-f]{1,2}$/i', trim($v))) {   // "0:10:f3:87:7c:cb"
        return strtolower(implode(':', array_map(fn($o) => str_pad($o, 2, '0', STR_PAD_LEFT), preg_split('/[:-]/', trim($v)))));
    }
    $hex = strtolower(preg_replace('/[^0-9a-fA-F]/', '', preg_replace('/^(Hex-)?STRING:\s*/i', '', $v)));
    if (strlen($hex) === 12) return implode(':', str_split($hex, 2));
    $parts = explode('.', $v);
    if (count($parts) === 6 && array_filter($parts, 'ctype_digit') === $parts) {
        return implode(':', array_map(fn($n) => sprintf('%02x', (int) $n), $parts));
    }
    return null;
}

/** IPv4 from raw 4 bytes or a hex string ("0A 0D 5A 0B"). */
function snmp_ipv4(string $v): ?string {
    if (strlen($v) === 4 && !ctype_print($v)) return inet_ntop($v);
    if (filter_var($v, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) return $v;
    $hex = preg_replace('/[^0-9a-fA-F]/', '', preg_replace('/^(Hex-)?STRING:\s*/i', '', $v));
    return strlen($hex) === 8 ? long2ip((int) hexdec($hex)) : null;
}

/** [suffix => [col => value]] for a table walked at TABLE.entry. */
function snmp_columns(string $ip, string $community, string $entry, array $cols, float $t): array {
    $rows = [];
    foreach ($cols as $c) {
        foreach (snmp_walk($ip, $community, "$entry.$c", $t) as $suffix => $v) $rows[$suffix][$c] = (string) $v;
    }
    return $rows;
}

/** Is this interface a front-panel port (not a VLAN interface, loopback, tunnel, stack port, ...)? */
function is_physical_port(int $type, string $name): bool {
    if ($type !== 6 && $type !== 117 && $type !== 62) return false;   // ethernetCsmacd, gigabitEthernet, fastEther
    return !preg_match('/^(Vl|Vlan|Lo|Nu|Tu|Ap|Gi0\/0$|Bluetooth|StackPort|StackSub|mgmt|internal|NVE)/i', $name);
}

/**
 * Collects the raw port map of a switch over SNMP (a few seconds; the MAC
 * table is walked once per VLAN on Cisco).
 */
function collect_port_map(array $host): array {
    $ip = $host['ip_address'];
    $c = $host['community'] !== '' ? $host['community'] : 'public';
    $t = 3.0;

    $names = snmp_walk($ip, $c, OID_IF_NAME, $t) ?: snmp_walk($ip, $c, OID_IF_DESCR, $t);
    if (!$names) throw new RuntimeException('the switch did not answer SNMP (check the community in Edit)');
    $types = snmp_walk($ip, $c, OID_IF_TYPE, $t);
    $oper = snmp_walk($ip, $c, OID_IF_OPER_STATUS, $t);
    $admin = snmp_walk($ip, $c, OID_IF_ADMIN, $t);
    $alias = snmp_walk($ip, $c, OID_IF_ALIAS, $t);
    $speed = snmp_walk($ip, $c, OID_IF_HIGH_SPEED, $t);
    $vmVlan = snmp_walk($ip, $c, OID_CISCO_VLAN_PORT, $t);
    $physAddr = snmp_walk($ip, $c, OID_IF_PHYS, $t);
    $trunk = snmp_walk($ip, $c, OID_CISCO_TRUNK, $t);
    $isCisco = (bool) $vmVlan || (bool) $trunk;

    $ports = [];
    foreach ($names as $idx => $name) {
        $name = (string) $name;
        if (!is_physical_port((int) ($types[$idx] ?? 0), $name)) continue;
        $o = (string) ($oper[$idx] ?? '');
        $ports[(int) $idx] = [
            'ifIndex' => (int) $idx, 'name' => $name,
            'description' => trim((string) ($alias[$idx] ?? '')),
            'status' => (string) ($admin[$idx] ?? '') === '2' ? 'disabled' : ($o === '1' ? 'up' : ($o === '2' || $o === '7' ? 'down' : 'other')),
            'speedMbps' => isset($speed[$idx]) && (int) $speed[$idx] > 0 ? (int) $speed[$idx] : null,
            'vlan' => isset($vmVlan[$idx]) && is_numeric($vmVlan[$idx]) ? (int) $vmVlan[$idx] : null,
            'trunk' => (string) ($trunk[$idx] ?? '') === '1',
            'lag' => null, 'macs' => [], 'neighbors' => [],
            'mac' => normalize_mac((string) ($physAddr[$idx] ?? '')), 'ips' => [], 'subs' => [],
        ];
    }

    // Routers and firewalls: VLAN sub-interfaces ("ix0.10", "Gi0/1.20") belong to their port.
    $parentOf = [];
    $nameToIdx = array_flip(array_map(fn($p) => $p['name'], $ports));
    foreach ($names as $idx => $name) {
        if (isset($ports[(int) $idx]) || !preg_match('/^(.+)\.(\d{1,4})$/', (string) $name, $m) || !isset($nameToIdx[$m[1]])) continue;
        $parentOf[(int) $idx] = [$nameToIdx[$m[1]], (int) $m[2]];
        $ports[$nameToIdx[$m[1]]]['subs'][(int) $idx] = ['name' => (string) $name, 'vlan' => (int) $m[2], 'ips' => []];
    }
    // Interface addresses ("192.168.10.254/24"), on the port or its sub-interface.
    $masks = snmp_walk($ip, $c, OID_IP_ADDR_MASK, $t);
    foreach (snmp_walk($ip, $c, OID_IP_ADDR_IF, $t) as $addr => $ifi) {
        $addr = (string) $addr;
        if (!filter_var($addr, FILTER_VALIDATE_IP) || str_starts_with($addr, '127.') || $addr === '0.0.0.0') continue;
        $mask = (string) ($masks[$addr] ?? '');
        $cidr = $addr . (filter_var($mask, FILTER_VALIDATE_IP) ? '/' . substr_count(decbin(ip2long($mask)), '1') : '');
        $ifi = (int) $ifi;
        if (isset($ports[$ifi])) $ports[$ifi]['ips'][] = $cidr;
        elseif (isset($parentOf[$ifi])) $ports[$parentOf[$ifi][0]]['subs'][$ifi]['ips'][] = $cidr;
    }

    // Port-channels: MACs are learned on the channel, so show them on its member ports.
    $lagMembers = [];
    foreach (snmp_walk($ip, $c, OID_LAG_MEMBER, $t) as $member => $agg) {
        if ((int) $agg > 0 && isset($ports[(int) $member])) {
            $lagMembers[(int) $agg][] = (int) $member;
            $ports[(int) $member]['lag'] = (string) ($names[(int) $agg] ?? "LAG $agg");
        }
    }

    // MAC address table, per VLAN context on Cisco.
    $contexts = [''];
    if ($isCisco) {
        $contexts = [];
        foreach (snmp_walk($ip, $c, OID_CISCO_VTP_STATE, $t) as $suffix => $state) {
            $vlan = (int) substr((string) $suffix, strrpos((string) $suffix, '.') + 1);
            if ((string) $state === '1' && $vlan >= 1 && ($vlan < 1002 || $vlan > 1005)) $contexts[] = (string) $vlan;
        }
    }
    $pvidByBridgePort = snmp_walk($ip, $c, OID_Q_PVID, $t);
    foreach ($contexts as $vlan) {
        $comm = $vlan === '' ? $c : "$c@$vlan";
        $bpIf = snmp_walk($ip, $comm, OID_BRIDGE_PORT_IFINDEX, $t);
        if ($vlan === '') {
            foreach ($pvidByBridgePort as $bp => $pvid) {
                $if = (int) ($bpIf[$bp] ?? 0);
                if (isset($ports[$if]) && $ports[$if]['vlan'] === null && is_numeric($pvid)) $ports[$if]['vlan'] = (int) $pvid;
            }
        }
        $status = snmp_walk($ip, $comm, OID_FDB_STATUS, $t);
        // The table is normally indexed by the MAC itself; some switches (FortiSwitch)
        // number the rows instead, so the address column is read too.
        $addr = snmp_walk($ip, $comm, OID_FDB_ADDRESS, $t);
        foreach (snmp_walk($ip, $comm, OID_FDB_PORT, $t) as $key => $bp) {
            if (isset($status[$key]) && (string) $status[$key] !== '3') continue;   // only learned entries
            $if = (int) ($bpIf[(string) $bp] ?? $bp);
            $mac = isset($addr[$key]) ? normalize_mac((string) $addr[$key]) : null;
            $mac ??= normalize_mac((string) $key);
            if (!$mac) continue;
            $v = $vlan !== '' ? (int) $vlan : null;
            foreach (isset($lagMembers[$if]) ? $lagMembers[$if] : [$if] as $pif) {
                if (isset($ports[$pif])) $ports[$pif]['macs'][$mac] = $v ?? $ports[$pif]['vlan'];
            }
        }
    }

    // Neighbours: CDP (Cisco) and LLDP.
    foreach (snmp_columns($ip, $c, OID_CDP, [4, 6, 7, 8], $t) as $suffix => $r) {
        $if = (int) explode('.', (string) $suffix)[0];
        if (!isset($ports[$if]) || empty($r[6])) continue;
        $ports[$if]['neighbors'][] = ['via' => 'CDP', 'name' => $r[6], 'port' => short_port_name($r[7] ?? ''), 'platform' => $r[8] ?? '',
                                     'ip' => isset($r[4]) ? snmp_ipv4($r[4]) : null];
    }
    $locPort = snmp_walk($ip, $c, OID_LLDP_LOC_PORT_ID, $t);
    $nameToIf = array_flip(array_map(fn($p) => $p['name'], $ports));
    foreach (snmp_columns($ip, $c, OID_LLDP_REM, [5, 7, 8, 9], $t) as $suffix => $r) {
        $parts = explode('.', (string) $suffix);   // timeMark.localPortNum.index
        $local = (int) ($parts[1] ?? 0);
        $locId = (string) ($locPort[$local] ?? '');
        $if = $nameToIf[$locId] ?? (isset($ports[$local]) ? $local : null);
        if ($if === null) continue;
        $sys = trim($r[9] ?? '');
        $chassis = normalize_mac($r[5] ?? '');
        if ($sys === '' && !$chassis) continue;
        $ports[$if]['neighbors'][] = ['via' => 'LLDP', 'name' => $sys !== '' ? $sys : $chassis, 'port' => trim($r[8] ?? '') ?: trim($r[7] ?? ''),
                                     'platform' => '', 'ip' => null, 'mac' => $chassis];
    }

    // ARP table: MAC -> IP for every map (the switch may route for other VLANs). On a port
    // or sub-interface (routers, firewalls) it's also what's connected there.
    $arp = [];
    foreach (snmp_walk($ip, $c, OID_ARP, $t) as $suffix => $v) {
        $parts = explode('.', (string) $suffix);
        $addr = implode('.', array_slice($parts, -4));
        $mac = normalize_mac((string) $v);
        if (!$mac || !filter_var($addr, FILTER_VALIDATE_IP)) continue;
        $arp[$mac] = $addr;
        $ifi = (int) $parts[0];
        [$pif, $vlan] = isset($ports[$ifi]) ? [$ifi, $ports[$ifi]['vlan']] : ($parentOf[$ifi] ?? [null, null]);
        if ($pif !== null && !isset($ports[$pif]['macs'][$mac])) $ports[$pif]['macs'][$mac] = $vlan;
    }

    foreach ($ports as &$p) {
        $p['subs'] = array_values($p['subs']);
        ksort($p['macs']);
        $p['macs'] = array_map(fn($m, $v) => ['mac' => $m, 'vlan' => $v], array_keys($p['macs']), $p['macs']);
    }
    unset($p);
    return ['ports' => array_values($ports), 'arp' => $arp, 'vlans' => get_switch_vlans($ip, $c, $t)];
}

/** "TenGigabitEthernet1/0/1" -> "Te1/0/1", like the switch's own ifName. */
function short_port_name(string $name): string {
    return preg_replace(['/^HundredGigE/i', '/^FortyGigabitEthernet/i', '/^TwentyFiveGigE/i', '/^TenGigabitEthernet/i',
        '/^GigabitEthernet/i', '/^FastEthernet/i', '/^Port-channel/i'], ['Hu', 'Fo', 'Twe', 'Te', 'Gi', 'Fa', 'Po'], $name);
}

function save_port_map(int $hostId, array $map): void {
    db()->prepare('INSERT INTO port_maps (host_id, collected_at, data) VALUES (?, ?, ?)
                   ON DUPLICATE KEY UPDATE collected_at = VALUES(collected_at), data = VALUES(data)')
        ->execute([$hostId, (int) round(microtime(true) * 1000), json_encode($map)]);
}

/** MAC -> IP from every stored switch ARP table, this server's ARP cache and agents. */
function known_mac_ips(): array {
    $pdo = db();
    // Switch ARP tables, oldest first, so the newest answer wins; and an IP that a newer table
    // gives to a different MAC is no longer that old MAC's (e.g. after fixing an IP conflict).
    $map = [];
    $ipOwner = [];
    foreach ($pdo->query('SELECT data FROM port_maps ORDER BY collected_at') as $r) {
        foreach ((json_decode($r['data'], true)['arp'] ?? []) as $mac => $ip) {
            $map[$mac] = $ip;
            $ipOwner[$ip] = $mac;
        }
    }
    foreach ($map as $mac => $ip) if ($ipOwner[$ip] !== $mac) unset($map[$mac]);
    foreach (json_decode((string) $pdo->query("SELECT v FROM settings WHERE k = 'arp_cache'")->fetchColumn(), true) ?: [] as $mac => $ip) {
        $map[strtolower($mac)] = $ip;
    }
    foreach ($pdo->query('SELECT a.metrics, h.ip_address FROM agents a JOIN hosts h ON h.id = a.host_id WHERE a.metrics IS NOT NULL') as $r) {
        foreach ((json_decode($r['metrics'], true)['network'] ?? []) as $if) {
            $mac = normalize_mac((string) ($if['mac'] ?? ''));
            if ($mac && !isset($map[$mac])) $map[$mac] = $r['ip_address'];
        }
    }
    return $map;
}

/**
 * The stored map with names filled in: each MAC gets its IP and WebMonitor
 * card, each neighbour its card, and each port a role (uplink/device/empty).
 */
function port_map_view(int $hostId): ?array {
    $pdo = db();
    $stmt = $pdo->prepare('SELECT collected_at, data FROM port_maps WHERE host_id = ?');
    $stmt->execute([$hostId]);
    $row = $stmt->fetch();
    if (!$row) return null;
    $map = json_decode($row['data'], true);

    $macIp = known_mac_ips();
    $hostsByIp = [];
    $hostsByName = [];
    foreach ($pdo->query('SELECT id, name, ip_address, device FROM hosts') as $h) {
        $hostsByIp[$h['ip_address']] = $h;
        $hostsByName[strtolower(explode('.', $h['name'])[0])] ??= $h;
    }
    $card = fn(?array $h) => $h ? ['id' => (int) $h['id'], 'name' => $h['name'], 'ip' => $h['ip_address'], 'device' => $h['device']] : null;

    // APs' real MACs (from the Wi-Fi controller): another MAC answering on an AP's IP is a
    // different device using the same address, not the AP.
    $apMacByIp = [];
    foreach ($pdo->query('SELECT data FROM wlan_maps') as $w) {
        foreach (json_decode($w['data'], true)['aps'] ?? [] as $a) if ($a['ip']) $apMacByIp[$a['ip']] = $a['mac'];
    }

    // This device's own addresses (its ARP table lists them too).
    $ownIps = [];
    foreach ($map['ports'] as $op) foreach (array_merge($op['ips'] ?? [], ...array_map(fn($x) => $x['ips'], $op['subs'] ?? [])) as $cidr) {
        $ownIps[explode('/', $cidr)[0]] = true;
    }
    $self = $pdo->prepare('SELECT ip_address FROM hosts WHERE id = ?');
    $self->execute([$hostId]);
    $ownIps[(string) $self->fetchColumn()] = true;

    // Where each of this device's own ports is plugged in: a port on another switch that
    // learned the port's MAC directly (not through an uplink full of other devices).
    $pluggedInto = [];
    $seesMe = [];   // other devices with any of this device's MACs on one of their ports
    $allOwn = array_flip(array_filter(array_column($map['ports'], 'mac')));
    $ownMacs = array_count_values(array_filter(array_column(array_filter($map['ports'], fn($p) => $p['status'] === 'up'), 'mac')));
    if ($allOwn) {
        $others = $pdo->prepare('SELECT m.host_id, m.data, h.name, h.ip_address, h.device FROM port_maps m JOIN hosts h ON h.id = m.host_id WHERE m.host_id <> ?');
        $others->execute([$hostId]);
        foreach ($others as $o) {
            foreach (json_decode($o['data'], true)['ports'] ?? [] as $op) {
                foreach ($op['macs'] as $m) if (isset($allOwn[$m['mac']])) $seesMe[(int) $o['host_id']] = true;
                if (count($op['macs']) > 3 || ($op['trunk'] ?? false) && count($op['macs']) > 1) continue;
                foreach ($op['macs'] as $m) {
                    if (($ownMacs[$m['mac']] ?? 0) !== 1) continue;   // shared by several ports: can't tell which
                    $key = strtolower(explode('.', $o['name'])[0]) . '|' . $op['name'];   // one switch, several addresses
                    $pluggedInto[$m['mac']][$key] = ['host' => $card(['id' => $o['host_id'], 'name' => $o['name'], 'ip_address' => $o['ip_address'], 'device' => $o['device']]),
                                                     'port' => $op['name'], 'description' => $op['description']];
                }
            }
        }
    }

    foreach ($map['ports'] as &$p) {
        foreach ($p['neighbors'] as &$n) {
            $n['port'] = short_port_name((string) $n['port']);
            $h = ($n['ip'] ?? null) ? ($hostsByIp[$n['ip']] ?? null) : null;
            $h ??= $hostsByName[strtolower(explode('.', (string) $n['name'])[0])] ?? null;
            $n['host'] = $card($h);
        }
        unset($n);
        $p['pluggedInto'] = $p['mac'] && $p['status'] === 'up' ? array_values($pluggedInto[$p['mac']] ?? []) : [];
        $p['macCount'] = count($p['macs']);
        // Uplink: a switch on the other end (CDP/LLDP), or so many MACs it must be one.
        $switchNeighbor = array_filter($p['neighbors'], fn($n) => in_array($n['host']['device'] ?? '', ['switch', 'router'], true)
            || preg_match('/switch|catalyst|fortiswitch|C2960|C3850|C9[23]00|WS-C|FS-\d/i', $n['platform'] . ' ' . $n['name']));
        $intoSwitch = array_filter($p['pluggedInto'], fn($x) => in_array($x['host']['device'], ['switch', 'router'], true));
        $uplink = $switchNeighbor || $intoSwitch || ($p['macCount'] > PORTMAP_UPLINK_MACS && !$p['neighbors']);
        $p['role'] = $p['status'] !== 'up' ? 'empty' : ($uplink ? 'uplink' : ($p['macCount'] || $p['neighbors'] ? 'device' : 'unknown'));
        $devices = [];
        foreach ($p['macs'] as $m) {
            $ip = $macIp[$m['mac']] ?? null;
            if ($m['mac'] === $p['mac'] || ($ip !== null && isset($ownIps[$ip]))) continue;   // the device itself
            $conflict = $ip !== null && isset($apMacByIp[$ip]) && $apMacByIp[$ip] !== $m['mac'];
            $devices[] = ['mac' => $m['mac'], 'vlan' => $m['vlan'], 'ip' => $ip, 'host' => $conflict ? null : $card($ip ? ($hostsByIp[$ip] ?? null) : null),
                          'note' => $conflict ? 'IP conflict: ' . ($hostsByIp[$ip]['name'] ?? 'an AP') . ' also uses ' . $ip : null];
        }
        // Known cards first, then by IP.
        usort($devices, fn($a, $b) => [$a['host'] === null, $a['ip'] === null, ip2long($a['ip'] ?? '0.0.0.0')]
                                   <=> [$b['host'] === null, $b['ip'] === null, ip2long($b['ip'] ?? '0.0.0.0')]);
        $p['devices'] = $devices;
        if ($p['role'] === 'device' && !$devices && !$p['neighbors'] && !$p['pluggedInto']) $p['role'] = 'unknown';
        // A port that leads to another monitored switch is an uplink to it, even when
        // CDP/LLDP doesn't say so (e.g. Cisco to FortiSwitch).
        if ($p['status'] === 'up' && !$switchNeighbor) {
            $sw = array_values(array_filter($devices, fn($d) => in_array($d['host']['device'] ?? '', ['switch', 'router'], true)
                && $d['host']['id'] !== $hostId));
            if ($sw) {
                // Several switches can sit beyond one port: the next one is the one that also
                // sees this device on one of its ports.
                $next = array_values(array_filter($sw, fn($d) => isset($seesMe[$d['host']['id']])));
                $p['role'] = 'uplink';
                $p['towards'] = array_map(fn($d) => $d['host'], $next ?: [$sw[0]]);
            }
        }
        unset($p['macs']);
    }
    unset($p);
    return ['collectedAt' => (int) $row['collected_at'], 'ports' => $map['ports'], 'vlans' => (object) ($map['vlans'] ?? [])];
}

/** Background refresh of switch port maps (cli/poll-snmp.php). */
function refresh_port_maps(): void {
    $pdo = db();
    $due = $pdo->prepare("SELECT h.* FROM hosts h LEFT JOIN port_maps m ON m.host_id = h.id
                          WHERE h.device IN ('switch', 'router') AND (m.collected_at IS NULL OR m.collected_at < ?)");
    $due->execute([(int) round(microtime(true) * 1000) - PORTMAP_MAX_AGE_SEC * 1000]);
    $any = false;
    foreach ($due->fetchAll() as $h) {
        try {
            save_port_map((int) $h['id'], collect_port_map($h));
            $any = true;
        } catch (Throwable $e) {
            // Unreachable or no SNMP: try again next time.
        }
    }
    if ($any) update_port_summaries();
}

// ---- Card strip ----

/** Role codes for the card strip: d device, n link up/nothing seen, u uplink, e free, x disabled. */
const PORT_ROLE_CODES = ['device' => 'd', 'unknown' => 'n', 'uplink' => 'u', 'empty' => 'e'];

/**
 * Recomputes the compact port strips shown on switch cards. Roles depend on
 * the other switches' maps (uplinks, "plugged into"), so all are redone
 * together after a refresh.
 */
function update_port_summaries(): void {
    $pdo = db();
    $save = $pdo->prepare('UPDATE port_maps SET summary = ? WHERE host_id = ?');
    foreach ($pdo->query('SELECT host_id FROM port_maps')->fetchAll(PDO::FETCH_COLUMN) as $id) {
        $v = port_map_view((int) $id);
        if (!$v) continue;
        $strip = [];
        foreach ($v['ports'] as $p) {
            $code = $p['status'] === 'disabled' ? 'x' : (PORT_ROLE_CODES[$p['role']] ?? 'e');
            $strip[] = [$p['name'], $code, count($p['devices'])];
        }
        $save->execute([json_encode(['at' => $v['collectedAt'], 'ports' => $strip]), $id]);
    }
}

/** [hostId => strip] for host summaries. */
function port_summaries(): array {
    $out = [];
    foreach (db()->query('SELECT host_id, summary FROM port_maps WHERE summary IS NOT NULL') as $r) {
        $out[(int) $r['host_id']] = json_decode($r['summary'], true);
    }
    return $out;
}

// ---- Network topology report ----

/**
 * The whole network as a tree: network devices (switches, routers) joined
 * by the links their port maps show, and under each one what's plugged into
 * its ports, with Proxmox guests under their host.
 *
 * Several cards for one switch (e.g. one per VLAN address) are merged: a
 * switch is identified by its ports' MAC addresses.
 */
function network_topology(): array {
    require_once __DIR__ . '/topology.php';
    $pdo = db();
    $hosts = [];
    foreach ($pdo->query('SELECT id, name, ip_address, device FROM hosts') as $h) $hosts[(int) $h['id']] = $h;
    $withBackup = array_flip(array_map('intval', $pdo->query('SELECT host_id FROM config_backup_settings')->fetchAll(PDO::FETCH_COLUMN)));
    $vendors = function_exists('vendor_summaries') ? vendor_summaries() : [];

    // One entry per physical device.
    $views = [];
    $groups = [];
    foreach ($pdo->query('SELECT host_id FROM port_maps')->fetchAll(PDO::FETCH_COLUMN) as $id) {
        $id = (int) $id;
        if (!isset($hosts[$id]) || !($v = port_map_view($id))) continue;
        $views[$id] = $v;
        $macs = array_values(array_unique(array_filter(array_column($v['ports'], 'mac'))));
        sort($macs);
        $groups[$macs ? md5(implode(',', $macs)) : "host$id"][] = $id;
    }
    $canon = [];
    $nodes = [];
    foreach ($groups as $ids) {
        // The card with a configuration backup, else the oldest card.
        usort($ids, fn($a, $b) => [!isset($withBackup[$a]), $a] <=> [!isset($withBackup[$b]), $b]);
        $main = $ids[0];
        foreach ($ids as $id) $canon[$id] = $main;
        $h = $hosts[$main];
        $ports = $views[$main]['ports'];
        $nodes[$main] = [
            'id' => $main, 'name' => $h['name'], 'ip' => $h['ip_address'], 'device' => $h['device'], 'vendor' => $vendors[$main] ?? null,
            'model' => null, 'aliases' => array_values(array_map(fn($i) => $hosts[$i]['ip_address'], array_slice($ids, 1))),
            'portsTotal' => count($ports), 'portsUp' => count(array_filter($ports, fn($p) => $p['status'] === 'up')),
            'collectedAt' => $views[$main]['collectedAt'], 'ports' => [],
        ];
    }

    // Links between network devices, with the port at each end when known.
    $links = [];
    $addLink = function (int $a, ?string $pa, int $b, ?string $pb) use (&$links) {
        if ($a === $b) return;
        $key = min($a, $b) . '-' . max($a, $b);
        $links[$key] ??= ['a' => min($a, $b), 'b' => max($a, $b), 'ports' => []];
        if ($pa !== null) $links[$key]['ports'][$a] ??= $pa;
        if ($pb !== null) $links[$key]['ports'][$b] ??= $pb;
    };
    foreach ($nodes as $nid => &$node) {
        foreach ($views[$nid]['ports'] as $p) {
            if ($p['status'] !== 'up') continue;
            $local = $p['lag'] ? "{$p['lag']}" : $p['name'];
            foreach ($p['neighbors'] as $n) {
                if (($hid = $n['host']['id'] ?? null) && isset($canon[$hid])) {
                    $addLink($nid, $local, $canon[$hid], $n['port'] ?: null);
                    if ($n['platform'] !== '') $nodes[$canon[$hid]]['model'] ??= $n['platform'];
                }
            }
            foreach ($p['pluggedInto'] as $x) {
                if (isset($canon[$x['host']['id']])) $addLink($nid, $local, $canon[$x['host']['id']], $x['port']);
            }
            foreach ($p['towards'] ?? [] as $t) {
                if (isset($canon[$t['id']])) $addLink($nid, $local, $canon[$t['id']], null);
            }
        }
    }
    unset($node);

    // What's plugged into each device's ports (uplinks excluded: those devices are further away).
    $topo = build_topology();
    $placed = [];   // host id -> true, so a device shows once
    require_once __DIR__ . '/wireless.php';
    $wifi = wireless_overview();
    $wifiClientMacs = array_flip(array_column($wifi['clients'], 'mac'));
    $clientsByAp = [];
    foreach ($wifi['clients'] as $cl) if ($cl['ap']) $clientsByAp[$cl['ap']][] = $cl;
    // APs with a card whose switch port is known get their own box under that switch.
    $apNodes = [];
    foreach ($wifi['aps'] as $mac => $ap) {
        if ($ap['card'] && $ap['uplink'] && isset($canon[$ap['uplink']['switch']['id']]) && !isset($nodes[$ap['card']['id']])) $apNodes[$mac] = $ap;
    }
    $isGuest = fn(?int $id) => $id !== null && isset($topo['parents'][$id]);
    foreach ($nodes as $nid => &$node) {
        $byPort = [];
        foreach ($views[$nid]['ports'] as $p) {
            if ($p['status'] !== 'up' || $p['role'] === 'uplink') continue;
            $portName = $p['lag'] ?: $p['name'];
            foreach ($p['devices'] as $d) {
                $hid = $d['host']['id'] ?? null;
                if (isset($wifiClientMacs[$d['mac']]) || isset($apNodes[$d['mac']])) continue;   // shown under / as its AP
                if ($hid !== null && (isset($canon[$hid]) || isset($placed[$hid]) || $isGuest($hid))) continue;
                if ($hid !== null) $placed[$hid] = true;
                $key = $hid !== null ? "h$hid" : $d['mac'];
                $byPort[$portName]['port'] = $portName;
                $byPort[$portName]['members'] = $p['lag'] ? array_values(array_unique(array_merge($byPort[$portName]['members'] ?? [], [$p['name']]))) : [$p['name']];
                $byPort[$portName]['description'] = $p['description'] !== $p['name'] ? $p['description'] : '';
                $byPort[$portName]['vlan'] = $p['trunk'] ? 'trunk' : $p['vlan'];
                $entry = ['host' => $d['host'], 'ip' => $d['ip'], 'mac' => $d['mac'], 'vlan' => $d['vlan'], 'note' => $d['note'] ?? null];
                if ($hid !== null && isset($topo['hypervisors'][$hid])) {
                    $entry['guests'] = array_map(fn($g) => ['name' => $g['name'], 'type' => $g['type'], 'vmid' => $g['vmid'],
                        'status' => $g['status'], 'hostId' => $g['hostId'], 'ip' => $g['ips'][0] ?? null], $topo['hypervisors'][$hid]['guests']);
                }
                if ($hid !== null && isset($topo['docker'][$hid])) $entry['containers'] = count($topo['docker'][$hid]['containers']);
                if (isset($wifi['aps'][$d['mac']])) {
                    $ap = $wifi['aps'][$d['mac']];
                    $entry['ap'] = ['name' => $ap['name'], 'radios' => array_map(fn($r) => "{$r['band']} ch{$r['channel']}", $ap['radios'])];
                    $entry['wifiClients'] = array_map(fn($c) => ['name' => (isset($c['card']['name']) && $c['card']['name'] !== $c['ip'] ? $c['card']['name'] : null) ?? $c['name'] ?? $c['ip'] ?? $c['mac'],
                        'hostId' => $c['card']['id'] ?? null, 'ip' => $c['ip'], 'mac' => $c['mac'], 'ssid' => $c['ssid'], 'band' => $c['band']],
                        $clientsByAp[$d['mac']] ?? []);
                    if ($entry['host'] === null) $entry['ip'] ??= $ap['ip'];
                    foreach ($clientsByAp[$d['mac']] ?? [] as $c) if ($c['card']) $placed[$c['card']['id']] = true;
                }
                $byPort[$portName]['devices'][$key] = $entry;
            }
        }
        foreach ($byPort as &$bp) $bp['devices'] = array_values($bp['devices']);
        unset($bp);
        uksort($byPort, 'strnatcasecmp');
        $node['ports'] = array_values($byPort);
    }
    unset($node);

    // Access points: a box each, linked to their switch port, with their clients by SSID.
    foreach ($apNodes as $mac => $ap) {
        $id = $ap['card']['id'];
        $placed[$id] = true;
        $bySsid = [];
        foreach ($clientsByAp[$mac] ?? [] as $c) {
            $ssid = $c['ssid'] ?? '(unknown SSID)';
            $bySsid[$ssid]['port'] = $ssid;
            $bySsid[$ssid]['members'] = [$ssid];
            $bySsid[$ssid]['description'] = '';
            $bySsid[$ssid]['vlan'] = null;
            $bySsid[$ssid]['ssid'] = true;
            $bySsid[$ssid]['devices'][] = ['host' => $c['card'], 'ip' => $c['ip'], 'mac' => $c['mac'], 'vlan' => null,
                                           'name' => $c['name'], 'band' => $c['band']];
            if ($c['card']) $placed[$c['card']['id']] = true;
        }
        uksort($bySsid, 'strnatcasecmp');
        $nodes[$id] = [
            'id' => $id, 'name' => $ap['card']['name'], 'ip' => $ap['ip'], 'mac' => $mac, 'device' => 'ap', 'vendor' => 'aruba',
            'model' => $ap['model'] ? "Aruba {$ap['model']}" . ($ap['role'] ? " · {$ap['role']}" : '') : null, 'aliases' => [],
            'portsTotal' => 1, 'portsUp' => 1, 'collectedAt' => $wifi['collectedAt'], 'ports' => array_values($bySsid),
            'radios' => array_map(fn($r) => ['band' => $r['band'], 'channel' => $r['channel'], 'clients' => $r['clients'], 'utilization' => $r['utilization']], $ap['radios']),
            'wifiClients' => count($clientsByAp[$mac] ?? []),
        ];
        $canon[$id] = $id;
        $addLink($canon[$ap['uplink']['switch']['id']], $ap['uplink']['port'], $id, 'eth0');
    }

    // Tree: from the router if it's linked, else the best-connected switch.
    $adj = [];
    foreach ($links as $l) { $adj[$l['a']][] = $l['b']; $adj[$l['b']][] = $l['a']; }
    $candidates = array_keys($nodes);
    // A card typed Router that CDP/LLDP says is a switch model (e.g. WS-C2960X) isn't the edge router.
    $isRouter = fn($n) => $n['device'] === 'router' && !preg_match('/WS-C|C9[23]\d{2}|catalyst|FS-\d|switch/i', (string) $n['model']);
    usort($candidates, fn($a, $b) => [!$isRouter($nodes[$a]) || empty($adj[$a]), -count($adj[$a] ?? []), $a]
                                   <=> [!$isRouter($nodes[$b]) || empty($adj[$b]), -count($adj[$b] ?? []), $b]);
    $seen = [];
    $build = function (int $id, ?int $from) use (&$build, &$seen, $adj, $links, $nodes) {
        $seen[$id] = true;
        $children = [];
        foreach ($adj[$id] ?? [] as $next) {
            if (isset($seen[$next])) continue;
            $seen[$next] = true;
            $l = $links[min($id, $next) . '-' . max($id, $next)];
            $child = $build($next, $id);
            $child['link'] = ['parentPort' => $l['ports'][$id] ?? null, 'childPort' => $l['ports'][$next] ?? null];
            $children[] = $child;
        }
        return ['id' => $id, 'children' => $children];
    };
    $roots = [];
    foreach ($candidates as $id) if (!isset($seen[$id])) $roots[] = $build($id, null);

    // Monitored hosts that aren't on any port we can see (VPN, other sites, quiet devices).
    $unplaced = [];
    foreach ($hosts as $id => $h) {
        if (isset($canon[$id]) || isset($placed[$id]) || $isGuest($id)) continue;
        if (in_array($id, $wifi['controllers'], true)) continue;   // a virtual controller's address moves with its conductor AP
        $unplaced[] = ['id' => $id, 'name' => $h['name'], 'ip' => $h['ip_address'], 'device' => $h['device']];
    }
    usort($unplaced, fn($a, $b) => ip2long($a['ip']) <=> ip2long($b['ip']));

    return ['nodes' => (object) $nodes, 'links' => array_values($links), 'roots' => $roots, 'unplaced' => $unplaced, 'wifiClients' => count($wifi['clients']),
            'generatedAt' => (int) round(microtime(true) * 1000)];
}
