#!/bin/bash
# Lets WebMonitor read server details (CPU, memory, disk, network) over SNMP
# from a Linux host running net-snmp (Debian, Ubuntu, Proxmox, Rocky, ...).
#
# Run as root on the Linux host being monitored:
#   sudo bash enable-snmp-details.sh
#
# It adds a read-only SNMP v2c community, usable only from the WebMonitor
# server, with a view covering what the Details panel shows. The community
# string is prompted for (or taken from $COMMUNITY) so it never ends up in
# this file or your shell history. Existing snmpd.conf lines are left alone;
# the managed block is replaced on re-runs. snmpd.conf is backed up first
# and restored automatically if snmpd fails to restart.
#
# Options (environment variables):
#   COMMUNITY  community string (prompted if unset)
#   SOURCE     who may query it: the WebMonitor server's IP address (prompted if unset)
set -euo pipefail

CONF=/etc/snmp/snmpd.conf
SOURCE="${SOURCE:-}"

if [ "$(id -u)" -ne 0 ]; then
    echo "Run this as root (sudo bash $0)." >&2
    exit 1
fi
if [ ! -f "$CONF" ]; then
    echo "$CONF not found — is snmpd installed? (apt install snmpd / dnf install net-snmp)" >&2
    exit 1
fi

if [ -z "$SOURCE" ]; then
    read -rp "WebMonitor server IP address (only it may query SNMP): " SOURCE
fi
if [ -z "$SOURCE" ] || [[ "$SOURCE" =~ [[:space:]] ]]; then
    echo "A WebMonitor server address is required (or set SOURCE=...)." >&2
    exit 1
fi
if [ -z "${COMMUNITY:-}" ]; then
    read -rsp "SNMP community string for WebMonitor: " COMMUNITY
    echo
fi
if [ -z "$COMMUNITY" ] || [[ "$COMMUNITY" =~ [[:space:]] ]]; then
    echo "Community string must be non-empty with no spaces." >&2
    exit 1
fi

BACKUP="$CONF.bak-$(date +%Y%m%d-%H%M%S)"
cp -a "$CONF" "$BACKUP"
echo "==> Backed up $CONF to $BACKUP"

# Drop any block from a previous run, then append a fresh one.
sed -i '/^# BEGIN webmonitor/,/^# END webmonitor/d' "$CONF"
cat >> "$CONF" <<EOF
# BEGIN webmonitor — managed by WebMonitor's enable-snmp-details.sh
view   webmonitor  included  .1.3.6.1.2.1.1          # system
view   webmonitor  included  .1.3.6.1.2.1.2          # interfaces
view   webmonitor  included  .1.3.6.1.2.1.4          # IP addresses
view   webmonitor  included  .1.3.6.1.2.1.25         # host resources (CPU, memory, disk)
view   webmonitor  included  .1.3.6.1.2.1.31         # interface names/speeds
view   webmonitor  included  .1.3.6.1.4.1.2021       # UCD-SNMP (load average)
rocommunity $COMMUNITY $SOURCE -V webmonitor
# END webmonitor
EOF
echo "==> Added read-only community for $SOURCE"

if systemctl restart snmpd && sleep 1 && systemctl is-active --quiet snmpd; then
    echo "==> snmpd restarted OK. WebMonitor can now read this host's details."
else
    echo "!! snmpd failed to start — restoring $BACKUP" >&2
    cp -a "$BACKUP" "$CONF"
    systemctl restart snmpd || true
    journalctl -u snmpd -n 15 --no-pager >&2 || true
    exit 1
fi
