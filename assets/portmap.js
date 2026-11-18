// Switch port view in host Details: a faceplate diagram (odd ports on top,
// even below, like the front of the switch) or a list, showing what each
// port is connected to. Data from api/port-map.php (includes/portmap.php).
// Uses api(), escapeHtml(), openModal(), openHostId and hostsById from app.js.

let portMap = null;
let portMapHost = null;
let portMapView = 'diagram';
let portMapSelected = null;

const PM_ROLE_LABEL = { uplink: 'Uplink', device: 'Connected', unknown: 'Link up, nothing seen', empty: 'Not connected' };

async function loadPortMap(id, { refresh = false } = {}) {
  portMapHost = id;
  portMapSelected = null;
  const body = document.getElementById('switch-info-body');
  const btn = document.getElementById('switch-info-refresh');
  if (!refresh || !portMap) body.innerHTML = '<div class="switch-info-status">Reading the switch’s ports and MAC table…</div>';
  btn.disabled = true;
  btn.textContent = refresh ? 'Refreshing…' : 'Refresh';
  try {
    const resp = await api(`api/port-map.php?host=${id}${refresh ? '&refresh=1' : ''}`, refresh ? { method: 'POST' } : {});
    const data = await resp.json();
    if (portMapHost !== id || openHostId !== id) return;
    if (!resp.ok) {
      body.innerHTML = `<div class="switch-info-status details-error">${escapeHtml(data.error || 'Could not read the ports')}</div>`;
      return;
    }
    portMap = data;
    renderPortMap();
  } catch (err) {
    if (portMapHost === id) body.innerHTML = `<div class="switch-info-status details-error">${escapeHtml(err.message)}</div>`;
  } finally {
    btn.disabled = false;
    btn.textContent = 'Refresh';
  }
}

document.getElementById('switch-info-refresh').addEventListener('click', () => {
  if (openHostId != null) loadPortMap(openHostId, { refresh: true });
});
document.querySelectorAll('[data-pmview]').forEach(b => b.addEventListener('click', () => {
  portMapView = b.dataset.pmview;
  document.querySelectorAll('[data-pmview]').forEach(x => x.classList.toggle('active', x === b));
  if (portMap) renderPortMap();
}));

function fmtPortSpeed(mbps) {
  if (!mbps) return '';
  return mbps >= 1000 ? `${mbps / 1000}G` : `${mbps}M`;
}

/** Trailing port number ("Gi1/0/12" -> 12) and the module prefix ("Gi1/0/"). */
function portParts(name) {
  const m = name.match(/^(.*?)(\d+)$/);
  return m ? { prefix: m[1], num: Number(m[2]) } : { prefix: name, num: 0 };
}

function deviceLabel(d) {
  if (d.host) return d.host.name;
  return d.ip || d.mac;
}

/** One line: what's on the other end of a port. */
function portSummary(p) {
  if (p.status === 'disabled') return 'Disabled';
  if (p.status !== 'up') return '';
  const n = p.neighbors.find(x => x.host) || p.neighbors[0];
  const into = (p.pluggedInto || [])[0];
  if (into && !n) {
    const others = p.devices.filter(d => !d.host || d.host.id !== into.host.id).length;
    return `plugged into ${into.host.name} ${into.port}${others && p.role !== 'uplink' ? ` · ${others} device${others === 1 ? '' : 's'}` : ''}`;
  }
  if (p.role === 'uplink') {
    const to = n ? (n.host ? n.host.name : n.name) : (p.towards || []).map(h => h.name).join(', ');
    return `${to ? `to ${to}` : 'to another switch'}${n && n.port ? ` (${n.port})` : ''} · ${p.macCount} device${p.macCount === 1 ? '' : 's'} beyond`;
  }
  if (n && !p.devices.length) return n.host ? n.host.name : n.name;
  if (!p.devices.length) return '';
  const first = deviceLabel(p.devices[0]);
  return p.devices.length > 1 ? `${first} +${p.devices.length - 1} more` : first;
}

function portTooltip(p) {
  const bits = [p.name];
  if (p.description && p.description !== p.name) bits.push(p.description);
  bits.push(`${PM_ROLE_LABEL[p.role] || p.status}${p.speedMbps && p.status === 'up' ? ' · ' + fmtPortSpeed(p.speedMbps) : ''}`);
  bits.push(p.trunk ? 'Trunk' : p.vlan != null ? `VLAN ${p.vlan}` : '');
  if (p.lag) bits.push(`Member of ${p.lag}`);
  if (p.ips && p.ips.length) bits.push(p.ips.join(', '));
  if (p.subs && p.subs.length) bits.push(`${p.subs.length} VLAN sub-interface${p.subs.length === 1 ? '' : 's'}`);
  const s = portSummary(p);
  if (s) bits.push(s);
  return bits.filter(Boolean).join('\n');
}

function renderPortMap() {
  const body = document.getElementById('switch-info-body');
  const age = document.getElementById('portmap-age');
  age.textContent = portMap.collectedAt ? `· read ${fmtAgoShort(portMap.collectedAt)}` : '';
  if (!portMap.ports.length) {
    body.innerHTML = '<div class="switch-info-status">The switch reported no front-panel ports.</div>';
    return;
  }
  const counts = portMap.ports.reduce((c, p) => (c[p.role] = (c[p.role] || 0) + 1, c), {});
  const summary = `<div class="pm-summary muted small">${portMap.ports.length} ports · ${(counts.device || 0) + (counts.unknown || 0)} in use ·
    ${counts.uplink || 0} uplink${counts.uplink === 1 ? '' : 's'} · ${counts.empty || 0} free</div>`;
  body.innerHTML = summary + (portMapView === 'diagram' ? renderFaceplate() : renderPortList()) + '<div id="pm-detail"></div>';
  body.querySelectorAll('[data-port]').forEach(el => el.addEventListener('click', () => selectPort(Number(el.dataset.port))));
  if (portMapSelected != null) selectPort(portMapSelected);
}

function fmtAgoShort(ms) {
  const min = Math.round((Date.now() - ms) / 60000);
  return min < 1 ? 'just now' : min < 60 ? `${min} min ago` : `${Math.round(min / 60)} h ago`;
}

/** Ports grouped by module, odd numbers on the top row and even below. */
function renderFaceplate() {
  const groups = new Map();
  for (const p of portMap.ports) {
    const { prefix, num } = portParts(p.name);
    if (!groups.has(prefix)) groups.set(prefix, []);
    groups.get(prefix).push({ ...p, num });
  }
  const html = [...groups.entries()].map(([prefix, ports]) => {
    ports.sort((a, b) => a.num - b.num);
    const cols = Math.ceil(ports.length / 2);
    const cells = ports.map((p, i) => {
      // Column/row: pairs (1,2), (3,4)... stacked; odd count fills the top row first.
      const col = Math.floor(i / 2) + 1;
      const row = i % 2 + 1;
      const badge = p.role === 'uplink' ? '<span class="pm-badge pm-up-arrow">▲</span>'
        : p.devices.length > 1 ? `<span class="pm-badge">${p.devices.length > 99 ? '99+' : p.devices.length}</span>` : '';
      return `<button type="button" class="pm-port pm-${p.role}${p.status === 'disabled' ? ' pm-disabled' : ''}${p.trunk ? ' pm-trunk' : ''}"
          style="grid-column:${col};grid-row:${row}" data-port="${p.ifIndex}" title="${escapeHtml(portTooltip(p))}">
          <span class="pm-num">${p.num}</span>${badge}
          ${p.status === 'up' && p.speedMbps >= 10000 ? '<span class="pm-speed">10G</span>' : ''}
        </button>`;
    }).join('');
    return `<div class="pm-module">
        <div class="pm-module-name muted small">${escapeHtml(prefix.replace(/[\/-]$/, '') || 'Ports')}</div>
        <div class="pm-grid" style="grid-template-columns:repeat(${cols}, var(--pm-cell))">${cells}</div>
      </div>`;
  }).join('');
  return `<div class="pm-faceplate">${html}</div>
    <div class="pm-legend small muted">
      <span><i class="pm-key pm-device"></i>Connected</span><span><i class="pm-key pm-uplink"></i>Uplink to a switch</span>
      <span><i class="pm-key pm-unknown"></i>Link up, nothing seen</span><span><i class="pm-key pm-empty"></i>Free</span>
      <span><i class="pm-key pm-empty pm-disabled"></i>Disabled</span><span>Number = devices on the port · click a port for details</span>
    </div>`;
}

function renderPortList() {
  const rows = portMap.ports.map(p => `
    <tr data-port="${p.ifIndex}" class="pm-row pm-row-${p.role}">
      <td><strong>${escapeHtml(p.name)}</strong>${p.description && p.description !== p.name ? `<div class="muted small">${escapeHtml(p.description)}</div>` : ''}</td>
      <td><span class="port-status ${p.status === 'up' ? 'up' : p.status === 'disabled' ? 'down' : 'other'}">${escapeHtml(p.status)}</span>
        ${p.status === 'up' && p.speedMbps ? `<span class="muted small">${fmtPortSpeed(p.speedMbps)}</span>` : ''}</td>
      <td>${p.trunk ? 'trunk' : p.vlan != null ? vlanLabel(p.vlan) : '—'}${p.lag ? `<div class="muted small">${escapeHtml(p.lag)}</div>` : ''}</td>
      <td>${escapeHtml(portSummary(p)) || '<span class="muted">—</span>'}</td>
    </tr>`).join('');
  return `<div class="details-table-wrap"><table class="switch-info-table pm-table">
    <thead><tr><th>Port</th><th>Status</th><th>VLAN</th><th>Connected to</th></tr></thead><tbody>${rows}</tbody></table></div>`;
}

function vlanLabel(v) {
  const name = portMap.vlans && portMap.vlans[v];
  return name && name !== `VLAN${String(v).padStart(4, '0')}` ? `${v} <span class="muted small">${escapeHtml(name)}</span>` : String(v);
}

function hostLink(h) {
  return `<button type="button" class="link-btn" data-open-host="${h.id}">${escapeHtml(h.name)}</button>`;
}

function selectPort(ifIndex) {
  const p = portMap.ports.find(x => x.ifIndex === ifIndex);
  const box = document.getElementById('pm-detail');
  if (!p || !box) return;
  portMapSelected = ifIndex;
  document.querySelectorAll('[data-port]').forEach(el => el.classList.toggle('pm-selected', Number(el.dataset.port) === ifIndex));

  const facts = [
    `<span class="port-status ${p.status === 'up' ? 'up' : 'other'}">${escapeHtml(PM_ROLE_LABEL[p.role] || p.status)}</span>`,
    p.status === 'up' && p.speedMbps ? fmtPortSpeed(p.speedMbps) : '',
    p.trunk ? 'Trunk' : p.vlan != null ? `VLAN ${vlanLabel(p.vlan)}` : '',
    p.lag ? `Member of ${escapeHtml(p.lag)}` : '',
    p.ips && p.ips.length ? escapeHtml(p.ips.join(', ')) : '',
    p.mac ? `<span class="mono">${escapeHtml(p.mac)}</span>` : '',
  ].filter(Boolean).join(' · ');

  let html = `<div class="pm-detail-head"><strong>${escapeHtml(p.name)}</strong>
      ${p.description && p.description !== p.name ? `<span class="muted">${escapeHtml(p.description)}</span>` : ''}
      <button type="button" class="link-btn" id="pm-detail-close">Close</button></div>
    <div class="small">${facts}</div>`;

  if (p.pluggedInto && p.pluggedInto.length) {
    html += '<h4>Plugged into</h4><ul class="pm-list">' + p.pluggedInto.map(x => `<li>${hostLink(x.host)}
      <span class="muted">port ${escapeHtml(x.port)}</span>${x.description && x.description !== x.port ? ` <span class="muted small">“${escapeHtml(x.description)}”</span>` : ''}</li>`).join('')
      + '</ul>';
  }
  if (p.subs && p.subs.length) {
    html += '<h4>VLAN sub-interfaces</h4><ul class="pm-list">' + p.subs.map(s => `<li><strong>${escapeHtml(s.name)}</strong>
      <span class="muted">VLAN ${s.vlan}</span>${s.ips.length ? ' · ' + escapeHtml(s.ips.join(', ')) : ''}</li>`).join('') + '</ul>';
  }
  if (p.neighbors.length) {
    html += '<h4>Neighbour</h4><ul class="pm-list">' + p.neighbors.map(n => `<li>
      ${n.host ? hostLink(n.host) : `<strong>${escapeHtml(n.name)}</strong>`}
      ${n.port ? `<span class="muted">port ${escapeHtml(n.port)}</span>` : ''}
      ${n.platform ? `<span class="muted small">${escapeHtml(n.platform)}</span>` : ''}
      <span class="muted small">via ${escapeHtml(n.via)}</span></li>`).join('') + '</ul>';
  } else if (p.towards && p.towards.length && !(p.pluggedInto && p.pluggedInto.length)) {
    html += `<h4>Leads to</h4><ul class="pm-list">${p.towards.map(h => `<li>${hostLink(h)}</li>`).join('')}</ul>`;
  }

  if (p.devices.length) {
    const title = p.role === 'uplink' ? `${p.devices.length} device${p.devices.length === 1 ? '' : 's'} beyond this port` : 'Devices on this port';
    const rows = p.devices.map(d => `<tr>
        <td>${d.host ? hostLink(d.host) : d.ip ? escapeHtml(d.ip) : '<span class="muted">unknown</span>'}${d.note ? `<div class="pm-note">⚠ ${escapeHtml(d.note)}</div>` : ''}</td>
        <td>${d.host ? escapeHtml(d.ip || d.host.ip) : ''}</td>
        <td class="mono small">${escapeHtml(d.mac)}</td>
        <td>${d.vlan != null ? d.vlan : ''}</td></tr>`).join('');
    const table = `<div class="details-table-wrap"><table class="switch-info-table">
      <thead><tr><th>Device</th><th>IP</th><th>MAC</th><th>VLAN</th></tr></thead><tbody>${rows}</tbody></table></div>`;
    html += p.role === 'uplink' && p.devices.length > 5
      ? `<details class="pm-beyond"><summary>${title}</summary>${table}</details>` : `<h4>${title}</h4>${table}`;
  } else if (p.status === 'up' && !p.neighbors.length) {
    html += '<p class="muted small">The link is up but the switch hasn’t learned any address on it yet (the device may be idle).</p>';
  }
  box.innerHTML = `<div class="pm-detail">${html}</div>`;
  document.getElementById('pm-detail-close').addEventListener('click', () => {
    box.innerHTML = '';
    portMapSelected = null;
    document.querySelectorAll('[data-port]').forEach(el => el.classList.remove('pm-selected'));
  });
  box.querySelectorAll('[data-open-host]').forEach(b => b.addEventListener('click', () => openModal(Number(b.dataset.openHost))));
}

// ---- Port strip on switch cards ----

const STRIP_ROLE = { d: 'device', n: 'unknown', u: 'uplink', e: 'empty', x: 'disabled' };

/** A small faceplate on switch/router cards (from host.portStrip); click opens the port view. */
function renderCardPorts(host) {
  const s = host.portStrip;
  if (!s || !s.ports || !s.ports.length) return '';
  const groups = new Map();
  for (const [name, code, devices] of s.ports) {
    const { prefix, num } = portParts(name);
    if (!groups.has(prefix)) groups.set(prefix, []);
    groups.get(prefix).push({ name, code, devices, num });
  }
  const used = s.ports.filter(p => p[1] === 'd' || p[1] === 'n' || p[1] === 'u').length;
  const modules = [...groups.values()].map(ports => {
    ports.sort((a, b) => a.num - b.num);
    const cols = Math.ceil(ports.length / 2);
    return `<div class="cp-grid" style="grid-template-columns:repeat(${cols}, 7px)">${ports.map((p, i) =>
      `<i class="cp-${STRIP_ROLE[p.code] || 'empty'}" style="grid-column:${Math.floor(i / 2) + 1};grid-row:${i % 2 + 1}"
        title="${escapeHtml(p.name)} — ${escapeHtml(PM_ROLE_LABEL[STRIP_ROLE[p.code]] || 'Disabled')}${p.devices ? ` (${p.devices} device${p.devices === 1 ? '' : 's'})` : ''}"></i>`).join('')}</div>`;
  }).join('');
  return `<div class="card-ports" title="Open the port view">
      <div class="cp-modules">${modules}</div>
      <div class="cp-text">${used} of ${s.ports.length} ports in use</div>
    </div>`;
}
