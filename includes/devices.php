<?php
require_once __DIR__ . '/db.php';

/**
 * Device identification: what kind of thing each address on the network is
 * (game console, TV, smart-home gadget, phone, ...), from its name (Wi-Fi
 * hostname or card name), the MAC's manufacturer (IEEE OUI list), the OS
 * the Wi-Fi controller fingerprinted, and which subnet it's on.
 */

const OUI_FILE = '/usr/share/hwdata/oui.txt';

const DEVICE_CATEGORIES = [
    'console' => ['🎮', 'Game console'], 'tv' => ['📺', 'TV & streaming'], 'speaker' => ['🔊', 'Smart speaker'],
    'camera' => ['📷', 'Camera & doorbell'], 'iot' => ['💡', 'Smart home / IoT'], 'appliance' => ['🧺', 'Appliance'],
    'phone' => ['📱', 'Phone & tablet'], 'computer' => ['💻', 'Computer'], 'printer' => ['🖨️', 'Printer'],
    'voip' => ['☎️', 'Phone system'], 'network' => ['🛜', 'Network gear'], 'server' => ['🖥️', 'Server & VM'],
    'unknown' => ['❔', 'Unknown'],
];

// [pattern on the device name, category, label] — checked first; names say the most.
const DEVICE_NAME_RULES = [
    ['/xbox/i', 'console', 'Xbox'], ['/playstation|\bps[345]\b|^ps[345]-/i', 'console', 'PlayStation'],
    ['/nintendo/i', 'console', 'Nintendo Switch'], ['/steam-?deck/i', 'console', 'Steam Deck'],
    ['/webos|bravia|roku|fire-?tv|aft[a-z]{1,3}\b|apple-?tv|chromecast|shield|tizen|smart-?tv|vizio|hisense|tcl-/i', 'tv', 'TV / streaming'],
    ['/\b\d{2}["”]|\b(q|o|mini ?)?led\b|\buhd\b|\b4k\b|\btv\b/i', 'tv', 'TV'],
    ['/homepod|home-pod/i', 'speaker', 'HomePod'], ['/google-?home|nest-?(mini|hub|audio)|home-?mini/i', 'speaker', 'Google / Nest speaker'],
    ['/\becho\b|alexa|^amazon-/i', 'speaker', 'Amazon Echo / Fire'], ['/sonos/i', 'speaker', 'Sonos'],
    ['/blink|^ring-|nest-?hello|doorbell|camera|\bcam\d*\b|arlo|wyze|eufy|reolink|hikvision|dahua/i', 'camera', 'Camera / doorbell'],
    ['/washer|dryer|dish-?washer|fridge|refrigerator|oven|thinq|lg_smart/i', 'appliance', 'Smart appliance'],
    ['/^esp[_-]|esp32|esp8266|tasmota|shelly|tuya|smartlife|^lwip|wled|sonoff|\bhue\b|lifx|kasa|ecobee|thermostat|myq|irobot|roomba|sync-module|^wlan0$/i', 'iot', 'Smart-home device'],
    ['/iphone|ipad|android|galaxy|pixel|-s2\d|oneplus|moto[ -]|redmi|xiaomi/i', 'phone', 'Phone / tablet'],
    ['/macbook|imac|mac-?mini|desktop-|laptop|-pc\b|^win-|surface|thinkpad/i', 'computer', 'Computer'],
    ['/epson|brother|canon|^hp[0-9a-f]{6}|printer|officejet|laserjet|deskjet/i', 'printer', 'Printer'],
];

// [pattern on the manufacturer, category, label]. Makers of one kind of thing:
// these beat the Wi-Fi controller's OS guess (which calls some printers "Windows Mobile").
const DEVICE_VENDOR_RULES = [
    ['/Sony Interactive/i', 'console', 'PlayStation'], ['/Nintendo/i', 'console', 'Nintendo'], ['/Valve/i', 'console', 'Steam Deck'],
    ['/Roku/i', 'tv', 'Roku'], ['/Dish Technologies|EchoStar/i', 'tv', 'Satellite TV receiver'], ['/Vizio|TCL|Hisense|TiVo/i', 'tv', 'TV / streaming'],
    ['/Sonos/i', 'speaker', 'Sonos'],
    ['/Ring LLC|Immedia Semiconductor|Blink|Wyze|Arlo|Hikvision|Dahua|Reolink|Axis Comm/i', 'camera', 'Camera / doorbell'],
    ['/Midea|Whirlpool|Electrolux|BSH Haus|Haier|iRobot/i', 'appliance', 'Smart appliance'],
    ['/Espressif/i', 'iot', 'ESP32/ESP8266 device'], ['/Tuya/i', 'iot', 'Tuya smart device'], ['/Signify|Philips Light/i', 'iot', 'Philips Hue'],
    ['/ecobee/i', 'iot', 'ecobee'], ['/Zengge/i', 'iot', 'LED light controller'], ['/Nest Labs/i', 'iot', 'Google Nest'], ['/iRobot/i', 'iot', 'iRobot'], ['/Chamberlain/i', 'iot', 'myQ garage'],
    ['/Belkin/i', 'iot', 'Wemo'], ['/Shelly|Allterco/i', 'iot', 'Shelly'], ['/Lutron/i', 'iot', 'Lutron'], ['/Murata/i', 'iot', 'Wi-Fi module device'],
    ['/Ooma|Polycom|Yealink|Grandstream|Snom|Obihai/i', 'voip', 'VoIP phone'],
    ['/Seiko Epson|Brother Ind|Canon|Xerox|Lexmark|Kyocera|Ricoh/i', 'printer', 'Printer'],
    ['/Proxmox|VMware|XEN|Parallels|QEMU|Docker/i', 'server', 'Virtual machine'], ['/Raspberry Pi/i', 'computer', 'Raspberry Pi'],
    ['/Cisco|Aruba|Hewlett Packard Enterprise|Fortinet|Ubiquiti|NETGEAR|Juniper|MikroTik|Netgate/i', 'network', 'Network device'],
];
// Makers of many kinds of things: only used when the name and OS say nothing.
const DEVICE_VENDOR_GENERIC = [
    ['/Apple/i', 'phone', 'Apple device'], ['/Samsung/i', 'phone', 'Samsung device'], ['/LG Electronics/i', 'tv', 'LG device'],
    ['/Amazon Tech/i', 'speaker', 'Amazon device (Echo, Fire TV, ...)'], ['/Google/i', 'speaker', 'Google device'],
    ['/Dell|Lenovo|ASUSTek|Micro-Star|GIGA-BYTE|Intel Corp|Realtek|Liteon|AzureWave|Hon Hai|Super Micro|INVENTEC/i', 'computer', 'Computer'],
    ['/HP Inc|Hewlett[- ]Packard/i', 'computer', 'HP device (PC or printer)'],
    ['/Microsoft/i', 'computer', 'Microsoft (Surface or Xbox)'], ['/TP-LINK/i', 'iot', 'TP-Link (smart plug or network)'],
];

// Kinds of device that belong on a network of their own, if you have one (matched on subnet names).
const EXPECTED_NETWORK = [
    'iot' => '/iot|smart/i', 'camera' => '/iot|camera|cctv|smart/i', 'speaker' => '/iot|smart/i', 'appliance' => '/iot|smart/i',
    'tv' => '/\btvs?\b|media|stream/i', 'console' => '/gam/i',
];

// Card device type -> category.
const CARD_CATEGORY = ['server' => 'server', 'nas' => 'server', 'switch' => 'network', 'router' => 'network', 'ap' => 'network',
    'printer' => 'printer', 'camera' => 'camera', 'desktop' => 'computer', 'laptop' => 'computer', 'mobile' => 'phone', 'ups' => 'iot',
    'iot' => 'iot', 'console' => 'console', 'tv' => 'tv'];

/** Loads the IEEE OUI list into mac_vendors when it's missing or the file changed (background jobs). */
function ensure_mac_vendors(): void {
    $pdo = db();
    $mtime = @filemtime(OUI_FILE);
    if (!$mtime || (string) $pdo->query("SELECT v FROM settings WHERE k = 'oui_mtime'")->fetchColumn() === (string) $mtime) return;
    $fh = @fopen(OUI_FILE, 'r');
    if (!$fh) return;
    $rows = [];
    while (($line = fgets($fh)) !== false) {
        if (preg_match('/^([0-9A-F]{6})\s+\(base 16\)\s+(.+?)\s*$/', $line, $m)) $rows[$m[1]] = mb_substr($m[2], 0, 160);
    }
    fclose($fh);
    if (count($rows) < 1000) return;
    $pdo->beginTransaction();
    $pdo->exec('DELETE FROM mac_vendors');
    foreach (array_chunk($rows, 1000, true) as $chunk) {
        $pdo->prepare('INSERT INTO mac_vendors (prefix, vendor) VALUES ' . implode(',', array_fill(0, count($chunk), '(?, ?)')))
            ->execute(array_merge(...array_map(fn($p, $v) => [$p, $v], array_keys($chunk), $chunk)));
    }
    $pdo->prepare("INSERT INTO settings (k, v) VALUES ('oui_mtime', ?) ON DUPLICATE KEY UPDATE v = VALUES(v)")->execute([(string) $mtime]);
    $pdo->commit();
}

/** Locally administered MACs: phones' private Wi-Fi addresses (but not Docker 02:42 / QEMU 52:54). */
function is_random_mac(string $mac): bool {
    return (bool) (hexdec($mac[1]) & 2) && !str_starts_with($mac, '02:42') && !str_starts_with($mac, '52:54:00');
}

/** [mac => vendor] for many MACs at once. */
function mac_vendors(array $macs): array {
    $prefixes = [];
    foreach ($macs as $m) if ($m && !is_random_mac($m)) $prefixes[strtoupper(str_replace(':', '', substr($m, 0, 8)))][] = $m;
    if (!$prefixes) return [];
    $out = [];
    foreach (array_chunk(array_keys($prefixes), 500) as $chunk) {
        $q = db()->prepare('SELECT prefix, vendor FROM mac_vendors WHERE prefix IN (' . implode(',', array_fill(0, count($chunk), '?')) . ')');
        $q->execute($chunk);
        foreach ($q->fetchAll(PDO::FETCH_KEY_PAIR) as $p => $v) foreach ($prefixes[$p] as $m) $out[$m] = $v;
    }
    foreach ($macs as $m) {
        if ($m && str_starts_with($m, '52:54:00')) $out[$m] = 'QEMU/KVM virtual NIC';
        elseif ($m && str_starts_with($m, '02:42')) $out[$m] = 'Docker container';
    }
    return $out;
}

/**
 * [category, label, confidence] for one device. $d: name, vendor, os (Wi-Fi
 * fingerprint), mac, wireless (bool), subnetName, cardDevice.
 */
function identify_device(array $d): array {
    $name = trim((string) ($d['name'] ?? ''));
    $vendor = (string) ($d['vendor'] ?? '');
    $os = (string) ($d['os'] ?? '');
    if (!empty($d['cardDevice']) && isset(CARD_CATEGORY[$d['cardDevice']]) && $d['cardDevice'] !== 'server') {
        $labels = ['ap' => 'Access point', 'nas' => 'NAS', 'ups' => 'UPS', 'iot' => 'Smart-home device', 'console' => 'Game console', 'tv' => 'TV / streaming'];
        return [CARD_CATEGORY[$d['cardDevice']], $labels[$d['cardDevice']] ?? ucfirst($d['cardDevice']), 'high'];
    }
    if ($name !== '' && !filter_var($name, FILTER_VALIDATE_IP)) {
        foreach (DEVICE_NAME_RULES as [$re, $cat, $label]) if (preg_match($re, $name)) return [$cat, $label, 'high'];
    }
    // What the device said about itself when scanned (model, UPnP name, web page title).
    $said = trim((string) ($d['scanText'] ?? ''));
    if ($said !== '') {
        foreach (DEVICE_NAME_RULES as [$re, $cat, $label]) if (preg_match($re, $said)) return [$cat, $label, 'high'];
        if (preg_match('/speaker|google home|nest (mini|audio|hub)/i', $said)) return ['speaker', 'Smart speaker', 'high'];
        if (preg_match('/mainsail|fluidd|octoprint|klipper|moonraker|bambu|prusalink|creality/i', $said)) return ['printer', '3D printer', 'high'];
        if (preg_match('/print|epson|brother|laserjet/i', $said)) return ['printer', 'Printer', 'high'];
        if (preg_match('/\bnas\b|qnap|synology|ts-x\d|diskstation/i', $said)) return ['server', 'NAS', 'high'];
    }
    // What Home Assistant controls on it (its entities' domains).
    $kinds = (array) ($d['haKinds'] ?? []);
    foreach ([['camera', 'camera', 'Camera'], ['vacuum', 'appliance', 'Robot vacuum'], ['lawn_mower', 'appliance', 'Robot mower'],
              ['climate', 'iot', 'Thermostat / climate'], ['lock', 'iot', 'Smart lock'], ['alarm_control_panel', 'iot', 'Alarm panel'],
              ['cover', 'iot', 'Blind / garage door'], ['light', 'iot', 'Smart light'], ['fan', 'iot', 'Smart fan'],
              ['media_player', 'tv', 'Media player'], ['switch', 'iot', 'Smart plug / switch']] as [$k, $cat, $label]) {
        if (in_array($k, $kinds, true)) return [$cat, $label, 'high'];
    }
    // Services it advertises over mDNS (same subnet only).
    $svc = (array) ($d['scan']['services'] ?? []);
    foreach ([['_ipp', 'printer', 'Printer'], ['_printer', 'printer', 'Printer'], ['_qdiscover', 'server', 'NAS'], ['_adisk', 'server', 'NAS'],
              ['_rdlink', 'computer', 'Mac'], ['_sonos', 'speaker', 'Sonos'], ['_googlecast', 'speaker', 'Google Cast device'],
              ['_airplay', 'tv', 'AirPlay device (Apple TV or speaker)'], ['_companion-link', 'phone', 'Apple device'],
              ['_hap', 'iot', 'HomeKit device'], ['_esphomelib', 'iot', 'ESPHome device']] as [$s, $cat, $label]) {
        if (in_array($s, $svc, true)) return [$cat, $label, 'medium'];
    }
    // Services it offers.
    $ports = $d['scanPorts'] ?? [];
    if (in_array(5555, $ports, true) && array_intersect([8008, 8009], $ports)) return ['tv', 'Android TV / Google TV device', 'medium'];
    foreach ([[8060, 'tv', 'Roku'], [6668, 'iot', 'Tuya smart device'], [8008, 'speaker', 'Google Cast device'], [62078, 'phone', 'iPhone / iPad'],
              [9100, 'printer', 'Printer'], [631, 'printer', 'Printer'], [1400, 'speaker', 'Sonos'],
              [9999, 'iot', 'TP-Link Kasa'], [3001, 'tv', 'LG webOS TV'], [8001, 'tv', 'Samsung TV'], [1925, 'tv', 'Philips TV'],
              [3389, 'computer', 'Windows PC']] as [$port, $cat, $label]) {
        if (in_array($port, $ports, true)) return [$cat, $label, 'medium'];
    }
    if (in_array(55443, $ports, true) && preg_match('/Yeelight|Xiaomi|Qingdao/i', $vendor)) return ['iot', 'Yeelight', 'medium'];
    if ($vendor !== '') {
        if (preg_match('/Microsoft/i', $vendor) && preg_match('/gam/i', (string) ($d['subnetName'] ?? ''))) return ['console', 'Xbox', 'medium'];
        foreach (DEVICE_VENDOR_RULES as [$re, $cat, $label]) if (preg_match($re, $vendor)) return [$cat, $label, 'medium'];
    }
    if ($os !== '' && $os !== 'NOFP') {
        if (preg_match('/iphone|ipad|android|windows mobile|ios/i', $os)) return ['phone', $os, 'medium'];
        if (preg_match('/roku/i', $os)) return ['tv', 'Roku', 'high'];
        if (preg_match('/^win|mac ?os|chrome ?os/i', $os)) return ['computer', $os, 'medium'];
    }
    if ($vendor !== '') {
        // Makers of both TVs/streamers and other things, on a network kept for TVs: a TV or streamer.
        if (preg_match('/\btvs?\b|media|stream/i', (string) ($d['subnetName'] ?? '')) && preg_match('/Amazon|Google|Samsung|LG |Sony|Hisense|TCL|Vizio|Apple/i', $vendor)) {
            $what = ['Amazon' => 'Fire TV', 'Google' => 'Chromecast / Google TV', 'Apple' => 'Apple TV'];
            foreach ($what as $re => $label) if (stripos($vendor, $re) !== false) return ['tv', $label, 'medium'];
            return ['tv', 'TV', 'medium'];
        }
        foreach (DEVICE_VENDOR_GENERIC as [$re, $cat, $label]) if (preg_match($re, $vendor)) return [$cat, $label, 'low'];
    }
    if (!empty($d['mac']) && is_random_mac($d['mac'])) {
        // Phones use a private address per Wi-Fi network; on a Wi-Fi subnet that's the likely answer.
        // On an IoT/TV/gaming subnet, the subnet says more (below); elsewhere it's likely a VM.
        $subnetName = (string) ($d['subnetName'] ?? '');
        if (!empty($d['wireless']) || preg_match('/wi-?fi|wireless|wlan|guest/i', $subnetName)) return ['phone', 'Phone / tablet (private Wi-Fi address)', 'low'];
        if (!preg_match('/iot|smart|\btvs?\b|media|stream|gam/i', $subnetName)) return ['server', 'VM or container (locally assigned MAC)', 'low'];
    }
    if (preg_match('/^linux$/i', $os)) return ['iot', 'Linux device', 'low'];
    $subnet = (string) ($d['subnetName'] ?? '');
    if (preg_match('/iot/i', $subnet)) return ['iot', 'Device on the IoT network', 'low'];
    if (preg_match('/gam/i', $subnet)) return ['console', 'Device on the gaming network', 'low'];
    if (preg_match('/\btvs?\b|media|stream/i', $subnet)) return ['tv', 'Device on the TV network', 'low'];
    // A card added as "server" (the default type) with nothing else to go on.
    if (!empty($d['cardDevice']) && isset(CARD_CATEGORY[$d['cardDevice']])) return [CARD_CATEGORY[$d['cardDevice']], $d['cardDevice'] === 'nas' ? 'NAS' : 'Server', 'medium'];
    return ['unknown', $vendor !== '' ? $vendor : 'Unknown', 'low'];
}

/**
 * Every device seen on the network, identified: from the topology (switch
 * ports, Wi-Fi clients by AP, Proxmox guests) plus monitored cards not seen
 * on any port.
 */
function device_inventory(): array {
    require_once __DIR__ . '/portmap.php';
    require_once __DIR__ . '/wireless.php';
    $topo = network_topology();
    $wifi = wireless_overview();
    $wifiByMac = [];
    foreach ($wifi['clients'] as $c) $wifiByMac[$c['mac']] = $c;
    $names = subnet_names();
    $cards = [];
    foreach (db()->query('SELECT id, name, ip_address, device, auto_named FROM hosts') as $h) $cards[(int) $h['id']] = $h;
    // A card named and typed by auto-naming says nothing new about the device: don't read it back.
    $autoNamed = array_flip(array_keys(array_filter($cards, fn($c) => (bool) $c['auto_named'])));

    $rows = [];
    $byIp = [];   // ip -> row key, so a VM listed by its host and seen on a port is one row
    $add = function (array $r) use (&$rows, &$byIp) {
        $key = $r['mac'] ?: ('ip:' . $r['ip']);
        // Same address as an earlier row: the same device if one of the two has no MAC (e.g. a VM
        // listed by its Proxmox host); two different MACs on one IP are two devices (a conflict).
        if ($r['ip'] && isset($byIp[$r['ip']]) && (!$r['mac'] || !($rows[$byIp[$r['ip']]]['mac'] ?? null))) $key = $byIp[$r['ip']];
        if (isset($rows[$key])) {
            foreach ($r as $k => $v) if (($rows[$key][$k] ?? null) === null && $v !== null) $rows[$key][$k] = $v;
            return;
        }
        $rows[$key] = $r;
        if ($r['ip']) $byIp[$r['ip']] = $key;
    };
    foreach ((array) $topo['nodes'] as $n) {
        $add(['mac' => $n['mac'] ?? null, 'ip' => $n['ip'], 'name' => $n['name'], 'hostId' => $n['id'], 'wireless' => false,
              'where' => ['kind' => 'network'], 'vlan' => null, 'cardDevice' => $n['device'], 'os' => null]);
        foreach ($n['ports'] as $p) {
            foreach ($p['devices'] as $d) {
                $w = $wifiByMac[$d['mac']] ?? null;
                $isWifi = $n['device'] === 'ap';
                $cardName = isset($d['host']['name']) && $d['host']['name'] !== ($d['host']['ip'] ?? null) && !isset($autoNamed[$d['host']['id']]) ? $d['host']['name'] : null;
                $add(['mac' => $d['mac'], 'ip' => $d['ip'] ?? ($d['host']['ip'] ?? null), 'name' => $cardName ?? ($w['name'] ?? null) ?? (isset($d['host']['id']) && isset($autoNamed[$d['host']['id']]) ? null : ($d['name'] ?? null)),
                      'hostId' => $d['host']['id'] ?? null, 'wireless' => $isWifi,
                      'where' => $isWifi ? ['kind' => 'wifi', 'ap' => $n['name'], 'apId' => $n['id'], 'ssid' => $p['port'], 'band' => $d['band'] ?? null]
                                         : ['kind' => 'wired', 'switch' => $n['name'], 'switchId' => $n['id'], 'port' => $p['port']],
                      'vlan' => $d['vlan'] ?? null, 'cardDevice' => isset($d['host']['id']) && !isset($autoNamed[$d['host']['id']]) ? ($cards[$d['host']['id']]['device'] ?? null) : null,
                      'os' => $w['os'] ?? null, 'note' => $d['note'] ?? null]);
                foreach ($d['guests'] ?? [] as $g) {
                    $add(['mac' => null, 'ip' => $g['ip'], 'name' => $g['name'], 'hostId' => $g['hostId'], 'wireless' => false,
                          'where' => ['kind' => 'vm', 'hypervisor' => $d['host']['name'] ?? '', 'hypervisorId' => $d['host']['id'] ?? null,
                                      'vmid' => $g['vmid'], 'type' => $g['type']],
                          'vlan' => null, 'cardDevice' => 'server', 'os' => null, 'forceCategory' => ['server', $g['type'] === 'lxc' ? 'LXC container' : 'Virtual machine']]);
                }
                foreach ($d['wifiClients'] ?? [] as $c) {
                    $w2 = $wifiByMac[$c['mac']] ?? null;
                    $wname = $c['hostId'] && isset($autoNamed[$c['hostId']]) ? ($wifiByMac[$c['mac']]['name'] ?? null) : $c['name'];
                    $add(['mac' => $c['mac'], 'ip' => $c['ip'], 'name' => $wname, 'hostId' => $c['hostId'], 'wireless' => true,
                          'where' => ['kind' => 'wifi', 'ap' => $d['host']['name'] ?? $d['ap']['name'], 'apId' => $d['host']['id'] ?? null, 'ssid' => $c['ssid'], 'band' => $c['band']],
                          'vlan' => null, 'cardDevice' => null, 'os' => $w2['os'] ?? null]);
                }
            }
        }
    }
    foreach ($topo['unplaced'] as $u) {
        $auto = isset($autoNamed[$u['id']]);
        $add(['mac' => null, 'ip' => $u['ip'], 'name' => $auto ? null : $u['name'], 'hostId' => $u['id'], 'wireless' => false,
              'where' => ['kind' => 'unplaced'], 'vlan' => null, 'cardDevice' => $auto ? null : $u['device'], 'os' => null]);
    }

    // Devices known only by address (cards not seen on any port): the MAC that the switches' ARP
    // tables, or else the DHCP server, give that address. Not a MAC another row already has.
    require_once __DIR__ . '/dhcpleases.php';
    $ipMac = dhcp_lease_macs();
    foreach (known_mac_ips() as $mac => $ip) $ipMac[$ip] = $mac;
    $usedMacs = array_flip(array_filter(array_column($rows, 'mac')));
    foreach ($rows as &$r) {
        $mac = !$r['mac'] && $r['ip'] ? ($ipMac[$r['ip']] ?? null) : null;
        if (!$mac || isset($usedMacs[$mac])) continue;
        $r['mac'] = $mac;
        $usedMacs[$mac] = true;
        if ($w = $wifiByMac[$mac] ?? null) {
            $r['wireless'] = true;
            $r['name'] ??= $w['name'] ?? null;
            $r['os'] ??= $w['os'] ?? null;
        }
    }
    unset($r);

    $vendors = mac_vendors(array_filter(array_column($rows, 'mac')));
    require_once __DIR__ . '/devicescan.php';
    $scans = device_scan_summaries();
    $scanPorts = [];
    foreach (db()->query('SELECT dkey, data FROM device_scans WHERE data IS NOT NULL') as $sr) {
        $scanPorts[$sr['dkey']] = array_column(json_decode($sr['data'], true)['ports'] ?? [], 'port');
    }
    require_once __DIR__ . '/homeassistant.php';
    [$haByMac, $haByIp, $haByName, $haAll] = ha_device_index();
    $scanKey = function (array $r) use ($scans): string {
        $key = device_scan_key($r['mac'], $r['ip']);
        return !isset($scans[$key]) && $r['ip'] && isset($scans['ip:' . $r['ip']]) ? 'ip:' . $r['ip'] : $key;   // scanned from its card (by IP)
    };
    // Home Assistant's record of each device: by MAC, then IP, then an exact (unique) name...
    $haFor = $haUsed = [];
    foreach ($rows as $k => $r) {
        $scan = $scans[$scanKey($r)] ?? null;
        $ha = ($r['mac'] ? $haByMac[$r['mac']] ?? null : null) ?? ($r['ip'] ? $haByIp[$r['ip']] ?? null : null)
            ?? $haByName[mb_strtolower((string) ($scan['name'] ?? ''))] ?? $haByName[mb_strtolower((string) ($r['name'] ?? ''))] ?? null;
        if ($ha) { $haFor[$k] = $ha; $haUsed[$ha['_i']] = true; }
    }
    // ...then a maker that only one device has on each side (HA's one Resideo thermostat is the one
    // Resideo device on the network). One name twice under a maker is one device in two integrations.
    $haBrand = $rowBrand = [];
    foreach ($haAll as $d) {
        if (!isset($haUsed[$d['_i']]) && ($b = short_brand($d['maker'] ?? null))) $haBrand[$b][mb_strtolower($d['name'])][] = $d;
    }
    foreach ($rows as $k => $r) {
        if (isset($haFor[$k])) continue;
        $scan = $scans[$scanKey($r)] ?? null;
        if ($b = short_brand($scan['maker'] ?? null) ?? short_brand($r['mac'] ? ($vendors[$r['mac']] ?? null) : null)) $rowBrand[$b][] = $k;
    }
    foreach ($haBrand as $b => $byName) {
        if (count($byName) !== 1 || count($rowBrand[$b] ?? []) !== 1 || in_array($b, HA_MAKER_MATCH_SKIP, true)) continue;
        $group = reset($byName);
        usort($group, fn($x, $y) => count($y) <=> count($x));   // the integration that says the most (has a model)
        $haFor[$rowBrand[$b][0]] = $group[0] + ['byMaker' => true];
        foreach ($group as $d) $haUsed[$d['_i']] = true;
    }
    // In Home Assistant but not found on the network (cloud-linked devices): listed on their own.
    $haOnly = [];
    foreach ($haAll as $d) {
        if (isset($haUsed[$d['_i']])) continue;
        $haOnly[] = array_intersect_key($d, array_flip(['name', 'maker', 'model', 'area', 'kinds']));
    }
    $out = [];
    foreach ($rows as $k => $r) {
        $key = $scanKey($r);
        $scan = $scans[$key] ?? null;
        $r['scan'] = $scan;
        $r['scanText'] = $scan ? implode(' ', array_filter([$scan['maker'] ?? null, $scan['model'] ?? null, $scan['name'] ?? null, $scan['webTitle'] ?? null])) : '';
        $r['scanPorts'] = $scanPorts[$key] ?? [];
        if (empty($r['name']) && !empty($scan['name'])) $r['name'] = $scan['name'];
        $ha = $haFor[$k] ?? null;
        $r['ha'] = $ha ? array_intersect_key($ha, array_flip(['name', 'maker', 'model', 'firmware', 'area', 'byMaker'])) : null;
        $r['haKinds'] = $ha['kinds'] ?? [];
        if ($ha) {
            // The name you gave it in Home Assistant, unless a person named its card here.
            $card = $r['hostId'] ? ($cards[$r['hostId']] ?? null) : null;
            $cardNamed = $card && !$card['auto_named'] && $card['name'] !== $card['ip_address'];
            if (!$cardNamed) $r['name'] = $ha['name'];
            $r['scanText'] = trim($r['scanText'] . ' ' . implode(' ', array_filter([$ha['maker'] ?? null, $ha['model'] ?? null, $ha['name']])));
        }
        $r['vendor'] = $r['mac'] ? ($vendors[$r['mac']] ?? null) : null;
        $r['randomMac'] = $r['mac'] ? is_random_mac($r['mac']) : false;
        $subnet = $r['ip'] ? preg_replace('/\.\d+$/', '.0/24', $r['ip']) : null;
        $r['subnet'] = $subnet;
        $r['subnetName'] = $subnet ? ($names[$subnet] ?? null) : null;
        if (!empty($r['name']) && filter_var($r['name'], FILTER_VALIDATE_IP)) $r['name'] = null;
        [$r['category'], $r['label'], $r['confidence']] = $r['forceCategory'] ?? null
            ? [...$r['forceCategory'], 'high'] : identify_device($r);
        // The exact model when the device told us ("Roku Premiere 3920CA").
        if (!empty($scan['model']) && $r['category'] !== 'server' || (!empty($scan['model']) && $r['label'] === 'NAS')) {
            $maker = (string) ($scan['maker'] ?? '');
            $r['label'] = $maker !== '' && stripos($scan['model'], $maker) === false ? "$maker {$scan['model']}" : $scan['model'];
        } elseif (!empty($ha['model']) && $r['category'] !== 'server' && empty($r['forceCategory'])) {
            $maker = (string) ($ha['maker'] ?? '');
            $r['label'] = mb_substr($maker !== '' && stripos($ha['model'], $maker) === false ? "$maker {$ha['model']}" : $ha['model'], 0, 80);
        }
        unset($r['forceCategory'], $r['scanText'], $r['scanPorts'], $r['haKinds']);
        $r['netWarning'] = wrong_network_note($r, $names);
        $out[] = $r;
    }
    usort($out, fn($a, $b) => [array_search($a['category'], array_keys(DEVICE_CATEGORIES)), ip2long($a['ip'] ?? '255.255.255.255')]
                           <=> [array_search($b['category'], array_keys(DEVICE_CATEGORIES)), ip2long($b['ip'] ?? '255.255.255.255')]);
    return ['devices' => $out, 'haOnly' => $haOnly, 'categories' => DEVICE_CATEGORIES, 'generatedAt' => (int) round(microtime(true) * 1000)];
}

// ---- Useful names ----

// Hostnames that say nothing about the device.
const GENERIC_NAME = '/^(wlan\d*|lwip\d*|esp[_-]?[0-9a-f]{4,}|android-[0-9a-f]{6,}|amazon-[0-9a-f]{6,}|blink[ -]?device|blink dhcp|device|unknown|localhost|espressif|[0-9a-f]{12}|samsung|lg|google)$/i';

// Chip/platform makers inside many brands' gadgets: one of each on both sides proves nothing.
const HA_MAKER_MATCH_SKIP = ['ESP', 'Murata', 'Tuya', 'Amazon', 'Google', 'Apple', 'Samsung', 'Microsoft', 'TP-Link'];

// Short brand from a maker name (MAC prefix or what the device said).
const BRANDS = [
    '/Blink/i' => 'Blink', '/Ring/i' => 'Ring', '/Nest/i' => 'Nest', '/Google/i' => 'Google', '/Amazon/i' => 'Amazon', '/Espressif/i' => 'ESP',
    '/Tuya/i' => 'Tuya', '/Samsung|Seongji/i' => 'Samsung', '/LG /i' => 'LG', '/Midea/i' => 'Midea', '/Roku/i' => 'Roku', '/Sonos/i' => 'Sonos',
    '/Apple/i' => 'Apple', '/Philips|Signify/i' => 'Philips Hue', '/ecobee/i' => 'ecobee', '/Wyze/i' => 'Wyze', '/TP-LINK/i' => 'TP-Link',
    '/Zengge/i' => 'Magic Home', '/Shelly/i' => 'Shelly', '/iRobot/i' => 'iRobot', '/Sony/i' => 'Sony', '/Microsoft/i' => 'Microsoft',
    '/Resideo|Honeywell/i' => 'Resideo', '/Ecovacs/i' => 'Ecovacs', '/^LGE$/i' => 'LG',
    '/Nintendo/i' => 'Nintendo', '/Epson/i' => 'Epson', '/Dish/i' => 'Dish', '/Belkin/i' => 'Wemo', '/Murata/i' => 'Murata',
];
const KIND_WORDS = ['console' => 'console', 'tv' => 'TV', 'speaker' => 'speaker', 'camera' => 'camera', 'iot' => 'smart device',
    'appliance' => 'appliance', 'phone' => 'phone', 'computer' => 'computer', 'printer' => 'printer', 'voip' => 'phone', 'network' => 'network device',
    'server' => 'server', 'unknown' => 'device'];
// Words that are what a thing is, not whose it is ("Console" isn't a first name).
const NOT_A_FIRST_NAME = '/^(phone|computer|speaker|camera|printer|laptop|console|tv|desktop|server|tablet|device|router|switch|plug|bulb|light|doorbell)$/i';

function short_brand(?string $maker): ?string {
    if (!$maker) return null;
    foreach (BRANDS as $re => $b) if (preg_match($re, $maker)) return $b;
    return null;
}

/** A readable name from a hostname: "Nest-Hello-56ba" -> "Nest Hello", "Samsung-Washer" -> "Samsung Washer". */
function tidy_hostname(?string $n): ?string {
    $n = trim((string) $n);
    if ($n === '' || filter_var($n, FILTER_VALIDATE_IP) || preg_match(GENERIC_NAME, $n)) return null;
    $n = preg_replace('/[-_ ][0-9a-f]{4,}$/i', '', $n);                // trailing serial/MAC fragment
    $n = preg_replace('/(?<=[a-z])[-_ ]s[-_ ]/i', "'s ", $n);           // "Geraldine-s-S21" -> "Geraldine's S21"
    $n = preg_replace('/^([A-Za-z]+?)s[-_ ](?=home|pod|tv|ipad|iphone|phone|laptop|mac)/i', "$1's ", $n);   // "Sams-Home-Pod" -> "Sam's Home Pod"
    $n = preg_replace('/[-_]+/', ' ', $n);
    $n = preg_replace('/\s+/', ' ', trim($n));
    if ($n === '' || preg_match(GENERIC_NAME, $n) || strlen($n) < 3) return null;
    return mb_substr(mb_strtoupper(mb_substr($n, 0, 1)) . mb_substr($n, 1), 0, 50);
}

/** Best name for a device (inventory row): its own name, else brand + kind ("Blink camera"). */
function useful_device_name(array $d): string {
    $scan = $d['scan'] ?? [];
    if (!empty($d['ha']['name'])) return mb_substr($d['ha']['name'], 0, 50);   // the name you gave it in Home Assistant
    $own = tidy_hostname($scan['name'] ?? null) ?? tidy_hostname($d['name'] ?? null);
    // Just a first name ("Aaron-"): say whose what it is ("Aaron's Roku").
    if ($own && !str_contains($own, ' ') && preg_match('/^[A-Z][a-z]{2,}$/', $own) && !preg_match(NOT_A_FIRST_NAME, $own)) {
        $what = short_brand($scan['maker'] ?? null) ?? short_brand($d['vendor'] ?? null) ?? (KIND_WORDS[$d['category']] ?? null);
        if ($what && $d['category'] !== 'unknown') return "$own's $what";
    }
    if ($own) return $own;
    // The hostname's brand beats the network card's maker ("Blink-Device" on an Amazon chip is a Blink).
    $brand = short_brand($d['name'] ?? null) ?? short_brand($scan['maker'] ?? null) ?? short_brand($d['vendor'] ?? null) ?? short_brand($d['label'] ?? null);
    if (!empty($scan['model'])) return mb_substr($brand && stripos($scan['model'], $brand) === false ? "$brand {$scan['model']}" : $scan['model'], 0, 50);
    $kind = KIND_WORDS[$d['category']] ?? 'device';
    $vendor = (string) ($d['vendor'] ?? '');
    if (preg_match('/doorbell|hello/i', (string) ($d['name'] ?? ''))) $kind = 'doorbell';
    if (preg_match('/^Amazon Echo/', $d['label'] ?? '')) [$brand, $kind] = ['Amazon', 'Echo'];
    if (preg_match('/Zengge/i', $vendor) || preg_match('/LED light/i', $d['label'] ?? '')) $kind = 'LED controller';
    if (preg_match('/Air-?Condition/i', $vendor)) $kind = 'air conditioner';
    if (($d['label'] ?? '') === '3D printer') return '3D printer';
    if (($d['label'] ?? '') === 'Android TV / Google TV device') return 'Android TV box';
    if (in_array($d['label'] ?? '', ['Fire TV', 'Apple TV'], true)) return $d['label'];
    if (($d['label'] ?? '') === 'Chromecast / Google TV') return 'Chromecast';
    return $brand ? "$brand $kind" : ucfirst($kind);
}

/** Card type for a device category. */
/** Nothing real known about the device: no name, nothing from a scan, no telling identification. */
function nothing_known(array $d): bool {
    $vague = $d['category'] === 'unknown' || ($d['category'] === 'server' && $d['label'] === 'Server');   // unknown: the label is just the chip's maker
    return $vague && empty($d['name']) && empty($d['scan']['name']) && empty($d['scan']['model']) && empty($d['scan']['webTitle']);
}

function card_type_for(string $category): string {
    return ['console' => 'console', 'tv' => 'tv', 'camera' => 'camera', 'phone' => 'mobile', 'printer' => 'printer', 'computer' => 'desktop',
            'network' => 'switch', 'server' => 'server'][$category] ?? 'iot';
}

/**
 * Subnets with "Name and type cards" on: cards named only by their address (or named
 * by this before, until a person renames them) get a useful name and type. Same names
 * in one subnet get the address's last number (".12"). Runs from cli/poll-snmp.php.
 */
function auto_name_cards(): void {
    $pdo = db();
    $subnets = $pdo->query('SELECT subnet FROM subnet_scans WHERE auto_name = 1')->fetchAll(PDO::FETCH_COLUMN);
    if (!$subnets) return;
    require_once __DIR__ . '/checker.php';
    $inventory = [];
    foreach (device_inventory()['devices'] as $d) if ($d['ip'] && $d['hostId']) $inventory[(int) $d['hostId']] = $d;
    foreach ($subnets as $subnet) {
        $prefix = substr($subnet, 0, -4);
        $q = $pdo->prepare('SELECT id, name, ip_address, device, auto_named FROM hosts WHERE ip_address LIKE ?');
        $q->execute([$prefix . '%']);
        $cards = $q->fetchAll();
        $planned = [];
        foreach ($cards as $c) {
            if (!$c['auto_named'] && $c['name'] !== $c['ip_address']) continue;   // a person named it
            $d = $inventory[(int) $c['id']] ?? null;
            if (!$d) continue;
            if ($c['auto_named'] && nothing_known($d)) {
                $pdo->prepare("UPDATE hosts SET name = ip_address, device = 'server', auto_named = 0 WHERE id = ?")->execute([$c['id']]);
                continue;
            }
            // Nothing known beyond the card's default "server" type: no name is better than a made-up one.
            if (nothing_known($d)) continue;
            $planned[(int) $c['id']] = ['name' => useful_device_name($d), 'type' => $c['device'] === 'server' || $c['auto_named'] ? card_type_for($d['category']) : $c['device'],
                                       'ip' => $c['ip_address'], 'old' => $c['name']];
        }
        // Names used more than once in the subnet (also by cards named by hand) get ".<last number>".
        $count = array_count_values(array_map('strtolower', array_merge(array_column($planned, 'name'),
            array_map(fn($c) => $c['name'], array_filter($cards, fn($c) => !isset($planned[(int) $c['id']]))))));
        foreach ($planned as $id => $p) {
            $name = $count[strtolower($p['name'])] > 1 ? $p['name'] . ' .' . substr(strrchr($p['ip'], '.'), 1) : $p['name'];
            if ($name === $p['old'] && $p['type'] === null) continue;
            $pdo->prepare('UPDATE hosts SET name = ?, device = ?, auto_named = 1 WHERE id = ?')->execute([mb_substr($name, 0, 60), $p['type'], $id]);
        }
    }
}

/**
 * "Smart-home device on Wifi — belongs on IoT": a device whose kind has a network of
 * its own (by subnet name) but that's on a different one. Null when fine or unknown.
 */
function wrong_network_note(array $d, array $subnetNames): ?string {
    $re = EXPECTED_NETWORK[$d['category']] ?? null;
    if (!$re || empty($d['ip']) || ($d['where']['kind'] ?? '') === 'vm' || $d['confidence'] === 'low') return null;
    $homes = array_filter($subnetNames, fn($n) => preg_match($re, $n));
    if (!$homes || isset($homes[$d['subnet']])) return null;
    $here = $d['subnetName'] ?: $d['subnet'];
    $kind = strtolower(DEVICE_CATEGORIES[$d['category']][1]);
    return "$kind on the $here network — belongs on " . implode(' / ', array_unique($homes));
}
