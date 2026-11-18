// Traffic report (Reports -> Traffic): network usage from NetFlow, refreshed
// every 30 seconds while open. Data from api/traffic.php (includes/flows.php).
// Uses api(), escapeHtml(), openModal(), cssVar() from app.js, fmtValue() from
// history.js and the device categories' icons from the Devices report.

let trafficRange = '1h';
let trafficTimer = null;
let trafficChart = null;
const TRAFFIC_ICONS = { console: '🎮', tv: '📺', speaker: '🔊', camera: '📷', iot: '💡', appliance: '🧺', phone: '📱', computer: '💻',
  printer: '🖨️', voip: '☎️', network: '🛜', server: '🖥️', unknown: '❔' };

function fmtBits(v) { return fmtValue(v, 'bit/s'); }
function fmtTrafficBytes(b) {
  const u = ['B', 'KB', 'MB', 'GB', 'TB'];
  let i = 0;
  while (b >= 1000 && i < u.length - 1) { b /= 1000; i++; }
  return `${b.toFixed(b >= 100 || i === 0 ? 0 : 1)} ${u[i]}`;
}

function stopTrafficReport() {
  clearInterval(trafficTimer);
  trafficTimer = null;
}

async function loadTrafficReport() {
  stopTrafficReport();
  await refreshTraffic(true);
  trafficTimer = setInterval(() => {
    if (document.getElementById('reports-view').classList.contains('hidden') || currentReport !== 'traffic') stopTrafficReport();
    else refreshTraffic(false);
  }, 30000);
}

async function refreshTraffic(first) {
  const box = document.getElementById('traffic-body');
  if (first && !box.innerHTML) box.innerHTML = '<div class="switch-info-status">Loading traffic…</div>';
  let d;
  try {
    d = await (await api(`api/traffic.php?range=${trafficRange}`)).json();
  } catch (err) {
    box.innerHTML = `<div class="switch-info-status details-error">${escapeHtml(err.message)}</div>`;
    return;
  }
  renderTraffic(d);
}

function devCell(w) {
  const icon = w.category ? `<span title="${escapeHtml(w.label || '')}">${TRAFFIC_ICONS[w.category] || ''}</span> ` : '';
  const label = w.name || w.ip;
  const link = w.hostId ? `<button type="button" class="link-btn" data-open-host="${w.hostId}">${escapeHtml(label)}</button>` : `<strong>${escapeHtml(label)}</strong>`;
  return `${icon}${link}${w.name ? `<div class="muted small">${escapeHtml(w.ip)}</div>` : ''}`;
}

function shareBar(v, max) {
  return `<div class="tr-bar"><i style="width:${max ? Math.max(1, Math.round(v / max * 100)) : 0}%"></i></div>`;
}

function trafficSetup(d) {
  const server = d.serverIp && !/^(127\.|::1)/.test(d.serverIp) ? d.serverIp : location.hostname;
  const c = d.collector;
  const state = !c ? 'The collector hasn’t reported yet (service <code>webmonitor-flow</code>).'
    : !c.running ? '⚠ The collector service isn’t running.'
    : c.lastPacket ? `Last flow data ${Math.round(Date.now() / 1000 - c.lastPacket)} s ago from ${escapeHtml(c.exporters.join(', '))}.`
    : `The collector is listening on UDP ${c.port}; no flow data has arrived yet.`;
  return `<div class="tr-setup">
    <p><strong>${state}</strong>${c && c.noTemplate && !c.records ? ' Data is arriving but its template hasn’t yet — Cisco resends templates every few minutes.' : ''}</p>
    <p>On the Cisco 3850, add WebMonitor as a second destination for the NetFlow you already send to Akvorado, and monitor
      the link to pfSense too so downloads are seen (traffic is counted where it enters a VLAN interface):</p>
    <pre class="install-cmd">conf t
 flow exporter EXPORTER_WEBMONITOR
  destination ${escapeHtml(server)}
  source Vlan90
  transport udp 2055
  template data timeout 60
 flow monitor MONITOR_NETFLOW
  exporter EXPORTER_WEBMONITOR
 interface Vlan100
  ip flow monitor MONITOR_NETFLOW input
end
write memory</pre>
    <p class="hint">If the switch refuses to change MONITOR_NETFLOW while it's in use, remove it from the VLAN interfaces
      (<code>no ip flow monitor MONITOR_NETFLOW input</code>), add the exporter, then put it back.</p>
  </div>`;
}

function renderTraffic(d) {
  const box = document.getElementById('traffic-body');
  const ranges = ['15m', '1h', '6h', '24h', '7d'];
  const has = d.series.length > 0;
  const maxHost = Math.max(1, ...d.hosts.map(h => h.in + h.out));
  const maxSvc = Math.max(1, ...d.services.map(s => s.in + s.out));
  const noDownloads = has && d.totals.down === 0 && d.totals.up > 0;
  box.innerHTML = `
    <div class="report-head">
      <div>
        <h2 class="report-title">Traffic</h2>
        <div class="muted small">${has ? `${fmtTrafficBytes(d.totals.down)} down · ${fmtTrafficBytes(d.totals.up)} up · ${fmtTrafficBytes(d.totals.internal)} between your networks` : 'No flow data yet'}
          ${d.collector && d.collector.lastPacket ? ` · updated ${Math.max(0, Math.round(Date.now() / 1000 - d.collector.lastPacket))} s ago` : ''}</div>
      </div>
      <div class="section-actions no-print">
        <div class="range-picker" role="group" aria-label="Traffic range">${ranges.map(r =>
          `<button type="button" class="group-btn ${r === trafficRange ? 'active' : ''}" data-trange="${r}">${r}</button>`).join('')}</div>
      </div>
    </div>
    ${!has || !d.collector || !d.collector.lastPacket ? trafficSetup(d) : ''}
    ${noDownloads ? `<p class="pm-note">⚠ Only uploads are being seen: add <code>ip flow monitor MONITOR_NETFLOW input</code> to <code>interface Vlan100</code> (the link to pfSense).</p>` : ''}
    ${has ? `
    <div class="tr-tiles">
      <div class="tr-tile"><div class="muted small">Download now <span title="Averaged over the last 5 minutes">(5 min avg)</span></div><div class="tr-big">${d.now ? fmtBits(d.now.down) : '—'}</div></div>
      <div class="tr-tile"><div class="muted small">Upload now</div><div class="tr-big">${d.now ? fmtBits(d.now.up) : '—'}</div></div>
      <div class="tr-tile"><div class="muted small">Between networks now</div><div class="tr-big">${d.now ? fmtBits(d.now.internal) : '—'}</div></div>
      <div class="tr-tile"><div class="muted small">Busiest device</div><div class="tr-big tr-name">${d.hosts[0] ? escapeHtml(d.hosts[0].name || d.hosts[0].ip) : '—'}</div></div>
    </div>
    <div class="history-chart"><div class="history-canvas tr-canvas"><canvas id="traffic-canvas"></canvas></div></div>
    <div class="tr-grid">
      <div>
        <h3 class="topo-h">Top devices</h3>
        <div class="details-table-wrap"><table class="switch-info-table">
          <thead><tr><th>Device</th><th>Down</th><th>Up</th><th></th></tr></thead>
          <tbody>${d.hosts.map(h => `<tr><td>${devCell(h)}</td><td>${fmtTrafficBytes(h.in)}</td><td>${fmtTrafficBytes(h.out)}</td><td class="tr-share">${shareBar(h.in + h.out, maxHost)}</td></tr>`).join('')}</tbody>
        </table></div>
      </div>
      <div>
        <h3 class="topo-h">Top services</h3>
        <div class="details-table-wrap"><table class="switch-info-table">
          <thead><tr><th>Service</th><th>Down</th><th>Up</th><th></th></tr></thead>
          <tbody>${d.services.map(s => `<tr><td>${escapeHtml(s.name)}</td><td>${fmtTrafficBytes(s.in)}</td><td>${fmtTrafficBytes(s.out)}</td><td class="tr-share">${shareBar(s.in + s.out, maxSvc)}</td></tr>`).join('')}</tbody>
        </table></div>
      </div>
    </div>
    <h3 class="topo-h">Top conversations</h3>
    <div class="details-table-wrap"><table class="switch-info-table">
      <thead><tr><th>Device</th><th>Talking to</th><th>Service</th><th>Down</th><th>Up</th></tr></thead>
      <tbody>${d.conversations.map(c => `<tr><td>${devCell(c.local)}</td>
        <td>${escapeHtml(c.remoteName || c.remote)}${c.remoteLocal ? ' <span class="muted small">(local)</span>' : ''}</td>
        <td>${escapeHtml(c.service)}</td><td>${fmtTrafficBytes(c.in)}</td><td>${fmtTrafficBytes(c.out)}</td></tr>`).join('')}</tbody>
    </table></div>` : ''}`;

  box.querySelectorAll('[data-trange]').forEach(b => b.addEventListener('click', () => { trafficRange = b.dataset.trange; loadTrafficReport(); }));
  box.querySelectorAll('[data-open-host]').forEach(b => b.addEventListener('click', () => openModal(Number(b.dataset.openHost))));
  if (has) drawTrafficChart(d);
}

function drawTrafficChart(d) {
  if (trafficChart) { trafficChart.destroy(); trafficChart = null; }
  const canvas = document.getElementById('traffic-canvas');
  if (!canvas) return;
  const grid = cssVar('--panel-border');
  const text = cssVar('--muted');
  const ds = (label, idx, color) => ({
    label, data: d.series.map(p => ({ x: p[0], y: p[idx] })), borderColor: color, backgroundColor: color + '33',
    fill: true, borderWidth: 1.6, pointRadius: 0, tension: 0.25,
  });
  const now = Math.floor(Date.now() / 1000);
  trafficChart = new Chart(canvas, {
    type: 'line',
    data: { datasets: [ds('Download', 1, '#3b82f6'), ds('Upload', 2, '#22c55e'), ds('Between networks', 3, '#a855f7')] },
    options: {
      responsive: true, maintainAspectRatio: false, animation: false, parsing: false,
      interaction: { mode: 'index', intersect: false },
      scales: {
        x: { type: 'linear', min: d.from, max: now, grid: { color: grid },
             ticks: { color: text, maxTicksLimit: 8, callback: v => {
               const dt = new Date(v * 1000);
               return d.range === '7d' ? dt.toLocaleDateString([], { weekday: 'short', day: 'numeric' }) : dt.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
             } } },
        y: { beginAtZero: true, grid: { color: grid }, ticks: { color: text, maxTicksLimit: 5, callback: v => fmtBits(v) } },
      },
      plugins: {
        legend: { labels: { color: cssVar('--text'), boxWidth: 10, boxHeight: 10 } },
        tooltip: { callbacks: { title: items => items.length ? new Date(items[0].raw.x * 1000).toLocaleString() : '',
                                label: c => `${c.dataset.label}: ${fmtBits(c.raw.y)}` } },
      },
    },
  });
}
