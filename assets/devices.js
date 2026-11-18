// Devices report: everything seen on the network, identified by kind (game
// consoles, TVs, smart-home gadgets, phones, ...). Data from api/devices.php.
// Uses api(), escapeHtml(), openModal(), IS_ADMIN, hostsById, loadHosts() from app.js.

let devData = null;
let devFilter = '';
let devCategory = '';
let devConn = '';   // '', 'wifi', 'wired', 'wrongnet'

async function loadDevicesReport() {
  const box = document.getElementById('dev-body');
  if (!devData) box.innerHTML = '<div class="switch-info-status">Identifying devices…</div>';
  try {
    const resp = await api('api/devices.php');
    const data = await resp.json();
    if (!resp.ok) throw new Error(data.error || 'Could not build the device list');
    devData = data;
    renderDevicesReport();
  } catch (err) {
    box.innerHTML = `<div class="switch-info-status details-error">${escapeHtml(err.message)}</div>`;
  }
}

document.getElementById('dev-refresh').addEventListener('click', loadDevicesReport);
document.getElementById('dev-print').addEventListener('click', () => window.print());
document.getElementById('dev-csv').addEventListener('click', () => {
  if (!devData) return;
  const rows = [['Kind', 'Identified as', 'Confidence', 'Name', 'IP', 'MAC', 'Maker', 'Connected via', 'Network']];
  for (const d of visibleDevices()) {
    rows.push([devData.categories[d.category][1], d.label, d.confidence, d.name || '', d.ip || '', d.mac || '', d.vendor || '', whereText(d), d.subnetName || d.subnet || '']);
  }
  const csv = rows.map(r => r.map(v => `"${String(v ?? '').replace(/"/g, '""')}"`).join(',')).join('\r\n');
  const a = document.createElement('a');
  a.href = URL.createObjectURL(new Blob([csv], { type: 'text/csv' }));
  a.download = `network-devices-${new Date().toISOString().slice(0, 10)}.csv`;
  a.click();
  setTimeout(() => URL.revokeObjectURL(a.href), 1000);
});

function whereText(d) {
  const w = d.where || {};
  if (w.kind === 'wifi') return `Wi-Fi ${w.ssid || ''} via ${w.ap || 'AP'}${w.band ? ' · ' + w.band : ''}`;
  if (w.kind === 'wired') return `${w.switch} ${w.port}`;
  if (w.kind === 'vm') return `${w.type === 'lxc' ? 'CT' : 'VM'} ${w.vmid} on ${w.hypervisor}`;
  if (w.kind === 'network') return 'network device';
  return 'not seen on a switch port';
}

function visibleDevices() {
  const q = devFilter.toLowerCase();
  return devData.devices.filter(d => (!devCategory || d.category === devCategory)
    && (!devConn || (devConn === 'wrongnet' ? !!d.netWarning : devConn === 'wifi' ? d.where.kind === 'wifi' : d.where.kind === 'wired'))
    && (!q || [d.name, d.ip, d.mac, d.vendor, d.label, whereText(d), d.subnetName].join(' ').toLowerCase().includes(q)));
}

function renderDevicesReport() {
  const box = document.getElementById('dev-body');
  const cats = devData.categories;
  const counts = {};
  devData.devices.forEach(d => { counts[d.category] = (counts[d.category] || 0) + 1; });
  const unmonitored = devData.devices.filter(d => !d.hostId && d.ip).length;
  document.getElementById('dev-meta').textContent =
    `${devData.devices.length} devices · ${unmonitored} not monitored · ${new Date(devData.generatedAt).toLocaleString()}`;
  box.innerHTML = `
    <div class="dev-cats no-print">
      <button type="button" class="dev-cat ${devCategory === '' ? 'active' : ''}" data-cat="">All <span class="n">${devData.devices.length}</span></button>
      ${Object.entries(cats).filter(([k]) => counts[k]).map(([k, [icon, label]]) =>
        `<button type="button" class="dev-cat ${devCategory === k ? 'active' : ''}" data-cat="${k}">${icon} ${escapeHtml(label)} <span class="n">${counts[k]}</span></button>`).join('')}
    </div>
    <div class="dev-cats no-print">
      ${[['', 'Any connection'], ['wifi', '📶 Wi-Fi'], ['wired', '🔌 Wired'], ['wrongnet', '⚠ On the wrong network']].map(([k, l]) => {
        const n = k === 'wrongnet' ? devData.devices.filter(d => d.netWarning).length : k ? devData.devices.filter(d => d.where.kind === k).length : devData.devices.length;
        if (k === 'wrongnet' && !n) return '';
        return `<button type="button" class="dev-cat ${devConn === k ? 'active' : ''}" data-conn="${k}">${l} <span class="n">${n}</span></button>`;
      }).join('')}
    </div>
    <input type="search" id="dev-filter" class="topo-filter no-print" placeholder="Filter by name, IP, MAC, maker…" value="${escapeHtml(devFilter)}" />
    <div id="dev-table"></div>
    ${haOnlyHtml(devData.haOnly || [])}`;
  box.querySelectorAll('[data-cat]').forEach(b => b.addEventListener('click', () => {
    devCategory = b.dataset.cat;
    renderDevicesReport();
  }));
  box.querySelectorAll('[data-conn]').forEach(b => b.addEventListener('click', () => {
    devConn = b.dataset.conn;
    renderDevicesReport();
  }));
  const input = document.getElementById('dev-filter');
  input.addEventListener('input', () => { devFilter = input.value; renderDeviceRows(); });
  renderDeviceRows();
}

// Devices Home Assistant knows through a cloud link, not seen on the network (or not matchable).
function haOnlyHtml(list) {
  if (!list.length) return '';
  const kind = k => ({ camera: 'Camera', light: 'Light', switch: 'Plug / switch', climate: 'Thermostat', vacuum: 'Vacuum', lock: 'Lock',
    alarm_control_panel: 'Alarm', media_player: 'Media player', cover: 'Cover', fan: 'Fan' })[k];
  const rows = [...list].sort((a, b) => (a.area || '~').localeCompare(b.area || '~') || a.name.localeCompare(b.name));
  return `<details class="ha-only"><summary><h3 class="inline">In Home Assistant only <span class="muted small">${rows.length} · linked through the maker's cloud, so their network address isn't known</span></h3></summary>
    <div class="details-table-wrap"><table class="switch-info-table">
      <thead><tr><th>Name</th><th>Room</th><th>Kind</th><th>Maker / model</th></tr></thead>
      <tbody>${rows.map(d => `<tr><td>${escapeHtml(d.name)}</td><td class="small">${escapeHtml(d.area || '')}</td>
        <td class="small">${escapeHtml((d.kinds || []).map(kind).filter(Boolean)[0] || '')}</td>
        <td class="small">${escapeHtml([d.maker, d.model].filter(Boolean).join(' · '))}</td></tr>`).join('')}</tbody></table></div></details>`;
}

function renderDeviceRows() {
  const cats = devData.categories;
  const list = visibleDevices();
  document.getElementById('dev-table').innerHTML = list.length ? `<div class="details-table-wrap"><table class="switch-info-table dev-table">
    <thead><tr><th></th><th>Device</th><th>Identified as</th><th>IP / MAC</th><th>Maker</th><th>Connected via</th><th>Network</th><th></th></tr></thead>
    <tbody>${list.map((d, i) => {
      const host = d.hostId ? hostsById.get(d.hostId) : null;
      const conn = d.where.kind === 'wifi' ? '📶 ' : d.where.kind === 'wired' ? '🔌 ' : '';
      return `<tr class="dev-row" data-dev="${i}" title="Click for device details">
        <td class="dev-icon" title="${escapeHtml(cats[d.category][1])}">${cats[d.category][0]}</td>
        <td>${d.hostId ? `<button type="button" class="link-btn" data-open-host="${d.hostId}">${escapeHtml(d.name || d.ip)}</button>`
          : `<strong>${escapeHtml(d.name || '—')}</strong>`}${host ? ` <span class="status-dot ${statusClass(host)}" style="display:inline-block;width:8px;height:8px"></span>` : ''}
          ${d.note ? `<div class="pm-note">⚠ ${escapeHtml(d.note)}</div>` : ''}
          ${d.netWarning ? `<div class="pm-note">⚠ ${escapeHtml(d.netWarning)}</div>` : ''}
          ${host && host.locationId && locationNames[host.locationId] ? `<div class="muted small">📍 ${escapeHtml(locationNames[host.locationId])}</div>` : ''}</td>
        <td>${escapeHtml(d.label)} <span class="dev-conf ${d.confidence}" title="How sure the identification is">${d.confidence}</span></td>
        <td>${escapeHtml(d.ip || '—')}${d.mac ? `<div class="muted small mono">${escapeHtml(d.mac)}${d.randomMac ? ' · private' : ''}</div>` : ''}</td>
        <td class="small">${escapeHtml(d.vendor || '')}</td>
        <td class="small">${conn}${escapeHtml(whereText(d))}</td>
        <td class="small">${escapeHtml(d.subnetName || d.subnet || '')}</td>
        <td>${d.ip && IS_ADMIN ? `<button type="button" class="section-btn" data-device-scan="${i}">Scan now</button>` : ''}
          ${!d.hostId && d.ip && IS_ADMIN ? `<button type="button" class="section-btn" data-monitor="${i}" title="Add a card for this device">Monitor</button>` : ''}</td>
      </tr>`;
    }).join('')}</tbody></table></div>` : '<p class="muted">No devices match.</p>';
  document.querySelectorAll('#dev-table [data-open-host]').forEach(b => b.addEventListener('click', () => openModal(Number(b.dataset.openHost))));
  document.querySelectorAll('#dev-table [data-monitor]').forEach(b => b.addEventListener('click', e => { e.stopPropagation(); monitorDevice(list[Number(b.dataset.monitor)], b); }));
  document.querySelectorAll('#dev-table [data-device-scan]').forEach(b => b.addEventListener('click', e => { e.stopPropagation(); requestAssetScan(list[Number(b.dataset.deviceScan)], b); }));
  document.querySelectorAll('#dev-table [data-open-host]').forEach(b => b.addEventListener('click', e => e.stopPropagation()));
  document.querySelectorAll('#dev-table .dev-row').forEach(tr => tr.addEventListener('click', () => openDevicePanel(list[Number(tr.dataset.dev)])));
}

const DEV_TO_CARD = { console: 'console', tv: 'tv', speaker: 'iot', camera: 'camera', iot: 'iot', appliance: 'iot',
  phone: 'mobile', computer: 'desktop', printer: 'printer', voip: 'mobile', network: 'switch', server: 'server', unknown: 'server' };

async function monitorDevice(d, btn) {
  const name = (d.name || `${d.label} ${d.ip}`).slice(0, 60);
  if (!confirm(`Add a card for ${name} (${d.ip})?`)) return;
  btn.disabled = true;
  const resp = await api('api/hosts.php', {
    method: 'POST', headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ name, ip: d.ip, os: 'other', device: DEV_TO_CARD[d.category] || 'server' }),
  });
  if (!resp.ok) {
    alert((await resp.json().catch(() => ({}))).error || 'Could not add it');
    btn.disabled = false;
    return;
  }
  await loadHosts();
  loadDevicesReport();
}

// ---- Device details panel (Fing-style) ----

const deviceBackdrop = document.getElementById('device-backdrop');
let devPanel = null;      // {mac, ip}
let devPanelTimer = null;

document.getElementById('device-close').addEventListener('click', closeDevicePanel);
deviceBackdrop.addEventListener('click', e => { if (e.target === deviceBackdrop) closeDevicePanel(); });
document.addEventListener('keydown', e => { if (e.key === 'Escape' && !deviceBackdrop.classList.contains('hidden')) closeDevicePanel(); });

function closeDevicePanel() {
  deviceBackdrop.classList.add('hidden');
  clearTimeout(devPanelTimer);
  devPanel = null;
}

function devQuery(p) {
  return new URLSearchParams(Object.fromEntries(Object.entries({ mac: p.mac || '', ip: p.ip || '' }).filter(([, v]) => v))).toString();
}

function openDevicePanel(d) {
  devPanel = { mac: d.mac, ip: d.ip };
  document.getElementById('device-body').innerHTML = '<div class="switch-info-status">Loading…</div>';
  deviceBackdrop.classList.remove('hidden');
  loadDevicePanel();
}

async function loadDevicePanel() {
  const p = devPanel;
  if (!p) return;
  let data;
  try {
    const resp = await api(`api/device-info.php?${devQuery(p)}`);
    data = await resp.json();
    if (!resp.ok) throw new Error(data.error || 'Could not load the device');
  } catch (err) {
    document.getElementById('device-body').innerHTML = `<div class="switch-info-status details-error">${escapeHtml(err.message)}</div>`;
    return;
  }
  if (devPanel !== p) return;
  renderDevicePanel(data);
  clearTimeout(devPanelTimer);
  if (data.scan && data.scan.requestedAt) devPanelTimer = setTimeout(loadDevicePanel, 4000);   // scan pending
}

function fact(label, value) {
  return value == null || value === '' ? '' : `<div class="dp-fact"><span class="muted">${escapeHtml(label)}</span><span>${value}</span></div>`;
}

function renderDevicePanel(data, target = null) {
  const d = data.device;
  const cats = data.categories;
  const scan = data.scan && data.scan.data;
  const sum = (scan && scan.summary) || {};
  const host = d.hostId ? hostsById.get(d.hostId) : null;
  const w = d.where || {};
  const wifi = data.wifi;
  const port = data.port;
  const snr = s => s == null ? null : `<span class="cw-snr ${s >= 35 ? 'good' : s >= 20 ? 'ok' : 'poor'}">${s} dB ${s >= 35 ? '(excellent)' : s >= 25 ? '(good)' : s >= 15 ? '(fair)' : '(weak)'}</span>`;
  const speed = m => m ? (m >= 1000 ? `${m / 1000} Gbit/s` : `${m} Mbit/s`) : null;
  const link = (id, name) => `<button type="button" class="link-btn" data-open-host="${id}">${escapeHtml(name)}</button>`;

  let connection = '';
  if (w.kind === 'wifi') {
    connection = `<h4>📶 Wi-Fi</h4>
      ${fact('Network (SSID)', escapeHtml(w.ssid || wifi?.ssid || '—'))}
      ${fact('Access point', w.apId ? link(w.apId, w.ap) : escapeHtml(w.ap || ''))}
      ${fact('Band', escapeHtml(w.band || wifi?.band || ''))}
      ${fact('Signal', wifi ? snr(wifi.snr) : null)}
      ${fact('Speed', wifi && wifi.rxRate != null ? `${wifi.rxRate} Mbit/s` : null)}
      ${fact('Connected for', wifi ? fmtUptimeShort(wifi.uptimeSec) : null)}`;
  } else if (w.kind === 'wired') {
    connection = `<h4>🔌 Wired</h4>
      ${fact('Switch', w.switchId ? link(w.switchId, w.switch) : escapeHtml(w.switch || ''))}
      ${fact('Port', escapeHtml(w.port) + (port && port.description && port.description !== port.name ? ` <span class="muted">“${escapeHtml(port.description)}”</span>` : ''))}
      ${fact('Link speed', port ? speed(port.speedMbps) : null)}
      ${fact('VLAN', port ? (port.trunk ? 'trunk' : port.vlan) : null)}
      ${fact('Port-channel', port && port.lag ? escapeHtml(port.lag) : null)}
      ${fact('Other devices on the port', port && port.devicesOnPort > 1 ? port.devicesOnPort - 1 : null)}`;
  } else if (w.kind === 'vm') {
    connection = `<h4>🖥️ Virtual machine</h4>${fact('Runs on', w.hypervisorId ? link(w.hypervisorId, w.hypervisor) : escapeHtml(w.hypervisor || ''))}
      ${fact('ID', `${w.type === 'lxc' ? 'CT' : 'VM'} ${w.vmid}`)}`;
  } else {
    connection = `<h4>Connection</h4><p class="muted small">Not seen on any switch port or Wi-Fi access point WebMonitor reads.</p>`;
  }

  const services = scan && scan.ports && scan.ports.length ? `<div class="details-table-wrap"><table class="switch-info-table">
      <thead><tr><th>Port</th><th>Service</th><th>Details</th></tr></thead>
      <tbody>${scan.ports.map(pt => {
        const web = scan.web && scan.web[pt.port];
        const detail = web ? [web.title, web.server].filter(Boolean).join(' · ') : pt.port === 22 ? scan.ssh : '';
        return `<tr><td>${pt.port}</td><td>${escapeHtml(pt.service || '')}</td><td class="small">${escapeHtml(detail || '')}</td></tr>`;
      }).join('')}</tbody></table></div>`
    : scan ? '<p class="muted small">No open ports — the device doesn’t offer any services on the network (normal for phones, consoles and many smart-home gadgets).</p>' : '';

  const discovery = scan ? [
    scan.upnp && `<div class="dp-disc"><strong>UPnP</strong> ${escapeHtml([scan.upnp.friendlyName, scan.upnp.manufacturer, scan.upnp.modelName, scan.upnp.modelDescription].filter(Boolean).join(' · '))}
      ${scan.upnp.server ? `<div class="muted small">${escapeHtml(scan.upnp.server)}</div>` : ''}</div>`,
    scan.brand && Object.entries(scan.brand).map(([k, b]) => `<div class="dp-disc"><strong>${escapeHtml({ roku: 'Roku', cast: 'Google Cast', shelly: 'Shelly', tasmota: 'Tasmota', hue: 'Philips Hue' }[k] || k)}</strong>
      ${escapeHtml(Object.values(b).filter(Boolean).join(' · '))}</div>`).join(''),
    scan.netbios && `<div class="dp-disc"><strong>NetBIOS</strong> ${escapeHtml([scan.netbios.name, scan.netbios.workgroup && 'workgroup ' + scan.netbios.workgroup].filter(Boolean).join(' · '))}</div>`,
    scan.mdns && `<div class="dp-disc"><strong>mDNS</strong> ${escapeHtml([scan.mdns.hostname && scan.mdns.hostname + '.local', scan.mdns.model].filter(Boolean).join(' · '))}
      ${scan.mdns.services ? `<div class="muted small">${escapeHtml(scan.mdns.services.join(', '))}</div>` : ''}</div>`,
    scan.snmp && `<div class="dp-disc"><strong>SNMP</strong> ${escapeHtml([scan.snmp.name, scan.snmp.descr].filter(Boolean).join(' · '))}</div>`,
  ].filter(Boolean).join('') : '';

  const t = data.traffic;
  const traffic = t ? `
      ${fact('Internet', `${fmtTrafficBytes(t.internetIn)} down · ${fmtTrafficBytes(t.internetOut)} up`)}
      ${fact('Total (incl. local)', `${fmtTrafficBytes(t.in)} in · ${fmtTrafficBytes(t.out)} out`)}
      ${t.top.length ? `<div class="details-table-wrap"><table class="switch-info-table"><thead><tr><th>Talking to</th><th>Service</th><th>Down</th><th>Up</th></tr></thead>
        <tbody>${t.top.map(c => `<tr><td>${escapeHtml(c.remote)}</td><td>${escapeHtml(c.service)}</td><td>${fmtTrafficBytes(c.in)}</td><td>${fmtTrafficBytes(c.out)}</td></tr>`).join('')}</tbody></table></div>` : ''}`
    : '<p class="muted small">No traffic recorded in the last 24 hours (only traffic between networks and to the internet is seen).</p>';

  const scanned = data.scan && data.scan.scannedAt;
  const pending = data.scan && data.scan.requestedAt;
  const html = `
    ${target ? '' : `<div class="dp-head">
      <div class="dp-icon">${cats[d.category][0]}</div>
      <div class="dp-title">
        <h2>${escapeHtml(d.name || d.label)}</h2>
        <div class="muted">${escapeHtml(d.label)} <span class="dev-conf ${d.confidence}">${d.confidence}</span> · ${escapeHtml(cats[d.category][1])}</div>
      </div>
      <div class="section-actions">
        ${d.ip && IS_ADMIN ? `<button type="button" class="section-btn" id="dp-scan">${pending ? 'Prioritize scan' : 'Scan now'}</button>` : ''}
        ${d.hostId ? `<button type="button" class="section-btn" data-open-host="${d.hostId}">Open card</button>` : d.ip && IS_ADMIN ? '<button type="button" class="section-btn" id="dp-monitor">Monitor</button>' : ''}
      </div>
    </div>`}
    ${d.note ? `<p class="pm-note">⚠ ${escapeHtml(d.note)}</p>` : ''}
    ${d.netWarning ? `<p class="pm-note">⚠ ${escapeHtml(d.netWarning.charAt(0).toUpperCase() + d.netWarning.slice(1))} — move it to the right Wi-Fi network (SSID) or switch port VLAN.</p>` : ''}
    <div class="dp-grid">
      <div class="dp-card">
        <h4>Identity</h4>
        ${fact('Name', escapeHtml(sum.name || d.name || ''))}
        ${fact('Model', escapeHtml(sum.model || ''))}
        ${fact('Maker', escapeHtml(sum.maker || d.vendor || (d.randomMac ? 'unknown (private address)' : '')))}
        ${fact('Network card maker', sum.maker && d.vendor && !d.vendor.toLowerCase().includes(String(sum.maker).toLowerCase()) ? escapeHtml(d.vendor) : '')}
        ${fact('Firmware', escapeHtml(sum.firmware || d.ha?.firmware || ''))}
        ${fact('Home Assistant', d.ha ? escapeHtml([d.ha.name, [d.ha.maker, d.ha.model].filter(Boolean).join(' ')].filter(Boolean).join(' · '))
          + (d.ha.byMaker ? ' <span class="muted small">(matched by maker: the only one on both sides)</span>' : '') : '')}
        ${fact('Room (Home Assistant)', escapeHtml(d.ha?.area || ''))}
        ${fact('Serial', escapeHtml(sum.serial || ''))}
        ${fact('Operating system', escapeHtml((wifi && wifi.os && wifi.os !== 'NOFP') ? wifi.os : ''))}
        ${fact('Status', host ? `<span class="status-dot ${statusClass(host)}" style="display:inline-block;width:9px;height:9px"></span> ${escapeHtml(host.status)}` : '')}
      </div>
      <div class="dp-card">
        ${connection}
        ${fact('IP address', escapeHtml(d.ip || '—'))}
        ${fact('MAC address', d.mac ? `<span class="mono">${escapeHtml(d.mac)}</span>${d.randomMac ? ' <span class="muted small">(private, changes per network)</span>' : ''}` : '')}
        ${fact('Network', escapeHtml(d.subnetName ? `${d.subnetName} · ${d.subnet}` : d.subnet || ''))}
        ${fact('Location', host && host.locationId && locationNames[host.locationId] ? escapeHtml(locationNames[host.locationId]) : '')}
      </div>
    </div>
    <div class="dp-card dp-wide">
      <h4>Services <span class="muted small">${pending ? '· scan requested' : scanned ? `· scanned ${escapeHtml(fmtAgo(scanned))}` : '· not scanned yet'}</span></h4>
      ${scan ? services + (discovery ? `<h4>Discovery</h4>${discovery}` : '') : `<p class="muted small">${pending ? 'Scan requested: waiting for the background scanner.' : 'Click <strong>Scan now</strong> to ask the device what it is (ports, UPnP, NetBIOS, SNMP). Devices are also scanned once a day.'}</p>`}
    </div>
    <div class="dp-card dp-wide"><h4>Traffic, last 24 hours</h4>${traffic}</div>`;
  if (target) {
    target.innerHTML = html;
    return;
  }
  document.getElementById('device-body').innerHTML = html;

  const body = document.getElementById('device-body');
  body.querySelectorAll('[data-open-host]').forEach(b => b.addEventListener('click', () => { closeDevicePanel(); openModal(Number(b.dataset.openHost)); }));
  document.getElementById('dp-scan')?.addEventListener('click', async () => {
    const resp = await api(`api/device-info.php?${devQuery(devPanel)}&scan=1`, { method: 'POST' });
    if (!resp.ok) { alert((await resp.json().catch(() => ({}))).error || 'Could not start the scan'); return; }
    loadDevicePanel();
  });
  document.getElementById('dp-monitor')?.addEventListener('click', e => monitorDevice(d, e.target));
}

// ---- Cards with device type IoT / Console / TV ----

const DEVICE_SCAN_TYPES = ['iot', 'console', 'tv', 'camera'];
let devInfoHost = null;
let devInfoTimer = null;

/** One line on the card: the model the device reported when scanned. */
function renderCardDeviceInfo(host) {
  if (!DEVICE_SCAN_TYPES.includes(host.device)) return '';
  const i = host.deviceInfo;
  if (!i) return '<div class="card-devinfo muted">Device info: scanning soon…</div>';
  const what = [i.maker && i.model && !i.model.toLowerCase().includes(String(i.maker).toLowerCase()) ? `${i.maker} ${i.model}` : i.model || i.maker, i.firmware && `fw ${i.firmware}`]
    .filter(Boolean).join(' · ');
  return what ? `<div class="card-devinfo" title="${escapeHtml(i.name || '')}">${escapeHtml(what)}</div>` : '';
}

/** Called from updateModal(): the Device info section for IoT / Console / TV cards. */
function showDeviceInfoSection(host) {
  const el = document.getElementById('devinfo-section');
  const applies = DEVICE_SCAN_TYPES.includes(host.device);
  el.classList.toggle('hidden', !applies);
  if (!applies) { stopDeviceInfoSection(); return; }
  if (devInfoHost !== host.id) {
    stopDeviceInfoSection();
    devInfoHost = host.id;
    document.getElementById('devinfo-body').innerHTML = '<div class="switch-info-status">Loading…</div>';
    loadDeviceInfoSection(host, true);
  }
}

function stopDeviceInfoSection() {
  clearTimeout(devInfoTimer);
  devInfoTimer = null;
  devInfoHost = null;
}

async function loadDeviceInfoSection(host, first) {
  let data;
  try {
    const resp = await api(`api/device-info.php?ip=${encodeURIComponent(host.ip)}`);
    data = await resp.json();
    if (!resp.ok) throw new Error(data.error || 'No device info');
  } catch (err) {
    if (devInfoHost === host.id) document.getElementById('devinfo-body').innerHTML = `<div class="switch-info-status details-error">${escapeHtml(err.message)}</div>`;
    return;
  }
  if (devInfoHost !== host.id) return;
  const scanned = data.scan && data.scan.scannedAt;
  const pending = data.scan && data.scan.requestedAt;
  // First look and nothing from the last day: ask the device now.
  if (first && !pending && (!scanned || Date.now() - scanned > 86400000) && IS_ADMIN) {
    await api(`api/device-info.php?ip=${encodeURIComponent(host.ip)}&scan=1`, { method: 'POST' });
    return loadDeviceInfoSection(host, false);
  }
  renderDevicePanel(data, document.getElementById('devinfo-body'));
  const actions = document.getElementById('devinfo-actions');
  actions.innerHTML = IS_ADMIN ? `<button type="button" class="section-btn">${pending ? 'Prioritize scan' : 'Scan now'}</button>` : '';
  actions.querySelector('button')?.addEventListener('click', async () => {
    try {
      const resp = await api(`api/device-info.php?ip=${encodeURIComponent(host.ip)}&scan=1`, { method: 'POST' });
      if (!resp.ok) throw new Error((await resp.json()).error || 'Could not request the scan');
      loadDeviceInfoSection(host, false);
    } catch (err) {
      alert(err.message);
    }
  });
  document.getElementById('devinfo-body').querySelectorAll('[data-open-host]').forEach(b => b.addEventListener('click', () => openModal(Number(b.dataset.openHost))));
  clearTimeout(devInfoTimer);
  if (pending) devInfoTimer = setTimeout(() => loadDeviceInfoSection(host, false), 4000);
}
