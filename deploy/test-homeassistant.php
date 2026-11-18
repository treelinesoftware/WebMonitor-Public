<?php
// Home Assistant link without a real HA or database: device mapping, and the
// WebSocket client against a fake HA server started on a local port.
//   php deploy/test-homeassistant.php
if (($argv[1] ?? '') === '--fake-server') { fake_ha_server((int) $argv[2], $argv[3]); exit; }

// Local PHP without mbstring: plain-byte stand-ins are enough for these ASCII checks.
if (!function_exists('mb_substr')) { function mb_substr($s, $a, $l = null) { return substr($s, $a, $l); } }
if (!function_exists('mb_strtolower')) { function mb_strtolower($s) { return strtolower($s); } }
require_once __DIR__ . '/../includes/homeassistant.php';
require_once __DIR__ . '/../includes/portmap.php';

function check(bool $ok, string $message): void {
    if (!$ok) { fwrite(STDERR, "FAIL: $message\n"); exit(1); }
}

// ---- mapping ----
$devices = [
    ['id' => 'd1', 'name' => 'Smart Plug', 'name_by_user' => 'Kitchen kettle', 'manufacturer' => 'Tuya', 'model' => 'Smart Plug (cz)',
     'sw_version' => '1.2', 'area_id' => 'kitchen', 'connections' => [['mac', 'AA:BB:CC:DD:EE:01']]],
    ['id' => 'd2', 'name' => 'Living room speaker', 'manufacturer' => 'Google Inc.', 'model' => 'Google Nest Mini', 'connections' => []],
    ['id' => 'd3', 'name' => 'Sun', 'entry_type' => 'service'],
    ['id' => 'd4', 'name' => 'Old thing', 'disabled_by' => 'user'],
    ['id' => 'd5', 'name' => 'Shelly 1', 'configuration_url' => 'http://192.168.60.42/', 'manufacturer' => 'Shelly'],
];
$areas = [['area_id' => 'kitchen', 'name' => 'Kitchen']];
$entities = [
    ['entity_id' => 'switch.kitchen_kettle', 'device_id' => 'd1'],
    ['entity_id' => 'sensor.kitchen_kettle_ip', 'device_id' => 'd1'],
    ['entity_id' => 'media_player.living_room_speaker', 'device_id' => 'd2', 'area_id' => 'kitchen'],
    ['entity_id' => 'light.shelly', 'device_id' => 'd5', 'disabled_by' => 'user'],
];
$states = [['entity_id' => 'sensor.kitchen_kettle_ip', 'state' => '192.168.60.88', 'attributes' => []],
           ['entity_id' => 'media_player.living_room_speaker', 'state' => 'idle', 'attributes' => ['ip_address' => '192.168.60.21']]];
$out = ha_build_devices($devices, $areas, $entities, $states);
check(count($out) === 3, 'service and disabled devices are skipped: ' . count($out));
check($out[0]['name'] === 'Kitchen kettle' && $out[0]['userNamed'] && $out[0]['area'] === 'Kitchen', 'user name and area');
check($out[0]['macs'] === ['aa:bb:cc:dd:ee:01'] && $out[0]['ips'] === ['192.168.60.88'] && $out[0]['kinds'] === ['switch', 'sensor'], 'MAC, IP sensor and kinds: ' . json_encode($out[0]));
check($out[1]['ips'] === ['192.168.60.21'] && $out[1]['area'] === 'Kitchen' && $out[1]['kinds'] === ['media_player'], 'IP attribute and area from entity');
check($out[2]['ips'] === ['192.168.60.42'] && !isset($out[2]['kinds']), 'configuration_url IP; disabled entity ignored');
check(ha_normalize_url('http://192.168.40.7:8123/') === 'http://192.168.40.7:8123', 'url normalized');
check(ha_normalize_url('http://x:8123/api') === null && ha_normalize_url('ftp://x') === null && ha_normalize_url('http://u:p@x') === null, 'bad urls refused');

// ---- WebSocket client against a fake HA ----
$port = random_int(20000, 60000);
$token = 'test.token-123';
$srv = proc_open([PHP_BINARY, __FILE__, '--fake-server', (string) $port, $token], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
usleep(300000);
$r = ha_ws_requests("http://127.0.0.1:$port", $token, [1 => ['type' => 'config/device_registry/list'], 2 => ['type' => 'config/area_registry/list']]);
check(count($r[1]) === 1500 && $r[1][1499]['name'] === 'Device 1499', 'large (64-bit length) result');
check($r[2] === [['area_id' => 'a', 'name' => 'Attic']], 'fragmented result after a ping');
proc_close($srv);
$srv = proc_open([PHP_BINARY, __FILE__, '--fake-server', (string) ($port + 1), 'other'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
usleep(300000);
try {
    ha_ws_requests('http://127.0.0.1:' . ($port + 1), $token, [1 => ['type' => 'x']]);
    check(false, 'a refused token must throw');
} catch (RuntimeException $e) {
    check(str_contains($e->getMessage(), 'refused the access token'), 'refused token message: ' . $e->getMessage());
}
proc_close($srv);
echo "Home Assistant checks passed.\n";

// One connection, speaking just enough of HA's WebSocket API.
function fake_ha_server(int $port, string $goodToken): void {
    $server = stream_socket_server("tcp://127.0.0.1:$port", $errno, $err);
    $c = stream_socket_accept($server, 5);
    $hdr = '';
    while (!str_contains($hdr, "\r\n\r\n")) $hdr .= fread($c, 1);
    preg_match('/Sec-WebSocket-Key: (\S+)/', $hdr, $m);
    $accept = base64_encode(sha1($m[1] . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));
    fwrite($c, "HTTP/1.1 101 Switching Protocols\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Accept: $accept\r\n\r\n");
    $frame = function (string $data, int $op = 1, bool $fin = true): string {
        $len = strlen($data);
        return chr(($fin ? 0x80 : 0) | $op) . ($len < 126 ? chr($len) : ($len < 65536 ? chr(126) . pack('n', $len) : chr(127) . pack('J', $len))) . $data;
    };
    $read = function () use ($c): array {
        $h = fread($c, 2);
        $len = ord($h[1]) & 0x7F;
        if ($len === 126) $len = unpack('n', fread($c, 2))[1];
        $mask = fread($c, 4);
        $data = '';
        while (strlen($data) < $len) $data .= fread($c, $len - strlen($data));
        return json_decode($data ^ str_repeat($mask, intdiv($len, 4) + 1), true) ?? [];
    };
    fwrite($c, $frame(json_encode(['type' => 'auth_required'])));
    $auth = $read();
    if (($auth['access_token'] ?? '') !== $goodToken) { fwrite($c, $frame(json_encode(['type' => 'auth_invalid']))); return; }
    fwrite($c, $frame(json_encode(['type' => 'auth_ok'])));
    $read(); $read();
    $devices = [];
    for ($i = 0; $i < 1500; $i++) $devices[] = ['id' => "d$i", 'name' => "Device $i", 'manufacturer' => str_repeat('x', 40)];
    fwrite($c, $frame(json_encode(['id' => 1, 'type' => 'result', 'success' => true, 'result' => $devices])));
    $areas = json_encode(['id' => 2, 'type' => 'result', 'success' => true, 'result' => [['area_id' => 'a', 'name' => 'Attic']]]);
    fwrite($c, $frame(substr($areas, 0, 10), 1, false) . $frame('', 9) . $frame(substr($areas, 10), 0, true));
    usleep(200000);
}
