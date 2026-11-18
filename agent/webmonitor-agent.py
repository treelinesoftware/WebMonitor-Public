#!/usr/bin/env python3
"""WebMonitor agent for Linux.

Reports to the WebMonitor server over HTTPS (outbound only, nothing listens
on this host):
  - metrics every minute: CPU, load, memory, disks, network, uptime
  - inventory every hour: OS, kernel, hardware, packages, pending updates,
    reboot-required, failed services, antivirus state
  - workloads every 5 minutes: Proxmox VE guests (VMs and LXC containers)
    and Docker/Podman containers, so the dashboard can show what runs where

Python 3 standard library only. Installed and enrolled by install.sh:
  webmonitor-agent.py --enroll TOKEN --server URL   (one time, by installer)
  webmonitor-agent.py                                (run by systemd)
  webmonitor-agent.py --print metrics|inventory      (show what would be sent)

It also runs jobs from the dashboard (check for / install updates, reboot,
upgrade the agent). Only those job types exist; each is implemented here,
so the server can never make the agent run arbitrary commands.
"""
import argparse
import gzip
import json
import logging
import os
import platform
import re
import shutil
import signal
import socket
import ssl
import subprocess
import sys
import tempfile
import threading
import time
import urllib.error
import urllib.request

VERSION = "0.6.0"
CONF_DIR = "/etc/webmonitor-agent"
CONF_FILE = os.path.join(CONF_DIR, "agent.conf")
CA_FILE = os.path.join(CONF_DIR, "server.crt")
STATE_FILE = "/var/lib/webmonitor-agent/state.json"

DEFAULT_INTERVALS = {"metrics": 60, "inventory": 3600, "workloads": 300}
CMD_TIMEOUT = 180

# Filesystems worth reporting. Network filesystems are skipped on purpose:
# statvfs() on a hung NFS/CIFS mount can block the agent indefinitely.
LOCAL_FS = {"ext2", "ext3", "ext4", "xfs", "btrfs", "zfs", "vfat", "exfat",
            "ntfs", "ntfs3", "f2fs", "jfs", "reiserfs", "fuseblk", "bcachefs"}
SKIP_MOUNT_PREFIXES = ("/snap/", "/var/lib/docker/", "/var/lib/containers/",
                       "/run/", "/proc", "/sys", "/dev")
# Container bridges: internal to the host, always hidden.
CONTAINER_IFACE = re.compile(r"^(docker\d+|br-[0-9a-f]{12}|podman\d+|cni-podman\d+)$")
# Virtual interfaces that are noise unless they carry an address.
VIRTUAL_IFACE_PREFIXES = ("veth", "tap", "fwbr", "fwpr", "fwln", "docker",
                          "br-", "virbr", "vnet", "cali", "flannel", "cni")

log = logging.getLogger("webmonitor-agent")


# ---------------------------------------------------------------- helpers

def read(path, default=None):
    try:
        with open(path, encoding="utf-8", errors="replace") as f:
            return f.read()
    except OSError:
        return default


def run(cmd, timeout=CMD_TIMEOUT):
    """Runs a command; returns (exit_code, stdout). Never raises."""
    if not shutil.which(cmd[0]):
        return None, ""
    env = dict(os.environ, LC_ALL="C", LANG="C")
    try:
        p = subprocess.run(cmd, stdout=subprocess.PIPE, stderr=subprocess.DEVNULL,
                           timeout=timeout, env=env, check=False)
        return p.returncode, p.stdout.decode("utf-8", "replace")
    except (OSError, subprocess.SubprocessError) as e:
        log.warning("%s failed: %s", cmd[0], e)
        return None, ""


# ---------------------------------------------------------------- metrics

class MetricsCollector:
    """Keeps the previous CPU/network sample so rates can be computed."""

    def __init__(self):
        self.prev_cpu = None
        self.prev_net = None
        self.prev_time = None

    @staticmethod
    def _cpu_times():
        parts = (read("/proc/stat", "").splitlines() or [""])[0].split()
        if not parts or parts[0] != "cpu":
            return None
        vals = [int(v) for v in parts[1:9]]  # user nice system idle iowait irq softirq steal
        idle = vals[3] + vals[4]
        return sum(vals), idle

    @staticmethod
    def _net_counters():
        counters = {}
        for line in (read("/proc/net/dev", "").splitlines())[2:]:
            name, _, rest = line.partition(":")
            fields = rest.split()
            if len(fields) >= 9:
                counters[name.strip()] = (int(fields[0]), int(fields[8]))  # rx, tx bytes
        return counters

    def collect(self):
        now = time.monotonic()
        cpu = self._cpu_times()
        net = self._net_counters()
        if self.prev_cpu is None:
            # First sample: take a short baseline so CPU% is meaningful.
            self.prev_cpu, self.prev_net, self.prev_time = cpu, net, now
            time.sleep(1)
            now, cpu, net = time.monotonic(), self._cpu_times(), self._net_counters()

        cpu_pct = None
        if cpu and self.prev_cpu:
            dt, di = cpu[0] - self.prev_cpu[0], cpu[1] - self.prev_cpu[1]
            if dt > 0:
                cpu_pct = round(max(0.0, min(100.0, 100.0 * (dt - di) / dt)), 1)

        elapsed = max(now - self.prev_time, 0.001)
        rates = {}
        for name, (rx, tx) in net.items():
            if name in self.prev_net:
                prx, ptx = self.prev_net[name]
                rates[name] = (max(0, rx - prx) / elapsed, max(0, tx - ptx) / elapsed)
        self.prev_cpu, self.prev_net, self.prev_time = cpu, net, now

        return {
            "cpu_pct": cpu_pct,
            "cpu_count": os.cpu_count(),
            "load": [round(x, 2) for x in os.getloadavg()],
            "memory": memory(),
            "uptime_sec": uptime_sec(),
            "processes": sum(1 for d in os.listdir("/proc") if d.isdigit()),
            "disks": disks(),
            "network": network(net, rates),
        }


def memory():
    info = {}
    for line in read("/proc/meminfo", "").splitlines():
        key, _, val = line.partition(":")
        parts = val.split()
        if parts:
            info[key] = int(parts[0]) * 1024
    total = info.get("MemTotal", 0)
    avail = info.get("MemAvailable", info.get("MemFree", 0))
    swap_total = info.get("SwapTotal", 0)
    return {
        "total": total,
        "used": max(0, total - avail),
        "available": avail,
        "swap_total": swap_total,
        "swap_used": max(0, swap_total - info.get("SwapFree", 0)),
    }


def uptime_sec():
    try:
        return int(float(read("/proc/uptime", "0").split()[0]))
    except (ValueError, IndexError):
        return None


def disks():
    out, seen = [], set()
    for line in read("/proc/mounts", "").splitlines():
        parts = line.split()
        if len(parts) < 3:
            continue
        dev, mnt, fstype = parts[0], parts[1].replace("\\040", " "), parts[2]
        if fstype not in LOCAL_FS or dev in seen or mnt.startswith(SKIP_MOUNT_PREFIXES):
            continue
        seen.add(dev)
        try:
            st = os.statvfs(mnt)
        except OSError:
            continue
        size = st.f_blocks * st.f_frsize
        if size == 0:
            continue
        used = (st.f_blocks - st.f_bfree) * st.f_frsize
        avail = st.f_bavail * st.f_frsize
        out.append({
            "mount": mnt, "device": dev, "fstype": fstype,
            "size": size, "used": used, "available": avail,
            # Same formula as df: space reserved for root doesn't count.
            "used_pct": round(100.0 * used / (used + avail), 1) if used + avail else None,
        })
        if len(out) >= 40:
            break
    return out


def addresses():
    """{iface: {"mac": str, "addrs": [cidr, ...]}} via `ip -j addr`."""
    code, out = run(["ip", "-j", "addr", "show"], timeout=10)
    result = {}
    if code != 0:
        return result
    try:
        for link in json.loads(out):
            addrs = [f"{a['local']}/{a['prefixlen']}" for a in link.get("addr_info", [])
                     if a.get("scope") == "global" and "local" in a]
            result[link["ifname"]] = {"mac": link.get("address"), "addrs": addrs,
                                      "up": "UP" in link.get("flags", [])}
    except (ValueError, KeyError, TypeError):
        pass
    return result


def network(counters, rates):
    addrs = addresses()
    out = []
    for name, (rx, tx) in sorted(counters.items()):
        if name == "lo" or CONTAINER_IFACE.match(name):
            continue
        info = addrs.get(name, {})
        physical = os.path.exists(f"/sys/class/net/{name}/device")
        if not info.get("addrs") and not physical and name.startswith(VIRTUAL_IFACE_PREFIXES):
            continue
        speed = read(f"/sys/class/net/{name}/speed", "").strip()
        rx_rate, tx_rate = rates.get(name, (None, None))
        out.append({
            "name": name,
            "addrs": info.get("addrs", []),
            "mac": info.get("mac"),
            "up": info.get("up"),
            "speed_mbps": int(speed) if speed.lstrip("-").isdigit() and int(speed) > 0 else None,
            "rx_bytes": rx, "tx_bytes": tx,
            "rx_rate": round(rx_rate) if rx_rate is not None else None,
            "tx_rate": round(tx_rate) if tx_rate is not None else None,
        })
    return out[:30]


# ------------------------------------------------------- boot tracking

class BootTracker:
    """Tells whether the previous boot ended cleanly.

    The agent records "stopped cleanly" when systemd stops it (as it does on
    every normal shutdown or reboot). If the machine comes back up and that
    record is missing, the previous boot ended in a crash, hang or power
    loss. Works without a persistent journal.
    """

    def __init__(self):
        self.boot_id = (read("/proc/sys/kernel/random/boot_id", "") or "").strip()
        prev = {}
        try:
            with open(STATE_FILE, encoding="utf-8") as f:
                prev = json.load(f)
        except (OSError, ValueError):
            pass
        if prev.get("boot_id") and prev["boot_id"] != self.boot_id:
            self.previous_shutdown_clean = bool(prev.get("clean_stop"))
        elif prev.get("boot_id") == self.boot_id:
            # Agent restarted within the same boot: keep what we found at boot.
            self.previous_shutdown_clean = prev.get("previous_shutdown_clean")
        else:
            self.previous_shutdown_clean = None  # first run after install
        self._save(clean_stop=False)

    def _save(self, clean_stop):
        try:
            os.makedirs(os.path.dirname(STATE_FILE), mode=0o700, exist_ok=True)
            tmp = STATE_FILE + ".tmp"
            with open(tmp, "w", encoding="utf-8") as f:
                json.dump({"boot_id": self.boot_id, "clean_stop": clean_stop,
                           "previous_shutdown_clean": self.previous_shutdown_clean}, f)
                f.flush()
                os.fsync(f.fileno())
            os.replace(tmp, STATE_FILE)
        except OSError as e:
            log.warning("cannot write %s: %s", STATE_FILE, e)

    def mark_clean_stop(self):
        self._save(clean_stop=True)


BOOT = None  # set in run_forever()


# -------------------------------------------------------------- inventory

def os_release():
    info = {}
    for line in read("/etc/os-release", "").splitlines():
        key, _, val = line.partition("=")
        if key:
            info[key.lower()] = val.strip().strip('"')
    return {k: info.get(k) for k in ("pretty_name", "id", "id_like", "version_id", "version_codename")}


def hardware():
    dmi = lambda name: (read(f"/sys/class/dmi/id/{name}", "") or "").strip() or None
    model = None
    for line in read("/proc/cpuinfo", "").splitlines():
        if line.lower().startswith(("model name", "hardware", "cpu model")):
            model = line.split(":", 1)[1].strip()
            break
    code, virt = run(["systemd-detect-virt"], timeout=10)
    return {
        "vendor": dmi("sys_vendor"),
        "model": dmi("product_name"),
        "serial": dmi("product_serial"),
        "bios": dmi("bios_version"),
        "cpu_model": model,
        "cpu_count": os.cpu_count(),
        "memory_total": memory()["total"],
        "virtualization": virt.strip() if code == 0 else "none",
    }


def package_manager():
    if shutil.which("apt-get") and shutil.which("dpkg-query"):
        return "apt"
    if shutil.which("dnf"):
        return "dnf"
    if shutil.which("yum"):
        return "yum"
    return None


def packages(pm):
    if pm == "apt":
        _, out = run(["dpkg-query", "-W", "-f", "${db:Status-Abbrev}\t${Package}\t${Version}\n"])
        pkgs = []
        for line in out.splitlines():
            status, _, rest = line.partition("\t")
            name, _, ver = rest.partition("\t")
            if status.startswith("ii"):
                pkgs.append([name, ver])
        return pkgs
    if pm in ("dnf", "yum"):
        _, out = run(["rpm", "-qa", "--qf", "%{NAME}\t%{EPOCHNUM}:%{VERSION}-%{RELEASE}.%{ARCH}\n"])
        return [line.split("\t", 1) for line in out.splitlines() if "\t" in line]
    return []


APT_INST = re.compile(r"^Inst (\S+) (?:\[(\S+)\] )?\((\S+) (.*?)\)")


def updates(pm):
    """Pending updates from the package manager's existing cache.

    Doesn't refresh package lists itself (apt-daily / dnf-makecache timers
    do that), so this stays fast and doesn't hit mirrors every hour.
    """
    pkgs = []
    if pm == "apt":
        code, out = run(["apt-get", "-s", "-o", "Debug::NoLocking=1", "dist-upgrade"])
        if code != 0:
            return {"manager": pm, "error": "apt-get simulation failed", "packages": None}
        for line in out.splitlines():
            m = APT_INST.match(line)
            if m:
                name, current, new, origin = m.groups()
                pkgs.append({"name": name, "current": current, "new": new,
                             "security": "security" in origin.lower()})
    elif pm in ("dnf", "yum"):
        code, out = run([pm, "-q", "-C", "check-update"])
        if code not in (0, 100):
            return {"manager": pm, "error": f"{pm} check-update failed", "packages": None}
        security = set()
        _, sec_out = run([pm, "-q", "-C", "updateinfo", "list", "--security"])
        for line in sec_out.splitlines():
            parts = line.split()
            if len(parts) >= 3:
                # name-version-release.arch -> name
                security.add(parts[-1].rsplit("-", 2)[0])
        for line in out.splitlines():
            if line.startswith("Obsoleting"):
                break  # what follows are replacements, not upgrades
            parts = line.split()
            if len(parts) == 3 and not line.startswith(" "):
                name = parts[0].rsplit(".", 1)[0]
                pkgs.append({"name": name, "current": None, "new": parts[1],
                             "security": name in security})
    else:
        return {"manager": None, "packages": None}
    return {"manager": pm, "packages": pkgs}


def newer_kernel_installed():
    """True if a kernel newer than the running one is in /boot.

    Catches cases the distro flag misses (Proxmox doesn't create
    /var/run/reboot-required after kernel updates).
    """
    running = f"/boot/vmlinuz-{platform.release()}"
    if not os.path.exists(running):
        return None
    try:
        kernels = [os.path.join("/boot", f) for f in os.listdir("/boot") if f.startswith("vmlinuz-")]
        newest = max(kernels, key=os.path.getmtime)
        return os.path.getmtime(newest) > os.path.getmtime(running) + 60
    except (OSError, ValueError):
        return None


def reboot_required(pm):
    if os.path.exists("/var/run/reboot-required"):
        return True
    if pm in ("dnf", "yum") and shutil.which("needs-restarting"):
        code, _ = run(["needs-restarting", "-r"], timeout=60)
        if code in (0, 1):
            return code == 1
    return newer_kernel_installed()


# Services the server asked us to watch (from its report replies); their state
# goes out with every metrics report, and only these can be restarted by a job.
WATCHED = []
SERVICE_NAME = re.compile(r"^[A-Za-z0-9@._:\-+]{1,120}$")


def list_services():
    """Every loaded systemd service, for the dashboard's "watch a service" picker."""
    code, out = run(["systemctl", "list-units", "--type=service", "--all", "--no-legend", "--plain", "--no-pager"], timeout=20)
    if code is None:
        return None
    result = []
    for line in out.splitlines():
        parts = line.split(None, 4)
        if len(parts) >= 4 and parts[0].endswith(".service") and parts[1] != "not-found":
            result.append({"name": parts[0], "state": parts[2], "sub": parts[3],
                           "display": parts[4] if len(parts) > 4 else None})
    return result


def watched_services(names):
    """State of each watched unit: active state, sub-state, since (epoch s), restart count, PID."""
    names = [n for n in names if SERVICE_NAME.match(n)]
    if not names or not shutil.which("systemctl"):
        return []
    code, out = run(["systemctl", "show", "--no-pager", "--property=Id,LoadState,ActiveState,SubState,"
                     "Description,ActiveEnterTimestampMonotonic,InactiveEnterTimestampMonotonic,NRestarts,MainPID,UnitFileState"]
                    + names, timeout=20)
    if code is None:
        return []
    up = uptime_sec() or 0
    boot = time.time() - up
    result = []
    for name, block in zip(names, out.strip().split("\n\n")):
        p = dict(line.split("=", 1) for line in block.splitlines() if "=" in line)
        if p.get("LoadState") in (None, "not-found"):
            result.append({"name": name, "missing": True})
            continue
        active = p.get("ActiveState")
        mono = p.get("ActiveEnterTimestampMonotonic" if active == "active" else "InactiveEnterTimestampMonotonic", "0")
        since = int(boot + int(mono) / 1e6) if mono.isdigit() and int(mono) > 0 else None
        result.append({
            "name": name, "display": p.get("Description"), "state": active, "sub": p.get("SubState"),
            "since": since, "restarts": int(p["NRestarts"]) if p.get("NRestarts", "").isdigit() else None,
            "pid": int(p["MainPID"]) if p.get("MainPID", "0").isdigit() and p.get("MainPID") != "0" else None,
            "start_type": p.get("UnitFileState") or None,
        })
    return result


def failed_services():
    code, out = run(["systemctl", "list-units", "--type=service", "--state=failed",
                     "--no-legend", "--plain", "--no-pager"], timeout=20)
    if code is None:
        return None
    return [line.split()[0] for line in out.splitlines() if line.strip()]


# Antivirus products recognised on Linux: systemd units that mean it's
# installed (the first that exists) and running (any active).
LINUX_AV = [
    ("ClamAV", ["clamav-daemon.service", "clamd@scan.service", "clamd.service"]),
    ("Microsoft Defender for Endpoint", ["mdatp.service"]),
    ("CrowdStrike Falcon", ["falcon-sensor.service"]),
    ("Sophos Protection", ["sophos-spl.service"]),
    ("ESET Endpoint Antivirus", ["eea.service", "esets.service"]),
    ("SentinelOne", ["sentinelone.service"]),
]
CLAMAV_DB_DIRS = ["/var/lib/clamav"]


def unit_state(unit):
    """'active', 'inactive', 'failed', ... or None when the unit doesn't exist."""
    code, out = run(["systemctl", "show", unit, "--property=LoadState,ActiveState", "--no-pager"], timeout=10)
    if code is None:
        return None
    props = dict(line.split("=", 1) for line in out.splitlines() if "=" in line)
    if props.get("LoadState") in (None, "not-found"):
        return None
    return props.get("ActiveState")


def clamav_signature_age_days():
    newest = None
    for d in CLAMAV_DB_DIRS:
        for name in ("daily.cld", "daily.cvd"):
            try:
                m = os.stat(os.path.join(d, name)).st_mtime
            except OSError:
                continue
            newest = m if newest is None else max(newest, m)
    return None if newest is None else int((time.time() - newest) // 86400)


def antivirus():
    """Installed antivirus products: enabled = its service runs; up_to_date where known."""
    if not shutil.which("systemctl"):
        return {"products": [], "error": "systemctl not available"}
    products = []
    for name, units in LINUX_AV:
        states = [s for s in (unit_state(u) for u in units) if s is not None]
        if not states:
            continue
        p = {"name": name, "enabled": "active" in states, "up_to_date": None, "realtime": None,
             "signature_age_days": None, "last_scan": None, "source": "systemd"}
        if name == "ClamAV":
            age = clamav_signature_age_days()
            p["signature_age_days"] = age
            p["up_to_date"] = None if age is None else age <= 3
            # clamd only scans on demand unless on-access scanning (clamonacc) runs too.
            p["realtime"] = unit_state("clamav-clamonacc.service") == "active" or unit_state("clamonacc.service") == "active"
        elif name == "Microsoft Defender for Endpoint":
            code, out = run(["mdatp", "health", "--field", "real_time_protection_enabled"], timeout=20)
            if code == 0 and out.strip() in ("true", "false"):
                p["realtime"] = out.strip() == "true"
            code, out = run(["mdatp", "health", "--field", "definitions_status"], timeout=20)
            if code == 0 and out.strip():
                p["up_to_date"] = "up_to_date" in out.replace('"', "").replace("-", "_").lower()
        products.append(p)
    return {"products": products}


def collect_inventory():
    pm = package_manager()
    up = uptime_sec()
    return {
        "hostname": socket.gethostname(),
        "os": os_release(),
        "kernel": platform.release(),
        "arch": platform.machine(),
        "boot_time": int(time.time() - up) if up is not None else None,
        "hardware": hardware(),
        "packages": packages(pm),
        "updates": updates(pm),
        "reboot_required": reboot_required(pm),
        "failed_services": failed_services(),
        "services": list_services(),
        "antivirus": antivirus(),
        "last_boot": {"previous_shutdown_clean": BOOT.previous_shutdown_clean if BOOT else None},
        "agent": {"version": VERSION, "python": platform.python_version()},
    }


# ------------------------------------------------------------- transport

class Client:
    def __init__(self, server, token=None):
        self.server = server.rstrip("/")
        self.token = token
        self.ctx = ssl.create_default_context(cafile=CA_FILE)

    def post(self, action, payload, headers=None):
        body = json.dumps(payload, separators=(",", ":")).encode()
        hdrs = {"Content-Type": "application/json", "User-Agent": f"webmonitor-agent/{VERSION}"}
        if len(body) > 2048:
            body = gzip.compress(body)
            hdrs["Content-Encoding"] = "gzip"
        if self.token:
            hdrs["X-Agent-Token"] = self.token
        hdrs.update(headers or {})
        req = urllib.request.Request(f"{self.server}/api/agent.php?action={action}",
                                     data=body, headers=hdrs, method="POST")
        try:
            with urllib.request.urlopen(req, context=self.ctx, timeout=60) as resp:
                return resp.status, json.loads(resp.read() or b"{}")
        except urllib.error.HTTPError as e:
            try:
                detail = json.loads(e.read() or b"{}")
            except ValueError:
                detail = {}
            return e.code, detail

    def get(self, path):
        """GET a file from the server (same pinned TLS); returns bytes."""
        req = urllib.request.Request(f"{self.server}/{path.lstrip('/')}",
                                     headers={"User-Agent": f"webmonitor-agent/{VERSION}"})
        with urllib.request.urlopen(req, context=self.ctx, timeout=120) as resp:
            return resp.read()


def load_conf():
    with open(CONF_FILE, encoding="utf-8") as f:
        return json.load(f)


def save_conf(conf):
    os.makedirs(CONF_DIR, mode=0o700, exist_ok=True)
    tmp = CONF_FILE + ".tmp"
    fd = os.open(tmp, os.O_WRONLY | os.O_CREAT | os.O_TRUNC, 0o600)
    with os.fdopen(fd, "w", encoding="utf-8") as f:
        json.dump(conf, f)
    os.replace(tmp, CONF_FILE)


def enroll(server, enroll_token):
    client = Client(server)
    status, resp = client.post("enroll", {"hostname": socket.gethostname(), "version": VERSION},
                               headers={"X-Enroll-Token": enroll_token})
    if status != 200 or "agent_token" not in resp:
        print(f"Enrollment failed ({status}): {resp.get('error', 'unexpected response')}", file=sys.stderr)
        return 1
    save_conf({"server": server.rstrip("/"), "agent_token": resp["agent_token"], "host_id": resp.get("host_id")})
    print(f"Enrolled with {server} as host #{resp.get('host_id')}.")
    return 0


# ------------------------------------------------------------- workloads

MAC_RE = re.compile(r"([0-9A-Fa-f]{2}(?::[0-9A-Fa-f]{2}){5})")


def run_json(cmd, timeout=20):
    code, out = run(cmd, timeout=timeout)
    if code != 0 or not out.strip():
        return None
    try:
        return json.loads(out)
    except ValueError:
        return None


def arp_table():
    """{mac: ip} for IPv4 neighbours this host has seen."""
    table = {}
    for n in run_json(["ip", "-j", "neigh", "show"], timeout=10) or []:
        mac, ip = (n.get("lladdr") or "").lower(), n.get("dst") or ""
        if mac and "." in ip:
            table[mac] = ip
    return table


def proxmox_guests():
    """VMs and LXC containers on this Proxmox VE node, or None if not one."""
    if not shutil.which("pvesh"):
        return None
    node = socket.gethostname().split(".")[0]
    resources = run_json(["pvesh", "get", "/cluster/resources", "--type", "vm", "--output-format", "json"], timeout=30)
    if resources is None:
        return {"error": "pvesh /cluster/resources failed", "guests": []}
    arp = arp_table()
    guests = []
    for r in resources:
        if r.get("node") != node or r.get("template"):
            continue
        kind, vmid = r.get("type"), r.get("vmid")
        if kind not in ("qemu", "lxc") or vmid is None:
            continue
        base = f"/nodes/{node}/{kind}/{vmid}"
        config = run_json(["pvesh", "get", base + "/config", "--output-format", "json"], timeout=15) or {}
        macs, ips = [], []
        for key, val in config.items():
            if not re.fullmatch(r"net\d+", key) or not isinstance(val, str):
                continue
            m = MAC_RE.search(val)
            if m:
                macs.append(m.group(1).lower())
            ipm = re.search(r"(?:^|,)ip=([0-9.]+)/", val)   # LXC static address
            if ipm:
                ips.append(ipm.group(1))
        if r.get("status") == "running":
            if kind == "qemu" and str(config.get("agent", "0")).split(",")[0] in ("1", "enabled=1"):
                data = run_json(["pvesh", "get", base + "/agent/network-get-interfaces", "--output-format", "json"], timeout=8)
                for iface in (data or {}).get("result", []) if isinstance(data, dict) else []:
                    for a in iface.get("ip-addresses", []):
                        if a.get("ip-address-type") == "ipv4" and not a.get("ip-address", "").startswith("127."):
                            ips.append(a["ip-address"])
            elif kind == "lxc":
                for iface in run_json(["pvesh", "get", base + "/interfaces", "--output-format", "json"], timeout=8) or []:
                    inet = (iface.get("inet") or "").split("/")[0]
                    if inet and not inet.startswith("127."):
                        ips.append(inet)
        ips += [arp[m] for m in macs if m in arp]
        guests.append({
            "vmid": vmid, "type": kind, "name": r.get("name") or config.get("hostname") or f"{kind}-{vmid}",
            "status": r.get("status"), "macs": sorted(set(macs)), "ips": sorted(set(ips)),
            "cpus": r.get("maxcpu"), "memory": r.get("maxmem"),
        })
    code, ver = run(["pveversion"], timeout=10)
    return {"node": node, "version": ver.strip().split("/")[1] if code == 0 and "/" in ver else None,
            "guests": sorted(guests, key=lambda g: g["vmid"])}


def parse_labels(raw):
    if isinstance(raw, dict):
        return raw
    out = {}
    for part in (raw or "").split(","):
        k, _, v = part.partition("=")
        if k:
            out[k.strip()] = v.strip()
    return out


def containers():
    """Docker (or Podman) containers on this host, or None if neither runs here."""
    for tool in ("docker", "podman"):
        if not shutil.which(tool):
            continue
        code, out = run([tool, "ps", "-a", "--no-trunc", "--format", "{{json .}}"], timeout=20)
        if code != 0:
            continue   # e.g. daemon not running; try the other tool
        items = []
        for line in out.splitlines():
            try:
                c = json.loads(line)
            except ValueError:
                continue
            names = c.get("Names")
            name = (names[0] if isinstance(names, list) and names else names or "").lstrip("/")
            labels = parse_labels(c.get("Labels"))
            status = c.get("Status") or ""
            health = "healthy" if "(healthy)" in status else "unhealthy" if "(unhealthy)" in status \
                else "starting" if "health: starting" in status else None
            ports = c.get("Ports")
            if isinstance(ports, list):   # podman
                ports = ", ".join(f"{p.get('host_port')}->{p.get('container_port')}/{p.get('protocol', 'tcp')}"
                                  for p in ports if p.get("host_port"))
            items.append({
                "id": (c.get("ID") or c.get("Id") or "")[:12],
                "name": name,
                "image": c.get("Image"),
                "state": (c.get("State") or "").lower(),
                "status": status,
                "health": health,
                "ports": ports or "",
                "project": labels.get("com.docker.compose.project") or labels.get("io.podman.compose.project"),
            })
        if items or tool == "docker":
            return {"engine": tool, "containers": sorted(items, key=lambda x: (x["project"] or "~", x["name"]))}
    return None


def collect_workloads():
    return {"proxmox": proxmox_guests(), "containers": containers()}


# ------------------------------------------------------------------ jobs

JOB_POLL_SEC = 20
JOB_FLUSH_SEC = 10
JOB_MAX_SEC = 3 * 3600
# Non-interactive package installs that keep existing config files and wait
# for another apt (e.g. unattended-upgrades) instead of failing.
APT_ENV = {"DEBIAN_FRONTEND": "noninteractive", "NEEDRESTART_MODE": "a", "APT_LISTCHANGES_FRONTEND": "none"}
APT_OPTS = ["-o", "Dpkg::Options::=--force-confdef", "-o", "Dpkg::Options::=--force-confold",
            "-o", "DPkg::Lock::Timeout=600"]


class JobFailed(Exception):
    pass


class JobRunner(threading.Thread):
    """Runs one job in the background, streaming output to the server."""

    def __init__(self, client, job):
        super().__init__(daemon=True)
        self.client = client
        self.job = job
        self.buf = []
        self.lock = threading.Lock()
        self.done = threading.Event()
        self.reboot = False
        self.restart_agent = False

    def emit(self, text):
        with self.lock:
            self.buf.append(text if text.endswith("\n") else text + "\n")

    def send(self, status, **extra):
        with self.lock:
            chunk, self.buf = "".join(self.buf), []
        payload = {"id": self.job["id"], "status": status, "output": chunk, **extra}
        try:
            code, _ = self.client.post("job_update", payload)
        except (OSError, ssl.SSLError, ValueError) as e:
            code = None
            log.warning("job %s: update failed: %s", self.job["id"], e)
        if code not in (200, 409):
            with self.lock:  # keep the output for the next attempt
                self.buf.insert(0, chunk)
        return code

    def run_cmd(self, cmd, env=None):
        """Runs a command, streaming its output; returns the exit code."""
        self.emit("$ " + " ".join(cmd))
        full_env = dict(os.environ, LC_ALL="C", LANG="C", **(env or {}))
        try:
            p = subprocess.Popen(cmd, stdout=subprocess.PIPE, stderr=subprocess.STDOUT,
                                 stdin=subprocess.DEVNULL, env=full_env, text=True, errors="replace")
        except OSError as e:
            raise JobFailed(f"cannot run {cmd[0]}: {e}")
        deadline = time.monotonic() + JOB_MAX_SEC
        for line in p.stdout:
            self.emit(line.rstrip("\n"))
            if time.monotonic() > deadline:
                p.kill()
                raise JobFailed(f"{cmd[0]} took longer than {JOB_MAX_SEC // 3600} hours")
        return p.wait()

    def run(self):
        flusher = threading.Thread(target=self._flush_loop, daemon=True)
        flusher.start()
        status, exit_code, summary = "succeeded", 0, None
        try:
            handler = JOB_HANDLERS.get(self.job["type"])
            if handler is None:
                raise JobFailed(f"this agent ({VERSION}) doesn't know job type {self.job['type']!r}")
            summary = handler(self, self.job.get("params") or {})
        except JobFailed as e:
            status, exit_code, summary = "failed", 1, str(e)
            self.emit("ERROR: " + str(e))
        except Exception as e:  # report anything unexpected rather than dying silently
            status, exit_code, summary = "failed", 1, f"agent error: {e}"
            self.emit("ERROR: " + repr(e))
        self.done.set()
        for attempt in range(6):  # make sure the final status gets through
            if self.send(status, exit_code=exit_code, summary=summary, rebooting=self.reboot) in (200, 409):
                break
            time.sleep(10 * (attempt + 1))
        if self.reboot:
            log.info("job %s: rebooting in 1 minute", self.job["id"])
            subprocess.run(["shutdown", "-r", "+1", "WebMonitor: rebooting (job %s)" % self.job["id"]], check=False)

    def _flush_loop(self):
        while not self.done.wait(JOB_FLUSH_SEC):
            self.send("running")


def job_refresh(job, params):
    pm = package_manager()
    if pm == "apt":
        code = job.run_cmd(["apt-get", "update"] + APT_OPTS, APT_ENV)
    elif pm in ("dnf", "yum"):
        code = job.run_cmd([pm, "-y", "makecache", "--refresh"])
    else:
        raise JobFailed("no supported package manager (apt or dnf) found")
    if code != 0:
        raise JobFailed(f"refreshing package lists failed (exit {code})")
    pkgs = updates(pm).get("packages") or []
    sec = sum(1 for p in pkgs if p["security"])
    return f"{len(pkgs)} updates available ({sec} security)"


def job_updates(job, params):
    pm = package_manager()
    security_only = bool(params.get("security_only"))
    if pm == "apt":
        if job.run_cmd(["apt-get", "update"] + APT_OPTS, APT_ENV) != 0:
            raise JobFailed("apt-get update failed")
        if security_only:
            # Debian/Ubuntu have no "security only" switch; upgrade exactly
            # the packages that come from the security archive.
            names = sorted({p["name"] for p in (updates(pm).get("packages") or []) if p["security"]})
            if not names:
                job.emit("No security updates to install.")
                return finish_updates(job, pm, params, "no security updates were pending")
            code = job.run_cmd(["apt-get", "-y", "install", "--only-upgrade"] + APT_OPTS + names, APT_ENV)
        else:
            code = job.run_cmd(["apt-get", "-y", "dist-upgrade"] + APT_OPTS, APT_ENV)
    elif pm in ("dnf", "yum"):
        cmd = [pm, "-y", "upgrade", "--refresh"] + (["--security"] if security_only else [])
        code = job.run_cmd(cmd)
    else:
        raise JobFailed("no supported package manager (apt or dnf) found")
    if code != 0:
        raise JobFailed(f"the package manager exited with code {code}")
    return finish_updates(job, pm, params, "updates installed")


def finish_updates(job, pm, params, what):
    left = updates(pm).get("packages") or []
    needs_reboot = bool(reboot_required(pm))
    msg = f"{what}; {len(left)} still pending; reboot {'required' if needs_reboot else 'not needed'}"
    if needs_reboot and params.get("reboot"):
        job.reboot = True
        msg += " — rebooting now"
    return msg


def job_reboot(job, params):
    job.emit("Rebooting in 1 minute.")
    job.reboot = True
    return "rebooting"


def job_agent_upgrade(job, params):
    me = os.path.realpath(__file__)
    try:
        code = job.client.get("agent/webmonitor-agent.py")
    except (OSError, ssl.SSLError) as e:
        raise JobFailed(f"download failed: {e}")
    m = re.search(rb'^VERSION = "([^"]+)"', code, re.M)
    if not m:
        raise JobFailed("downloaded file doesn't look like the agent")
    new_version = m.group(1).decode()
    fd, tmp = tempfile.mkstemp(dir=os.path.dirname(me), suffix=".py")
    with os.fdopen(fd, "wb") as f:
        f.write(code)
    try:
        compile(code, tmp, "exec")  # refuse to install something that won't run
    except SyntaxError as e:
        os.unlink(tmp)
        raise JobFailed(f"downloaded agent has a syntax error: {e}")
    os.chmod(tmp, 0o755)
    os.replace(tmp, me)
    job.emit(f"Installed agent {new_version} (was {VERSION}); restarting.")
    job.restart_agent = True
    return f"upgraded {VERSION} -> {new_version}"


def job_service_restart(job, params):
    name = str(params.get("service") or "")
    if not SERVICE_NAME.match(name):
        raise JobFailed(f"not a valid service name: {name!r}")
    if name not in WATCHED:
        raise JobFailed(f"{name} is not on this host's watched list; only watched services can be restarted")
    if job.run_cmd(["systemctl", "restart", name]) != 0:
        job.run_cmd(["systemctl", "status", "--no-pager", "--lines=20", name])
        raise JobFailed(f"systemctl restart {name} failed")
    time.sleep(3)
    state = (watched_services([name]) or [{}])[0]
    job.emit(f"{name}: {state.get('state')} ({state.get('sub')})")
    if state.get("state") != "active":
        raise JobFailed(f"{name} restarted but is {state.get('state')} ({state.get('sub')})")
    return f"restarted {name}; it is running"


JOB_HANDLERS = {
    "service_restart": job_service_restart,
    "refresh": job_refresh,
    "updates": job_updates,
    "reboot": job_reboot,
    "agent_upgrade": job_agent_upgrade,
}


def run_forever():
    global BOOT
    BOOT = BootTracker()

    def stop(signum, _frame):
        BOOT.mark_clean_stop()
        log.info("Stopping (signal %s)", signum)
        sys.exit(0)
    signal.signal(signal.SIGTERM, stop)
    signal.signal(signal.SIGINT, stop)

    conf = load_conf()
    client = Client(conf["server"], conf["agent_token"])
    intervals = dict(DEFAULT_INTERVALS)
    collector = MetricsCollector()
    next_due = {"inventory": 0.0, "metrics": 0.0, "workloads": 10.0, "jobs": 5.0}
    failures = 0
    job = None
    log.info("WebMonitor agent %s reporting to %s", VERSION, conf["server"])

    while True:
        now = time.monotonic()
        if job is not None and not job.is_alive():
            if job.restart_agent:
                log.info("Restarting into the upgraded agent")
                os.execv(sys.executable, [sys.executable, os.path.realpath(__file__)])
            job = None
            next_due["inventory"] = 0.0  # report the new state right away
            next_due["metrics"] = 0.0
        if job is None and now >= next_due["jobs"]:
            next_due["jobs"] = now + JOB_POLL_SEC
            try:
                status, resp = client.post("jobs", {"version": VERSION})
                if status == 200 and resp.get("job"):
                    log.info("Starting job %s (%s)", resp["job"]["id"], resp["job"]["type"])
                    job = JobRunner(client, resp["job"])
                    job.start()
            except (OSError, ssl.SSLError, ValueError) as e:
                log.warning("job poll failed: %s", e)

        for kind in ("inventory", "workloads", "metrics"):
            if now < next_due[kind]:
                continue
            try:
                data = (collect_inventory() if kind == "inventory"
                        else collect_workloads() if kind == "workloads" else collector.collect())
                if kind == "metrics" and WATCHED:
                    data["services"] = watched_services(WATCHED)
                status, resp = client.post("report", {"kind": kind, "version": VERSION, "data": data})
            except (OSError, ssl.SSLError, ValueError) as e:
                status, resp = None, {"error": str(e)}

            if status == 200:
                failures = 0
                intervals.update({k: int(v) for k, v in resp.get("intervals", {}).items()
                                  if k in intervals and isinstance(v, int) and v >= 10})
                watch = resp.get("watch_services")
                if isinstance(watch, list):
                    new = [n for n in watch if isinstance(n, str) and SERVICE_NAME.match(n)][:200]
                    if new != WATCHED:
                        WATCHED[:] = new
                        next_due["metrics"] = 0.0  # report the newly watched services right away
                next_due[kind] = time.monotonic() + intervals[kind]
            elif status == 401:
                # Removed or re-installed from the dashboard; don't hammer.
                log.error("Server rejected this agent's token: %s", resp.get("error"))
                next_due[kind] = time.monotonic() + 600
            else:
                failures += 1
                retry = min(300, 15 * 2 ** min(failures, 5))
                log.warning("%s report failed (%s): %s — retrying in %ss",
                            kind, status, resp.get("error"), retry)
                next_due[kind] = time.monotonic() + retry
        due = [t for k, t in next_due.items() if k != "jobs" or job is None]
        time.sleep(max(1.0, min(min(due) - time.monotonic(), 5.0 if job else 3600)))


def main():
    logging.basicConfig(level=logging.INFO, format="%(levelname)s %(message)s")
    p = argparse.ArgumentParser(description="WebMonitor agent")
    p.add_argument("--enroll", metavar="TOKEN", help="one-time enrollment token")
    p.add_argument("--server", help="WebMonitor URL, e.g. https://192.168.1.10/webmonitor")
    p.add_argument("--print", choices=["metrics", "inventory", "workloads"], help="print a report and exit")
    p.add_argument("--version", action="version", version=VERSION)
    args = p.parse_args()

    if args.print:
        data = (collect_inventory() if args.print == "inventory"
                else collect_workloads() if args.print == "workloads" else MetricsCollector().collect())
        json.dump(data, sys.stdout, indent=2)
        print()
        return 0
    if args.enroll:
        if not args.server:
            p.error("--enroll needs --server")
        return enroll(args.server, args.enroll)
    run_forever()
    return 0


if __name__ == "__main__":
    sys.exit(main())
