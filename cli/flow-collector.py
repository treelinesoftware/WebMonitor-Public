#!/usr/bin/python3 -I
"""WebMonitor flow collector: receives NetFlow v5/v9 and IPFIX from switches
and routers (e.g. Cisco Flexible NetFlow), adds traffic up per minute and
writes one JSON summary per minute for cli/cron.php to load (includes/flows.php).

Per minute it keeps:
  totals    bytes down (internet -> local), up (local -> internet), internal
  hosts     per local IP: bytes/packets in and out
  conv      top conversations: local IP, remote IP, protocol, service port, bytes in/out
  services  bytes per protocol/service port
  ifaces    per exporter and interface (the flow's input/output ifIndex): bytes, packets, flows
  ifconv    top conversations per exporter and input interface

When several switches export, one flow can be reported by each switch it crosses
(an access switch and the core). ifaces/ifconv keep every switch's view, but
totals, hosts, conv and services count each flow once: the first switch to report
a flow (by its 5-tuple) owns it, and the others' records of it are skipped until
the owner stops reporting it for OWNER_IDLE seconds.

Python standard library only. Runs as the webmonitor-flow systemd service.
Settings (environment): WM_FLOW_PORT (2055), WM_FLOW_DIR, WM_FLOW_EXPORTERS
(comma-separated allow-list; empty = any), WM_LOCAL_NETS.
"""
import ipaddress
import json
import os
import socket
import struct
import sys
import time

PORT = int(os.environ.get('WM_FLOW_PORT', '2055'))
OUT_DIR = os.environ.get('WM_FLOW_DIR', '/var/lib/webmonitor/flows')
ALLOW = {a.strip() for a in os.environ.get('WM_FLOW_EXPORTERS', '').split(',') if a.strip()}
LOCAL_NETS = [ipaddress.ip_network(n.strip()) for n in
              os.environ.get('WM_LOCAL_NETS', '10.0.0.0/8,172.16.0.0/12,192.168.0.0/16,fc00::/7').split(',') if n.strip()]
# Never leaves the network: broadcast, multicast (mDNS, SSDP), link-local. Counted as local, not internet.
ALWAYS_LOCAL = [ipaddress.ip_network(n) for n in ('224.0.0.0/4', '255.255.255.255/32', '169.254.0.0/16', 'ff00::/8', 'fe80::/10')]
TOP_CONVERSATIONS = 400
TOP_SERVICES = 150
TOP_IFCONV = 800
OWNER_IDLE = 180    # seconds without a record before another exporter may take over a flow

# NetFlow v9 / IPFIX information elements we use.
F_BYTES, F_PKTS, F_PROTO, F_SPORT, F_SRC4, F_INIF, F_DPORT, F_DST4, F_SRC6, F_DST6 = 1, 2, 4, 7, 8, 10, 11, 12, 27, 28
F_OUT_BYTES, F_OUT_PKTS, F_OUTIF = 23, 24, 14

# Server ports that win over the other end's port when picking a flow's service. Needed for
# protocols whose clients use reserved source ports below 1024 (NFS clients use 600-1023, which
# "the lower port is the service" would otherwise report instead of 2049).
SERVICE_PORTS = {20, 21, 22, 23, 25, 53, 67, 68, 80, 110, 111, 123, 137, 139, 143, 161, 389, 443, 445, 465, 514, 587, 636,
                 853, 873, 993, 995, 1194, 1883, 1900, 2049, 3074, 3306, 3389, 3478, 5353, 5432, 8006, 8080, 8443, 9100,
                 32400, 51820}

templates = {}     # (exporter, domain, template id) -> [(field type, length)]
owners = {}        # (src, dst, proto, sport, dport) -> [exporter, last seen]
local_cache = {}


def owns(exporter, key, now):
    """True if this exporter's record of the flow should count (see the module docstring)."""
    o = owners.get(key)
    if o is None or o[0] == exporter or now - o[1] > OWNER_IDLE:
        owners[key] = [exporter, now]
        return True
    return False


def prune_owners(now):
    for k in [k for k, o in owners.items() if now - o[1] > OWNER_IDLE]:
        del owners[k]
    status['flowKeys'] = len(owners)


def is_local(ip):
    hit = local_cache.get(ip)
    if hit is None:
        try:
            addr = ipaddress.ip_address(ip)
            hit = any(addr in n for n in LOCAL_NETS) or any(addr in n for n in ALWAYS_LOCAL)
        except ValueError:
            hit = False
        if len(local_cache) > 200000:
            local_cache.clear()
        local_cache[ip] = hit
    return hit


class Minute:
    def __init__(self, ts):
        self.ts = ts
        self.totals = {'down': 0, 'up': 0, 'internal': 0, 'other': 0, 'flows': 0}
        self.hosts = {}
        self.conv = {}
        self.services = {}
        self.ifaces = {}
        self.ifconv = {}

    def add(self, src, dst, proto, sport, dport, nbytes, npkts, exporter=None, inif=0, outif=0):
        if not nbytes:
            return
        sl, dl = is_local(src), is_local(dst)
        # The service is the lower port of the two (443, 53, 3074, ...); none for ICMP and the like.
        if proto in (6, 17) and sport and dport:
            port = dport if dport in SERVICE_PORTS else sport if sport in SERVICE_PORTS else min(sport, dport)
        else:
            port = 0
        # Per exporter interface: which port (or VLAN interface) the flow came in on / went out of.
        if exporter is not None:
            for idx, d in ((inif, 'i'), (outif, 'o')):
                if idx:
                    f = self.ifaces.setdefault((exporter, idx, d), [0, 0, 0])
                    f[0] += nbytes
                    f[1] += npkts
                    f[2] += 1
            if inif:
                k = (exporter, inif, src, dst, proto, port)
                self.ifconv[k] = self.ifconv.get(k, 0) + nbytes
            if not owns(exporter, (src, dst, proto, sport, dport), time.time()):
                status['duplicates'] += 1   # already counted from another switch
                return
        self.totals['flows'] += 1
        # Per host: bytes in, out, packets in, out, internet bytes in, out.
        if sl:
            h = self.hosts.setdefault(src, [0, 0, 0, 0, 0, 0])
            h[1] += nbytes
            h[3] += npkts
            if not dl:
                h[5] += nbytes
        if dl:
            h = self.hosts.setdefault(dst, [0, 0, 0, 0, 0, 0])
            h[0] += nbytes
            h[2] += npkts
            if not sl:
                h[4] += nbytes
        if sl and not dl:
            self.totals['up'] += nbytes
            key, direction = (src, dst, proto, port), 1
        elif dl and not sl:
            self.totals['down'] += nbytes
            key, direction = (dst, src, proto, port), 0
        elif sl and dl:
            self.totals['internal'] += nbytes
            key, direction = (src, dst, proto, port), 1
        else:
            self.totals['other'] += nbytes
            return
        c = self.conv.setdefault(key, [0, 0])
        c[direction] += nbytes
        s = self.services.setdefault((proto, port), [0, 0])
        s[direction] += nbytes

    def dump(self):
        conv = sorted(self.conv.items(), key=lambda kv: -(kv[1][0] + kv[1][1]))[:TOP_CONVERSATIONS]
        services = sorted(self.services.items(), key=lambda kv: -(kv[1][0] + kv[1][1]))[:TOP_SERVICES]
        ifconv = sorted(self.ifconv.items(), key=lambda kv: -kv[1])[:TOP_IFCONV]
        return {
            'ts': self.ts,
            'totals': self.totals,
            'hosts': self.hosts,
            'conv': [[k[0], k[1], k[2], k[3], v[0], v[1]] for k, v in conv],
            'services': [[k[0], k[1], v[0], v[1]] for k, v in services],
            'ifaces': [[k[0], k[1], k[2], v[0], v[1], v[2]] for k, v in self.ifaces.items()],
            'ifconv': [list(k) + [v] for k, v in ifconv],
        }


status = {'started': int(time.time()), 'lastPacket': None, 'exporters': {}, 'packets': 0, 'records': 0,
          'noTemplate': 0, 'errors': 0, 'templates': 0, 'duplicates': 0, 'flowKeys': 0, 'port': PORT}


def write_json(path, data):
    tmp = path + '.tmp'
    with open(tmp, 'w') as f:
        json.dump(data, f, separators=(',', ':'))
    os.replace(tmp, path)


def ip4(b):
    return socket.inet_ntop(socket.AF_INET, b)


def ip6(b):
    return socket.inet_ntop(socket.AF_INET6, b)


def uint(b):
    return int.from_bytes(b, 'big')


def records_from_template(fields, data, offset, end):
    """Yields dicts {type: raw bytes} for each record in a data set."""
    fixed = all(length != 0xFFFF for _, length in fields)
    reclen = sum(length for _, length in fields) if fixed else None
    while offset < end:
        if fixed:
            if reclen == 0 or offset + reclen > end:
                return
        rec = {}
        for ftype, length in fields:
            if length == 0xFFFF:   # IPFIX variable length
                if offset >= end:
                    return
                length = data[offset]
                offset += 1
                if length == 255:
                    length = uint(data[offset:offset + 2])
                    offset += 2
            rec[ftype] = data[offset:offset + length]
            offset += length
        if offset > end:
            return
        yield rec


def handle_record(minute, exporter, rec):
    if F_SRC4 in rec and F_DST4 in rec:
        src, dst = ip4(rec[F_SRC4]), ip4(rec[F_DST4])
    elif F_SRC6 in rec and F_DST6 in rec:
        src, dst = ip6(rec[F_SRC6]), ip6(rec[F_DST6])
    else:
        return
    nbytes = uint(rec.get(F_BYTES, b'')) or uint(rec.get(F_OUT_BYTES, b''))
    npkts = uint(rec.get(F_PKTS, b'')) or uint(rec.get(F_OUT_PKTS, b''))
    minute.add(src, dst, uint(rec.get(F_PROTO, b'\0')), uint(rec.get(F_SPORT, b'')), uint(rec.get(F_DPORT, b'')), nbytes, npkts,
               exporter, uint(rec.get(F_INIF, b'')), uint(rec.get(F_OUTIF, b'')))
    status['records'] += 1


def parse_templates(exporter, domain, data, offset, end, ipfix):
    while offset + 4 <= end:
        tid, count = struct.unpack_from('!HH', data, offset)
        offset += 4
        fields = []
        for _ in range(count):
            if offset + 4 > end:
                return
            ftype, length = struct.unpack_from('!HH', data, offset)
            offset += 4
            if ipfix and ftype & 0x8000:   # enterprise-specific: skip the enterprise number
                ftype &= 0x7FFF
                ftype |= 0x10000
                offset += 4
            fields.append((ftype, length))
        if tid >= 256 and fields:
            templates[(exporter, domain, tid)] = fields
    status['templates'] = len(templates)


def handle_packet(minute, exporter, data):
    if len(data) < 4:
        return
    version = struct.unpack_from('!H', data, 0)[0]
    if version == 5:
        count = struct.unpack_from('!H', data, 2)[0]
        for i in range(count):
            o = 24 + i * 48
            if o + 48 > len(data):
                break
            src, dst = ip4(data[o:o + 4]), ip4(data[o + 4:o + 8])
            pkts, octets = struct.unpack_from('!II', data, o + 16)
            inif, outif = struct.unpack_from('!HH', data, o + 12)
            sport, dport = struct.unpack_from('!HH', data, o + 32)
            minute.add(src, dst, data[o + 38], sport, dport, octets, pkts, exporter, inif, outif)
            status['records'] += 1
        return
    if version == 9:
        domain, offset, ipfix = struct.unpack_from('!I', data, 16)[0], 20, False
    elif version == 10:
        domain, offset, ipfix = struct.unpack_from('!I', data, 12)[0], 16, True
    else:
        status['errors'] += 1
        return
    while offset + 4 <= len(data):
        set_id, length = struct.unpack_from('!HH', data, offset)
        if length < 4:
            break
        end = min(offset + length, len(data))
        body = offset + 4
        if (not ipfix and set_id == 0) or (ipfix and set_id == 2):
            parse_templates(exporter, domain, data, body, end, ipfix)
        elif set_id >= 256:
            fields = templates.get((exporter, domain, set_id))
            if fields is None:
                status['noTemplate'] += 1   # data before its template: dropped until the exporter resends templates
            else:
                for rec in records_from_template(fields, data, body, end):
                    handle_record(minute, exporter, rec)
        offset += length


def main():
    os.makedirs(OUT_DIR, mode=0o750, exist_ok=True)
    sock = socket.socket(socket.AF_INET6, socket.SOCK_DGRAM)
    try:
        sock.setsockopt(socket.IPPROTO_IPV6, socket.IPV6_V6ONLY, 0)
        sock.bind(('::', PORT))
    except OSError:
        sock = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
        sock.bind(('0.0.0.0', PORT))
    sock.setsockopt(socket.SOL_SOCKET, socket.SO_RCVBUF, 8 * 1024 * 1024)
    sock.settimeout(5)
    print(f'webmonitor flow collector listening on udp/{PORT}, writing to {OUT_DIR}', flush=True)

    minute = Minute(int(time.time()) // 60 * 60)
    last_status = 0
    while True:
        try:
            data, addr = sock.recvfrom(65535)
        except socket.timeout:
            data, addr = None, None
        now = time.time()
        if now // 60 * 60 > minute.ts:   # minute finished: hand it over
            if minute.totals['flows'] or minute.ifaces:
                write_json(os.path.join(OUT_DIR, f'{minute.ts}.json'), minute.dump())
            minute = Minute(int(now) // 60 * 60)
            prune_owners(now)
        if now - last_status >= 15:
            write_json(os.path.join(OUT_DIR, 'status.json'), dict(status, at=int(now)))
            last_status = now
        if data is None:
            continue
        exporter = addr[0].removeprefix('::ffff:')
        if ALLOW and exporter not in ALLOW:
            continue
        status['packets'] += 1
        status['lastPacket'] = int(now)
        status['exporters'][exporter] = status['exporters'].get(exporter, 0) + 1
        try:
            handle_packet(minute, exporter, data)
        except Exception as exc:   # one bad packet must not stop collection
            status['errors'] += 1
            print(f'packet from {exporter}: {exc!r}', file=sys.stderr, flush=True)


if __name__ == '__main__':
    main()
