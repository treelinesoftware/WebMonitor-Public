// Services tab: web, port and DNS checks plus watched agent services.
// Uses api(), escapeHtml(), fmtAgo(), fmtDuration(), hostsById, IS_ADMIN,
// openModal() and showView() from app.js / updates.js.

const SVC_KIND_LABEL = { http: 'Web', tcp: 'Port', dns: 'DNS', agent: 'Agent service' };
const SVC_STATUS_LABEL = { up: 'Up', warn: 'Warning', down: 'Down', pending: 'Pending', unknown: 'Unknown', paused: 'Paused' };
const SVC_INTERVALS = [[30, '30 seconds'], [60, '1 minute'], [120, '2 minutes'], [300, '5 minutes'], [600, '10 minutes'],
  [900, '15 minutes'], [1800, '30 minutes'], [3600, '1 hour']];
const SVC_PROBLEM = s => s.status === 'down' || s.status === 'warn';

let svcData = null;
let svcVisible = false;
let svcTimer = null;
let svcFilter = 'all';
let svcSearch = '';
let svcBusy = new Set();   // ids with a check or restart in flight

async function loadServices() {
  clearTimeout(svcTimer);
  try {
    const resp = await api('api/services.php');
    const data = await resp.json();
    if (!resp.ok) throw new Error(data.error || 'Could not load services');
    setServices(data.services);
  } catch (err) {
    if (svcVisible) document.getElementById('svc-body').innerHTML = `<p class="details-error">${escapeHtml(err.message)}</p>`;
  }
  svcTimer = setTimeout(loadServices, svcVisible ? 15000 : 60000);
}

function setServices(list) {
  svcData = list || [];
  const down = svcData.filter(s => s.status === 'down').length;
  const el = document.getElementById('count-services');
  el.textContent = down ? String(down) : '';
  el.classList.toggle('crit', down > 0);
  if (svcVisible) renderServices();
  if (typeof openHostId !== 'undefined' && openHostId != null) renderHostServices(openHostId);   // app.js
}

function servicesViewShown(visible) {
  svcVisible = visible;
  if (visible) { if (svcData) renderServices(); loadServices(); }
}

// ---- Rendering

function svcPill(s) {
  const cls = { up: 'up', warn: 'pending', down: 'down', pending: 'muted', unknown: 'muted', paused: 'muted' }[s.status] || 'muted';
  const ms = s.status === 'up' && s.lastMs != null ? `<span class="pill-ms">${s.lastMs} ms</span>` : '';
  return `<span class="status-pill ${cls}">${SVC_STATUS_LABEL[s.status] || s.status}${ms}</span>`;
}

function svcTarget(s) {
  if (s.kind === 'dns') return `${s.target}${s.options.server ? ` @ ${s.options.server}` : ''}`;
  return s.target;
}

/** One line on what was seen: the check's message, plus the agent service's start time and restarts. */
function svcDetail(s) {
  const bits = [];
  if (s.message) bits.push(s.message);
  const d = s.detail || {};
  if (s.kind === 'agent') {
    if (d.display && d.display !== s.name) bits.unshift(d.display);
    if (d.since && s.status === 'up') bits.push(`running ${fmtDuration(Math.max(0, Date.now() / 1000 - d.since))}`);
    if (d.restarts) bits.push(`${d.restarts} automatic restart${d.restarts === 1 ? '' : 's'}`);
    if (d.startType) bits.push(d.startType.toLowerCase());
  }
  if (s.kind === 'http' && s.certExpiresAt && !(s.message || '').includes('certificate')) {
    const days = Math.floor((s.certExpiresAt - Date.now()) / 86400000);
    bits.push(`certificate valid ${days} more day${days === 1 ? '' : 's'}`);
  }
  if (s.status !== 'up' && s.changedAt && s.status !== 'pending') bits.push(`since ${fmtAgo(s.changedAt).replace(' ago', '')}`);
  return bits.join(' · ');
}

function svcBars(s) {
  if (!s.history.length) return '<span class="muted small">no results yet</span>';
  return s.history.map(([ts, up, ms]) =>
    `<div class="bar ${up ? 'up' : 'down'}" title="${escapeHtml(new Date(ts).toLocaleString())} — ${up ? 'up' : 'down'}${ms != null ? ` · ${ms} ms` : ''}"></div>`).join('');
}

function svcHostCell(s) {
  if (s.hostId == null) return '<span class="muted">—</span>';
  const name = s.hostName && s.hostName !== s.hostIp ? s.hostName : s.hostIp;
  return `<a href="#" class="svc-host" data-host="${s.hostId}">${escapeHtml(name || '?')}</a>`;
}

function svcActions(s, compact = false) {
  if (!IS_ADMIN) return '';
  const busy = svcBusy.has(s.id);
  const b = [];
  if (s.kind === 'agent') b.push(`<button type="button" class="card-btn" data-svc-act="restart" data-id="${s.id}"${busy ? ' disabled' : ''}>${busy ? 'Restarting…' : 'Restart'}</button>`);
  else b.push(`<button type="button" class="card-btn" data-svc-act="check" data-id="${s.id}"${busy ? ' disabled' : ''}>${busy ? 'Checking…' : 'Check now'}</button>`);
  b.push(`<button type="button" class="card-btn" data-svc-act="edit" data-id="${s.id}">Edit</button>`);
  if (!compact) b.push(`<button type="button" class="card-btn danger" data-svc-act="delete" data-id="${s.id}">Delete</button>`);
  return `<div class="svc-actions">${b.join('')}</div>`;
}

function svcMatches(s) {
  if (svcFilter === 'problem' && !SVC_PROBLEM(s) && s.status !== 'unknown') return false;
  if (['http', 'tcp', 'dns', 'agent'].includes(svcFilter) && s.kind !== svcFilter) return false;
  if (!svcSearch) return true;
  const hay = [s.name, s.target, s.hostName, s.hostIp, SVC_KIND_LABEL[s.kind], s.message, s.detail && s.detail.display].filter(Boolean).join(' ').toLowerCase();
  return svcSearch.split(/\s+/).every(t => hay.includes(t));
}

function renderServices() {
  const body = document.getElementById('svc-body');
  const all = svcData || [];
  const count = st => all.filter(s => s.status === st).length;
  const active = all.filter(s => s.status !== 'paused');
  const avg = active.filter(s => s.uptime != null);
  document.getElementById('svc-summary-line').textContent = all.length
    ? `${all.length} check${all.length === 1 ? '' : 's'} · ${new Set(all.map(s => s.hostId).filter(Boolean)).size} hosts`
      + (avg.length ? ` · ${(avg.reduce((n, s) => n + s.uptime, 0) / avg.length).toFixed(2)}% average uptime (24 h)` : '')
    : '';
  const tile = (key, label, value, sub, tone) =>
    `<button type="button" class="kpi ${tone}" data-svc-tile="${key}"><span class="kpi-label">${label}</span><span class="kpi-value">${value}</span><span class="kpi-sub">${sub}</span></button>`;
  document.getElementById('svc-tiles').innerHTML = all.length ? [
    tile('all', 'Checks', all.length, `${count('paused')} paused`, ''),
    tile('all', 'Up', count('up'), active.length ? `${Math.round(count('up') / active.length * 100)}% of active checks` : '—', 'good'),
    tile('problem', 'Down', count('down'), count('down') ? 'needs attention' : 'all clear', count('down') ? 'bad' : ''),
    tile('problem', 'Warnings', count('warn'), 'slow or certificate expiring', count('warn') ? 'warn' : ''),
    tile('agent', 'Unknown', count('unknown') + count('pending'), 'agent not reporting or new', ''),
  ].join('') : '';

  if (!all.length) {
    body.innerHTML = `<div class="svc-empty">
      <h3>No services monitored yet</h3>
      <p class="muted">Check that web pages answer, ports are open and names resolve, with no agent needed. Or watch services on
        hosts that run the WebMonitor agent, and restart them from here.</p>
      ${IS_ADMIN ? '<button type="button" class="primary-btn" data-svc-act="add">Add your first check</button>' : ''}</div>`;
    return;
  }
  const rank = { down: 0, warn: 1, unknown: 2, pending: 3, up: 4, paused: 5 };
  const rows = all.filter(svcMatches).sort((a, b) => (rank[a.status] ?? 9) - (rank[b.status] ?? 9)
    || (a.hostName || '~').localeCompare(b.hostName || '~') || a.name.localeCompare(b.name));
  if (!rows.length) {
    body.innerHTML = '<div class="empty">Nothing matches.</div>';
    return;
  }
  body.innerHTML = `<div class="svc-table-wrap"><table class="svc-table">
    <thead><tr><th>Status</th><th>Service</th><th>Host</th><th class="num">Response</th><th class="num">Uptime 24 h</th>
      <th class="svc-bars-col">Recent checks</th><th>Checked</th>${IS_ADMIN ? '<th></th>' : ''}</tr></thead>
    <tbody>${rows.map(s => `
      <tr class="svc-row st-${s.status}">
        <td>${svcPill(s)}</td>
        <td class="svc-name-cell">
          <div class="svc-name"><span class="svc-kind k-${s.kind}">${SVC_KIND_LABEL[s.kind]}</span>${escapeHtml(s.name)}${s.alert ? '' : ' <span class="muted small" title="Alerts are off for this check">🔕</span>'}</div>
          <div class="svc-sub"><span class="mono">${escapeHtml(svcTarget(s))}</span>${svcDetail(s) ? ` · ${escapeHtml(svcDetail(s))}` : ''}</div>
        </td>
        <td>${svcHostCell(s)}</td>
        <td class="num">${s.lastMs != null ? `${s.lastMs} ms` : '<span class="muted">—</span>'}</td>
        <td class="num">${s.uptime != null ? `${s.uptime}%` : '<span class="muted">—</span>'}</td>
        <td class="svc-bars-col"><div class="uptime-bar svc-bars">${svcBars(s)}</div></td>
        <td class="nowrap muted small">${s.lastCheck ? fmtAgo(s.lastCheck) : '—'}</td>
        ${IS_ADMIN ? `<td class="svc-act-cell">${svcActions(s)}</td>` : ''}
      </tr>`).join('')}</tbody></table></div>`;
}

/** Services section in a host's details window. */
function renderHostServices(hostId) {
  const el = document.getElementById('host-services');
  if (!el) return;
  const host = hostsById.get(hostId);
  if (!host) return;
  if (svcData === null) { loadServices(); return; }
  const list = svcData.filter(s => s.hostId === hostId);
  if (!list.length && !IS_ADMIN) { el.classList.add('hidden'); el.innerHTML = ''; return; }
  const html = `<div class="switch-info-header"><h3>Services</h3>
      ${IS_ADMIN ? `<div class="section-actions">${host.agent ? `<button type="button" class="section-btn" data-svc-act="add" data-host="${hostId}" data-kind="agent">Watch a service</button>` : ''}
        <button type="button" class="section-btn" data-svc-act="add" data-host="${hostId}" data-kind="http">Add check</button></div>` : ''}</div>
    ${list.length ? `<ul class="host-svc-list">${list.map(s => `<li>
        ${svcPill(s)}<span class="svc-kind k-${s.kind}">${SVC_KIND_LABEL[s.kind]}</span>
        <span class="host-svc-name" title="${escapeHtml(svcTarget(s))}">${escapeHtml(s.name)}<span class="muted small"> ${escapeHtml(svcDetail(s))}</span></span>
        ${svcActions(s, true)}</li>`).join('')}</ul>`
      : `<p class="hint">Nothing monitored on this host yet. ${host.agent ? 'Watch a service its agent reports (e.g. nginx or Spooler), or add' : 'Add'} a web, port or DNS check.</p>`}`;
  if (el.dataset.html !== html) { el.dataset.html = html; el.innerHTML = html; }
  el.classList.remove('hidden');
}

// ---- Actions

async function svcAction(act, id, btn) {
  const s = (svcData || []).find(x => x.id === id);
  if (act === 'edit') return openServiceForm(s);
  if (act === 'delete') {
    if (!confirm(`Delete the check "${s.name}"?\n\nIts history goes with it. This can't be undone.`)) return;
    const resp = await api(`api/services.php?id=${id}`, { method: 'DELETE' });
    const data = await resp.json().catch(() => ({}));
    if (!resp.ok) return alert(data.error || 'Could not delete the check');
    return setServices(data.services);
  }
  if (act === 'restart' && !confirm(`Restart ${s.name}${s.hostName ? ` on ${s.hostName}` : ''}?\n\nIts agent runs the restart within about a minute; it's logged in Updates → Job history.`)) return;
  svcBusy.add(id);
  renderAfterBusy();
  try {
    const resp = await api(`api/services.php?id=${id}&action=${act}`, { method: 'POST' });
    const data = await resp.json().catch(() => ({}));
    if (!resp.ok) throw new Error(data.error || 'Request failed');
    if (data.services) setServices(data.services);
    if (act === 'restart') setTimeout(() => { svcBusy.delete(id); loadServices(); }, 45000);
  } catch (err) {
    alert(err.message);
  } finally {
    if (act !== 'restart') svcBusy.delete(id);
    renderAfterBusy();
  }
}

function renderAfterBusy() {
  if (svcVisible) renderServices();
  if (typeof openHostId !== 'undefined' && openHostId != null) renderHostServices(openHostId);
}

document.addEventListener('click', e => {
  const b = e.target.closest('[data-svc-act]');
  if (b) {
    e.preventDefault();
    e.stopPropagation();
    if (b.dataset.svcAct === 'add') {
      openServiceForm(null, { hostId: b.dataset.host ? Number(b.dataset.host) : null, kind: b.dataset.kind || 'http' });
    } else {
      svcAction(b.dataset.svcAct, Number(b.dataset.id), b);
    }
    return;
  }
  const h = e.target.closest('.svc-host');
  if (h) { e.preventDefault(); openModal(Number(h.dataset.host)); }
  const t = e.target.closest('[data-svc-tile]');
  if (t) document.querySelector(`#svc-filter [data-svc-filter="${t.dataset.svcTile}"]`)?.click();
});

document.getElementById('svc-add').addEventListener('click', () => openServiceForm(null, { kind: 'http' }));
document.getElementById('svc-search').addEventListener('input', e => { svcSearch = e.target.value.trim().toLowerCase(); renderServices(); });
document.querySelectorAll('#svc-filter .tab-btn').forEach(b => b.addEventListener('click', () => {
  svcFilter = b.dataset.svcFilter;
  document.querySelectorAll('#svc-filter .tab-btn').forEach(x => x.classList.toggle('active', x === b));
  renderServices();
}));

// ---- Add / edit form

const svcBackdrop = document.getElementById('svc-backdrop');
const svcForm = document.getElementById('svc-form');
let svcEditing = null;     // the service being edited, or null when adding
let svcAvailable = null;   // agent services offered for the picked host

function closeServiceForm() { svcBackdrop.classList.add('hidden'); svcEditing = null; }
document.getElementById('svc-close').addEventListener('click', closeServiceForm);
svcBackdrop.addEventListener('click', e => { if (e.target === svcBackdrop) closeServiceForm(); });
document.addEventListener('keydown', e => { if (e.key === 'Escape' && !svcBackdrop.classList.contains('hidden')) closeServiceForm(); });

function hostOptions(kind, selected) {
  const hosts = [...hostsById.values()].filter(h => kind !== 'agent' || h.agent)
    .sort((a, b) => (a.name === a.ip ? '~' + a.ip : a.name).localeCompare(b.name === b.ip ? '~' + b.ip : b.name));
  return (kind === 'agent' ? '<option value="">Pick a host with an agent…</option>' : '<option value="">None (any address)</option>')
    + hosts.map(h => `<option value="${h.id}"${h.id === selected ? ' selected' : ''}>${escapeHtml(h.name === h.ip ? h.ip : `${h.name} (${h.ip})`)}</option>`).join('');
}

function openServiceForm(s, preset = {}) {
  svcEditing = s || null;
  const kind = s ? s.kind : preset.kind || 'http';
  document.getElementById('svc-form-title').textContent = s ? `Edit ${s.name}` : 'Add check';
  renderServiceForm(kind, s ? s.hostId : preset.hostId ?? null);
  svcBackdrop.classList.remove('hidden');
  svcForm.querySelector('[name="target"], [name="svc-pick-filter"]')?.focus();
}

function renderServiceForm(kind, hostId) {
  const s = svcEditing;
  const o = s ? s.options : {};
  const v = (x, d = '') => escapeHtml(String(x ?? d));
  const host = hostId != null ? hostsById.get(hostId) : null;
  const kinds = Object.entries(SVC_KIND_LABEL).map(([k, l]) =>
    `<label><input type="radio" name="kind" value="${k}"${k === kind ? ' checked' : ''}${s ? ' disabled' : ''} /> ${l}</label>`).join('');
  let fields = '';
  if (kind === 'http') {
    fields = `
      <label>Web address <input type="text" name="target" required value="${v(s ? s.target : host ? `http://${host.ip}/` : '')}" placeholder="https://nas.home.arpa:5001/ or http://192.168.1.53/admin" /></label>
      <div class="form-grid">
        <label>Expected status <input type="text" name="expect" value="${v(o.expect)}" placeholder="any 2xx or 3xx" /></label>
        <label>Page must contain <input type="text" name="keyword" value="${v(o.keyword)}" placeholder="optional text, e.g. Login" /></label>
        <label>Warn if slower than <span class="num-unit"><input type="number" name="warn_ms" min="0" max="60000" step="50" value="${v(o.warn_ms || '')}" placeholder="off" /> ms</span></label>
        <label>Warn before certificate expiry <span class="num-unit"><input type="number" name="cert_warn_days" min="0" max="365" value="${v(o.cert_warn_days ?? 14)}" /> days</span></label>
      </div>
      <div class="form-row">
        <label class="check"><input type="checkbox" name="verify_tls"${o.verify_tls === false ? '' : ' checked'} /> Check the certificate is trusted (untick for self-signed devices)</label>
        <label class="check"><input type="checkbox" name="head"${o.method === 'HEAD' ? ' checked' : ''} /> Headers only (HEAD)</label>
      </div>`;
  } else if (kind === 'tcp') {
    fields = `<label>${host ? 'Port, or host:port' : 'Host and port'} <input type="text" name="target" required value="${v(s ? s.target : '')}" placeholder="${host ? `e.g. 22, or ${escapeHtml(host.ip)}:22` : 'e.g. 192.168.1.50:445'}" /></label>
      <label>Warn if slower than <span class="num-unit"><input type="number" name="warn_ms" min="0" max="60000" step="50" value="${v(o.warn_ms || '')}" placeholder="off" /> ms</span></label>`;
  } else if (kind === 'dns') {
    fields = `
      <label>Name to look up <input type="text" name="target" required value="${v(s ? s.target : '')}" placeholder="e.g. nas.home.arpa or google.com" /></label>
      <div class="form-grid">
        <label>Record <select name="type">${['A', 'AAAA', 'CNAME', 'MX'].map(t => `<option${(o.type || 'A') === t ? ' selected' : ''}>${t}</option>`).join('')}</select></label>
        <label>DNS server <input type="text" name="server" value="${v(o.server ?? '')}" placeholder="${host ? escapeHtml(host.ip) + ' (the host)' : "this server's resolver"}" /></label>
        <label>Expected answer <input type="text" name="expect" value="${v(o.expect)}" placeholder="optional, e.g. 192.168.1.50" /></label>
      </div>`;
  } else {
    fields = `
      <div id="svc-picker">${hostId ? '<p class="muted small">Loading the services on this host…</p>' : '<p class="hint">Pick a host above to see its services.</p>'}</div>
      <input type="hidden" name="target" value="${v(s ? s.target : '')}" />`;
  }
  svcForm.innerHTML = `
    ${s ? '' : `<div class="choice-group svc-kinds">${kinds}</div>`}
    <label>Host <select name="hostId"${kind === 'agent' ? ' required' : ''}>${hostOptions(kind, hostId)}</select></label>
    ${fields}
    <div class="form-grid">
      <label>Name <input type="text" name="name" maxlength="120" value="${v(s ? s.name : '')}" placeholder="optional — made from the address" /></label>
      ${kind === 'agent' ? '<label>Checked <input type="text" value="every minute, by the agent" disabled /></label>'
        : `<label>Check every <select name="interval">${SVC_INTERVALS.map(([n, l]) => `<option value="${n}"${(s ? s.interval : 60) === n ? ' selected' : ''}>${l}</option>`).join('')}</select></label>`}
    </div>
    <div class="form-row">
      <label class="check"><input type="checkbox" name="alert"${!s || s.alert ? ' checked' : ''} /> Alert when it's down</label>
      ${s ? `<label class="check"><input type="checkbox" name="paused"${s.enabled ? '' : ' checked'} /> Paused</label>` : ''}
    </div>
    <div class="form-row"><button type="submit" class="primary-btn">${s ? 'Save' : 'Add check'}</button><span class="form-msg" id="svc-msg"></span></div>`;

  svcForm.querySelectorAll('[name="kind"]').forEach(r => r.addEventListener('change', () => renderServiceForm(r.value, currentFormHost())));
  svcForm.querySelector('[name="hostId"]').addEventListener('change', () => {
    const newHost = currentFormHost();
    const t = svcForm.querySelector('[name="target"]');
    const h = newHost != null ? hostsById.get(newHost) : null;
    if (kind === 'http' && h && (!t.value || /^https?:\/\/[\d.]+\/?$/.test(t.value))) t.value = `http://${h.ip}/`;
    if (kind === 'agent') renderServiceForm(kind, newHost);
  });
  if (kind === 'agent' && hostId) loadServicePicker(hostId, s ? s.target : '');
}

function currentFormHost() {
  const v = svcForm.querySelector('[name="hostId"]')?.value;
  return v ? Number(v) : null;
}

async function loadServicePicker(hostId, selected) {
  const box = document.getElementById('svc-picker');
  try {
    const resp = await api(`api/services.php?available=${hostId}`);
    svcAvailable = await resp.json();
    if (!resp.ok) throw new Error(svcAvailable.error || 'Could not load the services');
  } catch (err) {
    box.innerHTML = `<p class="details-error">${escapeHtml(err.message)}</p>`;
    return;
  }
  const a = svcAvailable;
  const watched = new Set((svcData || []).filter(s => s.hostId === hostId && s.kind === 'agent').map(s => s.target.toLowerCase()));
  if (!a.canWatch) {
    box.innerHTML = `<p class="details-error">This host's agent is ${escapeHtml(a.version || '?')}; watching services needs ${escapeHtml(a.minVersion)} or newer.
      Upgrade it from the Updates tab (select the host, then Upgrade agent), then try again.</p>`;
    return;
  }
  box.innerHTML = `
    <label>Service <input type="search" name="svc-pick-filter" placeholder="Filter, or type a name that isn't listed" value="${escapeHtml(selected)}" /></label>
    <div class="svc-pick-list" role="listbox"></div>
    ${a.services.length ? '' : '<p class="hint">The agent hasn\'t sent its service list yet (it does with its hourly inventory). You can type the name.</p>'}`;
  const input = box.querySelector('[name="svc-pick-filter"]');
  const list = box.querySelector('.svc-pick-list');
  const hidden = svcForm.querySelector('[name="target"]');
  const draw = () => {
    const q = input.value.trim().toLowerCase();
    hidden.value = input.value.trim();
    const items = a.services.filter(x => !q || x.name.toLowerCase().includes(q) || (x.display || '').toLowerCase().includes(q)).slice(0, 80);
    list.innerHTML = items.map(x => `<button type="button" class="svc-pick${x.name === hidden.value ? ' active' : ''}" data-name="${escapeHtml(x.name)}"${watched.has(x.name.toLowerCase()) && x.name !== selected ? ' disabled title="Already watched"' : ''}>
        <span class="svc-pick-name">${escapeHtml(x.display || x.name)}</span>
        <span class="muted small mono">${escapeHtml(x.name)}</span>
        <span class="svc-pick-state ${x.state === 'active' || x.state === 'running' ? 'on' : ''}">${escapeHtml(x.state || '')}</span></button>`).join('')
      || '<p class="muted small">No matching service in the list; the name you typed will be used.</p>';
  };
  input.addEventListener('input', draw);
  list.addEventListener('click', e => {
    const b = e.target.closest('.svc-pick');
    if (!b) return;
    input.value = b.dataset.name;
    draw();
  });
  draw();
}

svcForm.addEventListener('submit', async e => {
  e.preventDefault();
  const f = svcForm;
  const msg = document.getElementById('svc-msg');
  const get = n => f.querySelector(`[name="${n}"]`);
  const kind = svcEditing ? svcEditing.kind : f.querySelector('[name="kind"]:checked').value;
  const options = {};
  if (kind === 'http') {
    Object.assign(options, { expect: get('expect').value.trim(), keyword: get('keyword').value.trim(), verify_tls: get('verify_tls').checked,
      method: get('head').checked ? 'HEAD' : 'GET', warn_ms: Number(get('warn_ms').value) || 0, cert_warn_days: Number(get('cert_warn_days').value) || 0 });
  } else if (kind === 'tcp') {
    options.warn_ms = Number(get('warn_ms').value) || 0;
  } else if (kind === 'dns') {
    Object.assign(options, { type: get('type').value, server: get('server').value.trim(), expect: get('expect').value.trim() });
  }
  const body = {
    kind, hostId: currentFormHost(), target: get('target').value.trim(), name: get('name').value.trim(), options,
    interval: get('interval') ? Number(get('interval').value) : 60, alert: get('alert').checked,
  };
  if (svcEditing) body.enabled = !get('paused').checked;
  msg.className = 'form-msg';
  msg.textContent = kind === 'agent' ? 'Saving…' : 'Saving and checking…';
  f.querySelector('[type="submit"]').disabled = true;
  try {
    const resp = await api(svcEditing ? `api/services.php?id=${svcEditing.id}` : 'api/services.php',
      { method: svcEditing ? 'PUT' : 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) });
    const data = await resp.json().catch(() => ({}));
    if (!resp.ok) throw new Error(data.error || 'Could not save');
    setServices(data.services);
    closeServiceForm();
  } catch (err) {
    msg.className = 'form-msg err';
    msg.textContent = err.message;
  } finally {
    const btn = f.querySelector('[type="submit"]');
    if (btn) btn.disabled = false;
  }
});

if (location.hash === '#services') showView('services');
loadServices();
