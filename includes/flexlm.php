<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/agents.php';   // now_ms()

/**
 * FlexLM licence servers (Licences tab).
 *
 * cli/cron.php runs flexlm_run_due() every minute. Servers that are due (or
 * that someone pressed "Check now" on) are checked in parallel with
 * "lmutil lmstat -a -c port@host". The output gives the license server and
 * vendor daemon status, each feature's licences issued and in use, expiry
 * dates and who has each licence checked out.
 *
 * lmutil is Flexera's tool and can't be shipped with WebMonitor: copy it to
 * one of FLEXLM_LMUTIL_PATHS (docs/flexlm.md). Most lmutil builds ask for the
 * LSB loader (ld-lsb-x86-64.so.3), which Rocky Linux doesn't have; when the
 * loader is missing, lmutil is started through the normal glibc loader
 * instead, so no system changes are needed.
 *
 * Status: up; warn (a triad member or vendor daemon down, a feature full or
 * expiring soon); down (two failed checks in a row); unknown (lmutil missing
 * or not runnable); pending (not checked yet).
 */

const FLEXLM_LMUTIL_PATHS = [
    '/usr/local/bin/lmutil',
    '/opt/flexlm/lmutil',
    '/var/lib/webmonitor/lmutil',          // the Docker image's data volume
    '/var/lib/webmonitor/flexlm/lmutil',
    '/usr/bin/lmutil',
];
const FLEXLM_INTERVALS = [60, 120, 300, 600, 900, 1800, 3600];
const FLEXLM_TIMEOUT_SEC = 20;             // per lmstat run
const FLEXLM_PARALLEL = 12;
const FLEXLM_DOWN_AFTER = 2;               // failures in a row before it shows down
const FLEXLM_USAGE_KEEP_DAYS = 14;
const FLEXLM_HOURLY_KEEP_DAYS = 400;
const FLEXLM_CHECKOUTS_KEEP_DAYS = 180;
const FLEXLM_OUTPUT_MAX = 256 * 1024;
// port@host, @host (default ports), or a triad of three joined by commas. No file paths.
const FLEXLM_TARGET_PART_RE = '/^(\d{1,5})?@[A-Za-z0-9][A-Za-z0-9._\-]{0,252}$/';

// ---------------------------------------------------------------- lmutil

/** Path to lmutil, or null when it isn't installed. */
function flexlm_lmutil_path(): ?string {
    foreach (FLEXLM_LMUTIL_PATHS as $p) {
        if (@is_file($p) && @is_executable($p)) return $p;
    }
    return null;
}

/**
 * The ELF interpreter a binary asks for (e.g. /lib64/ld-lsb-x86-64.so.3) and
 * its class (32 or 64), or null for a script or unreadable file.
 */
function flexlm_elf_interp(string $path): ?array {
    $f = @fopen($path, 'rb');
    if (!$f) return null;
    try {
        $h = fread($f, 64);
        if (strlen($h) < 52 || substr($h, 0, 4) !== "\x7fELF" || ord($h[5]) !== 1) return null;   // little-endian only
        $is64 = ord($h[4]) === 2;
        if ($is64) {
            $phoff = unpack('P', substr($h, 0x20, 8))[1];
            [$phentsize, $phnum] = array_values(unpack('v2', substr($h, 0x36, 4)));
        } else {
            $phoff = unpack('V', substr($h, 0x1C, 4))[1];
            [$phentsize, $phnum] = array_values(unpack('v2', substr($h, 0x2A, 4)));
        }
        for ($i = 0; $i < min($phnum, 64); $i++) {
            fseek($f, $phoff + $i * $phentsize);
            $ph = fread($f, $phentsize);
            if (strlen($ph) < ($is64 ? 56 : 32) || unpack('V', substr($ph, 0, 4))[1] !== 3) continue;   // PT_INTERP
            [$off, $size] = $is64 ? [unpack('P', substr($ph, 8, 8))[1], unpack('P', substr($ph, 32, 8))[1]]
                                  : [unpack('V', substr($ph, 4, 4))[1], unpack('V', substr($ph, 16, 4))[1]];
            fseek($f, $off);
            return ['interp' => rtrim((string) fread($f, min($size, 256)), "\0"), 'bits' => $is64 ? 64 : 32];
        }
        return ['interp' => null, 'bits' => $is64 ? 64 : 32];   // static binary
    } finally {
        fclose($f);
    }
}

/**
 * The command that starts lmutil: [argv prefix, problem]. Problem is a message
 * for the Licences tab when lmutil can't run (argv prefix is then null).
 */
function flexlm_lmutil_command(): array {
    static $cached = null;
    if ($cached !== null) return $cached;
    $path = flexlm_lmutil_path();
    if ($path === null) return $cached = [null, 'lmutil is not installed on this server. See Help → FlexLM licences.'];
    $elf = flexlm_elf_interp($path);
    if ($elf === null) return $cached = [[$path], null];   // a wrapper script: run it as it is
    if ($elf['interp'] === null || is_file($elf['interp'])) return $cached = [[$path], null];
    // The LSB loader is missing: use the system's own loader of the same class.
    $loaders = $elf['bits'] === 64 ? ['/lib64/ld-linux-x86-64.so.2', '/lib/ld-linux-x86-64.so.2', '/lib/ld-linux-aarch64.so.1']
                                    : ['/lib/ld-linux.so.2'];
    foreach ($loaders as $l) {
        if (is_file($l)) return $cached = [[$l, $path], null];
    }
    return $cached = [null, $elf['bits'] === 32
        ? 'lmutil is a 32-bit program and the 32-bit system libraries are not installed (dnf install glibc.i686).'
        : "lmutil needs {$elf['interp']}, which this system doesn't have."];
}

/**
 * Runs "lmutil lmstat -a -c TARGET" for each target, several at once, each
 * stopped after $timeout seconds. Returns [key => [output, ms, timedOut]].
 */
function flexlm_lmstat_many(array $targets, ?array $prefix = null, int $timeout = FLEXLM_TIMEOUT_SEC): array {
    $prefix = $prefix ?? flexlm_lmutil_command()[0];   // tests pass their own command
    $out = [];
    if ($prefix === null) return $out;
    foreach (array_chunk($targets, FLEXLM_PARALLEL, true) as $batch) {
        $running = [];
        foreach ($batch as $key => $target) {
            $cmd = array_merge($prefix, ['lmstat', '-a', '-c', $target]);
            $p = proc_open($cmd, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes,
                           null, ['PATH' => '/usr/bin:/bin', 'LANG' => 'C', 'HOME' => sys_get_temp_dir()]);
            if (!is_resource($p)) {
                $out[$key] = ['Could not start lmutil.', 0, false];
                continue;
            }
            stream_set_blocking($pipes[1], false);
            $running[$key] = ['p' => $p, 'pipe' => $pipes[1], 'buf' => '', 'start' => microtime(true)];
        }
        while ($running) {
            $read = array_column($running, 'pipe');
            $w = $e = null;
            @stream_select($read, $w, $e, 0, 200000);
            foreach ($running as $key => &$r) {
                $chunk = fread($r['pipe'], 65536);
                if ($chunk !== false && $chunk !== '' && strlen($r['buf']) < FLEXLM_OUTPUT_MAX) $r['buf'] .= $chunk;
                $elapsed = microtime(true) - $r['start'];
                $done = feof($r['pipe']);
                $timedOut = !$done && $elapsed > $timeout;
                if ($done || $timedOut) {
                    if ($timedOut) proc_terminate($r['p'], 9);
                    fclose($r['pipe']);
                    proc_close($r['p']);
                    $out[$key] = [substr($r['buf'], 0, FLEXLM_OUTPUT_MAX), (int) round($elapsed * 1000), $timedOut];
                    unset($running[$key]);
                }
            }
            unset($r);
        }
    }
    return $out;
}

// ---------------------------------------------------------------- parsing

/**
 * Reads lmstat -a output. Returns:
 *   up        bool: at least one license server answered UP
 *   message   why it's down, or null
 *   servers   [[name, up]] license servers (three for a triad)
 *   lmgrd     lmgrd version, or null
 *   vendors   [[name, up, version]]
 *   features  [name => [vendor, version, total, used, reserved, expires (Y-m-d|null)]]
 *   checkouts [[feature, user, host, display, version, licenses, start]]
 */
function flexlm_parse(string $text, ?int $now = null): array {
    $r = ['up' => false, 'message' => null, 'servers' => [], 'lmgrd' => null, 'vendors' => [], 'features' => [], 'checkouts' => []];
    $lines = preg_split('/\r?\n/', $text);
    $feature = null;
    foreach ($lines as $line) {
        if (preg_match('/^\s*(\S+): license server (UP|DOWN)\b.*?(?:\bv(\d+[\d.]*))?\s*$/i', $line, $m)) {
            $up = strtoupper($m[2]) === 'UP';
            $r['servers'][] = [$m[1], $up];
            if ($up) { $r['up'] = true; $r['lmgrd'] = $r['lmgrd'] ?? ($m[3] ?? null) ?: null; }
            continue;
        }
        if (preg_match('/^Users of (\S+?):\s+\((.*)\)\s*$/', $line, $m)) {
            $feature = $m[1];
            $total = $used = 0;
            if (preg_match('/Total of (\d+) licen[sc]es? issued;\s*Total of (\d+) licen[sc]es? in use/i', $m[2], $t)) {
                [$total, $used] = [(int) $t[1], (int) $t[2]];
            }
            $r['features'][$feature] = [null, null, $total, $used, 0, null];
            continue;
        }
        if ($feature === null) {
            // Vendor daemon section: "MLM: UP v11.16.2" or "MLM: The desired vendor daemon is down."
            if (preg_match('/^\s+([A-Za-z0-9_\-]+): UP\b(?:\s+v(\d+[\d.]*))?/', $line, $m)) {
                $r['vendors'][] = [$m[1], true, $m[2] ?? null];
            } elseif (preg_match('/^\s+([A-Za-z0-9_\-]+): (.*vendor daemon is down.*|.*DOWN.*|Cannot .*)$/i', $line, $m)
                      && stripos($line, 'license server') === false) {
                $r['vendors'][] = [$m[1], false, null];
            }
            if ($r['message'] === null && preg_match('/(Cannot connect to license server[^(]*|lmgrd is not running[^(]*|License server machine is down[^(]*|Cannot find license file[^(]*|Error getting status:[^(]*)/i', $line, $m)) {
                $r['message'] = rtrim(trim($m[1]), ' .:') . '.';
            }
            continue;
        }
        // "MATLAB" v41, vendor: MLM, expiry: 31-dec-2026
        if (preg_match('/^\s+"([^"]+)"\s+v(\S+?),\s+vendor:\s+([^,\s]+)(?:,\s+expiry:\s*(\S+))?/i', $line, $m)) {
            $f = isset($r['features'][$m[1]]) ? $m[1] : $feature;
            $r['features'][$f][0] = $m[3];
            $r['features'][$f][1] = $m[2];
            $r['features'][$f][5] = flexlm_parse_expiry($m[4] ?? '');
            continue;
        }
        if (preg_match('/^\s+(\d+)\s+RESERVATIONs?\s+for\b/i', $line, $m)) {
            $r['features'][$feature][4] += (int) $m[1];
            continue;
        }
        // jsmith pc01 pc01 (v41) (licsrv/27000 101), start Wed 11/18 9:15, 2 licenses
        if (preg_match('/^\s+(\S+)\s+(\S+)\s+(.*?)\s*(?:\(v([^)]*)\)\s+)?\(([^)]*)\),\s+start\s+(\w{3}\s+\d{1,2}\/\d{1,2}\s+\d{1,2}:\d{2})(?:,\s+(\d+)\s+licen[sc]es?)?/i', $line, $m)) {
            if (stripos($line, 'queued') !== false) continue;
            $r['checkouts'][] = [$feature, $m[1], $m[2], $m[3] !== '' ? $m[3] : null, $m[4] !== '' ? $m[4] : null,
                                 isset($m[7]) && $m[7] !== '' ? (int) $m[7] : 1, preg_replace('/\s+/', ' ', $m[6])];
        }
    }
    if (!$r['up'] && $r['message'] === null) {
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || stripos($line, 'copyright') !== false || stripos($line, 'Flexible License Manager status') !== false
                || stripos($line, 'License server status') === 0 || stripos($line, 'License file(s)') === 0) continue;
            $r['message'] = mb_substr($line, 0, 300);
            break;
        }
        $r['message'] = $r['message'] ?? 'No answer from the license server.';
    }
    return $r;
}

/** "31-dec-2026" -> "2026-12-31"; permanent licences (year 0, "permanent") -> null. */
function flexlm_parse_expiry(string $s): ?string {
    if (!preg_match('/^(\d{1,2})-([a-z]{3})-(\d{1,4})$/i', trim($s), $m) || (int) $m[3] < 1970 || (int) $m[3] > 9000) return null;
    $ts = strtotime("{$m[1]} {$m[2]} {$m[3]}");
    return $ts ? date('Y-m-d', $ts) : null;
}

/** lmstat's start time ("Wed 11/18 9:15", no year) as ms: this year, or last year if that's in the future. */
function flexlm_start_ms(string $start, int $nowMs): ?int {
    if (!preg_match('/(\d{1,2})\/(\d{1,2})\s+(\d{1,2}):(\d{2})/', $start, $m)) return null;
    $year = (int) date('Y', intdiv($nowMs, 1000));
    $ts = mktime((int) $m[3], (int) $m[4], 0, (int) $m[1], (int) $m[2], $year);
    if ($ts > intdiv($nowMs, 1000) + 86400) $ts = mktime((int) $m[3], (int) $m[4], 0, (int) $m[1], (int) $m[2], $year - 1);
    return $ts ? $ts * 1000 : null;
}

// ---------------------------------------------------------------- recording

/** Server status from a parse result: [status, message]. */
function flexlm_judge(array $srv, array $p, int $nowMs): array {
    if (!$p['up']) return ['down', $p['message']];
    $warn = [];
    $downServers = array_filter($p['servers'], fn($s) => !$s[1]);
    if ($downServers) $warn[] = 'license server ' . implode(', ', array_column($downServers, 0)) . ' is down';
    $downVendors = array_filter($p['vendors'], fn($v) => !$v[1]);
    if ($downVendors && count($downVendors) === count($p['vendors'])) {
        return ['down', 'Vendor daemon ' . implode(', ', array_column($downVendors, 0)) . ' is down.'];
    }
    if ($downVendors) $warn[] = 'vendor daemon ' . implode(', ', array_column($downVendors, 0)) . ' is down';
    $pct = (int) $srv['full_pct'];
    $full = [];
    if ($pct > 0) {
        foreach ($p['features'] as $name => $f) {
            if ($f[2] > 0 && $f[3] * 100 >= $f[2] * $pct) $full[] = $name . ($f[3] >= $f[2] ? " (all {$f[2]} in use)" : " ({$f[3]} of {$f[2]} in use)");
        }
    }
    if ($full) $warn[] = implode(', ', array_slice($full, 0, 5)) . (count($full) > 5 ? ' and ' . (count($full) - 5) . ' more' : '');
    $days = (int) $srv['expiry_warn_days'];
    if ($days > 0) {
        $soon = [];
        $limit = date('Y-m-d', intdiv($nowMs, 1000) + $days * 86400);
        foreach ($p['features'] as $name => $f) {
            if ($f[5] !== null && $f[5] <= $limit) $soon[$f[5]][] = $name;
        }
        ksort($soon);
        foreach (array_slice($soon, 0, 2, true) as $date => $names) {
            $warn[] = (count($names) === 1 ? $names[0] : count($names) . ' features') . ($date < date('Y-m-d', intdiv($nowMs, 1000)) ? ' expired ' : ' expire' . (count($names) === 1 ? 's ' : ' ')) . $date;
        }
    }
    return $warn ? ['warn', ucfirst(implode('; ', $warn)) . '.'] : ['up', null];
}

/** Stores one check: status, features, usage points and checkouts. */
function flexlm_record(array $srv, string $output, int $ms, bool $timedOut, ?int $nowMs = null): void {
    $pdo = db();
    $now = $nowMs ?? now_ms();
    $id = (int) $srv['id'];
    $p = flexlm_parse($output, $now);
    if ($timedOut && !$p['up']) $p['message'] = 'No answer within ' . FLEXLM_TIMEOUT_SEC . ' seconds.';
    [$status, $msg] = flexlm_judge($srv, $p, $now);
    $fails = $status === 'down' ? (int) $srv['fail_count'] + 1 : 0;
    if ($status === 'down' && $fails < FLEXLM_DOWN_AFTER && !in_array($srv['status'], ['down', 'pending', 'unknown'], true)) {
        $status = $srv['status'];   // one failure: keep the old status until it's confirmed
    }
    $pdo->prepare('UPDATE flexlm_servers SET status = ?, message = ?, last_check_at = ?, last_ms = ?, fail_count = ?, check_requested = 0,
                   changed_at = IF(status <> ?, ?, COALESCE(changed_at, ?)), last_output = ?,
                   vendors = COALESCE(?, vendors), lmgrd_version = COALESCE(?, lmgrd_version) WHERE id = ?')
        ->execute([$status, $msg !== null ? mb_substr($msg, 0, 500) : null, $now, $ms, $fails, $status, $now, $now, $output,
                   $p['up'] ? json_encode(array_map(fn($v) => ['name' => $v[0], 'up' => $v[1], 'version' => $v[2]], $p['vendors'])) : null,
                   $p['lmgrd'], $id]);
    if (!$p['up']) return;   // keep the last known features and checkouts

    $pdo->beginTransaction();
    try {
        $names = array_keys($p['features']);
        if ($names) {
            $pdo->prepare('DELETE FROM flexlm_features WHERE server_id = ? AND feature NOT IN (' . implode(',', array_fill(0, count($names), '?')) . ')')
                ->execute(array_merge([$id], array_map('strval', $names)));
        } else {
            $pdo->prepare('DELETE FROM flexlm_features WHERE server_id = ?')->execute([$id]);
        }
        $feat = $pdo->prepare('INSERT INTO flexlm_features (server_id, feature, vendor, version, total, used, reserved, expires, updated_at)
                               VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE vendor = VALUES(vendor), version = VALUES(version),
                               total = VALUES(total), used = VALUES(used), reserved = VALUES(reserved), expires = VALUES(expires), updated_at = VALUES(updated_at)');
        $usage = $pdo->prepare('INSERT IGNORE INTO flexlm_usage (server_id, feature, ts, used, total) VALUES (?, ?, ?, ?, ?)');
        $hourly = $pdo->prepare('INSERT INTO flexlm_usage_hourly (server_id, feature, hour, peak, total) VALUES (?, ?, ?, ?, ?)
                                 ON DUPLICATE KEY UPDATE peak = GREATEST(peak, VALUES(peak)), total = GREATEST(total, VALUES(total))');
        $hour = intdiv($now, 3600000) * 3600000;
        foreach ($p['features'] as $name => [$vendor, $version, $total, $used, $reserved, $expires]) {
            $name = (string) $name;
            $feat->execute([$id, $name, $vendor, $version, $total, $used, $reserved, $expires, $now]);
            if ($total > 0) {
                $usage->execute([$id, $name, $now, $used, $total]);
                $hourly->execute([$id, $name, $hour, $used, $total]);
            }
        }

        // Checkouts: carry on the ones seen before, start new ones, end the ones that are gone.
        $open = [];
        $st = $pdo->prepare('SELECT id, feature, username, host, start_text FROM flexlm_checkouts WHERE server_id = ? AND ended_at IS NULL');
        $st->execute([$id]);
        foreach ($st as $c) $open["{$c['feature']}|{$c['username']}|{$c['host']}|{$c['start_text']}"] = (int) $c['id'];
        $seen = $pdo->prepare('UPDATE flexlm_checkouts SET last_seen = ?, licenses = ?, display = ?, version = ? WHERE id = ?');
        $add = $pdo->prepare('INSERT INTO flexlm_checkouts (server_id, feature, username, host, display, version, licenses, start_text, started_at, last_seen)
                              VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $done = [];
        foreach ($p['checkouts'] as [$f, $user, $host, $display, $version, $count, $start]) {
            $f = (string) $f;
            $user = mb_substr($user, 0, 120);
            $host = mb_substr($host, 0, 190);
            $key = "$f|$user|$host|$start";
            if (isset($done[$key])) continue;   // the same line twice in one output counts once
            $done[$key] = true;
            if (isset($open[$key])) {
                $seen->execute([$now, $count, $display !== null ? mb_substr($display, 0, 190) : null, $version, $open[$key]]);
                unset($open[$key]);
            } else {
                $started = flexlm_start_ms($start, $now);
                $add->execute([$id, $f, $user, $host, $display !== null ? mb_substr($display, 0, 190) : null, $version !== null ? mb_substr($version, 0, 40) : null,
                               $count, $start, $started !== null && $started <= $now ? $started : $now, $now]);
            }
        }
        if ($open) {
            $pdo->query('UPDATE flexlm_checkouts SET ended_at = last_seen WHERE id IN (' . implode(',', array_map('intval', $open)) . ')');
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/** Marks servers unknown when lmutil can't run (nothing else changes). */
function flexlm_record_unrunnable(array $servers, string $problem): void {
    $now = now_ms();
    $st = db()->prepare("UPDATE flexlm_servers SET status = 'unknown', message = ?, last_check_at = ?, check_requested = 0,
                         changed_at = IF(status <> 'unknown', ?, changed_at) WHERE id = ?");
    foreach ($servers as $s) $st->execute([$problem, $now, $now, $s['id']]);
}

/** Checks the given servers (rows from flexlm_servers) now. */
function flexlm_check(array $servers): void {
    if (!$servers) return;
    [$prefix, $problem] = flexlm_lmutil_command();
    if ($prefix === null) { flexlm_record_unrunnable($servers, $problem); return; }
    $targets = [];
    foreach ($servers as $s) $targets[(int) $s['id']] = $s['target'];
    $results = flexlm_lmstat_many($targets);
    foreach ($servers as $s) {
        [$out, $ms, $timedOut] = $results[(int) $s['id']] ?? ['', 0, false];
        // The loader or lmutil itself failed to start (missing libraries).
        if (preg_match('/(error while loading shared libraries|required file not found|cannot execute binary file|Exec format error)/i', $out)) {
            flexlm_record_unrunnable([$s], 'lmutil could not start: ' . mb_substr(trim(strtok($out, "\n")), 0, 300));
            continue;
        }
        flexlm_record($s, $out, $ms, $timedOut);
    }
}

/** Cron: checks the servers that are due or were asked for, and trims old data. One process at a time. */
function flexlm_run_due(): void {
    $pdo = db();
    if (!(int) $pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'flexlm_servers'")->fetchColumn()) return;
    if (!(int) $pdo->query("SELECT GET_LOCK('webmonitor_flexlm', 0)")->fetchColumn()) return;
    try {
        $now = now_ms();
        // 5 s of slack so a 60 s check run by a once-a-minute timer isn't skipped every other run.
        $st = $pdo->prepare('SELECT * FROM flexlm_servers WHERE enabled = 1
                             AND (check_requested = 1 OR last_check_at IS NULL OR last_check_at + interval_sec * 1000 - 5000 <= ?)');
        $st->execute([$now]);
        flexlm_check($st->fetchAll());
        if (random_int(1, 30) === 1) {
            $pdo->prepare('DELETE FROM flexlm_usage WHERE ts < ?')->execute([$now - FLEXLM_USAGE_KEEP_DAYS * 86400000]);
            $pdo->prepare('DELETE FROM flexlm_usage_hourly WHERE hour < ?')->execute([$now - FLEXLM_HOURLY_KEEP_DAYS * 86400000]);
            $pdo->prepare('DELETE FROM flexlm_checkouts WHERE ended_at IS NOT NULL AND ended_at < ?')->execute([$now - FLEXLM_CHECKOUTS_KEEP_DAYS * 86400000]);
        }
    } finally {
        $pdo->query("SELECT RELEASE_LOCK('webmonitor_flexlm')");
    }
}

// ---------------------------------------------------------------- validation

/** Cleans a create/update request. Returns [row, error]. */
function flexlm_validate(array $in, ?array $existing = null): array {
    $get = fn($k, $col, $d = null) => array_key_exists($k, $in) ? $in[$k] : ($existing[$col] ?? $d);
    $target = preg_replace('/\s+/', '', (string) $get('target', 'target', ''));
    $parts = $target === '' ? [] : explode(',', $target);
    if (!$parts || count($parts) > 3) return [null, 'Enter the server as port@host, e.g. 27000@192.168.1.20 (or three of them, comma-separated, for a triad).'];
    foreach ($parts as $part) {
        if (!preg_match(FLEXLM_TARGET_PART_RE, $part, $m) || (isset($m[1]) && $m[1] !== '' && ((int) $m[1] < 1 || (int) $m[1] > 65535))) {
            return [null, "\"$part\" isn't port@host. Use e.g. 27000@192.168.1.20 or 27000@licsrv."];
        }
    }
    $name = trim((string) $get('name', 'name', ''));
    if ($name === '') $name = $target;
    $interval = (int) $get('interval', 'interval_sec', 300);
    if (!in_array($interval, FLEXLM_INTERVALS, true)) $interval = 300;
    $notes = trim((string) $get('notes', 'notes', ''));
    return [[
        'name' => mb_substr($name, 0, 120),
        'target' => $target,
        'interval_sec' => $interval,
        'enabled' => (bool) $get('enabled', 'enabled', true),
        'alert' => (bool) $get('alert', 'alert', true),
        'down_after_min' => max(0, min(1440, (int) $get('downAfterMin', 'down_after_min', 10))),
        'full_pct' => max(0, min(100, (int) $get('fullPct', 'full_pct', 100))),
        'expiry_warn_days' => max(0, min(365, (int) $get('expiryWarnDays', 'expiry_warn_days', 30))),
        'notes' => $notes !== '' ? mb_substr($notes, 0, 500) : null,
    ], null];
}

// ---------------------------------------------------------------- reading

/** JSON-ready server row with its features and current checkouts. */
function flexlm_server_row(array $s, array $features, array $checkouts): array {
    $used = $total = 0;
    foreach ($features as $f) { $used += $f['used']; $total += $f['total']; }
    $expiries = array_filter(array_column($features, 'expires'));
    return [
        'id' => (int) $s['id'],
        'name' => $s['name'],
        'target' => $s['target'],
        'interval' => (int) $s['interval_sec'],
        'enabled' => (bool) $s['enabled'],
        'alert' => (bool) $s['alert'],
        'downAfterMin' => (int) $s['down_after_min'],
        'fullPct' => (int) $s['full_pct'],
        'expiryWarnDays' => (int) $s['expiry_warn_days'],
        'notes' => $s['notes'],
        'status' => $s['enabled'] ? $s['status'] : 'paused',
        'message' => $s['message'],
        'vendors' => $s['vendors'] ? (json_decode($s['vendors'], true) ?: []) : [],
        'lmgrdVersion' => $s['lmgrd_version'],
        'lastCheck' => $s['last_check_at'] !== null ? (int) $s['last_check_at'] : null,
        'lastMs' => $s['last_ms'] !== null ? (int) $s['last_ms'] : null,
        'changedAt' => $s['changed_at'] !== null ? (int) $s['changed_at'] : null,
        'checkRequested' => (bool) $s['check_requested'],
        'used' => $used,
        'total' => $total,
        'earliestExpiry' => $expiries ? min($expiries) : null,
        'features' => $features,
        'checkouts' => $checkouts,
    ];
}

/** Every server with its features and open checkouts. */
function flexlm_list(): array {
    $pdo = db();
    $servers = $pdo->query('SELECT id, name, target, interval_sec, enabled, alert, down_after_min, full_pct, expiry_warn_days, notes, status, message,
                            vendors, lmgrd_version, last_check_at, last_ms, changed_at, check_requested FROM flexlm_servers ORDER BY name')->fetchAll();
    $features = $checkouts = [];
    foreach ($pdo->query('SELECT * FROM flexlm_features ORDER BY feature') as $f) {
        $features[(int) $f['server_id']][] = [
            'name' => $f['feature'], 'vendor' => $f['vendor'], 'version' => $f['version'], 'total' => (int) $f['total'],
            'used' => (int) $f['used'], 'reserved' => (int) $f['reserved'], 'expires' => $f['expires'],
        ];
    }
    foreach ($pdo->query('SELECT * FROM flexlm_checkouts WHERE ended_at IS NULL ORDER BY started_at') as $c) {
        $checkouts[(int) $c['server_id']][] = flexlm_checkout_row($c);
    }
    return array_map(fn($s) => flexlm_server_row($s, $features[(int) $s['id']] ?? [], $checkouts[(int) $s['id']] ?? []), $servers);
}

function flexlm_checkout_row(array $c): array {
    return [
        'id' => (int) $c['id'],
        'serverId' => (int) $c['server_id'],
        'serverName' => $c['server_name'] ?? null,
        'feature' => $c['feature'],
        'user' => $c['username'],
        'host' => $c['host'],
        'display' => $c['display'],
        'version' => $c['version'],
        'licenses' => (int) $c['licenses'],
        'startedAt' => (int) $c['started_at'],
        'lastSeen' => (int) $c['last_seen'],
        'endedAt' => $c['ended_at'] !== null ? (int) $c['ended_at'] : null,
    ];
}

/** Checkout history and the heaviest users over the last $days days, filtered by user, host or feature. */
function flexlm_history(int $days, string $q = '', ?int $serverId = null): array {
    $pdo = db();
    $since = now_ms() - $days * 86400000;
    $where = 'c.last_seen >= ?';
    $args = [$since];
    if ($serverId) { $where .= ' AND c.server_id = ?'; $args[] = $serverId; }
    if ($q !== '') {
        $where .= ' AND (c.username LIKE ? OR c.host LIKE ? OR c.feature LIKE ?)';
        $like = '%' . addcslashes($q, '%_\\') . '%';
        array_push($args, $like, $like, $like);
    }
    $st = $pdo->prepare("SELECT c.*, s.name AS server_name FROM flexlm_checkouts c JOIN flexlm_servers s ON s.id = c.server_id
                         WHERE $where ORDER BY c.last_seen DESC, c.id DESC LIMIT 300");
    $st->execute($args);
    $rows = array_map('flexlm_checkout_row', $st->fetchAll());
    $st = $pdo->prepare("SELECT c.username, COUNT(*) AS sessions, COUNT(DISTINCT c.feature) AS features,
                                SUM(GREATEST(c.last_seen - GREATEST(c.started_at, ?), 0)) AS ms, MAX(c.last_seen) AS last_seen
                         FROM flexlm_checkouts c WHERE $where GROUP BY c.username ORDER BY ms DESC LIMIT 15");
    $st->execute(array_merge([$since], $args));
    $top = array_map(fn($r) => ['user' => $r['username'], 'sessions' => (int) $r['sessions'], 'features' => (int) $r['features'],
                                'hours' => round((int) $r['ms'] / 3600000, 1), 'lastSeen' => (int) $r['last_seen']], $st->fetchAll());
    return ['days' => $days, 'checkouts' => $rows, 'topUsers' => $top];
}

/**
 * Licences in use per feature for one server: every check for up to 7 days,
 * hourly peaks beyond that. Returns {features: {name: [[ts, used]...]}, totals: {name: total}}.
 */
function flexlm_usage_series(int $serverId, int $days): array {
    $pdo = db();
    $since = now_ms() - $days * 86400000;
    if ($days <= 7) {
        $st = $pdo->prepare('SELECT feature, ts, used, total FROM flexlm_usage WHERE server_id = ? AND ts >= ? ORDER BY ts');
    } else {
        $st = $pdo->prepare('SELECT feature, hour AS ts, peak AS used, total FROM flexlm_usage_hourly WHERE server_id = ? AND hour >= ? ORDER BY hour');
    }
    $st->execute([$serverId, $since]);
    $series = $totals = [];
    foreach ($st as $r) {
        $series[$r['feature']][] = [(int) $r['ts'], (int) $r['used']];
        $totals[$r['feature']] = max($totals[$r['feature']] ?? 0, (int) $r['total']);
    }
    // Peak per feature over the range, for the table under the chart.
    $peaks = [];
    foreach ($series as $f => $pts) $peaks[$f] = max(array_column($pts, 1));
    return ['days' => $days, 'hourly' => $days > 7, 'features' => $series, 'totals' => $totals, 'peaks' => $peaks];
}

// ---------------------------------------------------------------- alerts

/**
 * Problems for includes/alerts.php: server down for down_after_min minutes
 * (critical), a feature at or above full_pct % in use (warning), features
 * expiring within expiry_warn_days (one alert per server and date; critical
 * once expired). Subjects start with "flm:ID"; alerts have no host.
 */
function flexlm_problems(callable $add, array &$frozenHosts): void {
    $pdo = db();
    if (!(int) $pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'flexlm_servers'")->fetchColumn()) return;
    $now = now_ms();
    $today = date('Y-m-d', intdiv($now, 1000));
    $openSubjects = [];
    foreach ($pdo->query("SELECT type, subject FROM alerts WHERE resolved_at IS NULL AND type LIKE 'flexlm\\_%'") as $a) {
        $openSubjects[(int) explode(':', substr($a['subject'], 4))[0]][] = $a['type'] . '|' . $a['subject'];
    }
    $features = [];
    foreach ($pdo->query('SELECT server_id, feature, total, used, expires FROM flexlm_features') as $f) $features[(int) $f['server_id']][] = $f;

    foreach ($pdo->query('SELECT * FROM flexlm_servers WHERE enabled = 1 AND alert = 1') as $s) {
        $id = (int) $s['id'];
        $label = "{$s['name']} ({$s['target']})";
        $stale = $s['last_check_at'] === null || $now - (int) $s['last_check_at'] > max(3 * (int) $s['interval_sec'], 900) * 1000;
        $freeze = function (?string $onlyType = null) use ($id, $openSubjects, &$frozenHosts) {
            foreach ($openSubjects[$id] ?? [] as $key) {
                if ($onlyType === null || str_starts_with($key, $onlyType . '|')) $frozenHosts[0][$key] = true;
            }
        };
        if ($stale || in_array($s['status'], ['unknown', 'pending'], true)) { $freeze(); continue; }   // no fresh data: leave as is

        if ($s['status'] === 'down') {
            $downMs = $now - (int) ($s['changed_at'] ?? $now);
            if ($downMs >= (int) $s['down_after_min'] * 60000) {
                $add(null, 'flexlm_down', "flm:$id", 'critical', "Licence server $label is down since " . date('H:i', intdiv((int) $s['changed_at'], 1000)) . ': ' . ($s['message'] ?? ''));
            } else {
                $freeze('flexlm_down');   // not down long enough yet: don't resolve an open one either
            }
            // Features are from before it went down: keep full/expiry alerts as they are.
            $freeze('flexlm_full');
            $freeze('flexlm_expiry');
            continue;
        }

        $pct = (int) $s['full_pct'];
        $byDate = [];
        foreach ($features[$id] ?? [] as $f) {
            $total = (int) $f['total'];
            $used = (int) $f['used'];
            if ($pct > 0 && $total > 0 && $used * 100 >= $total * $pct) {
                $add(null, 'flexlm_full', "flm:$id:{$f['feature']}", 'warning', $used >= $total
                    ? "{$s['name']}: all $total {$f['feature']} licences are in use"
                    : "{$s['name']}: $used of $total {$f['feature']} licences are in use (" . round($used * 100 / $total) . '%)');
            }
            if ((int) $s['expiry_warn_days'] > 0 && $f['expires'] !== null
                && $f['expires'] <= date('Y-m-d', intdiv($now, 1000) + (int) $s['expiry_warn_days'] * 86400)) {
                $byDate[$f['expires']][] = $f['feature'];
            }
        }
        foreach ($byDate as $date => $names) {
            $days = (int) round((strtotime($date) - strtotime($today)) / 86400);
            $list = implode(', ', array_slice($names, 0, 8)) . (count($names) > 8 ? ' and ' . (count($names) - 8) . ' more' : '');
            $what = count($names) === 1 ? "the $list licence" : count($names) . " licences ($list)";
            $add(null, 'flexlm_expiry', "flm:$id:$date", $days < 0 ? 'critical' : 'warning', $days < 0
                ? "{$s['name']}: $what expired on $date"
                : "{$s['name']}: $what expire" . (count($names) === 1 ? 's' : '') . ($days === 0 ? ' today' : " in $days day" . ($days === 1 ? '' : 's')) . ", on $date");
        }
    }
}

/** Whether a FlexLM alert's server still exists with alerts on (else it's closed without a "resolved" message). */
function flexlm_alert_live(array $a): bool {
    $st = db()->prepare('SELECT 1 FROM flexlm_servers WHERE id = ? AND enabled = 1 AND alert = 1');
    $st->execute([(int) explode(':', substr((string) $a['subject'], 4))[0]]);
    return (bool) $st->fetchColumn();
}

/** Name of the server a FlexLM alert is about. */
function flexlm_alert_name(array $a): string {
    $st = db()->prepare('SELECT name FROM flexlm_servers WHERE id = ?');
    $st->execute([(int) explode(':', substr((string) $a['subject'], 4))[0]]);
    return $st->fetchColumn() ?: 'licence server';
}
