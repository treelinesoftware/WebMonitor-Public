// Scan status and queue. Uses api(), escapeHtml(), devQuery() and showView().
const scanPill = document.getElementById('scan-pill');
const scanQueueBody = document.getElementById('scan-queue-body');
let scanPollTimer = null;
let scanPollInFlight = false;
let scanPollNeedsRefresh = false;

async function loadScanQueue() {
  if (scanPollInFlight) { scanPollNeedsRefresh = true; return; }
  scanPollInFlight = true;
  clearTimeout(scanPollTimer);
  const details = !document.getElementById('scans-view').classList.contains('hidden');
  try {
    const resp = await api(`api/scan-queue.php${details ? '?details=1' : ''}`);
    const data = await resp.json();
    if (!resp.ok) throw new Error(data.error || 'Could not load scan status');
    scanPill.textContent = `Scans · ${data.active.length ? `${data.active.length} running` : 'idle'}${data.requested ? ` · ${data.requested} requested` : ''}`;
    scanPill.classList.toggle('busy', data.active.length > 0);
    if (details && !document.getElementById('scans-view').classList.contains('hidden')) renderScanQueue(data);
  } catch (err) {
    scanPill.textContent = 'Scans · unavailable';
    scanPill.classList.remove('busy');
    if (details) scanQueueBody.innerHTML = `<p class="details-error">${escapeHtml(err.message)}</p>`;
    console.error('Failed to load scan status', err);
  } finally {
    scanPollInFlight = false;
    scanPollTimer = setTimeout(loadScanQueue, scanPollNeedsRefresh ? 0 : 10000);
    scanPollNeedsRefresh = false;
  }
}

function renderScanQueue(data) {
  const active = (kind, target) => data.active.some(a => a.kind === kind && a.target === target);
  const requests = data.devices.filter(d => !active('device', d.ip));
  const due = data.dueDevices.filter(d => !active('device', d.ip) && !data.devices.some(r => r.ip === d.ip));
  const subnets = data.subnets.filter(s => !active('subnet', s.subnet));
  document.getElementById('scan-queue-summary').textContent =
    `${data.active.length} running · ${requests.length} requested assets · ${due.length} due assets`;
  scanQueueBody.innerHTML = `
    <h3>Running</h3>
    ${data.active.length ? `<ul class="scan-queue-list">${data.active.map(a =>
      `<li>${a.kind === 'device' ? 'Asset' : 'Subnet'} · ${escapeHtml(a.target)} <span class="muted small">started ${escapeHtml(new Date(a.startedAt).toLocaleString())}</span></li>`).join('')}</ul>`
      : '<p class="muted">No scans are running.</p>'}
    <h3>Asset scans</h3>
    ${requests.length || due.length ? `<div class="details-table-wrap"><table class="switch-info-table scan-queue-table">
      <thead><tr><th>Asset</th><th>Status</th><th></th></tr></thead><tbody>
      ${requests.map((d, i) => `<tr><td>${escapeHtml(d.ip)}${d.mac ? ` <span class="muted small">${escapeHtml(d.mac)}</span>` : ''}</td>
        <td>${d.priority ? 'Prioritized' : 'Requested'} · ${escapeHtml(new Date(d.requestedAt).toLocaleString())}</td>
        <td>${IS_ADMIN ? `<button type="button" class="section-btn" data-requested="${i}">Scan now</button>` : ''}</td></tr>`).join('')}
      ${due.map((d, i) => `<tr><td>${escapeHtml(d.name || d.ip)}${d.name ? ` <span class="muted small">${escapeHtml(d.ip)}</span>` : ''}</td>
        <td>Due for daily scan${d.scannedAt ? ` · last scanned ${escapeHtml(new Date(d.scannedAt).toLocaleString())}` : ' · never scanned'}</td>
        <td>${IS_ADMIN ? `<button type="button" class="section-btn" data-due="${i}">Scan now</button>` : ''}</td></tr>`).join('')}
      </tbody></table></div>` : '<p class="muted">No asset scans are waiting.</p>'}
    <h3>Subnet discovery</h3>
    ${subnets.length ? `<div class="details-table-wrap"><table class="switch-info-table scan-queue-table">
      <thead><tr><th>Subnet</th><th>Status</th><th></th></tr></thead><tbody>
      ${subnets.map((s, i) => `<tr><td>${escapeHtml(s.name ? `${s.name} · ${s.subnet}` : s.subnet)}</td>
        <td>${s.requestedAt ? 'Prioritized' : s.dueAt <= Date.now() ? 'Due' : `Scheduled ${escapeHtml(new Date(s.dueAt).toLocaleString())}`}</td>
        <td>${IS_ADMIN ? `<button type="button" class="section-btn" data-subnet-scan="${i}">Scan now</button>` : ''}</td></tr>`).join('')}
      </tbody></table></div>` : '<p class="muted">No subnet scans are scheduled or requested.</p>'}`;
  scanQueueBody.querySelectorAll('[data-requested]').forEach(b =>
    b.addEventListener('click', () => requestAssetScan(requests[Number(b.dataset.requested)], b)));
  scanQueueBody.querySelectorAll('[data-due]').forEach(b =>
    b.addEventListener('click', () => requestAssetScan(due[Number(b.dataset.due)], b)));
  scanQueueBody.querySelectorAll('[data-subnet-scan]').forEach(b =>
    b.addEventListener('click', () => requestSubnetScan(subnets[Number(b.dataset.subnetScan)].subnet, b)));
}

async function requestAssetScan(device, button) {
  button.disabled = true;
  try {
    const resp = await api(`api/device-info.php?${devQuery(device)}&scan=1`, { method: 'POST' });
    const data = await resp.json();
    if (!resp.ok) throw new Error(data.error || 'Could not request the scan');
    button.textContent = 'Requested';
    await loadScanQueue();
  } catch (err) {
    alert(err.message);
    button.disabled = false;
  }
}

async function requestSubnetScan(subnet, button) {
  button.disabled = true;
  try {
    const resp = await api('api/scan-queue.php', {
      method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ subnet }),
    });
    const data = await resp.json();
    if (!resp.ok) throw new Error(data.error || 'Could not request the subnet scan');
    button.textContent = 'Requested';
    await loadScanQueue();
  } catch (err) {
    alert(err.message);
    button.disabled = false;
  }
}

document.getElementById('scan-queue-refresh').addEventListener('click', loadScanQueue);
scanPill.addEventListener('click', () => showView('scans'));
if (location.hash === '#scans') showView('scans');
loadScanQueue();
