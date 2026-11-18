<?php
// Dashboard side of agents (login required).
//   GET    ?id=N  -> agent status + latest metrics/inventory (no package list)
//   POST   ?id=N  -> new one-time install command
//                    body: {platform: "linux"|"windows", removeZabbix: bool, removeCheckmk: bool}
//   DELETE ?id=N  -> remove the agent (its token stops working)
require_once __DIR__ . '/../includes/agents.php';
require_once __DIR__ . '/../includes/auth.php';
header('Content-Type: application/json');
$user = require_api_auth_admin_writes();

$id = (int) ($_GET['id'] ?? 0);
$stmt = db()->prepare('SELECT * FROM hosts WHERE id = ?');
$stmt->execute([$id]);
$host = $stmt->fetch();
if (!$host) {
    http_response_code(404);
    echo json_encode(['error' => 'not found']);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];
$server = rtrim('https://' . $_SERVER['HTTP_HOST'] . app_base_path(), '/');

/** `curl ... install.sh | sudo bash -s -- ARGS`, fetched over the pinned key. */
function installer_command(string $server, string $pin, string $args): string {
    return 'curl -fsSk --pinnedpubkey ' . escapeshellarg($pin) . ' ' . escapeshellarg("$server/agent/install.sh")
        . ' | sudo bash -s -- ' . $args;
}

function ps_quote(string $s): string {
    return "'" . str_replace("'", "''", $s) . "'";
}

/**
 * One-liner for an elevated Windows PowerShell: trusts only the server
 * certificate with this SHA-256 fingerprint, downloads install.ps1 and
 * runs it with ARGS.
 */
function windows_installer_command(string $server, string $certHash, string $args): string {
    // The certificate callback below only works in Windows PowerShell 5.1
    // (.NET Framework); PowerShell 7 ignores it, so say so plainly there.
    return "if (\$PSVersionTable.PSEdition -eq 'Core') { Write-Host 'Run this in Windows PowerShell (powershell.exe), not PowerShell 7.' -ForegroundColor Red } else { "
        . '[Net.ServicePointManager]::SecurityProtocol = [Net.ServicePointManager]::SecurityProtocol -bor 3072; '
        . '[Net.ServicePointManager]::ServerCertificateValidationCallback = { param($s, $c) '
        . "[BitConverter]::ToString([Security.Cryptography.SHA256]::Create().ComputeHash(\$c.GetRawCertData())).Replace('-', '') -eq '$certHash' }; "
        . '& ([scriptblock]::Create((New-Object Net.WebClient).DownloadString(' . ps_quote("$server/agent/install.ps1") . '))) '
        . $args . ' }';
}

if ($method === 'GET') {
    $stmt = db()->prepare('SELECT * FROM agents WHERE host_id = ?');
    $stmt->execute([$id]);
    $a = $stmt->fetch();
    if (!$a) {
        echo json_encode(['agent' => null]);
        exit;
    }
    $inventory = $a['inventory'] !== null ? json_decode($a['inventory'], true) : null;
    if (is_array($inventory)) {
        // The full package list can be thousands of entries; only the count
        // is shown for now.
        $inventory['package_count'] = is_array($inventory['packages'] ?? null) ? count($inventory['packages']) : null;
        unset($inventory['packages']);
    }
    $summary = agent_summaries()[$id] ?? [];
    $pin = tls_pubkey_pin();
    echo json_encode(['agent' => [
        'online' => $summary['online'] ?? false,
        'lastSeen' => $a['last_seen_at'] !== null ? (int) $a['last_seen_at'] : null,
        'enrolledAt' => (int) $a['enrolled_at'],
        'version' => $a['agent_version'],
        'hostname' => $a['hostname'],
        'lastIp' => $a['last_ip'],
        'metrics' => $a['metrics'] !== null ? json_decode($a['metrics'], true) : null,
        'metricsAt' => $a['metrics_at'] !== null ? (int) $a['metrics_at'] : null,
        'inventory' => $inventory,
        'inventoryAt' => $a['inventory_at'] !== null ? (int) $a['inventory_at'] : null,
        'platform' => ($inventory['os']['id'] ?? '') === 'windows' ? 'windows' : 'linux',
        'uninstallCommand' => ($inventory['os']['id'] ?? '') === 'windows'
            ? ((($h = tls_cert_sha256()) !== null) ? windows_installer_command($server, $h, '-Uninstall') : null)
            : ($pin ? installer_command($server, $pin, '--uninstall') : null),
    ]]);
    exit;
}

if ($method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $windows = ($input['platform'] ?? '') === 'windows';
    $pin = tls_pubkey_pin();
    $certHash = tls_cert_sha256();
    if ($pin === null || $certHash === null) {
        http_response_code(500);
        echo json_encode(['error' => 'Cannot read the server certificate (' . tls_cert_file() . ')']);
        exit;
    }
    $token = create_enrollment($id, $user['id']);
    if ($windows) {
        $args = '-Server ' . ps_quote($server) . " -Token '$token' -CertHash '$certHash'";
        if (!empty($input['removeZabbix'])) $args .= ' -RemoveZabbix';
        if (!empty($input['removeCheckmk'])) $args .= ' -RemoveCheckmk';
        $command = windows_installer_command($server, $certHash, $args);
    } else {
        $args = '--server ' . escapeshellarg($server) . ' --token ' . $token . ' --pin ' . escapeshellarg($pin);
        if (!empty($input['removeZabbix'])) $args .= ' --remove-zabbix';
        if (!empty($input['removeCheckmk'])) $args .= ' --remove-checkmk';
        $command = installer_command($server, $pin, $args);
    }
    echo json_encode([
        'command' => $command,
        'platform' => $windows ? 'windows' : 'linux',
        'expiresAt' => now_ms() + AGENT_ENROLL_TTL_SEC * 1000,
    ]);
    exit;
}

if ($method === 'DELETE') {
    db()->prepare('DELETE FROM agents WHERE host_id = ?')->execute([$id]);
    db()->prepare('DELETE FROM agent_enrollments WHERE host_id = ?')->execute([$id]);
    http_response_code(204);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'method not allowed']);
