<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/scanner.php';   // snmp_probe()
require_once __DIR__ . '/scanqueue.php';

/**
 * Deep device scan, Fing-style: asks a device directly what it is.
 *   - TCP ports (common services and smart-home/TV/console ports), with
 *     web page titles and server banners;
 *   - UPnP/SSDP description (friendly name, maker, model, serial);
 *   - brand info pages: Roku, Google Cast, Shelly, Tasmota, Philips Hue;
 *   - NetBIOS name (Windows, NAS), SNMP sysDescr (printers, gear).
 * Takes a few seconds; runs in the background (cron for "Scan now",
 * poll-snmp.php for a daily refresh of every device).
 */

const SCAN_PORTS = [
    21 => 'FTP', 22 => 'SSH', 23 => 'Telnet', 53 => 'DNS', 80 => 'Web', 81 => 'Web', 139 => 'NetBIOS', 443 => 'Web (HTTPS)', 445 => 'File sharing (SMB)',
    515 => 'Printer (LPD)', 548 => 'AFP (Apple file sharing)', 554 => 'Video stream (RTSP)', 631 => 'Printer (IPP)', 1400 => 'Sonos',
    1883 => 'MQTT', 1925 => 'Philips TV', 3000 => 'Web app', 3001 => 'LG webOS TV', 3389 => 'Remote Desktop', 3689 => 'iTunes / DAAP',
    4343 => 'Aruba Instant', 5000 => 'Web (UPnP / Synology)', 5001 => 'Web (Synology HTTPS)', 5555 => 'Android debug (ADB)', 5900 => 'VNC',
    6466 => 'Android TV remote', 6467 => 'Android TV remote', 6668 => 'Tuya', 7000 => 'AirPlay', 7100 => 'AirPlay', 8000 => 'Web',
    8001 => 'Samsung TV', 8002 => 'Samsung TV', 8008 => 'Google Cast', 8009 => 'Google Cast', 8060 => 'Roku', 8080 => 'Web', 8081 => 'Web',
    8443 => 'Web (HTTPS)', 8888 => 'Web', 9000 => 'Web / Cast', 9100 => 'Printer (raw)', 9999 => 'TP-Link Kasa', 32400 => 'Plex',
    49152 => 'UPnP', 49153 => 'UPnP', 55443 => 'Yeelight', 62078 => 'Apple device sync',
];
// Card types whose Details show the scanned device info (and get scanned when set).
const DEVICE_SCAN_TYPES = ['iot', 'console', 'tv', 'camera'];
const SCAN_WEB_PORTS = [80, 81, 443, 3000, 5000, 5001, 8000, 8080, 8081, 8443, 8888, 4343];
const SCAN_TLS_PORTS = [443, 5001, 8443, 4343];

/** Open TCP ports, all tried at once (one shared timeout). */
function scan_tcp_ports(string $ip, array $ports, float $timeout = 1.5): array {
    $socks = [];
    foreach ($ports as $p) {
        $s = @stream_socket_client("tcp://$ip:$p", $errno, $err, $timeout, STREAM_CLIENT_ASYNC_CONNECT | STREAM_CLIENT_CONNECT);
        if ($s) { stream_set_blocking($s, false); $socks[$p] = $s; }
    }
    $open = [];
    $deadline = microtime(true) + $timeout;
    while ($socks && microtime(true) < $deadline) {
        $w = array_values($socks);
        $r = $e = null;
        if (@stream_select($r, $w, $e, 0, 200000) === false) break;
        foreach ($w as $s) {
            $p = array_search($s, $socks, true);
            if (@stream_socket_get_name($s, true) !== false) $open[] = $p;   // connected
            fclose($s);
            unset($socks[$p]);
        }
    }
    foreach ($socks as $s) fclose($s);
    sort($open);
    return $open;
}

/** Small HTTP GET: [status, headers, body] or null. */
function scan_http(string $url, int $timeout = 3): ?array {
    $ch = curl_init($url);
    $headers = [];
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 2,
        CURLOPT_CONNECTTIMEOUT => 2, CURLOPT_TIMEOUT => $timeout, CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => 0,
        CURLOPT_USERAGENT => 'WebMonitor device scan', CURLOPT_RANGE => '0-65535',
        CURLOPT_HEADERFUNCTION => function ($c, $h) use (&$headers) {
            if (str_contains($h, ':')) { [$k, $v] = explode(':', $h, 2); $headers[strtolower(trim($k))] = trim($v); }
            return strlen($h);
        },
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if ($body === false || !$code) return null;
    return [$code, $headers, substr((string) $body, 0, 65536)];
}

/** UPnP: unicast M-SEARCH, then the device description of the first answer. */
function scan_ssdp(string $ip): ?array {
    $s = @stream_socket_client("udp://$ip:1900", $errno, $err, 1);
    if (!$s) return null;
    fwrite($s, "M-SEARCH * HTTP/1.1\r\nHOST: $ip:1900\r\nMAN: \"ssdp:discover\"\r\nMX: 1\r\nST: upnp:rootdevice\r\n\r\n");
    stream_set_timeout($s, 2);
    $reply = (string) fread($s, 4096);
    fclose($s);
    if (!preg_match('/^location:\s*(\S+)/mi', $reply, $m)) return null;
    $out = ['location' => $m[1], 'server' => preg_match('/^server:\s*(.+)$/mi', $reply, $sv) ? trim($sv[1]) : null];
    $r = scan_http($m[1]);
    if ($r && $r[0] < 400 && ($x = @simplexml_load_string($r[2]))) {
        $x->registerXPathNamespace('u', 'urn:schemas-upnp-org:device-1-0');
        $dev = $x->xpath('//u:device')[0] ?? $x->device ?? null;
        if ($dev) {
            foreach (['friendlyName', 'manufacturer', 'modelName', 'modelNumber', 'modelDescription', 'serialNumber', 'deviceType'] as $k) {
                $v = trim((string) ($dev->$k ?? ''));
                if ($v !== '') $out[$k] = mb_substr($v, 0, 120);
            }
        }
    }
    return $out;
}

/** NetBIOS node status (UDP 137): the machine's name and workgroup. */
function scan_netbios(string $ip): ?array {
    $s = @stream_socket_client("udp://$ip:137", $errno, $err, 1);
    if (!$s) return null;
    $name = "\x20" . 'CK' . str_repeat('A', 30) . "\x00";   // "*" padded, first-level encoded
    fwrite($s, pack('nnnnnn', random_int(1, 65535), 0, 1, 0, 0, 0) . $name . pack('nn', 0x21, 1));
    stream_set_timeout($s, 1);
    $r = (string) fread($s, 2048);
    fclose($s);
    if (strlen($r) < 57) return null;
    $count = ord($r[56]);
    $names = [];
    for ($i = 0; $i < $count && 57 + $i * 18 + 18 <= strlen($r); $i++) {
        $entry = substr($r, 57 + $i * 18, 18);
        $nm = rtrim(substr($entry, 0, 15));
        $type = ord($entry[15]);
        $group = (ord($entry[16]) & 0x80) !== 0;
        if ($nm !== '' && ctype_print($nm)) $names[] = ['name' => $nm, 'type' => $type, 'group' => $group];
    }
    $host = array_values(array_filter($names, fn($n) => !$n['group'] && $n['type'] === 0x00))[0]['name'] ?? null;
    $wg = array_values(array_filter($names, fn($n) => $n['group'] && $n['type'] === 0x00))[0]['name'] ?? null;
    return $host || $wg ? ['name' => $host, 'workgroup' => $wg] : null;
}

/** A DNS name in wire format. */
function dns_wire_name(string $name): string {
    $out = '';
    foreach (explode('.', rtrim($name, '.')) as $label) $out .= chr(strlen($label)) . $label;
    return $out . "\0";
}

/** Reads a (possibly compressed) DNS name at $off; moves $off past it. */
function dns_read_name(string $p, int &$off): string {
    $labels = [];
    $pos = $off;
    $jumped = false;
    for ($hops = 0; $pos < strlen($p) && $hops < 20;) {
        $len = ord($p[$pos]);
        if ($len === 0) { $pos++; break; }
        if (($len & 0xC0) === 0xC0) {
            if ($pos + 1 >= strlen($p)) break;
            if (!$jumped) $off = $pos + 2;
            $jumped = true;
            $pos = (($len & 0x3F) << 8) | ord($p[$pos + 1]);
            $hops++;
            continue;
        }
        $labels[] = substr($p, $pos + 1, $len);
        $pos += $len + 1;
    }
    if (!$jumped) $off = $pos;
    return implode('.', $labels);
}

/** One unicast mDNS question (RFC 6762 "legacy unicast"): [[type, name, value], ...]. */
function mdns_query(string $ip, string $qname, int $qtype): array {
    $s = @stream_socket_client("udp://$ip:5353", $errno, $err, 1);
    if (!$s) return [];
    fwrite($s, pack('nnnnnn', random_int(1, 65535), 0, 1, 0, 0, 0) . dns_wire_name($qname) . pack('nn', $qtype, 1));
    stream_set_timeout($s, 1);
    $r = (string) fread($s, 9000);
    fclose($s);
    if (strlen($r) < 12) return [];
    $h = unpack('nid/nflags/nqd/nan/nns/nar', $r);
    $off = 12;
    for ($i = 0; $i < $h['qd']; $i++) { dns_read_name($r, $off); $off += 4; }
    $out = [];
    for ($i = 0; $i < $h['an'] + $h['ns'] + $h['ar'] && $off + 10 <= strlen($r); $i++) {
        $name = dns_read_name($r, $off);
        $t = unpack('ntype/nclass/Nttl/nlen', substr($r, $off, 10));
        $off += 10;
        $start = $off;
        $data = substr($r, $off, $t['len']);
        $off += $t['len'];
        if ($t['type'] === 12) {
            $o = $start;
            $out[] = ['PTR', $name, dns_read_name($r, $o)];
        } elseif ($t['type'] === 16) {
            $txt = [];
            for ($j = 0; $j < strlen($data);) { $l = ord($data[$j]); $txt[] = substr($data, $j + 1, $l); $j += $l + 1; }
            $out[] = ['TXT', $name, $txt];
        }
    }
    return $out;
}

/**
 * mDNS / Bonjour: the device's .local name, the services it advertises and, for
 * Apple gear, its model. Only answered on the same subnet as this server.
 */
function scan_mdns(string $ip): ?array {
    $reverse = implode('.', array_reverse(explode('.', $ip))) . '.in-addr.arpa';
    $out = [];
    foreach (mdns_query($ip, $reverse, 12) as [$type, , $value]) {
        if ($type === 'PTR' && str_ends_with($value, '.local')) { $out['hostname'] = mb_substr(substr($value, 0, -6), 0, 80); break; }
    }
    if (!$out) return null;   // not answering mDNS: don't ask twice more
    $services = [];
    foreach (mdns_query($ip, '_services._dns-sd._udp.local', 12) as [$type, , $value]) {
        if ($type === 'PTR' && preg_match('/^(_[a-z0-9-]+)\._(tcp|udp)\.local$/i', $value, $m)) $services[] = strtolower($m[1]);
    }
    if ($services) $out['services'] = array_values(array_unique($services));
    foreach (mdns_query($ip, $out['hostname'] . '._device-info._tcp.local', 16) as [$type, , $txt]) {
        foreach ($type === 'TXT' ? $txt : [] as $kv) if (str_starts_with($kv, 'model=')) $out['model'] = mb_substr(substr($kv, 6), 0, 60);
    }
    return $out;
}

/** Brand info pages that say exactly what the device is. */
function scan_brand_apis(string $ip, array $open): array {
    $out = [];
    if (in_array(8060, $open, true) && ($r = scan_http("http://$ip:8060/query/device-info")) && ($x = @simplexml_load_string($r[2]))) {
        $out['roku'] = array_filter(['name' => (string) $x->{'user-device-name'} ?: (string) $x->{'friendly-device-name'},
            'model' => trim((string) $x->{'model-name'} . ' ' . (string) $x->{'model-number'}), 'serial' => (string) $x->{'serial-number'},
            'firmware' => (string) $x->{'software-version'}, 'maker' => (string) $x->{'vendor-name'} ?: 'Roku']);
    }
    if (in_array(8008, $open, true) && ($r = scan_http("http://$ip:8008/setup/eureka_info?params=name,device_info,build_info")) && ($j = json_decode($r[2], true))) {
        $out['cast'] = array_filter(['name' => $j['name'] ?? null, 'model' => $j['device_info']['model_name'] ?? null,
            'maker' => $j['device_info']['manufacturer'] ?? null, 'firmware' => $j['build_info']['cast_build_revision'] ?? null]);
    }
    if (in_array(80, $open, true)) {
        if (($r = scan_http("http://$ip/shelly", 2)) && ($j = json_decode($r[2], true)) && (isset($j['type']) || isset($j['model']))) {
            $out['shelly'] = array_filter(['model' => $j['model'] ?? $j['type'] ?? null, 'name' => $j['name'] ?? null,
                'firmware' => $j['fw'] ?? $j['ver'] ?? null, 'maker' => 'Shelly']);
        } elseif (($r = scan_http("http://$ip/cm?cmnd=Status%200", 2)) && ($j = json_decode($r[2], true)) && isset($j['StatusFWR'])) {
            $out['tasmota'] = array_filter(['name' => $j['Status']['DeviceName'] ?? $j['Status']['FriendlyName'][0] ?? null,
                'model' => 'Tasmota ' . ($j['StatusFWR']['Hardware'] ?? ''), 'firmware' => $j['StatusFWR']['Version'] ?? null, 'maker' => 'Tasmota']);
        } elseif (($r = scan_http("http://$ip/api/config", 2)) && ($j = json_decode($r[2], true)) && isset($j['bridgeid'])) {
            $out['hue'] = array_filter(['name' => $j['name'] ?? null, 'model' => 'Hue bridge ' . ($j['modelid'] ?? ''),
                'firmware' => $j['swversion'] ?? null, 'maker' => 'Philips Hue']);
        }
    }
    return $out;
}

/** Title and server banner of a web page. */
function scan_web(string $ip, int $port): ?array {
    $scheme = in_array($port, SCAN_TLS_PORTS, true) ? 'https' : 'http';
    $r = scan_http("$scheme://$ip:$port/", 3);
    if (!$r) return null;
    $title = preg_match('/<title[^>]*>(.*?)<\/title>/is', $r[2], $m) ? trim(html_entity_decode(strip_tags($m[1]), ENT_QUOTES)) : null;
    return array_filter(['status' => $r[0], 'title' => $title ? mb_substr(preg_replace('/\s+/', ' ', $title), 0, 100) : null,
        'server' => isset($r[1]['server']) ? mb_substr($r[1]['server'], 0, 100) : null]);
}

function scan_ssh_banner(string $ip): ?string {
    $s = @stream_socket_client("tcp://$ip:22", $errno, $err, 2);
    if (!$s) return null;
    stream_set_timeout($s, 2);
    $line = trim((string) fgets($s, 256));
    fclose($s);
    return str_starts_with($line, 'SSH-') ? mb_substr($line, 0, 100) : null;
}

/** SNMP communities to try on scanned devices: the ones set on host cards (most used first), then "public". */
function scan_snmp_communities(): array {
    static $list = null;
    if ($list === null) {
        $list = db()->query("SELECT community FROM hosts WHERE community <> '' GROUP BY community ORDER BY COUNT(*) DESC LIMIT 4")->fetchAll(PDO::FETCH_COLUMN);
        $list = array_values(array_unique(array_merge($list, ['public'])));
    }
    return $list;
}

/** Scans one device; returns the result stored in device_scans.data. */
function scan_device(string $ip): array {
    $t = microtime(true);
    $open = scan_tcp_ports($ip, array_keys(SCAN_PORTS));
    $out = ['ip' => $ip, 'ports' => array_map(fn($p) => ['port' => $p, 'service' => SCAN_PORTS[$p] ?? null], $open)];
    $web = [];
    foreach (array_intersect($open, SCAN_WEB_PORTS) as $p) if ($w = scan_web($ip, $p)) $web[$p] = $w;
    if ($web) $out['web'] = $web;
    if (in_array(22, $open, true) && ($b = scan_ssh_banner($ip))) $out['ssh'] = $b;
    if ($u = scan_ssdp($ip)) $out['upnp'] = $u;
    if ($nb = scan_netbios($ip)) $out['netbios'] = $nb;
    if ($md = scan_mdns($ip)) $out['mdns'] = $md;
    if ($api = scan_brand_apis($ip, $open)) $out['brand'] = $api;
    foreach (scan_snmp_communities() as $community) {
        if ($snmp = snmp_probe($ip, $community, 0.8)) { $out['snmp'] = array_filter(['descr' => mb_substr((string) $snmp['sysDescr'], 0, 200), 'name' => $snmp['sysName']]); break; }
    }
    $out['summary'] = scan_summary($out);
    $out['ms'] = (int) round((microtime(true) - $t) * 1000);
    return $out;
}

/** The best name/maker/model/firmware from everything the device said. */
function scan_summary(array $s): array {
    $sum = [];
    foreach ($s['brand'] ?? [] as $b) foreach (['name', 'maker', 'model', 'serial', 'firmware'] as $k) if (!empty($b[$k])) $sum[$k] ??= $b[$k];
    $u = $s['upnp'] ?? [];
    $sum['name'] ??= $u['friendlyName'] ?? null;
    $sum['maker'] ??= $u['manufacturer'] ?? null;
    if (!isset($sum['model']) && !empty($u['modelName'])) $sum['model'] = trim($u['modelName'] . (!empty($u['modelNumber']) && !str_contains($u['modelName'], $u['modelNumber']) ? ' ' . $u['modelNumber'] : ''));
    $sum['serial'] ??= $u['serialNumber'] ?? null;
    $sum['name'] ??= $s['mdns']['hostname'] ?? $s['netbios']['name'] ?? $s['snmp']['name'] ?? null;
    if (!isset($sum['model']) && !empty($s['mdns']['model'])) $sum['model'] = $s['mdns']['model'];
    if (!empty($s['mdns']['services'])) $sum['services'] = $s['mdns']['services'];
    if (!isset($sum['model']) && !empty($s['snmp']['descr'])) $sum['model'] = mb_substr(strtok($s['snmp']['descr'], "\r\n"), 0, 80);
    if (!isset($sum['firmware']) && !empty($u['server']) && preg_match('/([A-Za-z]+)\/([\d.]+)/', $u['server'], $m)) $sum['firmware'] = "{$m[1]} {$m[2]}";
    foreach ($s['web'] ?? [] as $w) { if (!empty($w['title'])) { $sum['webTitle'] = $w['title']; break; } }
    return array_filter($sum, fn($v) => $v !== null && $v !== '');
}

function device_scan_key(?string $mac, ?string $ip): string {
    return $mac ?: "ip:$ip";
}

/** Asks for a scan of a device (runs within a minute). */
function request_device_scan(?string $mac, string $ip, bool $priority = true): void {
    $now = (int) round(microtime(true) * 1000);
    db()->prepare('INSERT INTO device_scans (dkey, ip, mac, requested_at, priority_at) VALUES (?, ?, ?, ?, ?)
                   ON DUPLICATE KEY UPDATE ip = VALUES(ip),
                     priority_at = IF(VALUES(priority_at) IS NULL, priority_at, VALUES(priority_at)),
                     requested_at = GREATEST(COALESCE(requested_at, 0) + 1, VALUES(requested_at))')
        ->execute([device_scan_key($mac, $ip), $ip, $mac, $now, $priority ? next_scan_priority() : null]);
}

function save_device_scan(?string $mac, string $ip, array $data, ?int $requestAt): void {
    db()->prepare('INSERT INTO device_scans (dkey, ip, mac, scanned_at, data) VALUES (?, ?, ?, ?, ?)
                   ON DUPLICATE KEY UPDATE ip = IF(requested_at <=> ?, VALUES(ip), ip), scanned_at = VALUES(scanned_at), data = VALUES(data),
                     priority_at = IF(requested_at <=> ?, NULL, priority_at),
                     requested_at = IF(requested_at <=> ?, NULL, requested_at)')
        ->execute([device_scan_key($mac, $ip), $ip, $mac, (int) round(microtime(true) * 1000), json_encode($data), $requestAt, $requestAt, $requestAt]);
    // A card known only by its address takes the name the device gave itself.
    if (!empty($data['summary']['name'])) {
        $card = db()->prepare('SELECT id FROM hosts WHERE ip_address = ?');
        $card->execute([$ip]);
        if ($id = $card->fetchColumn()) adopt_host_name((int) $id, $data['summary']['name']);
    }
}

/** "Scan now" requests (cli/cron.php, every minute; a few per run). */
function run_requested_scans(int $max = 4): void {
    $pdo = db();
    if (!(int) $pdo->query("SELECT GET_LOCK('webmonitor_device_scan', 0)")->fetchColumn()) return;
    try {
        for ($i = 0; $i < $max; $i++) {
            $r = $pdo->query('SELECT dkey, ip, mac, requested_at FROM device_scans WHERE requested_at IS NOT NULL
                              ORDER BY priority_at DESC, requested_at, dkey LIMIT 1')->fetch();
            if (!$r) break;
            scan_activity_start('webmonitor_device_scan', 'device', $r['ip']);
            try {
                save_device_scan($r['mac'], $r['ip'], scan_device($r['ip']), (int) $r['requested_at']);
            } finally {
                scan_activity_end('webmonitor_device_scan');
            }
        }
    } finally {
        $pdo->query("SELECT RELEASE_LOCK('webmonitor_device_scan')");
    }
}

/** Devices eligible for the daily refresh, oldest scan first. */
function due_device_scans(): array {
    require_once __DIR__ . '/devices.php';
    require_once __DIR__ . '/checker.php';
    $known = [];
    foreach (db()->query('SELECT dkey, scanned_at, requested_at FROM device_scans') as $r) $known[$r['dkey']] = $r;
    $due = [];
    foreach (device_inventory()['devices'] as $d) {
        if (!$d['ip'] || in_array($d['where']['kind'] ?? '', ['network'], true)) continue;
        $key = device_scan_key($d['mac'], $d['ip']);
        $row = $known[$key] ?? null;
        $at = (int) ($row['scanned_at'] ?? 0);
        if ($at < (time() - 86400) * 1000 && empty($row['requested_at'])) $due[] = [$at, $d['mac'], $d['ip'], $d['name']];
    }
    sort($due);
    return $due;
}

/** Daily refresh: scans devices not scanned for a day (cli/poll-snmp.php, a few per run). */
function refresh_device_scans(int $max = 4): void {
    $pdo = db();
    if (!(int) $pdo->query("SELECT GET_LOCK('webmonitor_device_scan', 0)")->fetchColumn()) return;
    try {
        foreach (array_slice(due_device_scans(), 0, $max) as [, $mac, $ip]) {
            if ($pdo->query('SELECT 1 FROM device_scans WHERE requested_at IS NOT NULL LIMIT 1')->fetchColumn()) break;
            scan_activity_start('webmonitor_device_scan', 'device', $ip);
            try {
                save_device_scan($mac, $ip, scan_device($ip), null);
            } finally {
                scan_activity_end('webmonitor_device_scan');
            }
        }
    } finally {
        $pdo->query("SELECT RELEASE_LOCK('webmonitor_device_scan')");
    }
}

/** [dkey => summary + scannedAt] for device identification. */
function device_scan_summaries(): array {
    $out = [];
    foreach (db()->query('SELECT dkey, ip, scanned_at, data FROM device_scans WHERE data IS NOT NULL') as $r) {
        $d = json_decode($r['data'], true);
        $out[$r['dkey']] = ($d['summary'] ?? []) + ['scannedAt' => (int) $r['scanned_at'], 'ports' => count($d['ports'] ?? [])];
    }
    return $out;
}

/** [ip => {model, maker, firmware, name}] of the newest scan per address, for the cards. */
function device_card_summaries(): array {
    $out = [];
    foreach (db()->query('SELECT ip, data FROM device_scans WHERE data IS NOT NULL AND ip IS NOT NULL ORDER BY scanned_at') as $r) {
        $s = json_decode($r['data'], true)['summary'] ?? [];
        if ($s) $out[$r['ip']] = array_intersect_key($s, array_flip(['model', 'maker', 'firmware', 'name']));
    }
    return $out;
}
