<?php
require_once __DIR__ . '/configbackup.php';   // encrypt_secret(), decrypt_secret()

/**
 * Home Assistant: what it knows about each device (the name you gave it, maker,
 * model, firmware, room, and what it does), read hourly over HA's WebSocket API
 * with a long-lived access token. Covers every brand HA is linked to (Tuya,
 * Google, Alexa, Hue, ...), so devices that answer no scan still get identified.
 *
 * settings 'homeassistant': {"url", "token_enc"}
 * settings 'ha_devices':    {"at", "error", "devices": [{name, maker, model, firmware, area, macs[], ips[], kinds[]}]}
 */

const HA_REFRESH_EVERY_SEC = 3600;
const HA_TIMEOUT_SEC = 20;
// Entity domains that say what a device is.
const HA_KIND_DOMAINS = ['camera', 'media_player', 'vacuum', 'climate', 'light', 'switch', 'fan', 'cover', 'lock', 'alarm_control_panel',
    'water_heater', 'humidifier', 'lawn_mower', 'remote', 'sensor', 'binary_sensor'];

function ha_config(): array {
    $c = json_decode((string) db()->query("SELECT v FROM settings WHERE k = 'homeassistant'")->fetchColumn(), true);
    return is_array($c) ? $c : [];
}

function ha_save_config(array $c): void {
    db()->prepare("INSERT INTO settings (k, v) VALUES ('homeassistant', ?) ON DUPLICATE KEY UPDATE v = VALUES(v)")->execute([json_encode($c)]);
}

/** "http://192.168.40.7:8123" (scheme, host, optional port; no path) or null. */
function ha_normalize_url(string $url): ?string {
    $url = rtrim(trim($url), '/');
    $p = parse_url($url);
    if (!$p || !in_array($p['scheme'] ?? '', ['http', 'https'], true) || empty($p['host'])) return null;
    if (isset($p['user']) || isset($p['pass']) || isset($p['query']) || isset($p['fragment']) || (($p['path'] ?? '') !== '')) return null;
    if (!preg_match('/^[A-Za-z0-9.\-]+$|^\[[0-9a-fA-F:]+\]$/', $p['host'])) return null;
    return $p['scheme'] . '://' . $p['host'] . (isset($p['port']) ? ':' . (int) $p['port'] : '');
}

/**
 * Minimal WebSocket client for HA: authenticates, sends each request (given an
 * id) and returns [id => result]. Throws RuntimeException with a readable message.
 */
function ha_ws_requests(string $url, string $token, array $requests): array {
    $p = parse_url($url);
    $tls = $p['scheme'] === 'https';
    $host = $p['host'];
    $port = (int) ($p['port'] ?? ($tls ? 443 : 80));
    $ctx = stream_context_create(['ssl' => ['verify_peer' => false, 'verify_peer_name' => false, 'SNI_enabled' => true, 'peer_name' => trim($host, '[]')]]);
    $s = @stream_socket_client(($tls ? 'ssl' : 'tcp') . "://$host:$port", $errno, $err, 5, STREAM_CLIENT_CONNECT, $ctx);
    if (!$s) throw new RuntimeException("cannot connect to $host:$port ($err)");
    $deadline = microtime(true) + HA_TIMEOUT_SEC;
    $buf = '';
    $read = function (int $n) use ($s, &$buf, $deadline): string {
        while (strlen($buf) < $n) {
            if (microtime(true) > $deadline) throw new RuntimeException('Home Assistant took too long to answer');
            stream_set_timeout($s, max(1, (int) ceil($deadline - microtime(true))));
            $chunk = fread($s, 65536);
            if ($chunk === false || ($chunk === '' && feof($s))) throw new RuntimeException('Home Assistant closed the connection');
            $buf .= $chunk;
        }
        $out = substr($buf, 0, $n);
        $buf = (string) substr($buf, $n);
        return $out;
    };
    $send = function (int $opcode, string $payload) use ($s): void {
        $len = strlen($payload);
        $head = chr(0x80 | $opcode) . ($len < 126 ? chr(0x80 | $len) : ($len < 65536 ? chr(0x80 | 126) . pack('n', $len) : chr(0x80 | 127) . pack('J', $len)));
        $mask = random_bytes(4);
        fwrite($s, $head . $mask . ($payload ^ str_repeat($mask, intdiv($len, 4) + 1)));
    };
    // One whole message (joins fragments, answers pings).
    $recv = function () use ($read, $send): array {
        $msg = '';
        while (true) {
            [$b0, $b1] = array_values(unpack('C2', $read(2)));
            $op = $b0 & 0x0F;
            $len = $b1 & 0x7F;
            if ($len === 126) $len = unpack('n', $read(2))[1];
            elseif ($len === 127) $len = unpack('J', $read(8))[1];
            if ($len > 50 * 1024 * 1024) throw new RuntimeException('Home Assistant sent an oversized message');
            $mask = ($b1 & 0x80) ? $read(4) : null;
            $data = $len ? $read($len) : '';
            if ($mask) $data ^= str_repeat($mask, intdiv($len, 4) + 1);
            if ($op === 0x8) throw new RuntimeException('Home Assistant closed the connection');
            if ($op === 0x9) { $send(0xA, $data); continue; }
            if ($op === 0xA) continue;
            $msg .= $data;
            if ($b0 & 0x80) break;
        }
        $j = json_decode($msg, true);
        if (!is_array($j)) throw new RuntimeException('Home Assistant sent something that is not JSON');
        return $j;
    };

    try {
        $key = base64_encode(random_bytes(16));
        $hostHeader = $host . ((!$tls && $port !== 80) || ($tls && $port !== 443) ? ":$port" : '');
        fwrite($s, "GET /api/websocket HTTP/1.1\r\nHost: $hostHeader\r\nUpgrade: websocket\r\nConnection: Upgrade\r\n"
            . "Sec-WebSocket-Key: $key\r\nSec-WebSocket-Version: 13\r\nUser-Agent: WebMonitor\r\n\r\n");
        $raw = '';
        while (!str_contains($raw, "\r\n\r\n")) {
            $raw .= $read(1);
            if (strlen($raw) > 16384) throw new RuntimeException('not a Home Assistant WebSocket endpoint');
        }
        [$headers, $rest] = explode("\r\n\r\n", $raw, 2);
        $buf = $rest . $buf;
        if (!preg_match('#^HTTP/1\.[01] 101#', $headers)) {
            throw new RuntimeException('not a Home Assistant WebSocket endpoint (' . strtok($headers, "\r\n") . ')');
        }
        if (($recv()['type'] ?? '') !== 'auth_required') throw new RuntimeException('unexpected greeting from Home Assistant');
        $send(0x1, json_encode(['type' => 'auth', 'access_token' => $token]));
        $auth = $recv();
        if (($auth['type'] ?? '') !== 'auth_ok') throw new RuntimeException('Home Assistant refused the access token');
        foreach ($requests as $id => $req) $send(0x1, json_encode(['id' => $id] + $req));
        $results = [];
        while (count($results) < count($requests)) {
            $m = $recv();
            if (($m['type'] ?? '') !== 'result' || !isset($requests[$m['id'] ?? null])) continue;
            if (empty($m['success'])) throw new RuntimeException('Home Assistant: ' . ($m['error']['message'] ?? 'request failed'));
            $results[$m['id']] = $m['result'];
        }
        $send(0x8, '');
        return $results;
    } finally {
        fclose($s);
    }
}

/** HA's registries -> WebMonitor's device list. Pure (tested in deploy/test-device-identity.php). */
function ha_build_devices(array $devices, array $areas, array $entities, array $states): array {
    $areaName = array_column($areas, 'name', 'area_id');
    $stateBy = [];
    $stateVal = [];
    foreach ($states as $st) {
        $stateBy[$st['entity_id'] ?? ''] = $st['attributes'] ?? [];
        $stateVal[$st['entity_id'] ?? ''] = (string) ($st['state'] ?? '');
    }
    $kinds = $ips = $entityArea = [];
    foreach ($entities as $e) {
        $dev = $e['device_id'] ?? null;
        if (!$dev || !empty($e['disabled_by'])) continue;
        $domain = strtok((string) ($e['entity_id'] ?? ''), '.');
        if (in_array($domain, HA_KIND_DOMAINS, true)) $kinds[$dev][$domain] = true;
        foreach (['ip_address', 'ip', 'host', 'ipv4_address'] as $k) {
            $v = $stateBy[$e['entity_id']][$k] ?? null;
            if (is_string($v) && filter_var($v, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) $ips[$dev][$v] = true;
        }
        // A sensor whose state is the device's IP (e.g. "sensor.plug_ip").
        $val = $stateVal[$e['entity_id']] ?? '';
        if ($domain === 'sensor' && preg_match('/_ip(_address)?$/', (string) $e['entity_id']) && filter_var($val, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $ips[$dev][$val] = true;
        }
        if (!empty($e['area_id'])) $entityArea[$dev] ??= $e['area_id'];
    }
    $out = [];
    foreach ($devices as $d) {
        if (!empty($d['disabled_by']) || ($d['entry_type'] ?? null) === 'service') continue;
        $id = (string) ($d['id'] ?? '');
        $macs = [];
        foreach ((array) ($d['connections'] ?? []) as $c) {
            if (is_array($c) && ($c[0] ?? '') === 'mac' && ($m = normalize_mac((string) ($c[1] ?? '')))) $macs[] = $m;
        }
        $devIps = array_keys($ips[$id] ?? []);
        if (!empty($d['configuration_url']) && ($h = parse_url((string) $d['configuration_url'], PHP_URL_HOST)) && filter_var($h, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $devIps[] = $h;
        }
        $name = trim((string) ($d['name_by_user'] ?? '')) ?: trim((string) ($d['name'] ?? ''));
        if ($name === '') continue;
        $area = $d['area_id'] ?? $entityArea[$id] ?? null;
        $out[] = array_filter([
            'name' => mb_substr($name, 0, 80), 'userNamed' => !empty($d['name_by_user']),
            'maker' => $d['manufacturer'] ?? null, 'model' => $d['model'] ?? null, 'firmware' => $d['sw_version'] ?? null,
            'area' => $area ? ($areaName[$area] ?? null) : null,
            'macs' => array_values(array_unique($macs)), 'ips' => array_values(array_unique($devIps)),
            'kinds' => array_keys($kinds[$id] ?? []),
        ], fn($v) => $v !== null && $v !== '' && $v !== []);
    }
    return $out;
}

/** Hourly from cli/poll-snmp.php ($force: now, from Settings). Returns the error or null. */
function refresh_ha_devices(bool $force = false): ?string {
    require_once __DIR__ . '/portmap.php';   // normalize_mac()
    $cfg = ha_config();
    $token = decrypt_secret($cfg['token_enc'] ?? null);
    if (empty($cfg['url']) || $token === null) return null;
    $stored = ha_devices_stored();
    if (!$force && (int) ($stored['at'] ?? 0) > now_ms_cfg() - HA_REFRESH_EVERY_SEC * 1000) return $stored['error'] ?? null;
    $error = null;
    try {
        $r = ha_ws_requests($cfg['url'], $token, [
            1 => ['type' => 'config/device_registry/list'], 2 => ['type' => 'config/area_registry/list'],
            3 => ['type' => 'config/entity_registry/list'], 4 => ['type' => 'get_states'],
        ]);
        $devices = ha_build_devices((array) $r[1], (array) $r[2], (array) $r[3], (array) $r[4]);
    } catch (Throwable $e) {
        $error = $e->getMessage();
        $devices = $stored['devices'] ?? [];   // keep the last good list
    }
    db()->prepare("INSERT INTO settings (k, v) VALUES ('ha_devices', ?) ON DUPLICATE KEY UPDATE v = VALUES(v)")
        ->execute([json_encode(['at' => now_ms_cfg(), 'error' => $error, 'devices' => $devices])]);
    return $error;
}

function ha_devices_stored(): array {
    $d = json_decode((string) db()->query("SELECT v FROM settings WHERE k = 'ha_devices'")->fetchColumn(), true);
    return is_array($d) ? $d : [];
}

/** Lookups for device_inventory(): [byMac, byIp, byName (lower-case, unique names only), all]; each device has its index as _i. */
function ha_device_index(): array {
    $byMac = $byIp = $byName = $dupe = $all = [];
    foreach (ha_devices_stored()['devices'] ?? [] as $i => $d) {
        $d['_i'] = $i;
        $all[] = $d;
        foreach ($d['macs'] ?? [] as $m) $byMac[$m] = $d;
        foreach ($d['ips'] ?? [] as $ip) $byIp[$ip] = $d;
        $n = mb_strtolower($d['name']);
        if (isset($byName[$n])) $dupe[$n] = true;
        $byName[$n] = $d;
    }
    return [$byMac, $byIp, array_diff_key($byName, $dupe), $all];
}
