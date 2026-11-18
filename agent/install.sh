#!/bin/bash
# Installs (or removes) the WebMonitor agent on a Linux host.
#
# Normally run via the command shown in the dashboard (Details -> Agent ->
# Install agent), which looks like:
#   curl -fsSk --pinnedpubkey 'sha256//...' https://SERVER/webmonitor/agent/install.sh \
#     | sudo bash -s -- --server https://SERVER/webmonitor --token TOKEN --pin 'sha256//...' [--remove-zabbix]
#
# curl's -k is safe here only because --pinnedpubkey makes curl refuse any
# server whose key doesn't match this WebMonitor server's certificate.
#
# Uninstall:
#   sudo bash install.sh --uninstall
set -euo pipefail

# Everything is inside main() so bash has read the whole script before any
# of it runs; with `curl | bash`, a command reading stdin could otherwise
# swallow the rest of the script.
main() {

INSTALL_DIR=/usr/local/lib/webmonitor-agent
CONF_DIR=/etc/webmonitor-agent
UNIT=/etc/systemd/system/webmonitor-agent.service

SERVER="" TOKEN="" PIN="" REMOVE_ZABBIX=0 REMOVE_CHECKMK=0 UNINSTALL=0
while [ $# -gt 0 ]; do
    case "$1" in
        --server) SERVER="${2%/}"; shift 2 ;;
        --token) TOKEN="$2"; shift 2 ;;
        --pin) PIN="$2"; shift 2 ;;
        --remove-zabbix) REMOVE_ZABBIX=1; shift ;;
        --remove-checkmk) REMOVE_CHECKMK=1; shift ;;
        --uninstall) UNINSTALL=1; shift ;;
        *) echo "Unknown option: $1" >&2; exit 1 ;;
    esac
done

if [ "$(id -u)" -ne 0 ]; then
    echo "Run as root (sudo)." >&2
    exit 1
fi
if ! command -v systemctl >/dev/null 2>&1; then
    echo "systemd is required." >&2
    exit 1
fi

if [ "$UNINSTALL" = 1 ]; then
    systemctl disable --now webmonitor-agent 2>/dev/null || true
    rm -f "$UNIT"
    systemctl daemon-reload
    rm -rf "$INSTALL_DIR" "$CONF_DIR"
    echo "WebMonitor agent removed. (Remove the host's agent in the dashboard too.)"
    exit 0
fi

if [ -z "$SERVER" ] || [ -z "$TOKEN" ] || [ -z "$PIN" ]; then
    echo "Usage: install.sh --server URL --token TOKEN --pin 'sha256//...' [--remove-zabbix] [--remove-checkmk]" >&2
    exit 1
fi

fetch() {  # fetch URL DEST — only from the pinned server
    curl -fsSk --pinnedpubkey "$PIN" --retry 2 -o "$2" "$1"
}

echo "==> Checking for Python 3"
if ! command -v python3 >/dev/null 2>&1; then
    if command -v apt-get >/dev/null 2>&1; then
        apt-get update -qq && DEBIAN_FRONTEND=noninteractive apt-get install -y -qq python3 >/dev/null
    elif command -v dnf >/dev/null 2>&1; then
        dnf install -y -q python3 >/dev/null
    else
        echo "Python 3 is required and no supported package manager was found." >&2
        exit 1
    fi
fi
python3 --version

echo "==> Downloading the agent from $SERVER"
mkdir -p "$INSTALL_DIR"
install -d -m 700 "$CONF_DIR"
fetch "$SERVER/agent/webmonitor-agent.py" "$INSTALL_DIR/webmonitor-agent.py.new"
python3 -m py_compile "$INSTALL_DIR/webmonitor-agent.py.new"
mv "$INSTALL_DIR/webmonitor-agent.py.new" "$INSTALL_DIR/webmonitor-agent.py"
chmod 755 "$INSTALL_DIR/webmonitor-agent.py"
rm -rf "$INSTALL_DIR/__pycache__"
# The agent trusts exactly this certificate for all later connections.
fetch "$SERVER/api/agent.php?action=cert" "$CONF_DIR/server.crt"
chmod 644 "$CONF_DIR/server.crt"

echo "==> Enrolling with the server"
python3 "$INSTALL_DIR/webmonitor-agent.py" --enroll "$TOKEN" --server "$SERVER"

# remove_monitoring_agent LABEL CONFIG_NOTE "UNITS" "DEB_PACKAGES" "RPM_PACKAGES"
# Stops/disables the units and removes whichever packages are installed.
# Packages are removed, not purged, so their config stays on disk.
remove_monitoring_agent() {
    local label="$1" note="$2" units="$3" debs="$4" rpms="$5" found=0 pkgs="" u p
    echo "==> Removing the $label agent"
    for u in $units; do
        case "$u" in *.*) ;; *) u="$u.service" ;; esac
        if systemctl list-unit-files "$u" 2>/dev/null | grep -q "^$u"; then
            systemctl disable --now "$u" >/dev/null 2>&1 || true
            echo "    stopped and disabled $u"
            found=1
        fi
    done
    if command -v dpkg-query >/dev/null 2>&1; then
        for p in $debs; do
            dpkg-query -W -f '${db:Status-Abbrev}' "$p" 2>/dev/null | grep -q '^ii' && pkgs="$pkgs $p"
        done
        [ -n "$pkgs" ] && DEBIAN_FRONTEND=noninteractive apt-get remove -y -qq $pkgs >/dev/null
    elif command -v rpm >/dev/null 2>&1; then
        for p in $rpms; do
            rpm -q "$p" >/dev/null 2>&1 && pkgs="$pkgs $p"
        done
        [ -n "$pkgs" ] && dnf remove -y -q $pkgs >/dev/null
    fi
    if [ -n "$pkgs" ]; then
        echo "    removed packages:$pkgs ($note)"
    elif [ "$found" = 0 ]; then
        echo "    no $label agent found"
    fi
    return 0
}

# Remove other agents before ours starts, so its first inventory is accurate.
if [ "$REMOVE_ZABBIX" = 1 ]; then
    remove_monitoring_agent Zabbix "config in /etc/zabbix kept" \
        "zabbix-agent zabbix-agent2" \
        "zabbix-agent zabbix-agent2 zabbix-agent2-plugin-mongodb zabbix-agent2-plugin-postgresql zabbix-agent2-plugin-mssql" \
        "zabbix-agent zabbix-agent2"
fi
if [ "$REMOVE_CHECKMK" = 1 ]; then
    remove_monitoring_agent Checkmk "config in /etc/check_mk kept" \
        "check-mk-agent.socket check-mk-agent-async cmk-agent-ctl-daemon" \
        "check-mk-agent" "check-mk-agent"
fi

echo "==> Installing the systemd service"
cat > "$UNIT" <<EOF
[Unit]
Description=WebMonitor agent
Documentation=$SERVER
After=network-online.target
Wants=network-online.target

[Service]
ExecStart=/usr/bin/env python3 $INSTALL_DIR/webmonitor-agent.py
Restart=always
RestartSec=30
# Runs as root: it reads hardware info and package state and installs
# updates from the dashboard (package scripts may write anywhere, so no
# filesystem sandboxing beyond a private /tmp).
PrivateTmp=true
Nice=10

[Install]
WantedBy=multi-user.target
EOF
systemctl daemon-reload
systemctl enable webmonitor-agent >/dev/null 2>&1
systemctl restart webmonitor-agent

sleep 3
if systemctl is-active --quiet webmonitor-agent; then
    echo "==> Done. The agent is running; data appears in the dashboard within a minute."
    echo "    Logs: journalctl -u webmonitor-agent -f"
else
    echo "!! The agent service is not running:" >&2
    journalctl -u webmonitor-agent -n 20 --no-pager >&2 || true
    exit 1
fi
}

main "$@"
