<?php
/**
 * QNAP NAS over SNMP (no agent: QTS has no systemd/Python and resets custom
 * services on reboot). Uses QNAP's NAS-MIB "Ex" tables (64-bit sizes,
 * numeric temperatures) and the newer QTS-MIB for model, serial, system
 * temperature and RAID levels.
 *
 * Enable SNMP on the NAS: Control Panel -> Network & File Services -> SNMP.
 */

const QNAP_MODEL = '.1.3.6.1.4.1.24681.1.4.1.1.1.1.1.2.1.3.1';   // answers fast: used to detect QNAPs
// QNAP's agent is slow on some tables (2+ s per request); give it time.
const QNAP_TIMEOUT_SEC = 5.0;
const QNAP_DISK_EX = '.1.3.6.1.4.1.24681.1.3.11.1';               // .col.idx
const QNAP_FAN_EX = '.1.3.6.1.4.1.24681.1.3.15.1';
const QNAP_VOLUME_EX = '.1.3.6.1.4.1.24681.1.3.17.1';
const QNAP_ENCLOSURE = '.1.3.6.1.4.1.24681.1.4.1.1.1.1.1.2.1';    // model, serial, name, temp
const QNAP_POOLS = '.1.3.6.1.4.1.24681.1.4.1.1.1.2.1.2.1';        // capacity, status, RAID level
const HR_STORAGE_DESCR = '.1.3.6.1.2.1.25.2.3.1.3';
const HR_STORAGE_UNITS = '.1.3.6.1.2.1.25.2.3.1.4';
const HR_STORAGE_SIZE = '.1.3.6.1.2.1.25.2.3.1.5';
const HR_STORAGE_USED = '.1.3.6.1.2.1.25.2.3.1.6';

// Alert thresholds for NAS hardware (°C).
const NAS_DISK_TEMP_WARN = 55;
const NAS_DISK_TEMP_CRIT = 60;
const NAS_SYSTEM_TEMP_WARN = 60;
const NAS_SYSTEM_TEMP_CRIT = 70;

/** [index => [col => value]] from a walk of TABLE.entry (suffixes "col.idx"). */
function snmp_table(SNMP $s, string $entryOid): array {
    $rows = [];
    foreach (snmp_walk_suffix($s, $entryOid) as $suffix => $v) {
        [$col, $idx] = array_pad(explode('.', (string) $suffix, 2), 2, null);
        if ($idx !== null) $rows[$idx][(int) $col] = $v;
    }
    ksort($rows, SORT_NATURAL);
    return $rows;
}

function is_qnap(SNMP $s): bool {
    return (bool) snmp_get_many($s, [QNAP_MODEL]);
}

/**
 * QNAP details. $s should use QNAP_TIMEOUT_SEC; $cpuLoad is the per-core
 * hrProcessorLoad list the generic collector already fetched.
 */
function collect_qnap(SNMP $s, array $sys, array $cpuLoad): array {
    $enc = snmp_table($s, QNAP_ENCLOSURE)['1'] ?? [];

    $disks = [];
    foreach (snmp_table($s, QNAP_DISK_EX) as $r) {
        // Columns: 2 name, 3 temp °C, 4 status (0 ready, -5 no disk), 5 model, 6 bytes, 7 SMART.
        $status = int_or_null($r[4] ?? null);
        if ($status === -5) continue;   // empty bay
        $disks[] = [
            'slot' => (string) ($r[2] ?? ''), 'model' => (string) ($r[5] ?? ''),
            'bytes' => int_or_null($r[6] ?? null), 'tempC' => int_or_null($r[3] ?? null) ?: null,
            'status' => $status, 'smart' => (string) ($r[7] ?? ''),
        ];
    }

    $fans = [];
    foreach (snmp_table($s, QNAP_FAN_EX) as $r) {
        $fans[] = ['name' => (string) ($r[2] ?? ''), 'rpm' => int_or_null($r[3] ?? null)];
    }

    $pools = [];
    foreach (snmp_table($s, QNAP_POOLS) as $r) {
        // Columns: 2 pool id, 3 capacity bytes, 5 status, 7 RAID level ("Single", "1", "5", ...).
        $level = (string) ($r[7] ?? '');
        $pools[] = [
            'id' => int_or_null($r[2] ?? null), 'bytes' => int_or_null($r[3] ?? null),
            'status' => (string) ($r[5] ?? ''),
            'level' => ctype_digit($level) ? "RAID $level" : $level,
        ];
    }

    $volumes = [];
    foreach (snmp_table($s, QNAP_VOLUME_EX) as $r) {
        // Columns: 2 "[Volume NAME, Pool N]", 3 filesystem, 4 total KB, 5 free KB, 6 status.
        $desc = (string) ($r[2] ?? '');
        preg_match('/Volume\s+([^,\]]+)(?:,\s*Pool\s+(\d+))?/', $desc, $m);
        $total = (int_or_null($r[4] ?? null) ?? 0) * 1024;
        $free = (int_or_null($r[5] ?? null) ?? 0) * 1024;
        $volumes[] = [
            'name' => trim($m[1] ?? $desc), 'pool' => isset($m[2]) ? (int) $m[2] : null,
            'fs' => (string) ($r[3] ?? ''), 'bytes' => $total, 'freeBytes' => $free,
            'usedPct' => $total ? round(100 * ($total - $free) / $total, 1) : null,
            'status' => rtrim((string) ($r[6] ?? ''), '.'),
        ];
    }

    // Memory: hrStorage lists every mount on a QNAP (dozens of rows, slow),
    // so read the names, then fetch just physical memory, buffers and cache.
    $want = [];
    foreach (snmp_walk_suffix($s, HR_STORAGE_DESCR) as $idx => $name) {
        $name = strtolower((string) $name);
        if (in_array($name, ['physical memory', 'memory buffers', 'cached memory'], true)) $want[$name] = (string) $idx;
        if (count($want) === 3 || str_starts_with($name, '/')) break;   // mounts come after the memory rows
    }
    $mem = null;
    if (isset($want['physical memory'])) {
        $oids = [];
        foreach ($want as $idx) array_push($oids, HR_STORAGE_UNITS . ".$idx", HR_STORAGE_SIZE . ".$idx", HR_STORAGE_USED . ".$idx");
        $v = snmp_get_many($s, $oids);
        $val = fn($base, $name) => (int) ($v[$base . '.' . $want[$name]] ?? 0) * (int) ($v[HR_STORAGE_UNITS . '.' . $want[$name]] ?? 0);
        $mem = ['total' => $val(HR_STORAGE_SIZE, 'physical memory'), 'used' => $val(HR_STORAGE_USED, 'physical memory')];
        foreach (['memory buffers', 'cached memory'] as $n) if (isset($want[$n])) $mem['used'] -= $val(HR_STORAGE_USED, $n);
        $mem['used'] = max(0, $mem['used']);
    }

    preg_match('/(\d+\.\d+\.\d+(?:\.\d+)?)\s*$/', (string) ($sys[OID_SYS_DESCR_0] ?? ''), $fw);
    return [
        'vendor' => 'QNAP',
        'model' => (string) ($enc[3] ?? ''),
        'serial' => (string) ($enc[4] ?? ''),
        'name' => (string) ($enc[6] ?? ''),
        'bays' => int_or_null($enc[5] ?? null),
        'firmware' => $fw[1] ?? null,
        'systemTempC' => int_or_null($enc[7] ?? null),
        'cpuPct' => $cpuLoad ? round(array_sum($cpuLoad) / count($cpuLoad), 1) : null,
        'memory' => $mem,
        'disks' => $disks,
        'fans' => $fans,
        'pools' => $pools,
        'volumes' => $volumes,
    ];
}

/**
 * Alert-worthy NAS conditions: [[type, subject, severity, message]...].
 * Volume space uses the regular disk thresholds ($warnPct/$critPct).
 */
function nas_problems(array $nas, string $label, int $warnPct, int $critPct): array {
    $out = [];
    foreach ($nas['volumes'] ?? [] as $v) {
        $pct = $v['usedPct'];
        $note = $v['status'] && $v['status'] !== 'Ready' ? " (QNAP status: {$v['status']})" : '';
        if ($pct !== null && $pct >= $warnPct) {
            $out[] = ['disk', "volume {$v['name']}", $pct >= $critPct ? 'critical' : 'warning',
                "$label: volume {$v['name']} is {$pct}% full, " . human_bytes((float) $v['freeBytes']) . " free$note"];
        } elseif ($v['status'] && !in_array($v['status'], ['Ready', 'Warning'], true)) {
            $out[] = ['nas_health', "volume {$v['name']}", 'critical', "$label: volume {$v['name']} status is {$v['status']}"];
        }
    }
    foreach ($nas['pools'] ?? [] as $p) {
        if ($p['status'] && $p['status'] !== 'Ready') {
            $out[] = ['nas_health', "pool {$p['id']}", 'critical', "$label: storage pool {$p['id']} ({$p['level']}) is {$p['status']}"];
        }
    }
    foreach ($nas['disks'] ?? [] as $d) {
        $smart = strtoupper($d['smart']);
        if (($smart !== '' && $smart !== 'GOOD' && $smart !== '--') || ($d['status'] !== null && $d['status'] !== 0)) {
            $out[] = ['nas_health', "disk {$d['slot']}", 'critical',
                "$label: {$d['slot']} ({$d['model']}) reports SMART {$d['smart']}, status {$d['status']} — check or replace the drive"];
        } elseif ($d['tempC'] !== null && $d['tempC'] >= NAS_DISK_TEMP_WARN) {
            $out[] = ['nas_health', "disk {$d['slot']} temperature", $d['tempC'] >= NAS_DISK_TEMP_CRIT ? 'critical' : 'warning',
                "$label: {$d['slot']} is running hot ({$d['tempC']} °C)"];
        }
    }
    foreach ($nas['fans'] ?? [] as $f) {
        if ($f['rpm'] === 0) $out[] = ['nas_health', "fan {$f['name']}", 'warning', "$label: {$f['name']} has stopped (0 RPM)"];
    }
    $t = $nas['systemTempC'] ?? null;
    if ($t !== null && $t >= NAS_SYSTEM_TEMP_WARN) {
        $out[] = ['nas_health', 'system temperature', $t >= NAS_SYSTEM_TEMP_CRIT ? 'critical' : 'warning',
            "$label: system temperature is $t °C"];
    }
    return $out;
}

/** History points from a NAS snapshot (see includes/history.php). */
function nas_history_points(array $n): array {
    $p = [
        ['cpu', '', '%', $n['cpuPct'] ?? null],
        ['mem', '', '%', !empty($n['memory']['total']) ? round($n['memory']['used'] / $n['memory']['total'] * 100, 1) : null],
        ['temp_system', '', '°C', $n['systemTempC'] ?? null],
    ];
    foreach ($n['volumes'] ?? [] as $v) $p[] = ['disk', "volume {$v['name']}", '%', $v['usedPct']];
    foreach ($n['disks'] ?? [] as $d) $p[] = ['temp_disk', $d['slot'], '°C', $d['tempC']];
    foreach ($n['fans'] ?? [] as $f) $p[] = ['fan', $f['name'], 'RPM', $f['rpm']];
    return $p;
}
