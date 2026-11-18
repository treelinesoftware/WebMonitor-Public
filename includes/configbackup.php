<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/alerts.php';   // record_event(), alert_settings()

/**
 * Switch/router configuration backups over SSH. Every download is stored as
 * a new version in switch_configs; "changed" says whether it differs from
 * the previous version (ignoring lines that change on their own, like
 * timestamps).
 *
 * Backups run from the background jobs, not web requests: "Back up now"
 * sets requested_at and cli/cron.php picks it up within a minute; daily and
 * weekly backups run from cli/poll-snmp.php. Login is with WebMonitor's own
 * SSH key (CONFIG_SSH_DIR, shown in the dashboard to add to the device) or
 * a password, handed to ssh through SSH_ASKPASS so it never appears on a
 * command line.
 */

const CONFIG_SSH_DIR = '/var/lib/webmonitor/ssh';
const CONFIG_SSH_TIMEOUT_SEC = 60;
const CONFIG_SCHEDULES = ['off' => 0, 'daily' => 86400, 'weekly' => 7 * 86400];

// "Allow old SSH encryption" (per device): older Cisco IOS/IOS-XE only offer
// SHA-1 key exchange and ssh-rsa host keys. Rocky refuses both twice over —
// in ssh's algorithm lists and in OpenSSL's signature policy — so a device
// that opts in gets the algorithms added on its ssh command line and an
// OpenSSL config that allows SHA-1 signatures, for that ssh process only.
const LEGACY_SSH_OPTIONS = [
    'KexAlgorithms=+diffie-hellman-group14-sha1,diffie-hellman-group-exchange-sha1',
    'HostKeyAlgorithms=+ssh-rsa',
    'PubkeyAcceptedAlgorithms=+ssh-rsa',
];
const LEGACY_OPENSSL_CNF = <<<'CNF'
# Written by WebMonitor: used only by ssh to devices with "Allow old SSH
# encryption" ticked, so they can verify SHA-1 (ssh-rsa) host key signatures.
.include /etc/pki/tls/openssl.cnf
[openssl_init]
alg_section = wm_legacy_evp
[wm_legacy_evp]
rh-allow-sha1-signatures = yes
CNF;

/**
 * Device types. mode 'exec' runs one command (ssh host "command");
 * 'shell' opens an interactive session and types the commands at the
 * prompt (needed for paging commands and "enable").
 */
const CONFIG_VENDORS = [
    'cisco_ios' => ['label' => 'Cisco IOS / IOS-XE (Catalyst)', 'mode' => 'shell', 'enable' => true,
                    'commands' => ['terminal length 0', 'show running-config']],
    'cisco_nxos' => ['label' => 'Cisco Nexus (NX-OS)', 'mode' => 'exec', 'command' => 'show running-config'],
    'cisco_sb' => ['label' => 'Cisco Small Business (SG/CBS)', 'mode' => 'shell',
                   'commands' => ['terminal datadump', 'show running-config']],
    'aruba_os' => ['label' => 'HPE Aruba / ProCurve (ArubaOS-Switch)', 'mode' => 'shell', 'enable' => true,
                   'commands' => ['no page', 'show running-config']],
    'aruba_cx' => ['label' => 'HPE Aruba CX', 'mode' => 'shell', 'commands' => ['no page', 'show running-config']],
    // FortiOS pages "show" even over a one-shot command, so it needs a session to answer --More--.
    'fortinet' => ['label' => 'Fortinet FortiSwitch / FortiGate', 'mode' => 'shell', 'commands' => ['show']],
    'juniper' => ['label' => 'Juniper (Junos)', 'mode' => 'exec', 'command' => 'show configuration | display set | no-more'],
    'mikrotik' => ['label' => 'MikroTik (RouterOS)', 'mode' => 'exec', 'command' => '/export'],
    // Back up the virtual controller's address: it holds the whole cluster's configuration.
    'aruba_instant' => ['label' => 'Aruba Instant virtual controller (IAP)', 'mode' => 'shell', 'commands' => ['show running-config']],
    // The whole configuration is one XML file (/conf links to /cf/conf on pfSense). Needs an
    // account with shell access (admin, or a user with "User - System: Shell account access").
    'pfsense' => ['label' => 'pfSense / OPNsense firewall', 'mode' => 'exec', 'command' => 'cat /conf/config.xml', 'expect' => '/^\s*<\?xml/'],
    'ubiquiti' => ['label' => 'Ubiquiti EdgeSwitch / UniFi switch CLI', 'mode' => 'shell', 'enable' => true,
                   'commands' => ['terminal length 0', 'show running-config']],
    'dell' => ['label' => 'Dell PowerSwitch (OS6 / OS10)', 'mode' => 'shell', 'enable' => true,
               'commands' => ['terminal length 0', 'show running-config']],
    'netgear' => ['label' => 'Netgear managed (ProSAFE / M4xxx)', 'mode' => 'shell', 'enable' => true,
                  'commands' => ['terminal length 0', 'show running-config']],
    'generic' => ['label' => 'Other (SSH command you choose)', 'mode' => 'exec', 'command' => null],
];

// Lines that change without anyone changing the configuration.
const CONFIG_VOLATILE = [
    '/^! (Last configuration change|NVRAM config last updated) at .*$/m',
    '/^(Current configuration|Building configuration).*$/m',
    '/^ntp clock-period .*$/m',
    '/^#(config-version|conf_file_ver|buildno)=.*$/m',
    '/^# \w{3}\/\d{2}\/\d{4} \d\d:\d\d:\d\d by RouterOS.*$/m',
    '/^## Last commit: .*$/m',
];

// ---- secrets ----

function secret_key(): string {
    return hash_hkdf('sha256', DB_PASS, 32, 'webmonitor config-backup secrets');
}

function encrypt_secret(string $plain): string {
    $iv = random_bytes(12);
    $ct = openssl_encrypt($plain, 'aes-256-gcm', secret_key(), OPENSSL_RAW_DATA, $iv, $tag);
    return base64_encode($iv . $tag . $ct);
}

/** Null if missing or unreadable (e.g. the database password changed). */
function decrypt_secret(?string $enc): ?string {
    if ($enc === null || $enc === '') return null;
    $raw = base64_decode($enc, true);
    if ($raw === false || strlen($raw) < 29) return null;
    $plain = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', secret_key(), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
    return $plain === false ? null : $plain;
}

// ---- WebMonitor's SSH key ----

/** Creates the SSH key and askpass helper if needed (background jobs only). Returns an error or null. */
function ensure_ssh_identity(): ?string {
    if (!is_dir(CONFIG_SSH_DIR) || !is_writable(CONFIG_SSH_DIR)) {
        return CONFIG_SSH_DIR . ' is missing or not writable (re-run the installer)';
    }
    $key = CONFIG_SSH_DIR . '/id_ed25519';
    if (!is_file($key)) {
        exec('ssh-keygen -q -t ed25519 -N "" -C ' . escapeshellarg('webmonitor@' . gethostname()) . ' -f ' . escapeshellarg($key) . ' 2>&1', $out, $rc);
        if ($rc !== 0) return 'could not create an SSH key: ' . implode(' ', $out);
    }
    $pub = trim((string) @file_get_contents("$key.pub"));
    if ($pub !== '') {
        db()->prepare("INSERT INTO settings (k, v) VALUES ('ssh_public_key', ?) ON DUPLICATE KEY UPDATE v = VALUES(v)")->execute([$pub]);
    }
    $cnf = CONFIG_SSH_DIR . '/legacy-openssl.cnf';
    if (@file_get_contents($cnf) !== LEGACY_OPENSSL_CNF) file_put_contents($cnf, LEGACY_OPENSSL_CNF);
    $askpass = CONFIG_SSH_DIR . '/askpass.sh';
    if (!is_file($askpass)) {
        file_put_contents($askpass, "#!/bin/sh\nprintf '%s\\n' \"\$WM_SSH_SECRET\"\n");
        chmod($askpass, 0700);
    }
    return null;
}

function ssh_public_key(): ?string {
    $v = db()->query("SELECT v FROM settings WHERE k = 'ssh_public_key'")->fetchColumn();
    return $v ?: null;
}

// ---- running ssh ----

/** ssh argv for a device (no command). */
function ssh_argv(array $cfg, string $ip, bool $tty): array {
    $argv = ['ssh', '-o', 'ConnectTimeout=10', '-o', 'ServerAliveInterval=10',
             '-o', 'StrictHostKeyChecking=accept-new', '-o', 'UserKnownHostsFile=' . CONFIG_SSH_DIR . '/known_hosts',
             '-o', 'NumberOfPasswordPrompts=1', '-p', (string) (int) $cfg['port'], '-l', $cfg['username']];
    if ($cfg['auth'] === 'key') {
        array_push($argv, '-o', 'BatchMode=yes', '-o', 'IdentitiesOnly=yes', '-i', CONFIG_SSH_DIR . '/id_ed25519');
    } else {
        array_push($argv, '-o', 'PubkeyAuthentication=no', '-o', 'PreferredAuthentications=keyboard-interactive,password');
    }
    if (!empty($cfg['legacy_ssh'])) {
        foreach (LEGACY_SSH_OPTIONS as $o) array_push($argv, '-o', $o);
    }
    $argv[] = $tty ? '-tt' : '-T';
    $argv[] = $ip;
    return $argv;
}

function ssh_env(array $cfg): array {
    $env = ['PATH' => '/usr/bin:/bin', 'HOME' => CONFIG_SSH_DIR, 'LANG' => 'C'];
    if (!empty($cfg['legacy_ssh'])) $env['OPENSSL_CONF'] = CONFIG_SSH_DIR . '/legacy-openssl.cnf';
    if ($cfg['auth'] === 'password') {
        $env += ['SSH_ASKPASS' => CONFIG_SSH_DIR . '/askpass.sh', 'SSH_ASKPASS_REQUIRE' => 'force', 'DISPLAY' => 'none',
                 'WM_SSH_SECRET' => (string) decrypt_secret($cfg['secret_enc'])];
    }
    return $env;
}

/** Makes ssh's own error messages readable. */
function ssh_error(string $stderr, int $rc): string {
    $e = trim(preg_replace('/^(Warning: Permanently added|\*\*).*$/m', '', $stderr));
    if (str_contains($e, 'no matching key exchange method') || str_contains($e, 'no matching host key type')
        || str_contains($e, 'error in libcrypto')) {
        return 'the device only offers old SSH encryption (SHA-1) — tick "Allow old SSH encryption" in Settings, or update its firmware: ' . $e;
    }
    if (str_contains($e, 'REMOTE HOST IDENTIFICATION HAS CHANGED')) {
        return "the device's SSH host key changed since the last backup (replaced device, or someone in between). "
            . 'If expected, use "Forget host key" and back up again.';
    }
    if (str_contains($e, 'Permission denied')) return 'login refused — check the username and password/key: ' . $e;
    return $e !== '' ? $e : "ssh exited with code $rc";
}

/** Runs one command (exec mode). Returns [output, error]. */
function ssh_exec(array $cfg, string $ip, string $command): array {
    $p = proc_open(array_merge(ssh_argv($cfg, $ip, false), [$command]), [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, ssh_env($cfg));
    if (!$p) return [null, 'could not start ssh'];
    fclose($pipes[0]);
    [$out, $err] = read_until_exit($p, $pipes, CONFIG_SSH_TIMEOUT_SEC);
    $rc = proc_close($p);
    if ($rc !== 0 && trim($out) === '') return [null, ssh_error($err, $rc)];
    return [$out, null];
}

function read_until_exit($p, array $pipes, int $timeout): array {
    $out = $err = '';
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    $deadline = microtime(true) + $timeout;
    while (microtime(true) < $deadline) {
        $r = [$pipes[1], $pipes[2]];
        $w = $x = null;
        if (@stream_select($r, $w, $x, 1) === false) break;
        foreach ($r as $s) {
            $chunk = fread($s, 65536);
            if ($s === $pipes[1]) $out .= $chunk; else $err .= $chunk;
        }
        if (feof($pipes[1]) && feof($pipes[2])) break;
    }
    if (microtime(true) >= $deadline) proc_terminate($p);
    return [$out, $err];
}

/**
 * Interactive session (shell mode): waits for the prompt, enters enable mode
 * if needed, types each command and returns the last command's output.
 */
function ssh_shell(array $cfg, string $ip, array $vendor): array {
    $p = proc_open(ssh_argv($cfg, $ip, true), [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, ssh_env($cfg));
    if (!$p) return [null, 'could not start ssh'];
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    $deadline = microtime(true) + CONFIG_SSH_TIMEOUT_SEC;
    $buf = $err = '';
    $prompt = '/(?:^|\n)[^\n]{1,80}[>#]\s?$/';

    // Reads until $pattern matches the buffer; answers pagers along the way.
    $wait = function (string $pattern) use (&$buf, &$err, $pipes, $deadline): bool {
        while (microtime(true) < $deadline) {
            $tail = substr($buf, -300);
            if (preg_match('/(--More--|-- MORE --|Press any key to continue|<--- More --->)\s*$/i', $tail)) {
                fwrite($pipes[0], preg_match('/any key/i', $tail) ? "\n" : ' ');
                // Blank the pager prompt in place (the device's own backspaces then erase the
                // blanks, not real output); clean_terminal() drops any blanks left over.
                $buf = preg_replace_callback('/[ \t]*(--More--|-- MORE --|Press any key to continue|<--- More --->)[ \t]*$/i',
                    fn($m) => str_repeat("\x01", strlen($m[0])), $buf);
            }
            if (preg_match($pattern, clean_terminal(substr($buf, -2000)))) return true;
            $r = [$pipes[1], $pipes[2]];
            $w = $x = null;
            if (@stream_select($r, $w, $x, 1) === false) return false;
            foreach ($r as $s) {
                $chunk = (string) fread($s, 65536);
                if ($s === $pipes[1]) $buf .= $chunk; else $err .= $chunk;
            }
            if (feof($pipes[1])) return (bool) preg_match($pattern, clean_terminal(substr($buf, -2000)));
        }
        return false;
    };
    $finish = function (?string $out, ?string $error) use ($p, $pipes, &$err) {
        @fwrite($pipes[0], "exit\n");
        foreach ($pipes as $pp) @fclose($pp);
        proc_terminate($p);
        proc_close($p);
        return [$out, $error];
    };

    if (!$wait($prompt)) {
        return $finish(null, trim($buf) === '' ? ssh_error($err, 255) : 'no command prompt after login: ' . mb_substr(trim(clean_terminal($buf)), -200));
    }
    $enable = decrypt_secret($cfg['enable_enc']);
    if (!empty($vendor['enable']) && preg_match('/>\s?$/', clean_terminal($buf))) {
        if ($enable === null) return $finish(null, 'the login lands in user mode (>) — set an enable password, or give the account privilege 15');
        fwrite($pipes[0], "enable\n");
        if (!$wait('/(password|passwd)[^\n]*:\s*$/i')) return $finish(null, 'no enable password prompt');
        $buf = '';
        fwrite($pipes[0], $enable . "\n");
        if (!$wait($prompt) || preg_match('/>\s?$/', clean_terminal($buf))) return $finish(null, 'enable password refused');
    }
    $out = '';
    foreach ($vendor['commands'] as $cmd) {
        $buf = '';
        fwrite($pipes[0], $cmd . "\n");
        if (!$wait($prompt)) return $finish(null, "timed out waiting for \"$cmd\"");
        $out = $buf;
    }
    // Output of the last command: drop the echoed command line and the final prompt.
    $out = clean_terminal($out);
    $out = preg_replace('/^[^\n]*\n/', '', $out, 1);
    $out = preg_replace('/\n[^\n]{1,80}[>#]\s?$/', '', $out);
    return $finish($out, null);
}

/**
 * Turns raw terminal output into plain text the way a terminal would show it:
 * ANSI escapes dropped, backspaces erase the character before them, and a
 * carriage return goes back to the start of the line so what follows
 * overwrites it (pagers clear their "--More--" that way).
 */
function clean_terminal(string $s): string {
    $s = str_replace("\r\n", "\n", $s);
    $s = preg_replace('/\e\[2?K/', "\x02", $s);   // erase line: handled below
    $s = preg_replace('/\e\[[0-9;?]*[A-Za-z]|\e[()][A-Z0-9]|\e[=>]/', '', $s);
    while (preg_match('/[^\x08\n]\x08/', $s)) $s = preg_replace('/[^\x08\n]\x08/', '', $s);
    $s = str_replace("\x08", '', $s);
    if (str_contains($s, "\r") || str_contains($s, "\x02")) {
        $lines = explode("\n", $s);
        foreach ($lines as &$line) {
            if (!str_contains($line, "\r") && !str_contains($line, "\x02")) continue;
            $screen = '';
            foreach (explode("\r", $line) as $seg) {
                foreach (explode("\x02", $seg) as $k => $part) {
                    if ($k > 0) $screen = '';   // erase line
                    // What's left of the old text past the new: kept unless it's only blanks
                    // (a pager clearing its prompt), so real trailing spaces survive.
                    $rest = (string) substr($screen, strlen($part));
                    $screen = $part . (trim($rest, " \x01") === '' ? '' : $rest);
                }
            }
            $line = str_replace("\x01", '', $screen);
        }
        unset($line);
        $s = implode("\n", $lines);
    }
    return str_replace("\x01", '', $s);
}

/** Trims Cisco-style output to the configuration itself. */
function trim_config(string $vendor, string $out): string {
    $out = trim(str_replace("\r", '', $out), "\n");
    if (in_array($vendor, ['cisco_ios', 'cisco_sb', 'ubiquiti', 'dell', 'netgear'], true)
        && preg_match('/^(Building configuration|Current configuration|!)/m', $out, $m, PREG_OFFSET_CAPTURE)) {
        $out = substr($out, $m[0][1]);
        if (preg_match_all('/^end\s*$/m', $out, $mm, PREG_OFFSET_CAPTURE)) {
            $last = end($mm[0]);
            $out = substr($out, 0, $last[1] + strlen($last[0]));
        }
    }
    return rtrim($out) . "\n";
}

function config_fingerprint(string $content): string {
    return hash('sha256', preg_replace(CONFIG_VOLATILE, '', $content));
}

// ---- backing up ----

/** Fetches and stores one device's configuration. Returns [version id, error]. */
function backup_device(array $host, array $cfg, string $source): array {
    $pdo = db();
    $pdo->prepare('UPDATE config_backup_settings SET last_attempt_at = ?, requested_at = NULL WHERE host_id = ?')
        ->execute([now_ms_cfg(), $host['id']]);
    $vendor = CONFIG_VENDORS[$cfg['vendor']] ?? null;
    $err = ensure_ssh_identity();
    if (!$err && !$vendor) $err = "unknown device type {$cfg['vendor']}";
    if (!$err && $cfg['auth'] === 'password' && decrypt_secret($cfg['secret_enc']) === null) {
        $err = 'no password saved (or it can no longer be read) — enter it again';
    }
    $out = null;
    if (!$err) {
        if ($vendor['mode'] === 'exec') {
            $cmd = $vendor['command'] ?? trim((string) $cfg['command']);
            [$out, $err] = $cmd === '' ? [null, 'no command set'] : ssh_exec($cfg, $host['ip_address'], $cmd);
        } else {
            [$out, $err] = ssh_shell($cfg, $host['ip_address'], $vendor);
        }
    }
    if (!$err) {
        $out = trim_config($cfg['vendor'], (string) $out);
        if (strlen($out) < 40 || preg_match('/^\s*(% ?Invalid|Unknown command|Command fail|syntax error)/mi', substr($out, 0, 300))) {
            $err = 'the device did not return a configuration: ' . mb_substr(trim($out), 0, 200);
        } elseif (isset($vendor['expect']) && !preg_match($vendor['expect'], $out)) {
            $err = $cfg['vendor'] === 'pfsense'
                ? 'pfSense answered with its console menu instead of the file — the account needs shell access (use admin, or give the user "User - System: Shell account access"): ' . mb_substr(trim($out), 0, 120)
                : 'unexpected output: ' . mb_substr(trim($out), 0, 200);
        }
    }
    if ($err) {
        $pdo->prepare('UPDATE config_backup_settings SET last_error = ? WHERE host_id = ?')->execute([mb_substr($err, 0, 500), $host['id']]);
        return [null, $err];
    }

    $prev = $pdo->prepare('SELECT content FROM switch_configs WHERE host_id = ? ORDER BY id DESC LIMIT 1');
    $prev->execute([$host['id']]);
    $prevContent = $prev->fetchColumn();
    $changed = $prevContent === false || config_fingerprint($prevContent) !== config_fingerprint($out);
    $pdo->prepare('INSERT INTO switch_configs (host_id, fetched_at, source, bytes, sha256, changed, content) VALUES (?, ?, ?, ?, ?, ?, ?)')
        ->execute([$host['id'], now_ms_cfg(), $source, strlen($out), hash('sha256', $out), $changed ? 1 : 0, $out]);
    $id = (int) $pdo->lastInsertId();
    $pdo->prepare('UPDATE config_backup_settings SET last_ok_at = ?, last_error = NULL WHERE host_id = ?')->execute([now_ms_cfg(), $host['id']]);
    if ($changed && $prevContent !== false) note_config_change((int) $host['id'], $prevContent, $out);
    return [$id, null];
}

/**
 * Alerts that a device's configuration changed. The message names the changed
 * sections ("interface GigabitEthernet1/0/5", "vlan 30") but never the changed
 * lines themselves: notifications leave the server, and configs hold secrets.
 */
function note_config_change(int $hostId, string $old, string $new): void {
    $diff = config_diff($old, $new);
    $added = count(array_filter($diff, fn($d) => $d[0] === '+' && !volatile_line($d[1])));
    $removed = count(array_filter($diff, fn($d) => $d[0] === '-' && !volatile_line($d[1])));
    $sections = [];
    $header = '';
    $xml = str_starts_with(ltrim($new), '<?xml');
    foreach ($diff as [$op, $line]) {
        if ($xml) {
            // pfSense/OPNsense: the section is the element one level under the root (<system>, <filter>, ...).
            if (preg_match('/^(?:\t| {1,2})<([A-Za-z_][\w.-]*)[\s>\/]/', $line, $m)) $header = $m[1];
        } elseif ($line !== '' && !preg_match('/^[\s!#]/', $line)) {
            $header = $line;   // top-level line
        }
        if ($op === '=' || volatile_line($line)) continue;
        $h = $header !== '' ? $header : $line;
        $words = preg_split('/\s+/', trim($h));
        $name = preg_match('/secret|passw|community|key|token|hash/i', $h) ? $words[0] . ' …' : implode(' ', array_slice($words, 0, 3));
        $sections[$name] = true;
    }
    if (!$sections) return;
    $stmt = db()->prepare('SELECT name, ip_address, muted_until FROM hosts WHERE id = ?');
    $stmt->execute([$hostId]);
    $h = $stmt->fetch();
    if (!$h) return;
    $list = array_keys($sections);
    $more = count($list) > 6 ? ' and ' . (count($list) - 6) . ' more' : '';
    $msg = host_label($h) . " configuration changed: $added line" . ($added === 1 ? '' : 's') . " added, $removed removed — "
        . implode(', ', array_slice($list, 0, 6)) . $more . '. Compare versions in its Details.';
    $silent = (int) $h['muted_until'] > now_ms_cfg() || !alert_settings()['alert_config_changes'];
    record_event($hostId, 'config_change', 'warning', $msg, $silent);
}

function volatile_line(string $line): bool {
    return preg_replace(CONFIG_VOLATILE, '', $line) === '';
}

function now_ms_cfg(): int {
    return (int) round(microtime(true) * 1000);
}

/**
 * Runs backups that were asked for ($requestedOnly, every minute from cron)
 * or are due by schedule (every 5 minutes from poll-snmp.php).
 */
function run_config_backups(bool $requestedOnly): void {
    $pdo = db();
    if (!(int) $pdo->query("SELECT GET_LOCK('webmonitor_config_backup', 0)")->fetchColumn()) return;
    try {
        forget_requested_host_keys();
        $rows = $pdo->query('SELECT c.*, h.name, h.ip_address, h.id AS hid FROM config_backup_settings c JOIN hosts h ON h.id = c.host_id')->fetchAll();
        foreach ($rows as $r) {
            $source = null;
            if ($r['requested_at']) {
                $source = 'manual';
            } elseif (!$requestedOnly && ($every = CONFIG_SCHEDULES[$r['schedule']] ?? 0)) {
                // Retry failed scheduled backups hourly, not every 5 minutes.
                $lastTry = (int) $r['last_attempt_at'];
                $lastOk = (int) $r['last_ok_at'];
                if ($lastOk < now_ms_cfg() - $every * 1000 && $lastTry < now_ms_cfg() - 3600000) $source = 'schedule';
            }
            if ($source === null) continue;
            [$id, $err] = backup_device(['id' => (int) $r['hid'], 'ip_address' => $r['ip_address']], $r, $source);
            if ($err) printf("%s config backup of %s failed: %s\n", date('Y-m-d H:i:s'), $r['name'], $err);
        }
    } finally {
        $pdo->query("SELECT RELEASE_LOCK('webmonitor_config_backup')");
    }
}

/** "Forget host key" from the dashboard: removes the device's entry from known_hosts. */
function forget_requested_host_keys(): void {
    $pdo = db();
    $raw = $pdo->query("SELECT v FROM settings WHERE k = 'ssh_forget_hosts'")->fetchColumn();
    if (!$raw) return;
    $file = CONFIG_SSH_DIR . '/known_hosts';
    foreach (json_decode($raw, true) ?: [] as $ip) {
        if (!filter_var($ip, FILTER_VALIDATE_IP) || !is_file($file)) continue;
        $port = $pdo->prepare('SELECT c.port FROM config_backup_settings c JOIN hosts h ON h.id = c.host_id WHERE h.ip_address = ?');
        $port->execute([$ip]);
        foreach (array_unique([$ip, '[' . $ip . ']:' . (int) ($port->fetchColumn() ?: 22)]) as $name) {
            exec('ssh-keygen -q -R ' . escapeshellarg($name) . ' -f ' . escapeshellarg($file) . ' 2>/dev/null');
        }
    }
    @unlink("$file.old");
    $pdo->exec("DELETE FROM settings WHERE k = 'ssh_forget_hosts'");
}

// ---- comparing versions ----

/**
 * Line diff of two configs: [['=', line] | ['-', line] | ['+', line], ...].
 * Common start and end are trimmed first; configs usually differ in a few
 * lines, so the LCS table stays small. Very different files fall back to
 * "all removed, all added".
 */
function config_diff(string $a, string $b): array {
    $x = explode("\n", rtrim($a, "\n"));
    $y = explode("\n", rtrim($b, "\n"));
    $pre = 0;
    while ($pre < count($x) && $pre < count($y) && $x[$pre] === $y[$pre]) $pre++;
    $suf = 0;
    while ($suf < count($x) - $pre && $suf < count($y) - $pre && $x[count($x) - 1 - $suf] === $y[count($y) - 1 - $suf]) $suf++;
    $mx = array_slice($x, $pre, count($x) - $pre - $suf);
    $my = array_slice($y, $pre, count($y) - $pre - $suf);
    $out = array_map(fn($l) => ['=', $l], array_slice($x, 0, $pre));
    $n = count($mx);
    $m = count($my);
    if ($n * $m > 600_000) {   // keeps the table well under PHP's memory limit
        foreach ($mx as $l) $out[] = ['-', $l];
        foreach ($my as $l) $out[] = ['+', $l];
    } else {
        $L = array_fill(0, $n + 1, array_fill(0, $m + 1, 0));
        for ($i = $n - 1; $i >= 0; $i--) {
            for ($j = $m - 1; $j >= 0; $j--) {
                $L[$i][$j] = $mx[$i] === $my[$j] ? $L[$i + 1][$j + 1] + 1 : max($L[$i + 1][$j], $L[$i][$j + 1]);
            }
        }
        $i = $j = 0;
        while ($i < $n && $j < $m) {
            if ($mx[$i] === $my[$j]) { $out[] = ['=', $mx[$i]]; $i++; $j++; }
            elseif ($L[$i + 1][$j] >= $L[$i][$j + 1]) $out[] = ['-', $mx[$i++]];
            else $out[] = ['+', $my[$j++]];
        }
        while ($i < $n) $out[] = ['-', $mx[$i++]];
        while ($j < $m) $out[] = ['+', $my[$j++]];
    }
    foreach (array_slice($x, count($x) - $suf) as $l) $out[] = ['=', $l];
    return $out;
}
