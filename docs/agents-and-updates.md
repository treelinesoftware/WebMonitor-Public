# Agents and updates

The agent is a small program for Linux and Windows servers and PCs. It reports
CPU, memory, disks, inventory, failed services, workloads (Proxmox guests,
Docker/Podman containers) and pending updates, and it installs updates or
reboots when you ask or on a schedule.

It only connects out to WebMonitor over HTTPS; nothing listens on the host. It
only runs a fixed set of jobs, so the server can't make it run arbitrary commands.

## Install an agent

1. Open the host's card → **Agent** → **Install agent** (admins).
2. Pick Linux or Windows. WebMonitor shows a one-time install command with an
   enrollment token and the server's certificate fingerprint.
3. Run it on the host: as root on Linux, or in an elevated PowerShell on Windows.

The card shows the agent's data within a minute. The command works once and
expires after 24 hours; make a new one for each host.

- **Linux:** installs `webmonitor-agent.py` as a systemd service. Needs Python
  3.7+. Updates work on apt and dnf/yum systems.
- **Windows:** installs `webmonitor-agent.ps1` as a scheduled task. Needs
  Windows PowerShell 5.1+.

The agent trusts the server by its certificate. If the server's certificate
changes (a reinstall without restoring it, or a move to Docker without copying
it), reinstall the agents.

## Install updates

The **Updates** tab lists every host with an agent and its pending updates.
Select hosts, then:

| Action | What it does |
|---|---|
| Check for updates | Refreshes the list now |
| Install security updates | Security updates only |
| Install all updates | Everything pending |
| Reboot | Restarts the host |
| Upgrade agent | Installs the server's current agent version |

Tick **Reboot afterwards if required** to restart only when the updates need it.
**Job history** shows each job's progress and output.

## Update windows

**Settings → Schedules** sets recurring update windows: which hosts, which day
and time, security or all updates, and whether to reboot. Times use the
server's timezone. WebMonitor warns about clashes, such as a Proxmox host
rebooting while its VMs are being updated, or the WebMonitor server rebooting
while other hosts' jobs run.

## Remove an agent

On Linux, run the installer with `--uninstall` (`sudo bash install.sh --uninstall`),
or `sudo systemctl disable --now webmonitor-agent` and delete
`/usr/local/lib/webmonitor-agent`. On Windows, delete the **WebMonitor Agent**
scheduled task and its folder. Then remove the agent from the host's card.
