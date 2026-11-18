// Reports view. First report: the network topology — network devices as a
// tree (router -> core switch -> other switches) with the ports at each end
// of every link, and under each device what's plugged into its ports.
// Data from api/topology.php (network_topology() in includes/portmap.php).
// Uses api(), escapeHtml(), openModal() from app.js and VENDOR_ICONS,
// DEVICE_ICONS, PROXMOX_ICON from topology.js / app.js.

const reportsView = document.getElementById('reports-view');
let topoData = null;
const TOPO_PORT_PREVIEW = 6;   // devices shown per port before "+N more"

async function loadTopologyReport() {
  const box = document.getElementById('topo-body');
  box.innerHTML = '<div class="switch-info-status">Building the network map…</div>';
  try {
    const resp = await api('api/topology.php');
    const data = await resp.json();
    if (!resp.ok) throw new Error(data.error || 'Could not build the topology');
    topoData = data;
    renderTopologyReport();
  } catch (err) {
    box.innerHTML = `<div class="switch-info-status details-error">${escapeHtml(err.message)}</div>`;
  }
}

document.getElementById('topo-refresh').addEventListener('click', loadTopologyReport);
document.getElementById('topo-print').addEventListener('click', () => {
  // Print everything expanded.
  reportsView.querySelectorAll('details').forEach(d => { d.dataset.wasOpen = d.open ? '1' : ''; d.open = true; });
  window.print();
  reportsView.querySelectorAll('details').forEach(d => { d.open = d.dataset.wasOpen === '1'; });
});
document.getElementById('topo-csv').addEventListener('click', () => {
  if (!topoData) return;
  const rows = [['Device', 'Device IP', 'Port', 'VLAN', 'Connected to', 'IP', 'MAC', 'Kind']];
  for (const r of connectionRows()) rows.push([r.device, r.deviceIp, r.port, r.vlan, r.name, r.ip, r.mac, r.kind]);
  const csv = rows.map(r => r.map(v => `"${String(v ?? '').replace(/"/g, '""')}"`).join(',')).join('\r\n');
  const a = document.createElement('a');
  a.href = URL.createObjectURL(new Blob([csv], { type: 'text/csv' }));
  a.download = `network-connections-${new Date().toISOString().slice(0, 10)}.csv`;
  a.click();
  setTimeout(() => URL.revokeObjectURL(a.href), 1000);
});

function topoNodeIcon(n) {
  if (n.vendor && typeof VENDOR_ICONS !== 'undefined' && VENDOR_ICONS[n.vendor]) return VENDOR_ICONS[n.vendor];
  return DEVICE_ICONS[n.device] || '';
}

function topoHostLink(h, label) {
  return `<button type="button" class="link-btn topo-host" data-open-host="${h.id}">${escapeHtml(label || h.name)}</button>`;
}

/** One device under a port: a card link, or its IP/MAC. Proxmox hosts list their guests. */
function topoDevice(d) {
  let html;
  if (d.host) {
    const host = hostsById.get(d.host.id);
    const icon = d.guests ? PROXMOX_ICON : (host ? (OS_ICONS[host.os] || '') : '');
    const status = host ? `<span class="status-dot ${statusClass(host)}"></span>` : '';
    html = `<span class="topo-dev known">${status}${icon}${topoHostLink(d.host)}${d.containers ? ` <span class="muted small">🐳 ${d.containers}</span>` : ''}</span>`;
  } else {
    const label = d.name || d.ip || d.mac;
    html = `<span class="topo-dev${d.note ? ' topo-warn' : ''}" title="${escapeHtml([d.note, d.ip, d.mac, d.band].filter(Boolean).join(' · '))}">${d.note ? '⚠ ' : ''}${escapeHtml(label)}</span>`;
  }
  if (d.ap) {
    const label = d.host ? d.host.name : d.ap.name;
    html = `<span class="topo-dev known">${d.host && hostsById.get(d.host.id) ? `<span class="status-dot ${statusClass(hostsById.get(d.host.id))}"></span>` : ''}${VENDOR_ICONS.aruba}${d.host ? topoHostLink(d.host, label) : escapeHtml(label)}</span>`;
    const cl = d.wifiClients || [];
    if (cl.length) {
      html += `<details class="topo-wifi"><summary>📶 ${cl.length} Wi-Fi client${cl.length === 1 ? '' : 's'}</summary><div class="topo-guests">${cl.map(c =>
        `<span class="topo-guest wifi" title="${escapeHtml([c.ip, c.mac, c.ssid, c.band].filter(Boolean).join(' · '))}">${c.hostId ? topoHostLink({ id: c.hostId, name: c.name }) : escapeHtml(c.name)}</span>`).join('')}</div></details>`;
    }
    return html;
  }
  if (d.guests && d.guests.length) {
    html += `<div class="topo-guests">${d.guests.map(g => {
      const h = g.hostId ? hostsById.get(g.hostId) : null;
      const label = `${g.type === 'lxc' ? 'CT' : 'VM'} ${g.vmid} ${g.name}`;
      return `<span class="topo-guest ${g.status === 'running' ? '' : 'stopped'}">${h ? topoHostLink(h, label) : escapeHtml(label)}</span>`;
    }).join('')}</div>`;
  }
  return html;
}

function topoNodeBox(id, link) {
  const n = topoData.nodes[id];
  const ports = n.ports.map(p => {
    const shown = p.devices.slice(0, TOPO_PORT_PREVIEW).map(topoDevice).join('');
    const rest = p.devices.slice(TOPO_PORT_PREVIEW);
    const more = rest.length ? `<details class="topo-more"><summary>+${rest.length} more</summary>${rest.map(topoDevice).join('')}</details>` : '';
    const label = p.members.length > 1 ? `${p.port} (${p.members.join(' + ')})` : p.port;
    return `<div class="topo-port">
        <div class="topo-port-name" title="${escapeHtml(p.description || '')}">${p.ssid ? '📶 ' : ''}${escapeHtml(label)}${p.vlan != null ? `<span class="muted"> · ${p.vlan === 'trunk' ? 'trunk' : 'VLAN ' + escapeHtml(String(p.vlan))}</span>` : ''}</div>
        <div class="topo-devs">${shown}${more}</div>
      </div>`;
  }).join('');
  const deviceCount = n.ports.reduce((c, p) => c + p.devices.length, 0);
  const host = hostsById.get(n.id);
  const isAp = n.device === 'ap';
  const stats = isAp
    ? `${(n.radios || []).map(r => `${escapeHtml(r.band)} ch${escapeHtml(r.channel)} · ${r.clients}`).join(' | ')}<br>${n.wifiClients} Wi-Fi client${n.wifiClients === 1 ? '' : 's'}`
    : `${n.portsUp} of ${n.portsTotal} ports up · ${deviceCount} device${deviceCount === 1 ? '' : 's'}`;
  return `<div class="topo-node ${n.device}">
      ${link ? `<div class="topo-link" title="Port on the device above ↔ port on this device">${escapeHtml(link.parentPort || '?')} ↔ ${escapeHtml(link.childPort || '?')}</div>` : ''}
      <div class="topo-box">
        <div class="topo-head">
          <span class="topo-icon">${topoNodeIcon(n)}</span>
          <div class="topo-title">
            ${host ? `<span class="status-dot ${statusClass(host)}"></span>` : ''}${topoHostLink(n)}
            <div class="muted small">${escapeHtml(n.ip)}${n.aliases.length ? ` · also ${escapeHtml(n.aliases.join(', '))}` : ''}</div>
            ${n.model ? `<div class="muted small">${escapeHtml(n.model)}</div>` : ''}
          </div>
        </div>
        <div class="topo-stats small">${stats}</div>
        ${ports ? `<details class="topo-ports" ${deviceCount <= 12 ? 'open' : ''}><summary>${isAp ? 'Wi-Fi clients by SSID' : 'Connected devices'}</summary>${ports}</details>` : ''}
      </div>
    </div>`;
}

function topoTree(node) {
  const kids = node.children.length ? `<ul>${node.children.map(c => `<li>${topoTree(c)}</li>`).join('')}</ul>` : '';
  return topoNodeBox(node.id, node.link) + kids;
}

/** Flat rows for the connections table and CSV. */
function connectionRows() {
  const rows = [];
  const nodes = topoData.nodes;
  for (const l of topoData.links) {
    const a = nodes[l.a], b = nodes[l.b];
    rows.push({ device: a.name, deviceIp: a.ip, port: l.ports[l.a] || '', vlan: '', name: b.name, ip: b.ip, mac: '', kind: 'link', hostId: b.id });
  }
  for (const n of Object.values(nodes)) {
    for (const p of n.ports) {
      for (const d of p.devices) {
        rows.push({ device: n.name, deviceIp: n.ip, port: p.ssid ? `Wi-Fi ${p.port}${d.band ? ' · ' + d.band : ''}` : p.members.length > 1 ? `${p.port} (${p.members.join('+')})` : p.port,
          vlan: d.vlan ?? (p.vlan === 'trunk' ? 'trunk' : p.vlan ?? ''), name: d.host ? d.host.name : (d.name || ''), ip: d.ip || (d.host ? d.host.ip : ''),
          mac: d.mac, kind: p.ssid ? 'wifi' : d.ap ? 'access point' : d.guests ? 'proxmox host' : d.host ? 'monitored' : 'device', hostId: d.host ? d.host.id : null });
        for (const c of d.wifiClients || []) {
          rows.push({ device: n.name, deviceIp: n.ip, port: `${p.port} → ${d.host ? d.host.name : d.ap.name} (Wi-Fi)`, vlan: c.ssid || '',
            name: c.name, ip: c.ip || '', mac: c.mac, kind: 'wifi', hostId: c.hostId });
        }
        for (const g of d.guests || []) {
          rows.push({ device: n.name, deviceIp: n.ip, port: `${p.port} → ${d.host.name}`, vlan: '', name: `${g.type === 'lxc' ? 'CT' : 'VM'} ${g.vmid} ${g.name}`,
            ip: g.ip || '', mac: '', kind: 'guest', hostId: g.hostId });
        }
      }
    }
  }
  return rows;
}

function renderTopologyReport() {
  const t = topoData;
  const box = document.getElementById('topo-body');
  if (!t.roots.length) {
    box.innerHTML = `<p class="muted">No switch port maps yet. Set a card's device type to Switch or Router (with SNMP working)
      and open its Details, or wait for the background refresh.</p>`;
    return;
  }
  const rows = connectionRows();
  const total = rows.filter(r => r.kind !== 'link').length;
  document.getElementById('topo-meta').textContent =
    `${Object.keys(t.nodes).length} network devices${t.wifiClients ? ` · ${t.wifiClients} Wi-Fi clients` : ''} · ${t.links.length} links · ${total} connections · built ${new Date(t.generatedAt).toLocaleString()}`;

  box.innerHTML = `
    <div class="topo-scroll"><div class="topo-tree">${t.roots.map(r => `<ul><li>${topoTree(r)}</li></ul>`).join('')}</div></div>
    ${t.unplaced.length ? `<h3 class="topo-h">Not seen on any switch port <span class="muted small">(${t.unplaced.length})</span></h3>
      <p class="hint">Monitored, but no switch or router WebMonitor reads has learned their address — they may be behind a device
        it can't read (an unmanaged switch, Wi-Fi, VPN), or quiet right now.</p>
      <div class="topo-devs topo-unplaced">${t.unplaced.map(u => `<span class="topo-dev known">${topoHostLink(u)}${u.name !== u.ip ? ` <span class="muted small">${escapeHtml(u.ip)}</span>` : ''}</span>`).join('')}</div>` : ''}
    <h3 class="topo-h">Connections</h3>
    <input type="search" id="topo-filter" class="topo-filter" placeholder="Filter by device, port, IP or MAC…" />
    <div class="details-table-wrap"><table class="switch-info-table topo-table">
      <thead><tr><th>Network device</th><th>Port</th><th>VLAN</th><th>Connected to</th><th>IP</th><th>MAC</th></tr></thead>
      <tbody>${rows.map(r => `<tr data-q="${escapeHtml([r.device, r.port, r.name, r.ip, r.mac, r.vlan].join(' ').toLowerCase())}" class="topo-row-${r.kind.replace(' ', '-')}">
        <td>${escapeHtml(r.device)}</td><td>${escapeHtml(r.port)}</td><td>${escapeHtml(String(r.vlan ?? ''))}</td>
        <td>${r.hostId ? topoHostLink({ id: r.hostId, name: r.name || r.ip }) : escapeHtml(r.name || '—')}${r.kind === 'link' ? ' <span class="muted small">(link)</span>' : ''}</td>
        <td>${escapeHtml(r.ip || '')}</td><td class="mono small">${escapeHtml(r.mac || '')}</td></tr>`).join('')}</tbody>
    </table></div>`;

  box.querySelectorAll('[data-open-host]').forEach(b => b.addEventListener('click', () => openModal(Number(b.dataset.openHost))));
  document.getElementById('topo-filter').addEventListener('input', e => {
    const q = e.target.value.trim().toLowerCase();
    box.querySelectorAll('.topo-table tbody tr').forEach(tr => { tr.hidden = q !== '' && !tr.dataset.q.includes(q); });
  });
}

// ---- Report picker ----

let currentReport = 'topology';
function showReport(name) {
  currentReport = name;
  document.querySelectorAll('[data-report]').forEach(el => {
    if (el.classList.contains('report-panel')) el.classList.toggle('hidden', el.dataset.report !== name);
    else el.classList.toggle('active', el.dataset.report === name);
  });
  stopTrafficReport();   // traffic.js
  if (name === 'topology') loadTopologyReport();
  if (name === 'devices') loadDevicesReport();   // devices.js
  if (name === 'traffic') loadTrafficReport();   // traffic.js
}
document.querySelectorAll('.report-picker [data-report]').forEach(b => b.addEventListener('click', () => showReport(b.dataset.report)));

if (location.hash === '#reports') showView('reports');
