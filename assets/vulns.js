// Security tab: vulnerability scans and the report (api/vulns.php).
// Uses api(), escapeHtml(), fmtAgo(), IS_ADMIN and hostsById from app.js.

const SEV = ['critical', 'high', 'medium', 'low', 'info'];
const SEV_LABEL = { critical: 'Critical', high: 'High', medium: 'Medium', low: 'Low', info: 'Info' };
let secData = null;
let secVisible = false;
let secTimer = null;
const secFilter = { severity: '', ip: '', text: '' };

async function loadSecurity() {
  clearTimeout(secTimer);
  try {
    const resp = await api('api/vulns.php');
    const data = await resp.json();
    if (!resp.ok) throw new Error(data.error || 'Could not load the vulnerability report');
    secData = data;
    updateSecurityCount();
    if (typeof render === 'function' && !document.getElementById('hosts-view').classList.contains('hidden')) render();   // app.js: summary tiles
    if (secVisible) renderSecurity();
  } catch (err) {
    if (secVisible) document.getElementById('sec-body').innerHTML = `<p class="details-error">${escapeHtml(err.message)}</p>`;
  }
  // Follow a running scan; otherwise refresh now and then.
  const busy = secData && secData.scans.some(s => s.status === 'queued' || s.status === 'running');
  secTimer = setTimeout(loadSecurity, busy && secVisible ? 10000 : 300000);
}

function updateSecurityCount() {
  const el = document.getElementById('count-security');
  const n = secData ? secData.summary.critical + secData.summary.high : 0;
  el.textContent = n ? String(n) : '';
  el.classList.toggle('crit', !!(secData && secData.summary.critical));
}

function securityViewShown(visible) {
  secVisible = visible;
  if (visible) { if (secData) renderSecurity(); loadSecurity(); }
}

function scopeLabel(scope) {
  if (scope === 'all') return 'All devices';
  if (scope.startsWith('subnet:')) {
    const s = scope.slice(7);
    return secData && secData.subnets[s] ? `${secData.subnets[s]} · ${s}` : s;
  }
  const h = typeof hostsById !== 'undefined' ? hostsById.get(Number(scope.slice(5))) : null;
  return h ? h.name : scope;
}

function renderScopeSelect() {
  const sel = document.getElementById('sec-scope');
  const n = typeof hostsById !== 'undefined' ? hostsById.size : 0;
  if (sel.options.length && sel.dataset.hosts === String(n)) return;   // keeps the person's choice
  const keep = sel.value;
  sel.dataset.hosts = String(n);
  const subnets = Object.entries(secData.subnets || {}).map(([s, n]) => `<option value="subnet:${escapeHtml(s)}">${escapeHtml(n)} · ${escapeHtml(s)}</option>`);
  const hosts = typeof hostsById !== 'undefined' ? [...hostsById.values()].filter(h => h.ip).sort((a, b) => a.name.localeCompare(b.name)) : [];
  sel.innerHTML = '<option value="all">All devices</option>'
    + (subnets.length ? `<optgroup label="Subnet">${subnets.join('')}</optgroup>` : '')
    + (hosts.length ? `<optgroup label="One device">${hosts.map(h => `<option value="host:${h.id}">${escapeHtml(h.name)}${h.name !== h.ip ? ` · ${escapeHtml(h.ip)}` : ''}</option>`).join('')}</optgroup>` : '');
  if (keep && [...sel.options].some(o => o.value === keep)) sel.value = keep;
}

function renderSecurity() {
  if (!secData) return;
  renderScopeSelect();
  const d = secData;
  const last = d.scans.find(s => s.status === 'done');
  document.getElementById('sec-summary-line').textContent = d.hosts.length
    ? `${d.hosts.length} devices scanned${last ? ` · last complete scan ${fmtAgo(last.finishedAt)} (${scopeLabel(last.scope)})` : ''} · report ${new Date(d.generatedAt).toLocaleString()}`
    : 'No scans yet.';

  // Running / queued scan.
  const active = d.scans.filter(s => s.status === 'queued' || s.status === 'running');
  const failed = d.scans[0] && d.scans[0].status === 'failed' ? d.scans[0] : null;
  document.getElementById('sec-progress').innerHTML = (!d.nmap ? '<p class="details-error">nmap is not installed on the server, so scans can\'t run. Run the upgrade script (it installs nmap) or <code>dnf install nmap</code>.</p>' : '')
    + active.map(s => {
      const pct = s.total ? Math.round(100 * s.done / s.total) : 0;
      return `<div class="sec-run"><strong>${s.status === 'queued' ? 'Waiting to start' : `Scanning ${s.done} of ${s.total}`}</strong> · ${escapeHtml(scopeLabel(s.scope))}
        ${s.current ? `<span class="muted small">· now: ${escapeHtml(s.current)}</span>` : ''}
        ${IS_ADMIN ? `<button type="button" class="section-btn" data-sec-cancel="${s.id}">Cancel</button>` : ''}
        <div class="sec-bar"><span style="width:${pct}%"></span></div></div>`;
    }).join('')
    + (failed && !active.length ? `<p class="details-error">The last scan failed: ${escapeHtml(failed.error || 'unknown error')}</p>` : '');
  document.querySelectorAll('[data-sec-cancel]').forEach(b => b.addEventListener('click', () => cancelSecurityScan(Number(b.dataset.secCancel))));

  document.getElementById('sec-tiles').innerHTML = SEV.map(s => `<button type="button" class="sec-tile ${s} ${secFilter.severity === s ? 'active' : ''}" data-sev="${s}">
      <span class="n">${d.summary[s]}</span><span class="l">${SEV_LABEL[s]}</span></button>`).join('');
  document.querySelectorAll('#sec-tiles [data-sev]').forEach(b => b.addEventListener('click', () => {
    secFilter.severity = secFilter.severity === b.dataset.sev ? '' : b.dataset.sev;
    renderSecurity();
  }));

  if (!d.hosts.length) {
    document.getElementById('sec-body').innerHTML = `<p class="muted">Nothing scanned yet.${IS_ADMIN ? ' Pick what to scan and click <strong>Start scan</strong>.' : ''}</p>`;
    return;
  }
  const hostName = h => h.name ? `${escapeHtml(h.name)} <span class="muted small">${escapeHtml(h.ip)}</span>` : escapeHtml(h.ip);
  const chips = c => SEV.map(s => c[s] ? `<span class="sev-chip ${s}" title="${SEV_LABEL[s]}">${c[s]}</span>` : '').join('');
  const hostsHtml = `<h3>Devices <span class="muted small">most at risk first · click one to see only its findings</span></h3>
    <div class="details-table-wrap"><table class="switch-info-table sec-hosts">
      <thead><tr><th>Device</th><th>Findings</th><th>Open ports</th><th>Scanned</th></tr></thead>
      <tbody>${d.hosts.map(h => `<tr class="${secFilter.ip === h.ip ? 'active' : ''}" data-sec-ip="${escapeHtml(h.ip)}">
        <td>${hostName(h)}${h.error ? ` <span class="badge warn" title="${escapeHtml(h.error)}">partial</span>` : ''}</td>
        <td>${chips(h.counts) || '<span class="muted small">none</span>'}</td>
        <td class="small">${h.openPorts.length ? escapeHtml(h.openPorts.slice(0, 12).join(', ')) + (h.openPorts.length > 12 ? ' …' : '') : '—'}</td>
        <td class="small">${escapeHtml(fmtAgo(h.scannedAt))}</td></tr>`).join('')}</tbody></table></div>`;

  const byIp = new Map(d.hosts.map(h => [h.ip, h]));
  const text = secFilter.text.toLowerCase();
  const list = d.findings.filter(f => (!secFilter.severity || f.severity === secFilter.severity) && (!secFilter.ip || f.ip === secFilter.ip)
    && (!text || [f.title, f.ip, f.service, byIp.get(f.ip)?.name, ...(f.refs || [])].join(' ').toLowerCase().includes(text)))
    .sort((a, b) => SEV.indexOf(a.severity) - SEV.indexOf(b.severity) || (b.cvss ?? 0) - (a.cvss ?? 0));
  const refLink = r => /^CVE-\d{4}-\d+$/.test(r) ? `<a href="https://nvd.nist.gov/vuln/detail/${r}" target="_blank" rel="noopener">${r}</a>` : escapeHtml(r);
  const filters = [secFilter.severity && SEV_LABEL[secFilter.severity], secFilter.ip && (byIp.get(secFilter.ip)?.name || secFilter.ip)].filter(Boolean);
  const findingsHtml = `<h3>Findings <span class="muted small">${list.length}${filters.length ? ` · ${escapeHtml(filters.join(' · '))} <button type="button" class="link-btn no-print" id="sec-clear">show all</button>` : ''}</span></h3>
    <input type="search" id="sec-text" class="topo-filter no-print" placeholder="Filter by title, device, service or CVE…" value="${escapeHtml(secFilter.text)}" />
    <div class="sec-findings">${list.map(f => {
      const h = byIp.get(f.ip);
      return `<details class="sec-finding ${f.severity}">
        <summary><span class="sev-badge ${f.severity}">${SEV_LABEL[f.severity]}${f.cvss != null && f.severity !== 'info' ? ` ${Number(f.cvss).toFixed(1)}` : ''}</span>
          <span class="sec-title">${escapeHtml(f.title)}</span>
          <span class="muted small">${h ? hostName(h) : escapeHtml(f.ip)}${f.port ? ` · port ${f.port}` : ''}${f.service ? ` · ${escapeHtml(f.service)}` : ''}</span></summary>
        <div class="sec-detail">
          ${f.detail ? `<p class="pre">${escapeHtml(f.detail)}</p>` : ''}
          ${f.solution ? `<p><strong>Fix:</strong> ${escapeHtml(f.solution)}</p>` : ''}
          ${f.refs && f.refs.length ? `<p class="small"><strong>References:</strong> ${f.refs.map(refLink).join(', ')}</p>` : ''}
          ${f.confidence !== 'high' ? '<p class="muted small">Version-based: matched by the version the service reports; the vendor may have fixed it without changing the version.</p>' : ''}
        </div></details>`;
    }).join('') || '<p class="muted">No findings match.</p>'}</div>`;

  const scansHtml = `<details class="no-print"><summary><h3 class="inline">Recent scans</h3></summary>
    <div class="details-table-wrap"><table class="switch-info-table"><thead><tr><th>Scope</th><th>Status</th><th>Devices</th><th>Started</th><th>Finished</th></tr></thead>
    <tbody>${d.scans.map(s => `<tr><td>${escapeHtml(scopeLabel(s.scope))}</td><td>${escapeHtml(s.status)}${s.error ? ` <span class="muted small">${escapeHtml(s.error)}</span>` : ''}</td>
      <td>${s.done}/${s.total}</td><td class="small">${s.startedAt ? escapeHtml(new Date(s.startedAt).toLocaleString()) : '—'}</td>
      <td class="small">${s.finishedAt ? escapeHtml(new Date(s.finishedAt).toLocaleString()) : '—'}</td></tr>`).join('')}</tbody></table></div></details>`;

  const openDetails = [...document.querySelectorAll('#sec-body details.sec-finding[open]')].map(x => x.querySelector('.sec-title')?.textContent);
  document.getElementById('sec-body').innerHTML = hostsHtml + findingsHtml + scansHtml;
  document.querySelectorAll('#sec-body details.sec-finding').forEach(x => { if (openDetails.includes(x.querySelector('.sec-title')?.textContent)) x.open = true; });
  document.querySelectorAll('[data-sec-ip]').forEach(tr => tr.addEventListener('click', () => {
    secFilter.ip = secFilter.ip === tr.dataset.secIp ? '' : tr.dataset.secIp;
    renderSecurity();
  }));
  document.getElementById('sec-clear')?.addEventListener('click', () => { secFilter.severity = ''; secFilter.ip = ''; renderSecurity(); });
  const input = document.getElementById('sec-text');
  input.addEventListener('input', () => {
    secFilter.text = input.value;
    const pos = input.selectionStart;
    renderSecurity();
    const again = document.getElementById('sec-text');
    again.focus();
    again.setSelectionRange(pos, pos);
  });
}

async function startSecurityScan() {
  const scope = document.getElementById('sec-scope').value || 'all';
  const btn = document.getElementById('sec-start');
  btn.disabled = true;
  try {
    const resp = await api('api/vulns.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ scope }) });
    const data = await resp.json();
    if (!resp.ok) throw new Error(data.error || 'Could not start the scan');
    secData = data;
    renderSecurity();
    loadSecurity();
  } catch (err) {
    alert(err.message);
  } finally {
    btn.disabled = false;
  }
}

async function cancelSecurityScan(id) {
  if (!confirm('Cancel this scan? Devices already scanned keep their results.')) return;
  const resp = await api(`api/vulns.php?cancel=${id}`, { method: 'POST' });
  if (resp.ok) { secData = await resp.json(); renderSecurity(); }
}

document.getElementById('sec-start').addEventListener('click', startSecurityScan);
document.getElementById('sec-print').addEventListener('click', () => {
  const closed = [...document.querySelectorAll('#sec-body details.sec-finding:not([open])')];
  closed.forEach(x => { x.open = true; });
  document.body.classList.add('print-security');
  window.print();
  document.body.classList.remove('print-security');
  closed.forEach(x => { x.open = false; });
});
if (location.hash === '#security') showView('security');
loadSecurity();
