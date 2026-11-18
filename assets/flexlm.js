// Licences tab: FlexLM licence servers, the licences in use and who has them.
// Uses api(), escapeHtml(), fmtAgo(), fmtDuration(), cssVar(), IS_ADMIN and
// showView() from app.js / updates.js, and Chart.js.

const FLM_STATUS_LABEL = { up: 'Up', warn: 'Warning', down: 'Down', pending: 'Pending', unknown: 'Unknown', paused: 'Paused' };
const FLM_INTERVALS = [[60, '1 minute'], [120, '2 minutes'], [300, '5 minutes'], [600, '10 minutes'], [900, '15 minutes'],
  [1800, '30 minutes'], [3600, '1 hour']];
const FLM_COLORS = ['#5b8cff', '#33d17a', '#f0ad4e', '#ef5350', '#a78bfa', '#22d3ee', '#f472b6', '#facc15', '#94a3b8', '#fb923c'];

let flmData = null;          // {lmutil, servers}
let flmVisible = false;
let flmTimer = null;
let flmTab = 'servers';
let flmSearch = '';
let flmHistory = null;
let flmHistoryDays = 7;
let flmHistoryTimer = null;
let flmDetailId = null;      // server open in the details window
let flmUsageDays = 1;
let flmChart = null;

async function loadFlexlm() {
  clearTimeout(flmTimer);
  try {
    const resp = await api('api/flexlm.php');
    const data = await resp.json();
    if (!resp.ok) throw new Error(data.error || 'Could not load the licence servers');
    setFlexlm(data);
  } catch (err) {
    if (flmVisible) document.getElementById('flm-body').innerHTML = `<p class="details-error">${escapeHtml(err.message)}</p>`;
  }
  // While a check is waiting for the background job, look again soon.
  const waiting = flmData && flmData.servers.some(s => s.checkRequested);
  flmTimer = setTimeout(loadFlexlm, flmVisible ? (waiting ? 5000 : 30000) : 120000);
}

function setFlexlm(data) {
  flmData = data;
  const down = data.servers.filter(s => s.status === 'down').length;
  const el = document.getElementById('count-flexlm');
  el.textContent = down ? String(down) : '';
  el.classList.toggle('crit', down > 0);
  if (flmVisible) renderFlexlm();
  if (flmDetailId != null) renderFlexlmDetail(false);
}

function flexlmViewShown(visible) {
  flmVisible = visible;
  if (visible) { if (flmData) renderFlexlm(); loadFlexlm(); }
}

// ---- Helpers

function flmPill(s) {
  const cls = { up: 'up', warn: 'pending', down: 'down' }[s.status] || 'muted';
  return `<span class="status-pill ${cls}">${FLM_STATUS_LABEL[s.status] || escapeHtml(s.status)}</span>`;
}

function flmMeter(used, total, pctWarn = 100) {
  if (!total) return '<span class="muted small">uncounted</span>';
  const pct = Math.min(100, Math.round(used / total * 100));
  const cls = used >= total ? 'crit' : pct >= Math.min(pctWarn || 100, 85) ? 'warn' : '';
  return `<div class="flm-meter" title="${used} of ${total} in use (${pct}%)">
    <div class="flm-meter-label"><span><strong>${used}</strong> / ${total}</span><span class="muted">${pct}%</span></div>
    <div class="storage-meter"><div class="storage-fill ${cls}" style="width:${pct}%"></div></div></div>`;
}

function flmDaysUntil(date) {
  const d = new Date(date + 'T00:00:00');
  const today = new Date(); today.setHours(0, 0, 0, 0);
  return Math.round((d - today) / 86400000);
}

function flmExpiry(date, warnDays = 30) {
  if (!date) return '<span class="muted">permanent</span>';
  const days = flmDaysUntil(date);
  const cls = days < 0 ? 'down-text' : days <= warnDays ? 'warn-text' : '';
  const when = days < 0 ? `expired ${-days} d ago` : days === 0 ? 'today' : `in ${days} d`;
  return `<span class="${cls}" title="${escapeHtml(date)}">${escapeHtml(date)} <span class="muted small">${when}</span></span>`;
}

function flmSince(ms) {
  return fmtDuration(Math.max(0, (Date.now() - ms) / 1000));
}

function flmMatches(...fields) {
  if (!flmSearch) return true;
  const hay = fields.filter(Boolean).join(' ').toLowerCase();
  return flmSearch.split(/\s+/).every(t => hay.includes(t));
}

function flmAllCheckouts() {
  return (flmData ? flmData.servers : []).flatMap(s => s.checkouts.map(c => ({ ...c, serverName: s.name })));
}

// ---- Rendering

function renderFlexlm() {
  const d = flmData;
  if (!d) return;
  const all = d.servers;
  const count = st => all.filter(s => s.status === st).length;
  const used = all.reduce((n, s) => n + s.used, 0);
  const total = all.reduce((n, s) => n + s.total, 0);
  const users = new Set(flmAllCheckouts().map(c => c.user)).size;
  const soon = all.filter(s => s.earliestExpiry && flmDaysUntil(s.earliestExpiry) <= (s.expiryWarnDays || 30)).length;
  const full = all.reduce((n, s) => n + s.features.filter(f => f.total && f.used >= f.total).length, 0);
  document.getElementById('flm-summary-line').textContent = all.length
    ? `${all.length} licence server${all.length === 1 ? '' : 's'} · ${all.reduce((n, s) => n + s.features.length, 0)} features`
    : 'FlexLM licence servers';
  document.getElementById('flm-count-inuse').textContent = users ? String(users) : '';

  const notice = document.getElementById('flm-notice');
  notice.innerHTML = d.lmutil.ok ? '' : `<div class="flm-notice details-error">
      <strong>${escapeHtml(d.lmutil.problem || 'lmutil is not available.')}</strong>
      ${d.lmutil.path ? '' : `<br>WebMonitor looks for it in ${d.lmutil.searched.map(p => `<code>${escapeHtml(p)}</code>`).join(', ')}.`}
      <a href="#help=flexlm" data-help-link="flexlm">How to install lmutil</a></div>`;

  const tile = (key, label, value, sub, tone) =>
    `<button type="button" class="kpi ${tone}" data-flm-tile="${key}"><span class="kpi-label">${label}</span><span class="kpi-value">${value}</span><span class="kpi-sub">${sub}</span></button>`;
  document.getElementById('flm-tiles').innerHTML = all.length ? [
    tile('servers', 'Servers', all.length, `${count('up') + count('warn')} answering${count('paused') ? ` · ${count('paused')} paused` : ''}`, ''),
    tile('servers', 'Down', count('down'), count('down') ? 'needs attention' : 'all answering', count('down') ? 'bad' : 'good'),
    tile('servers', 'Licences in use', used, total ? `of ${total} (${Math.round(used / total * 100)}%)` : 'none counted', full ? 'warn' : ''),
    tile('inuse', 'Users now', users, `${flmAllCheckouts().length} checkouts`, ''),
    tile('servers', 'Warnings', count('warn'), soon ? `${soon} server${soon === 1 ? '' : 's'} with licences expiring` : full ? `${full} feature${full === 1 ? '' : 's'} full` : 'none', count('warn') ? 'warn' : ''),
  ].join('') : '';

  document.querySelectorAll('#flm-tabs .tab-btn').forEach(b => b.classList.toggle('active', b.dataset.flmTab === flmTab));
  if (flmTab === 'inuse') return renderFlexlmInUse();
  if (flmTab === 'history') return renderFlexlmHistory();
  renderFlexlmServers();
}

function renderFlexlmServers() {
  const body = document.getElementById('flm-body');
  const all = flmData.servers;
  if (!all.length) {
    body.innerHTML = `<div class="svc-empty">
      <h3>No licence servers yet</h3>
      <p class="muted">Add a FlexLM licence server by its port and address (for example 27000@192.168.1.20) to see whether it's up,
        how many licences are in use, when they expire and who has them.</p>
      ${IS_ADMIN ? '<button type="button" class="primary-btn" data-flm-act="add">Add your first licence server</button>' : ''}</div>`;
    return;
  }
  const rank = { down: 0, warn: 1, unknown: 2, pending: 3, up: 4, paused: 5 };
  const rows = all.filter(s => flmMatches(s.name, s.target, s.message, s.notes, ...s.features.map(f => f.name), ...s.vendors.map(v => v.name),
    ...s.checkouts.flatMap(c => [c.user, c.host])))
    .sort((a, b) => (rank[a.status] ?? 9) - (rank[b.status] ?? 9) || a.name.localeCompare(b.name));
  if (!rows.length) { body.innerHTML = '<div class="empty">Nothing matches.</div>'; return; }
  body.innerHTML = `<div class="svc-table-wrap"><table class="svc-table flm-table">
    <thead><tr><th>Status</th><th>Licence server</th><th class="flm-meter-col">Licences in use</th><th class="num">Users</th>
      <th>Next expiry</th><th>Checked</th>${IS_ADMIN ? '<th></th>' : ''}</tr></thead>
    <tbody>${rows.map(s => {
      const vend = s.vendors.map(v => v.up ? escapeHtml(v.name) : `<span class="down-text">${escapeHtml(v.name)} down</span>`).join(', ');
      const sub = [escapeHtml(s.target), vend, s.lmgrdVersion ? `lmgrd ${escapeHtml(s.lmgrdVersion)}` : ''].filter(Boolean).join(' · ');
      const users = new Set(s.checkouts.map(c => c.user)).size;
      const since = s.status !== 'up' && s.changedAt && s.status !== 'pending' ? ` · since ${fmtAgo(s.changedAt).replace(' ago', '')}` : '';
      return `<tr class="svc-row st-${s.status}">
        <td>${flmPill(s)}</td>
        <td class="svc-name-cell">
          <div class="svc-name"><a href="#" class="flm-link" data-flm-open="${s.id}">${escapeHtml(s.name)}</a>${s.alert ? '' : ' <span class="muted small" title="Alerts are off for this server">🔕</span>'}</div>
          <div class="svc-sub"><span class="mono">${sub}</span></div>
          ${s.message ? `<div class="svc-sub ${s.status === 'down' ? 'down-text' : s.status === 'warn' ? 'warn-text' : ''}">${escapeHtml(s.message)}${since}</div>` : ''}
        </td>
        <td class="flm-meter-col">${s.features.length ? flmMeter(s.used, s.total, s.fullPct) : '<span class="muted">—</span>'}</td>
        <td class="num">${users || '<span class="muted">0</span>'}</td>
        <td class="nowrap">${s.features.length ? flmExpiry(s.earliestExpiry, s.expiryWarnDays) : '<span class="muted">—</span>'}</td>
        <td class="nowrap muted small">${s.checkRequested ? 'checking…' : s.lastCheck ? fmtAgo(s.lastCheck) : '—'}</td>
        ${IS_ADMIN ? `<td class="svc-act-cell"><div class="svc-actions">
          <button type="button" class="card-btn" data-flm-act="check" data-id="${s.id}"${s.checkRequested || !s.enabled ? ' disabled' : ''}>${s.checkRequested ? 'Checking…' : 'Check now'}</button>
          <button type="button" class="card-btn" data-flm-act="edit" data-id="${s.id}">Edit</button>
          <button type="button" class="card-btn danger" data-flm-act="delete" data-id="${s.id}">Delete</button></div></td>` : ''}
      </tr>`;
    }).join('')}</tbody></table></div>`;
}

function flmCheckoutTable(list, withServer = true, ended = false) {
  return `<div class="svc-table-wrap"><table class="svc-table">
    <thead><tr><th>User</th><th>Computer</th><th>Feature</th>${withServer ? '<th>Server</th>' : ''}<th class="num">Licences</th>
      <th>${ended ? 'Started' : 'Since'}</th><th class="num">${ended ? 'Duration' : 'For'}</th></tr></thead>
    <tbody>${list.map(c => `<tr>
      <td><strong>${escapeHtml(c.user)}</strong></td>
      <td>${escapeHtml(c.host)}${c.display && c.display !== c.host ? ` <span class="muted small">${escapeHtml(c.display)}</span>` : ''}</td>
      <td><span class="mono">${escapeHtml(c.feature)}</span>${c.version ? ` <span class="muted small">v${escapeHtml(c.version)}</span>` : ''}</td>
      ${withServer ? `<td><a href="#" class="flm-link" data-flm-open="${c.serverId}">${escapeHtml(c.serverName || '')}</a></td>` : ''}
      <td class="num">${c.licenses}</td>
      <td class="nowrap small">${escapeHtml(new Date(c.startedAt).toLocaleString([], { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' }))}</td>
      <td class="num small">${ended
        ? (c.endedAt ? fmtDuration((c.endedAt - c.startedAt) / 1000) : '<span class="ok-text">in use</span>')
        : flmSince(c.startedAt)}</td>
    </tr>`).join('')}</tbody></table></div>`;
}

function renderFlexlmInUse() {
  const body = document.getElementById('flm-body');
  const list = flmAllCheckouts().filter(c => flmMatches(c.user, c.host, c.display, c.feature, c.serverName))
    .sort((a, b) => a.user.localeCompare(b.user) || a.feature.localeCompare(b.feature));
  if (!flmAllCheckouts().length) {
    body.innerHTML = '<div class="empty">Nobody has a licence checked out right now.</div>';
    return;
  }
  body.innerHTML = list.length ? flmCheckoutTable(list) : '<div class="empty">Nothing matches.</div>';
}

async function loadFlexlmHistory() {
  const q = flmSearch;
  const days = flmHistoryDays;
  try {
    const resp = await api(`api/flexlm.php?history=1&days=${days}&q=${encodeURIComponent(q)}`);
    const data = await resp.json();
    if (!resp.ok) throw new Error(data.error || 'Could not load the history');
    if (q !== flmSearch || days !== flmHistoryDays) return;   // a newer request is on its way
    flmHistory = data;
  } catch (err) {
    flmHistory = { error: err.message };
  }
  if (flmVisible && flmTab === 'history') renderFlexlmHistory(false);
}

function renderFlexlmHistory(load = true) {
  const body = document.getElementById('flm-body');
  const range = `<div class="seg flm-range" role="group" aria-label="Range">${[1, 7, 30, 90, 180].map(n =>
    `<button type="button" class="group-btn${n === flmHistoryDays ? ' active' : ''}" data-flm-hdays="${n}">${n === 1 ? '24 h' : n + ' days'}</button>`).join('')}</div>`;
  if (load) loadFlexlmHistory();
  const h = flmHistory;
  if (!h || h.days !== flmHistoryDays) {
    if (!h || !h.error) { body.innerHTML = `${range}<div class="switch-info-status">Loading…</div>`; return; }
  }
  if (h.error) { body.innerHTML = `${range}<p class="details-error">${escapeHtml(h.error)}</p>`; return; }
  body.innerHTML = `${range}
    <div class="flm-history">
      <div class="panel-box flm-top">
        <h3>Top users</h3>
        ${h.topUsers.length ? `<ol class="flm-top-list">${h.topUsers.map(u => `<li>
          <span class="flm-top-name">${escapeHtml(u.user)}</span>
          <span class="muted small">${u.hours} h · ${u.sessions} checkout${u.sessions === 1 ? '' : 's'} · ${u.features} feature${u.features === 1 ? '' : 's'}</span></li>`).join('')}</ol>`
          : '<p class="muted small">No checkouts in this period.</p>'}
        <p class="hint">Hours run from each checkout's start time to the last check that saw it.</p>
      </div>
      <div class="flm-history-list">
        ${h.checkouts.length ? flmCheckoutTable(h.checkouts, true, true) : '<div class="empty">No checkouts in this period.</div>'}
        ${h.checkouts.length >= 300 ? '<p class="hint">Showing the latest 300. Search to narrow it down.</p>' : ''}
      </div>
    </div>`;
}

// ---- Details window: features, users and the usage chart

const flmDetailBackdrop = document.getElementById('flm-detail-backdrop');

function openFlexlmDetail(id) {
  flmDetailId = id;
  flmDetailBackdrop.classList.remove('hidden');
  renderFlexlmDetail(true);
}

function closeFlexlmDetail() {
  flmDetailBackdrop.classList.add('hidden');
  flmDetailId = null;
  if (flmChart) { flmChart.destroy(); flmChart = null; }
}

function renderFlexlmDetail(full) {
  const s = flmData && flmData.servers.find(x => x.id === flmDetailId);
  const el = document.getElementById('flm-detail');
  if (!s) { closeFlexlmDetail(); return; }
  const head = `<div class="modal-title-row"><h2 id="flm-detail-title">${escapeHtml(s.name)}</h2>${flmPill(s)}</div>
    <p class="modal-meta"><span class="mono">${escapeHtml(s.target)}</span>
      ${s.vendors.length ? ' · vendor daemon' + (s.vendors.length === 1 ? ' ' : 's ') + s.vendors.map(v => `${escapeHtml(v.name)}${v.version ? ' v' + escapeHtml(v.version) : ''}${v.up ? '' : ' <span class="down-text">down</span>'}`).join(', ') : ''}
      ${s.lmgrdVersion ? ` · lmgrd v${escapeHtml(s.lmgrdVersion)}` : ''}
      · checked ${s.lastCheck ? fmtAgo(s.lastCheck) : 'never'}${s.lastMs != null ? ` (${s.lastMs} ms)` : ''}</p>
    ${s.message ? `<p class="${s.status === 'down' ? 'details-error' : 'flm-warn-line'}">${escapeHtml(s.message)}</p>` : ''}
    ${s.notes ? `<p class="hint">${escapeHtml(s.notes)}</p>` : ''}`;
  const byFeature = {};
  s.checkouts.forEach(c => { (byFeature[c.feature] = byFeature[c.feature] || []).push(c); });
  const features = s.features.length ? `<div class="svc-table-wrap"><table class="svc-table">
      <thead><tr><th>Feature</th><th>Vendor</th><th class="flm-meter-col">In use</th><th>Expires</th><th>Checked out by</th></tr></thead>
      <tbody>${s.features.map(f => `<tr>
        <td><span class="mono">${escapeHtml(f.name)}</span>${f.version ? ` <span class="muted small">v${escapeHtml(f.version)}</span>` : ''}</td>
        <td class="small">${escapeHtml(f.vendor || '—')}</td>
        <td class="flm-meter-col">${flmMeter(f.used, f.total, s.fullPct)}${f.reserved ? `<div class="muted small">${f.reserved} reserved</div>` : ''}</td>
        <td class="nowrap">${flmExpiry(f.expires, s.expiryWarnDays)}</td>
        <td class="small">${(byFeature[f.name] || []).map(c => `<span class="flm-user" title="since ${escapeHtml(new Date(c.startedAt).toLocaleString())}">${escapeHtml(c.user)}@${escapeHtml(c.host)}${c.licenses > 1 ? ` ×${c.licenses}` : ''}</span>`).join(' ') || '<span class="muted">—</span>'}</td>
      </tr>`).join('')}</tbody></table></div>`
    : `<p class="hint">${s.status === 'pending' ? 'Not checked yet: the first check runs within a minute.' : 'No features reported yet.'}</p>`;

  if (!full && el.querySelector('#flm-usage-canvas')) {
    // Refresh the text parts only; leave the chart alone.
    el.querySelector('.flm-detail-head').innerHTML = head;
    el.querySelector('.flm-detail-features').innerHTML = features;
    return;
  }
  el.innerHTML = `<div class="flm-detail-head">${head}</div>
    <div class="switch-info"><div class="switch-info-header"><h3>Features</h3></div><div class="flm-detail-features">${features}</div></div>
    <div class="switch-info">
      <div class="switch-info-header"><h3>Licences in use over time</h3>
        <div class="seg" role="group" aria-label="Range">${[[1, '24 h'], [7, '7 d'], [30, '30 d'], [90, '90 d'], [365, '1 year']].map(([n, l]) =>
          `<button type="button" class="group-btn${n === flmUsageDays ? ' active' : ''}" data-flm-udays="${n}">${l}</button>`).join('')}</div></div>
      <div class="flm-chart-box"><canvas id="flm-usage-canvas"></canvas></div>
      <p class="muted small" id="flm-usage-note"></p>
    </div>
    <details class="switch-info flm-output"><summary>Last lmstat output</summary><pre class="cfg-pre" id="flm-output">Loading…</pre></details>`;
  el.querySelector('.flm-output').addEventListener('toggle', e => { if (e.target.open) loadFlexlmOutput(s.id); });
  loadFlexlmUsage(s.id);
}

async function loadFlexlmOutput(id) {
  const pre = document.getElementById('flm-output');
  try {
    const resp = await api(`api/flexlm.php?id=${id}&output=1`);
    const d = await resp.json();
    if (!resp.ok) throw new Error(d.error || 'Could not load the output');
    pre.textContent = d.output ? `Checked ${new Date(d.checkedAt).toLocaleString()}\n\n${d.output}` : 'No output yet.';
  } catch (err) {
    pre.textContent = err.message;
  }
}

async function loadFlexlmUsage(id) {
  const days = flmUsageDays;
  const note = document.getElementById('flm-usage-note');
  try {
    const resp = await api(`api/flexlm.php?usage=${id}&days=${days}`);
    const d = await resp.json();
    if (!resp.ok) throw new Error(d.error || 'Could not load the usage');
    if (flmDetailId !== id || flmUsageDays !== days) return;
    const c = document.getElementById('flm-usage-canvas');
    if (!c) return;
    if (flmChart) flmChart.destroy();
    // Busiest features first; the chart gets crowded beyond ten.
    const names = Object.keys(d.features).sort((a, b) => (d.peaks[b] || 0) - (d.peaks[a] || 0)).slice(0, 10);
    const grid = cssVar('--panel-border');
    const text = cssVar('--muted');
    const span = days * 86400;
    flmChart = new Chart(c, {
      type: 'line',
      data: { datasets: names.map((n, i) => ({
        label: `${n} (of ${d.totals[n]})`, data: d.features[n].map(([x, y]) => ({ x: x / 1000, y })),
        borderColor: FLM_COLORS[i % FLM_COLORS.length], backgroundColor: FLM_COLORS[i % FLM_COLORS.length],
        borderWidth: 1.6, pointRadius: d.features[n].length < 3 ? 3 : 0, stepped: true,
      })) },
      options: {
        responsive: true, maintainAspectRatio: false, animation: false, parsing: false,
        interaction: { mode: 'nearest', axis: 'x', intersect: false },
        scales: {
          x: { type: 'linear', min: Date.now() / 1000 - span, max: Date.now() / 1000, grid: { color: grid }, ticks: { color: text, maxTicksLimit: 7, callback: v => {
            const dt = new Date(v * 1000);
            return span > 2 * 86400 ? dt.toLocaleDateString([], { month: 'short', day: 'numeric' }) : dt.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
          } } },
          y: { beginAtZero: true, grid: { color: grid }, ticks: { color: text, precision: 0 } },
        },
        plugins: {
          legend: { position: 'bottom', labels: { color: cssVar('--text'), boxWidth: 10, boxHeight: 10 } },
          tooltip: { callbacks: { title: items => items.length ? new Date(items[0].raw.x * 1000).toLocaleString() : '' } },
        },
      },
    });
    const more = Object.keys(d.features).length - names.length;
    note.textContent = !names.length ? 'No usage recorded in this period yet.'
      : (d.hourly ? 'Highest number in use in each hour.' : 'Licences in use at each check.')
        + (more > 0 ? ` Showing the 10 busiest features; ${more} more not shown.` : '');
  } catch (err) {
    note.textContent = err.message;
  }
}

flmDetailBackdrop.addEventListener('click', e => {
  if (e.target === flmDetailBackdrop) return closeFlexlmDetail();
  const b = e.target.closest('[data-flm-udays]');
  if (b) {
    flmUsageDays = Number(b.dataset.flmUdays);
    b.parentElement.querySelectorAll('.group-btn').forEach(x => x.classList.toggle('active', x === b));
    loadFlexlmUsage(flmDetailId);
  }
});
document.getElementById('flm-detail-close').addEventListener('click', closeFlexlmDetail);

// ---- Actions

async function flmAction(act, id) {
  const s = flmData.servers.find(x => x.id === id);
  if (act === 'edit') return openFlexlmForm(s);
  if (act === 'delete') {
    if (!confirm(`Delete the licence server "${s.name}"?\n\nIts usage and checkout history go with it. This can't be undone.`)) return;
  }
  try {
    const resp = await api(`api/flexlm.php?id=${id}${act === 'check' ? '&action=check' : ''}`, { method: act === 'delete' ? 'DELETE' : 'POST' });
    const data = await resp.json().catch(() => ({}));
    if (!resp.ok) throw new Error(data.error || 'Request failed');
    setFlexlm(data);
    if (act === 'check') loadFlexlm();   // starts the quick refresh while it waits
  } catch (err) {
    alert(err.message);
  }
}

document.addEventListener('click', e => {
  const help = e.target.closest('[data-help-link]');
  if (help) { e.preventDefault(); showView('help', help.dataset.helpLink); return; }
  const b = e.target.closest('[data-flm-act]');
  if (b) {
    e.preventDefault();
    if (b.dataset.flmAct === 'add') openFlexlmForm(null);
    else flmAction(b.dataset.flmAct, Number(b.dataset.id));
    return;
  }
  const o = e.target.closest('[data-flm-open]');
  if (o) { e.preventDefault(); openFlexlmDetail(Number(o.dataset.flmOpen)); return; }
  const t = e.target.closest('[data-flm-tile]');
  if (t) { flmTab = t.dataset.flmTile; renderFlexlm(); return; }
  const hd = e.target.closest('[data-flm-hdays]');
  if (hd) { flmHistoryDays = Number(hd.dataset.flmHdays); flmHistory = null; renderFlexlmHistory(); }
});

document.getElementById('flm-add').addEventListener('click', () => openFlexlmForm(null));
document.querySelectorAll('#flm-tabs .tab-btn').forEach(b => b.addEventListener('click', () => { flmTab = b.dataset.flmTab; renderFlexlm(); }));
document.getElementById('flm-search').addEventListener('input', e => {
  flmSearch = e.target.value.trim().toLowerCase();
  if (flmTab !== 'history') return renderFlexlm();
  clearTimeout(flmHistoryTimer);
  flmHistoryTimer = setTimeout(() => renderFlexlmHistory(), 300);
});

// ---- Add / edit form

const flmBackdrop = document.getElementById('flm-backdrop');
const flmForm = document.getElementById('flm-form');
let flmEditing = null;

function closeFlexlmForm() { flmBackdrop.classList.add('hidden'); flmEditing = null; }
document.getElementById('flm-close').addEventListener('click', closeFlexlmForm);
flmBackdrop.addEventListener('click', e => { if (e.target === flmBackdrop) closeFlexlmForm(); });
document.addEventListener('keydown', e => {
  if (e.key !== 'Escape') return;
  if (!flmBackdrop.classList.contains('hidden')) closeFlexlmForm();
  else if (!flmDetailBackdrop.classList.contains('hidden')) closeFlexlmDetail();
});

function openFlexlmForm(s) {
  flmEditing = s || null;
  const v = (x, d = '') => escapeHtml(String(x ?? d));
  document.getElementById('flm-form-title').textContent = s ? `Edit ${s.name}` : 'Add licence server';
  flmForm.innerHTML = `
    <label>Server (port@host) <input type="text" name="target" required value="${v(s ? s.target : '')}" placeholder="27000@192.168.1.20" /></label>
    <p class="hint">The port lmgrd listens on and the server's name or address, as in the licence file's SERVER line. For a three-server
      triad, enter all three separated by commas.</p>
    <div class="form-grid">
      <label>Name <input type="text" name="name" maxlength="120" value="${v(s ? s.name : '')}" placeholder="optional, e.g. MATLAB" /></label>
      <label>Check every <select name="interval">${FLM_INTERVALS.map(([n, l]) => `<option value="${n}"${(s ? s.interval : 300) === n ? ' selected' : ''}>${l}</option>`).join('')}</select></label>
    </div>
    <div class="form-row">
      <label class="check"><input type="checkbox" name="alert"${!s || s.alert ? ' checked' : ''} /> Send alerts for this server</label>
      ${s ? `<label class="check"><input type="checkbox" name="paused"${s.enabled ? '' : ' checked'} /> Paused</label>` : ''}
    </div>
    <div class="form-grid">
      <label>Alert when down for <span class="num-unit"><input type="number" name="downAfterMin" min="0" max="1440" value="${v(s ? s.downAfterMin : 10)}" /> minutes</span></label>
      <label>Warn when a feature is <span class="num-unit"><input type="number" name="fullPct" min="0" max="100" value="${v(s ? s.fullPct : 100)}" /> % in use</span></label>
      <label>Warn before licences expire <span class="num-unit"><input type="number" name="expiryWarnDays" min="0" max="365" value="${v(s ? s.expiryWarnDays : 30)}" /> days</span></label>
    </div>
    <p class="hint">100% warns only when every licence of a feature is in use. 0 turns that warning off.</p>
    <label>Notes <input type="text" name="notes" maxlength="500" value="${v(s ? s.notes : '')}" placeholder="optional, e.g. who to contact about renewals" /></label>
    <div class="form-row"><button type="submit" class="primary-btn">${s ? 'Save' : 'Add licence server'}</button><span class="form-msg" id="flm-msg"></span></div>`;
  flmBackdrop.classList.remove('hidden');
  flmForm.querySelector('[name="target"]').focus();
}

flmForm.addEventListener('submit', async e => {
  e.preventDefault();
  const get = n => flmForm.querySelector(`[name="${n}"]`);
  const msg = document.getElementById('flm-msg');
  const body = {
    target: get('target').value.trim(), name: get('name').value.trim(), interval: Number(get('interval').value),
    alert: get('alert').checked, downAfterMin: Number(get('downAfterMin').value) || 0, fullPct: Number(get('fullPct').value) || 0,
    expiryWarnDays: Number(get('expiryWarnDays').value) || 0, notes: get('notes').value.trim(),
  };
  if (flmEditing) body.enabled = !get('paused').checked;
  msg.className = 'form-msg';
  msg.textContent = 'Saving…';
  flmForm.querySelector('[type="submit"]').disabled = true;
  try {
    const resp = await api(flmEditing ? `api/flexlm.php?id=${flmEditing.id}` : 'api/flexlm.php',
      { method: flmEditing ? 'PUT' : 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) });
    const data = await resp.json().catch(() => ({}));
    if (!resp.ok) throw new Error(data.error || 'Could not save');
    setFlexlm(data);
    closeFlexlmForm();
    loadFlexlm();
  } catch (err) {
    msg.className = 'form-msg err';
    msg.textContent = err.message;
  } finally {
    const btn = flmForm.querySelector('[type="submit"]');
    if (btn) btn.disabled = false;
  }
});

if (location.hash === '#flexlm') showView('flexlm');
loadFlexlm();
