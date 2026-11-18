<?php
// Vulnerability scan logic without nmap, a database or the internet:
//   php deploy/test-vulnscan.php
if (!function_exists('mb_substr')) { function mb_substr($s, $a, $l = null) { return substr($s, $a, $l); } }
require_once __DIR__ . '/../includes/vulnscan.php';

function check(bool $ok, string $message): void {
    if (!$ok) { fwrite(STDERR, "FAIL: $message\n"); exit(1); }
}

// CVSS 3.x base scores (values from the FIRST calculator).
foreach (['CVSS:3.1/AV:N/AC:L/PR:N/UI:N/S:U/C:H/I:H/A:H' => 9.8, 'CVSS:3.1/AV:N/AC:L/PR:N/UI:N/S:C/C:H/I:H/A:H' => 10.0,
          'CVSS:3.1/AV:N/AC:H/PR:N/UI:N/S:U/C:H/I:N/A:N' => 5.9, 'CVSS:3.1/AV:L/AC:L/PR:L/UI:N/S:U/C:H/I:H/A:H' => 7.8,
          'CVSS:3.0/AV:N/AC:L/PR:N/UI:R/S:C/C:L/I:L/A:N' => 6.1, 'CVSS:3.1/AV:N/AC:L/PR:N/UI:N/S:U/C:N/I:N/A:N' => 0.0] as $v => $want) {
    check(cvss3_base_score($v) === $want, "$v -> " . var_export(cvss3_base_score($v), true) . ", want $want");
}
check(cvss3_base_score('AV:N/AC:L') === null, 'not a v3 vector');
check(cvss_severity(9.0) === 'critical' && cvss_severity(8.9) === 'high' && cvss_severity(4.0) === 'medium'
      && cvss_severity(0.1) === 'low' && cvss_severity(0.0) === 'info' && cvss_severity(null) === 'info', 'severity bands');

check(nmap_cpe_to_23('cpe:/a:openbsd:openssh:8.9p1') === 'cpe:2.3:a:openbsd:openssh:8.9:p1', 'OpenSSH CPE');
check(nmap_cpe_to_23('cpe:/a:apache:http_server:2.4.57') === 'cpe:2.3:a:apache:http_server:2.4.57', 'Apache CPE');
check(nmap_cpe_to_23('cpe:/o:linux:linux_kernel') === null, 'no version: no lookup');
check(osv_ecosystem(['id' => 'debian', 'version_id' => '12']) === 'Debian:12', 'Debian');
check(osv_ecosystem(['id' => 'ubuntu', 'version_id' => '22.04']) === 'Ubuntu:22.04:LTS', 'Ubuntu LTS');
check(osv_ecosystem(['id' => 'rocky', 'version_id' => '10.2']) === 'Rocky Linux:10', 'Rocky');
check(osv_ecosystem(['id' => 'fedora', 'version_id' => '44']) === null, 'unsupported');

$xml = <<<'XML'
<?xml version="1.0"?>
<nmaprun><host><status state="up"/><address addr="192.0.2.10" addrtype="ipv4"/>
<ports>
 <port protocol="tcp" portid="21"><state state="open"/><service name="ftp" product="vsftpd" version="3.0.3"><cpe>cpe:/a:vsftpd:vsftpd:3.0.3</cpe></service>
  <script id="ftp-anon" output="Anonymous FTP login allowed (FTP code 230)"/></port>
 <port protocol="tcp" portid="22"><state state="open"/><service name="ssh" product="OpenSSH" version="8.9p1" ostype="Linux"><cpe>cpe:/a:openbsd:openssh:8.9p1</cpe></service></port>
 <port protocol="tcp" portid="23"><state state="open"/><service name="telnet"/></port>
 <port protocol="tcp" portid="80"><state state="open"/><service name="http" product="lighttpd"/>
  <script id="http-title" output="Router Login"><elem key="title">Router Login</elem></script></port>
 <port protocol="tcp" portid="443"><state state="open"/><service name="https" tunnel="ssl"/>
  <script id="ssl-cert" output="..."><table key="subject"><elem key="commonName">dev</elem></table><table key="issuer"><elem key="commonName">dev</elem></table>
   <table key="validity"><elem key="notBefore">2020-01-01T00:00:00</elem><elem key="notAfter">2021-01-01T00:00:00</elem></table></script>
  <script id="ssl-enum-ciphers" output="..."><table key="TLSv1.0"><table key="ciphers"></table></table><table key="TLSv1.2"></table><elem key="least strength">F</elem></script></port>
 <port protocol="tcp" portid="5900"><state state="open"/><service name="vnc"/><script id="vnc-info" output="Protocol version: 3.8&#xa;Security types: &#xa;  None (1)"/></port>
 <port protocol="tcp" portid="6379"><state state="open"/><service name="redis"/><script id="redis-info" output="Version: 6.0.16"/></port>
 <port protocol="tcp" portid="8080"><state state="closed"/><service name="http-proxy"/></port>
</ports>
<hostscript><script id="smb-protocols" output="dialects: NT LM 0.12 (SMBv1) [dangerous, but default]"><table key="dialects"><elem>NT LM 0.12 (SMBv1) [dangerous, but default]</elem></table></script></hostscript>
</host></nmaprun>
XML;
$hosts = parse_nmap_xml($xml);
$h = $hosts['192.0.2.10'];
check(count($h['ports']) === 7 && $h['os'] === 'Linux', 'open ports only, OS from service: ' . count($h['ports']));
check($h['ports'][1]['cpes'] === ['cpe:/a:openbsd:openssh:8.9p1'], 'CPEs');
check($h['ports'][4]['scripts']['ssl-cert']['data']['validity']['notAfter'] === '2021-01-01T00:00:00', 'nested script data');
$f = builtin_checks($h, strtotime('2026-11-16'));
$by = [];
foreach ($f as $x) $by[$x['check_id']] = $x;
foreach (['ftp-anon' => 'high', 'telnet' => 'high', 'http-login' => 'low', 'cert-expired' => 'medium', 'cert-self-signed' => 'info',
          'tls-old' => 'medium', 'tls-weak-ciphers' => 'medium', 'vnc-noauth' => 'critical', 'redis-noauth' => 'critical', 'smbv1' => 'high'] as $id => $sev) {
    check(isset($by[$id]) && $by[$id]['severity'] === $sev, "$id should be $sev: " . json_encode($by[$id] ?? null));
}
check(!isset($by['ftp-cleartext']), 'anonymous FTP replaces the cleartext finding');
check($by['telnet']['port'] === 23 && $by['vnc-noauth']['port'] === 5900, 'findings carry the port');
check(count($f) === 10, 'no extra findings: ' . implode(',', array_keys($by)));
echo "Vulnerability scan checks passed.\n";
