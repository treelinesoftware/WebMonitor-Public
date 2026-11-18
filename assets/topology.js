// What runs where: Proxmox hosts and their guests, Docker hosts and their
// containers. Card tags, the "Group by Host" layout, and Details sections.
// Uses escapeHtml(), hostsById, IS_ADMIN, api(), openModal(), loadHosts(),
// subnetOf(), ipSortKey() and renderCard() from app.js.

// Simplified Proxmox mark: two chevrons forming an X (orange + current text
// colour, so it works in light and dark themes).
const PROXMOX_ICON = `<svg class="pve-icon" viewBox="0 0 24 24" role="img" aria-label="Proxmox VE">
  <path d="M3 3.5 L11 12 L3 20.5" fill="none" stroke="#e57000" stroke-width="3.4" stroke-linecap="round" stroke-linejoin="round"/>
  <path d="M21 3.5 L13 12 L21 20.5" fill="none" stroke="currentColor" stroke-width="3.4" stroke-linecap="round" stroke-linejoin="round"/>
</svg>`;

// Tag colours: warm for hypervisors, cool for Docker hosts. Chosen to stay
// readable as borders/tints on both themes.
const HV_COLORS = ['#e57000', '#c2410c', '#b45309', '#be185d'];
const DOCKER_COLORS = ['#2496ed', '#0e7490', '#4f46e5', '#0f766e'];

let groupMode = ['host', 'location'].includes(document.querySelector('meta[name="user-grouping"]')?.content)
  ? document.querySelector('meta[name="user-grouping"]').content : 'subnet';

function isProxmox(h) {
  return !!(h.hypervisor || (h.agent && h.agent.hypervisor));
}

function hostColor(id) {
  const hosts = [...hostsById.values()];
  const hv = hosts.filter(isProxmox).map(h => h.id).sort((a, b) => a - b);
  if (hv.includes(id)) return HV_COLORS[hv.indexOf(id) % HV_COLORS.length];
  const dk = hosts.filter(h => h.docker).map(h => h.id).sort((a, b) => a - b);
  if (dk.includes(id)) return DOCKER_COLORS[dk.indexOf(id) % DOCKER_COLORS.length];
  return null;
}

function shortName(h) {
  return h.name === h.ip ? h.ip : h.name;
}

// Simplified maker marks for network gear (brand colours, not the official logos).
const VENDOR_ICONS = {
  cisco: `<svg class="pve-icon vendor-icon" viewBox="0 0 24 24" role="img" aria-label="Cisco"><title>Cisco</title>
    <g stroke="#049fd9" stroke-width="2" stroke-linecap="round">
      <path d="M2 11v2M6 8v8M10 4v16M14 4v16M18 8v8M22 11v2"/></g></svg>`,
  aruba: `<svg class="pve-icon vendor-icon" viewBox="0 0 24 24" role="img" aria-label="Aruba"><title>Aruba</title>
    <g fill="none" stroke="#ff8300" stroke-width="2.2" stroke-linecap="round">
      <path d="M4.5 10.5a10.5 10.5 0 0 1 15 0"/><path d="M7.5 13.5a6.4 6.4 0 0 1 9 0"/><path d="M10.4 16.4a2.3 2.3 0 0 1 3.2 0"/></g>
    <circle cx="12" cy="19.4" r="1.6" fill="#ff8300"/></svg>`,
  fortinet: `<svg class="pve-icon vendor-icon" viewBox="0 0 24 24" role="img" aria-label="Fortinet"><title>Fortinet</title>
    <g fill="#da291c"><rect x="2" y="2" width="6" height="5" rx="0.6"/><rect x="9" y="2" width="6" height="5" rx="0.6"/>
      <rect x="16" y="2" width="6" height="5" rx="0.6"/><rect x="2" y="9.5" width="6" height="5" rx="0.6"/>
      <rect x="16" y="9.5" width="6" height="5" rx="0.6"/><rect x="2" y="17" width="6" height="5" rx="0.6"/>
      <rect x="9" y="17" width="6" height="5" rx="0.6"/><rect x="16" y="17" width="6" height="5" rx="0.6"/></g></svg>`,
};

/** Device icon for a card: the Proxmox mark for Proxmox VE hosts, the maker's mark for Cisco/Fortinet gear. */
function deviceIcon(h) {
  if (isProxmox(h)) return PROXMOX_ICON;
  if (h.vendor && VENDOR_ICONS[h.vendor]) return VENDOR_ICONS[h.vendor];
  return DEVICE_ICONS[h.device] || '';
}

/** Colour of the stripe on a card: its own role colour, or its parent's. */
function cardFlagColor(h) {
  if (h.parent) return hostColor(h.parent.hostId);
  return isProxmox(h) || h.docker ? hostColor(h.id) : null;
}

/** Tag lines under the IP: "VM 100 on …", "3 VMs · 1 LXC", "🐳 12 containers". */
function renderCardFlags(h) {
  let out = '';
  if (h.parent) {
    const p = hostsById.get(h.parent.hostId);
    const kind = h.parent.kind === 'lxc' ? 'LXC' : 'VM';
    out += `<div class="card-flag" style="--flag:${hostColor(h.parent.hostId)}" title="Runs on ${escapeHtml(p ? shortName(p) : '?')} (Proxmox VE)">
      ${kind} ${escapeHtml(String(h.parent.vmid))} on ${escapeHtml(p ? shortName(p) : 'Proxmox host')}</div>`;
  }
  if (h.hypervisor) {
    const g = h.hypervisor.guests || [];
    const vms = g.filter(x => x.type === 'qemu').length;
    const lxc = g.length - vms;
    const running = g.filter(x => x.status === 'running').length;
    out += `<div class="card-flag" style="--flag:${hostColor(h.id)}">${vms} VM${vms === 1 ? '' : 's'}${lxc ? ` · ${lxc} LXC` : ''} · ${running} running${h.hypervisor.stale ? ' · stale' : ''}</div>`;
  }
  if (h.docker) {
    const c = h.docker.containers || [];
    const running = c.filter(x => x.state === 'running').length;
    const unhealthy = c.filter(x => x.health === 'unhealthy').length;
    out += `<div class="card-flag${unhealthy ? ' warn' : ''}" style="--flag:${hostColor(h.id)}">🐳 ${c.length} container${c.length === 1 ? '' : 's'} · ${running} running${unhealthy ? ` · ${unhealthy} unhealthy` : ''}</div>`;
  }
  return out;
}

// ---- "Group by" switch (saved to the user's preferences) ----

function syncGroupButtons() {
  document.querySelectorAll('#group-switch .group-btn').forEach(b => b.classList.toggle('active', b.dataset.group === groupMode));
}

document.querySelectorAll('#group-switch .group-btn').forEach(b => b.addEventListener('click', async () => {
  if (groupMode === b.dataset.group) return;
  groupMode = b.dataset.group;
  syncGroupButtons();
  render();
  try {
    await api('api/account.php', { method: 'PATCH', headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ prefs: { grouping: groupMode } }) });
  } catch (e) { /* layout still switches for this page */ }
}));
syncGroupButtons();

// ---- Group by Host ----

function section(title, count, extraClass = '') {
  const el = document.createElement('div');
  el.className = 'network-group ' + extraClass;
  el.innerHTML = `<h3 class="network-group-title">${title} <span class="network-group-count">${count}</span></h3>`;
  const inner = document.createElement('div');
  inner.className = 'grid';
  el.appendChild(inner);
  return [el, inner];
}

function guestChip(g, color) {
  const canAdd = IS_ADMIN && g.ips && g.ips.length;
  return `<span class="chip ${g.status === 'running' ? 'on' : 'off'}" style="--flag:${color}"
      title="${escapeHtml(`${g.type === 'lxc' ? 'LXC' : 'VM'} ${g.vmid} · ${g.status}${g.ips.length ? ' · ' + g.ips.join(', ') : ' · IP unknown'}`)}">
      <span class="chip-dot"></span>${g.type === 'lxc' ? 'LXC' : 'VM'} ${escapeHtml(String(g.vmid))} ${escapeHtml(g.name)}
      ${canAdd ? `<button type="button" class="chip-add" data-name="${escapeHtml(g.name)}" data-ip="${escapeHtml(g.ips[0])}" title="Monitor this guest">+ Add</button>` : ''}
    </span>`;
}

function containerChip(c, color) {
  const state = c.state === 'running' ? (c.health === 'unhealthy' ? 'bad' : 'on') : 'off';
  return `<span class="chip ${state}" style="--flag:${color}"
      title="${escapeHtml(`${c.image}\n${c.status}${c.ports ? '\n' + c.ports : ''}`)}">
      <span class="chip-dot"></span>${escapeHtml(c.name)}${c.health === 'unhealthy' ? ' ⚠' : ''}</span>`;
}

function guestStrip(hv) {
  const others = (hv.hypervisor.guests || []).filter(g => !g.hostId || !hostsById.has(g.hostId));
  if (!others.length) return '';
  return `<div class="workload-strip" style="--flag:${hostColor(hv.id)}">
    <div class="strip-title">Other guests on ${escapeHtml(shortName(hv))} <span class="muted">(not monitored)</span></div>
    <div class="chips">${others.map(g => guestChip(g, hostColor(hv.id))).join('')}</div></div>`;
}

function containerStrip(h) {
  const list = h.docker.containers || [];
  if (!list.length) return '';
  const color = hostColor(h.id);
  const byProject = new Map();
  for (const c of list) {
    const k = c.project || '';
    if (!byProject.has(k)) byProject.set(k, []);
    byProject.get(k).push(c);
  }
  const groups = [...byProject.entries()].sort(([a], [b]) => (a === '') - (b === '') || a.localeCompare(b)).map(([proj, cs]) =>
    `<div class="chip-group"><span class="chip-group-name">${proj ? escapeHtml(proj) : 'standalone'}</span>${cs.map(c => containerChip(c, color)).join('')}</div>`).join('');
  return `<div class="workload-strip" style="--flag:${color}">
    <div class="strip-title">🐳 Containers on ${escapeHtml(shortName(h))} <span class="muted">(${escapeHtml(h.docker.engine)})</span></div>
    <div class="chips">${groups}</div></div>`;
}

function addStrip(inner, htmlStr) {
  if (!htmlStr) return;
  const wrap = document.createElement('div');
  wrap.className = 'strip-cell';
  wrap.innerHTML = htmlStr;
  wrap.querySelectorAll('.chip-add').forEach(b => b.addEventListener('click', e => {
    e.stopPropagation();
    addGuestAsHost(b.dataset.name, b.dataset.ip);
  }));
  inner.appendChild(wrap);
}

async function addGuestAsHost(name, ip) {
  if (!confirm(`Start monitoring ${name} (${ip})?`)) return;
  const resp = await api('api/hosts.php', {
    method: 'POST', headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ name: name.slice(0, 60), ip, os: 'other', device: 'server' }),
  });
  if (!resp.ok) {
    alert((await resp.json().catch(() => ({}))).error || 'Could not add the host');
    return;
  }
  loadHosts();
}

/** Hosts view grouped by Proxmox host / Docker host, then everything else by subnet. */
function renderByHost(filtered) {
  const showAll = activeTab === 'all';
  const visible = new Set(filtered.map(h => h.id));
  const all = [...hostsById.values()];
  const used = new Set();
  grid.innerHTML = '';

  const byName = (a, b) => shortName(a).localeCompare(shortName(b));
  // Proxmox hosts, each with its guests (and their containers).
  for (const hv of all.filter(h => h.hypervisor).sort(byName)) {
    const guests = all.filter(h => h.parent && h.parent.hostId === hv.id).sort((a, b) => (a.parent.vmid || 0) - (b.parent.vmid || 0));
    const members = [hv, ...guests];
    members.forEach(m => used.add(m.id));
    const shown = members.filter(m => visible.has(m.id));
    if (!shown.length && (!showAll || activeSubnet)) continue;
    const ver = hv.hypervisor.version ? ` · Proxmox VE ${escapeHtml(hv.hypervisor.version)}` : '';
    const [el, inner] = section(`${PROXMOX_ICON} ${escapeHtml(shortName(hv))}<span class="muted">${ver}</span>`, `${(hv.hypervisor.guests || []).length} guests`, 'host-group');
    el.style.setProperty('--flag', hostColor(hv.id));
    for (const m of shown) {
      inner.appendChild(renderCard(m));
      if (showAll && m.docker) addStrip(inner, containerStrip(m));
    }
    if (showAll) addStrip(inner, guestStrip(hv));
    grid.appendChild(el);
  }
  // Docker hosts that aren't guests of a Proxmox host shown above.
  for (const dk of all.filter(h => h.docker && !used.has(h.id)).sort(byName)) {
    used.add(dk.id);
    if (!visible.has(dk.id)) continue;
    const [el, inner] = section(`🐳 ${escapeHtml(shortName(dk))}`, `${(dk.docker.containers || []).length} containers`, 'host-group');
    el.style.setProperty('--flag', hostColor(dk.id));
    inner.appendChild(renderCard(dk));
    if (showAll) addStrip(inner, containerStrip(dk));
    grid.appendChild(el);
  }
  // Everything else, by subnet as usual.
  renderSubnetSections(filtered.filter(h => !used.has(h.id)), 'Other hosts · ');   // app.js
  if (!grid.children.length) grid.innerHTML = `<div class="empty">No ${escapeHtml(activeTab)} hosts.</div>`;
}

// ---- Host Details: guests / containers tables ----

function renderWorkloadDetails(h) {
  let out = '';
  if (h.parent) {
    const p = hostsById.get(h.parent.hostId);
    out += `<h4>Runs on</h4><div class="card-flag" style="--flag:${hostColor(h.parent.hostId)}">
      ${h.parent.kind === 'lxc' ? 'LXC' : 'VM'} ${escapeHtml(String(h.parent.vmid))} (“${escapeHtml(h.parent.name)}”) on
      <a href="#" class="open-host" data-id="${h.parent.hostId}">${escapeHtml(p ? shortName(p) : 'Proxmox host')}</a></div>`;
  }
  if (h.hypervisor) {
    const rows = (h.hypervisor.guests || []).map(g => {
      const mon = g.hostId && hostsById.get(g.hostId);
      return `<tr><td>${g.type === 'lxc' ? 'LXC' : 'VM'} ${escapeHtml(String(g.vmid))}</td><td>${escapeHtml(g.name)}</td>
        <td><span class="port-status ${g.status === 'running' ? 'up' : 'down'}">${escapeHtml(g.status)}</span></td>
        <td class="small">${escapeHtml((g.ips || []).join(', ') || '—')}</td>
        <td>${mon ? `<a href="#" class="open-host" data-id="${g.hostId}">${escapeHtml(shortName(mon))}</a>` : '<span class="muted">not monitored</span>'}</td></tr>`;
    }).join('');
    out += `<h4>Guests${h.hypervisor.stale ? ' <span class="muted">(last report over 30 min ago)</span>' : ''}</h4>
      <div class="details-table-wrap"><table class="switch-info-table">
      <thead><tr><th>ID</th><th>Name</th><th>Status</th><th>IP</th><th>In WebMonitor</th></tr></thead><tbody>${rows}</tbody></table></div>`;
  }
  if (h.docker) {
    const rows = (h.docker.containers || []).map(c => `<tr>
      <td>${escapeHtml(c.name)}${c.project ? `<div class="muted small">${escapeHtml(c.project)}</div>` : ''}</td>
      <td class="wrap small">${escapeHtml(c.image)}</td>
      <td><span class="port-status ${c.state === 'running' ? (c.health === 'unhealthy' ? 'down' : 'up') : 'unknown'}">${escapeHtml(c.status || c.state)}</span></td>
      <td class="wrap small">${escapeHtml(c.ports || '—')}</td></tr>`).join('');
    out += `<h4>🐳 Containers (${escapeHtml(h.docker.engine)})</h4>
      <div class="details-table-wrap"><table class="switch-info-table">
      <thead><tr><th>Name</th><th>Image</th><th>Status</th><th>Ports</th></tr></thead><tbody>${rows}</tbody></table></div>`;
  }
  return out;
}

function wireWorkloadDetails(container) {
  container.querySelectorAll('.open-host').forEach(a => a.addEventListener('click', e => {
    e.preventDefault();
    openModal(+a.dataset.id);
  }));
}
