<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/agents.php';
require_once __DIR__ . '/devicescan.php';   // dns_wire_name(), dns_read_name()

/**
 * Service monitoring (Services tab).
 *
 * Network checks need no agent and run from this server every interval:
 *   http   the page answers with an expected status (and keyword), how fast,
 *          and when its TLS certificate expires
 *   tcp    a port accepts connections
 *   dns    a name resolves (optionally on a given server, to an expected value)
 * Agent services are services on an agent host that must be running. The
 * server tells each agent which to watch (in its report replies) and the agent
 * reports their state with its metrics, every minute.
 *
 * Status: up, warn (slow, or certificate expiring soon), down, pending (not
 * checked yet), unknown (the agent isn't reporting).
 */

const SERVICE_KINDS = ['http', 'tcp', 'dns', 'agent'];
const SERVICE_INTERVALS = [30, 60, 120, 300, 600, 900, 1800, 3600];
const SERVICE_HTTP_TIMEOUT_SEC = 10;
const SERVICE_TCP_TIMEOUT_SEC = 5;
const SERVICE_DNS_TIMEOUT_SEC = 3;
const SERVICE_BODY_MAX = 1024 * 1024;          // read at most 1 MB of a page for the keyword
const SERVICE_RESULTS_KEEP_DAYS = 7;
const SERVICE_DOWN_AFTER = 2;                  // network checks: failures in a row before an alert
const SERVICE_CERT_WARN_DAYS = 14;
const SERVICE_NAME_RE = '/^[A-Za-z0-9@._:\-+ ]{1,120}$/';   // systemd units and Windows service names
const SERVICE_RESTART_MIN_AGENT = ['linux' => '0.6.0', 'windows' => '0.5.0'];
const SERVICE_WATCH_MIN_AGENT = SERVICE_RESTART_MIN_AGENT;

function service_options(?string $json): array {
    $o = $json ? json_decode($json, true) : null;
    return is_array($o) ? $o : [];
}

// ---------------------------------------------------------------- validation

/**
 * Cleans a create/update request. Returns [row, error]: row has host_id, kind,
 * name, target, options (array), interval_sec, enabled, alert.
 */
function service_validate(array $in, ?array $existing = null): array {
    $kind = (string) ($in['kind'] ?? ($existing['kind'] ?? ''));
    if (!in_array($kind, SERVICE_KINDS, true)) return [null, 'Pick a check type.'];
    $hostId = array_key_exists('hostId', $in) ? ($in['hostId'] !== null && $in['hostId'] !== '' ? (int) $in['hostId'] : null)
        : ($existing ? ($existing['host_id'] !== null ? (int) $existing['host_id'] : null) : null);
    $host = null;
    if ($hostId !== null) {
        $st = db()->prepare('SELECT id, name, ip_address FROM hosts WHERE id = ?');
        $st->execute([$hostId]);
        $host = $st->fetch() ?: null;
        if (!$host) return [null, 'That host no longer exists.'];
    }
    $target = trim((string) ($in['target'] ?? ($existing['target'] ?? '')));
    $opt = is_array($in['options'] ?? null) ? $in['options'] : ($existing ? service_options($existing['options']) : []);
    $clean = [];

    if ($kind === 'http') {
        if (preg_match('#^[a-z][a-z0-9+.\-]*://#i', $target) && !preg_match('#^https?://#i', $target)) return [null, 'Only http:// and https:// addresses can be checked.'];
        if ($target !== '' && !preg_match('#^https?://#i', $target)) $target = 'http://' . $target;
        $u = parse_url($target);
        if (!$u || empty($u['host']) || !in_array(strtolower($u['scheme'] ?? ''), ['http', 'https'], true)) {
            return [null, 'Enter a web address, e.g. https://nas.local:5001/ or http://192.168.1.53/admin.'];
        }
        $clean['method'] = ($opt['method'] ?? 'GET') === 'HEAD' ? 'HEAD' : 'GET';
        $expect = trim((string) ($opt['expect'] ?? ''));
        if ($expect !== '' && !preg_match('/^\d{3}(-\d{3})?(\s*,\s*\d{3}(-\d{3})?)*$/', $expect)) {
            return [null, 'Expected status: codes or ranges like 200 or 200-299, 301.'];
        }
        $clean['expect'] = $expect;
        $clean['keyword'] = mb_substr(trim((string) ($opt['keyword'] ?? '')), 0, 200);
        $clean['verify_tls'] = !array_key_exists('verify_tls', $opt) || !empty($opt['verify_tls']);
        $clean['warn_ms'] = max(0, min(60000, (int) ($opt['warn_ms'] ?? 0)));
        $clean['cert_warn_days'] = max(0, min(365, (int) ($opt['cert_warn_days'] ?? SERVICE_CERT_WARN_DAYS)));
    } elseif ($kind === 'tcp') {
        if (preg_match('/^\d{1,5}$/', $target) && $host) $target = $host['ip_address'] . ':' . $target;
        if (!preg_match('/^(\[[0-9a-f:]+\]|[A-Za-z0-9.\-]+):(\d{1,5})$/i', $target, $m) || (int) $m[2] < 1 || (int) $m[2] > 65535) {
            return [null, 'Enter host:port, e.g. 192.168.1.50:445, or just the port when a host is picked.'];
        }
        $clean['warn_ms'] = max(0, min(60000, (int) ($opt['warn_ms'] ?? 0)));
    } elseif ($kind === 'dns') {
        if (!preg_match('/^[A-Za-z0-9_.\-]{1,253}$/', $target) || !str_contains($target, '.')) {
            return [null, 'Enter the name to look up, e.g. nas.home.arpa or google.com.'];
        }
        $server = trim((string) ($opt['server'] ?? ''));
        if ($server === '' && $host) $server = $host['ip_address'];
        if ($server !== '' && !filter_var($server, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) return [null, 'DNS server: an IPv4 address, or blank for this server\'s resolver.'];
        $clean['server'] = $server;
        $type = (string) ($opt['type'] ?? 'A');
        $clean['type'] = in_array($type, ['A', 'AAAA', 'CNAME', 'MX'], true) ? $type : 'A';
        $clean['expect'] = mb_substr(trim((string) ($opt['expect'] ?? '')), 0, 200);
    } else {   // agent
        if (!$host) return [null, 'Pick the host the service runs on.'];
        if (!preg_match(SERVICE_NAME_RE, $target)) return [null, 'Pick a service, or type its name (e.g. nginx.service or Spooler).'];
        $st = db()->prepare("SELECT agent_version, JSON_UNQUOTE(JSON_EXTRACT(inventory, '$.os.id')) AS os_id FROM agents WHERE host_id = ?");
        $st->execute([$hostId]);
        $a = $st->fetch();
        if (!$a) return [null, 'That host has no agent. Install one from its Details window first.'];
        $platform = $a['os_id'] === 'windows' ? 'windows' : 'linux';
        if ($platform === 'linux' && !str_contains($target, '.')) $target .= '.service';
        if (!$a['agent_version'] || version_compare($a['agent_version'], SERVICE_WATCH_MIN_AGENT[$platform], '<')) {
            return [null, "Watching services needs agent " . SERVICE_WATCH_MIN_AGENT[$platform] . " or newer (this host has {$a['agent_version']}). Upgrade it from the Updates tab first."];
        }
    }

    $name = trim((string) ($in['name'] ?? ($existing['name'] ?? '')));
    if ($name === '') $name = service_default_name($kind, $target, $clean, $host);
    $interval = (int) ($in['interval'] ?? ($existing['interval_sec'] ?? 60));
    if (!in_array($interval, SERVICE_INTERVALS, true)) $interval = 60;

    $dup = db()->prepare('SELECT id FROM services WHERE kind = ? AND target = ? AND host_id <=> ? AND id <> ?');
    $dup->execute([$kind, $target, $hostId, $existing ? (int) $existing['id'] : 0]);
    if ($dup->fetchColumn()) return [null, 'That check already exists.'];

    return [[
        'host_id' => $hostId, 'kind' => $kind, 'name' => mb_substr($name, 0, 120), 'target' => mb_substr($target, 0, 500),
        'options' => $clean, 'interval_sec' => $kind === 'agent' ? 60 : $interval,
        'enabled' => array_key_exists('enabled', $in) ? (bool) $in['enabled'] : ($existing ? (bool) $existing['enabled'] : true),
        'alert' => array_key_exists('alert', $in) ? (bool) $in['alert'] : ($existing ? (bool) $existing['alert'] : true),
    ], null];
}

function service_default_name(string $kind, string $target, array $opt, ?array $host): string {
    if ($kind === 'http') {
        $u = parse_url($target);
        return ($u['host'] ?? $target) . (isset($u['port']) ? ':' . $u['port'] : '') . (($u['path'] ?? '/') !== '/' ? $u['path'] : '');
    }
    if ($kind === 'tcp') {
        $port = (int) substr($target, strrpos($target, ':') + 1);
        $known = [21 => 'FTP', 22 => 'SSH', 25 => 'SMTP', 53 => 'DNS', 80 => 'HTTP', 110 => 'POP3', 143 => 'IMAP', 443 => 'HTTPS',
                  445 => 'SMB', 993 => 'IMAPS', 1433 => 'SQL Server', 1883 => 'MQTT', 3306 => 'MySQL', 3389 => 'Remote Desktop',
                  5432 => 'PostgreSQL', 5900 => 'VNC', 6379 => 'Redis', 8006 => 'Proxmox', 8123 => 'Home Assistant', 9100 => 'Printer'];
        return ($known[$port] ?? "Port $port") . ($host ? '' : ' · ' . substr($target, 0, strrpos($target, ':')));
    }
    if ($kind === 'dns') return "DNS $target";
    return preg_replace('/\.service$/', '', $target);
}

// ---------------------------------------------------------------- network checks

/** Whether an HTTP status matches "200-299, 301" ('' = 200-399). */
function http_status_ok(int $code, string $expect): bool {
    if ($expect === '') return $code >= 200 && $code < 400;
    foreach (preg_split('/\s*,\s*/', trim($expect)) as $part) {
        [$lo, $hi] = array_pad(explode('-', $part), 2, null);
        if ($code >= (int) $lo && $code <= (int) ($hi ?? $lo)) return true;
    }
    return false;
}

/**
 * Runs HTTP checks in parallel. $checks: [id => [url, options]].
 * Returns [id => [up(bool), ms, message, cert_expires_at(ms|null), warn(bool)]].
 */
function http_checks(array $checks): array {
    $mh = curl_multi_init();
    $handles = [];
    $bodies = [];
    foreach ($checks as $id => [$url, $o]) {
        $ch = curl_init($url);
        $bodies[$id] = '';
        curl_setopt_array($ch, [
            CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 5,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS, CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_CONNECTTIMEOUT => SERVICE_HTTP_TIMEOUT_SEC, CURLOPT_TIMEOUT => SERVICE_HTTP_TIMEOUT_SEC,
            CURLOPT_SSL_VERIFYPEER => !empty($o['verify_tls']), CURLOPT_SSL_VERIFYHOST => !empty($o['verify_tls']) ? 2 : 0,
            CURLOPT_CERTINFO => true, CURLOPT_NOBODY => ($o['method'] ?? 'GET') === 'HEAD',
            CURLOPT_USERAGENT => 'WebMonitor service check', CURLOPT_ENCODING => '',
            CURLOPT_HTTPHEADER => ['Accept: */*'],
            CURLOPT_WRITEFUNCTION => function ($ch, $data) use ($id, &$bodies, $o) {
                if (($o['keyword'] ?? '') === '') return strlen($data);   // not needed: discard
                if (strlen($bodies[$id]) < SERVICE_BODY_MAX) $bodies[$id] .= substr($data, 0, SERVICE_BODY_MAX - strlen($bodies[$id]));
                return strlen($data);
            },
        ]);
        curl_multi_add_handle($mh, $ch);
        $handles[$id] = $ch;
    }
    do {
        $status = curl_multi_exec($mh, $running);
        if ($running) curl_multi_select($mh, 1.0);
    } while ($running && $status === CURLM_OK);
    // Per-transfer results: curl_error() is empty for transfers run by curl_multi.
    $codes = [];
    while ($info = curl_multi_info_read($mh)) $codes[spl_object_id($info['handle'])] = (int) $info['result'];

    $out = [];
    foreach ($handles as $id => $ch) {
        [$url, $o] = $checks[$id];
        $code = $codes[spl_object_id($ch)] ?? 0;
        $err = curl_error($ch) ?: ($code ? curl_strerror($code) : '');
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $ms = (int) round(curl_getinfo($ch, CURLINFO_TOTAL_TIME) * 1000);
        $cert = null;
        foreach ((array) curl_getinfo($ch, CURLINFO_CERTINFO) as $c) {
            if (!empty($c['Expire date']) && ($t = strtotime($c['Expire date']))) { $cert = $t * 1000; break; }
        }
        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);

        if ($err !== '' && $code === 0) {
            $out[$id] = [false, null, service_curl_message($err), $cert, false];
            continue;
        }
        if (!http_status_ok($code, (string) ($o['expect'] ?? ''))) {
            $out[$id] = [false, $ms, "HTTP $code" . (($o['expect'] ?? '') !== '' ? " (expected {$o['expect']})" : ''), $cert, false];
            continue;
        }
        if (($o['keyword'] ?? '') !== '' && mb_stripos($bodies[$id], $o['keyword']) === false) {
            $out[$id] = [false, $ms, "HTTP $code, but \"{$o['keyword']}\" is not on the page", $cert, false];
            continue;
        }
        $msg = "HTTP $code";
        $warn = false;
        $days = $cert !== null ? (int) floor(($cert - now_ms()) / 86400000) : null;
        if ($days !== null && $days < 0) {
            $out[$id] = [false, $ms, "HTTP $code, but the certificate expired " . date('Y-m-d', intdiv($cert, 1000)), $cert, false];
            continue;
        }
        if ($days !== null && !empty($o['cert_warn_days']) && $days <= (int) $o['cert_warn_days']) {
            $warn = true;
            $msg .= " · certificate expires in $days day" . ($days === 1 ? '' : 's');
        }
        if (!empty($o['warn_ms']) && $ms > (int) $o['warn_ms']) {
            $warn = true;
            $msg .= " · slow ({$ms} ms, limit {$o['warn_ms']} ms)";
        }
        $out[$id] = [true, $ms, $msg, $cert, $warn];
    }
    curl_multi_close($mh);
    return $out;
}

/** curl's error in plain words. */
function service_curl_message(string $err): string {
    $map = [
        '/Could not resolve host|resolve host name/i' => 'Name does not resolve',
        '/Connection refused|Failed to connect.*refused|Couldn\'t connect to server|Could not connect to server/i' => 'Connection refused',
        '/timed out/i' => 'No answer (timed out)',
        '/SSL certificate problem: self.signed|unable to get local issuer|certificate verify failed/i' =>
            'Certificate not trusted (untick "Check the certificate" for self-signed devices)',
        '/certificate has expired/i' => 'Certificate expired',
        '/No route to host/i' => 'No route to host',
        '/SSL|TLS/i' => 'TLS error: ' . $err,
    ];
    foreach ($map as $re => $msg) if (preg_match($re, $err)) return $msg;
    return $err;
}

/** TCP connects in parallel. $checks: [id => [host, port]]. Returns [id => [up, ms, message]]. */
function tcp_checks(array $checks): array {
    $out = [];
    $socks = [];
    $start = microtime(true);
    foreach ($checks as $id => [$host, $port]) {
        $ip = filter_var(trim($host, '[]'), FILTER_VALIDATE_IP) ? trim($host, '[]') : gethostbyname($host);
        if (!filter_var($ip, FILTER_VALIDATE_IP)) { $out[$id] = [false, null, 'Name does not resolve']; continue; }
        $addr = str_contains($ip, ':') ? "[$ip]" : $ip;
        $fp = @stream_socket_client("tcp://$addr:$port", $errno, $errstr, SERVICE_TCP_TIMEOUT_SEC,
            STREAM_CLIENT_CONNECT | STREAM_CLIENT_ASYNC_CONNECT);
        if ($fp === false) { $out[$id] = [false, null, $errstr ?: 'Cannot connect']; continue; }
        $socks[(int) $fp] = [$id, $fp];
    }
    while ($socks) {
        $left = SERVICE_TCP_TIMEOUT_SEC - (microtime(true) - $start);
        if ($left <= 0) break;
        $w = array_column($socks, 1);
        $e = $w;
        $r = [];
        $n = @stream_select($r, $w, $e, (int) $left, (int) (($left - (int) $left) * 1e6));
        if (!$n) break;
        foreach (array_merge($w, $e) as $fp) {
            if (!isset($socks[(int) $fp])) continue;
            $id = $socks[(int) $fp][0];
            $ms = (int) round((microtime(true) - $start) * 1000);
            $out[$id] = @stream_socket_get_name($fp, true) !== false ? [true, $ms, 'Port open'] : [false, null, 'Connection refused'];
            fclose($fp);
            unset($socks[(int) $fp]);
        }
    }
    foreach ($socks as [$id, $fp]) { fclose($fp); $out[$id] = [false, null, 'No answer (timed out)']; }
    return $out;
}

/**
 * One DNS lookup. Returns [up, ms, message]. With a server, asks it directly
 * over UDP; without, uses this server's resolver.
 */
function dns_check(string $name, array $o, int $port = 53): array {
    $types = ['A' => 1, 'CNAME' => 5, 'MX' => 15, 'AAAA' => 28];
    $type = $o['type'] ?? 'A';
    $start = microtime(true);
    $answers = [];
    if (($o['server'] ?? '') === '') {
        $recs = @dns_get_record($name, ['A' => DNS_A, 'AAAA' => DNS_AAAA, 'CNAME' => DNS_CNAME, 'MX' => DNS_MX][$type]);
        foreach ((array) $recs as $r) $answers[] = $r['ip'] ?? $r['ipv6'] ?? $r['target'] ?? '';
    } else {
        $s = @stream_socket_client("udp://{$o['server']}:$port", $errno, $err, SERVICE_DNS_TIMEOUT_SEC);
        if (!$s) return [false, null, "Cannot reach DNS server {$o['server']}"];
        $qid = random_int(1, 65535);
        fwrite($s, pack('nnnnnn', $qid, 0x0100, 1, 0, 0, 0) . dns_wire_name($name) . pack('nn', $types[$type], 1));
        stream_set_timeout($s, SERVICE_DNS_TIMEOUT_SEC);
        $r = (string) fread($s, 4096);
        fclose($s);
        if (strlen($r) < 12) return [false, null, "No answer from DNS server {$o['server']}"];
        $h = unpack('nid/nflags/nqd/nan/nns/nar', $r);
        $rcode = $h['flags'] & 0xF;
        if ($rcode === 3) return [false, (int) round((microtime(true) - $start) * 1000), "$name does not exist (NXDOMAIN)"];
        if ($rcode !== 0) return [false, null, "DNS server error (code $rcode)"];
        $off = 12;
        for ($i = 0; $i < $h['qd']; $i++) { dns_read_name($r, $off); $off += 4; }
        for ($i = 0; $i < $h['an'] && $off + 10 <= strlen($r); $i++) {
            dns_read_name($r, $off);
            $t = unpack('ntype/nclass/Nttl/nlen', substr($r, $off, 10));
            $off += 10;
            $data = substr($r, $off, $t['len']);
            $o2 = $off;
            $off += $t['len'];
            if ($t['type'] === 1 && $t['len'] === 4) $answers[] = inet_ntop($data);
            elseif ($t['type'] === 28 && $t['len'] === 16) $answers[] = inet_ntop($data);
            elseif ($t['type'] === 5) $answers[] = dns_read_name($r, $o2);
            elseif ($t['type'] === 15) { $o2 += 2; $answers[] = dns_read_name($r, $o2); }
        }
    }
    $ms = (int) round((microtime(true) - $start) * 1000);
    $answers = array_values(array_filter($answers));
    if (!$answers) return [false, $ms, "No $type record for $name"];
    $shown = implode(', ', array_slice($answers, 0, 4));
    if (($o['expect'] ?? '') !== '' && !array_filter($answers, fn($a) => stripos($a, $o['expect']) !== false)) {
        return [false, $ms, "Resolves to $shown, expected {$o['expect']}"];
    }
    return [true, $ms, $shown];
}

// ---------------------------------------------------------------- recording

/**
 * Stores a result: [up, ms, message, certExpires?, warn?]. Status changes set
 * changed_at; network checks only show down after SERVICE_DOWN_AFTER failures.
 */
function record_service_result(array $svc, array $res, ?array $detail = null): void {
    [$up, $ms, $msg] = $res;
    $cert = $res[3] ?? null;
    $warn = $res[4] ?? false;
    $now = now_ms();
    $fails = $up ? 0 : (int) $svc['fail_count'] + 1;
    $needed = $svc['kind'] === 'agent' ? 1 : SERVICE_DOWN_AFTER;
    if ($up) $status = $warn ? 'warn' : 'up';
    elseif ($fails >= $needed || in_array($svc['status'], ['down', 'pending'], true)) $status = 'down';
    else $status = $svc['status'];   // one failure: keep the old status until it's confirmed
    $pdo = db();
    $pdo->prepare('UPDATE services SET status = ?, message = ?, last_check_at = ?, last_ms = ?, fail_count = ?,
                   changed_at = IF(status <> ?, ?, COALESCE(changed_at, ?)), cert_expires_at = COALESCE(?, cert_expires_at),
                   detail = COALESCE(?, detail) WHERE id = ?')
        ->execute([$status, $msg !== null ? mb_substr($msg, 0, 500) : null, $now, $ms, $fails, $status, $now, $now,
                   $cert, $detail !== null ? json_encode($detail) : null, $svc['id']]);
    $pdo->prepare('INSERT IGNORE INTO service_results (service_id, ts, up, ms) VALUES (?, ?, ?, ?)')
        ->execute([$svc['id'], $now, $up ? 1 : 0, $ms]);
}

/** Runs the given network checks (rows from services) now. */
function run_service_checks(array $rows): void {
    $http = $tcp = [];
    $byId = [];
    foreach ($rows as $s) {
        $byId[(int) $s['id']] = $s;
        $o = service_options($s['options']);
        if ($s['kind'] === 'http') $http[(int) $s['id']] = [$s['target'], $o];
        elseif ($s['kind'] === 'tcp') {
            $i = strrpos($s['target'], ':');
            $tcp[(int) $s['id']] = [substr($s['target'], 0, $i), (int) substr($s['target'], $i + 1)];
        }
    }
    $results = [];
    foreach (array_chunk($http, 25, true) as $batch) $results += http_checks($batch);
    foreach (array_chunk($tcp, 100, true) as $batch) {
        foreach (tcp_checks($batch) as $id => $r) {
            $o = service_options($byId[$id]['options']);
            if ($r[0] && !empty($o['warn_ms']) && $r[1] > $o['warn_ms']) $r = [true, $r[1], "Port open · slow ({$r[1]} ms)", null, true];
            $results[$id] = $r;
        }
    }
    foreach ($byId as $id => $s) {
        if ($s['kind'] === 'dns') $results[$id] = dns_check($s['target'], service_options($s['options']));
    }
    foreach ($results as $id => $r) record_service_result($byId[$id], $r);
}

/**
 * Cron: runs network checks that are due, marks agent services unknown when
 * their agent stops reporting, and trims old results. One process at a time.
 */
function run_due_services(): void {
    $pdo = db();
    if (!(int) $pdo->query("SELECT GET_LOCK('webmonitor_services', 0)")->fetchColumn()) return;
    try {
        $now = now_ms();
        // 5 s of slack so a 60 s check run by a once-a-minute timer isn't skipped every other run.
        $st = $pdo->prepare("SELECT * FROM services WHERE enabled = 1 AND kind <> 'agent'
                             AND (last_check_at IS NULL OR last_check_at + interval_sec * 1000 - 5000 <= ?)");
        $st->execute([$now]);
        $due = $st->fetchAll();
        if ($due) run_service_checks($due);

        $pdo->prepare("UPDATE services SET status = 'unknown', message = 'The agent is not reporting', changed_at = ?
                       WHERE kind = 'agent' AND status NOT IN ('unknown', 'pending') AND (last_check_at IS NULL OR last_check_at < ?)")
            ->execute([$now, $now - AGENT_OFFLINE_AFTER_SEC * 1000]);
        if (random_int(1, 30) === 1) {
            $pdo->prepare('DELETE FROM service_results WHERE ts < ?')->execute([$now - SERVICE_RESULTS_KEEP_DAYS * 86400000]);
        }
    } finally {
        $pdo->query("SELECT RELEASE_LOCK('webmonitor_services')");
    }
}

/** Service names an agent should report on (sent in its report replies). */
function services_watch_list(int $hostId): array {
    $st = db()->prepare("SELECT target FROM services WHERE host_id = ? AND kind = 'agent' AND enabled = 1 ORDER BY target");
    $st->execute([$hostId]);
    return $st->fetchAll(PDO::FETCH_COLUMN);
}

/**
 * From an agent's metrics: [{name, display, state, sub, since, restarts, start_type}]
 * for the services it was asked to watch. Running = up; anything else = down.
 */
function store_agent_services(int $hostId, $list): void {
    if (!is_array($list)) return;
    $byName = [];
    foreach ($list as $s) {
        if (is_array($s) && is_string($s['name'] ?? null)) $byName[strtolower($s['name'])] = $s;
    }
    $st = db()->prepare("SELECT * FROM services WHERE host_id = ? AND kind = 'agent' AND enabled = 1");
    $st->execute([$hostId]);
    foreach ($st->fetchAll() as $svc) {
        $r = $byName[strtolower($svc['target'])] ?? null;
        if ($r === null) continue;   // not in this report (the agent may not have the new list yet)
        $detail = [
            'display' => is_string($r['display'] ?? null) ? mb_substr($r['display'], 0, 200) : null,
            'state' => is_string($r['state'] ?? null) ? mb_substr($r['state'], 0, 40) : null,
            'sub' => is_string($r['sub'] ?? null) ? mb_substr($r['sub'], 0, 40) : null,
            'since' => num_or_null($r['since'] ?? null),
            'restarts' => num_or_null($r['restarts'] ?? null),
            'startType' => is_string($r['start_type'] ?? null) ? mb_substr($r['start_type'], 0, 40) : null,
            'pid' => num_or_null($r['pid'] ?? null),
        ];
        if (!empty($r['missing'])) {
            record_service_result($svc, [false, null, 'Not installed on this host'], $detail);
            continue;
        }
        $up = in_array($detail['state'], ['active', 'running', 'Running'], true);
        $msg = trim(($detail['state'] ?? '?') . ($detail['sub'] && $detail['sub'] !== $detail['state'] ? " ({$detail['sub']})" : ''));
        record_service_result($svc, [$up, null, $msg], $detail);
    }
}

// ---------------------------------------------------------------- reading

/** JSON-ready row. $history: [[ts, up, ms]...] oldest first. */
function service_row(array $s, array $history = [], ?float $uptime = null): array {
    return [
        'id' => (int) $s['id'],
        'hostId' => $s['host_id'] !== null ? (int) $s['host_id'] : null,
        'hostName' => $s['host_name'] ?? null,
        'hostIp' => $s['ip_address'] ?? null,
        'kind' => $s['kind'],
        'name' => $s['name'],
        'target' => $s['target'],
        'options' => service_options($s['options']),
        'interval' => (int) $s['interval_sec'],
        'enabled' => (bool) $s['enabled'],
        'alert' => (bool) $s['alert'],
        'status' => $s['enabled'] ? $s['status'] : 'paused',
        'message' => $s['message'],
        'lastCheck' => $s['last_check_at'] !== null ? (int) $s['last_check_at'] : null,
        'lastMs' => $s['last_ms'] !== null ? (int) $s['last_ms'] : null,
        'changedAt' => $s['changed_at'] !== null ? (int) $s['changed_at'] : null,
        'certExpiresAt' => $s['cert_expires_at'] !== null ? (int) $s['cert_expires_at'] : null,
        'detail' => $s['detail'] ? json_decode($s['detail'], true) : null,
        'uptime' => $uptime,
        'history' => $history,
    ];
}

/** Every service with its last 30 results and 24-hour uptime. $hostId limits it to one host. */
function service_list(?int $hostId = null): array {
    $pdo = db();
    $sql = 'SELECT s.*, h.name AS host_name, h.ip_address FROM services s LEFT JOIN hosts h ON h.id = s.host_id';
    $st = $pdo->prepare($sql . ($hostId !== null ? ' WHERE s.host_id = ?' : '') . ' ORDER BY h.name IS NULL, h.name, s.name');
    $st->execute($hostId !== null ? [$hostId] : []);
    $rows = $st->fetchAll();
    if (!$rows) return [];
    $ids = array_map(fn($r) => (int) $r['id'], $rows);
    $in = implode(',', $ids);
    $since = now_ms() - 86400000;
    $uptime = [];
    foreach ($pdo->query("SELECT service_id, AVG(up) AS u FROM service_results WHERE service_id IN ($in) AND ts >= $since GROUP BY service_id") as $r) {
        $uptime[(int) $r['service_id']] = round((float) $r['u'] * 100, 2);
    }
    $hist = array_fill_keys($ids, []);
    $recent = $pdo->query("SELECT service_id, ts, up, ms FROM (
            SELECT service_id, ts, up, ms, ROW_NUMBER() OVER (PARTITION BY service_id ORDER BY ts DESC) AS rn
            FROM service_results WHERE service_id IN ($in) AND ts >= $since) x WHERE rn <= 30 ORDER BY ts");
    foreach ($recent as $r) $hist[(int) $r['service_id']][] = [(int) $r['ts'], (bool) $r['up'], $r['ms'] !== null ? (int) $r['ms'] : null];
    return array_map(fn($r) => service_row($r, $hist[(int) $r['id']], $uptime[(int) $r['id']] ?? null), $rows);
}

/** For the host cards: [hostId => {total, up, warn, down, unknown, down_names}]. */
function service_card_summaries(): array {
    $out = [];
    foreach (db()->query('SELECT host_id, name, status, enabled FROM services WHERE host_id IS NOT NULL ORDER BY name') as $r) {
        $h = (int) $r['host_id'];
        $out[$h] ??= ['total' => 0, 'up' => 0, 'warn' => 0, 'down' => 0, 'other' => 0, 'downNames' => []];
        if (!$r['enabled']) continue;
        $out[$h]['total']++;
        $k = in_array($r['status'], ['up', 'warn', 'down'], true) ? $r['status'] : 'other';
        $out[$h][$k]++;
        if ($k === 'down') $out[$h]['downNames'][] = $r['name'];
    }
    return array_filter($out, fn($s) => $s['total'] > 0);
}

/**
 * Services an agent host has (from its inventory) for the "watch a service"
 * picker: {platform, version, canWatch, canRestart, services: [{name, display, state}]}.
 */
function agent_available_services(int $hostId): ?array {
    $st = db()->prepare("SELECT agent_version, JSON_UNQUOTE(JSON_EXTRACT(inventory, '$.os.id')) AS os_id,
                                JSON_EXTRACT(inventory, '$.services') AS services FROM agents WHERE host_id = ?");
    $st->execute([$hostId]);
    $a = $st->fetch();
    if (!$a) return null;
    $platform = $a['os_id'] === 'windows' ? 'windows' : 'linux';
    $ok = $a['agent_version'] && version_compare($a['agent_version'], SERVICE_WATCH_MIN_AGENT[$platform], '>=');
    $list = [];
    foreach ((array) json_decode((string) $a['services'], true) as $s) {
        if (!is_array($s) || !is_string($s['name'] ?? null) || !preg_match(SERVICE_NAME_RE, $s['name'])) continue;
        $list[] = ['name' => $s['name'], 'display' => is_string($s['display'] ?? null) ? $s['display'] : null,
                   'state' => is_string($s['state'] ?? null) ? $s['state'] : null];
    }
    usort($list, fn($x, $y) => strcasecmp($x['display'] ?? $x['name'], $y['display'] ?? $y['name']));
    return ['platform' => $platform, 'version' => $a['agent_version'], 'canWatch' => (bool) $ok,
            'minVersion' => SERVICE_WATCH_MIN_AGENT[$platform], 'services' => $list];
}
