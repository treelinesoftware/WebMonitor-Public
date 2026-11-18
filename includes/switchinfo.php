<?php
require_once __DIR__ . '/scanner.php'; // snmp_available(), snmp_cli_path()

// Standard IF-MIB.
const OID_IF_NAME = '.1.3.6.1.2.1.31.1.1.1.1';     // ifXTable ifName (short, e.g. "Gi1/0/1")
const OID_IF_DESCR = '.1.3.6.1.2.1.2.2.1.2';       // ifTable ifDescr (fallback if ifName is empty)
const OID_IF_OPER_STATUS = '.1.3.6.1.2.1.2.2.1.8'; // ifTable ifOperStatus (1=up, 2=down)

// Cisco-proprietary MIBs — only Cisco (and some Cisco-compatible) switches
// answer these.
const OID_CISCO_VLAN_PORT = '.1.3.6.1.4.1.9.9.68.1.2.2.1.2';   // CISCO-VLAN-MEMBERSHIP-MIB vmVlan, indexed by ifIndex
const OID_CISCO_VTP_VLAN_NAME = '.1.3.6.1.4.1.9.9.46.1.3.1.1.4'; // CISCO-VTP-MIB vtpVlanName, indexed by vtpDomainIndex.vlanIndex

/**
 * Walks an SNMP subtree and returns [oidSuffix => value], where oidSuffix
 * is whatever comes after $oid (typically an ifIndex or vlan index).
 */
function snmp_walk(string $ip, string $community, string $oid, float $timeoutSec = 2.0): array {
    $result = [];
    $prefix = ltrim($oid, '.') . '.';

    if (function_exists('snmprealwalk')) {
        snmp_set_quick_print(true);
        snmp_set_valueretrieval(SNMP_VALUE_PLAIN);
        // Without this, returned keys can come back as symbolic MIB names
        // (e.g. "IF-MIB::ifName.1") instead of numeric OIDs, breaking the
        // prefix-stripping below.
        snmp_set_oid_output_format(SNMP_OID_OUTPUT_NUMERIC);
        $timeoutMicro = (int) round($timeoutSec * 1_000_000);
        $walk = @snmprealwalk($ip, $community, $oid, $timeoutMicro, 1);
        if ($walk === false || !is_array($walk)) return [];

        foreach ($walk as $fullOid => $value) {
            $fullOid = ltrim((string) $fullOid, '.');
            $suffix = str_starts_with($fullOid, $prefix) ? substr($fullOid, strlen($prefix)) : $fullOid;
            $result[$suffix] = is_string($value) ? trim($value, " \t\"") : $value;
        }
        return $result;
    }

    $bin = snmp_cli_path();
    if ($bin === null) return [];

    $timeoutArg = max(1, (int) round($timeoutSec));
    $cmd = escapeshellarg($bin) .
        ' -O nq -v2c -c ' . escapeshellarg($community) .
        ' -t ' . $timeoutArg . ' -r 1 ' . escapeshellarg($ip) . ' ' . escapeshellarg($oid) .
        ' 2>/dev/null';
    exec($cmd, $lines, $exit);
    if ($exit !== 0 || !$lines) return [];

    foreach ($lines as $line) {
        if (!preg_match('/^\.?(\S+)\s+(.*)$/', $line, $m)) continue;
        $fullOid = ltrim($m[1], '.');
        $value = trim($m[2], " \t\"");
        $suffix = str_starts_with($fullOid, $prefix) ? substr($fullOid, strlen($prefix)) : $fullOid;
        $result[$suffix] = $value;
    }
    return $result;
}

/** Returns [vlanId => vlanName] from CISCO-VTP-MIB (empty on non-Cisco gear). */
function get_switch_vlans(string $ip, string $community, float $timeoutSec = 2.0): array {
    $walk = snmp_walk($ip, $community, OID_CISCO_VTP_VLAN_NAME, $timeoutSec);
    $vlans = [];
    foreach ($walk as $suffix => $name) {
        $parts = explode('.', $suffix);
        $vlanId = (int) end($parts);
        if ($vlanId > 0) $vlans[$vlanId] = $name;
    }
    ksort($vlans);
    return $vlans;
}
