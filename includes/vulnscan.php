<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/agents.php';    // antivirus_status(), now_ms()
require_once __DIR__ . '/scanner.php';   // snmp_probe()

/**
 * Vulnerability scanning (Security tab), OpenVAS-style but built in:
 *   - nmap (TCP connect, no root) finds open services and their versions, with
 *     a few NSE scripts for TLS, certificates, SMB, FTP, VNC, Redis, MongoDB, Docker;
 *   - built-in checks turn that into findings (cleartext logins, SMBv1, old TLS,
 *     expired certificates, services open without a password, SNMP "public");
 *   - devices without an agent: known CVEs for each service version (NVD);
 *   - hosts with an agent: pending security updates with their CVEs (OSV), the
 *     antivirus state and pending reboots, like OpenVAS's logged-in scans.
 * NVD and OSV get product names and versions only (never addresses), cached.
 * Runs in its own service (cli/vulnscan.php) so long scans never delay checks.
 * Severity from CVSS: critical 9+, high 7+, medium 4+, low >0, info 0.
 */

const VULN_SEVERITIES = ['critical', 'high', 'medium', 'low', 'info'];
const VULN_NMAP_BATCH = 6;                 // hosts per nmap run (nmap scans them in parallel)
const VULN_NMAP_TIMEOUT_SEC = 1800;
const VULN_SCRIPTS = 'ssl-cert,ssl-enum-ciphers,smb-protocols,ftp-anon,vnc-info,redis-info,mongodb-info,docker-version,http-title';
const VULN_NVD_TTL_SEC = 7 * 86400;
const VULN_OSV_TTL_SEC = 86400;
const VULN_OSV_VULN_TTL_SEC = 30 * 86400;
const VULN_MAX_CVES_LISTED = 15;
const VULN_TARGET_SEEN_DAYS = 7;           // "all devices": cards seen in this many days
// Versions nmap reports when it can't tell versions apart (every Samba 4.x answers as "4.6.2"): no CVE lookup.
const NMAP_PLACEHOLDER_VERSIONS = ['cpe:2.3:a:samba:samba:4.6.2'];

function cvss_severity(?float $score): string {
    if ($score === null) return 'info';
    return $score >= 9 ? 'critical' : ($score >= 7 ? 'high' : ($score >= 4 ? 'medium' : ($score > 0 ? 'low' : 'info')));
}

/** CVSS v3.x base score from a vector ("CVSS:3.1/AV:N/AC:L/..."), or null. */
function cvss3_base_score(string $vector): ?float {
    if (!preg_match('#^CVSS:3\.[01]/#', $vector)) return null;
    $m = [];
    foreach (explode('/', substr($vector, 9)) as $part) { [$k, $v] = array_pad(explode(':', $part, 2), 2, ''); $m[$k] = $v; }
    $w = ['AV' => ['N' => .85, 'A' => .62, 'L' => .55, 'P' => .2], 'AC' => ['L' => .77, 'H' => .44], 'UI' => ['N' => .85, 'R' => .62],
          'CIA' => ['H' => .56, 'L' => .22, 'N' => 0]];
    foreach (['AV', 'AC', 'PR', 'UI', 'S', 'C', 'I', 'A'] as $k) if (!isset($m[$k])) return null;
    $changed = $m['S'] === 'C';
    $pr = ['N' => .85, 'L' => $changed ? .68 : .62, 'H' => $changed ? .5 : .27][$m['PR']] ?? null;
    $av = $w['AV'][$m['AV']] ?? null; $ac = $w['AC'][$m['AC']] ?? null; $ui = $w['UI'][$m['UI']] ?? null;
    $c = $w['CIA'][$m['C']] ?? null; $i = $w['CIA'][$m['I']] ?? null; $a = $w['CIA'][$m['A']] ?? null;
    if (in_array(null, [$pr, $av, $ac, $ui, $c, $i, $a], true)) return null;
    $iss = 1 - (1 - $c) * (1 - $i) * (1 - $a);
    $impact = $changed ? 7.52 * ($iss - 0.029) - 3.25 * (($iss - 0.02) ** 15) : 6.42 * $iss;
    if ($impact <= 0) return 0.0;
    $expl = 8.22 * $av * $ac * $pr * $ui;
    $base = $changed ? min(1.08 * ($impact + $expl), 10) : min($impact + $expl, 10);
    $int = (int) round($base * 100000);   // CVSS 3.1 "Roundup"
    return $int % 10000 === 0 ? $int / 100000.0 : (floor($int / 10000) + 1) / 10.0;
}

// ---- queue ----

/** Queues a scan; returns its id (an already queued/running scan of the same scope is reused). */
function vuln_queue_scan(string $scope, ?int $userId): int {
    $pdo = db();
    $q = $pdo->prepare("SELECT id FROM vuln_scans WHERE scope = ? AND status IN ('queued', 'running') LIMIT 1");
    $q->execute([$scope]);
    if ($id = $q->fetchColumn()) return (int) $id;
    $pdo->prepare('INSERT INTO vuln_scans (scope, requested_by, created_at) VALUES (?, ?, ?)')->execute([$scope, $userId, now_ms()]);
    return (int) $pdo->lastInsertId();
}

/** "all", "subnet:192.168.60.0/24" or "host:12" -> [ip => host_id]. */
function vuln_targets(string $scope): array {
    $pdo = db();
    if (str_starts_with($scope, 'host:')) {
        $q = $pdo->prepare('SELECT id, ip_address FROM hosts WHERE id = ?');
        $q->execute([(int) substr($scope, 5)]);
    } elseif (str_starts_with($scope, 'subnet:')) {
        $q = $pdo->prepare('SELECT id, ip_address FROM hosts WHERE ip_address LIKE ? AND COALESCE(last_seen_at, 0) >= ?');
        $q->execute([substr($scope, 7, -4) . '%', (time() - VULN_TARGET_SEEN_DAYS * 86400) * 1000]);
    } else {
        $q = $pdo->prepare('SELECT id, ip_address FROM hosts WHERE COALESCE(last_seen_at, 0) >= ?');
        $q->execute([(time() - VULN_TARGET_SEEN_DAYS * 86400) * 1000]);
    }
    $out = [];
    foreach ($q->fetchAll() as $r) {
        if (filter_var($r['ip_address'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) $out[$r['ip_address']] ??= (int) $r['id'];
    }
    uksort($out, fn($a, $b) => ip2long($a) <=> ip2long($b));
    return $out;
}

// ---- nmap ----

function nmap_path(): ?string {
    foreach (['/usr/bin/nmap', '/usr/local/bin/nmap'] as $p) if (is_executable($p)) return $p;
    return null;
}

/** Runs nmap on a few addresses; returns parse_nmap_xml() of its output. */
function nmap_scan(array $ips): array {
    $nmap = nmap_path();
    if (!$nmap) throw new RuntimeException('nmap is not installed (dnf install nmap)');
    $cmd = array_merge([$nmap, '-sT', '-sV', '--version-light', '-Pn', '-T4', '--top-ports', '1000', '--max-retries', '2',
        '--host-timeout', '15m', '--script-timeout', '60s', '--script', VULN_SCRIPTS, '-oX', '-'], array_values($ips));
    $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!$p) throw new RuntimeException('could not start nmap');
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    $out = $err = '';
    $deadline = time() + VULN_NMAP_TIMEOUT_SEC;
    while (true) {
        $r = [$pipes[1], $pipes[2]]; $w = $x = null;
        if (@stream_select($r, $w, $x, 5) === false) break;
        foreach ($r as $s) { $chunk = (string) fread($s, 65536); if ($s === $pipes[1]) $out .= $chunk; else $err .= $chunk; }
        if (feof($pipes[1]) && feof($pipes[2])) break;
        if (time() > $deadline) { proc_terminate($p); throw new RuntimeException('nmap took too long'); }
    }
    fclose($pipes[1]); fclose($pipes[2]);
    $rc = proc_close($p);
    if ($rc !== 0 && !str_contains($out, '</nmaprun>')) throw new RuntimeException('nmap failed: ' . mb_substr(trim($err), 0, 300));
    return parse_nmap_xml($out);
}

/** An NSE <script>/<table> as nested arrays: keyed elems/tables by key, unkeyed ones as a list. */
function nmap_script_data(SimpleXMLElement $node): array {
    $data = [];
    foreach ($node->children() as $child) {
        $key = isset($child['key']) ? (string) $child['key'] : null;
        $val = $child->getName() === 'table' ? nmap_script_data($child) : trim((string) $child);
        if ($key === null) $data[] = $val; else $data[$key] = $val;
    }
    return $data;
}

/** nmap XML -> [ip => ['ports' => [...], 'hostscripts' => [id => {output, data}], 'os' => ?string]]. */
function parse_nmap_xml(string $xml): array {
    $x = @simplexml_load_string($xml);
    if (!$x) throw new RuntimeException('nmap output was not valid XML');
    $out = [];
    foreach ($x->host as $h) {
        $ip = null;
        foreach ($h->address as $a) if ((string) $a['addrtype'] === 'ipv4') $ip = (string) $a['addr'];
        if (!$ip) continue;
        $ports = [];
        $os = null;
        foreach ($h->ports->port ?? [] as $p) {
            if ((string) $p->state['state'] !== 'open') continue;
            $svc = $p->service;
            $scripts = [];
            foreach ($p->script as $s) $scripts[(string) $s['id']] = ['output' => (string) $s['output'], 'data' => nmap_script_data($s)];
            $cpes = [];
            foreach ($svc->cpe ?? [] as $c) $cpes[] = (string) $c;
            $ports[] = [
                'port' => (int) $p['portid'], 'proto' => (string) $p['protocol'],
                'name' => (string) ($svc['name'] ?? ''), 'product' => (string) ($svc['product'] ?? ''),
                'version' => (string) ($svc['version'] ?? ''), 'extrainfo' => (string) ($svc['extrainfo'] ?? ''),
                'tunnel' => (string) ($svc['tunnel'] ?? ''), 'cpes' => $cpes, 'scripts' => $scripts,
            ];
            if (!$os && !empty($svc['ostype'])) $os = (string) $svc['ostype'];
        }
        $hostscripts = [];
        foreach ($h->hostscript->script ?? [] as $s) $hostscripts[(string) $s['id']] = ['output' => (string) $s['output'], 'data' => nmap_script_data($s)];
        $out[$ip] = ['ports' => $ports, 'hostscripts' => $hostscripts, 'os' => $os];
    }
    return $out;
}

// ---- built-in checks ----

/** A finding row (scan_id/ip added when stored). */
function vuln_finding(string $check, float $cvss, string $title, string $detail, string $solution, ?array $port = null, array $refs = [], string $confidence = 'high'): array {
    return ['check_id' => $check, 'cvss' => $cvss, 'severity' => cvss_severity($cvss), 'title' => $title, 'detail' => $detail,
            'solution' => $solution, 'port' => $port['port'] ?? null,
            'service' => $port ? (trim(($port['product'] ?: $port['name']) . ' ' . $port['version']) ?: null) : null,
            'refs' => $refs, 'confidence' => $confidence];
}

/** Findings from what nmap saw on one host (pure; tested in deploy/test-vulnscan.php). */
function builtin_checks(array $host, ?int $nowSec = null): array {
    $now = $nowSec ?? time();
    $f = [];
    foreach ($host['ports'] as $p) {
        $s = $p['scripts'];
        $name = strtolower($p['name']);
        if ($p['port'] === 23 || $name === 'telnet') {
            $f[] = vuln_finding('telnet', 7.5, 'Telnet service accepts cleartext logins',
                'Telnet sends user names and passwords unencrypted; anyone on the network path can read them.',
                'Turn Telnet off and use SSH for remote administration.', $p);
        }
        if ($name === 'ftp') {
            if (str_contains($s['ftp-anon']['output'] ?? '', 'Anonymous FTP login allowed')) {
                $f[] = vuln_finding('ftp-anon', 7.5, 'Anonymous FTP login allowed', 'Anyone can log in to this FTP server without a password.',
                    'Disable anonymous FTP, or remove anything sensitive from it.', $p);
            } elseif ($p['tunnel'] !== 'ssl') {
                $f[] = vuln_finding('ftp-cleartext', 4.8, 'FTP sends passwords in cleartext', 'FTP logins are not encrypted.',
                    'Use SFTP or FTPS instead of plain FTP.', $p);
            }
        }
        if (isset($s['vnc-info']) && preg_match('/\bNone\b/', $s['vnc-info']['output'])) {
            $f[] = vuln_finding('vnc-noauth', 9.8, 'VNC allows access without a password', 'The VNC server offers the "None" security type: anyone can see and control the screen.',
                'Set a VNC password (or turn VNC off) and restrict who can reach it.', $p);
        }
        if (isset($s['redis-info']) && preg_match('/Version/i', $s['redis-info']['output'])) {
            $f[] = vuln_finding('redis-noauth', 9.8, 'Redis open without authentication', 'Anyone who can reach this port can read and change all data and often run commands.',
                'Set requirepass / ACLs, bind Redis to localhost, or firewall the port.', $p);
        }
        if (isset($s['mongodb-info']) && preg_match('/version/i', $s['mongodb-info']['output']) && !preg_match('/auth|unauthorized/i', $s['mongodb-info']['output'])) {
            $f[] = vuln_finding('mongodb-noauth', 9.8, 'MongoDB open without authentication', 'The database answers without logging in.',
                'Enable authentication (security.authorization) and restrict network access.', $p);
        }
        if (isset($s['docker-version']) && preg_match('/Version/i', $s['docker-version']['output'])) {
            $f[] = vuln_finding('docker-api', 10.0, 'Docker API open without TLS or authentication', 'Anyone who can reach this port controls Docker on the host, which means root on it.',
                'Stop exposing the Docker socket over TCP, or protect it with TLS client certificates.', $p);
        }
        if (isset($s['ssl-enum-ciphers'])) {
            $d = $s['ssl-enum-ciphers']['data'];
            if (isset($d['SSLv3'])) {
                $f[] = vuln_finding('tls-sslv3', 7.5, 'SSLv3 enabled', 'SSL 3.0 is broken (POODLE) and must not be used.', 'Disable SSLv3; allow TLS 1.2 and 1.3 only.', $p, ['CVE-2014-3566']);
            }
            $old = array_values(array_intersect(['TLSv1.0', 'TLSv1.1'], array_keys($d)));
            if ($old) {
                $f[] = vuln_finding('tls-old', 5.3, 'Outdated TLS versions enabled (' . implode(', ', $old) . ')', 'TLS 1.0 and 1.1 are deprecated and have known weaknesses.',
                    'Allow TLS 1.2 and 1.3 only.', $p);
            }
            $grade = $d['least strength'] ?? null;
            if (in_array($grade, ['D', 'E', 'F'], true)) {
                $f[] = vuln_finding('tls-weak-ciphers', 5.9, "Weak TLS ciphers offered (grade $grade)", 'The server offers ciphers that are weak or broken.',
                    'Remove weak ciphers (RC4, 3DES, export and NULL ciphers) from the TLS configuration.', $p);
            }
        }
        if (isset($s['ssl-cert'])) {
            $d = $s['ssl-cert']['data'];
            $notAfter = isset($d['validity']['notAfter']) ? strtotime($d['validity']['notAfter']) : false;
            if ($notAfter && $notAfter < $now) {
                $f[] = vuln_finding('cert-expired', 5.3, 'TLS certificate expired', 'The certificate expired on ' . gmdate('Y-m-d', $notAfter) . '.',
                    'Renew the certificate.', $p);
            } elseif ($notAfter && $notAfter < $now + 30 * 86400) {
                $f[] = vuln_finding('cert-expiring', 3.1, 'TLS certificate expires within 30 days', 'The certificate expires on ' . gmdate('Y-m-d', $notAfter) . '.',
                    'Renew the certificate before it expires.', $p);
            }
            if (!empty($d['subject']) && $d['subject'] === ($d['issuer'] ?? null)) {
                $f[] = vuln_finding('cert-self-signed', 0.0, 'Self-signed TLS certificate', 'Browsers warn about it, and users learn to click through warnings.',
                    'Use a certificate from your own CA or a public one if the service is used by people.', $p);
            }
        }
        $title = $s['http-title']['data']['title'] ?? (preg_match('/^(.*?)(\n|$)/', $s['http-title']['output'] ?? '', $mt) ? $mt[1] : '');
        if (in_array($name, ['http', 'http-alt', 'http-proxy'], true) && $p['tunnel'] !== 'ssl'
            && preg_match('/log ?in|sign ?in|admin|router|configuration|management|dashboard/i', $title)) {
            $f[] = vuln_finding('http-login', 3.7, 'Login page served over unencrypted HTTP', "\"$title\": passwords typed here travel unencrypted.",
                'Serve the admin interface over HTTPS only.', $p);
        }
        if (in_array($p['port'], [3306, 5432, 1433, 1521, 9200, 5984], true)) {
            $f[] = vuln_finding('db-exposed', 0.0, 'Database port reachable from the network', "Port {$p['port']} accepts connections from this network.",
                'Bind the database to localhost or firewall it to the servers that need it.', $p);
        }
        if ($p['port'] === 3389 || $name === 'ms-wbt-server') {
            $f[] = vuln_finding('rdp-exposed', 0.0, 'Remote Desktop reachable', 'RDP is reachable from this network; it is a common target for password guessing.',
                'Require Network Level Authentication and limit who can reach port 3389.', $p);
        }
    }
    $smb = $host['hostscripts']['smb-protocols'] ?? null;
    if ($smb && (str_contains($smb['output'], 'SMBv1') || str_contains($smb['output'], 'NT LM 0.12'))) {
        $f[] = vuln_finding('smbv1', 8.1, 'SMBv1 enabled', 'SMB version 1 is obsolete and was used by WannaCry and NotPetya (EternalBlue).',
            'Disable SMBv1 (Windows: Disable-WindowsOptionalFeature SMB1Protocol; Samba: server min protocol = SMB2).', null, ['CVE-2017-0144']);
    }
    return $f;
}

/** SNMP answering the default community. */
function snmp_public_finding(string $ip): ?array {
    $s = snmp_probe($ip, 'public', 0.8);
    if (!$s) return null;
    return vuln_finding('snmp-public', 5.3, 'SNMP readable with the default "public" community',
        'Anyone on the network can read the device\'s configuration and network details over SNMP.',
        'Change the community to a private value, or use SNMPv3; restrict SNMP to your monitoring server.');
}

// ---- external databases (NVD, OSV) ----

function vuln_cache_get(string $key, int $ttlSec): ?array {
    $q = db()->prepare('SELECT fetched_at, data FROM vuln_cache WHERE k = ?');
    $q->execute([substr($key, 0, 190)]);
    $r = $q->fetch();
    return $r && (int) $r['fetched_at'] > now_ms() - $ttlSec * 1000 ? json_decode($r['data'], true) : null;
}

function vuln_cache_put(string $key, array $data): void {
    db()->prepare('INSERT INTO vuln_cache (k, fetched_at, data) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE fetched_at = VALUES(fetched_at), data = VALUES(data)')
        ->execute([substr($key, 0, 190), now_ms(), json_encode($data)]);
}

/** GET (or POST JSON) -> [status, decoded body]. */
function vuln_http_json(string $url, ?array $post = null): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_USERAGENT => 'WebMonitor vulnerability scan', CURLOPT_HTTPHEADER => ['Accept: application/json', 'Content-Type: application/json']]);
    if ($post !== null) { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($post)); }
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    return [$code, is_string($body) ? json_decode($body, true) : null];
}

/** nmap "cpe:/a:openbsd:openssh:8.9p1" -> NVD match string "cpe:2.3:a:openbsd:openssh:8.9:p1", or null without a version. */
function nmap_cpe_to_23(string $cpe): ?string {
    if (!preg_match('#^cpe:/([aoh]):([^:]+):([^:]+):([^:]+)(?::([^:]+))?#', strtolower($cpe), $m)) return null;
    [$part, $vendor, $product, $version, $update] = [$m[1], $m[2], $m[3], $m[4], $m[5] ?? ''];
    if ($update === '' && preg_match('/^([\d.]+)(p\d+)$/', $version, $v)) [$version, $update] = [$v[1], $v[2]];   // OpenSSH 8.9p1
    if (!preg_match('/\d/', $version)) return null;
    return "cpe:2.3:$part:$vendor:$product:$version" . ($update !== '' ? ":$update" : '');
}

/** Known CVEs for a product version (NVD), highest score first: [{id, score, summary}]. */
function nvd_cves(string $cpe23): array {
    static $last = 0.0;
    if (($c = vuln_cache_get("nvd:$cpe23", VULN_NVD_TTL_SEC)) !== null) return $c;
    $url = 'https://services.nvd.nist.gov/rest/json/cves/2.0?noRejected&resultsPerPage=500&virtualMatchString=' . rawurlencode($cpe23);
    for ($try = 0; $try < 3; $try++) {
        $wait = 6.5 - (microtime(true) - $last);   // public rate limit: 5 requests per 30 s
        if ($wait > 0) usleep((int) ($wait * 1e6));
        $last = microtime(true);
        [$code, $j] = vuln_http_json($url);
        if ($code === 200 && is_array($j)) break;
        if ($code === 404) { $j = ['vulnerabilities' => []]; break; }
        sleep(30);   // 403/429/5xx: back off
    }
    if (!is_array($j ?? null)) throw new RuntimeException('NVD did not answer');
    $out = [];
    foreach ($j['vulnerabilities'] ?? [] as $v) {
        $cve = $v['cve'] ?? [];
        $score = null;
        foreach (['cvssMetricV31', 'cvssMetricV30', 'cvssMetricV40', 'cvssMetricV2'] as $k) {
            if (isset($cve['metrics'][$k][0]['cvssData']['baseScore'])) { $score = (float) $cve['metrics'][$k][0]['cvssData']['baseScore']; break; }
        }
        $summary = '';
        foreach ($cve['descriptions'] ?? [] as $d) if (($d['lang'] ?? '') === 'en') { $summary = $d['value']; break; }
        $out[] = ['id' => $cve['id'] ?? '?', 'score' => $score, 'summary' => mb_substr($summary, 0, 300)];
    }
    usort($out, fn($a, $b) => ($b['score'] ?? 0) <=> ($a['score'] ?? 0));
    vuln_cache_put("nvd:$cpe23", $out);
    return $out;
}

/** One finding per service with known CVEs (devices without an agent). */
function service_cve_findings(array $host): array {
    $f = [];
    $done = [];
    foreach ($host['ports'] as $p) {
        foreach ($p['cpes'] as $cpe) {
            $c23 = nmap_cpe_to_23($cpe);
            if (!$c23 || isset($done[$c23]) || in_array($c23, NMAP_PLACEHOLDER_VERSIONS, true)) continue;
            $done[$c23] = true;
            $cves = nvd_cves($c23);
            if (!$cves) continue;
            $top = $cves[0]['score'] ?? 0.0;
            $label = trim(($p['product'] ?: $p['name']) . ' ' . $p['version']);
            $list = array_slice($cves, 0, VULN_MAX_CVES_LISTED);
            $detail = count($cves) . " known vulnerabilit" . (count($cves) === 1 ? 'y' : 'ies') . " for $label (highest CVSS " . number_format((float) $top, 1) . ").\n"
                . implode("\n", array_map(fn($c) => "{$c['id']} (" . ($c['score'] !== null ? number_format($c['score'], 1) : '?') . "): {$c['summary']}", array_slice($list, 0, 5)))
                . "\nMatched by version: a vendor may have fixed some without changing the version number.";
            $f[] = vuln_finding('cve-service', (float) $top, "$label: " . count($cves) . ' known vulnerabilit' . (count($cves) === 1 ? 'y' : 'ies'),
                $detail, "Update $label to the vendor's latest version.", $p, array_column($list, 'id'), 'medium');
        }
    }
    return $f;
}

/** OSV ecosystem for an agent's OS, or null. */
function osv_ecosystem(array $os): ?string {
    $id = strtolower((string) ($os['id'] ?? ''));
    $ver = (string) ($os['version_id'] ?? '');
    $major = strtok($ver, '.');
    return match ($id) {
        'debian' => $major ? "Debian:$major" : null,
        'ubuntu' => $ver ? "Ubuntu:$ver" . (preg_match('/^\d*[02468]\.04$/', $ver) ? ':LTS' : '') : null,
        'rocky' => $major ? "Rocky Linux:$major" : null,
        'almalinux' => $major ? "AlmaLinux:$major" : null,
        'alpine' => $ver ? 'Alpine:v' . implode('.', array_slice(explode('.', $ver), 0, 2)) : null,
        default => null,
    };
}

/** OSV entry's score: CVSS v3 vector, else the distribution's own rating. */
function osv_vuln_score(array $v): ?float {
    foreach ($v['severity'] ?? [] as $s) {
        if (str_starts_with((string) ($s['type'] ?? ''), 'CVSS_V3') && ($sc = cvss3_base_score((string) $s['score'])) !== null) return $sc;
    }
    $text = strtolower((string) ($v['database_specific']['severity'] ?? $v['affected'][0]['ecosystem_specific']['urgency'] ?? ''));
    return ['critical' => 9.5, 'important' => 8.0, 'high' => 8.0, 'moderate' => 5.5, 'medium' => 5.5, 'low' => 3.0, 'unimportant' => 0.0][$text] ?? null;
}

/** For each package [name, current version]: [{id, score, aliases}] of vulns OSV knows that have a fix. */
function osv_package_vulns(string $ecosystem, array $packages): array {
    $out = $ask = [];
    foreach ($packages as [$name, $version]) {
        $c = vuln_cache_get("osvq:$ecosystem:$name:$version", VULN_OSV_TTL_SEC);
        if ($c !== null) $out[$name] = $c; else $ask[] = [$name, $version];
    }
    foreach (array_chunk($ask, 500) as $chunk) {
        [$code, $j] = vuln_http_json('https://api.osv.dev/v1/querybatch', ['queries' => array_map(
            fn($p) => ['package' => ['name' => $p[0], 'ecosystem' => $ecosystem], 'version' => $p[1]], $chunk)]);
        if ($code !== 200 || !is_array($j['results'] ?? null)) throw new RuntimeException('OSV did not answer');
        foreach ($chunk as $i => [$name, $version]) {
            $vulns = [];
            foreach (array_slice($j['results'][$i]['vulns'] ?? [], 0, 25) as $ref) {
                $v = vuln_cache_get('osv:' . $ref['id'], VULN_OSV_VULN_TTL_SEC);
                if ($v === null) {
                    [$vc, $vj] = vuln_http_json('https://api.osv.dev/v1/vulns/' . rawurlencode($ref['id']));
                    if ($vc !== 200 || !is_array($vj)) continue;
                    $fixed = false;
                    foreach ($vj['affected'] ?? [] as $a) foreach ($a['ranges'] ?? [] as $r) foreach ($r['events'] ?? [] as $e) if (isset($e['fixed'])) $fixed = true;
                    $v = ['id' => $vj['id'], 'score' => osv_vuln_score($vj), 'fixed' => $fixed,
                          'aliases' => array_values(array_filter($vj['aliases'] ?? [], fn($a) => str_starts_with($a, 'CVE-')))];
                    vuln_cache_put('osv:' . $ref['id'], $v);
                }
                if ($v['fixed']) $vulns[] = $v;
            }
            vuln_cache_put("osvq:$ecosystem:$name:$version", $vulns);
            $out[$name] = $vulns;
        }
    }
    return $out;
}

/** Findings from a host's agent: pending security updates (with CVEs), antivirus, reboot. */
function agent_findings(int $hostId): array {
    $q = db()->prepare('SELECT inventory, inventory_at FROM agents WHERE host_id = ?');
    $q->execute([$hostId]);
    $r = $q->fetch();
    if (!$r || !$r['inventory'] || (int) $r['inventory_at'] < now_ms() - 2 * 86400000) return [];
    $inv = json_decode($r['inventory'], true) ?: [];
    $windows = ($inv['os']['id'] ?? '') === 'windows';
    $f = [];
    $pkgs = is_array($inv['updates']['packages'] ?? null) ? $inv['updates']['packages'] : [];
    $security = array_values(array_filter($pkgs, fn($p) => !empty($p['security'])));
    $osv = [];
    $eco = $windows ? null : osv_ecosystem((array) ($inv['os'] ?? []));
    if ($eco && $security) {
        $withVersion = array_values(array_filter(array_map(fn($p) => [(string) $p['name'], (string) ($p['current'] ?? '')], $security), fn($p) => $p[1] !== ''));
        try { $osv = osv_package_vulns($eco, $withVersion); } catch (Throwable $e) { $osv = []; }
    }
    foreach ($security as $p) {
        $name = (string) $p['name'];
        $vulns = $osv[$name] ?? [];
        $scores = array_filter(array_column($vulns, 'score'), fn($s) => $s !== null);
        $score = $scores ? max($scores) : 7.5;   // a security update with no score: treat as high
        $cves = [];
        foreach ($vulns as $v) foreach ($v['aliases'] ?: [$v['id']] as $c) $cves[$c] = true;
        $cves = array_keys($cves);
        $label = $windows && !empty($p['new']) ? "{$p['new']}: $name" : $name;
        $detail = $windows ? "Windows security update not installed: $name."
            : "Installed $name " . ($p['current'] ?? '?') . '; security update to ' . ($p['new'] ?? '?') . ' available.'
              . ($vulns ? ' Fixes ' . count($vulns) . ' known vulnerabilit' . (count($vulns) === 1 ? 'y' : 'ies') . '.' : '');
        $f[] = vuln_finding('missing-update', (float) $score, ($windows ? 'Missing security update ' : 'Security update pending: ') . mb_substr($label, 0, 200),
            $detail, 'Install updates (Updates tab, or the host\'s package manager).', null, array_slice($cves, 0, VULN_MAX_CVES_LISTED));
    }
    $others = count($pkgs) - count($security);
    if ($others > 0) {
        $f[] = vuln_finding('other-updates', 0.0, "$others other update" . ($others === 1 ? '' : 's') . ' pending', 'Updates that are not marked as security fixes.',
            'Install updates when convenient.');
    }
    if (!empty($inv['reboot_required'])) {
        $f[] = vuln_finding('reboot-pending', 3.1, 'Reboot needed to finish updates', 'Some installed fixes (often the kernel) only take effect after a reboot.',
            'Reboot the host in a maintenance window.');
    }
    $av = antivirus_status($inv['antivirus'] ?? null, $windows ? 'windows' : 'linux');
    if ($av && $av['state'] === 'off') {
        $f[] = vuln_finding('antivirus-off', 7.5, 'Antivirus: ' . $av['text'], 'No running antivirus protection on this host.', 'Turn on Microsoft Defender or install an antivirus product.');
    } elseif ($av && $av['state'] === 'warn') {
        $f[] = vuln_finding('antivirus-warn', 5.0, 'Antivirus: ' . $av['text'], 'Antivirus protection is not fully working.', 'Update antivirus signatures and turn real-time protection on.');
    }
    return $f;
}

// ---- worker ----

/** Background worker (cli/vulnscan.php): runs queued scans one after another. */
function run_vuln_scans(): void {
    $pdo = db();
    if (!(int) $pdo->query("SELECT GET_LOCK('webmonitor_vulnscan', 0)")->fetchColumn()) return;
    try {
        // A scan left "running" by a worker that died.
        $pdo->exec("UPDATE vuln_scans SET status = 'failed', error = 'interrupted', finished_at = " . now_ms() . " WHERE status = 'running'");
        while ($scan = $pdo->query("SELECT * FROM vuln_scans WHERE status = 'queued' ORDER BY id LIMIT 1")->fetch()) {
            run_vuln_scan($scan);
        }
    } finally {
        $pdo->query("SELECT RELEASE_LOCK('webmonitor_vulnscan')");
    }
}

function run_vuln_scan(array $scan): void {
    $pdo = db();
    $id = (int) $scan['id'];
    $targets = vuln_targets($scan['scope']);
    $pdo->prepare("UPDATE vuln_scans SET status = 'running', started_at = ?, total_hosts = ?, done_hosts = 0 WHERE id = ?")
        ->execute([now_ms(), count($targets), $id]);
    $agentHosts = array_flip(array_map('intval', $pdo->query('SELECT host_id FROM agents')->fetchAll(PDO::FETCH_COLUMN)));
    $done = 0;
    try {
        if (!nmap_path()) throw new RuntimeException('nmap is not installed on the server (dnf install nmap)');
        foreach (array_chunk(array_keys($targets), VULN_NMAP_BATCH) as $batch) {
            $status = $pdo->query("SELECT status FROM vuln_scans WHERE id = $id")->fetchColumn();
            if ($status === 'cancelled') return;
            $pdo->prepare('UPDATE vuln_scans SET current = ? WHERE id = ?')->execute([mb_substr(implode(', ', $batch), 0, 255), $id]);
            $results = nmap_scan($batch);
            foreach ($batch as $ip) {
                $hostId = $targets[$ip];
                $h = $results[$ip] ?? ['ports' => [], 'hostscripts' => [], 'os' => null];
                $error = null;
                $findings = builtin_checks($h);
                if ($sp = snmp_public_finding($ip)) $findings[] = $sp;
                try {
                    $findings = array_merge($findings, $hostId && isset($agentHosts[$hostId]) ? agent_findings($hostId) : service_cve_findings($h));
                } catch (Throwable $e) {
                    $error = 'CVE lookup failed: ' . $e->getMessage();
                }
                $ports = implode(',', array_map(fn($p) => $p['port'], $h['ports']));
                $pdo->prepare('INSERT INTO vuln_hosts (scan_id, ip, host_id, scanned_at, open_ports, os_guess, error) VALUES (?, ?, ?, ?, ?, ?, ?)')
                    ->execute([$id, $ip, $hostId, now_ms(), mb_substr($ports, 0, 1000) ?: null, $h['os'] ? mb_substr($h['os'], 0, 120) : null, $error]);
                $ins = $pdo->prepare('INSERT INTO vuln_findings (scan_id, ip, port, service, check_id, severity, cvss, title, detail, solution, refs, confidence)
                                      VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
                foreach ($findings as $f) {
                    $ins->execute([$id, $ip, $f['port'], $f['service'] ? mb_substr($f['service'], 0, 120) : null, $f['check_id'], $f['severity'], $f['cvss'],
                        mb_substr($f['title'], 0, 255), $f['detail'], mb_substr($f['solution'], 0, 1000), $f['refs'] ? json_encode($f['refs']) : null, $f['confidence']]);
                }
                $done++;
            }
            $pdo->prepare('UPDATE vuln_scans SET done_hosts = ? WHERE id = ?')->execute([$done, $id]);
        }
        $pdo->prepare("UPDATE vuln_scans SET status = 'done', finished_at = ?, current = NULL WHERE id = ?")->execute([now_ms(), $id]);
    } catch (Throwable $e) {
        $pdo->prepare("UPDATE vuln_scans SET status = 'failed', finished_at = ?, error = ?, current = NULL WHERE id = ?")
            ->execute([now_ms(), mb_substr($e->getMessage(), 0, 500), $id]);
    }
}

// ---- report ----

/** Current results: each address's newest scanned result. */
function vuln_report(): array {
    $pdo = db();
    $hostNames = [];
    foreach ($pdo->query('SELECT id, name, ip_address FROM hosts') as $h) $hostNames[$h['ip_address']] = ['id' => (int) $h['id'], 'name' => $h['name']];
    $latest = $pdo->query('SELECT v.* FROM vuln_hosts v JOIN (SELECT ip, MAX(scanned_at) m FROM vuln_hosts GROUP BY ip) l
                           ON l.ip = v.ip AND l.m = v.scanned_at')->fetchAll();
    $hosts = [];
    $summary = array_fill_keys(VULN_SEVERITIES, 0);
    $findings = [];
    $q = $pdo->prepare('SELECT * FROM vuln_findings WHERE scan_id = ? AND ip = ? ORDER BY cvss DESC, title');
    foreach ($latest as $v) {
        $q->execute([$v['scan_id'], $v['ip']]);
        $counts = array_fill_keys(VULN_SEVERITIES, 0);
        foreach ($q->fetchAll() as $f) {
            $counts[$f['severity']]++;
            $summary[$f['severity']]++;
            $findings[] = ['id' => (int) $f['id'], 'ip' => $f['ip'], 'port' => $f['port'] !== null ? (int) $f['port'] : null, 'service' => $f['service'],
                'check' => $f['check_id'], 'severity' => $f['severity'], 'cvss' => $f['cvss'] !== null ? (float) $f['cvss'] : null, 'title' => $f['title'],
                'detail' => $f['detail'], 'solution' => $f['solution'], 'refs' => $f['refs'] ? json_decode($f['refs'], true) : [],
                'confidence' => $f['confidence'], 'source' => $f['source']];
        }
        $n = $hostNames[$v['ip']] ?? null;
        $hosts[] = ['ip' => $v['ip'], 'hostId' => $n['id'] ?? null, 'name' => $n && $n['name'] !== $v['ip'] ? $n['name'] : null,
            'scannedAt' => (int) $v['scanned_at'], 'openPorts' => $v['open_ports'] ? array_map('intval', explode(',', $v['open_ports'])) : [],
            'os' => $v['os_guess'], 'error' => $v['error'], 'counts' => $counts,
            'risk' => $counts['critical'] * 1000000 + $counts['high'] * 10000 + $counts['medium'] * 100 + $counts['low']];
    }
    usort($hosts, fn($a, $b) => $b['risk'] <=> $a['risk'] ?: ip2long($a['ip']) <=> ip2long($b['ip']));
    $scans = array_map(fn($s) => ['id' => (int) $s['id'], 'scope' => $s['scope'], 'status' => $s['status'], 'createdAt' => (int) $s['created_at'],
        'startedAt' => $s['started_at'] ? (int) $s['started_at'] : null, 'finishedAt' => $s['finished_at'] ? (int) $s['finished_at'] : null,
        'total' => (int) $s['total_hosts'], 'done' => (int) $s['done_hosts'], 'current' => $s['current'], 'error' => $s['error']],
        $pdo->query('SELECT * FROM vuln_scans ORDER BY id DESC LIMIT 10')->fetchAll());
    return ['summary' => $summary, 'hosts' => $hosts, 'findings' => $findings, 'scans' => $scans, 'nmap' => nmap_path() !== null,
            'subnets' => subnet_names(), 'generatedAt' => now_ms()];
}
