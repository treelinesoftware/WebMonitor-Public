<?php
// Checks without a database: DHCP lease parsing and identification rules.
//   php deploy/test-device-identity.php
require_once __DIR__ . '/../includes/dhcpleases.php';
require_once __DIR__ . '/../includes/devices.php';
require_once __DIR__ . '/../includes/devicescan.php';

function check(bool $ok, string $message): void {
    if (!$ok) { fwrite(STDERR, "FAIL: $message\n"); exit(1); }
}

$bindings = <<<'OUT'
Bindings from all pools not associated with VRF:
IP address      Client-ID/              Lease expiration        Type       State      Interface
                Hardware address/
                User name
192.168.10.202    01bc.2411.8074.eb       Nov 17 2026 03:00 AM    Automatic  Active     Vlan10
192.168.20.22     fffd.83b8.f900.0100.    Nov 21 2026 02:08 AM    Automatic  Active     Vlan20
192.168.50.77     f0f0.a456.6156          Nov 22 2026 05:33 PM    Automatic  Active     Vlan50
192.168.60.88     01b6.0d37.5b05.00       Infinite                Manual     Active     Vlan60
999.1.1.1       01bc.2411.8074.ec       Nov 17 2026 03:00 AM    Automatic  Active     Vlan10
OUT;
$leases = parse_cisco_dhcp_bindings($bindings);
check($leases === [
    '192.168.10.202' => 'bc:24:11:80:74:eb',
    '192.168.50.77' => 'f0:f0:a4:56:61:56',
    '192.168.60.88' => 'b6:0d:37:5b:05:00',
], 'lease parsing: ' . json_encode($leases));

$id = fn(array $d) => identify_device($d + ['name' => null, 'vendor' => null, 'os' => null, 'wireless' => false]);
check($id(['mac' => 'ce:ab:01:9b:00:7d', 'subnetName' => 'Wifi'])[0] === 'phone', 'private MAC on the Wifi subnet is a phone');
check($id(['mac' => 'ce:ab:01:9b:00:7d', 'subnetName' => 'Servers'])[0] === 'server', 'private MAC on a server subnet is a VM');
check($id(['mac' => 'b6:0d:37:5b:05:00', 'subnetName' => 'IoT'])[1] === 'Device on the IoT network', 'private MAC on IoT falls to the subnet rule');
check($id(['mac' => null, 'subnetName' => 'TV'])[0] === 'tv', 'unknown device on the TV subnet');
check($id(['mac' => 'ce:ab:01:9b:00:7d', 'subnetName' => 'Clients', 'wireless' => true])[0] === 'phone', 'private MAC seen on Wi-Fi is a phone');
check($id(['mac' => null, 'subnetName' => 'IoT', 'scan' => ['services' => ['_hap', '_http']]])[1] === 'HomeKit device', 'HomeKit service');
check($id(['mac' => null, 'subnetName' => 'Servers', 'scan' => ['services' => ['_rdlink', '_airplay', '_companion-link']]])[1] === 'Mac', 'a Mac advertising AirPlay is a Mac');
$off = 0;
$packet = "\x03abc\x05local\x00" . "\xC0\x00";
check(dns_read_name($packet, $off) === 'abc.local' && $off === 11, 'DNS name');
$off = 11;
check(dns_read_name($packet, $off) === 'abc.local' && $off === 13, 'compressed DNS name');
check($id(['mac' => null, 'subnetName' => 'IoT', 'haKinds' => ['sensor', 'camera']])[0] === 'camera', 'Home Assistant camera entity');
check($id(['mac' => null, 'subnetName' => 'IoT', 'haKinds' => ['media_player'], 'scanText' => 'Google Nest Mini Living room speaker'])[0] === 'speaker', 'a speaker named so is not a TV');
require_once __DIR__ . '/../includes/discovery.php';
$h = 3600000;
check(looks_like_outage([10 * $h, 10 * $h + 300000, 10 * $h + 600000], 5), 'hosts that went quiet together look like an outage');
check(looks_like_outage([10 * $h, 200 * $h], 0), 'nothing answering now looks like an outage');
check(!looks_like_outage([10 * $h, 40 * $h, 90 * $h, 200 * $h], 5), 'hosts that dropped off over days are not an outage');
check(!looks_like_outage([0, 0, 0], 5), 'never-seen hosts are not an outage');
echo "Device identity checks passed.\n";
