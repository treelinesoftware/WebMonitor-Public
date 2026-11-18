const POLL_INTERVAL_MS = 5000;

const OS_ICONS = {
  windows: '🪟',
  macos: '🍎',
  linux: '🐧',
  other: '❓',
};

const DEVICE_ICONS = {
  server: '🗄️',
  desktop: '🖥️',
  laptop: '💻',
  mobile: '📱',
  router: '📡',
  switch: '🔀',
  ap: '📶',
  printer: '🖨️',
  camera: '📷',
  nas: '💾',
  ups: '🔋',
  iot: '💡',
  console: '🎮',
  tv: '📺',
};

// Line icons for the cards (24px grid, drawn with the current text colour).
const DEVICE_SVG = {
  server: '<rect x="4" y="3" width="16" height="7" rx="1.5"/><rect x="4" y="14" width="16" height="7" rx="1.5"/><path d="M8 6.5h.01M8 17.5h.01M12 6.5h4M12 17.5h4"/>',
  desktop: '<rect x="3" y="4" width="18" height="12" rx="1.5"/><path d="M8 20h8M12 16v4"/>',
  laptop: '<rect x="5" y="5" width="14" height="10" rx="1.5"/><path d="M2.5 19h19l-2-4h-15z"/>',
  mobile: '<rect x="7" y="2.5" width="10" height="19" rx="2"/><path d="M11 18.5h2"/>',
  router: '<rect x="3" y="13" width="18" height="7" rx="1.5"/><path d="M7 16.5h.01M11 16.5h.01M17 13V9M8.5 8.5a5 5 0 0 1 7 0M6 6a8.5 8.5 0 0 1 12 0"/>',
  switch: '<rect x="2.5" y="7" width="19" height="10" rx="1.5"/><path d="M6 11h1M9 11h1M12 11h1M15 11h1M18 11h.01M6 14h12"/>',
  ap: '<path d="M12 20h.01M8.5 16.5a5 5 0 0 1 7 0M5.5 13.5a9 9 0 0 1 13 0M2.5 10.5a13 13 0 0 1 19 0"/>',
  printer: '<path d="M7 9V3h10v6"/><rect x="3" y="9" width="18" height="8" rx="1.5"/><path d="M7 14h10v7H7z"/>',
  camera: '<path d="M3 8a2 2 0 0 1 2-2h2l1.5-2h7L17 6h2a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><circle cx="12" cy="13" r="3.5"/>',
  nas: '<rect x="5" y="2.5" width="14" height="19" rx="1.5"/><path d="M8.5 7h7M8.5 11h7M8.5 15h7M12 18.5h.01"/>',
  ups: '<rect x="3" y="7" width="16" height="10" rx="1.5"/><path d="M21 10.5v3M11.5 9l-2.5 3.5h3L9.5 15"/>',
  iot: '<path d="M9 18h6M10 21h4M12 3a6 6 0 0 0-3.5 10.9V16h7v-2.1A6 6 0 0 0 12 3z"/>',
  console: '<path d="M6.5 7h11a4 4 0 0 1 4 4.4l-.5 4.6a2.6 2.6 0 0 1-4.6 1.4L15 16H9l-1.4 1.4A2.6 2.6 0 0 1 3 16l-.5-4.6A4 4 0 0 1 6.5 7z"/><path d="M7.5 10v4M5.5 12h4M15.5 11h.01M17.5 13h.01"/>',
  tv: '<rect x="2.5" y="5" width="19" height="13" rx="1.5"/><path d="M8 21h8M9 2l3 3 3-3"/>',
};
const OS_SHORT = { windows: 'Windows', macos: 'macOS', linux: 'Linux' };
function svgIcon(paths, cls = 'line-icon') {
  return `<svg class="${cls}" viewBox="0 0 24 24" aria-hidden="true">${paths}</svg>`;
}
/** Icon tile on a card: the maker's or Proxmox mark when there is one, else the device type's line icon. */
function cardIcon(host) {
  const mark = deviceIcon(host);   // topology.js
  if (mark && mark.startsWith('<svg')) return mark;
  return svgIcon(DEVICE_SVG[host.device] || DEVICE_SVG.server);
}
/** Status badge on a card: Up 8 ms, Down, Down 3 days, Muted, Pending. */
function statusBadge(host) {
  const age = downAge(host);
  if (host.mutedUntil) return `<span class="status-pill muted">Muted</span>`;
  if (host.status === 'up') return `<span class="status-pill up">Up${host.lastMs != null ? `<span class="pill-ms">${host.lastMs} ms</span>` : ''}</span>`;
  if (host.status === 'down') {
    return `<span class="status-pill down${age && age !== 'recent' ? ` down-${age}` : ''}">Down${age && age !== 'recent' && host.lastSeenAt ? `<span class="pill-ms">${fmtDownFor(host)}</span>` : ''}</span>`;
  }
  return `<span class="status-pill pending">Pending</span>`;
}

const CSRF_TOKEN = document.querySelector('meta[name="csrf-token"]').content;
// Admin-only controls are hidden for viewers; the server enforces it too.
const IS_ADMIN = document.querySelector('meta[name="user-role"]').content === 'admin';

const headerClock = document.getElementById('header-clock');
const clockFormatter = new Intl.DateTimeFormat([], {
  year: 'numeric', month: 'short', day: 'numeric',
  hour: '2-digit', minute: '2-digit', second: '2-digit', timeZoneName: 'short',
});
function updateHeaderClock() {
  const now = new Date();
  headerClock.dateTime = now.toISOString();
  headerClock.textContent = `Local: ${clockFormatter.format(now)}`;
}
updateHeaderClock();
setInterval(updateHeaderClock, 1000);

// fetch() for the app's API: sends the CSRF token on writes and sends the
// browser back to the login page if the session has expired.
async function api(url, opts = {}) {
  const method = (opts.method || 'GET').toUpperCase();
  const headers = { ...(opts.headers || {}) };
  if (method !== 'GET') headers['X-CSRF-Token'] = CSRF_TOKEN;
  const resp = await fetch(url, { ...opts, headers, credentials: 'same-origin' });
  if (resp.status === 401) {
    window.location.href = 'login.php';
    throw new Error('Session expired');
  }
  return resp;
}

document.getElementById('logout-btn').addEventListener('click', async () => {
  try { await api('api/logout.php', { method: 'POST' }); } catch (e) { /* redirect below either way */ }
  window.location.href = 'login.php';
});

const grid = document.getElementById('grid');
const addForm = document.getElementById('add-form');
const modalBackdrop = document.getElementById('modal-backdrop');
const modalTitle = document.getElementById('modal-title');
const modalMeta = document.getElementById('modal-meta');
const modalCanvas = document.getElementById('modal-chart');

let hostsById = new Map();
let openHostId = null;
let chart = null;
let activeTab = 'all';
// Subnet tab in the top bar ('' = All). Remembered in the URL hash (#net=192.168.10.0/24).
let subnetNames = {};
try { subnetNames = JSON.parse(document.querySelector('meta[name="subnet-names"]')?.content || '{}'); } catch (e) { /* none */ }
let locationNames = {};   // id -> name (Settings -> Locations)
try { locationNames = JSON.parse(document.querySelector('meta[name="locations"]')?.content || '{}'); } catch (e) { /* none */ }
let subnetGateways = {};
try { subnetGateways = JSON.parse(document.querySelector('meta[name="subnet-gateways"]')?.content || '{}'); } catch (e) { /* none */ }
const isGateway = h => subnetGateways[subnetOf(h.ip)] === h.ip;
let activeSubnet = location.hash.startsWith('#net=') ? decodeURIComponent(location.hash.slice(5)) : '';
// id -> { running: bool, result?: {...}, error?: string, at: ms }; kept outside
// the cards because they're rebuilt on every poll.
const pingState = new Map();
const PING_RESULT_SHOW_MS = 30000;

function cssVar(name) {
  return getComputedStyle(document.documentElement).getPropertyValue(name).trim();
}

// The chart bakes in colors when created; rebuild it after a theme change.
function refreshChartTheme() {
  if (chart) {
    chart.destroy();
    chart = null;
    updateModal();
  }
  if (typeof historyHostId !== 'undefined' && historyHostId) loadHistory(historyHostId);   // history.js
}
window.matchMedia('(prefers-color-scheme: light)').addEventListener('change', refreshChartTheme);

// Far-future end time used for "muted until I unmute".
const MUTE_FOREVER_MS = Date.UTC(2100, 0, 1);

function fmtMuteUntil(ts) {
  if (ts >= MUTE_FOREVER_MS - 86400000) return 'until unmuted';
  const d = new Date(ts);
  const sameDay = d.toDateString() === new Date().toDateString();
  return 'until ' + (sameDay ? d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })
    : d.toLocaleString([], { weekday: 'short', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' }));
}

function fmtTime(ts) {
  if (!ts) return 'never';
  return new Date(ts).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', second: '2-digit' });
}

function subnetOf(ip) {
  const parts = ip.split('.');
  return parts.length === 4 ? `${parts[0]}.${parts[1]}.${parts[2]}.0/24` : ip;
}

/** "Servers · 192.168.10.0/24" as HTML (the address muted), or just the address. */
function subnetLabel(subnet) {
  const name = subnetNames[subnet];
  return name ? `<span class="subnet-name">${escapeHtml(name)}</span> <span class="subnet-ip">${escapeHtml(subnet)}</span>`
    : escapeHtml(subnet);
}

function ipSortKey(ip) {
  const parts = ip.split('.').map(n => parseInt(n, 10) || 0);
  return ((parts[0] || 0) * 2 ** 24) + ((parts[1] || 0) * 2 ** 16) + ((parts[2] || 0) * 2 ** 8) + (parts[3] || 0);
}

document.querySelectorAll('#host-tabs .tab-btn').forEach(btn => {
  btn.addEventListener('click', () => {
    activeTab = btn.dataset.tab;
    document.querySelectorAll('#host-tabs .tab-btn').forEach(b => b.classList.toggle('active', b === btn));
    render();
  });
});

/** One top-bar tab per subnet, between All and Updates, with host and down counts. */
function renderSubnetTabs() {
  const nav = document.getElementById('view-tabs');
  const all = [...hostsById.values()];
  const subnets = [...new Set(all.map(h => subnetOf(h.ip)))].sort((a, b) => ipSortKey(a) - ipSortKey(b));
  if (activeSubnet && !subnets.includes(activeSubnet) && all.length) activeSubnet = '';
  const current = [...nav.querySelectorAll('.subnet-btn')].map(b => b.dataset.subnet);
  if (current.join() !== subnets.join()) {
    nav.querySelectorAll('.subnet-btn').forEach(b => b.remove());
    for (const s of subnets) {
      const b = document.createElement('button');
      b.type = 'button';
      b.className = 'view-btn subnet-btn';
      b.dataset.view = 'hosts';
      b.dataset.subnet = s;
      b.addEventListener('click', () => showView('hosts', s));   // updates.js
      nav.appendChild(b);
    }
  }
  // Short tabs: the subnet's name (or address) and host count; the address and
  // down count are in the tooltip, with a red dot only when something is down.
  const fill = (b, label, hs, title) => {
    const downs = hs.filter(h => h.status === 'down' && !h.mutedUntil).map(downAge);
    const fresh = downs.filter(a => a === 'recent').length;
    const days = downs.filter(a => a === 'day').length;
    const weeks = downs.filter(a => a === 'week').length;
    b.innerHTML = `${escapeHtml(label)}<span class="tab-count">${hs.length}</span>`
      + (fresh ? `<span class="tab-down">${fresh}</span>` : '') + (days ? `<span class="tab-down tab-down-day">${days}</span>` : '');
    const parts = [fresh && `${fresh} down`, days && `${days} down 1-7 days`, weeks && `${weeks} down over a week`].filter(Boolean);
    b.title = `${title} — ${hs.length} host${hs.length === 1 ? '' : 's'}${parts.length ? ', ' + parts.join(', ') : ''}`;
  };
  nav.querySelectorAll('.subnet-btn').forEach(b => {
    const s = b.dataset.subnet;
    fill(b, subnetNames[s] || s.replace(/\.0\/24$/, '.x'), all.filter(h => subnetOf(h.ip) === s), subnetNames[s] ? `${subnetNames[s]} · ${s}` : s);
  });
  fill(nav.querySelector('[data-subnet=""]'), 'All', all, 'All hosts');
  if (!document.getElementById('hosts-view').classList.contains('hidden')) {
    nav.querySelectorAll('[data-view="hosts"]').forEach(b => b.classList.toggle('active', b.dataset.subnet === activeSubnet));
  }
}

// Search box above the cards: matches name, IP, maker, model, OS, location and agent OS.
let searchText = '';
const searchInput = document.getElementById('host-search');
searchInput.addEventListener('input', () => { searchText = searchInput.value.trim().toLowerCase(); render(); });
searchInput.addEventListener('keydown', e => { if (e.key === 'Escape') { searchInput.value = ''; searchText = ''; render(); } });
document.addEventListener('keydown', e => {
  if (e.key === '/' && !e.target.closest('input, textarea, select, [contenteditable]') && !document.getElementById('hosts-view').classList.contains('hidden')) {
    e.preventDefault();
    searchInput.focus();
  }
});
function matchesSearch(h) {
  if (!searchText) return true;
  const hay = [h.name, h.ip, h.vendor, h.device, DEVICE_LABELS[h.device], OS_LABELS[h.os], h.locationId && locationNames[h.locationId],
    h.agent && h.agent.os, h.deviceInfo && JSON.stringify(h.deviceInfo)].filter(Boolean).join(' ').toLowerCase();
  return searchText.split(/\s+/).every(t => hay.includes(t));
}

/** Summary tiles above the subnet tabs, for the subnet being shown. */
function renderKpis(hosts) {
  const el = document.getElementById('kpis');
  if (!el) return;
  const live = hosts.filter(h => !h.mutedUntil);
  const up = live.filter(h => h.status === 'up').length;
  const downs = live.filter(h => h.status === 'down');
  const fresh = downs.filter(h => downAge(h) === 'recent').length;
  const ids = new Set(hosts.map(h => h.id));
  const alerts = openAlerts.filter(a => !activeSubnet || ids.has(a.hostId));
  const crit = alerts.filter(a => a.severity === 'critical').length;
  const agents = hosts.filter(h => h.agent);
  const withUpdates = agents.filter(h => h.agent.updates > 0);
  const security = agents.reduce((n, h) => n + (h.agent.securityUpdates || 0), 0);
  // Vulnerability counts (vulns.js), for this subnet's devices when one is picked.
  let sec = typeof secData !== 'undefined' && secData ? secData.summary : null;
  if (sec && activeSubnet) {
    sec = { critical: 0, high: 0 };
    for (const vh of secData.hosts) {
      if (subnetOf(vh.ip) === activeSubnet) { sec.critical += vh.counts.critical || 0; sec.high += vh.counts.high || 0; }
    }
  }
  const pct = live.length ? Math.round(up / live.length * 1000) / 10 : null;
  const tile = (key, label, value, sub, tone = '', action = '') =>
    `<button type="button" class="kpi ${tone}" data-kpi="${key}"${action ? ` title="${escapeHtml(action)}"` : ''}>
      <span class="kpi-label">${label}</span><span class="kpi-value">${value}</span><span class="kpi-sub">${sub}</span></button>`;
  el.innerHTML = [
    tile('all', activeSubnet ? escapeHtml(subnetNames[activeSubnet] || activeSubnet) : 'Monitored', hosts.length,
      `${agents.length} with agent${hosts.length - live.length ? ` · ${hosts.length - live.length} muted` : ''}`, '', 'Show all hosts'),
    tile('up', 'Online', up, pct != null ? `${pct}% of active hosts` : '—', 'good', 'Show hosts that are up'),
    tile('down', 'Down', fresh, downs.length > fresh ? `+${downs.length - fresh} down over a day` : fresh ? 'needs attention' : 'all clear',
      fresh ? 'bad' : '', 'Show hosts that are down'),
    tile('alerts', 'Active alerts', alerts.length, crit ? `${crit} critical` : alerts.length ? 'warnings only' : 'none open',
      crit ? 'bad' : alerts.length ? 'warn' : '', 'Show the alert list'),
    tile('updates', 'Pending updates', withUpdates.length, withUpdates.length ? `hosts · ${security} security` : 'all agents up to date',
      security ? 'warn' : '', 'Open Updates'),
    tile('security', 'Vulnerabilities', sec ? sec.critical + sec.high : '—', sec ? `${sec.critical} critical · ${sec.high} high` : 'no scan yet',
      sec && sec.critical ? 'bad' : sec && sec.high ? 'warn' : '', 'Open the Security report'),
  ].join('');
}
document.getElementById('kpis').addEventListener('click', e => {
  const k = e.target.closest('.kpi')?.dataset.kpi;
  if (!k) return;
  if (k === 'updates' || k === 'security') { showView(k); return; }   // updates.js
  if (k === 'alerts') {
    alertsBar.classList.remove('collapsed');
    alertsBar.scrollIntoView({ behavior: 'smooth', block: 'start' });
    return;
  }
  document.querySelector(`#host-tabs .tab-btn[data-tab="${k}"]`)?.click();
});

function render() {
  renderSubnetTabs();
  const inSubnet = [...hostsById.values()].filter(h => !activeSubnet || subnetOf(h.ip) === activeSubnet);
  renderKpis(inSubnet);
  const hosts = inSubnet.filter(matchesSearch);

  const counts = { all: hosts.length, up: 0, down: 0 };
  for (const h of hosts) {
    if (h.status === 'up') counts.up++;
    else if (h.status === 'down') counts.down++;
  }
  for (const tab of ['all', 'up', 'down']) {
    const el = document.getElementById(`count-${tab}`);
    if (el) el.textContent = counts[tab];
  }

  if (!hosts.length) {
    grid.innerHTML = searchText ? `<div class="empty">Nothing matches “${escapeHtml(searchInput.value.trim())}”.</div>` : activeSubnet ? `<div class="empty">No hosts in ${escapeHtml(activeSubnet)}.</div>`
      : '<div class="empty">No hosts yet — add one in Settings → Add hosts to start monitoring.</div>';
    return;
  }

  const filtered = activeTab === 'all' ? hosts : hosts.filter(h => h.status === activeTab);
  if (!filtered.length) {
    grid.innerHTML = `<div class="empty">No ${escapeHtml(activeTab)} hosts.</div>`;
    return;
  }

  if (groupMode === 'host') {   // topology.js
    renderByHost(filtered);
    return;
  }
  if (groupMode === 'location') {
    renderByLocation(filtered);
    return;
  }

  grid.innerHTML = '';
  renderSubnetSections(filtered);
}

/**
 * One section per subnet. A subnet's gateway (Settings -> Subnets) is shown
 * as a chip in the heading instead of as a card.
 */
function renderSubnetSections(hosts, prefix = '') {
  const groups = new Map();
  for (const host of hosts) {
    const key = subnetOf(host.ip);
    if (!groups.has(key)) groups.set(key, []);
    groups.get(key).push(host);
  }
  for (const key of [...groups.keys()].sort((a, b) => ipSortKey(a) - ipSortKey(b))) {
    const groupHosts = groups.get(key).filter(h => !isGateway(h)).sort((a, b) => ipSortKey(a.ip) - ipSortKey(b.ip));

    const section = document.createElement('div');
    section.className = 'network-group';
    section.innerHTML = `<h3 class="network-group-title">${prefix}${subnetLabel(key)} <span class="network-group-count">${groupHosts.length}</span>${gatewayChip(key)}</h3>`;

    const inner = document.createElement('div');
    inner.className = 'grid';
    for (const host of groupHosts) inner.appendChild(renderCard(host));
    if (!groupHosts.length) inner.innerHTML = '<div class="muted small">No other hosts to show.</div>';
    section.appendChild(inner);

    grid.appendChild(section);
  }
}

/** Heading chip for a subnet's gateway: status, name, response time. */
function gatewayChip(subnet) {
  const ip = subnetGateways[subnet];
  if (!ip) return '';
  const gw = [...hostsById.values()].find(h => h.ip === ip);
  if (!gw) {
    return `<span class="gw-chip" title="Not monitored: add ${escapeHtml(ip)} as a host to check it">Gateway ${escapeHtml(ip)}</span>`;
  }
  const state = gw.mutedUntil ? 'muted' : gw.status;
  const detail = gw.status === 'down' ? 'down' : gw.lastMs != null ? `${gw.lastMs} ms` : '';
  const gwAge = downAge(gw);
  return `<button type="button" class="gw-chip ${gw.status === 'down' && !gw.mutedUntil ? (gwAge === 'recent' ? 'is-down' : `is-down-${gwAge}`) : ''}" data-host="${gw.id}"
      title="Gateway — open details${gw.mutedUntil ? ' (muted)' : ''}">
    <span class="status-dot ${statusClass(gw)}"></span>Gateway ${escapeHtml(ip)}${gw.name !== gw.ip ? ` · ${escapeHtml(gw.name)}` : ''}${detail ? ` · ${escapeHtml(detail)}` : ''}${state === 'muted' ? ' · 🔕' : ''}
  </button>`;
}

grid.addEventListener('click', e => {
  const chip = e.target.closest('.gw-chip[data-host]');
  if (chip) openModal(Number(chip.dataset.host));
});

// How long a down host has been gone, from when it was last seen (a successful
// check, the ARP table or a scan): red for under a day, orange for 1-7 days,
// grey after a week, so long-gone devices stop shouting like fresh outages.
const DAY_MS = 86400000;
function downAge(h) {
  if (h.status !== 'down') return null;
  const age = h.lastSeenAt ? Date.now() - h.lastSeenAt : 0;
  return age > 7 * DAY_MS ? 'week' : age > DAY_MS ? 'day' : 'recent';
}

/** Class for a status dot: up / down / pending, plus down-day or down-week. */
function statusClass(h) {
  const a = downAge(h);
  return a && a !== 'recent' ? `down down-${a}` : h.status;
}

function fmtDownFor(h) {
  const ms = Date.now() - h.lastSeenAt;
  const days = Math.floor(ms / DAY_MS);
  return days >= 1 ? `${days} day${days === 1 ? '' : 's'}` : `${Math.max(1, Math.floor(ms / 3600000))} h`;
}

/** Host view grouped by location (Settings -> Locations), then cards with no location. */
function renderByLocation(hosts) {
  grid.innerHTML = '';
  const groups = new Map();
  for (const h of hosts) {
    const name = h.locationId && locationNames[h.locationId] ? locationNames[h.locationId] : '';
    if (!groups.has(name)) groups.set(name, []);
    groups.get(name).push(h);
  }
  const names = [...groups.keys()].filter(n => n).sort((a, b) => a.localeCompare(b));
  if (groups.has('')) names.push('');
  for (const name of names) {
    const list = groups.get(name).sort((a, b) => ipSortKey(a.ip) - ipSortKey(b.ip));
    const section = document.createElement('div');
    section.className = 'network-group';
    section.innerHTML = `<h3 class="network-group-title">${name ? `📍 ${escapeHtml(name)}`
      : `No location <span class="muted small">— set one in a card's Edit${IS_ADMIN && !Object.keys(locationNames).length ? ' (add locations in Settings → Locations)' : ''}</span>`}
      <span class="network-group-count">${list.length}</span></h3>`;
    const inner = document.createElement('div');
    inner.className = 'grid';
    for (const h of list) inner.appendChild(renderCard(h));
    section.appendChild(inner);
    grid.appendChild(section);
  }
}

function renderCard(host) {
  const card = document.createElement('div');
  const alertSev = host.mutedUntil ? null : worstAlertFor(host.id);
  const age = downAge(host);
  card.className = 'card' + (alertSev ? ` alert-${alertSev}` : '') + (host.mutedUntil ? ' is-muted' : '')
    + (age && age !== 'recent' ? ` down-${age}` : '');
  card.dataset.id = host.id;
  const flag = cardFlagColor(host);   // topology.js: tie guests/containers to their host
  if (flag) {
    card.classList.add('flagged');
    card.style.setProperty('--flag', flag);
  }

  const bars = host.history.slice(-30).map(
    h => `<div class="bar ${h.up ? 'up' : 'down'}" title="${fmtTime(h.ts)} — ${h.up ? 'up' : 'down'}"></div>`
  ).join('');

  card.innerHTML = `
    <div class="card-top">
      <span class="card-icon ${statusClass(host)}" title="${escapeHtml(DEVICE_LABELS[host.device] || host.device || '')}">${cardIcon(host)}</span>
      <div class="card-title">
        <span class="card-name${host.name === host.ip ? ' unnamed' : ''}">${host.name === host.ip ? 'Unnamed' : escapeHtml(host.name)}</span>
        <div class="card-ip"><span class="mono">${escapeHtml(host.ip)}</span>${OS_SHORT[host.os] ? `<span class="card-os">${OS_SHORT[host.os]}</span>` : ''}${host.locationId && locationNames[host.locationId] ? `<span class="card-loc">${escapeHtml(locationNames[host.locationId])}</span>` : ''}</div>
      </div>
      <span${age && age !== 'recent' && host.lastSeenAt ? ` title="Last seen ${escapeHtml(new Date(host.lastSeenAt).toLocaleString())}"` : ''}>${statusBadge(host)}</span>
    </div>
    ${host.mutedUntil ? `<div class="card-muted" title="${escapeHtml(host.muteReason || '')}">🔕 Muted ${escapeHtml(fmtMuteUntil(host.mutedUntil))}${host.muteReason ? ' · ' + escapeHtml(host.muteReason) : ''}</div>` : ''}
    ${renderDuplicateFlag(host)}
    ${renderCardFlags(host)}
    ${renderCardNas(host.nas)}
    ${renderCardPorts(host)}
    ${renderCardWifi(host)}
    ${renderCardDeviceInfo(host)}
    ${renderCardAgent(host.agent)}
    ${renderCardAntivirus(host.agent)}
    ${renderCardServices(host.services)}
    <div class="card-spacer"></div>
    <div class="uptime-bar">${bars || '<span class="muted small">waiting for first check…</span>'}</div>
    <div class="card-footer">
      <span title="Last check">Checked ${fmtTime(host.lastCheck)}</span>
      <span class="pct" title="Uptime over the history shown">${host.uptimePct != null ? host.uptimePct + '%' : '—'}<span class="muted"> uptime</span></span>
    </div>
    <div class="card-actions">
      <button class="card-btn ping-btn" type="button" title="Check reachability now (TCP ports 80, 443, 22, 445, 3389)">Ping</button>
      <button class="card-btn details-btn" type="button" title="Show server details collected over SNMP">Details</button>
      ${IS_ADMIN ? '<button class="card-btn asset-scan-btn" type="button" title="Request an asset scan">Scan now</button>' : ''}
      <span class="ping-result"></span>
    </div>
  `;

  renderPingState(card, host.id);

  card.querySelector('.ping-btn').addEventListener('click', e => {
    e.stopPropagation();
    pingHost(host.id);
  });
  card.querySelector('.asset-scan-btn')?.addEventListener('click', e => {
    e.stopPropagation();
    requestAssetScan({ ip: host.ip }, e.currentTarget);
  });

  card.querySelector('.card-ports')?.addEventListener('click', e => {
    e.stopPropagation();
    openModal(host.id, { scrollToPorts: true });
  });
  card.querySelector('.card-wifi')?.addEventListener('click', e => {
    e.stopPropagation();
    openModal(host.id, { scrollToWifi: true });
  });

  card.querySelector('.details-btn').addEventListener('click', e => {
    e.stopPropagation();
    openModal(host.id, { scrollToDetails: true });
  });

  card.addEventListener('click', () => openModal(host.id));
  return card;
}

/** Flags a card when another card watches the same IP (a host added twice). */
function renderDuplicateFlag(host) {
  const twins = [...hostsById.values()].filter(o => o.id !== host.id && o.ip === host.ip);
  if (!twins.length) return '';
  return `<div class="card-flag warn" style="--flag:var(--pending)" title="Open Details and use Delete to remove the copy you don't need">
    Duplicate: same IP as ${twins.map(o => `"${escapeHtml(o.name)}"`).join(', ')}</div>`;
}

async function deleteHost(id) {
  const host = hostsById.get(id);
  if (!host) return;
  // Agent hosts: fetch the uninstall command first; the agent row goes with the host.
  let uninstall = null;
  if (host.agent) {
    const r = await api(`api/host-agent.php?id=${id}`).catch(() => null);
    if (r && r.ok) uninstall = (await r.json().catch(() => ({}))).uninstallCommand || null;
  }
  const what = 'its check results, history graphs, alerts and update jobs' + (host.agent ? ', and its agent registration' : '');
  if (!confirm(`Delete "${host.name}" (${host.ip})?\n\nThis removes the card along with ${what}. It can't be undone.`)) return;
  const resp = await api(`api/host.php?id=${id}`, { method: 'DELETE' });
  if (!resp.ok && resp.status !== 404) {
    alert('Could not delete the host');
    return;
  }
  hostsById.delete(id);
  closeModal();
  render();
  if (uninstall) {
    prompt('Host deleted. Its agent can no longer report. If the machine still exists, uninstall the agent there with:', uninstall);
  }
}

document.getElementById('delete-host-btn').addEventListener('click', () => {
  if (openHostId != null) deleteHost(openHostId);
});

function renderCardAgent(a) {
  if (!a) return '';
  const bits = [a.online ? 'Agent' : 'Agent offline'];
  if (a.updates != null) {
    bits.push(a.updates === 0 ? 'up to date'
      : `${a.updates} update${a.updates === 1 ? '' : 's'}${a.securityUpdates ? ` (${a.securityUpdates} security)` : ''}`);
  }
  if (a.rebootRequired) bits.push('reboot needed');
  const warn = a.securityUpdates || a.rebootRequired;
  return `<div class="card-agent ${a.online ? 'online' : 'offline'}${warn ? ' warn' : ''}"
    title="${escapeHtml(a.os || '')}">${escapeHtml(bits.join(' · '))}</div>`;
}

// Monitored services (Services tab): "Services 5/5 up", or what's down.
function renderCardServices(s) {
  if (!s || !s.total) return '';
  const icon = svgIcon('<rect x="3" y="4" width="18" height="6" rx="1.5"/><rect x="3" y="14" width="18" height="6" rx="1.5"/><path d="M7 7h.01M7 17h.01"/>', 'mini-icon');
  if (s.down) {
    const names = s.downNames.slice(0, 2).join(', ') + (s.downNames.length > 2 ? ` +${s.downNames.length - 2}` : '');
    return `<div class="card-svc down" title="${escapeHtml(s.downNames.join(', '))}">${icon}${s.down} service${s.down === 1 ? '' : 's'} down: ${escapeHtml(names)}</div>`;
  }
  const tone = s.warn ? 'warn' : s.up === s.total ? 'ok' : 'other';
  return `<div class="card-svc ${tone}">${icon}Services ${s.up + s.warn}/${s.total} up${s.warn ? ` · ${s.warn} warning` : ''}</div>`;
}

// Antivirus state from the agent (agents 0.4+ on Windows, 0.5+ on Linux).
function renderCardAntivirus(a) {
  const av = a && a.antivirus;
  if (!av) return '';
  const tip = av.products && av.products.length ? `Antivirus: ${av.products.join(', ')}` : 'No antivirus product found';
  return `<div class="card-av ${av.state}${a.online ? '' : ' stale'}" title="${escapeHtml(tip)}">${svgIcon('<path d="M12 3 4.5 6v5.5c0 4.6 3.2 8.3 7.5 9.5 4.3-1.2 7.5-4.9 7.5-9.5V6z"/>', 'mini-icon')}${escapeHtml(av.text)}</div>`;
}

function renderPingState(card, id) {
  const btn = card.querySelector('.ping-btn');
  const out = card.querySelector('.ping-result');
  const st = pingState.get(id);
  btn.disabled = !!(st && st.running);
  btn.textContent = st && st.running ? 'Pinging…' : 'Ping';
  out.className = 'ping-result';
  out.textContent = '';
  if (!st || st.running || Date.now() - st.at > PING_RESULT_SHOW_MS) return;

  if (st.error) {
    out.classList.add('down');
    out.textContent = st.error;
  } else if (st.result.up) {
    out.classList.add('up');
    out.textContent = `Up · ${st.result.ms} ms${st.result.port ? ` · port ${st.result.port}` : ''}`;
  } else {
    out.classList.add('down');
    out.textContent = `No response (${st.result.timeoutSec}s)`;
  }
}

function refreshCardPing(id) {
  const card = grid.querySelector(`.card[data-id="${id}"]`);
  if (card) renderPingState(card, id);
}

async function pingHost(id) {
  if (pingState.get(id)?.running) return;
  pingState.set(id, { running: true, at: Date.now() });
  refreshCardPing(id);
  try {
    const resp = await api(`api/ping.php?id=${id}`, { method: 'POST' });
    const data = await resp.json();
    pingState.set(id, resp.ok
      ? { running: false, result: data, at: Date.now() }
      : { running: false, error: data.error || 'Ping failed', at: Date.now() });
  } catch (err) {
    pingState.set(id, { running: false, error: 'Ping failed', at: Date.now() });
  }
  refreshCardPing(id);
  setTimeout(() => refreshCardPing(id), PING_RESULT_SHOW_MS + 100);
  loadHosts().catch(() => {}); // pull in the newly recorded check
}

function escapeHtml(str) {
  const d = document.createElement('div');
  d.textContent = str;
  return d.innerHTML;
}

let switchInfoLoadedFor = null;
let detailsLoadedFor = null;

function openModal(id, { scrollToDetails = false, scrollToPorts = false, scrollToWifi = false } = {}) {
  openHostId = id;
  document.getElementById('host-edit').classList.add('hidden');
  muteBoxKey = null;
  switchInfoLoadedFor = null;
  detailsLoadedFor = null;
  wifiLoadedFor = null;   // wireless.js
  stopDeviceInfoSection();   // devices.js
  modalBackdrop.classList.remove('hidden');
  updateModal();
  startHistory(id);   // history.js
  if (scrollToDetails) {
    document.getElementById('agent-section').scrollIntoView({ block: 'start' });
  }
  if (scrollToPorts) document.getElementById('switch-info').scrollIntoView({ block: 'start' });
  if (scrollToWifi) document.getElementById('wifi-section').scrollIntoView({ block: 'start' });
}

function closeModal() {
  openHostId = null;
  switchInfoLoadedFor = null;
  detailsLoadedFor = null;
  stopAgentRefresh();
  stopHistory();   // history.js
  stopConfigSection();   // configbackup.js
  wifiLoadedFor = null;   // wireless.js
  stopDeviceInfoSection();   // devices.js
  modalBackdrop.classList.add('hidden');
  if (chart) { chart.destroy(); chart = null; }
}

function updateModal() {
  if (!openHostId) return;
  const host = hostsById.get(openHostId);
  if (!host) return closeModal();

  modalTitle.innerHTML = `<span class="card-icon ${statusClass(host)}">${cardIcon(host)}</span> ${escapeHtml(host.name)}`;
  const wl = document.getElementById('workload-section');
  const wlHtml = renderWorkloadDetails(host);   // topology.js
  if (wl.dataset.html !== wlHtml) {
    wl.dataset.html = wlHtml;
    wl.innerHTML = wlHtml;
    wl.classList.toggle('hidden', !wlHtml);
    wireWorkloadDetails(wl);
  }
  modalMeta.textContent = `${host.ip} — uptime ${host.uptimePct != null ? host.uptimePct + '%' : '—'}`;
  renderMuteBox(host);
  if (typeof renderHostServices === 'function') renderHostServices(host.id);   // services.js

  const labels = host.history.map(h => fmtTime(h.ts));
  const latencies = host.history.map(h => (h.up ? h.ms : null));
  const pointColors = host.history.map(h => (h.up ? cssVar('--up') : cssVar('--down')));

  if (!chart) {
    chart = new Chart(modalCanvas, {
      type: 'line',
      data: {
        labels,
        datasets: [{
          label: 'Ping time (ms)',
          data: latencies,
          borderColor: cssVar('--accent'),
          backgroundColor: cssVar('--accent-soft'),
          pointBackgroundColor: pointColors,
          pointBorderColor: pointColors,
          pointRadius: 4,
          spanGaps: true,
          tension: 0.25,
        }],
      },
      options: {
        responsive: true,
        scales: {
          x: { ticks: { color: cssVar('--muted') }, grid: { color: cssVar('--panel-border') } },
          y: { ticks: { color: cssVar('--muted') }, grid: { color: cssVar('--panel-border') }, beginAtZero: true },
        },
        plugins: { legend: { labels: { color: cssVar('--text') } } },
      },
    });
  } else {
    chart.data.labels = labels;
    chart.data.datasets[0].data = latencies;
    chart.data.datasets[0].pointBackgroundColor = pointColors;
    chart.data.datasets[0].pointBorderColor = pointColors;
    chart.update();
  }

  if (detailsLoadedFor !== host.id) {
    detailsLoadedFor = host.id;
    loadAgent(host.id, { first: true });
  }

  const switchInfoEl = document.getElementById('switch-info');
  document.querySelector('#modal-backdrop .modal').classList.toggle('modal-wide', ['switch', 'router', 'ap', 'iot', 'console', 'tv', 'camera'].includes(host.device) || !!host.wifi);
  if (host.device === 'switch' || host.device === 'router') {
    switchInfoEl.classList.remove('hidden');
    if (switchInfoLoadedFor !== host.id) {
      switchInfoLoadedFor = host.id;
      loadPortMap(host.id);   // portmap.js
    }
  } else {
    switchInfoEl.classList.add('hidden');
  }
  showConfigSection(host);   // configbackup.js
  showWifiSection(host);     // wireless.js
  showDeviceInfoSection(host);   // devices.js
}

// Re-rendered only when the mute state changes, so polling doesn't wipe
// what someone is typing into the reason field.
let muteBoxKey = null;

function renderMuteBox(host) {
  const box = document.getElementById('mute-box');
  const key = `${host.id}|${host.mutedUntil || ''}|${host.muteReason || ''}`;
  if (key === muteBoxKey) return;
  muteBoxKey = key;

  if (host.mutedUntil) {
    box.className = 'mute-box active';
    box.innerHTML = `<span>🔕 <strong>Alerts muted</strong> ${escapeHtml(fmtMuteUntil(host.mutedUntil))}${
      host.muteReason ? ' — ' + escapeHtml(host.muteReason) : ''}</span>
      ${IS_ADMIN ? '<button type="button" class="section-btn" id="unmute-btn">Unmute</button>' : ''}`;
    document.getElementById('unmute-btn')?.addEventListener('click', () => setMute(host.id, null, ''));
    return;
  }
  if (!IS_ADMIN) {
    box.className = 'mute-box hidden';
    box.innerHTML = '';
    return;
  }
  box.className = 'mute-box';
  box.innerHTML = `
    <span>🔔 Mute alerts for</span>
    <select id="mute-duration">
      <option value="3600000">1 hour</option>
      <option value="14400000">4 hours</option>
      <option value="86400000">1 day</option>
      <option value="604800000">1 week</option>
      <option value="forever">until I unmute</option>
    </select>
    <input type="text" id="mute-reason" maxlength="120" placeholder="Reason (optional), e.g. maintenance" />
    <button type="button" class="section-btn" id="mute-btn">Mute</button>`;
  document.getElementById('mute-btn').addEventListener('click', () => {
    const d = document.getElementById('mute-duration').value;
    const until = d === 'forever' ? MUTE_FOREVER_MS : Date.now() + Number(d);
    setMute(host.id, until, document.getElementById('mute-reason').value.trim());
  });
}

async function setMute(id, mutedUntil, muteReason) {
  const resp = await api(`api/host.php?id=${id}`, {
    method: 'PATCH',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ mutedUntil, muteReason }),
  });
  const data = await resp.json().catch(() => ({}));
  if (!resp.ok) {
    alert(data.error || 'Could not change the mute');
    return;
  }
  const host = hostsById.get(id);
  if (host) {
    host.mutedUntil = data.mutedUntil;
    host.muteReason = data.mutedUntil ? (muteReason || null) : null;
  }
  // The server closes the host's open alerts on its next run; hide them now.
  if (mutedUntil) openAlerts = openAlerts.filter(a => a.hostId !== id);
  renderAlertsBar();
  render();
  updateModal();
}

// ---- Server details (SNMP) ----

const detailsBody = document.getElementById('host-details-body');
const detailsAge = document.getElementById('host-details-age');
const detailsRefreshBtn = document.getElementById('host-details-refresh');

// Shows the stored details right away, then queries SNMP if there are none yet.
async function loadHostDetails(id) {
  detailsBody.innerHTML = '<div class="switch-info-status">Loading…</div>';
  detailsAge.textContent = '';
  try {
    const resp = await api(`api/host-details.php?id=${id}`);
    const data = await resp.json();
    if (openHostId !== id) return;
    if (resp.ok && data.details) {
      renderHostDetails(data);
      return;
    }
  } catch (err) {
    if (openHostId !== id) return;
  }
  collectHostDetails(id);
}

async function collectHostDetails(id) {
  detailsRefreshBtn.disabled = true;
  detailsRefreshBtn.textContent = 'Querying…';
  const hadDetails = !!detailsBody.querySelector('.details-grid');
  if (!hadDetails) detailsBody.innerHTML = '<div class="switch-info-status">Querying SNMP…</div>';
  try {
    const resp = await api(`api/host-details.php?id=${id}`, { method: 'POST' });
    const data = await resp.json();
    if (openHostId !== id) return;
    if (data.details) renderHostDetails(data);
    if (!resp.ok) {
      const note = data.details ? ' Showing the last details collected.' : '';
      showDetailsError((data.error || 'SNMP query failed.') + note, !!data.details);
    }
  } catch (err) {
    if (openHostId !== id) return;
    showDetailsError(`Failed to query: ${err.message}`, hadDetails);
  } finally {
    detailsRefreshBtn.disabled = false;
    detailsRefreshBtn.textContent = 'Refresh';
  }
}

function showDetailsError(msg, keepContent) {
  const html = `<div class="switch-info-status details-error">${escapeHtml(msg)}</div>`;
  if (keepContent) detailsBody.insertAdjacentHTML('afterbegin', html);
  else detailsBody.innerHTML = html;
}

detailsRefreshBtn.addEventListener('click', () => {
  if (openHostId) collectHostDetails(openHostId);
});

function fmtDuration(sec) {
  const d = Math.floor(sec / 86400);
  const h = Math.floor((sec % 86400) / 3600);
  const m = Math.floor((sec % 3600) / 60);
  if (d) return `${d}d ${h}h ${m}m`;
  if (h) return `${h}h ${m}m`;
  return `${m}m`;
}

function fmtBytes(bytes) {
  const units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
  let i = 0;
  while (bytes >= 1024 && i < units.length - 1) { bytes /= 1024; i++; }
  return `${bytes.toFixed(bytes >= 100 || i === 0 ? 0 : 1)} ${units[i]}`;
}

function fmtSpeed(mbps) {
  return mbps >= 1000 ? `${mbps / 1000} Gbps` : `${mbps} Mbps`;
}

function fmtAgo(ts) {
  const sec = Math.max(0, Math.round((Date.now() - ts) / 1000));
  if (sec < 60) return 'just now';
  if (sec < 3600) return `${Math.floor(sec / 60)} min ago`;
  if (sec < 86400) return `${Math.floor(sec / 3600)} h ago`;
  return new Date(ts).toLocaleString();
}

function kvRows(pairs) {
  return pairs
    .filter(([, v]) => v != null && v !== '')
    .map(([k, v]) => `<dt>${escapeHtml(k)}</dt><dd>${escapeHtml(String(v))}</dd>`)
    .join('');
}

function renderHostDetails({ details: d, collectedAt }) {
  detailsAge.textContent = collectedAt ? `· collected ${fmtAgo(collectedAt)}` : '';
  detailsAge.title = collectedAt ? new Date(collectedAt).toLocaleString() : '';

  const sys = d.system || {};
  const res = d.resources || {};
  let html = renderNasDetails(d.nas);   // nas.js

  html += `<h4>System</h4><dl class="details-grid">${kvRows([
    ['Name', sys.name],
    ['Description', sys.descr ? sys.descr.split(/\r?\n/)[0] : null],
    ['Uptime', sys.uptimeSec != null ? fmtDuration(sys.uptimeSec) : null],
    ['Location', sys.location],
    ['Contact', sys.contact],
  ])}</dl>`;

  const cpuAvg = res.cpuLoad && res.cpuLoad.length
    ? Math.round(res.cpuLoad.reduce((a, b) => a + b, 0) / res.cpuLoad.length)
    : null;
  const cpu = cpuAvg == null ? null
    : res.cpuLoad.length > 1 ? `${cpuAvg}% avg across ${res.cpuLoad.length} cores` : `${cpuAvg}%`;
  const resRows = kvRows([
    ['CPU', cpu],
    ['Load average', res.loadAvg ? res.loadAvg.map(n => n.toFixed(2)).join(' / ') : null],
    ['Memory', res.memoryKb ? fmtBytes(res.memoryKb * 1024) : null],
    ['Processes', res.processes],
    ['Users logged in', res.users],
  ]);
  if (resRows) html += `<h4>Resources</h4><dl class="details-grid">${resRows}</dl>`;

  const storage = (d.storage || []).filter(s => s.type !== 'virtual' || s.sizeBytes > 0);
  if (storage.length) {
    html += '<h4>Storage</h4><div class="storage-list">' + storage.map(s => {
      const pct = s.sizeBytes ? Math.round((s.usedBytes / s.sizeBytes) * 100) : 0;
      const level = pct >= 90 ? 'crit' : pct >= 75 ? 'warn' : '';
      return `
        <div class="storage-row">
          <div class="storage-label"><span>${escapeHtml(s.descr || s.type)}</span>
            <span>${fmtBytes(s.usedBytes)} / ${fmtBytes(s.sizeBytes)} (${pct}%)</span></div>
          <div class="storage-meter"><div class="storage-fill ${level}" style="width:${pct}%"></div></div>
        </div>`;
    }).join('') + '</div>';
  }

  const net = d.network || [];
  if (net.length) {
    const rows = net.map(n => `
      <tr>
        <td>${escapeHtml(n.name)}</td>
        <td>${escapeHtml(n.ip)}${n.mask ? `<span class="muted"> / ${escapeHtml(n.mask)}</span>` : ''}</td>
        <td>${n.mac ? escapeHtml(n.mac) : '—'}</td>
        <td>${n.speedMbps ? fmtSpeed(n.speedMbps) : '—'}</td>
        <td><span class="port-status ${n.status}">${escapeHtml(n.status)}</span></td>
      </tr>`).join('');
    html += `<h4>Network</h4><div class="details-table-wrap"><table class="switch-info-table">
      <thead><tr><th>Interface</th><th>IP address</th><th>MAC</th><th>Speed</th><th>Status</th></tr></thead>
      <tbody>${rows}</tbody></table></div>`;
  }

  if (d.netSnmp && !res.memoryKb && !storage.length && !net.length) {
    html += `<div class="details-hint">This host's SNMP agent only shares basic system info. To see CPU, memory,
      disk and network details, allow more of the MIB in <code>/etc/snmp/snmpd.conf</code> on the host, e.g.
      <code>view systemonly included .1.3.6.1.2.1.25</code>, <code>view systemonly included .1.3.6.1.4.1.2021</code>,
      <code>view systemonly included .1.3.6.1.2.1.2</code> and <code>view systemonly included .1.3.6.1.2.1.4</code>,
      then restart snmpd.</div>`;
  }

  detailsBody.innerHTML = html;
}

// ---- Agent ----

const agentSection = document.getElementById('agent-section');
const agentBody = document.getElementById('agent-body');
const agentActions = document.getElementById('agent-actions');
const agentAge = document.getElementById('agent-age');
const snmpSection = document.getElementById('host-details');
let agentRefreshTimer = null;
// While the install options or an install command are on screen, refreshes
// must not replace them. Holds the enrolledAt of the agent being replaced
// (null for a first install); a different enrollment means it's done.
let agentBusy = null;

function stopAgentRefresh() {
  clearInterval(agentRefreshTimer);
  agentRefreshTimer = null;
  agentBusy = null;
}

function startAgentRefresh(id, everyMs) {
  clearInterval(agentRefreshTimer);
  agentRefreshTimer = setInterval(() => {
    if (openHostId === id) loadAgent(id);
  }, everyMs);
}

// Hosts with an agent show its data; others fall back to SNMP details.
async function loadAgent(id, { first = false } = {}) {
  if (first) {
    stopAgentRefresh();
    agentBody.innerHTML = '<div class="switch-info-status">Loading…</div>';
    agentActions.innerHTML = '';
    agentAge.textContent = '';
    snmpSection.classList.add('hidden');
  }
  let data;
  try {
    const resp = await api(`api/host-agent.php?id=${id}`);
    data = await resp.json();
    if (!resp.ok) throw new Error(data.error || 'Failed to load agent');
  } catch (err) {
    if (openHostId !== id) return;
    agentBody.innerHTML = `<div class="switch-info-status details-error">${escapeHtml(err.message)}</div>`;
    return;
  }
  if (openHostId !== id) return;

  if (agentBusy) {
    const enrolledAt = data.agent ? data.agent.enrolledAt : null;
    if (enrolledAt === agentBusy.enrolledAt) return;  // still waiting
    agentBusy = null;
  }
  if (data.agent) {
    snmpSection.classList.add('hidden');
    renderAgent(id, data.agent);
    startAgentRefresh(id, 30000);
    return;
  }
  renderNoAgent(id);
  if (first) {
    snmpSection.classList.remove('hidden');
    loadHostDetails(id);
  }
}

function renderNoAgent(id) {
  const host = hostsById.get(id);
  agentAge.textContent = '';
  agentActions.innerHTML = '';
  if (host && host.device === 'nas') {
    agentBody.innerHTML = `<div class="switch-info-status">NAS: monitored over SNMP, no agent needed. Health is below
      under Server Details${host.nas ? '' : ' once SNMP answers (check the SNMP community with Edit above)'}.</div>`;
    return;
  }
  if (!IS_ADMIN) {
    agentBody.innerHTML = '<div class="switch-info-status">No agent installed on this host.</div>';
    return;
  }
  agentBody.innerHTML = `
    <div class="switch-info-status">No agent installed. The WebMonitor agent reports CPU, memory, disk,
      network, inventory and pending updates from Linux and Windows servers.</div>`;
  renderInstallOptions(id, { platform: host && host.os === 'windows' ? 'windows' : 'linux', append: true });
}

// Platform choice, "remove other agents" options and the Install button.
// Used for first installs and for reinstalling over an existing agent.
function renderInstallOptions(id, { platform, append = false, replacing = null } = {}) {
  const html = `
    ${replacing ? `<p class="agent-note">Reinstalling creates a new install command. The current agent keeps
      reporting until the new one is installed; then its old token stops working.</p>` : ''}
    <div class="agent-option">Platform:
      <label><input type="radio" name="agent-platform" value="linux" ${platform === 'windows' ? '' : 'checked'} /> Linux</label>
      <label><input type="radio" name="agent-platform" value="windows" ${platform === 'windows' ? 'checked' : ''} /> Windows</label>
    </div>
    <label class="agent-option"><input type="checkbox" id="agent-remove-zabbix" checked />
      Remove the Zabbix agent during install</label>
    <label class="agent-option"><input type="checkbox" id="agent-remove-checkmk" checked />
      Remove the Checkmk agent during install</label>
    <div class="agent-cmd-actions">
      <button type="button" class="section-btn" id="agent-install-btn">${replacing ? 'Create reinstall command' : 'Install agent…'}</button>
      ${replacing ? '<button type="button" class="section-btn" id="agent-options-cancel">Cancel</button>' : ''}
    </div>`;
  if (append) agentBody.insertAdjacentHTML('beforeend', html);
  else agentBody.innerHTML = html;

  document.getElementById('agent-install-btn').addEventListener('click', () => {
    const chosen = agentBody.querySelector('input[name="agent-platform"]:checked')?.value || 'linux';
    createInstallCommand(id, chosen);
  });
  document.getElementById('agent-options-cancel')?.addEventListener('click', () => {
    agentBusy = null;
    loadAgent(id);
  });
}

async function createInstallCommand(id, platform) {
  const removeZabbix = document.getElementById('agent-remove-zabbix')?.checked ?? false;
  const removeCheckmk = document.getElementById('agent-remove-checkmk')?.checked ?? false;
  const host = hostsById.get(id);
  let data;
  try {
    const resp = await api(`api/host-agent.php?id=${id}`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ platform, removeZabbix, removeCheckmk }),
    });
    data = await resp.json();
    if (!resp.ok) throw new Error(data.error || 'Failed to create install command');
  } catch (err) {
    alert(err.message);
    return;
  }
  if (openHostId !== id) return;
  if (!agentBusy) agentBusy = { enrolledAt: null };
  agentActions.innerHTML = '';
  agentBody.innerHTML = `
    <p class="agent-note">${data.platform === 'windows'
      ? `On <strong>${escapeHtml(host ? host.ip : 'the host')}</strong>, open <strong>Windows PowerShell as Administrator</strong> and paste:`
      : `Run this on <strong>${escapeHtml(host ? host.ip : 'the host')}</strong> as root or with sudo:`}</p>
    <pre class="install-cmd" id="agent-cmd">${escapeHtml(data.command)}</pre>
    <div class="agent-cmd-actions">
      <button type="button" class="section-btn" id="agent-copy-btn">Copy</button>
      <button type="button" class="section-btn" id="agent-cancel-btn">Done</button>
    </div>
    <p class="agent-note muted">Single use, valid until ${escapeHtml(new Date(data.expiresAt).toLocaleString())}.
      The agent only connects out to this server over HTTPS (it trusts this server's certificate by its
      fingerprint); no ports are opened on the host. This panel updates once the agent checks in.</p>`;
  document.getElementById('agent-copy-btn').addEventListener('click', e => copyText(data.command, e.target));
  document.getElementById('agent-cancel-btn').addEventListener('click', () => {
    agentBusy = null;
    loadAgent(id);
  });
  startAgentRefresh(id, 5000);
}

async function copyText(text, btn) {
  try {
    await navigator.clipboard.writeText(text);
  } catch (e) {
    // Fallback for browsers that block the async clipboard API.
    const ta = document.createElement('textarea');
    ta.value = text;
    document.body.appendChild(ta);
    ta.select();
    document.execCommand('copy');
    ta.remove();
  }
  const label = btn.textContent;
  btn.textContent = 'Copied';
  setTimeout(() => { btn.textContent = label; }, 1500);
}

async function removeAgent(id, uninstallCommand) {
  const host = hostsById.get(id);
  if (!confirm(`Remove the agent from "${host ? host.name : 'this host'}"? It will stop reporting immediately.`)) return;
  const resp = await api(`api/host-agent.php?id=${id}`, { method: 'DELETE' });
  if (!resp.ok) {
    alert('Failed to remove the agent');
    return;
  }
  stopAgentRefresh();
  renderNoAgent(id);
  agentBody.insertAdjacentHTML('afterbegin', `
    <p class="agent-note">Agent removed from WebMonitor. To uninstall it from the host as well, run there:</p>
    <pre class="install-cmd">${escapeHtml(uninstallCommand)}</pre>`);
  snmpSection.classList.remove('hidden');
  loadHostDetails(id);
  loadHosts().catch(() => {});
}

function meter(pct) {
  const level = pct >= 90 ? 'crit' : pct >= 75 ? 'warn' : '';
  return `<div class="storage-meter"><div class="storage-fill ${level}" style="width:${Math.min(100, pct)}%"></div></div>`;
}

function meterRow(label, detail, pct) {
  return `
    <div class="storage-row">
      <div class="storage-label"><span>${escapeHtml(label)}</span><span>${escapeHtml(detail)}</span></div>
      ${meter(pct)}
    </div>`;
}

function fmtRate(bytesPerSec) {
  if (bytesPerSec == null) return '—';
  const bits = bytesPerSec * 8;
  if (bits >= 1e9) return `${(bits / 1e9).toFixed(1)} Gb/s`;
  if (bits >= 1e6) return `${(bits / 1e6).toFixed(1)} Mb/s`;
  if (bits >= 1e3) return `${(bits / 1e3).toFixed(0)} kb/s`;
  return `${Math.round(bits)} b/s`;
}

function renderAgent(id, a) {
  const m = a.metrics || {};
  const inv = a.inventory || {};
  const hw = inv.hardware || {};
  const os = inv.os || {};

  agentAge.textContent = a.lastSeen ? `· last report ${fmtAgo(a.lastSeen)}` : '';
  agentAge.title = a.lastSeen ? new Date(a.lastSeen).toLocaleString() : '';
  agentActions.innerHTML = !IS_ADMIN ? '' : `
    <button type="button" class="section-btn" id="agent-reinstall-btn" title="Reinstall or update the agent, optionally removing Zabbix/Checkmk">Reinstall…</button>
    <button type="button" class="section-btn danger" id="agent-remove-btn">Remove</button>`;
  document.getElementById('agent-reinstall-btn')?.addEventListener('click', () => {
    agentBusy = { enrolledAt: a.enrolledAt };
    agentActions.innerHTML = '';
    renderInstallOptions(id, { platform: a.platform || 'linux', replacing: a.enrolledAt });
  });
  document.getElementById('agent-remove-btn')?.addEventListener('click', () => removeAgent(id, a.uninstallCommand));

  let html = `<div class="agent-status ${a.online ? 'online' : 'offline'}">
    <span class="status-dot ${a.online ? 'up' : 'down'}"></span>
    ${a.online ? 'Online' : 'Offline — no report for ' + escapeHtml(a.lastSeen ? fmtAgo(a.lastSeen) : 'a while')}
    <span class="muted">· v${escapeHtml(a.version || '?')}${a.hostname ? ' · ' + escapeHtml(a.hostname) : ''}</span>
  </div>`;

  if (!a.metrics && !a.inventory) {
    agentBody.innerHTML = html + '<div class="switch-info-status">Enrolled — waiting for the first report…</div>';
    return;
  }

  const hwLabel = [hw.vendor, hw.model].filter(Boolean).join(' ');
  html += `<h4>System</h4><dl class="details-grid">${kvRows([
    ['OS', os.pretty_name],
    ['Kernel', inv.kernel ? `${inv.kernel}${inv.arch ? ' (' + inv.arch + ')' : ''}` : null],
    ['Hardware', hwLabel || null],
    ['CPU', hw.cpu_model ? `${hw.cpu_model} × ${hw.cpu_count}` : null],
    ['Virtualization', hw.virtualization && hw.virtualization !== 'none' ? hw.virtualization : null],
    ['Serial', hw.serial],
    ['Uptime', m.uptime_sec != null ? fmtDuration(m.uptime_sec) : null],
    ['Packages', inv.package_count != null ? `${inv.package_count} installed` : null],
  ])}</dl>`;

  // Resources
  const mem = m.memory || {};
  let res = '';
  if (m.cpu_pct != null) res += meterRow('CPU', `${m.cpu_pct}%${m.cpu_count ? ' of ' + m.cpu_count + ' cores' : ''}`, m.cpu_pct);
  if (mem.total) res += meterRow('Memory', `${fmtBytes(mem.used)} / ${fmtBytes(mem.total)} (${Math.round(mem.used / mem.total * 100)}%)`, mem.used / mem.total * 100);
  if (mem.swap_total) res += meterRow('Swap', `${fmtBytes(mem.swap_used)} / ${fmtBytes(mem.swap_total)}`, mem.swap_used / mem.swap_total * 100);
  const extra = kvRows([
    ['Load average', m.load ? m.load.map(n => n.toFixed(2)).join(' / ') : null],
    ['Processes', m.processes],
  ]);
  if (res || extra) html += `<h4>Resources</h4><div class="storage-list">${res}</div><dl class="details-grid agent-extra">${extra}</dl>`;

  if (m.disks && m.disks.length) {
    html += '<h4>Storage</h4><div class="storage-list">' + m.disks.map(d =>
      meterRow(`${d.mount} (${d.fstype})`, `${fmtBytes(d.used)} / ${fmtBytes(d.used + d.available)} (${d.used_pct}%)`, d.used_pct || 0)
    ).join('') + '</div>';
  }

  if (m.network && m.network.length) {
    const rows = m.network.map(n => `
      <tr>
        <td>${escapeHtml(n.name)}</td>
        <td>${n.addrs && n.addrs.length ? n.addrs.map(escapeHtml).join('<br>') : '—'}</td>
        <td>${n.mac ? escapeHtml(n.mac) : '—'}</td>
        <td>${n.speed_mbps ? fmtSpeed(n.speed_mbps) : '—'}</td>
        <td class="nowrap">↓ ${fmtRate(n.rx_rate)}<br>↑ ${fmtRate(n.tx_rate)}</td>
      </tr>`).join('');
    html += `<h4>Network</h4><div class="details-table-wrap"><table class="switch-info-table">
      <thead><tr><th>Interface</th><th>Addresses</th><th>MAC</th><th>Speed</th><th>Traffic</th></tr></thead>
      <tbody>${rows}</tbody></table></div>`;
  }

  // Updates
  const upd = inv.updates || {};
  if (upd.packages) {
    const sec = upd.packages.filter(p => p.security).length;
    const summary = upd.packages.length === 0 ? 'Up to date'
      : `${upd.packages.length} pending${sec ? `, ${sec} security` : ''}`;
    const reboot = inv.reboot_required ? '<span class="badge warn">Reboot required</span>' : '';
    html += `<h4>Updates <span class="muted">(${escapeHtml(upd.manager || '')}, checked ${a.inventoryAt ? escapeHtml(fmtAgo(a.inventoryAt)) : '—'})</span></h4>
      <div class="agent-updates ${sec ? 'has-security' : ''}">${escapeHtml(summary)} ${reboot}</div>`;
    if (upd.packages.length) {
      const sorted = [...upd.packages].sort((x, y) => (y.security - x.security) || x.name.localeCompare(y.name));
      html += `<details class="update-list"><summary>Show packages</summary><table class="switch-info-table">
        <thead><tr><th>Package</th><th>Installed</th><th>Available</th><th></th></tr></thead><tbody>
        ${sorted.map(p => `<tr><td>${escapeHtml(p.name)}</td><td>${escapeHtml(p.current || '—')}</td>
          <td>${escapeHtml(p.new || '—')}</td><td>${p.security ? '<span class="badge crit">security</span>' : ''}</td></tr>`).join('')}
        </tbody></table></details>`;
    }
  } else if (upd.error) {
    html += `<h4>Updates</h4><div class="switch-info-status details-error">${escapeHtml(upd.error)}</div>`;
  }

  if (inv.antivirus) {
    const prods = inv.antivirus.products || [];
    const yn = v => v == null ? '—' : v ? 'yes' : '<span class="badge warn">no</span>';
    html += `<h4>Antivirus</h4>` + (prods.length ? `<div class="details-table-wrap"><table class="switch-info-table">
      <thead><tr><th>Product</th><th>On</th><th>Up to date</th><th>Real-time</th><th>Signatures</th><th>Last scan</th></tr></thead>
      <tbody>${prods.map(p => `<tr><td>${escapeHtml(p.name)}${p.passive ? ' <span class="muted small">(passive)</span>' : ''}</td>
        <td>${yn(p.enabled)}</td><td>${yn(p.up_to_date)}</td><td>${yn(p.realtime)}</td>
        <td>${p.signature_age_days != null ? `${p.signature_age_days} day${p.signature_age_days === 1 ? '' : 's'} old` : '—'}</td>
        <td>${p.last_scan ? escapeHtml(fmtAgo(p.last_scan * 1000)) : '—'}</td></tr>`).join('')}</tbody></table></div>`
      : '<div class="switch-info-status">No antivirus product found.</div>')
      + (inv.antivirus.error ? `<div class="muted small">${escapeHtml(inv.antivirus.error)}</div>` : '');
  }

  if (inv.failed_services && inv.failed_services.length) {
    html += `<h4>Failed services</h4><div class="agent-failed">${inv.failed_services.map(escapeHtml).join('<br>')}</div>`;
  }

  const host = hostsById.get(id);
  if (host && host.agent) html += renderHostJobControls(host);   // updates.js

  // Keep an open package list open across refreshes.
  const wasOpen = agentBody.querySelector('.update-list')?.open;
  agentBody.innerHTML = html;
  if (wasOpen) agentBody.querySelector('.update-list').open = true;
  if (host && host.agent) wireHostJobControls(id);
}

document.getElementById('modal-close').addEventListener('click', closeModal);
modalBackdrop.addEventListener('click', e => { if (e.target === modalBackdrop) closeModal(); });

addForm.addEventListener('submit', async e => {
  e.preventDefault();
  const name = document.getElementById('name').value.trim();
  const ip = document.getElementById('ip').value.trim();
  const os = document.getElementById('os').value;
  const device = document.getElementById('device').value;
  const community = document.getElementById('community').value.trim();

  const resp = await api('api/hosts.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ name, ip, os, device, community }),
  });
  if (!resp.ok) {
    const err = await resp.json().catch(() => ({}));
    alert(err.error || 'Failed to add host');
    return;
  }
  const host = await resp.json();
  hostsById.set(host.id, host);
  render();
  addForm.reset();
  document.getElementById('os').value = 'other';
  document.getElementById('device').value = 'server';
});

const APP_VERSION = document.querySelector('meta[name="app-version"]')?.content || '';

/** After an update on the server, this page is running old code: offer a reload. */
function checkAppVersion(resp) {
  const v = resp.headers.get('X-App-Version');
  if (!v || !APP_VERSION || v === APP_VERSION || document.getElementById('update-banner')) return;
  const bar = document.createElement('div');
  bar.id = 'update-banner';
  bar.className = 'update-banner';
  bar.innerHTML = 'WebMonitor has been updated. <button type="button" class="primary-btn">Reload</button>';
  bar.querySelector('button').addEventListener('click', () => location.reload());
  document.body.prepend(bar);
}

async function loadHosts() {
  const resp = await api('api/hosts.php');
  checkAppVersion(resp);
  const hosts = await resp.json();
  hostsById = new Map(hosts.map(h => [h.id, h]));
  render();
  if (openHostId) updateModal();
  if (typeof onHostsUpdated === 'function') onHostsUpdated();   // updates.js
}

// Schedule the next poll only after the current one finishes, so slow
// responses can't stack up overlapping requests.
async function pollHosts() {
  try {
    await loadHosts();
  } catch (err) {
    console.error('Failed to load hosts', err);
  }
  setTimeout(pollHosts, POLL_INTERVAL_MS);
}

pollHosts();

// ---- Active alerts ----

const alertsBar = document.getElementById('alerts-bar');
try { if (localStorage.getItem('wm-alerts-collapsed')) alertsBar.classList.add('collapsed'); } catch (e) { /* private mode */ }
const ALERTS_POLL_MS = 15000;
const SEVERITY_RANK = { critical: 3, warning: 2, info: 1 };
let openAlerts = [];

function worstAlertFor(hostId) {
  let worst = null;
  for (const a of openAlerts) {
    if (a.hostId === hostId && (!worst || SEVERITY_RANK[a.severity] > SEVERITY_RANK[worst])) worst = a.severity;
  }
  return worst;
}

function renderAlertsBar() {
  if (!openAlerts.length) {
    alertsBar.classList.add('hidden');
    alertsBar.innerHTML = '';
    return;
  }
  const crit = openAlerts.filter(a => a.severity === 'critical').length;
  const items = openAlerts.map(a => `
    <li class="alert-item sev-${escapeHtml(a.severity)}" data-host="${a.hostId ?? ''}" data-type="${escapeHtml(a.type || '')}" tabindex="0" role="button"
        title="Open ${escapeHtml(a.hostName)}">
      <span class="sev-tag ${escapeHtml(a.severity)}">${escapeHtml(a.severity)}</span>
      <span class="alert-type">${escapeHtml(a.typeLabel || '')}</span>
      <span class="alert-msg">${escapeHtml(a.message)}</span>
      <span class="alert-since">since ${escapeHtml(fmtAgo(a.startedAt).replace(' ago', ''))}</span>
    </li>`).join('');
  const warn = openAlerts.filter(a => a.severity === 'warning').length;
  alertsBar.innerHTML = `
    <button type="button" class="alerts-head" aria-expanded="${!alertsBar.classList.contains('collapsed')}">
      ${svgIcon('<path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0zM12 9v4M12 17h.01"/>', 'alerts-icon')}
      <span class="alerts-title">${openAlerts.length} active alert${openAlerts.length === 1 ? '' : 's'}</span>
      ${crit ? `<span class="sev-tag critical">${crit} critical</span>` : ''}${warn ? `<span class="sev-tag warning">${warn} warning</span>` : ''}
      <span class="alerts-toggle">${alertsBar.classList.contains('collapsed') ? 'Show' : 'Hide'}</span>
    </button>
    <ul class="alert-list">${items}</ul>`;
  alertsBar.classList.toggle('has-critical', crit > 0);
  alertsBar.classList.remove('hidden');
  alertsBar.querySelector('.alerts-head').addEventListener('click', () => {
    alertsBar.classList.toggle('collapsed');
    try { localStorage.setItem('wm-alerts-collapsed', alertsBar.classList.contains('collapsed') ? '1' : ''); } catch (e) { /* private mode */ }
    renderAlertsBar();
  });
  alertsBar.querySelectorAll('.alert-item').forEach(li => {
    const open = () => {
      if (li.dataset.host && hostsById.has(+li.dataset.host)) openModal(+li.dataset.host);
      else if ((li.dataset.type || '').startsWith('service_')) showView('services');
      else if (li.dataset.type === 'speedtest') showView('network');   // network.js
      else if ((li.dataset.type || '').startsWith('flexlm_')) showView('flexlm');   // flexlm.js
      else { showView('reports'); showReport('traffic'); }   // a device without a card: see its traffic
    };
    li.addEventListener('click', open);
    li.addEventListener('keydown', e => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); open(); } });
  });
}

async function pollAlerts() {
  try {
    const resp = await api('api/alerts.php?open=1');
    const data = await resp.json();
    if (resp.ok) {
      openAlerts = data.open || [];
      renderAlertsBar();
      render();
    }
  } catch (err) {
    console.error('Failed to load alerts', err);
  }
  setTimeout(pollAlerts, ALERTS_POLL_MS);
}

pollAlerts();

// ---- Network scan ----

const scanForm = document.getElementById('scan-form');
const scanStatus = document.getElementById('scan-status');
const scanResults = document.getElementById('scan-results');
const scanBtn = document.getElementById('scan-btn');

let lastScanResults = [];
let lastScanCommunity = 'public';

const DEVICE_LABELS = {
  server: 'Server', desktop: 'Desktop', laptop: 'Laptop', mobile: 'Mobile',
  router: 'Router', switch: 'Switch', ap: 'Access Point', printer: 'Printer',
  camera: 'Camera', nas: 'NAS', ups: 'UPS', iot: 'IoT / smart home', console: 'Game console', tv: 'TV / streaming',
};
const OS_LABELS = { windows: 'Windows', macos: 'macOS', linux: 'Linux', other: 'Other' };

scanForm.addEventListener('submit', async e => {
  e.preventDefault();
  const networks = document.getElementById('scan-networks').value.trim();
  const snmp = document.getElementById('scan-snmp').checked;
  const community = document.getElementById('scan-community').value.trim() || 'public';
  lastScanCommunity = community;
  if (!networks) return;

  scanBtn.disabled = true;
  scanBtn.textContent = 'Scanning…';
  scanStatus.textContent = 'Checking addresses and probing SNMP — this can take a moment for larger ranges…';
  scanResults.innerHTML = '';

  try {
    const resp = await api('api/scan.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ networks, snmp, community }),
    });
    const data = await resp.json();
    if (!resp.ok) {
      scanStatus.textContent = data.error || 'Scan failed';
      return;
    }

    lastScanResults = data.results;
    let statusMsg = `Scanned ${data.scanned} address${data.scanned === 1 ? '' : 'es'} — ${data.active} active.`;
    if (data.snmpRequested && !data.snmpAvailable) {
      statusMsg += ' (SNMP was requested but this server has no SNMP support — install php-snmp or net-snmp-utils.)';
    }
    scanStatus.textContent = statusMsg;
    renderScanResults(data.results);
  } catch (err) {
    scanStatus.textContent = 'Scan failed: ' + err.message;
  } finally {
    scanBtn.disabled = false;
    scanBtn.textContent = 'Scan';
  }
});

function renderScanResults(results) {
  if (!results.length) {
    scanResults.innerHTML = '<div class="empty">No active hosts found.</div>';
    return;
  }

  const rows = results.map((r, i) => {
    const snmpText = r.snmp?.sysDescr || (r.snmp?.sysName ? r.snmp.sysName : '—');
    const disabled = r.monitored ? 'disabled' : '';
    return `
      <tr data-idx="${i}">
        <td><input type="checkbox" class="scan-pick" ${disabled} /></td>
        <td>${escapeHtml(r.ip)}</td>
        <td>${r.ms != null ? r.ms + 'ms' : '—'}${r.port != null ? ` (port ${r.port}${r.open ? ' open' : ' refused'})` : ''}</td>
        <td><input type="text" class="scan-name" value="${escapeHtml(r.snmp?.sysName || r.ip)}" ${disabled} /></td>
        <td>
          <select class="scan-os" ${disabled}>
            ${Object.entries(OS_LABELS).map(([v, l]) => `<option value="${v}" ${v === r.os ? 'selected' : ''}>${l}</option>`).join('')}
          </select>
        </td>
        <td>
          <select class="scan-device" ${disabled}>
            ${Object.entries(DEVICE_LABELS).map(([v, l]) => `<option value="${v}" ${v === r.device ? 'selected' : ''}>${l}</option>`).join('')}
          </select>
        </td>
        <td class="scan-label" title="${escapeHtml(snmpText)}">${r.monitored ? 'Already monitored' : escapeHtml(r.label || '—')}</td>
      </tr>`;
  }).join('');

  scanResults.innerHTML = `
    <table class="scan-table">
      <thead>
        <tr><th></th><th>IP</th><th>Ping</th><th>Name</th><th>OS</th><th>Device</th><th>Classification</th></tr>
      </thead>
      <tbody>${rows}</tbody>
    </table>
    <button id="scan-add-btn" type="button">+ Add selected to monitoring</button>
  `;

  document.getElementById('scan-add-btn').addEventListener('click', addSelectedScanResults);
}

async function addSelectedScanResults() {
  const rows = [...scanResults.querySelectorAll('tbody tr')];
  const toAdd = [];
  for (const row of rows) {
    const pick = row.querySelector('.scan-pick');
    if (!pick.checked || pick.disabled) continue;
    toAdd.push({
      name: row.querySelector('.scan-name').value.trim() || row.children[1].textContent,
      ip: lastScanResults[row.dataset.idx].ip,
      os: row.querySelector('.scan-os').value,
      device: row.querySelector('.scan-device').value,
      community: lastScanCommunity,
    });
  }
  if (!toAdd.length) {
    alert('Select at least one host to add.');
    return;
  }

  let added = 0, skipped = 0;
  for (const host of toAdd) {
    const resp = await api('api/hosts.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(host),
    });
    if (resp.ok) {
      const h = await resp.json();
      hostsById.set(h.id, h);
      added++;
    } else if (resp.status === 409) {
      skipped++;
    }
  }
  render();
  scanStatus.textContent = `Added ${added} host${added === 1 ? '' : 's'} to monitoring.`
    + (skipped ? ` Skipped ${skipped} already monitored.` : '');
  scanResults.innerHTML = '';
}
