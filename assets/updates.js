// Updates view and update jobs (Phase 3).
// Uses api(), escapeHtml(), fmtAgo(), hostsById, IS_ADMIN, openModal() from app.js.

const JOB_MIN_AGENT = '0.3.0';
const JOB_LABELS = {
  refresh: 'Check for updates',
  updates: 'Install updates',
  reboot: 'Reboot',
  agent_upgrade: 'Upgrade agent',
};
const JOB_STATUS_CLASS = {
  queued: 'muted', running: 'run', succeeded: 'ok', failed: 'crit', cancelled: 'muted', expired: 'muted',
};

const updatesView = document.getElementById('updates-view');
const hostsView = document.getElementById('hosts-view');
const updatesTable = document.getElementById('updates-table');
const jobsTable = document.getElementById('jobs-table');
const selectedHosts = new Set();
let latestJobByHost = {};
let recentJobs = [];
let jobsTimer = null;
const openOutputs = new Map();   // jobId -> refresh timer while expanded

function versionAtLeast(v, min) {
  const a = String(v || '0').split('.').map(Number);
  const b = min.split('.').map(Number);
  for (let i = 0; i < 3; i++) {
    if ((a[i] || 0) !== (b[i] || 0)) return (a[i] || 0) > (b[i] || 0);
  }
  return true;
}

function hostTitle(h) {
  return h.name === h.ip ? h.ip : `${h.name} (${h.ip})`;
}

// ---- View switching (Hosts / Updates), remembered in the URL hash ----

function showView(name, subnet = '') {
  const updates = name === 'updates';
  const reports = name === 'reports';
  const scans = name === 'scans';
  const security = name === 'security';
  const services = name === 'services';
  const network = name === 'network';
  const flexlm = name === 'flexlm';
  const help = name === 'help';
  const other = updates || reports || scans || security || services || network || flexlm || help;
  updatesView.classList.toggle('hidden', !updates);
  hostsView.classList.toggle('hidden', other);
  document.getElementById('services-view').classList.toggle('hidden', !services);
  document.getElementById('reports-view').classList.toggle('hidden', !reports);
  document.getElementById('scans-view').classList.toggle('hidden', !scans);
  document.getElementById('security-view').classList.toggle('hidden', !security);
  document.getElementById('network-view').classList.toggle('hidden', !network);
  document.getElementById('flexlm-view').classList.toggle('hidden', !flexlm);
  document.getElementById('help-view').classList.toggle('hidden', !help);
  document.getElementById('help-btn')?.classList.toggle('active', help);
  if (!other) {
    activeSubnet = subnet;   // app.js
    render();
  }
  if (reports) showReport(currentReport);   // reports.js
  // App-bar sections, plus the subnet tabs on the Hosts page.
  document.querySelectorAll('#app-nav .view-btn').forEach(b => b.classList.toggle('active', b.dataset.view === name));
  document.querySelectorAll('#view-tabs .view-btn').forEach(b => b.classList.toggle('active', !other && b.dataset.subnet === subnet));
  const hash = other ? '#' + name : subnet ? '#net=' + encodeURIComponent(subnet) : '#';
  if (history.replaceState) history.replaceState(null, '', hash);
  clearInterval(jobsTimer);
  if (updates) {
    renderUpdates();
    loadJobs();
    loadSchedules();
    let tick = 0;
    jobsTimer = setInterval(() => { loadJobs(); if (++tick % 3 === 0) loadSchedules(); }, 10000);
  }
  if (scans && typeof loadScanQueue === 'function') loadScanQueue();
  if (typeof securityViewShown === 'function') securityViewShown(security);   // vulns.js
  if (typeof servicesViewShown === 'function') servicesViewShown(services);   // services.js
  if (typeof networkViewShown === 'function') networkViewShown(network);      // network.js
  if (typeof flexlmViewShown === 'function') flexlmViewShown(flexlm);         // flexlm.js
  if (typeof helpViewShown === 'function') helpViewShown(help, subnet);      // help.js (subnet = topic here)
}

// The app bar's Hosts button keeps the subnet you were looking at.
document.querySelectorAll('#app-nav .view-btn, #view-tabs .view-btn').forEach(b => b.addEventListener('click', () =>
  showView(b.dataset.view, b.classList.contains('nav-btn') ? activeSubnet : b.dataset.subnet || '')));
document.querySelector('.brand').addEventListener('click', e => { e.preventDefault(); showView('hosts', activeSubnet); });

// Called by app.js after every host poll.
function onHostsUpdated() {
  const withUpdates = [...hostsById.values()].filter(h => h.agent && h.agent.updates > 0).length;
  document.getElementById('count-updates').textContent = withUpdates || '';
  if (!updatesView.classList.contains('hidden')) renderUpdates();
}

// ---- Updates table ----

function agentHosts() {
  return [...hostsById.values()].filter(h => h.agent)
    .sort((a, b) => (b.agent.securityUpdates || 0) - (a.agent.securityUpdates || 0) || ipSortKey(a.ip) - ipSortKey(b.ip));
}

function jobBadge(j) {
  if (!j) return '<span class="muted">—</span>';
  const when = j.finishedAt || j.startedAt || j.createdAt;
  return `<span class="job-status ${JOB_STATUS_CLASS[j.status] || ''}">${escapeHtml(j.status)}</span>
    <span class="muted">${escapeHtml(j.label)} · ${escapeHtml(fmtAgo(when))}</span>`;
}

function renderUpdates() {
  const hosts = agentHosts();
  for (const id of [...selectedHosts]) if (!hostsById.has(id)) selectedHosts.delete(id);
  const noAgent = hostsById.size - hosts.length;
  if (!hosts.length) {
    updatesTable.innerHTML = `<div class="empty">No hosts have the agent yet. Install it from a host's Details.</div>`;
    return;
  }
  const rows = hosts.map(h => {
    const a = h.agent;
    const canJob = versionAtLeast(a.version, JOB_MIN_AGENT);
    const pending = a.updates == null ? '—'
      : a.updates === 0 ? '<span class="ok-text">up to date</span>'
      : `${a.updates}${a.securityUpdates ? ` <span class="badge crit">${a.securityUpdates} security</span>` : ''}`;
    return `
      <tr data-id="${h.id}" class="${selectedHosts.has(h.id) ? 'selected' : ''}">
        ${IS_ADMIN ? `<td><input type="checkbox" class="upd-pick" ${selectedHosts.has(h.id) ? 'checked' : ''}
          ${canJob ? '' : 'disabled title="Agent too old for jobs — reinstall it once"'} /></td>` : ''}
        <td><a href="#" class="upd-host">${escapeHtml(h.name === h.ip ? h.ip : h.name)}</a>
          <div class="muted small">${escapeHtml(h.ip)}${h.mutedUntil ? ' · 🔕' : ''}</div></td>
        <td class="wrap small col-os">${escapeHtml(a.os || '—')}</td>
        <td class="nowrap">${a.online ? '' : '<span class="badge crit">offline</span> '}v${escapeHtml(a.version || '?')}
          ${canJob ? '' : '<div class="small warn-text">reinstall needed</div>'}</td>
        <td class="nowrap">${pending}</td>
        <td>${a.rebootRequired ? '<span class="badge warn">reboot</span>' : '<span class="muted">—</span>'}</td>
        <td class="small">${jobBadge(latestJobByHost[h.id])}</td>
      </tr>`;
  }).join('');
  updatesTable.innerHTML = `
    <div class="details-table-wrap updates-wrap"><table class="switch-info-table updates-table">
      <thead><tr>
        ${IS_ADMIN ? '<th><input type="checkbox" id="upd-all" title="Select all" /></th>' : ''}
        <th>Host</th><th class="col-os">OS</th><th>Agent</th><th>Pending</th><th>Reboot</th><th>Last job</th>
      </tr></thead>
      <tbody>${rows}</tbody></table></div>
    ${noAgent ? `<p class="hint">${noAgent} host${noAgent === 1 ? '' : 's'} without an agent ${noAgent === 1 ? 'is' : 'are'} not listed.</p>` : ''}`;

  updatesTable.querySelectorAll('tr[data-id]').forEach(tr => {
    const id = +tr.dataset.id;
    tr.querySelector('.upd-host').addEventListener('click', e => { e.preventDefault(); openModal(id); });
    tr.querySelector('.upd-pick')?.addEventListener('change', e => {
      if (e.target.checked) selectedHosts.add(id); else selectedHosts.delete(id);
      tr.classList.toggle('selected', e.target.checked);
      updateSelectedCount();
    });
  });
  document.getElementById('upd-all')?.addEventListener('change', e => {
    updatesTable.querySelectorAll('.upd-pick:not(:disabled)').forEach(cb => {
      cb.checked = e.target.checked;
      const id = +cb.closest('tr').dataset.id;
      if (e.target.checked) selectedHosts.add(id); else selectedHosts.delete(id);
      cb.closest('tr').classList.toggle('selected', e.target.checked);
    });
    updateSelectedCount();
  });
  updateSelectedCount();
}

function updateSelectedCount() {
  document.getElementById('updates-selected').textContent = `${selectedHosts.size} selected`;
}

document.querySelectorAll('.updates-toolbar [data-job]').forEach(btn => {
  btn.addEventListener('click', () => {
    if (!selectedHosts.size) {
      alert('Select one or more hosts first (checkboxes on the left).');
      return;
    }
    const params = btn.dataset.job === 'updates'
      ? { security_only: !!btn.dataset.security, reboot: document.getElementById('updates-reboot').checked }
      : {};
    startJobs([...selectedHosts], btn.dataset.job, params);
  });
});

// ---- Starting jobs (shared with the host Details panel) ----

function jobDescription(type, params) {
  if (type !== 'updates') return JOB_LABELS[type];
  return (params.security_only ? 'Install security updates' : 'Install all updates')
    + (params.reboot ? ', then reboot if required' : ' (no reboot)');
}

async function startJobs(hostIds, type, params = {}) {
  const hosts = hostIds.map(id => hostsById.get(id)).filter(Boolean);
  const willReboot = type === 'reboot' || (type === 'updates' && params.reboot);
  const lines = [`${jobDescription(type, params)} on ${hosts.length} host${hosts.length === 1 ? '' : 's'}:`,
    ...hosts.map(h => '  • ' + hostTitle(h))];
  if (willReboot) {
    for (const h of hosts) {
      if (h.agent && h.agent.hypervisor) lines.push(`\n⚠ ${hostTitle(h)} runs Proxmox VE: rebooting it restarts all of its virtual machines.`);
      if (h.ip === location.hostname) lines.push(`\n⚠ ${hostTitle(h)} runs WebMonitor itself: this page will be unavailable while it reboots.`);
    }
  }
  if (type === 'updates' || type === 'reboot') lines.push('\nThese hosts are muted while this runs, so you won\'t get alerts for it.');
  if (!confirm(lines.join('\n') + '\n\nContinue?')) return null;

  try {
    const resp = await api('api/jobs.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ hostIds, type, params }),
    });
    const data = await resp.json();
    if (!resp.ok) throw new Error(data.error || 'Could not start the job');
    if (data.skipped.length) {
      alert(`Started on ${data.created.length} host(s). Skipped:\n` +
        data.skipped.map(s => `  • ${s.host}: ${s.reason}`).join('\n'));
    }
    selectedHosts.clear();
    loadJobs();
    return data;
  } catch (err) {
    alert(err.message);
    return null;
  }
}

// ---- Job history ----

async function loadJobs() {
  try {
    const resp = await api('api/jobs.php');
    const data = await resp.json();
    if (!resp.ok) return;
    recentJobs = data.jobs;
    latestJobByHost = data.latestByHost || {};
    renderJobs();
    if (!updatesView.classList.contains('hidden')) renderUpdates();
  } catch (err) {
    console.error('Failed to load jobs', err);
  }
}

function fmtDurationMs(j) {
  if (!j.startedAt) return '—';
  const sec = Math.round(((j.finishedAt || Date.now()) - j.startedAt) / 1000);
  return sec < 60 ? `${sec}s` : `${Math.floor(sec / 60)}m ${sec % 60}s`;
}

function jobRowsHtml(jobs, { showHost }) {
  return jobs.map(j => `
    <tr data-job="${j.id}">
      <td class="nowrap muted small">${escapeHtml(new Date(j.createdAt).toLocaleString([], { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' }))}</td>
      ${showHost ? `<td>${escapeHtml(j.hostName === j.hostIp ? j.hostIp : j.hostName)}</td>` : ''}
      <td>${escapeHtml(j.label)}<div class="muted small">${j.scheduleName ? '⏰ ' + escapeHtml(j.scheduleName)
        : j.createdBy ? 'by ' + escapeHtml(j.createdBy) : ''}</div></td>
      <td class="nowrap"><span class="job-status ${JOB_STATUS_CLASS[j.status] || ''}">${escapeHtml(j.status)}</span>
        <span class="muted small">${fmtDurationMs(j)}</span></td>
      <td class="wrap small">${escapeHtml(j.summary || '')}</td>
      <td class="row-actions">
        <button type="button" class="section-btn job-output">Output</button>
        ${IS_ADMIN && j.status === 'queued' ? '<button type="button" class="section-btn danger job-cancel">Cancel</button>' : ''}
      </td>
    </tr>`).join('');
}

function wireJobRows(container) {
  container.querySelectorAll('tr[data-job]').forEach(tr => {
    const id = +tr.dataset.job;
    tr.querySelector('.job-output').addEventListener('click', () => toggleOutput(tr, id));
    tr.querySelector('.job-cancel')?.addEventListener('click', async () => {
      const resp = await api(`api/jobs.php?id=${id}`, { method: 'DELETE' });
      if (!resp.ok) alert((await resp.json().catch(() => ({}))).error || 'Could not cancel');
      loadJobs();
      const host = recentJobs.find(j => j.id === id)?.hostId;
      if (host && openHostId === host) loadHostJobs(host);
    });
    if (openOutputs.has(id)) toggleOutput(tr, id, true);
  });
}

function renderJobs() {
  if (!recentJobs.length) {
    jobsTable.innerHTML = '<div class="switch-info-status">No jobs yet.</div>';
    return;
  }
  jobsTable.innerHTML = `<div class="details-table-wrap"><table class="switch-info-table">
    <thead><tr><th>When</th><th>Host</th><th>Action</th><th>Status</th><th>Result</th><th></th></tr></thead>
    <tbody>${jobRowsHtml(recentJobs, { showHost: true })}</tbody></table></div>`;
  wireJobRows(jobsTable);
}

// Expands a row with the job's output; refreshes it while the job runs.
async function toggleOutput(tr, id, reopen = false) {
  const next = tr.nextElementSibling;
  if (!reopen && next && next.classList.contains('job-output-row')) {
    next.remove();
    clearInterval(openOutputs.get(id));
    openOutputs.delete(id);
    return;
  }
  const row = document.createElement('tr');
  row.className = 'job-output-row';
  row.innerHTML = `<td colspan="${tr.children.length}"><pre class="job-output-pre">Loading…</pre></td>`;
  tr.after(row);
  const pre = row.querySelector('pre');
  const refresh = async () => {
    try {
      const data = await (await api(`api/jobs.php?id=${id}`)).json();
      const atBottom = pre.scrollTop + pre.clientHeight >= pre.scrollHeight - 8;
      pre.textContent = data.job.output || (data.job.status === 'queued'
        ? 'Waiting for the agent to pick this up (it checks every 20 seconds)…' : '(no output)');
      if (atBottom) pre.scrollTop = pre.scrollHeight;
      if (!['queued', 'running'].includes(data.job.status)) {
        clearInterval(openOutputs.get(id));
        openOutputs.set(id, null);
      }
    } catch (err) {
      pre.textContent = 'Could not load output: ' + err.message;
    }
  };
  clearInterval(openOutputs.get(id));
  openOutputs.set(id, setInterval(refresh, 5000));
  refresh();
}

// ---- Host Details: actions + recent jobs for one host ----

function renderHostJobControls(host) {
  const a = host.agent;
  if (!a) return '';
  if (!versionAtLeast(a.version, JOB_MIN_AGENT)) {
    return `<h4>Actions</h4><div class="switch-info-status">This agent (v${escapeHtml(a.version || '?')}) can't run update
      jobs yet. ${IS_ADMIN ? 'Use <strong>Reinstall…</strong> above once to upgrade it.' : ''}</div>`;
  }
  const buttons = IS_ADMIN ? `
    <div class="host-job-actions">
      <button type="button" class="section-btn" data-hjob="refresh">Check for updates</button>
      <button type="button" class="section-btn" data-hjob="updates" data-security="1">Install security updates</button>
      <button type="button" class="section-btn" data-hjob="updates">Install all updates</button>
      <button type="button" class="section-btn" data-hjob="reboot">Reboot</button>
      <button type="button" class="section-btn" data-hjob="agent_upgrade">Upgrade agent</button>
      <label class="check small"><input type="checkbox" id="hjob-reboot" /> Reboot afterwards if required</label>
    </div>` : '';
  return `<h4>Actions</h4>${buttons}<div id="host-jobs"><div class="switch-info-status">Loading jobs…</div></div>`;
}

function wireHostJobControls(hostId) {
  document.querySelectorAll('[data-hjob]').forEach(btn => btn.addEventListener('click', async () => {
    const params = btn.dataset.hjob === 'updates'
      ? { security_only: !!btn.dataset.security, reboot: document.getElementById('hjob-reboot').checked }
      : {};
    if (await startJobs([hostId], btn.dataset.hjob, params)) loadHostJobs(hostId);
  }));
  loadHostJobs(hostId);
}

async function loadHostJobs(hostId) {
  const el = document.getElementById('host-jobs');
  if (!el) return;
  try {
    const data = await (await api(`api/jobs.php?host=${hostId}`)).json();
    if (openHostId !== hostId || !document.getElementById('host-jobs')) return;
    const jobs = data.jobs.slice(0, 8);
    el.innerHTML = jobs.length ? `<div class="details-table-wrap"><table class="switch-info-table">
      <thead><tr><th>When</th><th>Action</th><th>Status</th><th>Result</th><th></th></tr></thead>
      <tbody>${jobRowsHtml(jobs, { showHost: false })}</tbody></table></div>`
      : '<div class="switch-info-status">No jobs have run on this host yet.</div>';
    wireJobRows(el);
  } catch (err) {
    el.innerHTML = `<div class="switch-info-status details-error">${escapeHtml(err.message)}</div>`;
  }
}

// ---- Schedules (update windows) ----

const schedulesList = document.getElementById('schedules-list');
const scheduleAdd = document.getElementById('schedule-add');
const scheduleForm = document.getElementById('schedule-form');
const WEEKDAYS = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
const SCHEDULE_ACTIONS = {
  security: { jobType: 'updates', security_only: true, label: 'Install security updates' },
  all: { jobType: 'updates', security_only: false, label: 'Install all updates' },
  refresh: { jobType: 'refresh', label: 'Check for updates' },
  reboot: { jobType: 'reboot', label: 'Reboot' },
};
const TARGET_LABELS = { all: 'All agent hosts', linux: 'All Linux hosts', windows: 'All Windows hosts' };
let schedules = [];
let scheduleConflicts = [];
let editingSchedule = null;
let previewTimer = null;

async function loadSchedules() {
  try {
    const data = await (await api('api/schedules.php')).json();
    schedules = data.schedules || [];
    scheduleConflicts = data.conflicts || [];
    document.getElementById('schedules-tz').textContent = data.timezone ? `· times are server time (${data.timezone})` : '';
    renderSchedules();
    renderScheduleWeek();
    renderScheduleConflicts();
    renderScheduleSummary();
    if (IS_ADMIN && !scheduleForm.innerHTML) renderScheduleForm();
  } catch (err) {
    schedulesList.innerHTML = `<div class="switch-info-status details-error">${escapeHtml(err.message)}</div>`;
  }
}

function conflictLevel(id) {
  let level = null;
  for (const c of scheduleConflicts) {
    if (c.scheduleIds.includes(id)) level = c.level === 'error' || level === 'error' ? 'error' : 'warning';
  }
  return level;
}

function openScheduleEditor(s) {
  if (!IS_ADMIN) return;
  editingSchedule = s;
  renderScheduleForm(s);
  scheduleAdd.open = true;
  scheduleAdd.scrollIntoView({ block: 'nearest' });
}

// Weekly calendar: one column per day, schedules as blocks at their time.
function renderScheduleWeek() {
  const week = document.getElementById('schedule-week');
  const cols = WEEKDAYS.map((day, i) => {
    const items = schedules.filter(s => s.days & (1 << i)).sort((a, b) => a.atTime.localeCompare(b.atTime));
    return `<div class="week-day"><div class="week-day-name">${day}</div>${items.map(s => {
      const lvl = s.enabled ? conflictLevel(s.id) : null;
      return `<button type="button" class="week-item ${s.enabled ? '' : 'off'} ${lvl ? 'conflict-' + lvl : ''}" data-id="${s.id}"
        title="${escapeHtml(s.label + ' · ' + scheduleTargets(s))}${s.enabled ? '' : ' (disabled)'}" ${IS_ADMIN ? '' : 'tabindex="-1"'}>
        <span class="week-time">${escapeHtml(s.atTime)}</span>
        <span class="week-name">${lvl === 'error' ? '⛔ ' : lvl ? '⚠ ' : ''}${escapeHtml(s.name)}</span></button>`;
    }).join('') || '<div class="week-empty">—</div>'}</div>`;
  }).join('');
  week.innerHTML = cols;
  week.querySelectorAll('.week-item').forEach(b => b.addEventListener('click', () => {
    const s = schedules.find(x => x.id === +b.dataset.id);
    if (s) openScheduleEditor(s);
  }));
}

function conflictItems(list) {
  return list.map(c => `<li class="conflict ${c.level}">${c.level === 'error' ? '⛔' : '⚠'} ${escapeHtml(c.message)}</li>`).join('');
}

function renderScheduleConflicts() {
  const el = document.getElementById('schedule-conflicts');
  if (!scheduleConflicts.length) {
    el.innerHTML = schedules.some(s => s.enabled) ? '<p class="hint ok-text">✓ No conflicts between enabled schedules.</p>' : '';
    return;
  }
  el.innerHTML = `<ul class="conflict-list">${conflictItems(scheduleConflicts)}</ul>
    ${IS_ADMIN ? '<p class="hint">Click a schedule in the calendar (or Edit below) to change its time, days or hosts.</p>' : ''}`;
}

// One line in the Updates view pointing at Settings -> Schedules.
function renderScheduleSummary() {
  const el = document.getElementById('schedule-summary');
  const on = schedules.filter(s => s.enabled);
  const next = on.filter(s => s.nextRun).sort((a, b) => a.nextRun - b.nextRun)[0];
  const errors = scheduleConflicts.filter(c => c.level === 'error').length;
  const warns = scheduleConflicts.length - errors;
  el.innerHTML = `<span>⏰ ${on.length ? `${on.length} active schedule${on.length === 1 ? '' : 's'}` : 'No active schedules'}${
    next ? ` · next: <strong>${escapeHtml(next.name)}</strong> ${escapeHtml(fmtWhen(next.nextRun))}` : ''}${
    errors ? ` · <span class="crit-text">${errors} conflict${errors === 1 ? '' : 's'}</span>` : ''}${
    warns ? ` · <span class="warn-text">${warns} warning${warns === 1 ? '' : 's'}</span>` : ''}</span>
    <button type="button" class="section-btn" id="manage-schedules">${IS_ADMIN ? 'Manage schedules' : 'View schedules'}</button>`;
  document.getElementById('manage-schedules').addEventListener('click', () => openSettings('schedules'));   // settings.js
}

function scheduleTargets(s) {
  if (s.targets !== 'hosts') return TARGET_LABELS[s.targets];
  return s.hostIds.map(id => {
    const h = hostsById.get(id);
    return h ? (h.name === h.ip ? h.ip : h.name) : `#${id}`;
  }).join(', ');
}

function fmtWhen(ts) {
  return new Date(ts).toLocaleString([], { weekday: 'short', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' });
}

function renderSchedules() {
  if (!schedules.length) {
    schedulesList.innerHTML = `<div class="switch-info-status">No schedules yet.${IS_ADMIN
      ? ' Add one below, e.g. security updates every Sunday at 03:00.' : ''}</div>`;
    return;
  }
  schedulesList.innerHTML = `<div class="details-table-wrap updates-wrap"><table class="switch-info-table">
    <thead><tr><th>On</th><th>Schedule</th><th>When</th><th>Hosts</th><th>Next run</th><th>Last run</th><th></th></tr></thead>
    <tbody>${schedules.map(s => `
      <tr data-id="${s.id}" class="${s.enabled ? '' : 'disabled-row'} ${s.enabled && conflictLevel(s.id) ? 'conflict-row-' + conflictLevel(s.id) : ''}">
        <td><input type="checkbox" class="sched-enabled" ${s.enabled ? 'checked' : ''} ${IS_ADMIN ? '' : 'disabled'} title="Enabled" /></td>
        <td><strong>${escapeHtml(s.name)}</strong><div class="muted small">${escapeHtml(s.label)}</div></td>
        <td class="nowrap">${escapeHtml(s.daysLabel)}<div class="muted small">${escapeHtml(s.atTime)}</div></td>
        <td class="wrap small">${escapeHtml(scheduleTargets(s))}</td>
        <td class="nowrap small">${s.nextRun ? escapeHtml(fmtWhen(s.nextRun)) : '<span class="muted">—</span>'}</td>
        <td class="wrap small">${s.lastRun ? `${escapeHtml(fmtWhen(s.lastRun.startedAt))}${s.lastRun.manual ? ' (manual)' : ''}
          <div class="muted">${escapeHtml(s.lastRun.summary || (s.lastRun.finishedAt ? '' : 'running…'))}</div>` : '<span class="muted">never</span>'}</td>
        <td class="row-actions">${IS_ADMIN ? `
          <button type="button" class="section-btn sched-run">Run now</button>
          <button type="button" class="section-btn sched-edit">Edit</button>
          <button type="button" class="section-btn danger sched-delete">Delete</button>` : ''}</td>
      </tr>`).join('')}</tbody></table></div>`;

  schedulesList.querySelectorAll('tr[data-id]').forEach(tr => {
    const s = schedules.find(x => x.id === +tr.dataset.id);
    tr.querySelector('.sched-enabled').addEventListener('change', async e => {
      const resp = await api(`api/schedules.php?id=${s.id}`, {
        method: 'PATCH', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ enabled: e.target.checked }),
      });
      if (!resp.ok) {
        e.target.checked = !e.target.checked;
        alert((await resp.json().catch(() => ({}))).error || 'Could not change the schedule');
      }
      loadSchedules();
    });
    tr.querySelector('.sched-run')?.addEventListener('click', async () => {
      if (!confirm(`Run "${s.name}" now?\n\n${s.label} on: ${scheduleTargets(s)}`)) return;
      const resp = await api(`api/schedules.php?id=${s.id}&run=1`, { method: 'POST' });
      const data = await resp.json().catch(() => ({}));
      if (!resp.ok) { alert(data.error || 'Could not run the schedule'); return; }
      alert(`Queued on ${data.created.length} host(s).` + (data.skipped.length
        ? '\nSkipped:\n' + data.skipped.map(k => `  • ${k.host}: ${k.reason}`).join('\n') : ''));
      loadSchedules();
      loadJobs();
    });
    tr.querySelector('.sched-edit')?.addEventListener('click', () => openScheduleEditor(s));
    tr.querySelector('.sched-delete')?.addEventListener('click', async () => {
      if (!confirm(`Delete the schedule "${s.name}"? Jobs it already started are kept in the history.`)) return;
      await api(`api/schedules.php?id=${s.id}`, { method: 'DELETE' });
      loadSchedules();
    });
  });
}

function renderScheduleForm(s = null) {
  const action = !s ? 'security'
    : s.jobType === 'updates' ? (s.params.security_only ? 'security' : 'all') : s.jobType;
  const days = s ? s.days : 64;   // Sunday
  const targets = s ? s.targets : 'hosts';
  const picked = new Set(s ? s.hostIds : []);
  const hosts = agentHosts();
  scheduleForm.innerHTML = `
    <div class="form-grid">
      <label>Name <input id="sf-name" maxlength="60" required value="${escapeHtml(s ? s.name : '')}" placeholder="e.g. Sunday security updates" /></label>
      <label>Action <select id="sf-action">
        ${Object.entries(SCHEDULE_ACTIONS).map(([k, a]) => `<option value="${k}" ${k === action ? 'selected' : ''}>${a.label}</option>`).join('')}
      </select></label>
      <label>Time (24h) <input id="sf-time" type="time" required value="${escapeHtml(s ? s.atTime : '03:00')}" /></label>
    </div>
    <label class="check" id="sf-reboot-wrap"><input type="checkbox" id="sf-reboot" ${s && s.params.reboot ? 'checked' : ''} />
      Reboot afterwards if required</label>
    <div><div class="field-label">Days</div><div class="choice-group">
      ${WEEKDAYS.map((d, i) => `<label><input type="checkbox" class="sf-day" value="${1 << i}" ${days & (1 << i) ? 'checked' : ''} /> ${d}</label>`).join('')}
    </div></div>
    <div><div class="field-label">Hosts</div><div class="choice-group">
      ${[['hosts', 'Pick hosts'], ['all', 'All agent hosts'], ['linux', 'All Linux'], ['windows', 'All Windows']].map(([v, l]) =>
        `<label><input type="radio" name="sf-targets" value="${v}" ${v === targets ? 'checked' : ''} /> ${l}</label>`).join('')}
    </div></div>
    <div id="sf-hosts" class="sf-hosts">${hosts.map(h => `
      <label class="check"><input type="checkbox" class="sf-host" value="${h.id}" ${picked.has(h.id) ? 'checked' : ''} />
        ${escapeHtml(h.name === h.ip ? h.ip : h.name)} <span class="muted small">${escapeHtml(h.ip)}${h.agent && h.agent.hypervisor ? ' · Proxmox host' : ''}</span></label>`).join('')}
    </div>
    <p class="hint">Group-based targets ("All Linux" etc.) include hosts whose agent is added later. Hosts muted for
      maintenance are skipped. Put hosts that depend on each other, like the Proxmox host and its VMs, in separate
      schedules at different times. Avoid 01:00–03:00 on the nights clocks change: a time that doesn't exist runs an
      hour later, and one that happens twice runs once.</p>
    <div id="sf-conflicts" aria-live="polite"></div>
    <div class="form-row">
      <button type="submit" class="primary-btn">${s ? 'Save changes' : 'Add schedule'}</button>
      ${s ? '<button type="button" class="section-btn" id="sf-cancel">Cancel</button>' : ''}
      <span class="form-msg" id="sf-msg"></span>
    </div>`;
  scheduleAdd.querySelector('summary').textContent = s ? `Edit “${s.name}”` : 'Add a schedule…';
  const syncVisibility = () => {
    const a = SCHEDULE_ACTIONS[document.getElementById('sf-action').value] || SCHEDULE_ACTIONS.security;
    document.getElementById('sf-reboot-wrap').style.display = a.jobType === 'updates' ? '' : 'none';
    const t = scheduleForm.querySelector('input[name="sf-targets"]:checked').value;
    document.getElementById('sf-hosts').style.display = t === 'hosts' ? '' : 'none';
  };
  document.getElementById('sf-action').addEventListener('change', syncVisibility);
  scheduleForm.querySelectorAll('input[name="sf-targets"]').forEach(r => r.addEventListener('change', syncVisibility));
  document.getElementById('sf-cancel')?.addEventListener('click', () => {
    editingSchedule = null;
    renderScheduleForm();
    scheduleAdd.open = false;
  });
  syncVisibility();
  if (s) queuePreview();
}

// Re-check conflicts as the form changes (attached once; the form's
// contents are re-rendered, the form element itself stays).
scheduleForm.addEventListener('input', () => queuePreview());
scheduleForm.addEventListener('change', () => queuePreview());

function scheduleFormBody() {
  const action = SCHEDULE_ACTIONS[document.getElementById('sf-action').value] || SCHEDULE_ACTIONS.security;
  return {
    name: document.getElementById('sf-name').value.trim(),
    jobType: action.jobType,
    params: action.jobType === 'updates'
      ? { security_only: action.security_only, reboot: document.getElementById('sf-reboot').checked } : {},
    days: [...scheduleForm.querySelectorAll('.sf-day:checked')].reduce((n, cb) => n | +cb.value, 0),
    atTime: document.getElementById('sf-time').value,
    targets: scheduleForm.querySelector('input[name="sf-targets"]:checked').value,
    hostIds: [...scheduleForm.querySelectorAll('.sf-host:checked')].map(cb => +cb.value),
  };
}

// Asks the server what conflicts this schedule would have if saved.
function queuePreview() {
  clearTimeout(previewTimer);
  previewTimer = setTimeout(async () => {
    const box = document.getElementById('sf-conflicts');
    if (!box) return;
    const body = { ...scheduleFormBody(), id: editingSchedule ? editingSchedule.id : 0, name: document.getElementById('sf-name').value.trim() || 'this schedule' };
    try {
      const data = await (await api('api/schedules.php?preview=1', {
        method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body),
      })).json();
      box.innerHTML = data.invalid ? ''
        : data.conflicts.length ? `<ul class="conflict-list">${conflictItems(data.conflicts)}</ul>`
        : '<p class="hint ok-text">✓ No conflicts with other schedules.</p>';
    } catch (err) {
      box.innerHTML = '';
    }
  }, 400);
}

scheduleForm.addEventListener('submit', async e => {
  e.preventDefault();
  const body = scheduleFormBody();
  const msg = document.getElementById('sf-msg');
  try {
    const resp = await api(editingSchedule ? `api/schedules.php?id=${editingSchedule.id}` : 'api/schedules.php', {
      method: editingSchedule ? 'PATCH' : 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(body),
    });
    const data = await resp.json().catch(() => ({}));
    if (!resp.ok) throw new Error(data.error || 'Could not save the schedule');
    editingSchedule = null;
    renderScheduleForm();
    scheduleAdd.open = false;
    loadSchedules();
  } catch (err) {
    msg.textContent = err.message;
    msg.className = 'form-msg err';
  }
});

loadSchedules();
if (location.hash === '#updates') showView('updates');
