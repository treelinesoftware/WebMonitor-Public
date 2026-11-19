# Getting started

You've installed WebMonitor and signed in as `admin`. These steps take you from
an empty dashboard to a monitored network. Settings are under the gear icon at
the top right; most of them need an Admin login.

## 1. Change the admin password

**Settings → Account → Change your password.** Use 10 characters or more.

## 2. Add your subnets

**Settings → Subnets → Add subnet**, for example `192.168.1.0/24` (each subnet
is a /24). For each one you can:

- give it a name, shown on the tabs and headings (`Servers · 192.168.1.0/24`);
- set its gateway, which moves the router into the subnet's heading;
- turn on **Scan every ... minutes** to sweep it for devices;
- turn on **Add new devices** so devices it finds get a card automatically.
  Otherwise they're listed for you to review;
- set **Remove cards not seen** to clean up devices that have been gone for a
  number of days. Hosts with an agent, gateways and muted hosts are never
  removed, and nothing is removed when a quarter of a subnet goes quiet at once.

The first scan runs within 5 minutes.

## 3. Add hosts

Discovery finds most devices. To add one by hand: **Settings → Add hosts**.
Enter its IP address, name, OS and device type, and the SNMP community if it
has SNMP. **Scan network** does a one-off sweep of a range.

Each host gets a card on the **Hosts** tab with its status and response time.
Checks are TCP connects to common ports every minute. A host that stays down
for 3 minutes raises an alert (adjustable under **Settings → Notifications**).

Click a card for its details: device info, history graphs, SNMP server details,
switch ports, Wi-Fi clients and configuration backups, depending on the device.
**Edit** changes its name, type, OS, location, group and SNMP community.

To organise cards your own way, add groups under **Settings → Groups** (for
example Servers, Cameras, Office PCs), then tick them in each card's **Edit**;
a card can be in several groups.
Choosing **Group** in the Group switch above the cards shows them by group. On the same Settings tab,
a **screen** is a named set of groups: pick it from **Screen** above the cards to
see only those groups, or open `?screen=N` (the link is shown next to each
screen) on a wall display to start on it.

## 4. Turn on SNMP on your switches and servers

SNMP gives you switch port maps, the network map, live port traffic, server
CPU/memory/disk and NAS health. See [Switches and SNMP](switches-and-snmp.md).

## 5. Set up alerts

**Settings → Notifications**: add where alerts go (email over SMTP, ntfy, or a
webhook for Discord, Slack or any JSON endpoint), then choose what to alert on.
See [Alerts and services](alerts-and-services.md).

## 6. Add users

**Settings → Users → Add a user.** Admins can change everything; Viewers can
see everything but can't change settings, run scans or install updates.

## 7. Optional extras

| Want | Guide |
|---|---|
| Pending OS updates, update windows, CPU/memory/disk from servers and PCs | [Agents and updates](agents-and-updates.md) |
| Who's using the bandwidth, per device and per switch port | [NetFlow](netflow.md) |
| Live switch traffic map and scheduled internet speed tests | [Network tab](network-tab.md) |
| Web page, port and DNS checks | [Alerts and services](alerts-and-services.md) |
| What every device is, deep scans, Home Assistant, vulnerability scans | [Devices and security](devices-and-security.md) |
| FlexLM licence servers: licences in use, who has them, expiry | [FlexLM licences](flexlm.md) |

## The tabs

| Tab | What's there |
|---|---|
| **Hosts** | A card per device, grouped by subnet, with up/down status. Filter by All, Up or Down, or pick a subnet. |
| **Services** | Web, port, DNS and agent service checks, and their problems. |
| **Network** | Internet speed tests and the live network map with per-port traffic. |
| **Updates** | Pending OS updates from agents, update jobs and their history. |
| **Reports** | Network topology, the device inventory and NetFlow traffic. Each can be exported to CSV or printed. |
| **Scans** | The device scan queue: what's running and waiting. |
| **Security** | Vulnerability scan results by severity. |
| **Licences** | FlexLM licence servers, licences in use, who has them checked out, and expiry dates. |
