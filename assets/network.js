// Network tab: internet speed tests, a live map of the switches and what's
// plugged into them, and each switch's ports with their traffic right now.
// Data: api/network.php (live port rates, speed tests), api/topology.php
// (the map) and api/port-map.php (one switch's ports).
// Uses api(), escapeHtml(), openModal(), hostsById, statusClass(), DEVICE_SVG,
// cssVar(), fmtAgo(), IS_ADMIN from app.js, VENDOR_ICONS from topology.js and
// fmtBits() from traffic.js.

const NET_LIVE_MS = 5000;
const NET_BUF = 72;                 // live samples kept per port (6 minutes)
const NET_NODE = { w: 214, h: 64 };
const NET_AP = { w: 178, h: 56 };
const NET_PILL = { w: 178, h: 24, gap: 4, col: 10, channel: 34 };
const NET_HGAP = 30;
const NET_VGAP = 86;

let netVisible = false;
let netTopo = null;            // api/topology.php
let netPorts = {};             // hostId -> [{i, name, speed, status, lag, ips}]
let netLive = {};              // hostId -> {at, error, rates: {ifIndex: [in, out]}}
let netLiveTimer = null;
let netLiveBusy = false;
const netBuf = new Map();      // "host:ifIndex" -> [[sec, in, out], ...]
let netLayout = null;
let netView = { k: 1, x: 0, y: 0 };
let netSel = null;             // selected device id
let netSelMap = null;          // its port map (api/port-map.php)
let netSelPort = null;         // selected ifIndex
let netPortRange = 'live';
let netPortChart = null;
let netSpeed = null;
let netSpeedDays = 7;
let netSpeedChart = null;
let netSpeedTimer = null;
let netShowAllPorts = false;
let netFlowRange = '1h';
let netFlowIfaces = null;      // NetFlow the selected device exports, per interface

const netShowDevices = document.getElementById('net-show-devices');
try { netShowDevices.checked = localStorage.getItem('wm-net-devices') === '1'; } catch (e) { /* private mode */ }

function networkViewShown(visible) {
  netVisible = visible;
  if (visible) {
    if (!netTopo) loadNetTopo(); else renderNetMap();
    loadSpeedtests();
    netLiveTick(true);
  } else {
    clearTimeout(netLiveTimer);
    clearTimeout(netSpeedTimer);
  }
}

// ---- Formatting

function netRate(bps, short = false) {
  if (bps == null) return '—';
  const u = short ? ['b', 'k', 'M', 'G', 'T'] : ['bit/s', 'kbit/s', 'Mbit/s', 'Gbit/s', 'Tbit/s'];
  let i = 0;
  while (bps >= 1000 && i < u.length - 1) { bps /= 1000; i++; }
  return `${bps.toFixed(bps >= 100 || i === 0 ? 0 : 1)}${short ? '' : ' '}${u[i]}`;
}
function netMs(v) { return v == null ? '—' : v >= 100 ? Math.round(v) : (Math.round(v * 10) / 10).toString(); }
function netMbps(v) { return v == null ? '—' : v >= 100 ? Math.round(v).toLocaleString() : v.toFixed(1); }
function netShortPort(n) {
  return String(n || '').replace(/^HundredGigE/i, 'Hu').replace(/^FortyGigabitEthernet/i, 'Fo').replace(/^TwentyFiveGigE/i, 'Twe')
    .replace(/^TenGigabitEthernet/i, 'Te').replace(/^GigabitEthernet/i, 'Gi').replace(/^FastEthernet/i, 'Fa').replace(/^Port-channel/i, 'Po');
}
function netShortName(n) { return String(n || '').replace(/\.[a-z][\w-]*\.[a-z]{2,}$/i, ''); }
function netIn(ms) {
  const m = Math.max(0, Math.round(ms / 60000));
  if (m < 1) return 'less than a minute';
  if (m < 60) return `${m} min`;
  const h = Math.floor(m / 60);
  return h < 48 ? `${h} h${m % 60 ? ` ${m % 60} min` : ''}` : `${Math.round(h / 24)} days`;
}
function netEvery(min) {
  if (!min) return 'off';
  if (min < 60) return `every ${min} minutes`;
  if (min === 60) return 'every hour';
  if (min === 1440) return 'once a day';
  return `every ${min / 60} hours`;
}
/** Load level of a link: 0 idle … 4 busy, by share of the port speed. */
function netLevel(bps, speedMbps) {
  if (bps == null) return null;
  if (bps < 50000) return 0;
  const u = speedMbps ? bps / (speedMbps * 1e6) : 0;
  return u < 0.1 ? 1 : u < 0.5 ? 2 : u < 0.8 ? 3 : 4;
}

// ---- Live rates

async function netLiveTick(first = false) {
  clearTimeout(netLiveTimer);
  if (!netVisible) return;
  const live = document.getElementById('net-live').checked;
  if (live && document.visibilityState === 'visible' && !netLiveBusy) {
    netLiveBusy = true;
    let missing = false;
    try {
      const names = first || !Object.keys(netPorts).length;
      const resp = await api(`api/network.php?live=1${names ? '&names=1' : ''}`);
      const d = await resp.json();
      if (resp.ok) {
        if (d.ports) netPorts = d.ports;
        netLive = d.devices || {};
        const sec = Math.round(d.at / 1000);
        for (const [hid, dev] of Object.entries(netLive)) {
          if (!dev.rates) { if (!dev.error) missing = true; continue; }
          for (const [idx, [i, o]] of Object.entries(dev.rates)) {
            const key = `${hid}:${idx}`;
            const buf = netBuf.get(key) || [];
            if (!buf.length || buf[buf.length - 1][0] !== Math.round(dev.at / 1000)) buf.push([Math.round(dev.at / 1000), i, o]);
            if (buf.length > NET_BUF) buf.shift();
            netBuf.set(key, buf);
          }
        }
        netApplyLive();
        document.getElementById('net-meta').textContent = netMetaText(sec);
      }
    } catch (e) { /* try again next tick */ }
    netLiveBusy = false;
    // The first sample of a device has nothing to compare with: ask again soon.
    netLiveTimer = setTimeout(netLiveTick, missing ? 2700 : NET_LIVE_MS);
    return;
  }
  netLiveTimer = setTimeout(netLiveTick, NET_LIVE_MS);
}
document.getElementById('net-live').addEventListener('change', () => { netLiveTick(); netApplyLive(); });
document.addEventListener('visibilitychange', () => { if (netVisible && document.visibilityState === 'visible') netLiveTick(); });

function netMetaText() {
  const devs = Object.values(netLive);
  const bad = devs.filter(d => d.error).length;
  const parts = [];
  if (netTopo) parts.push(`${Object.keys(netTopo.nodes).length} network devices`);
  if (document.getElementById('net-live').checked) parts.push(`traffic updated ${new Date().toLocaleTimeString()}`);
  else parts.push('live traffic paused');
  if (bad) parts.push(`${bad} device${bad === 1 ? '' : 's'} not answering SNMP`);
  return parts.join(' · ');
}

/** {in, out, speed} of a port (or the members of a port-channel) of a device, or null. */
function netPortRate(hostId, name) {
  const ports = netPorts[hostId];
  const rates = netLive[hostId]?.rates;
  if (!ports || !name) return null;
  const n = netShortPort(name);
  let match = ports.filter(p => netShortPort(p.name) === n);
  if (!match.length) match = ports.filter(p => p.lag && netShortPort(p.lag) === n);
  if (!match.length) return null;
  const speed = match.reduce((a, p) => a + (p.speed || 0), 0) || null;
  if (!rates) return { in: null, out: null, speed, error: netLive[hostId]?.error || null };
  let i = 0, o = 0, any = false;
  for (const p of match) {
    const r = rates[p.i];
    if (r) { i += r[0]; o += r[1]; any = true; }
  }
  return any ? { in: i, out: o, speed } : { in: null, out: null, speed };
}

/** Traffic through a device: the sum of what comes in on its ports. */
function netDeviceTotal(hostId) {
  const rates = netLive[hostId]?.rates;
  if (!rates) return null;
  return Object.values(rates).reduce((a, r) => a + r[0], 0);
}

/**
 * The edge router's internet port: the active port described as WAN/Internet,
 * else its one active port that isn't a link to another network device or a LAN port.
 */
function netWanPort(rootId) {
  const ports = netPorts[rootId];
  if (!ports || !netTopo) return null;
  const links = new Set(netTopo.links.filter(l => l.a === rootId || l.b === rootId).map(l => netShortPort(l.ports[rootId] || '')));
  const cand = ports.filter(p => p.status === 'up' && !p.lag && !links.has(netShortPort(p.name)));
  const named = cand.filter(p => /\b(wan|internet|isp|modem|ont|uplink)\b/i.test(p.desc || ''));
  if (named.length === 1) return named[0].name;
  const lan = new Set((netTopo.nodes[rootId]?.ports || []).filter(p => p.devices.length > 1).map(p => netShortPort(p.port)));
  const rest = cand.filter(p => !lan.has(netShortPort(p.name)));
  return rest.length === 1 ? rest[0].name : null;
}

// ---- Map

async function loadNetTopo() {
  const box = document.getElementById('net-map');
  if (!netTopo) box.innerHTML = '<div class="switch-info-status">Building the network map…</div>';
  try {
    const resp = await api('api/topology.php');
    const d = await resp.json();
    if (!resp.ok) throw new Error(d.error || 'Could not build the map');
    netTopo = d;
    netLayout = null;
    renderNetMap(true);
    if (netSel && !netTopo.nodes[netSel]) closeNetSwitch();
  } catch (err) {
    box.innerHTML = `<div class="switch-info-status details-error">${escapeHtml(err.message)}</div>`;
  }
}
document.getElementById('net-refresh').addEventListener('click', () => { loadNetTopo(); netLiveTick(true); });
netShowDevices.addEventListener('change', () => {
  try { localStorage.setItem('wm-net-devices', netShowDevices.checked ? '1' : ''); } catch (e) { /* private mode */ }
  renderNetMap(true);
});

/** Everything plugged into a device's ports (or connected to an AP), for the device pills. */
function netNodeDevices(n) {
  const out = [];
  for (const p of n.ports || []) {
    for (const d of p.devices) {
      const h = d.host ? hostsById.get(d.host.id) : null;
      const name = d.host && d.host.name !== d.host.ip ? d.host.name : d.name || d.ip || d.mac;
      out.push({ port: p.ssid ? (d.band || '').replace(' GHz', 'G') || 'Wi-Fi' : netShortPort(p.port), ssid: p.ssid ? p.port : null,
        name, ip: d.ip, mac: d.mac, hostId: d.host?.id ?? null, device: d.host?.device || h?.device || (d.ap ? 'ap' : null),
        status: h ? statusClass(h) : null, ap: d.ap?.name || null, clients: d.wifiClients?.length || 0, guests: d.guests?.length || 0 });
    }
  }
  return out;
}

function netMeasure(t) {
  const n = netTopo.nodes[t.id];
  const box = n.device === 'ap' ? NET_AP : NET_NODE;
  const devs = netShowDevices.checked ? netNodeDevices(n) : [];
  const kids = (t.children || []).map(netMeasure);
  // Devices sit in two columns either side of the line down to the next switches.
  const cols = devs.length ? (devs.length > 6 || kids.length ? 2 : 1) : 0;
  const rows = cols ? Math.ceil(devs.length / cols) : 0;
  const devW = cols === 2 ? NET_PILL.w * 2 + NET_PILL.channel : cols ? NET_PILL.w : 0;
  const devH = rows ? 16 + rows * (NET_PILL.h + NET_PILL.gap) : 0;
  const kidsW = kids.reduce((a, k) => a + k.width, 0) + Math.max(0, kids.length - 1) * NET_HGAP;
  return { t, n, w: box.w, h: box.h, devs, cols, rows, devW, devH, kids, kidsW, width: Math.max(box.w, devW, kidsW) };
}

function netPlace(L, x, y) {
  L.cx = x + L.width / 2;
  L.x = L.cx - L.w / 2;
  L.y = y;
  let kx = L.cx - L.kidsW / 2;
  const ky = y + L.h + L.devH + NET_VGAP;
  for (const k of L.kids) { netPlace(k, kx, ky); kx += k.width + NET_HGAP; }
}

function netWalk(L, fn) { fn(L); L.kids.forEach(k => netWalk(k, fn)); }

function netBuildLayout() {
  const roots = netTopo.roots.map(netMeasure);
  const INTERNET_H = 58;
  let x = 0;
  let maxY = 0;
  const top = INTERNET_H + NET_VGAP;
  for (const r of roots) { netPlace(r, x, top); x += r.width + NET_HGAP * 2; }
  for (const r of roots) netWalk(r, L => { maxY = Math.max(maxY, L.y + L.h + L.devH); });
  const first = roots[0];
  const internet = first ? { cx: first.cx, y: 0, w: 230, h: INTERNET_H, root: first } : null;
  return { roots, internet, width: Math.max(x - NET_HGAP * 2, 240), height: maxY + 10 };
}

function netNodeIcon(n, x, y, size = 22) {
  if (n.vendor && typeof VENDOR_ICONS !== 'undefined' && VENDOR_ICONS[n.vendor]) {
    return VENDOR_ICONS[n.vendor].replace('<svg ', `<svg x="${x}" y="${y}" width="${size}" height="${size}" `);
  }
  const paths = DEVICE_SVG[n.device] || DEVICE_SVG.switch;
  return `<svg x="${x}" y="${y}" width="${size}" height="${size}" viewBox="0 0 24 24" class="net-icon">${paths}</svg>`;
}

function netTrim(s, max) { s = String(s ?? ''); return s.length > max ? s.slice(0, max - 1) + '…' : s; }

function renderNetMap(refit = false) {
  const box = document.getElementById('net-map');
  if (!netTopo) return;
  if (!netTopo.roots.length) {
    box.innerHTML = `<div class="switch-info-status">No switch port maps yet. Give your switches and routers the Switch or Router type
      with an SNMP community (Edit on their card); their ports are read every 15 minutes.</div>`;
    return;
  }
  netLayout = netBuildLayout();
  const parts = [];
  const links = [];
  const nodes = [];
  const pills = [];
  const L0 = netLayout;
  // Internet, above the edge router.
  if (L0.internet) {
    const I = L0.internet;
    const r = I.root;
    links.push(`<path class="net-link" data-link="wan" d="M${I.cx},${I.h} C${I.cx},${I.h + NET_VGAP / 2} ${r.cx},${r.y - NET_VGAP / 2} ${r.cx},${r.y}"/>`);
    links.push(netLinkLabel('wan', r.cx, r.y - NET_VGAP / 2 + 6));
    nodes.push(`<g class="net-node net-internet" transform="translate(${I.cx - I.w / 2},0)">
      <rect width="${I.w}" height="${I.h}" rx="29"/>
      <svg x="14" y="15" width="28" height="28" viewBox="0 0 24 24" class="net-icon"><circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3a14 14 0 0 1 0 18M12 3a14 14 0 0 0 0 18"/></svg>
      <text x="50" y="25" class="net-name">Internet</text>
      <text x="50" y="43" class="net-sub" id="net-inet-sub">${netInetSub()}</text></g>`);
  }
  for (const R of L0.roots) {
    netWalk(R, L => {
      const n = L.n;
      const h = hostsById.get(n.id);
      const st = h ? statusClass(h) : 'unknown';
      const ap = n.device === 'ap';
      const sub = n.model || n.ip;
      const line3 = ap ? `${n.wifiClients || 0} Wi-Fi clients` : `${n.portsUp}/${n.portsTotal} ports up`;
      nodes.push(`<g class="net-node${netSel === n.id ? ' selected' : ''}${ap ? ' net-ap' : ''}" data-node="${n.id}" transform="translate(${L.x},${L.y})" tabindex="0" role="button" aria-label="${escapeHtml(n.name)}">
        <title>${escapeHtml(`${n.name} · ${n.ip}${n.aliases?.length ? ` (also ${n.aliases.join(', ')})` : ''}`)}</title>
        <rect width="${L.w}" height="${L.h}" rx="10"/>
        ${netNodeIcon(n, 12, ap ? 16 : 18, 24)}
        <circle class="net-dot ${st}" cx="${L.w - 12}" cy="12" r="4.5"/>
        <text x="46" y="${ap ? 20 : 21}" class="net-name">${escapeHtml(netTrim(netShortName(n.name), ap ? 17 : 21))}</text>
        <text x="46" y="${ap ? 35 : 37}" class="net-sub">${escapeHtml(netTrim(sub, ap ? 22 : 27))}</text>
        <text x="46" y="${ap ? 49 : 53}" class="net-sub small">${escapeHtml(line3)}${ap ? '' : `<tspan class="net-total" data-total="${n.id}" dx="6"></tspan>`}</text>
      </g>`);
      // Devices, either side of the trunk.
      const top = L.y + L.h + 16;
      L.devs.forEach((d, i) => {
        const col = L.cols === 2 ? i % 2 : 0;
        const row = L.cols === 2 ? Math.floor(i / 2) : i;
        const px = L.cols === 2 ? (col === 0 ? L.cx - NET_PILL.channel / 2 - NET_PILL.w : L.cx + NET_PILL.channel / 2) : L.cx - NET_PILL.w / 2;
        const py = top + row * (NET_PILL.h + NET_PILL.gap);
        const icon = DEVICE_SVG[d.device] || '<circle cx="12" cy="12" r="3.5"/>';
        const extra = d.ap ? ` · AP, ${d.clients} clients` : d.guests ? ` · ${d.guests} guests` : '';
        pills.push(`<g class="net-pill${d.hostId ? ' link' : ''}" ${d.hostId ? `data-host="${d.hostId}"` : ''} transform="translate(${px},${py})">
          <title>${escapeHtml(`${d.name}${d.ip && d.ip !== d.name ? ` · ${d.ip}` : ''}${d.mac ? ` · ${d.mac}` : ''}\n${d.ssid ? `SSID ${d.ssid} (${d.port})` : `Port ${d.port}`}${extra}`)}</title>
          <rect width="${NET_PILL.w}" height="${NET_PILL.h}" rx="6"/>
          <text x="7" y="16" class="net-pport">${escapeHtml(netTrim(d.port, 9))}</text>
          <svg x="58" y="5" width="14" height="14" viewBox="0 0 24 24" class="net-icon">${icon}</svg>
          ${d.status ? `<circle class="net-dot ${d.status}" cx="${NET_PILL.w - 9}" cy="12" r="3"/>` : ''}
          <text x="77" y="16" class="net-pname">${escapeHtml(netTrim(d.name, 15))}</text></g>`);
      });
      for (const K of L.kids) {
        const x1 = L.cx, y1 = L.y + L.h, yt = y1 + L.devH, x2 = K.cx, y2 = K.y;
        const key = `${n.id}-${K.n.id}`;
        links.push(`<path class="net-link" data-link="${key}" d="M${x1},${y1} V${yt + 4} C${x1},${yt + NET_VGAP * 0.55} ${x2},${y2 - NET_VGAP * 0.55} ${x2},${y2}"/>`);
        links.push(netLinkLabel(key, x2, y2 - NET_VGAP / 2 + 4, K.t.link));
      }
    });
  }
  parts.push(`<g class="net-links">${links.join('')}</g>`, `<g class="net-pills">${pills.join('')}</g>`, `<g class="net-nodes">${nodes.join('')}</g>`);
  const keep = box.querySelector('svg.net-svg');
  box.innerHTML = `<svg class="net-svg" xmlns="http://www.w3.org/2000/svg"><g id="net-vp">${parts.join('')}</g></svg>`;
  netBindMap(box);
  if (refit || !keep) netFit(); else netApplyView();
  netApplyLive();
}

function netLinkLabel(key, x, y, link) {
  const ports = link ? [link.parentPort, link.childPort].filter(Boolean).map(netShortPort).join(' ⇄ ') : '';
  return `<g class="net-label" data-label="${key}" transform="translate(${x},${y})">
    <rect x="-66" y="-15" width="132" height="${ports ? 30 : 18}" rx="6"/>
    ${ports ? `<text y="-3" class="net-lports">${escapeHtml(netTrim(ports, 24))}</text>` : ''}
    <text y="${ports ? 11 : -2}" class="net-lrate">—</text></g>`;
}

function netInetSub() {
  const l = netSpeed?.latest;
  if (!l || l.error) return 'speed test: ' + (l ? 'failed' : 'not run yet');
  return `↓ ${netMbps(l.down)}  ↑ ${netMbps(l.up)} Mbit/s · ${netMs(l.ping)} ms`;
}

/** Live traffic onto the map's links and labels, the switch panel and the port chart. */
function netApplyLive() {
  if (!netVisible) return;
  const live = document.getElementById('net-live').checked;
  const svg = document.querySelector('#net-map svg');
  if (svg && netLayout) {
    const setLink = (key, r) => {
      const path = svg.querySelector(`[data-link="${key}"]`);
      const label = svg.querySelector(`[data-label="${key}"] .net-lrate`);
      if (!path) return;
      const total = r && r.in != null ? Math.max(r.down, r.up) : null;
      const lvl = live ? netLevel(total, r?.speed) : null;
      path.setAttribute('class', `net-link${lvl == null ? ' nodata' : ` lvl${lvl}`}${lvl ? (r.down >= r.up ? ' flow-down' : ' flow-up') : ''}`);
      const w = total == null ? 2 : 2 + Math.max(0, Math.min(7, Math.log10(Math.max(total, 1) / 1e5) * 1.6));
      path.style.strokeWidth = w.toFixed(1);
      path.style.setProperty('--dur', `${[0, 2.4, 1.4, 0.8, 0.45][lvl || 0]}s`);
      if (label) label.textContent = !live || !r ? (r?.speed ? netSpeedLabel(r.speed) : '—') : r.in == null ? (r.error ? 'no SNMP answer' : 'measuring…') : `↓ ${netRate(r.down, true)}  ↑ ${netRate(r.up, true)}`;
    };
    // Each link's traffic from the parent's port (out = towards the child), else the child's.
    for (const R of netLayout.roots) {
      netWalk(R, L => {
        for (const K of L.kids) {
          const link = K.t.link || {};
          let r = netPortRate(L.n.id, link.parentPort);
          if (r && r.in != null) r = { ...r, down: r.out, up: r.in };
          else {
            const c = netPortRate(K.n.id, link.childPort);
            r = c && c.in != null ? { ...c, down: c.in, up: c.out } : (r || c);
          }
          setLink(`${L.n.id}-${K.n.id}`, r);
        }
      });
    }
    if (netLayout.internet) {
      const rid = netLayout.internet.root.n.id;
      const wan = netWanPort(rid);
      const r = wan ? netPortRate(rid, wan) : null;
      setLink('wan', r && r.in != null ? { ...r, down: r.in, up: r.out } : r);
      const lbl = svg.querySelector('[data-label="wan"] .net-lports');
      if (!lbl && wan) {
        const g = svg.querySelector('[data-label="wan"]');
        g.querySelector('rect').setAttribute('height', 30);
        g.querySelector('.net-lrate').setAttribute('y', 11);
        g.querySelector('rect').insertAdjacentHTML('afterend', `<text y="-3" class="net-lports">${escapeHtml(netShortPort(wan))} (internet)</text>`);
      }
    }
    svg.querySelectorAll('[data-total]').forEach(t => {
      const v = live ? netDeviceTotal(+t.dataset.total) : null;
      t.textContent = v == null ? '' : `· ⇅ ${netRate(v, true)}`;
    });
    const sub = svg.querySelector('#net-inet-sub');
    if (sub) sub.textContent = netInetSub();
  }
  netApplySwitchLive();
}
function netSpeedLabel(mbps) { return mbps >= 1000 ? `${mbps / 1000}G link` : `${mbps}M link`; }

// ---- Pan and zoom

function netApplyView() {
  const vp = document.getElementById('net-vp');
  if (vp) vp.setAttribute('transform', `translate(${netView.x},${netView.y}) scale(${netView.k})`);
}
function netFit() {
  const svg = document.querySelector('#net-map svg');
  if (!svg || !netLayout) return;
  const cw = svg.clientWidth || 800, ch = svg.clientHeight || 520;
  const pad = 24;
  const k = Math.min((cw - pad * 2) / netLayout.width, (ch - pad * 2) / netLayout.height, 1.15);
  netView = { k, x: (cw - netLayout.width * k) / 2, y: pad + Math.max(0, (ch - pad * 2 - netLayout.height * k) / 2) };
  netApplyView();
}
function netZoom(f, cx, cy) {
  const svg = document.querySelector('#net-map svg');
  if (!svg) return;
  if (cx == null) { cx = svg.clientWidth / 2; cy = svg.clientHeight / 2; }
  const k = Math.max(0.2, Math.min(3, netView.k * f));
  netView.x = cx - (cx - netView.x) * (k / netView.k);
  netView.y = cy - (cy - netView.y) * (k / netView.k);
  netView.k = k;
  netApplyView();
}
document.getElementById('net-zoom-in').addEventListener('click', () => netZoom(1.25));
document.getElementById('net-zoom-out').addEventListener('click', () => netZoom(0.8));
document.getElementById('net-zoom-fit').addEventListener('click', netFit);

function netBindMap(box) {
  const svg = box.querySelector('svg');
  let drag = null;
  svg.addEventListener('wheel', e => {
    e.preventDefault();
    const r = svg.getBoundingClientRect();
    netZoom(e.deltaY < 0 ? 1.12 : 1 / 1.12, e.clientX - r.left, e.clientY - r.top);
  }, { passive: false });
  svg.addEventListener('pointerdown', e => {
    if (e.button !== 0) return;
    drag = { x: e.clientX, y: e.clientY, vx: netView.x, vy: netView.y, moved: false, id: e.pointerId };
  });
  svg.addEventListener('pointermove', e => {
    if (!drag) return;
    const dx = e.clientX - drag.x, dy = e.clientY - drag.y;
    if (!drag.moved && Math.hypot(dx, dy) > 4) { drag.moved = true; svg.setPointerCapture(drag.id); svg.classList.add('dragging'); }
    if (drag.moved) { netView.x = drag.vx + dx; netView.y = drag.vy + dy; netApplyView(); }
  });
  const end = e => {
    if (!drag) return;
    const wasDrag = drag.moved;
    drag = null;
    svg.classList.remove('dragging');
    if (wasDrag || e.type !== 'pointerup') return;
    const node = e.target.closest('[data-node]');
    const pill = e.target.closest('[data-host]');
    if (node) selectNetDevice(+node.dataset.node);
    else if (pill) openModal(+pill.dataset.host);
  };
  svg.addEventListener('pointerup', end);
  svg.addEventListener('pointercancel', end);
  svg.querySelectorAll('[data-node]').forEach(g => {
    g.addEventListener('keydown', e => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); selectNetDevice(+g.dataset.node); } });
    g.addEventListener('dblclick', () => { if (hostsById.has(+g.dataset.node)) openModal(+g.dataset.node); });
  });
}

// ---- One switch: faceplate, ports, port traffic

const netSwitchBox = document.getElementById('net-switch');

function closeNetSwitch() {
  netSel = null;
  netSelMap = null;
  netSelPort = null;
  netSwitchBox.classList.add('hidden');
  if (netPortChart) { netPortChart.destroy(); netPortChart = null; }
  document.querySelectorAll('#net-map .net-node.selected').forEach(g => g.classList.remove('selected'));
}

async function selectNetDevice(id) {
  const n = netTopo?.nodes[id];
  if (!n) return;
  if (netSel !== id) { netSelPort = null; netShowAllPorts = false; }
  netSel = id;
  document.querySelectorAll('#net-map .net-node').forEach(g => g.classList.toggle('selected', +g.dataset.node === id));
  netSwitchBox.classList.remove('hidden');
  const head = netSwitchHead(n);
  if (n.device === 'ap') {
    netSelMap = null;
    netSwitchBox.innerHTML = head + netApBody(n);
    netBindSwitch();
    netSwitchBox.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    return;
  }
  netSwitchBox.innerHTML = head + '<div class="switch-info-status">Reading the ports…</div>';
  netBindSwitch();
  netSwitchBox.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
  try {
    const resp = await api(`api/port-map.php?host=${id}`);
    const d = await resp.json();
    if (!resp.ok) throw new Error(d.error || 'Could not read the ports');
    if (netSel !== id) return;
    netSelMap = d;
    if (netSelPort == null) {
      // Start on the busiest uplink, else the first active port.
      const up = d.ports.filter(p => p.status === 'up');
      const rate = p => { const r = netLive[id]?.rates?.[p.ifIndex]; return r ? r[0] + r[1] : 0; };
      const pick = [...up.filter(p => p.role === 'uplink')].sort((a, b) => rate(b) - rate(a))[0] || up[0];
      netSelPort = pick ? pick.ifIndex : null;
    }
    renderNetSwitch();
  } catch (err) {
    netSwitchBox.innerHTML = head + `<div class="switch-info-status details-error">${escapeHtml(err.message)}</div>`;
    netBindSwitch();
  }
}

function netSwitchHead(n) {
  const h = hostsById.get(n.id);
  return `<div class="net-sw-head">
    <div class="net-sw-title">
      <span class="net-sw-icon">${n.vendor && VENDOR_ICONS[n.vendor] ? VENDOR_ICONS[n.vendor] : svgIcon(DEVICE_SVG[n.device] || DEVICE_SVG.switch)}</span>
      <div><h3>${escapeHtml(n.name)} ${h ? `<span class="status-dot ${statusClass(h)}"></span>` : ''}</h3>
        <div class="muted small">${escapeHtml([n.model, n.ip, ...(n.aliases || [])].filter(Boolean).join(' · '))}</div></div>
    </div>
    <div class="section-actions">
      ${hostsById.has(n.id) ? `<button type="button" class="section-btn" data-open-host="${n.id}">Open card</button>` : ''}
      <button type="button" class="section-btn" id="net-sw-close" aria-label="Close">Close</button>
    </div></div>`;
}

function netBindSwitch() {
  netSwitchBox.querySelector('#net-sw-close')?.addEventListener('click', closeNetSwitch);
  netSwitchBox.querySelectorAll('[data-open-host]').forEach(b => b.addEventListener('click', () => openModal(+b.dataset.openHost)));
}

function netApBody(n) {
  const radios = (n.radios || []).map(r => `<span class="chip">${escapeHtml(r.band)} · ch ${escapeHtml(String(r.channel ?? '—'))} · ${r.clients ?? 0} clients${r.utilization != null ? ` · ${r.utilization}% busy` : ''}</span>`).join(' ');
  const groups = (n.ports || []).map(p => `<div class="net-ap-ssid"><h4>${escapeHtml(p.port)} <span class="muted small">${p.devices.length}</span></h4>
    <ul class="net-dev-list">${p.devices.map(netDevItem).join('')}</ul></div>`).join('');
  return `<div class="net-ap-radios">${radios || '<span class="muted small">No radio details.</span>'}</div>
    <div class="net-ap-grid">${groups || '<div class="muted small">No Wi-Fi clients right now.</div>'}</div>`;
}

function netDevItem(d) {
  const h = d.host ? hostsById.get(d.host.id) : null;
  const name = d.host && d.host.name !== d.host.ip ? d.host.name : d.name || d.ip || d.mac;
  const icon = DEVICE_SVG[d.host?.device || h?.device] || '';
  const label = d.host ? `<button type="button" class="link-btn" data-open-host="${d.host.id}">${escapeHtml(name)}</button>` : escapeHtml(name);
  return `<li>${h ? `<span class="status-dot ${statusClass(h)}"></span>` : '<span class="status-dot none"></span>'}
    ${icon ? svgIcon(icon, 'line-icon net-dev-icon') : ''}<span class="net-dev-name">${label}</span>
    <span class="muted small">${escapeHtml([d.ip !== name ? d.ip : null, d.band, d.vlan ? `VLAN ${d.vlan}` : null].filter(Boolean).join(' · '))}</span>
    ${d.note ? `<span class="chip warn">${escapeHtml(d.note)}</span>` : ''}</li>`;
}

/** Ports grouped by module ("Gi1/0/", "Te1/1/", "port"), numbered within each. */
function netPortGroups(ports) {
  const groups = new Map();
  for (const p of ports) {
    const m = String(p.name).match(/^(.*?)(\d+)$/);
    const prefix = m ? m[1] : p.name;
    if (!groups.has(prefix)) groups.set(prefix, []);
    groups.get(prefix).push({ ...p, num: m ? +m[2] : 0 });
  }
  for (const g of groups.values()) g.sort((a, b) => a.num - b.num);
  return [...groups.entries()];
}

function renderNetSwitch() {
  const n = netTopo.nodes[netSel];
  const d = netSelMap;
  if (!n || !d) return;
  const ports = d.ports;
  const face = netPortGroups(ports).map(([prefix, g]) => `
    <div class="fp-group${g.length > 4 ? ' two-row' : ''}" title="${escapeHtml(prefix)}">
      ${g.map(p => {
        const conn = p.role === 'uplink' ? (p.neighbors?.[0]?.name || p.towards?.[0]?.name || 'uplink') : p.devices.length ? p.devices.map(x => x.host?.name || x.ip || x.mac).slice(0, 3).join(', ') + (p.devices.length > 3 ? ` +${p.devices.length - 3}` : '') : '';
        const tip = `${p.name}${p.description ? ` — ${p.description}` : ''}\n${p.status}${p.speedMbps ? ` · ${fmtSpeed(p.speedMbps)}` : ''}${p.trunk ? ' · trunk' : p.vlan ? ` · VLAN ${p.vlan}` : ''}${conn ? `\n${conn}` : ''}`;
        return `<button type="button" class="fp-port ${p.status} role-${p.role}${p.ifIndex === netSelPort ? ' selected' : ''}" data-port="${p.ifIndex}" title="${escapeHtml(tip)}">
          <span class="fp-led"></span><span class="fp-fill" data-fill="${p.ifIndex}"></span><span class="fp-num">${p.num || escapeHtml(netTrim(p.name, 4))}</span></button>`;
      }).join('')}
    </div>`).join('');
  const shown = netShowAllPorts ? ports : ports.filter(p => p.status === 'up');
  const rows = shown.map(p => {
    const conn = p.role === 'uplink'
      ? `<span class="chip">uplink</span> ${escapeHtml(p.neighbors?.[0]?.name ? netShortName(p.neighbors[0].name) : p.towards?.[0]?.name ? netShortName(p.towards[0].name) : `${p.macCount} devices beyond`)}`
      : p.devices.length ? p.devices.slice(0, 2).map(x => x.host ? `<button type="button" class="link-btn" data-open-host="${x.host.id}">${escapeHtml(x.host.name)}</button>` : escapeHtml(x.ip || x.mac)).join(', ')
        + (p.devices.length > 2 ? ` <span class="muted">+${p.devices.length - 2}</span>` : '')
      : p.neighbors?.length ? escapeHtml(p.neighbors[0].name) : '<span class="muted">—</span>';
    return `<tr class="net-prow${p.ifIndex === netSelPort ? ' selected' : ''}" data-port="${p.ifIndex}">
      <td><span class="fp-dot ${p.status}"></span> <strong>${escapeHtml(p.name)}</strong>${p.description ? `<div class="muted small">${escapeHtml(p.description)}</div>` : ''}</td>
      <td>${p.status === 'up' ? (p.speedMbps ? fmtSpeed(p.speedMbps) : 'up') : escapeHtml(p.status)}</td>
      <td>${p.trunk ? 'trunk' : p.vlan ?? '—'}</td>
      <td class="net-conn">${conn}</td>
      <td class="num" data-rin="${p.ifIndex}">—</td><td class="num" data-rout="${p.ifIndex}">—</td>
      <td><span class="net-bar"><span data-bar="${p.ifIndex}"></span></span></td></tr>`;
  }).join('');
  const up = ports.filter(p => p.status === 'up').length;
  netSwitchBox.innerHTML = netSwitchHead(n) + `
    <div class="net-face-wrap"><div class="net-face">${face}</div></div>
    <div class="net-face-legend muted small"><span class="fp-dot up"></span> link up <span class="fp-dot down"></span> no link
      <span class="fp-dot disabled"></span> disabled <span class="fp-dot uplink"></span> uplink · the bar in each port shows its load right now
      · ports read ${fmtAgo(d.collectedAt)}</div>
    <div class="net-sw-grid">
      <div class="net-sw-ports">
        <div class="net-sw-ports-head"><strong>${up} of ${ports.length} ports up</strong>
          <label class="net-chk"><input type="checkbox" id="net-all-ports"${netShowAllPorts ? ' checked' : ''} /> Show all ports</label></div>
        <div class="details-table-wrap"><table class="switch-info-table net-ports-table">
          <thead><tr><th>Port</th><th>Speed</th><th>VLAN</th><th>Connected</th><th class="num">In</th><th class="num">Out</th><th>Load</th></tr></thead>
          <tbody>${rows || '<tr><td colspan="7" class="muted">No ports up.</td></tr>'}</tbody></table></div>
      </div>
      <div class="net-port-detail" id="net-port-detail"></div>
    </div>
    <div id="net-flowifaces"></div>`;
  netBindSwitch();
  netSwitchBox.querySelectorAll('[data-port]').forEach(el => el.addEventListener('click', e => {
    if (e.target.closest('[data-open-host]')) return;
    netSelPort = +el.dataset.port;
    netSwitchBox.querySelectorAll('[data-port]').forEach(x => x.classList.toggle('selected', +x.dataset.port === netSelPort));
    renderNetPortDetail();
  }));
  netSwitchBox.querySelector('#net-all-ports').addEventListener('change', e => { netShowAllPorts = e.target.checked; renderNetSwitch(); });
  loadNetFlowIfaces(netSel);
  renderNetPortDetail();
  netApplySwitchLive();
}

function netApplySwitchLive() {
  if (!netSel || !netSelMap || netSwitchBox.classList.contains('hidden')) return;
  const live = document.getElementById('net-live').checked;
  const rates = live ? netLive[netSel]?.rates : null;
  for (const p of netSelMap.ports) {
    const r = rates?.[p.ifIndex];
    const util = r && p.speedMbps ? Math.max(r[0], r[1]) / (p.speedMbps * 1e6) : 0;
    const pct = r ? Math.min(100, util > 0 ? Math.max(4, Math.sqrt(util) * 100) : 0) : 0;
    const lvl = r ? netLevel(Math.max(r[0], r[1]), p.speedMbps) : null;
    const fill = netSwitchBox.querySelector(`[data-fill="${p.ifIndex}"]`);
    if (fill) { fill.style.height = `${pct}%`; fill.className = `fp-fill${lvl != null ? ` lvl${lvl}` : ''}`; }
    const bar = netSwitchBox.querySelector(`[data-bar="${p.ifIndex}"]`);
    if (bar) { bar.style.width = `${r ? Math.min(100, util * 100) : 0}%`; bar.className = lvl != null ? `lvl${lvl}` : ''; bar.parentElement.title = r ? `${(util * 100).toFixed(util < 0.1 ? 2 : 0)}% of ${p.speedMbps ? fmtSpeed(p.speedMbps) : '?'}` : ''; }
    const ci = netSwitchBox.querySelector(`[data-rin="${p.ifIndex}"]`);
    const co = netSwitchBox.querySelector(`[data-rout="${p.ifIndex}"]`);
    if (ci) ci.textContent = r ? netRate(r[0]) : '—';
    if (co) co.textContent = r ? netRate(r[1]) : '—';
  }
  if (netPortRange === 'live') netUpdatePortChart();
  const now = document.getElementById('net-port-now');
  if (now) {
    const r = rates?.[netSelPort];
    now.innerHTML = r ? `<span class="net-in">In ${netRate(r[0])}</span> <span class="net-out">Out ${netRate(r[1])}</span>` : `<span class="muted">${live ? 'measuring…' : 'live paused'}</span>`;
  }
}

function renderNetPortDetail() {
  const box = document.getElementById('net-port-detail');
  if (!box) return;
  const p = netSelMap?.ports.find(x => x.ifIndex === netSelPort);
  if (!p) { box.innerHTML = '<div class="muted small">Pick a port to see its traffic.</div>'; return; }
  const devs = p.role === 'uplink' ? [] : p.devices;
  const nb = (p.neighbors || []).map(x => `${escapeHtml(x.name)}${x.port ? ` (${escapeHtml(x.port)})` : ''}${x.platform ? ` <span class="muted small">${escapeHtml(x.platform)}</span>` : ''}`).join('<br>');
  const ranges = ['live', '6h', '24h', '7d', '30d'];
  box.innerHTML = `
    <div class="net-pd-head"><div><h4>${escapeHtml(p.name)}${p.description ? ` <span class="muted">— ${escapeHtml(p.description)}</span>` : ''}</h4>
      <div class="muted small">${escapeHtml([p.status, p.speedMbps ? fmtSpeed(p.speedMbps) : null, p.trunk ? 'trunk' : p.vlan ? `VLAN ${p.vlan}` : null, p.lag ? `in ${p.lag}` : null].filter(Boolean).join(' · '))}</div></div>
      <div class="net-pd-now" id="net-port-now"></div></div>
    <div class="seg net-pd-ranges">${ranges.map(r => `<button type="button" class="group-btn${r === netPortRange ? ' active' : ''}" data-range="${r}">${r === 'live' ? 'Live' : r}</button>`).join('')}</div>
    <div class="net-pd-chart"><canvas id="net-port-canvas"></canvas><div class="net-pd-empty muted small hidden" id="net-port-empty"></div></div>
    ${nb ? `<div class="net-pd-sec"><div class="field-label">Neighbour (CDP/LLDP)</div>${nb}</div>` : ''}
    ${p.role === 'uplink' ? `<div class="net-pd-sec muted small">Uplink: ${p.macCount} devices are reached through this port.</div>` : ''}
    ${devs.length ? `<div class="net-pd-sec"><div class="field-label">Connected (${devs.length})</div><ul class="net-dev-list">${devs.map(netDevItem).join('')}</ul></div>` : ''}
    <div class="net-pd-sec net-pflow" id="net-pflow"></div>`;
  box.querySelectorAll('[data-open-host]').forEach(b => b.addEventListener('click', () => openModal(+b.dataset.openHost)));
  box.querySelectorAll('[data-range]').forEach(b => b.addEventListener('click', () => { netPortRange = b.dataset.range; renderNetPortDetail(); }));
  if (netPortChart) { netPortChart.destroy(); netPortChart = null; }
  if (netPortRange === 'live') { netDrawPortChart(); netApplySwitchLive(); }
  else loadNetPortHistory(p);
  loadNetPortFlows(p);
}

// ---- What's using a port (NetFlow)

const NET_FLOW_RANGES = ['15m', '1h', '6h', '24h', '7d'];

async function loadNetPortFlows(p) {
  const box = document.getElementById('net-pflow');
  if (!box) return;
  const host = netSel, range = netFlowRange;
  box.innerHTML = netFlowHead(null) + `<div class="muted small"><span class="net-spin"></span> Adding up the flows${['24h', '7d'].includes(range) ? ' — long ranges take a few seconds' : ''}…</div>`;
  netBindFlowHead(box, p);
  try {
    const resp = await api(`api/network.php?portflows=1&host=${host}&if=${p.ifIndex}&range=${range}`);
    const d = await resp.json();
    if (!resp.ok) throw new Error(d.error || 'Could not load the flows');
    if (netSel !== host || netSelPort !== p.ifIndex || netFlowRange !== range) return;
    box.innerHTML = netFlowHead(d) + netFlowBody(d);
    netBindFlowHead(box, p);
    box.querySelectorAll('[data-open-host]').forEach(b => b.addEventListener('click', () => openModal(+b.dataset.openHost)));
  } catch (err) {
    box.innerHTML = netFlowHead(null) + `<div class="muted small details-error">${escapeHtml(err.message)}</div>`;
    netBindFlowHead(box, p);
  }
}

function netFlowHead(d) {
  const badge = !d ? '' : d.mode === 'exact'
    ? `<span class="chip on" title="Sent: flows the switch reports as entering on this port. Received: ${d.received === 'exact' ? 'flows it reports leaving on this port' : d.received === 'ip' ? 'flows of the devices on this port, matched by IP' : 'not reported'}.">exact per port${d.received === 'ip' ? ' · received matched by IP' : ''}</span>`
    : d.mode === 'ip' ? `${d.internetPath ? '<span class="chip" title="This port leads to the router, so the internet traffic of every device is counted here">internet path</span> ' : ''}<span class="chip" title="Flows of the ${d.ips} device${d.ips === 1 ? '' : 's'} the port map puts on this port. Only traffic that passes a switch exporting NetFlow is counted: two devices talking to each other on another switch aren't seen.">matched by IP</span>` : '';
  return `<div class="net-pflow-head"><div class="field-label">What's using it (NetFlow) ${badge}</div>
    <div class="seg">${NET_FLOW_RANGES.map(r => `<button type="button" class="group-btn${r === netFlowRange ? ' active' : ''}" data-frange="${r}">${r}</button>`).join('')}</div></div>`;
}
function netBindFlowHead(box, p) {
  box.querySelectorAll('[data-frange]').forEach(b => b.addEventListener('click', () => { netFlowRange = b.dataset.frange; loadNetPortFlows(p); }));
}

function netWho(x) {
  const name = x.name || x.label || x.ip;
  const label = x.hostId ? `<button type="button" class="link-btn" data-open-host="${x.hostId}">${escapeHtml(name)}</button>` : escapeHtml(name);
  return `${label}${name !== x.ip ? ` <span class="muted small">${escapeHtml(x.ip)}</span>` : ''}`;
}

function netFlowBars(rows, label, max) {
  return `<div class="net-fbars">${rows.map(r => {
    const tot = r.in + r.out;
    return `<div class="net-fbar"><div class="net-fbar-label">${label(r)}</div>
      <div class="net-fbar-track" title="to it ${fmtTrafficBytes(r.in)} · from it ${fmtTrafficBytes(r.out)}">
        <span class="fin" style="width:${max ? (r.in / max) * 100 : 0}%"></span><span class="fout" style="width:${max ? (r.out / max) * 100 : 0}%"></span></div>
      <div class="net-fbar-val">${fmtTrafficBytes(tot)}</div></div>`;
  }).join('')}</div>`;
}

function netFlowBody(d) {
  if (d.mode === 'none') return `<div class="muted small">${escapeHtml(d.reason)}</div>`;
  const t = d.totals;
  if (!t.in && !t.out) return `<div class="muted small">No flows for ${d.mode === 'exact' ? 'this port' : 'the devices on this port'} in the last ${escapeHtml(d.range)}.
    Only traffic that passes a switch exporting NetFlow is seen.</div>`;
  const inet = t.inetIn != null && (t.in + t.out) ? Math.round((t.inetIn + t.inetOut) / (t.in + t.out) * 100) : null;
  const max = rows => Math.max(1, ...rows.map(r => r.in + r.out));
  let html = `<div class="net-ftotals"><span class="net-in">↓ to ${d.role === 'uplink' ? 'devices behind it' : 'the device'} ${fmtTrafficBytes(t.in)}</span>
    <span class="net-out">↑ from ${d.role === 'uplink' ? 'them' : 'it'} ${fmtTrafficBytes(t.out)}</span>${inet != null ? `<span class="muted">${inet}% internet</span>` : ''}</div>`;
  if (d.devices.length > 1 || d.role === 'uplink') {
    html += `<div class="net-fsec"><div class="net-fsec-h">Devices</div>${netFlowBars(d.devices.slice(0, 8), netWho, max(d.devices))}</div>`;
  }
  if (d.services.length) html += `<div class="net-fsec"><div class="net-fsec-h">Services</div>${netFlowBars(d.services.slice(0, 6), r => escapeHtml(r.name), max(d.services))}</div>`;
  if (d.remotes.length) {
    html += `<div class="net-fsec"><div class="net-fsec-h">Talking to</div>${netFlowBars(d.remotes.slice(0, 8), r => `${r.hostId ? `<button type="button" class="link-btn" data-open-host="${r.hostId}">${escapeHtml(r.name || r.ip)}</button>` : escapeHtml(r.name || r.ip)}
       <span class="muted small">${r.name ? escapeHtml(r.ip) + ' · ' : ''}${r.local ? 'local' : 'internet'}${r.services.length ? ' · ' + escapeHtml(r.services.join(', ')) : ''}</span>`, max(d.remotes))}</div>`;
  }
  if (d.conversations.length) {
    html += `<details class="net-fconv"><summary>Top conversations (${d.conversations.length})</summary>
      <div class="details-table-wrap"><table class="switch-info-table"><thead><tr><th>Device</th><th>Other end</th><th>Service</th><th class="num">↓</th><th class="num">↑</th></tr></thead>
      <tbody>${d.conversations.map(c => `<tr><td>${netWho(c.local)}</td>
        <td>${c.remoteHostId ? `<button type="button" class="link-btn" data-open-host="${c.remoteHostId}">${escapeHtml(c.remoteName || c.remote)}</button>` : escapeHtml(c.remoteName || c.remote)}${c.remoteName ? ` <span class="muted small">${escapeHtml(c.remote)}</span>` : ''}</td>
        <td>${escapeHtml(c.service)}</td><td class="num">${fmtTrafficBytes(c.in)}</td><td class="num">${fmtTrafficBytes(c.out)}</td></tr>`).join('')}</tbody></table></div></details>`;
  }
  if (d.generatedAt && Date.now() / 1000 - d.generatedAt > 45) html += `<div class="muted small">Added up ${fmtAgo(d.generatedAt * 1000)}.</div>`;
  if (d.range === '7d' && d.conversations.length) html += '<div class="muted small">Conversations and services cover the last 3 days (how long they\'re kept).</div>';
  return html;
}

/** The switch's own NetFlow export, per interface, with a hint when its monitors are on VLAN interfaces only. */
async function loadNetFlowIfaces(id) {
  const box = document.getElementById('net-flowifaces');
  if (!box) return;
  try {
    const resp = await api(`api/network.php?flowifaces=1&host=${id}&range=1h`);
    const d = await resp.json();
    if (!resp.ok || netSel !== id) return;
    netFlowIfaces = d;
    if (!d.exporter || !d.interfaces.length) { box.innerHTML = ''; return; }
    const max = Math.max(1, ...d.interfaces.map(i => i.in + i.out));
    const vlanOnly = !d.physical;
    box.innerHTML = `<details class="net-fconv"${vlanOnly ? ' open' : ''}><summary>NetFlow from this switch, last hour (${d.interfaces.length} interface${d.interfaces.length === 1 ? '' : 's'})</summary>
      ${!vlanOnly ? '<p class="hint">The switch reports the port each flow came in on, so port breakdowns here are exact for what each port sends. Traffic between two devices on another switch never reaches this one; export NetFlow from that switch too to see it.</p>'
        : `<p class="hint">This switch's flows name a VLAN interface rather than a port, so port breakdowns are matched by IP. To get exact per-port flows, apply the flow monitor to the physical ports, for example:</p>
        <pre class="net-pre">${escapeHtml(d.suggest || 'interface range <ports>\n ip flow monitor <monitor name> input')}</pre>
        <p class="hint">Uplinks and trunks are worth including too. Leave the VLAN interfaces as they are if another collector relies on them.</p>`}
      ${netFlowBars(d.interfaces.slice(0, 16).map(i => ({ ...i, in: i.out, out: i.in })), i => `${escapeHtml(i.name)}${i.physical ? '' : ' <span class="muted small">VLAN / other</span>'}`, max)}
    </details>`;
  } catch (e) { /* optional */ }
}

function netChartOptions(span) {
  const grid = cssVar('--panel-border');
  const text = cssVar('--muted');
  return {
    responsive: true, maintainAspectRatio: false, animation: false, parsing: false,
    interaction: { mode: 'index', intersect: false },
    scales: {
      x: { type: 'linear', grid: { color: grid }, ticks: { color: text, maxTicksLimit: 6, callback: v => {
        const dt = new Date(v * 1000);
        return span > 2 * 86400 ? dt.toLocaleDateString([], { month: 'short', day: 'numeric' }) : dt.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', ...(span < 900 ? { second: '2-digit' } : {}) });
      } } },
      y: { beginAtZero: true, grid: { color: grid }, ticks: { color: text, maxTicksLimit: 5, callback: v => netRate(v, true) } },
    },
    plugins: {
      legend: { labels: { color: cssVar('--text'), boxWidth: 10, boxHeight: 10 } },
      tooltip: { callbacks: { title: items => items.length ? new Date(items[0].raw.x * 1000).toLocaleString() : '', label: c => `${c.dataset.label}: ${netRate(c.raw.y)}` } },
    },
  };
}
const NET_IN = '#3b82f6';
const NET_OUT = '#22c55e';
function netDs(label, color, data) { return { label, data, borderColor: color, backgroundColor: color + '30', fill: true, borderWidth: 1.6, pointRadius: 0, tension: 0.25 }; }

function netDrawPortChart() {
  const c = document.getElementById('net-port-canvas');
  if (!c) return;
  const buf = netBuf.get(`${netSel}:${netSelPort}`) || [];
  netPortChart = new Chart(c, { type: 'line', data: { datasets: [
    netDs('In', NET_IN, buf.map(b => ({ x: b[0], y: b[1] }))), netDs('Out', NET_OUT, buf.map(b => ({ x: b[0], y: b[2] }))),
  ] }, options: netChartOptions(360) });
  netPortEmpty(buf.length < 2 ? 'Collecting live samples — the chart fills in over the next minute.' : '');
}
function netUpdatePortChart() {
  if (!netPortChart) return;
  const buf = netBuf.get(`${netSel}:${netSelPort}`) || [];
  netPortChart.data.datasets[0].data = buf.map(b => ({ x: b[0], y: b[1] }));
  netPortChart.data.datasets[1].data = buf.map(b => ({ x: b[0], y: b[2] }));
  netPortChart.update('none');
  netPortEmpty(buf.length < 2 ? 'Collecting live samples — the chart fills in over the next minute.' : '');
}
function netPortEmpty(msg) {
  const e = document.getElementById('net-port-empty');
  if (e) { e.textContent = msg; e.classList.toggle('hidden', !msg); }
}

async function loadNetPortHistory(p) {
  const host = netSel, range = netPortRange;
  try {
    const resp = await api(`api/network.php?port=${encodeURIComponent(p.name)}&host=${host}&range=${range}`);
    const d = await resp.json();
    if (!resp.ok) throw new Error(d.error || 'Could not load the history');
    if (netSel !== host || netPortRange !== range || netSelPort !== p.ifIndex) return;
    const c = document.getElementById('net-port-canvas');
    const span = { '6h': 21600, '24h': 86400, '7d': 604800, '30d': 2592000 }[range] || 86400;
    if (netPortChart) netPortChart.destroy();
    netPortChart = new Chart(c, { type: 'line', data: { datasets: [
      netDs('In', NET_IN, d.in.map(x => ({ x: x[0], y: x[1] }))), netDs('Out', NET_OUT, d.out.map(x => ({ x: x[0], y: x[1] }))),
    ] }, options: netChartOptions(span) });
    netPortEmpty(d.in.length ? '' : 'No history yet: port traffic is recorded every minute from now on.');
  } catch (err) {
    netPortEmpty(err.message);
  }
}

// ---- Internet speed

async function loadSpeedtests() {
  clearTimeout(netSpeedTimer);
  try {
    const resp = await api(`api/network.php?speedtests=1&days=${netSpeedDays}`);
    const d = await resp.json();
    if (!resp.ok) throw new Error(d.error || 'Could not load the speed tests');
    netSpeed = d;
    renderSpeedtests();
    const sub = document.getElementById('net-inet-sub');
    if (sub) sub.textContent = netInetSub();
    // While a test is waiting or running, check back often.
    if (netVisible) netSpeedTimer = setTimeout(loadSpeedtests, d.running || d.requested ? 4000 : 60000);
  } catch (err) {
    document.getElementById('net-speed-body').innerHTML = `<div class="switch-info-status details-error">${escapeHtml(err.message)}</div>`;
  }
}

document.querySelectorAll('#net-speed-range [data-days]').forEach(b => b.addEventListener('click', () => {
  netSpeedDays = +b.dataset.days;
  document.querySelectorAll('#net-speed-range [data-days]').forEach(x => x.classList.toggle('active', x === b));
  loadSpeedtests();
}));

document.getElementById('net-speed-run').addEventListener('click', async e => {
  const btn = e.currentTarget;
  btn.disabled = true;
  try {
    const resp = await api('api/network.php?action=speedtest', { method: 'POST' });
    const d = await resp.json();
    if (!resp.ok) throw new Error(d.error || 'Could not start the test');
    await loadSpeedtests();
  } catch (err) {
    alert(err.message);
  } finally {
    btn.disabled = false;
  }
});

function renderSpeedtests() {
  const d = netSpeed;
  const body = document.getElementById('net-speed-body');
  const ok = d.results.filter(r => !r.error);
  const l = d.latest;
  const avg = k => ok.length ? ok.reduce((a, r) => a + (r[k] || 0), 0) / ok.length : null;
  const min = k => ok.length ? Math.min(...ok.map(r => r[k] ?? Infinity)) : null;
  const tile = (label, value, unit, sub, cls = '') => `<div class="net-st-tile ${cls}"><div class="kpi-label">${label}</div>
    <div class="net-st-val">${value}<span class="net-st-unit">${unit}</span></div><div class="kpi-sub">${sub}</div></div>`;
  const low = (v, m) => m > 0 && v != null && v < m ? ' low' : '';
  const s = d.settings;
  const engine = s.engine === 'ookla' ? 'Speedtest CLI (Ookla)' : 'Cloudflare';
  let status;
  if (d.running) status = '<span class="net-spin"></span> Testing now — this takes about half a minute…';
  else if (d.requested) status = '<span class="net-spin"></span> Starting within a minute…';
  else if (d.nextDue) status = `Next scheduled test in ${netIn(d.nextDue - Date.now())} · ${netEvery(s.interval_min)} · ${engine}`;
  else status = `Scheduled tests are off${IS_ADMIN ? ' — set a schedule with Schedule…' : ''} · ${engine}`;
  const fresh = l && !l.error;
  body.innerHTML = `
    <div class="net-st-grid">
      <div class="net-st-tiles">
        ${tile('Download', fresh ? netMbps(l.down) : '—', 'Mbit/s', ok.length ? `avg ${netMbps(avg('down'))} · low ${netMbps(min('down'))}` : '', 'down' + (fresh ? low(l.down, s.min_down_mbps) : ''))}
        ${tile('Upload', fresh ? netMbps(l.up) : '—', 'Mbit/s', ok.length ? `avg ${netMbps(avg('up'))} · low ${netMbps(min('up'))}` : '', 'up' + (fresh ? low(l.up, s.min_up_mbps) : ''))}
        ${tile('Ping', fresh && l.ping != null ? netMs(l.ping) : '—', 'ms', fresh && l.jitter != null ? `jitter ${netMs(l.jitter)} ms${l.loss ? ` · ${l.loss}% lost` : ''}` : '', 'ping')}
      </div>
      <div class="net-st-chart">${ok.length ? '<canvas id="net-speed-canvas"></canvas>' : '<div class="muted small net-st-nochart">No results in this range yet.</div>'}</div>
    </div>
    <div class="net-st-meta small">
      <span>${status}</span>
      ${l ? `<span class="muted">Last test ${fmtAgo(l.ts)}${l.error ? ` — <span class="bad">failed: ${escapeHtml(l.error)}</span>` : `${l.server ? ` · ${escapeHtml(l.server)}` : ''}${l.isp ? ` · ${escapeHtml(l.isp)}` : ''}${l.ip ? ` · ${escapeHtml(l.ip)}` : ''}${l.bytes ? ` · used ${fmtTrafficBytes(l.bytes)}` : ''}${l.url ? ` · <a href="${escapeHtml(l.url)}" target="_blank" rel="noopener">result</a>` : ''}`}</span>` : ''}
    </div>
    ${d.results.length ? `<details class="net-st-list"><summary>All tests in this range (${d.results.length})</summary>
      <div class="details-table-wrap"><table class="switch-info-table"><thead><tr><th>When</th><th class="num">Download</th><th class="num">Upload</th><th class="num">Ping</th><th class="num">Jitter</th><th>Server</th><th></th></tr></thead>
      <tbody>${[...d.results].reverse().map(r => `<tr><td>${new Date(r.ts).toLocaleString([], { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' })}</td>
        ${r.error ? `<td colspan="5" class="bad">${escapeHtml(r.error)}</td>` : `<td class="num">${netMbps(r.down)}</td><td class="num">${netMbps(r.up)}</td><td class="num">${netMs(r.ping)} ms</td><td class="num">${netMs(r.jitter)} ms</td><td>${escapeHtml(r.server || '')}</td>`}
        <td class="muted small">${r.manual ? 'manual' : ''}</td></tr>`).join('')}</tbody></table></div></details>` : ''}`;
  if (netSpeedChart) { netSpeedChart.destroy(); netSpeedChart = null; }
  const c = document.getElementById('net-speed-canvas');
  if (c) {
    const o = netChartOptions(netSpeedDays * 86400);
    o.scales.y.ticks.callback = v => `${v} Mb`;
    o.scales.y2 = { position: 'right', beginAtZero: true, grid: { display: false }, ticks: { color: cssVar('--muted'), maxTicksLimit: 5, callback: v => `${v} ms` } };
    o.scales.x.min = Math.floor(Date.now() / 1000) - netSpeedDays * 86400;
    o.scales.x.max = Math.floor(Date.now() / 1000);
    o.plugins.tooltip.callbacks.label = c2 => `${c2.dataset.label}: ${c2.dataset.yAxisID === 'y2' ? `${netMs(c2.raw.y)} ms` : `${netMbps(c2.raw.y)} Mbit/s`}`;
    const pts = k => ok.map(r => ({ x: Math.round(r.ts / 1000), y: r[k] }));
    const ds = (label, color, k, extra = {}) => ({ label, data: pts(k), borderColor: color, backgroundColor: color, borderWidth: 1.8,
      pointRadius: ok.length > 60 ? 0 : 2.5, tension: 0.2, ...extra });
    netSpeedChart = new Chart(c, { type: 'line', data: { datasets: [
      ds('Download', NET_IN, 'down'), ds('Upload', NET_OUT, 'up'),
      ds('Ping', '#f59e0b', 'ping', { yAxisID: 'y2', borderDash: [4, 4], borderWidth: 1.4 }),
    ] }, options: o });
  }
}

// ---- Speed test settings (admins)

const netSpeedBackdrop = document.getElementById('netspeed-backdrop');
function closeSpeedSettings() { netSpeedBackdrop.classList.add('hidden'); }
document.getElementById('netspeed-close').addEventListener('click', closeSpeedSettings);
netSpeedBackdrop.addEventListener('click', e => { if (e.target === netSpeedBackdrop) closeSpeedSettings(); });
document.addEventListener('keydown', e => { if (e.key === 'Escape' && !netSpeedBackdrop.classList.contains('hidden')) closeSpeedSettings(); });

document.getElementById('net-speed-settings').addEventListener('click', () => {
  if (!netSpeed) return;
  const s = netSpeed.settings;
  const lastBytes = netSpeed.latest?.bytes;
  const form = document.getElementById('netspeed-form');
  form.innerHTML = `
    <label>Run a test <select name="interval_min">${netSpeed.intervals.map(m => `<option value="${m}"${m === s.interval_min ? ' selected' : ''}>${m ? netEvery(m).replace(/^./, c => c.toUpperCase()) : 'Never (only when I press Run now)'}</option>`).join('')}</select></label>
    <div class="field-label">Test with</div>
    <label class="check"><input type="radio" name="engine" value="cloudflare"${s.engine !== 'ookla' ? ' checked' : ''} /> Cloudflare (built in; download, upload and latency to speed.cloudflare.com)</label>
    <label class="check"><input type="radio" name="engine" value="ookla"${s.engine === 'ookla' ? ' checked' : ''}${netSpeed.engines.ookla ? '' : ' disabled'} />
      Speedtest CLI by Ookla (speedtest.net servers)${netSpeed.engines.ookla ? '' : ' — not installed on the server'}</label>
    <p class="hint">${netSpeed.engines.ookla ? 'Using the Speedtest CLI accepts Ookla\'s licence and privacy terms, and results are shared with speedtest.net.'
      : 'To use speedtest.net, install Ookla\'s official Speedtest CLI on the server (see speedtest.net/apps/cli; the package is called <code>speedtest</code>), then pick it here.'}</p>
    <div class="form-grid">
      <label>Parallel connections <input type="number" name="streams" min="1" max="16" value="${s.streams}" /></label>
      <label>Seconds per direction <input type="number" name="seconds" min="4" max="20" value="${s.seconds}" /></label>
    </div>
    <div class="field-label">Alert when the last two tests are slower than</div>
    <div class="form-grid">
      <label>Download <span class="num-unit"><input type="number" name="min_down_mbps" min="0" value="${s.min_down_mbps}" /> Mbit/s</span></label>
      <label>Upload <span class="num-unit"><input type="number" name="min_up_mbps" min="0" value="${s.min_up_mbps}" /> Mbit/s</span></label>
    </div>
    <p class="hint">0 turns the alert off. Alerts go to the channels in Settings → Notifications.</p>
    <label>Keep results for <span class="num-unit"><input type="number" name="keep_days" min="7" max="3650" value="${s.keep_days}" /> days</span></label>
    <p class="hint" id="netspeed-usage"></p>
    <div class="form-row"><button type="submit" class="primary-btn">Save</button><span class="form-msg" id="netspeed-msg"></span></div>`;
  const usage = () => {
    const m = +form.interval_min.value;
    const el = document.getElementById('netspeed-usage');
    el.textContent = lastBytes && m ? `Each test moved about ${fmtTrafficBytes(lastBytes)} last time, so this schedule uses about ${fmtTrafficBytes(lastBytes * 1440 / m)} a day — keep it in mind on a capped plan.`
      : 'A test moves as much data as the line can carry for the seconds above in each direction (hundreds of MB on a fast line).';
  };
  form.interval_min.addEventListener('change', usage);
  usage();
  form.onsubmit = async e => {
    e.preventDefault();
    const msg = document.getElementById('netspeed-msg');
    const body = { interval_min: +form.interval_min.value, engine: form.engine.value, streams: +form.streams.value, seconds: +form.seconds.value,
      min_down_mbps: +form.min_down_mbps.value, min_up_mbps: +form.min_up_mbps.value, keep_days: +form.keep_days.value };
    try {
      const resp = await api('api/network.php?action=speedtest-settings', { method: 'PUT', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) });
      const d = await resp.json();
      if (!resp.ok) throw new Error(d.error || 'Could not save');
      closeSpeedSettings();
      loadSpeedtests();
    } catch (err) {
      msg.textContent = err.message;
      msg.className = 'form-msg err';
    }
  };
  netSpeedBackdrop.classList.remove('hidden');
});

window.matchMedia('(prefers-color-scheme: light)').addEventListener('change', () => { if (netVisible) { renderSpeedtests(); renderNetPortDetail(); } });
window.addEventListener('resize', () => { if (netVisible && netLayout) netFit(); });

if (location.hash === '#network') showView('network');
