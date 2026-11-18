# 🛰️ WebMonitor

A self-hosted dashboard for keeping an eye on a home or small-office network:
server and device uptime, what every device on the network is, switch and Wi-Fi
details, traffic, alerts, and OS updates through a small agent. It runs on your
own server, with no cloud service: on Rocky Linux with the install script, or
anywhere with Docker.

**Help:** the guides in [docs/](docs/README.md) are also built into the
dashboard (the **?** button at the top right).

## Features

**Monitoring**
- Host cards with live up/down status and response time. Checks are plain TCP
  connects, so no raw ICMP or special privileges are needed.
- Server details over SNMP (system, CPU, memory, disks, interfaces), QNAP NAS
  health, and history graphs kept for over a year.
- Service monitoring: web pages (status, keyword, response time, certificate
  expiry), TCP ports and DNS lookups checked from the server with no agent, plus
  systemd units and Windows services watched by the agent, with a Restart button.
- Alerts for hosts going down, agents going offline, disks filling, failed
  services, monitored services going down, crashes, reboots, bandwidth and slow
  internet. Sent by email (SMTP), ntfy or webhooks (Discord, Slack or any JSON
  endpoint), as one digest per run.

**Network**
- Subnet discovery on a schedule, adding or listing new devices and removing
  cards that have been gone for a set number of days, with an outage guard.
- Network topology from switch port maps (CDP/LLDP neighbours, MAC tables,
  VLANs), Aruba Instant Wi-Fi clients by access point, and Proxmox VMs and
  containers matched to the hosts they run on.
- Switch and router configuration backups over SSH, with version history and
  change diffs (Cisco, HPE Aruba, Fortinet, Juniper, MikroTik, pfSense and others).
- Traffic from NetFlow/IPFIX: top talkers, per-host bandwidth, and what's using
  each switch port. Flows reported by more than one switch are counted once.
- A live map of the router, switches and access points (and optionally every
  device on each port), with each link coloured and sized by its traffic right
  now; per-port faceplates, live tables and history charts.
- Internet speed tests on a schedule an admin sets, or on demand, with history
  and alerts when the connection is slower than a set minimum.

**Devices**
- A device inventory that works out what every address is (game console, TV,
  smart speaker, camera, phone, printer, ...) from Wi-Fi hostnames, MAC makers,
  DHCP leases and the Wi-Fi controller's OS fingerprint.
- Deep device scans (open ports, web titles, UPnP, mDNS, NetBIOS, SNMP and brand
  info pages), daily or on demand, with optional auto-naming.
- Home Assistant link (read-only) for device names, rooms, makers and models.
- Vulnerability scans with nmap, built-in checks and CVE lookups (NVD, OSV).

**Updates (agent)**
- A lightweight agent for Linux (Python, standard library only) and Windows
  (PowerShell) reports metrics, inventory, pending updates and workloads.
- Install updates or reboot from the dashboard, or on recurring update windows.
  The agent only runs a fixed set of job types and only connects out.

**Access**
- Logins with Admin and Viewer roles, bcrypt passwords, CSRF protection and
  HTTPS (self-signed by default).

## System requirements

| | Minimum | Recommended |
|---|---|---|
| CPU | 2 cores (x86-64) | 4 cores |
| Memory | 2 GB | 4 GB or more with NetFlow |
| Disk | 10 GB | 40 GB SSD (about 1.5 GB per 100 devices with a year of history and NetFlow) |
| OS | Rocky Linux 9/10 (or AlmaLinux/RHEL), or any Linux with Docker 24+ / Podman 4+ | A dedicated server or VM with a fixed IP |

The server needs to reach the networks it monitors (TCP checks, SNMP on UDP 161,
SSH for backups) and listens on HTTPS, HTTP (redirect only) and UDP 2055 for
NetFlow. See [docs/requirements.md](docs/requirements.md) for the full list,
including what agents and monitored devices need.

## Install

Three ways to run it:

| | Use it for | Guide |
|---|---|---|
| **Rocky Linux script** | A dedicated server or VM | [docs/install-rocky.md](docs/install-rocky.md) |
| **Docker Compose** | A Linux host with Docker, or if you prefer containers | [docs/install-docker.md](docs/install-docker.md) |
| **Local test copy** | Trying it out, testing an upgrade, or development on your PC | [docs/local-testing.md](docs/local-testing.md) |

### Rocky Linux

Copy `deploy/rocky-install.sh` to a fresh server and run it as root. It carries
every application file.

```sh
sudo bash rocky-install.sh
```

It installs Apache, PHP, MariaDB and the background jobs, sets up HTTPS, and
shows a generated `admin` password at the end. Open `https://<server>/webmonitor/`.

Upgrade with `deploy/rocky-upgrade.sh` from the new release, which backs up
first and keeps `config.php` ([details](deploy/UPGRADING.md)):

```sh
sudo bash rocky-upgrade.sh
```

### Docker

```sh
git clone https://github.com/treelinesoftware/WebMonitor-Public.git webmonitor && cd webmonitor
cp .env.example .env        # set WEBMON_DB_PASSWORD (openssl rand -hex 24)
docker compose up -d --build
docker compose logs app     # shows the generated admin password once
```

Open `https://<host>:8443/webmonitor/`. `compose.yaml` uses host networking,
which WebMonitor needs for MAC addresses, NetFlow and device discovery; on
Docker Desktop use `compose.bridge.yaml`. Upgrade with
`git pull && docker compose up -d --build`.

### Local test copy

```sh
cp .env.example .env        # set WEBMON_DB_PASSWORD and WEBMON_ADMIN_PASSWORD
docker compose -f compose.bridge.yaml up -d --build
```

Open `https://localhost:8443/webmonitor/`. Add `-f compose.dev.yaml` to serve the
app straight from your checkout while you work on it.

## Agents

Add a host's agent from its card in the dashboard: it shows a one-time install
command with an enrollment token and the server's certificate pin.

- Linux: `agent/install.sh` installs `webmonitor-agent.py` as a systemd service.
- Windows: `agent/install.ps1` installs `webmonitor-agent.ps1` as a scheduled task.

## Background jobs

| Job | When | What |
|---|---|---|
| `cli/cron.php` | every minute | host checks, alerts, requested scans and backups, schedules, flows, switch port traffic |
| `cli/poll-snmp.php` | every 5 minutes | SNMP polling, subnet discovery, port and Wi-Fi maps, DHCP leases, Home Assistant sync, daily device scans, auto-naming |
| `cli/vulnscan.php` | every minute | starts queued vulnerability scans |
| `cli/speedtest.php --if-due` | every minute | runs an internet speed test when one is due or was asked for |
| `cli/flow-collector.py` | service | NetFlow/IPFIX collector |

The Rocky install runs them as systemd timers; the Docker image runs them with
its own scheduler (`docker/scheduler.sh`) on the same timetable.

## Development

Releases are built from the repository. After changing any application file,
regenerate the embedded copies in the Rocky install and upgrade scripts:

```sh
python3 deploy/build-install-script.py
```

The Docker image is built from the checkout (`docker/Dockerfile`). For a
development copy that serves your working tree, see
[docs/local-testing.md](docs/local-testing.md#work-on-the-code).

Tests that need no server:

```sh
php deploy/test-device-identity.php
php deploy/test-homeassistant.php
node deploy/test-scans.js
python3 deploy/test-upgrade.py
```

`deploy/test-scan-queue.php` and `deploy/test-subnet-queue.php` need a
throwaway `webmonitor_test_*` database.

## Project layout

```
api/          JSON endpoints used by the dashboard
assets/       dashboard JavaScript and CSS
includes/     application logic (checks, discovery, devices, SNMP, alerts, ...)
cli/          background jobs, migrations and admin tools
migrations/   numbered SQL migrations (applied by cli/migrate.php)
agent/        Linux and Windows agents and their installers
docs/         help guides (also shown in the dashboard's Help)
docker/       Docker image, entrypoint and job scheduler
deploy/       Rocky Linux install/upgrade scripts, their builder, and tests
```

## License

MIT. See [LICENSE](LICENSE).
