<?php
require_once __DIR__ . '/db.php';

/**
 * Internet speed tests from the server (Network tab).
 *
 * Engines:
 *   cloudflare  built in: download, upload and latency against
 *               speed.cloudflare.com over HTTPS, using PHP's curl. Nothing to install.
 *   ookla       the official Speedtest CLI ("speedtest", speedtest.net servers),
 *               when an admin has installed it on the server.
 *
 * Tests run on the schedule set by an admin (every N minutes, or off) or on
 * demand. Its own timer (webmonitor-speedtest.timer, cli/speedtest.php
 * --if-due) starts them, since a test takes 20-40 seconds and must not hold
 * up cli/cron.php; the lock below keeps it to one at a time.
 */

const SPEEDTEST_LOCK = 'webmonitor_speedtest';
const SPEEDTEST_INTERVALS = [0, 15, 30, 60, 120, 180, 240, 360, 720, 1440];   // minutes; 0 = off
const SPEEDTEST_DEFAULTS = [
    'interval_min' => 180,
    'engine' => 'cloudflare',
    'streams' => 6,            // parallel connections (cloudflare)
    'seconds' => 8,            // per direction (cloudflare)
    'min_down_mbps' => 0,      // alert when two tests in a row are slower than this (0 = off)
    'min_up_mbps' => 0,
    'keep_days' => 400,
];
const SPEEDTEST_CF = 'https://speed.cloudflare.com';
const SPEEDTEST_CHUNK = 25000000;   // bytes per download request / upload body

function speedtest_settings(): array {
    $saved = json_decode((string) db()->query("SELECT v FROM settings WHERE k = 'speedtest'")->fetchColumn(), true) ?: [];
    $out = SPEEDTEST_DEFAULTS;
    foreach ($saved as $k => $v) if (array_key_exists($k, $out)) $out[$k] = is_string(SPEEDTEST_DEFAULTS[$k]) ? (string) $v : (int) $v;
    return $out;
}

function save_speedtest_settings(array $in): array {
    $s = speedtest_settings();
    if (isset($in['interval_min'])) {
        $s['interval_min'] = (int) $in['interval_min'];
        if (!in_array($s['interval_min'], SPEEDTEST_INTERVALS, true)) throw new InvalidArgumentException('Pick one of the offered intervals.');
    }
    if (isset($in['engine'])) {
        if (!array_key_exists($in['engine'], speedtest_engines())) throw new InvalidArgumentException('Unknown engine.');
        if ($in['engine'] === 'ookla' && !speedtest_engines()['ookla']) throw new InvalidArgumentException('The Speedtest CLI is not installed on the server.');
        $s['engine'] = $in['engine'];
    }
    foreach (['streams' => [1, 16], 'seconds' => [4, 20], 'min_down_mbps' => [0, 100000], 'min_up_mbps' => [0, 100000], 'keep_days' => [7, 3650]] as $k => [$lo, $hi]) {
        if (isset($in[$k])) $s[$k] = max($lo, min($hi, (int) $in[$k]));
    }
    db()->prepare("INSERT INTO settings (k, v) VALUES ('speedtest', ?) ON DUPLICATE KEY UPDATE v = VALUES(v)")->execute([json_encode($s)]);
    return $s;
}

/** Path of the Ookla Speedtest CLI, or null. */
function ookla_cli_path(): ?string {
    foreach (['/usr/bin/speedtest', '/usr/local/bin/speedtest'] as $p) {
        if (is_executable($p)) {
            // speedtest-cli (the unofficial Python one) also installs as "speedtest" on some systems; it lacks --accept-license.
            $v = (string) shell_exec(escapeshellarg($p) . ' --version 2>&1');
            if (stripos($v, 'Ookla') !== false) return $p;
        }
    }
    return null;
}

/** ['cloudflare' => true, 'ookla' => bool]. */
function speedtest_engines(): array {
    return ['cloudflare' => function_exists('curl_multi_init'), 'ookla' => ookla_cli_path() !== null];
}

function speedtest_running(): bool {
    $st = db()->prepare('SELECT IS_USED_LOCK(?)');
    $st->execute([SPEEDTEST_LOCK]);
    return $st->fetchColumn() !== null;
}

function speedtest_requested(): bool {
    return (bool) db()->query("SELECT v FROM settings WHERE k = 'speedtest_requested'")->fetchColumn();
}

function request_speedtest(): void {
    db()->exec("INSERT INTO settings (k, v) VALUES ('speedtest_requested', '1') ON DUPLICATE KEY UPDATE v = '1'");
}

/** When the next scheduled test is due (ms), or null when scheduling is off. */
function speedtest_next_due(array $s): ?int {
    if ($s['interval_min'] <= 0) return null;
    $last = (int) db()->query('SELECT MAX(ts) FROM speedtests WHERE manual = 0')->fetchColumn();
    return $last ? $last + $s['interval_min'] * 60000 : (int) round(microtime(true) * 1000);
}

/**
 * What should run now: 'manual' (someone pressed Run now), 'scheduled' (the
 * next scheduled test is due) or null. Checked every minute by
 * webmonitor-speedtest.timer (cli/speedtest.php --if-due).
 */
function speedtest_due_now(): ?string {
    $pdo = db();
    if (!(int) $pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'speedtests'")->fetchColumn()) return null;
    if (speedtest_running()) return null;
    if (speedtest_requested()) return 'manual';
    $due = speedtest_next_due(speedtest_settings());
    return $due !== null && $due <= microtime(true) * 1000 ? 'scheduled' : null;
}

/** Runs one test now with the configured engine and stores it. Returns the stored row. */
function run_speedtest(bool $manual): array {
    $pdo = db();
    $st = $pdo->prepare('SELECT GET_LOCK(?, 0)');
    $st->execute([SPEEDTEST_LOCK]);
    if (!(int) $st->fetchColumn()) throw new RuntimeException('a speed test is already running');
    try {
        if ($manual) $pdo->exec("DELETE FROM settings WHERE k = 'speedtest_requested'");
        $s = speedtest_settings();
        $engine = $s['engine'] === 'ookla' && ookla_cli_path() ? 'ookla' : 'cloudflare';
        $start = (int) round(microtime(true) * 1000);
        try {
            $r = $engine === 'ookla' ? speedtest_ookla() : speedtest_cloudflare($s['streams'], $s['seconds']);
            $r['error'] = null;
        } catch (Throwable $e) {
            $r = ['error' => mb_substr($e->getMessage(), 0, 500)];
        }
        $cols = ['down_mbps', 'up_mbps', 'ping_ms', 'jitter_ms', 'loss_pct', 'bytes_used', 'server', 'isp', 'public_ip', 'result_url', 'error'];
        $vals = array_map(fn($c) => $r[$c] ?? null, $cols);
        $pdo->prepare('INSERT INTO speedtests (ts, engine, manual, duration_ms, ' . implode(', ', $cols) . ') VALUES (?, ?, ?, ?, '
            . implode(', ', array_fill(0, count($cols), '?')) . ')')
            ->execute(array_merge([$start, $engine, $manual ? 1 : 0, (int) round(microtime(true) * 1000) - $start], $vals));
        $id = (int) $pdo->lastInsertId();
        $pdo->prepare('DELETE FROM speedtests WHERE ts < ?')->execute([$start - $s['keep_days'] * 86400000]);
        $row = $pdo->query("SELECT * FROM speedtests WHERE id = $id")->fetch();
        return $row;
    } finally {
        $pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([SPEEDTEST_LOCK]);
    }
}

// ---- Ookla Speedtest CLI ----

function speedtest_ookla(): array {
    $bin = ookla_cli_path();
    if (!$bin) throw new RuntimeException('the Speedtest CLI is not installed');
    // HOME must be writable: the CLI stores its licence acceptance there.
    $home = sys_get_temp_dir() . '/webmonitor-speedtest';
    @mkdir($home, 0700, true);
    $cmd = 'HOME=' . escapeshellarg($home) . ' timeout 120 ' . escapeshellarg($bin) . ' --accept-license --accept-gdpr -f json -p no 2>&1';
    $raw = (string) shell_exec($cmd);
    $j = null;
    foreach (array_reverse(preg_split('/\R/', trim($raw))) as $line) {
        if (($d = json_decode($line, true)) && ($d['type'] ?? '') === 'result') { $j = $d; break; }
    }
    if (!$j) {
        $msg = '';
        foreach (preg_split('/\R/', trim($raw)) as $line) if (($d = json_decode($line, true)) && isset($d['message'])) $msg = $d['message'];
        throw new RuntimeException('Speedtest CLI: ' . ($msg ?: mb_substr(trim($raw), 0, 200) ?: 'no result'));
    }
    return [
        'down_mbps' => round(($j['download']['bandwidth'] ?? 0) * 8 / 1e6, 2),
        'up_mbps' => round(($j['upload']['bandwidth'] ?? 0) * 8 / 1e6, 2),
        'ping_ms' => isset($j['ping']['latency']) ? round($j['ping']['latency'], 1) : null,
        'jitter_ms' => isset($j['ping']['jitter']) ? round($j['ping']['jitter'], 1) : null,
        'loss_pct' => isset($j['packetLoss']) ? round($j['packetLoss'], 1) : null,
        'bytes_used' => (int) (($j['download']['bytes'] ?? 0) + ($j['upload']['bytes'] ?? 0)),
        'server' => mb_substr(trim(($j['server']['name'] ?? '') . ' · ' . ($j['server']['location'] ?? ''), ' ·'), 0, 160),
        'isp' => mb_substr((string) ($j['isp'] ?? ''), 0, 160) ?: null,
        'public_ip' => $j['interface']['externalIp'] ?? null,
        'result_url' => $j['result']['url'] ?? null,
    ];
}

// ---- Built-in test against speed.cloudflare.com ----

function speedtest_curl(string $url): CurlHandle {
    $h = curl_init($url);
    curl_setopt_array($h, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_USERAGENT => 'WebMonitor speed test',
        CURLOPT_HTTPHEADER => ['Referer: ' . SPEEDTEST_CF . '/'],
        CURLOPT_ENCODING => 'identity',
    ]);
    return $h;
}

function speedtest_cloudflare(int $streams, int $seconds): array {
    // Who and where.
    $h = speedtest_curl(SPEEDTEST_CF . '/meta');
    $meta = json_decode((string) curl_exec($h), true) ?: [];
    if (curl_errno($h)) throw new RuntimeException('cannot reach speed.cloudflare.com: ' . curl_error($h));

    // Latency: TCP connect time of fresh connections (one round trip each).
    $rtts = [];
    $fails = 0;
    for ($i = 0; $i < 12; $i++) {
        $h = speedtest_curl(SPEEDTEST_CF . '/__down?bytes=0');
        curl_setopt_array($h, [CURLOPT_FRESH_CONNECT => true, CURLOPT_FORBID_REUSE => true, CURLOPT_TIMEOUT => 5]);
        curl_exec($h);
        if (curl_errno($h)) { $fails++; continue; }
        $connect = curl_getinfo($h, CURLINFO_CONNECT_TIME_T) - curl_getinfo($h, CURLINFO_NAMELOOKUP_TIME_T);
        if ($connect > 0) $rtts[] = $connect / 1000;
    }
    if (!$rtts) throw new RuntimeException('no answer from speed.cloudflare.com');
    $sorted = $rtts;
    sort($sorted);
    $ping = $sorted[intdiv(count($sorted), 2)];
    $jitter = 0.0;
    for ($i = 1; $i < count($rtts); $i++) $jitter += abs($rtts[$i] - $rtts[$i - 1]);
    $jitter = count($rtts) > 1 ? $jitter / (count($rtts) - 1) : 0.0;

    [$down, $bytesDown] = speedtest_transfer('down', $streams, $seconds);
    [$up, $bytesUp] = speedtest_transfer('up', $streams, $seconds);

    $colo = $meta['colo'] ?? null;
    return [
        'down_mbps' => round($down, 2),
        'up_mbps' => round($up, 2),
        'ping_ms' => round($ping, 1),
        'jitter_ms' => round($jitter, 1),
        'loss_pct' => round($fails / 12 * 100, 1),
        'bytes_used' => $bytesDown + $bytesUp,
        'server' => 'Cloudflare' . (is_array($colo) ? ' ' . trim(($colo['iata'] ?? '') . ' ' . ($colo['city'] ?? '')) : (is_string($colo) ? " $colo" : '')),
        'isp' => isset($meta['asOrganization']) ? mb_substr($meta['asOrganization'], 0, 160) : null,
        'public_ip' => $meta['clientIp'] ?? null,
        'result_url' => null,
    ];
}

/**
 * Saturates the line with $streams parallel transfers for $seconds and
 * returns [Mbit/s, bytes moved]. The first second and a half (TCP ramp-up)
 * is left out of the rate.
 */
function speedtest_transfer(string $dir, int $streams, int $seconds): array {
    $moved = 0;
    $payload = $dir === 'up' ? random_bytes(1 << 20) : '';
    $mh = curl_multi_init();
    $handles = [];
    $make = function () use ($dir, &$moved, $payload): CurlHandle {
        if ($dir === 'down') {
            $h = speedtest_curl(SPEEDTEST_CF . '/__down?bytes=' . SPEEDTEST_CHUNK);
            curl_setopt($h, CURLOPT_RETURNTRANSFER, false);
            curl_setopt($h, CURLOPT_WRITEFUNCTION, function ($ch, $data) use (&$moved) { $len = strlen($data); $moved += $len; return $len; });
        } else {
            $h = speedtest_curl(SPEEDTEST_CF . '/__up');
            $sent = 0;
            curl_setopt_array($h, [
                CURLOPT_UPLOAD => true, CURLOPT_CUSTOMREQUEST => 'POST', CURLOPT_INFILESIZE => SPEEDTEST_CHUNK,
                CURLOPT_HTTPHEADER => ['Referer: ' . SPEEDTEST_CF . '/', 'Content-Type: application/octet-stream'],
                CURLOPT_READFUNCTION => function ($ch, $fd, $len) use (&$sent, &$moved, $payload) {
                    $n = min($len, SPEEDTEST_CHUNK - $sent);
                    if ($n <= 0) return '';
                    $off = $sent % strlen($payload);
                    $chunk = substr($payload, $off, min($n, strlen($payload) - $off));
                    $sent += strlen($chunk);
                    $moved += strlen($chunk);
                    return $chunk;
                },
            ]);
        }
        curl_setopt($h, CURLOPT_TIMEOUT, 60);
        return $h;
    };
    for ($i = 0; $i < $streams; $i++) {
        $h = $make();
        curl_multi_add_handle($mh, $h);
        $handles[] = $h;
    }
    $t0 = microtime(true);
    $warm = 1.5;
    $markBytes = null;
    $markAt = null;
    $errors = [];
    do {
        curl_multi_exec($mh, $running);
        curl_multi_select($mh, 0.1);
        while ($info = curl_multi_info_read($mh)) {
            $h = $info['handle'];
            if ($info['result'] !== CURLE_OK) $errors[] = curl_error($h);
            curl_multi_remove_handle($mh, $h);
            // Keep the line busy: start another transfer in its place.
            if (microtime(true) - $t0 < $seconds && count($errors) < $streams * 3) {
                $n = $make();
                curl_multi_add_handle($mh, $n);
                $handles[] = $n;
            }
        }
        $elapsed = microtime(true) - $t0;
        if ($markAt === null && $elapsed >= $warm) { $markAt = microtime(true); $markBytes = $moved; }
    } while ($elapsed < $seconds && ($running > 0 || count($errors) < $streams * 3));
    $end = microtime(true);
    foreach ($handles as $h) { @curl_multi_remove_handle($mh, $h); }
    curl_multi_close($mh);
    if ($markAt === null || $end - $markAt < 0.5) throw new RuntimeException("$dir test failed" . ($errors ? ': ' . $errors[0] : ''));
    $mbps = ($moved - $markBytes) * 8 / ($end - $markAt) / 1e6;
    if ($mbps <= 0) throw new RuntimeException("$dir test moved no data" . ($errors ? ': ' . $errors[0] : ''));
    return [$mbps, $moved];
}

// ---- Dashboard ----

function speedtest_row(array $r): array {
    $f = fn($v) => $v === null ? null : (float) $v;
    return [
        'id' => (int) $r['id'], 'ts' => (int) $r['ts'], 'engine' => $r['engine'], 'manual' => (bool) $r['manual'],
        'down' => $f($r['down_mbps']), 'up' => $f($r['up_mbps']), 'ping' => $f($r['ping_ms']), 'jitter' => $f($r['jitter_ms']),
        'loss' => $f($r['loss_pct']), 'bytes' => $r['bytes_used'] === null ? null : (int) $r['bytes_used'],
        'durationMs' => (int) $r['duration_ms'], 'server' => $r['server'], 'isp' => $r['isp'], 'ip' => $r['public_ip'],
        'url' => $r['result_url'], 'error' => $r['error'],
    ];
}

/** Results for the Network tab: settings, state and the tests within $days. */
function speedtest_overview(int $days): array {
    $s = speedtest_settings();
    $st = db()->prepare('SELECT * FROM speedtests WHERE ts >= ? ORDER BY ts');
    $st->execute([(int) round(microtime(true) * 1000) - $days * 86400000]);
    $rows = array_map('speedtest_row', $st->fetchAll());
    $last = db()->query('SELECT * FROM speedtests ORDER BY ts DESC LIMIT 1')->fetch();
    return [
        'settings' => $s, 'intervals' => SPEEDTEST_INTERVALS, 'engines' => speedtest_engines(),
        'running' => speedtest_running(), 'requested' => speedtest_requested(), 'nextDue' => speedtest_next_due($s),
        'latest' => $last ? speedtest_row($last) : null, 'results' => $rows,
    ];
}

/** Alerts: the last two scheduled tests were both slower than the set minimum. */
function speedtest_problems(callable $add, array &$frozenHosts): void {
    $pdo = db();
    if (!(int) $pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'speedtests'")->fetchColumn()) return;
    $s = speedtest_settings();
    if ($s['min_down_mbps'] <= 0 && $s['min_up_mbps'] <= 0) return;
    $last = $pdo->query('SELECT down_mbps, up_mbps, ts FROM speedtests WHERE error IS NULL ORDER BY ts DESC LIMIT 2')->fetchAll();
    $maxAge = max(2, $s['interval_min'] * 3) * 60000;
    if (count($last) < 2 || microtime(true) * 1000 - (int) $last[0]['ts'] > max($maxAge, 6 * 3600000)) {
        $frozenHosts[0]['speedtest'] = true;   // not enough fresh results: leave as is
        return;
    }
    foreach (['down' => ['min_down_mbps', 'Download'], 'up' => ['min_up_mbps', 'Upload']] as $dir => [$key, $label]) {
        $min = $s[$key];
        if ($min <= 0) continue;
        $vals = array_map(fn($r) => (float) $r["{$dir}_mbps"], $last);
        if (max($vals) < $min) {
            $add(null, 'speedtest', $dir, 'warning', sprintf('Internet %s speed is low: %.1f and %.1f Mbit/s in the last two tests (minimum %d)',
                strtolower($label), $vals[0], $vals[1], $min));
        }
    }
}
