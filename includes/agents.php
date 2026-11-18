<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/alerts.php';
require_once __DIR__ . '/history.php';

/**
 * Server side of the WebMonitor agent (agent/webmonitor-agent.py).
 *
 * Trust model:
 * - The dashboard issues a one-time enrollment token (24h) for a host and
 *   shows an install command containing it plus this server's TLS
 *   public-key pin, so the installer can trust the self-signed certificate.
 * - The installer exchanges the enrollment token for a permanent agent
 *   token. Only SHA-256 hashes of tokens are stored.
 * - Agents only ever connect out to this server; nothing listens on hosts.
 */

const AGENT_METRICS_INTERVAL_SEC = 60;
const AGENT_INVENTORY_INTERVAL_SEC = 3600;
const AGENT_OFFLINE_AFTER_SEC = 180;      // 3 missed metrics reports
const AGENT_ENROLL_TTL_SEC = 24 * 3600;
const AGENT_MAX_METRICS_BYTES = 512 * 1024;
const AGENT_MAX_INVENTORY_BYTES = 8 * 1024 * 1024;

function now_ms(): int {
    return (int) round(microtime(true) * 1000);
}

function token_hash(string $token): string {
    return hash('sha256', $token);
}

function new_token(): string {
    return bin2hex(random_bytes(32));
}

function tls_cert_file(): string {
    return defined('TLS_CERT_FILE') ? TLS_CERT_FILE : '/etc/pki/tls/certs/webmonitor.crt';
}

/** curl-style public key pin ("sha256//base64") of the server certificate. */
function tls_pubkey_pin(): ?string {
    $pem = @file_get_contents(tls_cert_file());
    if ($pem === false) return null;
    $key = openssl_pkey_get_public($pem);
    if ($key === false) return null;
    $spki = openssl_pkey_get_details($key)['key'] ?? '';
    $der = base64_decode(preg_replace('/-----[^-]+-----|\s+/', '', $spki));
    return 'sha256//' . base64_encode(hash('sha256', $der, true));
}

/** SHA-256 fingerprint (uppercase hex) of the server certificate, for Windows agents. */
function tls_cert_sha256(): ?string {
    $pem = @file_get_contents(tls_cert_file());
    if ($pem === false) return null;
    $fp = openssl_x509_fingerprint($pem, 'sha256');
    return $fp === false ? null : strtoupper($fp);
}

/** Creates a one-time enrollment token for a host; returns the raw token. */
function create_enrollment(int $hostId, ?int $userId): string {
    $token = new_token();
    $now = now_ms();
    $pdo = db();
    // Only the newest install command for a host is valid.
    $pdo->prepare('DELETE FROM agent_enrollments WHERE host_id = ? OR expires_at < ?')->execute([$hostId, $now]);
    $pdo->prepare('INSERT INTO agent_enrollments (host_id, token_hash, created_at, expires_at, created_by) VALUES (?, ?, ?, ?, ?)')
        ->execute([$hostId, token_hash($token), $now, $now + AGENT_ENROLL_TTL_SEC * 1000, $userId]);
    return $token;
}

/**
 * Exchanges an enrollment token for a permanent agent token. Returns
 * [hostId, agentToken] or null if the token is unknown, used or expired.
 * Replaces any previous agent for the host (its token stops working).
 */
function redeem_enrollment(string $enrollToken, string $hostname, string $version, string $ip): ?array {
    $pdo = db();
    $now = now_ms();
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT id, host_id FROM agent_enrollments
                               WHERE token_hash = ? AND used_at IS NULL AND expires_at > ? FOR UPDATE');
        $stmt->execute([token_hash($enrollToken), $now]);
        $row = $stmt->fetch();
        if (!$row) {
            $pdo->rollBack();
            return null;
        }
        $hostId = (int) $row['host_id'];
        $pdo->prepare('UPDATE agent_enrollments SET used_at = ? WHERE id = ?')->execute([$now, $row['id']]);

        $agentToken = new_token();
        $pdo->prepare('DELETE FROM agents WHERE host_id = ?')->execute([$hostId]);
        $pdo->prepare('INSERT INTO agents (host_id, token_hash, enrolled_at, last_seen_at, last_ip, agent_version, hostname)
                       VALUES (?, ?, ?, ?, ?, ?, ?)')
            ->execute([$hostId, token_hash($agentToken), $now, $now, $ip, substr($version, 0, 20), substr($hostname, 0, 255)]);
        $pdo->commit();
        return [$hostId, $agentToken];
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/** Host id for an agent token, or null. */
function agent_host_for_token(string $token): ?int {
    if (!preg_match('/^[0-9a-f]{64}$/', $token)) return null;
    $stmt = db()->prepare('SELECT host_id FROM agents WHERE token_hash = ?');
    $stmt->execute([token_hash($token)]);
    $id = $stmt->fetchColumn();
    return $id === false ? null : (int) $id;
}

function num_or_null($v, float $min = 0, float $max = 1e12): ?float {
    if (!is_int($v) && !is_float($v)) return null;
    return ($v >= $min && $v <= $max) ? (float) $v : null;
}

function store_agent_metrics(int $hostId, array $m, string $version, string $ip): void {
    $now = now_ms();
    $pdo = db();
    $pdo->prepare('UPDATE agents SET metrics = ?, metrics_at = ?, last_seen_at = ?, last_ip = ?, agent_version = ? WHERE host_id = ?')
        ->execute([json_encode($m), $now, $now, $ip, substr($version, 0, 20), $hostId]);

    // History (graphs).
    // A missing value stays missing (no false 0% dips in the graphs).
    $pct = fn($used, $total) => ($t = num_or_null($total)) && ($u = num_or_null($used)) !== null ? round($u / $t * 100, 1) : null;
    $points = [
        ['cpu', '', '%', num_or_null($m['cpu_pct'] ?? null, 0, 100)],
        ['mem', '', '%', $pct($m['memory']['used'] ?? null, $m['memory']['total'] ?? null)],
        ['swap', '', '%', $pct($m['memory']['swap_used'] ?? null, $m['memory']['swap_total'] ?? null)],
        ['load1', '', '', is_array($m['load'] ?? null) ? num_or_null($m['load'][0] ?? null, 0, 1e6) : null],
    ];
    foreach ((array) ($m['disks'] ?? []) as $d) {
        if (is_array($d) && isset($d['mount'])) $points[] = ['disk', (string) $d['mount'], '%', num_or_null($d['used_pct'] ?? null, 0, 100)];
    }
    foreach ((array) ($m['network'] ?? []) as $n) {
        if (!is_array($n) || empty($n['addrs']) || !isset($n['name'])) continue;   // interfaces with an address
        $points[] = ['net_rx', (string) $n['name'], 'B/s', num_or_null($n['rx_rate'] ?? null)];
        $points[] = ['net_tx', (string) $n['name'], 'B/s', num_or_null($n['tx_rate'] ?? null)];
    }
    record_points($hostId, $points, intdiv($now, 1000));

    $uptime = num_or_null($m['uptime_sec'] ?? null);
    if ($uptime !== null) note_boot_time($hostId, $now - (int) $uptime * 1000);
}

function store_agent_inventory(int $hostId, array $inv, string $version, string $ip): void {
    $now = now_ms();
    $updates = is_array($inv['updates']['packages'] ?? null) ? $inv['updates']['packages'] : null;
    $total = $updates !== null ? count($updates) : null;
    $security = $updates !== null ? count(array_filter($updates, fn($u) => !empty($u['security']))) : null;
    $reboot = isset($inv['reboot_required']) ? (int) (bool) $inv['reboot_required'] : null;
    $osPretty = is_string($inv['os']['pretty_name'] ?? null) ? substr($inv['os']['pretty_name'], 0, 120) : null;

    $pdo = db();
    $pdo->prepare('UPDATE agents SET inventory = ?, inventory_at = ?, last_seen_at = ?, last_ip = ?, agent_version = ?,
                   hostname = COALESCE(?, hostname), os_pretty = ?, updates_total = ?, updates_security = ?, reboot_required = ?
                   WHERE host_id = ?')
        ->execute([json_encode($inv), $now, $now, $ip, substr($version, 0, 20),
                   is_string($inv['hostname'] ?? null) ? substr($inv['hostname'], 0, 255) : null,
                   $osPretty, $total, $security, $reboot, $hostId]);
    adopt_host_name($hostId, is_string($inv['hostname'] ?? null) ? $inv['hostname'] : null);

    // Agents 0.2+ say whether the previous boot ended cleanly.
    $clean = $inv['last_boot']['previous_shutdown_clean'] ?? null;
    $clean = is_bool($clean) ? $clean : null;
    $boot = num_or_null($inv['boot_time'] ?? null);
    if ($boot !== null) note_boot_time($hostId, (int) $boot * 1000, $clean);
    if ($clean === false) upgrade_reboot_to_crash($hostId);
    // The agent knows the OS for certain; fix up hosts added as "Other".
    $os = ($inv['os']['id'] ?? '') === 'windows' ? 'windows' : 'linux';
    $pdo->prepare("UPDATE hosts SET os = ? WHERE id = ? AND os = 'other'")->execute([$os, $hostId]);
}

/**
 * Antivirus status from an agent's report: {state, text, products}. state: ok,
 * warn (out of date / real-time off), off (disabled, or none on Windows), none
 * (none on Linux: common, not a problem). Null when the agent doesn't report it.
 */
function antivirus_status($av, string $platform): ?array {
    if (!is_array($av) || !is_array($av['products'] ?? null)) return null;
    $products = array_values(array_filter($av['products'], fn($p) => is_array($p) && !empty($p['name'])));
    $short = fn(string $n) => trim(preg_replace(['/^Microsoft Defender( Antivirus)?$/i', '/\s+(Antivirus|Endpoint Security|Internet Security)$/i'], ['Defender', ''], $n)) ?: $n;
    $names = array_map(fn($p) => (string) $p['name'], $products);
    if (!$products) {
        return ['state' => $platform === 'windows' ? 'off' : 'none', 'text' => 'No antivirus', 'products' => []];
    }
    $on = array_values(array_filter($products, fn($p) => !empty($p['enabled'])));
    if (!$on) return ['state' => 'off', 'text' => $short($names[0]) . ' · off', 'products' => $names];
    // The best running product: up to date, then unknown, then out of date.
    usort($on, fn($a, $b) => [($b['up_to_date'] ?? null) === true, ($b['up_to_date'] ?? null) === null]
                         <=> [($a['up_to_date'] ?? null) === true, ($a['up_to_date'] ?? null) === null]);
    $p = $on[0];
    $outdated = ($p['up_to_date'] ?? null) === false;
    // On Linux, on-demand scanning (no real-time) is the normal ClamAV setup.
    $realtimeOff = $platform === 'windows' && ($p['realtime'] ?? null) === false;
    $age = isset($p['signature_age_days']) && is_numeric($p['signature_age_days']) ? (int) $p['signature_age_days'] : null;
    $text = $short((string) $p['name']) . ' · ' . ($outdated ? 'out of date' . ($age !== null ? " ({$age} days)" : '') : ($realtimeOff ? 'real-time off' : 'on'));
    return ['state' => $outdated || $realtimeOff ? 'warn' : 'ok', 'text' => $text, 'products' => $names];
}

/** Card-sized agent summary for every host that has one: [hostId => summary]. */
function agent_summaries(): array {
    $rows = db()->query("SELECT host_id, last_seen_at, agent_version, os_pretty, updates_total,
                                updates_security, reboot_required,
                                JSON_UNQUOTE(JSON_EXTRACT(inventory, '$.kernel')) AS kernel,
                                JSON_UNQUOTE(JSON_EXTRACT(inventory, '$.os.id')) AS os_id,
                                JSON_UNQUOTE(JSON_EXTRACT(inventory, '$.hardware.virtualization')) AS virt,
                                JSON_EXTRACT(inventory, '$.antivirus') AS av
                         FROM agents")->fetchAll();
    $out = [];
    $now = now_ms();
    foreach ($rows as $r) {
        $out[(int) $r['host_id']] = [
            'online' => $r['last_seen_at'] !== null && $now - (int) $r['last_seen_at'] < AGENT_OFFLINE_AFTER_SEC * 1000,
            'lastSeen' => $r['last_seen_at'] !== null ? (int) $r['last_seen_at'] : null,
            'version' => $r['agent_version'],
            'os' => $r['os_pretty'],
            'updates' => $r['updates_total'] !== null ? (int) $r['updates_total'] : null,
            'securityUpdates' => $r['updates_security'] !== null ? (int) $r['updates_security'] : null,
            'rebootRequired' => $r['reboot_required'] !== null ? (bool) $r['reboot_required'] : null,
            'platform' => $r['os_id'] === 'windows' ? 'windows' : 'linux',
            // A Proxmox VE kernel: rebooting this host restarts its VMs.
            'hypervisor' => is_string($r['kernel']) && str_ends_with($r['kernel'], '-pve'),
            // A KVM/QEMU guest, i.e. possibly a VM on that Proxmox host.
            'vm' => in_array($r['virt'], ['kvm', 'qemu'], true),
            'antivirus' => antivirus_status(json_decode((string) $r['av'], true), $r['os_id'] === 'windows' ? 'windows' : 'linux'),
        ];
    }
    return $out;
}
