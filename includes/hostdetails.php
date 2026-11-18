<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/nas.php';
require_once __DIR__ . '/history.php';

/**
 * Server details over SNMP v2c: system info (SNMPv2-MIB), resources
 * (HOST-RESOURCES-MIB, plus UCD-SNMP load average on net-snmp agents) and
 * IP-bearing interfaces (IF-MIB + IP-MIB). Every section is optional — many
 * agents only expose some of these, so missing data is simply left out.
 *
 * Uses the php-snmp extension's SNMP class (installed by rocky-install.sh).
 */

const OID_SYS_DESCR_0 = '.1.3.6.1.2.1.1.1.0';
const OID_SYS_OBJECT_ID_0 = '.1.3.6.1.2.1.1.2.0';
const OID_SYS_UPTIME_0 = '.1.3.6.1.2.1.1.3.0';       // agent uptime, timeticks
const OID_SYS_CONTACT_0 = '.1.3.6.1.2.1.1.4.0';
const OID_SYS_NAME_0 = '.1.3.6.1.2.1.1.5.0';
const OID_SYS_LOCATION_0 = '.1.3.6.1.2.1.1.6.0';

const OID_HR_SYSTEM_UPTIME_0 = '.1.3.6.1.2.1.25.1.1.0'; // OS uptime, timeticks
const OID_HR_NUM_USERS_0 = '.1.3.6.1.2.1.25.1.5.0';
const OID_HR_PROCESSES_0 = '.1.3.6.1.2.1.25.1.6.0';
const OID_HR_MEMORY_SIZE_0 = '.1.3.6.1.2.1.25.2.2.0';   // KB
const OID_HR_STORAGE_ENTRY = '.1.3.6.1.2.1.25.2.3.1';   // .col.index
const OID_HR_PROCESSOR_LOAD = '.1.3.6.1.2.1.25.3.3.1.2';
const OID_UCD_LOAD_AVG = '.1.3.6.1.4.1.2021.10.1.3';   // .1/.2/.3 = 1/5/15 min

const OID_IP_AD_ENT_IF_INDEX = '.1.3.6.1.2.1.4.20.1.2'; // indexed by IP
const OID_IP_AD_ENT_NETMASK = '.1.3.6.1.2.1.4.20.1.3';
const OID_IF_DESCR_COL = '.1.3.6.1.2.1.2.2.1.2';
const OID_IF_PHYS_ADDR_COL = '.1.3.6.1.2.1.2.2.1.6';
const OID_IF_OPER_STATUS_COL = '.1.3.6.1.2.1.2.2.1.8';
const OID_IF_NAME_COL = '.1.3.6.1.2.1.31.1.1.1.1';
const OID_IF_HIGH_SPEED_COL = '.1.3.6.1.2.1.31.1.1.1.15'; // Mbps

const OID_NET_SNMP_PREFIX = '.1.3.6.1.4.1.8072.';

// Cisco devices don't implement HOST-RESOURCES-MIB; these fill CPU/memory in.
const OID_CISCO_PREFIX = '.1.3.6.1.4.1.9.';
const OID_CISCO_CPU_1MIN = '.1.3.6.1.4.1.9.9.109.1.1.1.1.7';     // cpmCPUTotal1minRev, % per CPU
const OID_CISCO_MEM_POOL_NAME = '.1.3.6.1.4.1.9.9.48.1.1.1.2';   // ciscoMemoryPoolName
const OID_CISCO_MEM_POOL_USED = '.1.3.6.1.4.1.9.9.48.1.1.1.5';   // bytes
const OID_CISCO_MEM_POOL_FREE = '.1.3.6.1.4.1.9.9.48.1.1.1.6';   // bytes

// hrStorageType values worth showing; buffers/caches ("other") are skipped.
const HR_STORAGE_TYPES = [
    '.1.3.6.1.2.1.25.2.1.2' => 'ram',
    '.1.3.6.1.2.1.25.2.1.3' => 'virtual',
    '.1.3.6.1.2.1.25.2.1.4' => 'disk',
    '.1.3.6.1.2.1.25.2.1.10' => 'network',
];

function host_details_available(): bool {
    return class_exists('SNMP');
}

function snmp_session(string $ip, string $community, float $timeoutSec): SNMP {
    $s = new SNMP(SNMP::VERSION_2c, $ip, $community, (int) round($timeoutSec * 1_000_000), 1);
    $s->valueretrieval = SNMP_VALUE_PLAIN;
    $s->oid_output_format = SNMP_OID_OUTPUT_NUMERIC;
    $s->quick_print = true;
    $s->exceptions_enabled = 0;
    return $s;
}

/** GET several scalars; OIDs the agent doesn't have are simply absent. */
function snmp_get_many(SNMP $s, array $oids): array {
    $r = @$s->get($oids);
    if (!is_array($r)) return [];
    return array_map(fn($v) => is_string($v) ? trim($v, " \t\"") : $v, $r);
}

/** Walks a subtree, returning [suffix => value]; [] if unsupported. */
function snmp_walk_suffix(SNMP $s, string $oid): array {
    $r = @$s->walk($oid, true);
    if (!is_array($r)) return [];
    $out = [];
    foreach ($r as $k => $v) {
        if ($v === false || $v === null) continue;
        $out[(string) $k] = is_string($v) ? trim($v, " \t\"") : $v;
    }
    return $out;
}

function int_or_null($v): ?int {
    return is_numeric($v) ? (int) $v : null;
}

function str_or_null($v): ?string {
    return is_string($v) && $v !== '' ? $v : null;
}

function format_mac(string $raw): ?string {
    if ($raw === '') return null;
    // Plain retrieval returns the raw 6 bytes; some agents send hex text.
    $hex = strlen($raw) === 6 ? bin2hex($raw) : strtolower(preg_replace('/[^0-9a-fA-F]/', '', $raw));
    if (strlen($hex) !== 12 || $hex === '000000000000') return null;
    return implode(':', str_split($hex, 2));
}

/**
 * Collects server details over SNMP. Returns null if the host doesn't
 * answer SNMP at all (not enabled, wrong community, or firewalled).
 */
function collect_host_details(string $ip, string $community, float $timeoutSec = 1.5): ?array {
    $s = snmp_session($ip, $community, $timeoutSec);

    $sys = snmp_get_many($s, [
        OID_SYS_DESCR_0, OID_SYS_OBJECT_ID_0, OID_SYS_UPTIME_0,
        OID_SYS_CONTACT_0, OID_SYS_NAME_0, OID_SYS_LOCATION_0,
    ]);
    if (!$sys) return null;

    $hr = snmp_get_many($s, [
        OID_HR_SYSTEM_UPTIME_0, OID_HR_NUM_USERS_0, OID_HR_PROCESSES_0, OID_HR_MEMORY_SIZE_0,
        OID_UCD_LOAD_AVG . '.1', OID_UCD_LOAD_AVG . '.2', OID_UCD_LOAD_AVG . '.3',
    ]);

    // Prefer the OS uptime; sysUpTime is only how long the SNMP agent has run.
    $ticks = int_or_null($hr[OID_HR_SYSTEM_UPTIME_0] ?? null) ?? int_or_null($sys[OID_SYS_UPTIME_0] ?? null);

    $load = [];
    foreach (['.1', '.2', '.3'] as $i) {
        $v = $hr[OID_UCD_LOAD_AVG . $i] ?? null;
        if (is_numeric($v)) $load[] = (float) $v;
    }

    $cpu = array_values(array_filter(
        array_map('int_or_null', snmp_walk_suffix($s, OID_HR_PROCESSOR_LOAD)),
        fn($v) => $v !== null
    ));

    // QNAPs list every mount in hrStorage (slow, and their volumes come
    // from QNAP's own tables instead), so skip the generic storage walk.
    // Detect with the patient session: a slow answer mustn't make a QNAP
    // look like a generic host (and trigger the slow storage walk).
    $qs = snmp_session($ip, $community, QNAP_TIMEOUT_SEC);
    $isQnap = is_qnap($qs);
    $storage = $isQnap ? [] : collect_storage($s);
    $objectId = (string) ($sys[OID_SYS_OBJECT_ID_0] ?? '');
    if (str_starts_with($objectId, OID_CISCO_PREFIX)) {
        if (!$cpu) {
            $cpu = array_values(array_filter(
                array_map('int_or_null', snmp_walk_suffix($s, OID_CISCO_CPU_1MIN)),
                fn($v) => $v !== null
            ));
        }
        if (!$storage) $storage = collect_cisco_memory($s);
    }

    return [
        'system' => [
            'name' => str_or_null($sys[OID_SYS_NAME_0] ?? null),
            'descr' => str_or_null($sys[OID_SYS_DESCR_0] ?? null),
            'objectId' => str_or_null($sys[OID_SYS_OBJECT_ID_0] ?? null),
            'contact' => str_or_null($sys[OID_SYS_CONTACT_0] ?? null),
            'location' => str_or_null($sys[OID_SYS_LOCATION_0] ?? null),
            'uptimeSec' => $ticks !== null ? intdiv($ticks, 100) : null,
        ],
        'resources' => [
            'users' => int_or_null($hr[OID_HR_NUM_USERS_0] ?? null),
            'processes' => int_or_null($hr[OID_HR_PROCESSES_0] ?? null),
            'memoryKb' => int_or_null($hr[OID_HR_MEMORY_SIZE_0] ?? null),
            'cpuLoad' => $cpu,
            'loadAvg' => count($load) === 3 ? $load : null,
        ],
        'storage' => $storage,
        'network' => collect_network($s),
        'nas' => $isQnap ? collect_qnap($qs, $sys, $cpu) : null,
        'netSnmp' => str_starts_with($objectId, OID_NET_SNMP_PREFIX),
    ];
}

function collect_storage(SNMP $s): array {
    $rows = [];
    foreach (snmp_walk_suffix($s, OID_HR_STORAGE_ENTRY) as $suffix => $v) {
        [$col, $idx] = array_pad(explode('.', $suffix, 2), 2, null);
        if ($idx !== null) $rows[$idx][(int) $col] = $v;
    }

    $storage = [];
    foreach ($rows as $r) {
        // Columns: 2=type, 3=descr, 4=allocation units (bytes), 5=size, 6=used.
        $type = HR_STORAGE_TYPES['.' . ltrim((string) ($r[2] ?? ''), '.')] ?? null;
        $units = int_or_null($r[4] ?? null);
        $size = int_or_null($r[5] ?? null);
        if ($type === null || !$units || !$size) continue;
        $storage[] = [
            'descr' => (string) ($r[3] ?? ''),
            'type' => $type,
            'sizeBytes' => $size * $units,
            'usedBytes' => (int) (int_or_null($r[6] ?? null) ?? 0) * $units,
        ];
    }
    return $storage;
}

/** Cisco memory pools (e.g. "Processor", "I/O") as RAM-type storage rows. */
function collect_cisco_memory(SNMP $s): array {
    $names = snmp_walk_suffix($s, OID_CISCO_MEM_POOL_NAME);
    if (!$names) return [];
    $used = snmp_walk_suffix($s, OID_CISCO_MEM_POOL_USED);
    $free = snmp_walk_suffix($s, OID_CISCO_MEM_POOL_FREE);

    $pools = [];
    foreach ($names as $idx => $name) {
        $u = int_or_null($used[$idx] ?? null);
        $f = int_or_null($free[$idx] ?? null);
        if ($u === null || $f === null || $u + $f === 0) continue;
        $pools[] = ['descr' => "$name memory", 'type' => 'ram', 'sizeBytes' => $u + $f, 'usedBytes' => $u];
    }
    return $pools;
}

function collect_network(SNMP $s): array {
    $ipToIf = snmp_walk_suffix($s, OID_IP_AD_ENT_IF_INDEX);
    if (!$ipToIf) return [];
    $masks = snmp_walk_suffix($s, OID_IP_AD_ENT_NETMASK);

    $ifIndexes = array_values(array_unique(array_filter(array_map('int_or_null', $ipToIf))));
    $oids = [];
    foreach ($ifIndexes as $i) {
        array_push($oids,
            OID_IF_NAME_COL . ".$i", OID_IF_DESCR_COL . ".$i", OID_IF_OPER_STATUS_COL . ".$i",
            OID_IF_PHYS_ADDR_COL . ".$i", OID_IF_HIGH_SPEED_COL . ".$i");
    }
    $info = [];
    foreach (array_chunk($oids, 40) as $chunk) $info += snmp_get_many($s, $chunk);

    $network = [];
    foreach ($ipToIf as $ip => $ifIndex) {
        $ifIndex = (int) $ifIndex;
        if (!filter_var($ip, FILTER_VALIDATE_IP) || str_starts_with($ip, '127.')) continue;
        $status = (string) ($info[OID_IF_OPER_STATUS_COL . ".$ifIndex"] ?? '');
        $speed = int_or_null($info[OID_IF_HIGH_SPEED_COL . ".$ifIndex"] ?? null);
        $network[] = [
            'ifIndex' => $ifIndex,
            'name' => str_or_null($info[OID_IF_NAME_COL . ".$ifIndex"] ?? null)
                ?? str_or_null($info[OID_IF_DESCR_COL . ".$ifIndex"] ?? null)
                ?? "if$ifIndex",
            'ip' => $ip,
            'mask' => str_or_null($masks[$ip] ?? null),
            'mac' => format_mac((string) ($info[OID_IF_PHYS_ADDR_COL . ".$ifIndex"] ?? '')),
            'status' => $status === '1' ? 'up' : ($status === '2' ? 'down' : 'unknown'),
            'speedMbps' => $speed ?: null,
        ];
    }
    usort($network, fn($a, $b) => ip2long($a['ip']) <=> ip2long($b['ip']));
    return $network;
}

/** Returns ['details' => array, 'collectedAt' => ms] or null if never collected. */
function load_host_details(int $hostId): ?array {
    $stmt = db()->prepare('SELECT data, collected_at FROM host_details WHERE host_id = ?');
    $stmt->execute([$hostId]);
    $row = $stmt->fetch();
    if (!$row) return null;
    return ['details' => json_decode($row['data'], true), 'collectedAt' => (int) $row['collected_at']];
}

function save_host_details(int $hostId, array $details): int {
    $ts = (int) round(microtime(true) * 1000);
    db()->prepare(
        'INSERT INTO host_details (host_id, data, collected_at) VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE data = VALUES(data), collected_at = VALUES(collected_at)'
    )->execute([$hostId, json_encode($details), $ts]);
    adopt_host_name($hostId, $details['system']['name'] ?? null);
    return $ts;
}

const SNMP_POLL_SEC = 240;   // polled by webmonitor-snmp.timer every 5 min

/**
 * Re-collects SNMP details for NAS hosts every SNMP_POLL_SEC, so their
 * health is monitored (and alerted on) without anyone opening Details.
 * Called by cron every minute.
 */
function poll_snmp_devices(): void {
    if (!host_details_available()) return;
    $pdo = db();
    $due = $pdo->prepare("SELECT h.* FROM hosts h LEFT JOIN host_details d ON d.host_id = h.id
                          WHERE h.device = 'nas' AND (d.collected_at IS NULL OR d.collected_at < ?)");
    $due->execute([(int) round(microtime(true) * 1000) - SNMP_POLL_SEC * 1000]);
    foreach ($due->fetchAll() as $h) {
        $details = collect_host_details($h['ip_address'], $h['community'] !== '' ? $h['community'] : 'public');
        // A failed poll keeps the previous details; alerts treat them as
        // stale (and leave NAS alerts unchanged) after 20 minutes.
        if ($details !== null) {
            save_host_details((int) $h['id'], $details);
            if (!empty($details['nas'])) record_points((int) $h['id'], nas_history_points($details['nas']));
        } else fwrite(STDERR, date('c') . " no SNMP response from {$h['name']} ({$h['ip_address']})\n");
    }
}
