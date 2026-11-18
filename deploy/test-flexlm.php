<?php
// FlexLM monitoring without lmutil or a database: lmstat output parsing,
// status rules, target checks, and running lmstat through a stand-in script.
//   php deploy/test-flexlm.php

// Local PHP without mbstring: plain-byte stand-ins are enough for these ASCII checks.
if (!function_exists('mb_substr')) { function mb_substr($s, $a, $l = null) { return substr($s, $a, $l); } }
require_once __DIR__ . '/../includes/flexlm.php';

function check(bool $ok, string $message): void {
    if (!$ok) { fwrite(STDERR, "FAIL: $message\n"); exit(1); }
}

$up = <<<'OUT'
lmutil - Copyright (c) 1989-2023 Flexera. All Rights Reserved.
Flexible License Manager status on Wed 11/18/2026 10:00

License server status: 27000@licsrv
    License file(s) on licsrv: /opt/flexlm/license.dat:

    licsrv: license server UP (MASTER) v11.19.1

Vendor daemon status (on licsrv):

     MLM: UP v11.19.1
   cdslmd: The desired vendor daemon is down. (-97,121)
Feature usage info:

Users of MATLAB:  (Total of 10 licenses issued;  Total of 3 licenses in use)

  "MATLAB" v41, vendor: MLM, expiry: 31-dec-2026
  vendor_string: QQ
  floating license

    jsmith pc01 pc01 (v41) (licsrv/27000 101), start Wed 11/18 9:15
    ajones lab-07 lab-07:0.0 (v41) (licsrv/27000 202), start Tue 11/17 16:02, 2 licenses

Users of Simulink:  (Total of 2 licenses issued;  Total of 2 licenses in use)

  "Simulink" v41, vendor: MLM, expiry: 01-jan-0
  floating license

    bwong ws-12 ws-12 (v41) (licsrv/27000 303), start Wed 11/18 8:01
    cdiaz ws-14  (v41) (licsrv/27000 304), start Wed 11/18 8:30
        1 RESERVATION for GROUP eng (licsrv/27000)

Users of Toolbox:  (Uncounted, node-locked)

Users of Single_Seat:  (Total of 1 license issued;  Total of 0 licenses in use)

OUT;

$p = flexlm_parse($up);
check($p['up'], 'up output should be up');
check($p['lmgrd'] === '11.19.1', 'lmgrd version: ' . var_export($p['lmgrd'], true));
check(count($p['vendors']) === 2 && $p['vendors'][0] === ['MLM', true, '11.19.1'] && $p['vendors'][1][1] === false, 'vendors: ' . json_encode($p['vendors']));
check(array_keys($p['features']) === ['MATLAB', 'Simulink', 'Toolbox', 'Single_Seat'], 'features: ' . json_encode(array_keys($p['features'])));
check($p['features']['MATLAB'] === ['MLM', '41', 10, 3, 0, '2026-12-31'], 'MATLAB: ' . json_encode($p['features']['MATLAB']));
check($p['features']['Simulink'][5] === null, 'year-0 expiry means permanent');
check($p['features']['Simulink'][4] === 1, 'reservation counted');
check($p['features']['Toolbox'][2] === 0, 'uncounted feature has no total');
check($p['features']['Single_Seat'][2] === 1, 'singular "license" parsed');
check(count($p['checkouts']) === 4, 'checkouts: ' . json_encode($p['checkouts']));
check($p['checkouts'][0] === ['MATLAB', 'jsmith', 'pc01', 'pc01', '41', 1, 'Wed 11/18 9:15'], 'checkout 1: ' . json_encode($p['checkouts'][0]));
check($p['checkouts'][1][3] === 'lab-07:0.0' && $p['checkouts'][1][5] === 2, 'checkout 2: ' . json_encode($p['checkouts'][1]));
check($p['checkouts'][3][1] === 'cdiaz' && $p['checkouts'][3][3] === null, 'empty display: ' . json_encode($p['checkouts'][3]));

$now = strtotime('2026-11-18 10:00:00') * 1000;
$srv = ['full_pct' => 100, 'expiry_warn_days' => 60, 'status' => 'up', 'fail_count' => 0];
[$st, $msg] = flexlm_judge($srv, $p, $now);
check($st === 'warn', "one vendor down / full feature / expiry -> warn, got $st");
check(str_contains($msg, 'cdslmd') && str_contains($msg, 'Simulink (all 2 in use)') && str_contains($msg, 'MATLAB expires 2026-12-31'), "warn message: $msg");
[$st] = flexlm_judge(['full_pct' => 0, 'expiry_warn_days' => 0] + $srv, ['vendors' => [['MLM', true, null]]] + $p, $now);
check($st === 'up', "nothing to warn about -> up, got $st");

$down = <<<'OUT'
lmutil - Copyright (c) 1989-2023 Flexera. All Rights Reserved.
Flexible License Manager status on Wed 11/18/2026 10:00

Error getting status: Cannot connect to license server system. (-15,570:115 "Operation now in progress")
OUT;
$p = flexlm_parse($down);
check(!$p['up'] && str_contains($p['message'], 'Cannot connect to license server system'), 'down message: ' . $p['message']);
check(flexlm_judge($srv, $p, $now)[0] === 'down', 'cannot connect -> down');

$vendorDown = "License server status: 27000@a\n    a: license server UP (MASTER) v11.16.2\n\nVendor daemon status (on a):\n\n     MLM: The desired vendor daemon is down. (-97,121)\nFeature usage info:\n";
[$st, $msg] = flexlm_judge($srv, flexlm_parse($vendorDown), $now);
check($st === 'down' && str_contains($msg, 'MLM'), "every vendor daemon down -> down: $st $msg");

$triad = "License server status: 27000@a,27000@b,27000@c\n    a: license server UP (MASTER) v11.16.2\n    b: license server UP v11.16.2\n    c: license server DOWN\n\nVendor daemon status (on a):\n\n     MLM: UP v11.16.2\n";
[$st, $msg] = flexlm_judge($srv, flexlm_parse($triad), $now);
check($st === 'warn' && str_contains($msg, 'icense server c is down'), "triad member down -> warn: $st $msg");

check(flexlm_parse("")['message'] === 'No answer from the license server.', 'empty output');
check(flexlm_parse("lmgrd is not running: License server machine is down or not responding. (-96,7)")['message'] === 'lmgrd is not running: License server machine is down or not responding.', 'lmgrd not running');

// ---- start times without a year
$jan = strtotime('2026-01-02 10:00:00') * 1000;
check(flexlm_start_ms('Wed 12/31 23:10', $jan) === strtotime('2025-12-31 23:10:00') * 1000, 'December checkout seen in January is last year');
check(flexlm_start_ms('Wed 11/18 9:15', $now) === strtotime('2026-11-18 09:15:00') * 1000, 'start time this year');
check(flexlm_parse_expiry('permanent') === null && flexlm_parse_expiry('1-jan-2027') === '2027-01-01', 'expiry parsing');

// ---- targets
foreach (['27000@192.168.1.20', '@licsrv', '27000@a,27000@b,27000@c', ' 1717@lic-01.example.lan '] as $t) {
    [$row, $err] = flexlm_validate(['target' => $t]);
    check($err === null, "valid target $t: $err");
}
check(flexlm_validate(['target' => '1717@lic-01.example.lan'])[0]['name'] === '1717@lic-01.example.lan', 'name defaults to the target');
foreach (['', '/opt/flexlm/license.dat', '27000@a,27000@b,27000@c,27000@d', '99999@host', '27000@-x', '27000@host;id', 'host'] as $t) {
    check(flexlm_validate(['target' => $t])[1] !== null, "invalid target accepted: $t");
}
$row = flexlm_validate(['target' => '27000@a', 'interval' => 7, 'fullPct' => 250, 'expiryWarnDays' => -3])[0];
check($row['interval_sec'] === 300 && $row['full_pct'] === 100 && $row['expiry_warn_days'] === 0, 'numbers clamped: ' . json_encode($row));

// ---- ELF interpreter lookup on a system binary
$elf = flexlm_elf_interp(PHP_BINARY);
check($elf !== null && $elf['bits'] === 64 && ($elf['interp'] === null || str_starts_with($elf['interp'], '/')), 'ELF interp of php: ' . json_encode($elf));
check(flexlm_elf_interp(__FILE__) === null, 'a PHP file is not ELF');

// ---- running lmstat in parallel, with a stand-in for lmutil
$dir = sys_get_temp_dir() . '/wm-flexlm-test-' . getmypid();
@mkdir($dir);
$fake = "$dir/lmutil";
file_put_contents($fake, "#!/bin/sh\ncase \"\$4\" in\n  *@slow) sleep 5 ;;\n  *) echo \"args: \$*\"; echo '    x: license server UP (MASTER) v11.1' ;;\nesac\n");
chmod($fake, 0755);
$t0 = microtime(true);
$res = flexlm_lmstat_many(['a' => '27000@one', 'b' => '27001@two', 'c' => '1@slow'], [$fake], 1);
$elapsed = microtime(true) - $t0;
check(str_contains($res['a'][0], 'args: lmstat -a -c 27000@one') && !$res['a'][2], 'fake lmutil a: ' . json_encode($res['a']));
check(str_contains($res['b'][0], '27001@two'), 'fake lmutil b');
check($res['c'][2] === true, 'slow server times out');
check($elapsed < 4, "runs in parallel and stops slow ones ($elapsed s)");
unlink($fake);
rmdir($dir);

echo "FlexLM tests passed.\n";
