<?php
// Antivirus status rules, without a database:  php deploy/test-antivirus.php
require_once __DIR__ . '/../includes/agents.php';

function check(bool $ok, string $message): void {
    if (!$ok) { fwrite(STDERR, "FAIL: $message\n"); exit(1); }
}
$defender = fn(array $o = []) => $o + ['name' => 'Microsoft Defender Antivirus', 'enabled' => true, 'up_to_date' => true,
                                       'realtime' => true, 'signature_age_days' => 0];

check(antivirus_status(null, 'windows') === null, 'older agents report nothing');
$s = antivirus_status(['products' => [$defender()]], 'windows');
check($s['state'] === 'ok' && $s['text'] === 'Defender · on', 'Defender on: ' . json_encode($s));
$s = antivirus_status(['products' => [$defender(['up_to_date' => false, 'signature_age_days' => 9])]], 'windows');
check($s['state'] === 'warn' && $s['text'] === 'Defender · out of date (9 days)', 'out of date: ' . $s['text']);
check(antivirus_status(['products' => [$defender(['realtime' => false])]], 'windows')['text'] === 'Defender · real-time off', 'real-time off');
check(antivirus_status(['products' => [$defender(['enabled' => false])]], 'windows')['state'] === 'off', 'disabled');
check(antivirus_status(['products' => []], 'windows')['state'] === 'off', 'none on Windows is a problem');
check(antivirus_status(['products' => []], 'linux')['state'] === 'none', 'none on Linux is not');
// Third-party AV on, Defender passive: the running one wins.
$s = antivirus_status(['products' => [$defender(['enabled' => false, 'passive' => true]),
                                      ['name' => 'Bitdefender Antivirus', 'enabled' => true, 'up_to_date' => true]]], 'windows');
check($s['state'] === 'ok' && $s['text'] === 'Bitdefender · on', 'third-party AV: ' . $s['text']);
// ClamAV without on-access scanning is normal on Linux.
$s = antivirus_status(['products' => [['name' => 'ClamAV', 'enabled' => true, 'up_to_date' => true, 'realtime' => false]]], 'linux');
check($s['state'] === 'ok' && $s['text'] === 'ClamAV · on', 'ClamAV on demand: ' . $s['text']);
echo "Antivirus checks passed.\n";
