# Switches and SNMP

WebMonitor reads switches, routers, servers and NAS devices over SNMP v1/v2c,
read-only. Set the device's community on its card (**Edit → SNMP community**);
an empty community means `public`.

## What SNMP gives you

| From | You get |
|---|---|
| Switches | Port list with speed, status, VLANs, descriptions and LAGs; MAC tables (which device is on which port); CDP/LLDP neighbours for the map; DHCP leases (Cisco); live port traffic |
| Routers and firewalls | Interfaces and traffic; the internet port on the network map |
| Linux and Windows servers | System, CPU, memory, disks and interfaces (**Server Details** on the card) |
| QNAP NAS (device type **NAS**) | Disk, volume, temperature and fan health |
| Aruba Instant access points | Wi-Fi clients by access point and SSID |

Port maps and Wi-Fi maps refresh every 5 minutes; live port traffic every few
seconds while the Network tab is open.

## Turn on SNMP

Use a read-only community that isn't `public`, and limit it to the WebMonitor
server's address where the device allows.

**Cisco IOS / IOS-XE**

```
ip access-list standard ACL_SNMP
 permit <WebMonitor server IP>
snmp-server community <community> RO ACL_SNMP
```

CDP is on by default; for LLDP add `lldp run`.

**Aruba / HPE, Fortinet, Ubiquiti, Netgear and others:** turn on SNMP v2c with a
read-only community in the switch's web interface or CLI, and allow the
WebMonitor server's address.

**Linux servers (net-snmp):** run `deploy/enable-snmp-details.sh` from the
repository as root on the server. It asks for a community and the WebMonitor
server's IP address, adds a read-only view with what the details panel shows,
and restarts snmpd (it backs up `snmpd.conf` and restores it if snmpd fails).

```sh
sudo bash enable-snmp-details.sh
```

**Windows servers:** add the SNMP Service feature, set a read-only community and
allow the WebMonitor server under its Security tab. Or use the
[agent](agents-and-updates.md), which reports the same and more.

Then set the same community on the device's card in WebMonitor.

## Configuration backups

WebMonitor can save each switch's and router's running configuration over SSH,
keep its history and show what changed between versions. It alerts when a
configuration changes (turn that off under **Settings → Notifications → When to
alert**).

1. Open the device's card → **Configuration backups**.
2. Pick the vendor (Cisco IOS/IOS-XE, NX-OS and Small Business; HPE Aruba
   ArubaOS-Switch, CX and Instant; Fortinet; Juniper; MikroTik; pfSense/OPNsense;
   Ubiquiti), and enter an SSH user. Sign in with WebMonitor's SSH key (shown there; add it to
   the device's user) or a password. Passwords are stored encrypted.
3. Set **Back up automatically** to daily or weekly, or use **Back up now**.

Older Cisco devices that only offer SHA-1 key exchange or `ssh-rsa` host keys
need **Allow old SSH encryption (SHA-1)** turned on for that device.

The device's SSH host key is remembered on first connect. If you replace a
device or its key changes, use **Forget host key** before the next backup.

## The network map

The topology report (**Reports → Network topology**) and the Network tab's map
are built from CDP/LLDP neighbours and MAC tables, so they need SNMP on each
switch. A switch you've added more than once (for example by two addresses) is
shown once. Devices behind an unmanaged switch show on the port that leads to it.
