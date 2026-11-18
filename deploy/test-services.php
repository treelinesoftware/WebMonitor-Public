<?php
// Service check engine without a database: web, port and DNS checks against
// local test servers. Needs php-curl and python3.
//   php deploy/test-services.php
if (!function_exists('mb_substr')) { function mb_substr($s, $a, $l = null) { return substr($s, $a, $l); } }
if (!function_exists('mb_stripos')) { function mb_stripos($h, $n) { return stripos($h, $n); } }
require_once __DIR__ . '/../includes/services.php';

function check(bool $ok, string $message): void {
    if (!$ok) { fwrite(STDERR, "FAIL: $message\n"); exit(1); }
}

// Expected status codes.
check(http_status_ok(200, '') && http_status_ok(302, '') && !http_status_ok(404, '') && !http_status_ok(500, ''), 'default 2xx/3xx');
check(http_status_ok(401, '401') && http_status_ok(204, '200-299, 301') && http_status_ok(301, '200-299, 301') && !http_status_ok(302, '200-299, 301'), 'ranges');

// A local web server: /ok, /fail (500), /moved -> /ok, /slow (1.5 s).
$dir = sys_get_temp_dir() . '/wm-svc-test-' . getmypid();
@mkdir($dir);
file_put_contents("$dir/router.php", '<?php
$p = parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH);
if ($p === "/ok") { echo "<html>Welcome to the test page</html>"; }
elseif ($p === "/fail") { http_response_code(500); echo "broken"; }
elseif ($p === "/moved") { header("Location: /ok", true, 302); }
elseif ($p === "/slow") { usleep(1500000); echo "slow page"; }
else { http_response_code(404); }');
$port = 18000 + getmypid() % 1000;
$web = proc_open([PHP_BINARY, '-S', "127.0.0.1:$port", "$dir/router.php"], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);

// A fake DNS server: answers A for test.example (10.1.2.3), NXDOMAIN for anything else.
$dnsPort = $port + 1;
file_put_contents("$dir/dns.py", 'import socket, struct, sys
s = socket.socket(socket.AF_INET, socket.SOCK_DGRAM); s.bind(("127.0.0.1", int(sys.argv[1])))
while True:
    q, a = s.recvfrom(512)
    qid = q[:2]; i = 12; labels = []
    while q[i]: labels.append(q[i+1:i+1+q[i]].decode()); i += q[i] + 1
    question = q[12:i+5]; name = ".".join(labels)
    if name == "test.example":
        ans = b"\xc0\x0c" + struct.pack(">HHIH", 1, 1, 60, 4) + bytes([10, 1, 2, 3])
        s.sendto(qid + struct.pack(">HHHHH", 0x8180, 1, 1, 0, 0) + question + ans, a)
    else:
        s.sendto(qid + struct.pack(">HHHHH", 0x8183, 1, 0, 0, 0) + question, a)');
$dns = proc_open(['python3', '-I', "$dir/dns.py", (string) $dnsPort], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes2);
usleep(600000);

try {
    $base = "http://127.0.0.1:$port";
    $o = ['method' => 'GET', 'expect' => '', 'keyword' => '', 'verify_tls' => true, 'warn_ms' => 0, 'cert_warn_days' => 14];
    $r = http_checks([
        1 => ["$base/ok", $o],
        2 => ["$base/ok", ['keyword' => 'welcome to the test'] + $o],
        3 => ["$base/ok", ['keyword' => 'not there'] + $o],
        4 => ["$base/fail", $o],
        5 => ["$base/fail", ['expect' => '500'] + $o],
        6 => ["$base/moved", ['keyword' => 'Welcome'] + $o],
        7 => ["$base/slow", ['warn_ms' => 500] + $o],
        8 => ['http://127.0.0.1:' . ($port + 2) . '/', $o],
    ]);
    check($r[1][0] === true && $r[1][2] === 'HTTP 200' && is_int($r[1][1]), 'page up: ' . json_encode($r[1]));
    check($r[2][0] === true, 'keyword found (any case): ' . json_encode($r[2]));
    check($r[3][0] === false && str_contains($r[3][2], '"not there" is not on the page'), 'keyword missing: ' . json_encode($r[3]));
    check($r[4][0] === false && $r[4][2] === 'HTTP 500', '500 is down: ' . json_encode($r[4]));
    check($r[5][0] === true, 'expected 500: ' . json_encode($r[5]));
    check($r[6][0] === true, 'redirect followed: ' . json_encode($r[6]));
    check($r[7][0] === true && $r[7][4] === true && str_contains($r[7][2], 'slow'), 'slow page warns: ' . json_encode($r[7]));
    check($r[8][0] === false && $r[8][2] === 'Connection refused', 'refused: ' . json_encode($r[8]));

    $t = tcp_checks([1 => ['127.0.0.1', $port], 2 => ['127.0.0.1', $port + 2], 3 => ['no-such-host.invalid', 80]]);
    check($t[1][0] === true && $t[1][2] === 'Port open', 'port open: ' . json_encode($t[1]));
    check($t[2][0] === false && $t[2][2] === 'Connection refused', 'port closed: ' . json_encode($t[2]));
    check($t[3][0] === false && $t[3][2] === 'Name does not resolve', 'bad name: ' . json_encode($t[3]));

    // The fake DNS server listens on a high port.
    $d1 = dns_check('test.example', ['server' => '127.0.0.1', 'type' => 'A', 'expect' => ''], $dnsPort);
    check($d1[0] === true && $d1[2] === '10.1.2.3', 'dns answer: ' . json_encode($d1));
    $d2 = dns_check('test.example', ['server' => '127.0.0.1', 'type' => 'A', 'expect' => '10.9.9.9'], $dnsPort);
    check($d2[0] === false && str_contains($d2[2], 'expected 10.9.9.9'), 'dns expect: ' . json_encode($d2));
    $d3 = dns_check('missing.example', ['server' => '127.0.0.1', 'type' => 'A', 'expect' => ''], $dnsPort);
    check($d3[0] === false && str_contains($d3[2], 'NXDOMAIN'), 'nxdomain: ' . json_encode($d3));

    check(service_default_name('tcp', '10.0.0.5:22', [], ['ip_address' => '10.0.0.5']) === 'SSH', 'port names');
    check(service_default_name('http', 'https://nas.local:5001/admin', [], null) === 'nas.local:5001/admin', 'web names');
    check(service_default_name('agent', 'nginx.service', [], null) === 'nginx', 'agent names');
    check(service_curl_message('SSL certificate problem: self-signed certificate') === 'Certificate not trusted (untick "Check the certificate" for self-signed devices)', 'curl messages');
} finally {
    proc_terminate($web);
    proc_terminate($dns);
    array_map('unlink', glob("$dir/*"));
    @rmdir($dir);
}
echo "Service check tests passed.\n";
