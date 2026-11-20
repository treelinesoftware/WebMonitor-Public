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

To update by group, pick **Groups** under Hosts and tick one or more groups
(Settings → Groups). The group's cards are looked up each time the window runs,
so cards added to a group later are included; cards without an agent are left
out. Removing a group takes it off its schedules, and a schedule left with no
groups is turned off.

## Change management and audit log

Part of [WebMonitor Pro](pro.md).

Every update window, and every manual update, reboot, agent upgrade or
service restart, gets a **change record** (CHG-000123) under
**Updates → Change records**. It keeps:

- what was planned: the hosts and, for updates, each host's pending packages
  when the record was made;
- who requested it, and who approved or rejected it (with their note);
- the result on each host: job status, summary, the end of its output and a
  SHA-256 of the full output;
- a check after the next inventory of which planned packages are still pending.

Records are kept indefinitely (job history is pruned after 180 days) and can
be exported as CSV.

**Settings → Change management** sets:

- **Change list**: the email addresses that get change emails, sent through
  your email channel's SMTP settings (alert emails are unaffected). They get an
  advance notice for each scheduled window, a notice when a manual change
  starts (optional), approvals and rejections, and a completion report.
- **Advance notice**: how many hours before a scheduled window its change
  record is raised and emailed (0 = when it runs). Hosts are checked again when
  the window runs.
- **Require approval**: a scheduled window then runs only if an admin
  approved its change record beforehand; one not approved in time is skipped
  and recorded. Manual changes count as approved by the admin who starts them.
  Any planned window can be rejected, with a reason, whether or not approval is
  on.

**Reports → Audit log** (admins) lists everything changed in WebMonitor:
sign-ins (including failed and locked-out ones), every settings, host, user,
group, schedule and job change made in the dashboard (with what was sent,
passwords and tokens redacted), schedule runs and the change-record history.
Filter it, export it as CSV, or use **Verify integrity**: each entry is chained
to the one before by a SHA-256 hash, so an entry edited or deleted directly in
the database shows up as a break.

## Remove an agent

On Linux, run the installer with `--uninstall` (`sudo bash install.sh --uninstall`),
or `sudo systemctl disable --now webmonitor-agent` and delete
`/usr/local/lib/webmonitor-agent`. On Windows, delete the **WebMonitor Agent**
scheduled task and its folder. Then remove the agent from the host's card.
