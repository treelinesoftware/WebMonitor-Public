# System requirements

WebMonitor runs on one server on your network. Pick the install that suits you:

| Install | Best for |
|---|---|
| [Rocky Linux script](install-rocky.md) | A dedicated server or VM. Uses systemd, Apache and MariaDB from the OS. |
| [Docker](install-docker.md) | A Linux host that already runs Docker, or if you prefer containers. |
| [Local test copy](local-testing.md) | Trying WebMonitor, or testing changes, on your own PC. |

## Server

| | Minimum | Recommended |
|---|---|---|
| CPU | 2 cores (x86-64) | 4 cores |
| Memory | 2 GB | 4 GB or more with NetFlow, 8 GB for 250+ devices |
| Disk | 10 GB free | 40 GB (SSD). History is kept for 400 days; about 1.5 GB per 100 devices with NetFlow |
| Network | Wired, on a network that can reach every subnet you monitor | Same, with a fixed IP address |

**Rocky Linux script:** Rocky Linux 9 or 10, or another RHEL-compatible
distribution (AlmaLinux, RHEL), as a fresh install with root access. The script
installs Apache with mod_ssl, MariaDB, PHP 8 (php-fpm, php-mysqlnd, php-snmp),
net-snmp-utils, nmap and Python 3.

**Docker:** Docker Engine 24 or newer with the Compose plugin (`docker compose`),
or Podman 4+ with `podman compose`. A Linux host is recommended, because the
full feature set needs host networking. Docker Desktop on Windows or macOS
works, with [some limits](install-docker.md#docker-desktop-and-bridge-networking).
The images are about 400 MB (app) and 400 MB (MariaDB).

## Network access

Ports **on the WebMonitor server** that other devices connect to:

| Port | Who connects | What for |
|---|---|---|
| TCP 443 (script) or 8443 (Docker) | Browsers, agents | The dashboard and the agent API (HTTPS) |
| TCP 80 (script) or 8080 (Docker) | Browsers | Redirects to HTTPS only |
| UDP 2055 | Switches and routers | NetFlow/IPFIX (optional) |

Connections **from the WebMonitor server** to your network:

| Traffic | What for |
|---|---|
| TCP connects to ports 80, 443, 22, 445 and 3389 | Up/down checks and subnet discovery. No ICMP ping is used. |
| UDP 161 (SNMP v1/v2c, read-only) | Switch ports, MAC tables, server details, NAS health, Wi-Fi clients |
| TCP 22 (SSH) | Switch and router configuration backups (optional) |
| UDP 1900, 5353, 137 | Device scans: UPnP, mDNS/Bonjour, NetBIOS (optional) |

Internet access the server uses (optional):

| Destination | What for |
|---|---|
| speed.cloudflare.com | Built-in internet speed tests |
| services.nvd.nist.gov, api.osv.dev | Vulnerability scans: CVE lookups by product name and version (no addresses are sent) |
| Your SMTP server, ntfy, Discord or Slack | Sending alerts |

**Browsers** load the chart library from `cdn.jsdelivr.net`, so the device you
view the dashboard on needs internet access for the graphs to show.

## Agents (optional)

The agent reports metrics and pending updates, and installs updates on schedule.
It only connects out to the WebMonitor server over HTTPS.

| OS | Needs |
|---|---|
| Linux (systemd) | Python 3.7+ (standard library only). Updates are supported on apt (Debian, Ubuntu, Proxmox) and dnf/yum (Rocky, RHEL, Alma, Fedora) systems. |
| Windows | Windows 10/11 or Server 2016+, PowerShell 5.1+ |

## Devices you monitor

Nothing is needed for basic up/down checks. To get more:

| Feature | What the device needs |
|---|---|
| Switch ports, VLANs, MAC tables, neighbours | SNMP v2c read-only, with CDP or LLDP for the map |
| Server CPU, memory, disks | SNMP (net-snmp on Linux) or the agent |
| Configuration backups | SSH access with a user that can show the running config |
| Traffic and per-port flows | NetFlow v5/v9 or IPFIX export (see [NetFlow](netflow.md)) |
| Wi-Fi clients by access point | Aruba Instant (SNMP) |
| Device names, rooms and makers | Home Assistant with a long-lived access token (optional) |

## Browsers

Current versions of Chrome, Edge, Firefox or Safari, on desktop or mobile.
