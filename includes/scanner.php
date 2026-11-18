<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/netcheck.php';

const SCAN_MIN_PREFIX = 22;   // smallest allowed network per CIDR token (1024 addresses)
const SCAN_MAX_TOTAL_IPS = 1024;

const OID_SYS_DESCR = '.1.3.6.1.2.1.1.1.0';
const OID_SYS_OBJECT_ID = '.1.3.6.1.2.1.1.2.0';
const OID_SYS_NAME = '.1.3.6.1.2.1.1.5.0';
const OID_SYS_SERVICES = '.1.3.6.1.2.1.1.7.0';

/**
 * Expands a comma/newline-separated list of IPv4 addresses and/or CIDR
 * ranges (e.g. "192.168.1.0/24, 10.0.0.5") into a flat, deduped IP list.
 * Network/broadcast addresses are skipped for prefixes < 31.
 */
function parse_networks(string $input): array {
    $ips = [];
    $tokens = preg_split('/[\s,]+/', trim($input), -1, PREG_SPLIT_NO_EMPTY);

    foreach ($tokens as $token) {
        if (!str_contains($token, '/')) {
            if (!filter_var($token, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                throw new InvalidArgumentException("Invalid address: $token");
            }
            $ips[] = $token;
            continue;
        }

        [$base, $prefixStr] = explode('/', $token, 2);
        if (!filter_var($base, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) || !ctype_digit($prefixStr)) {
            throw new InvalidArgumentException("Invalid network: $token");
        }
        $prefix = (int) $prefixStr;
        if ($prefix < SCAN_MIN_PREFIX || $prefix > 32) {
            throw new InvalidArgumentException(
                "Network $token is too large — smallest allowed prefix is /" . SCAN_MIN_PREFIX
            );
        }

        $hostBits = 32 - $prefix;
        $baseLong = ip2long($base);
        $mask = $hostBits === 0 ? 0xFFFFFFFF : ((~0 << $hostBits) & 0xFFFFFFFF);
        $network = $baseLong & $mask;
        $broadcast = $network | (~$mask & 0xFFFFFFFF);

        $start = $prefix >= 31 ? $network : $network + 1;
        $end = $prefix >= 31 ? $broadcast : $broadcast - 1;

        for ($i = $start; $i <= $end; $i++) {
            $ips[] = long2ip($i);
            if (count($ips) > SCAN_MAX_TOTAL_IPS) {
                throw new InvalidArgumentException(
                    'Too many addresses requested — max ' . SCAN_MAX_TOTAL_IPS . ' per scan. Narrow your ranges.'
                );
            }
        }
    }

    return array_values(array_unique($ips));
}

const SCAN_BATCH_SIZE = 200; // caps concurrent sockets per round, well under typical fd limits

/**
 * Sweeps every address for liveness via TCP connect (see netcheck.php) —
 * no raw ICMP, so no root/setuid/capabilities/SELinux policy needed.
 * Addresses are checked in batches to keep concurrent socket counts sane;
 * returns ip => ['up' => bool, 'port' => ?int, 'open' => bool, 'ms' => ?int].
 */
function network_sweep(array $ips, array $ports = NETCHECK_PORTS, float $timeoutSec = 1.0): array {
    $results = [];
    foreach (array_chunk($ips, SCAN_BATCH_SIZE) as $batch) {
        $results += tcp_probe_hosts($batch, $ports, $timeoutSec);
    }
    return $results;
}

function snmp_cli_path(): ?string {
    static $path = false;
    if ($path === false) {
        $found = trim((string) shell_exec('command -v snmpget 2>/dev/null'));
        $path = $found !== '' ? $found : null;
    }
    return $path;
}

function snmp_available(): bool {
    return function_exists('snmpget') || snmp_cli_path() !== null;
}

/**
 * Queries sysDescr/sysObjectID/sysName/sysServices over SNMP v2c.
 * Returns null if the host didn't respond (SNMP not enabled on it, wrong
 * community string, or blocked by a firewall).
 */
function snmp_probe(string $ip, string $community, float $timeoutSec = 1.0): ?array {
    if (function_exists('snmpget')) {
        snmp_set_quick_print(true);
        snmp_set_valueretrieval(SNMP_VALUE_PLAIN);
        $timeoutMicro = (int) round($timeoutSec * 1_000_000);

        $descr = @snmpget($ip, $community, OID_SYS_DESCR, $timeoutMicro, 1);
        if ($descr === false) return null;

        $objId = @snmpget($ip, $community, OID_SYS_OBJECT_ID, $timeoutMicro, 1);
        $name = @snmpget($ip, $community, OID_SYS_NAME, $timeoutMicro, 1);
        $services = @snmpget($ip, $community, OID_SYS_SERVICES, $timeoutMicro, 1);

        return [
            'sysDescr' => is_string($descr) ? trim($descr, " \t\"") : null,
            'sysObjectID' => is_string($objId) ? trim($objId, " \t\"") : null,
            'sysName' => is_string($name) ? trim($name, " \t\"") : null,
            'sysServices' => is_numeric($services) ? (int) $services : null,
        ];
    }

    $bin = snmp_cli_path();
    if ($bin === null) return null;

    $timeoutArg = max(1, (int) round($timeoutSec));
    $cmd = escapeshellarg($bin) .
        ' -O nq -v2c -c ' . escapeshellarg($community) .
        ' -t ' . $timeoutArg . ' -r 1 ' . escapeshellarg($ip) . ' ' .
        OID_SYS_DESCR . ' ' . OID_SYS_OBJECT_ID . ' ' . OID_SYS_NAME . ' ' . OID_SYS_SERVICES .
        ' 2>/dev/null';
    exec($cmd, $lines, $exit);
    if ($exit !== 0 || !$lines) return null;

    $values = [];
    foreach ($lines as $line) {
        if (preg_match('/^\S+\s+(.*)$/', $line, $m)) {
            $values[] = trim($m[1], " \t\"");
        }
    }
    if (!$values) return null;

    return [
        'sysDescr' => $values[0] ?? null,
        'sysObjectID' => $values[1] ?? null,
        'sysName' => $values[2] ?? null,
        'sysServices' => isset($values[3]) && is_numeric($values[3]) ? (int) $values[3] : null,
    ];
}

/**
 * Guesses os/device from which TCP port responded and (if available) SNMP
 * sysDescr text. device is one of: server, desktop, laptop, mobile, router,
 * switch, ap, printer, camera, nas, ups.
 */
function classify_device(?int $respondingPort, bool $portOpen, ?array $snmp): array {
    $os = guess_os_from_port($respondingPort, $portOpen);

    if ($snmp === null) {
        return ['os' => $os, 'device' => 'server', 'label' => 'No SNMP response', 'confidence' => 'low'];
    }

    $descr = strtolower((string) ($snmp['sysDescr'] ?? ''));

    if (str_contains($descr, 'windows')) $os = 'windows';
    elseif (str_contains($descr, 'darwin') || str_contains($descr, 'mac os')) $os = 'macos';
    elseif (str_contains($descr, 'linux')) $os = 'linux';

    // Order matters: more specific categories are checked before generic ones.
    $devicePatterns = [
        'ap'      => ['access point', 'unifi ap', 'aironet', 'wireless ap'],
        'printer' => ['printer', 'jetdirect', 'laserjet', 'officejet', 'deskjet'],
        'camera'  => ['camera', 'ipcam', 'dvr', 'nvr', 'hikvision', 'dahua'],
        'nas'     => ['synology', 'qnap', 'freenas', 'truenas', 'nas4free'],
        'ups'     => ['ups', 'smart-ups', 'apc web/snmp'],
        'switch'  => ['switch', 'catalyst', 'procurve'],
        'router'  => ['router', 'routeros', 'mikrotik', 'juniper', 'fortigate', 'pfsense', 'edgerouter', 'firewall', 'ubiquiti', 'cisco ios'],
    ];

    foreach ($devicePatterns as $device => $needles) {
        foreach ($needles as $needle) {
            if (str_contains($descr, $needle)) {
                return [
                    'os' => $os,
                    'device' => $device,
                    'label' => $snmp['sysDescr'] ?: ucfirst($device),
                    'confidence' => 'high',
                ];
            }
        }
    }

    return [
        'os' => $os,
        'device' => 'server',
        'label' => $snmp['sysDescr'] ?: 'SNMP host (unclassified)',
        'confidence' => 'medium',
    ];
}
