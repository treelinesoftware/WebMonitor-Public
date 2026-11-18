// History graphs in host Details: range picker + one chart per group.
// Uses api(), escapeHtml(), cssVar(), openHostId and Chart (chart.js) from app.js.

const HISTORY_RANGES_UI = ['1h', '6h', '24h', '7d', '30d', '1y'];
const HISTORY_GROUPS = [
  { title: 'Availability', metrics: ['up'], unit: '%', max: 100 },
  { title: 'Response time', metrics: ['ping_ms'], unit: 'ms' },
  { title: 'CPU & memory', metrics: ['cpu', 'mem', 'swap'], unit: '%', max: 100 },
  { title: 'Load average', metrics: ['load1'], unit: '' },
  { title: 'Disk & volume usage', metrics: ['disk'], unit: '%', max: 100 },
  { title: 'Network', metrics: ['net_rx', 'net_tx'], unit: 'bit/s' },
  { title: 'Traffic (NetFlow)', metrics: ['flow_in', 'flow_out'], unit: 'bit/s' },
  { title: 'Temperatures', metrics: ['temp_system', 'temp_disk'], unit: '°C' },
  { title: 'Fans', metrics: ['fan'], unit: 'RPM' },
];
const METRIC_LABELS = {
  up: 'Availability', ping_ms: 'Response time', cpu: 'CPU', mem: 'Memory', swap: 'Swap',
  load1: 'Load (1 min)', temp_system: 'System', flow_in: 'Download', flow_out: 'Upload',
};
const RANGE_SECONDS = { '1h': 3600, '6h': 21600, '24h': 86400, '7d': 604800, '30d': 2592000, '1y': 31536000 };
const BUCKET_SECONDS = { '1h': 60, '6h': 60, '24h': 300, '7d': 1800, '30d': 10800, '1y': 86400 };

let historyRange = '24h';
let historyCharts = [];
let historyHostId = null;
let historyTimer = null;

function seriesLabel(s) {
  if (s.metric === 'net_rx') return `${s.subject} in`;
  if (s.metric === 'net_tx') return `${s.subject} out`;
  return METRIC_LABELS[s.metric] || s.subject || s.metric;
}

function seriesPalette() {
  return [cssVar('--accent'), cssVar('--up'), cssVar('--pending'), cssVar('--down'), '#8b5cf6', '#06b6d4', '#ec4899', '#64748b'];
}

function fmtValue(v, unit) {
  if (v == null) return '—';
  if (unit === 'bit/s') {
    const units = ['bit/s', 'kbit/s', 'Mbit/s', 'Gbit/s'];
    let i = 0;
    while (Math.abs(v) >= 1000 && i < units.length - 1) { v /= 1000; i++; }
    return `${v.toFixed(v >= 100 || i === 0 ? 0 : 1)} ${units[i]}`;
  }
  const n = Math.abs(v) >= 100 ? Math.round(v) : Math.round(v * 10) / 10;
  return unit ? `${n} ${unit}` : String(n);
}

function fmtTick(ts) {
  const d = new Date(ts * 1000);
  const span = RANGE_SECONDS[historyRange];
  if (span <= 86400) return d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
  if (span <= 604800) return d.toLocaleString([], { weekday: 'short', hour: '2-digit' });
  return d.toLocaleDateString([], { month: 'short', day: 'numeric' });
}

function destroyHistoryCharts() {
  historyCharts.forEach(c => c.destroy());
  historyCharts = [];
}

function stopHistory() {
  clearInterval(historyTimer);
  historyTimer = null;
  historyHostId = null;
  destroyHistoryCharts();
}

/** Called when a host's Details open. */
function startHistory(hostId) {
  stopHistory();
  historyHostId = hostId;
  const section = document.getElementById('history-section');
  section.innerHTML = `
    <div class="switch-info-header">
      <h3>History</h3>
      <div class="range-picker" role="group" aria-label="History range">${HISTORY_RANGES_UI.map(r =>
        `<button type="button" class="group-btn ${r === historyRange ? 'active' : ''}" data-range="${r}">${r}</button>`).join('')}</div>
    </div>
    <div id="history-charts"><div class="switch-info-status">Loading…</div></div>`;
  section.querySelectorAll('[data-range]').forEach(b => b.addEventListener('click', () => {
    historyRange = b.dataset.range;
    section.querySelectorAll('[data-range]').forEach(x => x.classList.toggle('active', x === b));
    loadHistory(hostId);
  }));
  loadHistory(hostId);
  historyTimer = setInterval(() => loadHistory(hostId), 60000);
}

async function loadHistory(hostId) {
  let data;
  try {
    data = await (await api(`api/history.php?host=${hostId}&range=${historyRange}`)).json();
  } catch (err) {
    return;
  }
  if (historyHostId !== hostId) return;
  renderHistory(data.series || []);
}

/** Inserts nulls where points are missing, so gaps show as gaps, not lines. */
function withGaps(points) {
  const step = BUCKET_SECONDS[historyRange];
  const out = [];
  for (let i = 0; i < points.length; i++) {
    if (i && points[i][0] - points[i - 1][0] > step * 2.5) out.push({ x: points[i - 1][0] + step, y: null });
    out.push({ x: points[i][0], y: points[i][1], min: points[i][2], max: points[i][3] });
  }
  return out;
}

function renderHistory(series) {
  destroyHistoryCharts();
  const box = document.getElementById('history-charts');
  const groups = HISTORY_GROUPS.map(g => ({ ...g, series: series.filter(s => g.metrics.includes(s.metric)) })).filter(g => g.series.length);
  if (!groups.length) {
    box.innerHTML = `<div class="switch-info-status">No history for this range yet. WebMonitor records history from now on
      (every minute for agents and reachability, every 5 minutes for NAS devices).</div>`;
    return;
  }
  box.innerHTML = groups.map((g, i) => `
    <div class="history-chart">
      <div class="history-title">${escapeHtml(g.title)}</div>
      <div class="history-canvas"><canvas id="hist-${i}"></canvas></div>
    </div>`).join('');

  const palette = seriesPalette();
  const now = Math.floor(Date.now() / 1000);
  const from = now - RANGE_SECONDS[historyRange];
  const grid = cssVar('--panel-border');
  const text = cssVar('--muted');
  const long = RANGE_SECONDS[historyRange] > 86400;
  groups.forEach((g, i) => {
    const scale = g.unit === 'bit/s' ? 8 : 1;   // network is stored in bytes/s
    const datasets = g.series.map((s, j) => ({
      label: seriesLabel(s),
      data: withGaps(s.points.map(p => [p[0], p[1] * scale, p[2] * scale, p[3] * scale])),
      borderColor: palette[j % palette.length],
      backgroundColor: palette[j % palette.length],
      borderWidth: 1.6,
      pointRadius: 0,
      pointHitRadius: 6,
      tension: 0.2,
      spanGaps: false,
      stepped: g.metrics[0] === 'up' ? 'before' : false,
    }));
    historyCharts.push(new Chart(document.getElementById(`hist-${i}`), {
      type: 'line',
      data: { datasets },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        animation: false,
        parsing: false,
        interaction: { mode: 'index', intersect: false },
        scales: {
          x: { type: 'linear', min: from, max: now, ticks: { color: text, maxTicksLimit: 7, callback: v => fmtTick(v) }, grid: { color: grid } },
          y: { beginAtZero: true, max: g.max, ticks: { color: text, maxTicksLimit: 5, callback: v => fmtValue(v, g.unit) }, grid: { color: grid } },
        },
        plugins: {
          legend: { display: datasets.length > 1, labels: { color: cssVar('--text'), boxWidth: 10, boxHeight: 10 } },
          tooltip: {
            callbacks: {
              title: items => items.length ? new Date(items[0].raw.x * 1000).toLocaleString() : '',
              label: c => {
                const r = c.raw;
                const range = long && r.min != null && r.max !== r.min ? ` (${fmtValue(r.min, g.unit)} – ${fmtValue(r.max, g.unit)})` : '';
                return `${c.dataset.label}: ${fmtValue(r.y, g.unit)}${range}`;
              },
            },
          },
        },
      },
    }));
  });
}
