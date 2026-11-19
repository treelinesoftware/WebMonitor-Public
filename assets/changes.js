// Change management: change records (Updates view), their settings
// (Settings -> Change management) and the audit log (Reports -> Audit log).
// Uses api(), escapeHtml(), IS_ADMIN from app.js; jsonApi(), setMsg() from settings.js.

const CHANGE_BADGE = {
  planned: 'info', pending_approval: 'warn', approved: 'ok', rejected: 'bad', in_progress: 'info',
  completed: 'ok', partial: 'warn', failed: 'bad', skipped: 'muted', cancelled: 'muted',
};
let changesFilter = 'open';
let changeSettings = null;
const openChanges = new Set();   // expanded rows, kept across refreshes

function fmtChangeTime(ms) {
  return ms ? new Date(ms).toLocaleString([], { weekday: 'short', day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' }) : '—';
}

function changeBadge(c) {
  return `<span class="chg-badge chg-${CHANGE_BADGE[c.status] || 'muted'}">${escapeHtml(c.statusLabel)}</span>`;
}

// ---- Updates -> Change records ----

async function loadChanges() {
  const box = document.getElementById('changes-table');
  if (!box) return;
  try {
    const resp = await api(`api/changes.php?status=${changesFilter}&limit=${changesFilter === 'open' ? 200 : 100}`);
    const data = await resp.json();
    if (!resp.ok) throw new Error(data.error || 'Could not load change records');
    changeSettings = data.settings;
    const pend = document.getElementById('changes-pending');
    pend.textContent = data.pendingApproval ? `${data.pendingApproval} awaiting approval` : '';
    pend.classList.toggle('warn-text', !!data.pendingApproval);
    document.getElementById('changes-hint').innerHTML = `${data.settings.require_approval
      ? '<strong>Approval is on:</strong> scheduled windows run only once approved.' : 'Scheduled windows run unless rejected.'}
      ${data.settings.lead_hours ? `Records are raised ${data.settings.lead_hours} h before each window.` : 'Records are raised when each window runs.'}
      ${data.emailReady ? '' : IS_ADMIN ? ' <span class="warn-text">Change emails are off: set a change list and an email channel in Settings → Change management.</span>' : ''}`;
    renderChanges(data.changes);
  } catch (err) {
    box.innerHTML = `<div class="switch-info-status details-error">${escapeHtml(err.message)}</div>`;
  }
}

function renderChanges(list) {
  const box = document.getElementById('changes-table');
  if (!list.length) {
    box.innerHTML = `<div class="switch-info-status">${changesFilter === 'open' ? 'No open changes.' : 'No change records yet.'}
      Each update window and manual update, reboot or agent upgrade gets one.</div>`;
    return;
  }
  box.innerHTML = `<div class="details-table-wrap updates-wrap"><table class="switch-info-table chg-table">
    <thead><tr><th>Change</th><th>What</th><th>When</th><th>Hosts</th><th>Status</th><th>Requested / decided</th><th></th></tr></thead>
    <tbody>${list.map(c => `
      <tr data-id="${c.id}" class="chg-row">
        <td class="nowrap"><button type="button" class="link-btn chg-toggle" aria-expanded="${openChanges.has(c.id)}">${escapeHtml(c.number)}</button></td>
        <td><strong>${escapeHtml(c.title)}</strong><div class="muted small">${escapeHtml(c.action)}${c.packageCount ? ` · ${c.packageCount} package${c.packageCount === 1 ? '' : 's'}` : ''}</div></td>
        <td class="nowrap small">${escapeHtml(fmtChangeTime(c.plannedAt))}<div class="muted">${c.source === 'schedule' ? 'scheduled' : 'manual'}</div></td>
        <td class="wrap small">${escapeHtml(c.hosts.slice(0, 4).join(', '))}${c.hosts.length > 4 ? ` +${c.hosts.length - 4}` : ''}</td>
        <td>${changeBadge(c)}</td>
        <td class="small">${escapeHtml(c.requestedBy || 'WebMonitor')}${c.decidedBy ? `<div class="muted">${c.status === 'rejected' ? 'rejected' : 'approved'} by ${escapeHtml(c.decidedBy)}</div>` : ''}</td>
        <td class="row-actions">${IS_ADMIN && c.status === 'pending_approval' ? '<button type="button" class="section-btn chg-approve">Approve</button>' : ''}
          ${IS_ADMIN && ['planned', 'pending_approval', 'approved'].includes(c.status) ? '<button type="button" class="section-btn danger chg-reject">Reject</button>' : ''}</td>
      </tr>
      <tr class="chg-detail ${openChanges.has(c.id) ? '' : 'hidden'}" data-detail="${c.id}"><td colspan="7"><div class="chg-detail-body">Loading…</div></td></tr>`).join('')}
    </tbody></table></div>`;
  box.querySelectorAll('tr.chg-row').forEach(tr => {
    const id = +tr.dataset.id;
    tr.querySelector('.chg-toggle').addEventListener('click', () => toggleChange(id));
    tr.querySelector('.chg-approve')?.addEventListener('click', () => decideChange(id, 'approve'));
    tr.querySelector('.chg-reject')?.addEventListener('click', () => decideChange(id, 'reject'));
    if (openChanges.has(id)) loadChangeDetail(id);
  });
}

function toggleChange(id) {
  const row = document.querySelector(`#changes-table tr[data-detail="${id}"]`);
  const open = row.classList.toggle('hidden') === false;
  document.querySelector(`#changes-table tr[data-id="${id}"] .chg-toggle`).setAttribute('aria-expanded', open);
  if (open) { openChanges.add(id); loadChangeDetail(id); } else openChanges.delete(id);
}

async function loadChangeDetail(id) {
  const body = document.querySelector(`#changes-table tr[data-detail="${id}"] .chg-detail-body`);
  if (!body) return;
  try {
    const resp = await api(`api/changes.php?id=${id}`);
    const data = await resp.json();
    if (!resp.ok) throw new Error(data.error || 'Could not load the change');
    body.innerHTML = renderChangeDetail(data.change);
  } catch (err) {
    body.innerHTML = `<span class="details-error">${escapeHtml(err.message)}</span>`;
  }
}

function pkgLine(p) {
  return `${escapeHtml(p.name)}${p.new ? ` <span class="muted">${p.current ? `${escapeHtml(p.current)} → ` : ''}${escapeHtml(p.new)}</span>` : ''}${
    p.kb ? ` <span class="muted">${escapeHtml(p.kb)}</span>` : ''}${p.security ? ' <span class="chg-badge chg-warn">security</span>' : ''}`;
}

function renderChangeDetail(c) {
  const facts = [
    ['Status', changeBadge(c)],
    ['Action', escapeHtml(c.action)],
    [c.source === 'schedule' ? 'Window' : 'Started', escapeHtml(fmtChangeTime(c.plannedAt))],
    ['Requested by', escapeHtml(c.requestedBy || 'WebMonitor') + (c.source === 'schedule' ? ' (schedule)' : ' (manual)')],
    c.decidedBy && ['Decision', `${escapeHtml(c.status === 'rejected' ? 'Rejected' : 'Approved')} by ${escapeHtml(c.decidedBy)} · ${escapeHtml(fmtChangeTime(c.decidedAt))}${
      c.decisionNote ? `<div class="muted">${escapeHtml(c.decisionNote)}</div>` : ''}`],
    c.finishedAt && ['Finished', escapeHtml(fmtChangeTime(c.finishedAt))],
    ['Emails', `${c.noticeSentAt ? `notice ${escapeHtml(fmtChangeTime(c.noticeSentAt))}` : 'no notice sent'}${c.reportSentAt ? ` · report ${escapeHtml(fmtChangeTime(c.reportSentAt))}` : ''}`],
  ].filter(Boolean);
  const hostIds = [...new Set([...Object.keys(c.planned || {}), ...Object.keys(c.outcome || {})])];
  const hosts = hostIds.map(hid => {
    const p = (c.planned || {})[hid] || {};
    const o = (c.outcome || {})[hid];
    const v = (c.verification || {})[hid];
    const pk = Array.isArray(p.packages) ? p.packages : null;
    return `<details class="chg-host" ${hostIds.length === 1 ? 'open' : ''}><summary><strong>${escapeHtml((o && o.host) || p.host || '#' + hid)}</strong>
        ${o ? `<span class="port-status ${o.status === 'succeeded' ? 'up' : 'down'}">${escapeHtml(o.status)}</span> <span class="muted small">${escapeHtml(o.summary || '')}</span>` : ''}
        ${pk ? `<span class="muted small">· ${pk.length} planned package${pk.length === 1 ? '' : 's'}</span>` : 'packages' in p ? '<span class="muted small">· no update inventory when planned</span>' : ''}
        ${v ? `<span class="small ${v.stillPending.length ? 'warn-text' : 'ok-text'}">· ${v.stillPending.length ? `${v.stillPending.length} still pending` : 'verified: none still pending'}</span>` : ''}
      </summary>
      ${pk && pk.length ? `<ul class="chg-pkgs">${pk.map(x => `<li>${pkgLine(x)}${v && v.stillPending.includes(x.name) ? ' <span class="warn-text small">still pending</span>' : ''}</li>`).join('')}</ul>` : ''}
      ${o && o.output ? `<details class="chg-output"><summary class="small">Job #${o.jobId} output${o.exitCode != null ? ` · exit ${o.exitCode}` : ''}${
        o.outputSha256 ? ` · <span class="mono" title="SHA-256 of the full output">${escapeHtml(o.outputSha256.slice(0, 16))}…</span>` : ''}</summary><pre class="job-output">${escapeHtml(o.output)}</pre></details>` : ''}
    </details>`;
  }).join('');
  const skipped = (c.skipped || []).length ? `<h4>Skipped</h4><ul class="chg-pkgs">${c.skipped.map(s => `<li>${escapeHtml(s.host)}: <span class="muted">${escapeHtml(s.reason)}</span></li>`).join('')}</ul>` : '';
  const history = (c.history || []).length ? `<h4>History</h4><ol class="chg-history">${c.history.map(h =>
    `<li><span class="muted small">${escapeHtml(new Date(h.ts).toLocaleString())}</span> ${escapeHtml(h.summary)}${h.username ? ` <span class="muted small">— ${escapeHtml(h.username)}</span>` : ''}</li>`).join('')}</ol>` : '';
  return `<dl class="details-grid chg-facts">${facts.map(([k, v]) => `<dt>${k}</dt><dd>${v}</dd>`).join('')}</dl>
    <h4>Hosts</h4>${hosts || '<p class="muted small">No hosts.</p>'}${skipped}${history}`;
}

async function decideChange(id, decision) {
  const verb = decision === 'approve' ? 'Approve' : 'Reject';
  const note = prompt(decision === 'approve' ? 'Approve this change? Optional note:' : 'Reject this change — it will not run. Reason (required):', '');
  if (note === null) return;
  try {
    await jsonApi(`api/changes.php?id=${id}&decision=${decision}`, 'POST', { note });   // settings.js
    openChanges.add(id);
    loadChanges();
  } catch (err) {
    alert(`${verb}: ${err.message}`);
  }
}

document.querySelectorAll('[data-changes]').forEach(b => b.addEventListener('click', () => {
  changesFilter = b.dataset.changes;
  document.querySelectorAll('[data-changes]').forEach(x => x.classList.toggle('active', x === b));
  loadChanges();
}));
document.getElementById('changes-csv').addEventListener('click', () => {
  location.href = `api/changes.php?format=csv&status=${changesFilter}`;
});

// ---- Settings -> Change management ----

async function loadChangeSettings() {
  try {
    const data = await jsonApi('api/changes.php?what=settings', 'GET');
    const s = data.settings;
    document.getElementById('cm-recipients').value = s.recipients;
    document.getElementById('cm-lead').value = s.lead_hours;
    document.getElementById('cm-approval').checked = s.require_approval;
    document.getElementById('cm-manual').checked = s.notify_manual;
    showChangeEmailWarning(data.emailReady, s.recipients);
  } catch (err) {
    setMsg(document.getElementById('cm-msg'), err.message, false);
  }
}

function showChangeEmailWarning(ready, recipients) {
  document.getElementById('cm-email-warning').innerHTML = ready ? ''
    : `<p class="hint warn-text">${recipients ? 'Change emails need an enabled email channel: add one under Notifications (its SMTP settings are used; its To address is not).'
      : 'No change list yet — change records and the audit log still work, but no change emails are sent.'}</p>`;
}

document.getElementById('cm-form').addEventListener('submit', async e => {
  e.preventDefault();
  const msg = document.getElementById('cm-msg');
  try {
    const data = await jsonApi('api/changes.php?what=settings', 'PUT', {
      recipients: document.getElementById('cm-recipients').value,
      lead_hours: Number(document.getElementById('cm-lead').value || 0),
      require_approval: document.getElementById('cm-approval').checked,
      notify_manual: document.getElementById('cm-manual').checked,
    });
    document.getElementById('cm-recipients').value = data.settings.recipients;
    showChangeEmailWarning(data.emailReady, data.settings.recipients);
    setMsg(msg, 'Saved');
  } catch (err) {
    setMsg(msg, err.message, false);
  }
});

document.getElementById('cm-test').addEventListener('click', async () => {
  const msg = document.getElementById('cm-msg');
  setMsg(msg, 'Sending…');
  try {
    await jsonApi('api/changes.php?what=test', 'POST', {});
    setMsg(msg, 'Test email sent to the change list (save first if you just changed it)');
  } catch (err) {
    setMsg(msg, err.message, false);
  }
});

// ---- Reports -> Audit log ----

let auditEntries = [];
let auditTimer = null;

function auditQuery() {
  const p = new URLSearchParams();
  const v = id => document.getElementById(id).value.trim();
  if (v('audit-q')) p.set('q', v('audit-q'));
  if (v('audit-user')) p.set('user', v('audit-user'));
  if (v('audit-action')) p.set('action', v('audit-action'));
  if (v('audit-outcome')) p.set('outcome', v('audit-outcome'));
  if (v('audit-from')) p.set('from', new Date(v('audit-from') + 'T00:00:00').getTime());
  if (v('audit-to')) p.set('to', new Date(v('audit-to') + 'T23:59:59.999').getTime());
  return p;
}

async function loadAuditLog(more = false) {
  const box = document.getElementById('audit-body');
  const p = auditQuery();
  p.set('limit', 100);
  if (more && auditEntries.length) p.set('before', auditEntries[auditEntries.length - 1].id);
  if (!more) box.innerHTML = '<div class="switch-info-status">Loading…</div>';
  try {
    const resp = await api('api/audit.php?' + p);
    const data = await resp.json();
    if (!resp.ok) throw new Error(data.error || 'Could not load the audit log');
    auditEntries = more ? auditEntries.concat(data.entries) : data.entries;
    const sel = document.getElementById('audit-user');
    const keep = sel.value;
    sel.innerHTML = '<option value="">Anyone</option><option value="(system)">WebMonitor (automatic)</option>'
      + data.users.map(u => `<option value="${escapeHtml(u)}">${escapeHtml(u)}</option>`).join('');
    sel.value = keep;
    renderAuditLog(data.more);
  } catch (err) {
    box.innerHTML = `<div class="switch-info-status details-error">${escapeHtml(err.message)}</div>`;
  }
}

function renderAuditLog(more) {
  const box = document.getElementById('audit-body');
  document.getElementById('audit-meta').textContent = `${auditEntries.length}${more ? '+' : ''} entr${auditEntries.length === 1 ? 'y' : 'ies'} shown, newest first`;
  if (!auditEntries.length) { box.innerHTML = '<div class="switch-info-status">Nothing matches.</div>'; return; }
  box.innerHTML = `<div class="details-table-wrap"><table class="switch-info-table audit-table">
    <thead><tr><th>When</th><th>Who</th><th>What</th><th>Outcome</th></tr></thead>
    <tbody>${auditEntries.map(e => `
      <tr class="audit-${e.outcome}">
        <td class="nowrap small">${escapeHtml(new Date(e.ts).toLocaleString())}</td>
        <td class="small">${e.username ? escapeHtml(e.username) : '<span class="muted">WebMonitor</span>'}${e.ip ? `<div class="muted mono">${escapeHtml(e.ip)}</div>` : ''}</td>
        <td>${escapeHtml(e.summary)}<div class="muted small mono">${escapeHtml(e.action)}${e.target ? ` · ${escapeHtml(e.target)}` : ''}</div>
          ${e.details ? `<details class="audit-details"><summary class="small">Details</summary><pre>${escapeHtml(JSON.stringify(e.details, null, 2))}</pre></details>` : ''}</td>
        <td><span class="chg-badge chg-${e.outcome === 'ok' ? 'ok' : e.outcome === 'denied' ? 'warn' : 'bad'}">${escapeHtml(e.outcome)}</span></td>
      </tr>`).join('')}</tbody></table></div>
    ${more ? '<button type="button" class="section-btn" id="audit-more">Load older entries</button>' : ''}`;
  document.getElementById('audit-more')?.addEventListener('click', () => loadAuditLog(true));
}

document.getElementById('audit-filters').addEventListener('input', () => {
  clearTimeout(auditTimer);
  auditTimer = setTimeout(() => loadAuditLog(), 350);
});
document.getElementById('audit-filters').addEventListener('submit', e => { e.preventDefault(); loadAuditLog(); });
document.getElementById('audit-csv').addEventListener('click', () => {
  location.href = 'api/audit.php?format=csv&' + auditQuery();
});
document.getElementById('audit-verify').addEventListener('click', async () => {
  const meta = document.getElementById('audit-meta');
  meta.textContent = 'Checking the hash chain…';
  try {
    const resp = await api('api/audit.php?verify=1');
    const r = await resp.json();
    if (!resp.ok) throw new Error(r.error || 'Check failed');
    meta.innerHTML = r.ok ? `<span class="ok-text">✓ Intact: all ${r.checked} entries chain correctly.</span>`
      : `<span class="crit-text">✗ Broken at entry #${r.brokenAt}: ${escapeHtml(r.reason)}.</span>`;
  } catch (err) {
    meta.textContent = err.message;
  }
});
