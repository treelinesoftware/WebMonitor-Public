# Devices and security

## Device inventory

**Reports → Devices** lists every address WebMonitor knows, and what it works
out each one is: game console, TV, smart speaker, camera, phone, printer and so
on. It uses:

- MAC address makers (the IEEE list), from the server's ARP table, switch MAC
  tables and DHCP leases read from the switch;
- Wi-Fi hostnames and OS fingerprints from Aruba Instant;
- deep device scans (below);
- Home Assistant, if linked (below).

Export it with **Export CSV** or **Print / PDF**.

## Device scans

A scan asks a device about itself: open ports, web page titles, UPnP, mDNS/Bonjour,
NetBIOS, SNMP and brand info pages (Roku, Google Cast, Shelly, Tasmota, Philips
Hue). Devices are scanned daily, or right away with **Scan now** on a card.

The **Scans** tab shows what's running and waiting. Requested scans normally
start within a minute; subnet sweeps and daily scans run with the 5-minute
background poll.

**Auto-naming** (per subnet, under **Settings → Subnets**) names cards from what
devices say about themselves. **Wrong network** warnings show when a device type
is outside where it belongs (for example, a camera outside the IoT VLAN).

## Home Assistant

**Settings → Home Assistant** links WebMonitor to Home Assistant, read-only. It
reads every device Home Assistant knows each hour (names, rooms, makers, models,
firmware), including cloud-linked brands such as Tuya, Blink, Google and Alexa,
and matches them by MAC, IP address or name.

1. In Home Assistant: your profile → **Security** → **Long-lived access tokens** → create one.
2. In WebMonitor: enter Home Assistant's address (for example `http://192.168.1.20:8123`)
   and the token, then **Save and sync**.

The token is stored encrypted and never shown again.

## Locations

**Settings → Locations** lists places (rooms, buildings, racks). Pick one for a
device in its card's **Edit**; it shows on the card.

## Vulnerability scans

The **Security** tab scans your devices and lists what it finds by severity
(CVSS: Critical, High, Medium, Low, Info). **Start scan** scans everything, one
subnet or one host. Scans run in the background; the page updates as they go.

What it checks:

- **Every device:** nmap checks the top 1,000 TCP ports and identifies services
  and versions. Built-in checks look for cleartext logins (Telnet, FTP),
  services open without a password (VNC, Redis, MongoDB, Docker), SMBv1, old TLS
  versions and weak ciphers, expired certificates, login pages over plain HTTP,
  and SNMP readable with `public`.
- **Devices without an agent:** known CVEs for each service version, from the
  NVD database. These are by version, so they can include issues a vendor fixed
  without changing the version.
- **Hosts with an agent:** pending security updates and the CVEs they fix (OSV
  database), antivirus state and pending reboots.

Only product names and versions are sent to NVD and OSV, never addresses.

A full scan of a large network takes a while and adds load on the devices
scanned. Run it outside busy hours, and only scan networks you're responsible for.
