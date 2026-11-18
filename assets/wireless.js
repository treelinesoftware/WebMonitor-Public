// Wi-Fi (Aruba Instant): port/radio strip on AP cards, cluster summary on the
// virtual controller's card, and the Wireless section in host Details.
// Data: host.wifi from api/hosts.php, details from api/wireless.php.
// Uses api(), escapeHtml(), openModal(), openHostId, hostsById from app.js.

let wifiLoadedFor = null;

function fmtUptimeShort(sec) {
  if (sec == null) return '—';
  const d = Math.floor(sec / 86400), h = Math.floor(sec % 86400 / 3600), m = Math.floor(sec % 3600 / 60);
  return d ? `${d}d ${h}h` : h ? `${h}h ${m}m` : `${m}m`;
}

function utilClass(u) {
  return u >= 60 ? 'crit' : u >= 35 ? 'warn' : '';
}

/** AP card: eth0 uplink and one chip per radio. VC card: cluster totals. */
function renderCardWifi(host) {
  const w = host.wifi;
  if (!w) return '';
  let html = '';
  if (w.controller) {
    const c = w.controller;
    html += `<div class="card-wifi"><div class="cw-line">📡 Virtual controller · ${c.apsUp}/${c.aps} APs up · ${c.clients} client${c.clients === 1 ? '' : 's'} · ${c.ssids} SSID${c.ssids === 1 ? '' : 's'}</div></div>`;
  }
  if (w.ap) {
    const a = w.ap;
    const up = a.uplink;
    html += `<div class="card-wifi">
      <div class="cw-ports">
        <span class="cw-eth ${up ? 'cw-linked' : ''}" title="${up ? `eth0 → ${escapeHtml(up.switch)} ${escapeHtml(up.port)}` : 'eth0'}">eth0</span>
        <span class="cw-uplink muted">${up ? `→ ${escapeHtml(shortHostName(up.switch))} ${escapeHtml(up.port)}${up.speedMbps ? ` · ${up.speedMbps >= 1000 ? up.speedMbps / 1000 + 'G' : up.speedMbps + 'M'}` : ''}` : 'switch port not found'}</span>
      </div>
      <div class="cw-radios">${a.radios.map(r => `<span class="cw-radio ${utilClass(r.utilization)}" title="${escapeHtml(r.band)} channel ${escapeHtml(r.channel)} · ${r.utilization ?? '?'}% busy">
          <b>${escapeHtml(r.band.replace(' GHz', 'G'))}</b> ch${escapeHtml(r.channel.replace(/[EP+-]$/, ''))} · ${r.clients}</span>`).join('')}
        <span class="cw-total muted">${a.clients} client${a.clients === 1 ? '' : 's'}${a.role && /conductor|master/i.test(a.role) ? ' · conductor' : ''}</span>
      </div>
    </div>`;
  }
  return html;
}

function shortHostName(n) {
  return String(n).split('.')[0];
}

/** Called from updateModal() for every open host. */
function showWifiSection(host) {
  const el = document.getElementById('wifi-section');
  const applies = !!host.wifi || host.device === 'ap';
  el.classList.toggle('hidden', !applies);
  if (!applies) { wifiLoadedFor = null; return; }
  if (wifiLoadedFor !== host.id) {
    wifiLoadedFor = host.id;
    loadWifi(host.id);
  }
}

async function loadWifi(id) {
  const body = document.getElementById('wifi-body');
  body.innerHTML = '<div class="switch-info-status">Loading…</div>';
  try {
    const data = await (await api(`api/wireless.php?host=${id}`)).json();
    if (openHostId !== id) return;
    document.getElementById('wifi-age').textContent = data.collectedAt ? `· read ${fmtAgoShort(data.collectedAt)}` : '';
    if (data.role === 'controller') body.innerHTML = renderWifiController(data);
    else if (data.role === 'ap') body.innerHTML = renderWifiAp(data);
    else {
      body.innerHTML = `<p class="muted">No Wi-Fi data for this address yet. WebMonitor reads Aruba Instant clusters over SNMP from cards
        with device type <strong>AP</strong> (the virtual controller's address is best) every 5 minutes.</p>`;
    }
    body.querySelectorAll('[data-open-host]').forEach(b => b.addEventListener('click', () => openModal(Number(b.dataset.openHost))));
    const filter = body.querySelector('.wifi-filter');
    filter?.addEventListener('input', () => {
      const q = filter.value.trim().toLowerCase();
      body.querySelectorAll('.wifi-clients tbody tr').forEach(tr => { tr.hidden = q !== '' && !tr.dataset.q.includes(q); });
    });
  } catch (err) {
    if (openHostId === id) body.innerHTML = `<div class="switch-info-status details-error">${escapeHtml(err.message)}</div>`;
  }
}

function wifiHostLink(card, label) {
  return card ? `<button type="button" class="link-btn" data-open-host="${card.id}">${escapeHtml(label || card.name)}</button>` : escapeHtml(label || '');
}

function radiosTable(radios) {
  return `<div class="details-table-wrap"><table class="switch-info-table">
    <thead><tr><th>Radio</th><th>Channel</th><th>Power</th><th>Noise</th><th>Busy</th><th>Clients</th></tr></thead>
    <tbody>${radios.map(r => `<tr><td>${escapeHtml(r.band)}</td><td>${escapeHtml(r.channel)}</td>
      <td>${r.txPower != null ? r.txPower + ' dBm' : '—'}</td><td>${r.noise != null ? r.noise + ' dBm' : '—'}</td>
      <td><span class="cw-util ${utilClass(r.utilization)}">${r.utilization ?? '—'}%</span></td><td>${r.clients}</td></tr>`).join('')}</tbody>
  </table></div>`;
}

function clientsTable(clients, withAp, apsByMac = {}) {
  if (!clients.length) return '<p class="muted small">No clients connected.</p>';
  const snr = s => s == null ? '—' : `<span class="cw-snr ${s >= 35 ? 'good' : s >= 20 ? 'ok' : 'poor'}">${s} dB</span>`;
  return `<input type="search" class="wifi-filter topo-filter" placeholder="Filter clients…" />
    <div class="details-table-wrap"><table class="switch-info-table wifi-clients">
    <thead><tr><th>Client</th><th>IP</th>${withAp ? '<th>AP</th>' : ''}<th>SSID</th><th>Band</th><th>Signal</th><th>Rate</th><th>Connected</th></tr></thead>
    <tbody>${clients.map(c => {
      const ap = withAp ? apsByMac[c.ap] : null;
      return `<tr data-q="${escapeHtml([c.name, c.ip, c.mac, c.ssid, c.os, ap && ap.name].join(' ').toLowerCase())}">
        <td>${c.card ? wifiHostLink(c.card) : escapeHtml(c.name || c.mac)}${c.os ? `<div class="muted small">${escapeHtml(c.os)}</div>` : ''}</td>
        <td>${escapeHtml(c.ip || '—')}<div class="muted small mono">${escapeHtml(c.mac)}</div></td>
        ${withAp ? `<td>${ap ? wifiHostLink(ap.card, ap.name) : '—'}</td>` : ''}
        <td>${escapeHtml(c.ssid || '—')}</td><td>${escapeHtml(c.band || '—')}</td><td>${snr(c.snr)}</td>
        <td>${c.rxRate != null ? `${c.rxRate} Mbps` : '—'}</td><td>${fmtUptimeShort(c.uptimeSec)}</td></tr>`;
    }).join('')}</tbody></table></div>`;
}

function renderWifiAp(d) {
  const a = d.ap;
  const up = a.uplink;
  return `<div class="small wifi-facts">
      <span class="port-status ${a.up ? 'up' : 'down'}">${a.up ? 'Up' : 'Down'}</span> · Aruba ${escapeHtml(a.model || '')}
      · ${escapeHtml(a.role || '')} · up ${fmtUptimeShort(a.uptimeSec)}${a.serial ? ` · serial ${escapeHtml(a.serial)}` : ''}
    </div>
    <h4>Ports</h4>
    <div class="wifi-port">
      <span class="pm-port pm-uplink wifi-eth">eth0</span>
      ${up ? `<span>plugged into ${wifiHostLink({ id: up.switch.id, name: up.switch.name })} <strong>${escapeHtml(up.port)}</strong>
        ${up.description ? `<span class="muted small">“${escapeHtml(up.description)}”</span>` : ''}${up.speedMbps ? ` · ${up.speedMbps >= 1000 ? up.speedMbps / 1000 + ' Gbit/s' : up.speedMbps + ' Mbit/s'}` : ''}</span>`
        : '<span class="muted">No switch WebMonitor reads has learned this AP’s address.</span>'}
    </div>
    <h4>Radios</h4>${radiosTable(a.radios)}
    <h4>Clients (${d.clients.length})</h4>${clientsTable(d.clients, false)}`;
}

function renderWifiController(d) {
  const apsByMac = Object.fromEntries(d.aps.map(a => [a.mac, a]));
  const ssids = {};
  d.clients.forEach(c => { ssids[c.ssid || '(unknown)'] = (ssids[c.ssid || '(unknown)'] || 0) + 1; });
  return `<div class="small wifi-facts">${d.aps.filter(a => a.up).length} of ${d.aps.length} APs up · ${d.clients.length} clients ·
      ${Object.entries(ssids).map(([s, n]) => `${escapeHtml(s)}: ${n}`).join(' · ')}</div>
    <h4>Access points</h4>
    <div class="details-table-wrap"><table class="switch-info-table">
      <thead><tr><th>AP</th><th>IP</th><th>Role</th><th>Radios</th><th>Clients</th><th>Switch port</th><th>Up for</th></tr></thead>
      <tbody>${d.aps.map(a => `<tr>
        <td><span class="status-dot ${a.up ? 'up' : 'down'}"></span> ${wifiHostLink(a.card, a.name)}<div class="muted small">${escapeHtml(a.model || '')}</div></td>
        <td>${escapeHtml(a.ip || '')}</td><td>${escapeHtml(a.role || '')}</td>
        <td class="small">${a.radios.map(r => `${escapeHtml(r.band)} ch${escapeHtml(r.channel)} <span class="cw-util ${utilClass(r.utilization)}">${r.utilization ?? '?'}%</span>`).join('<br>')}</td>
        <td>${a.clients}</td>
        <td>${a.uplink ? `${wifiHostLink({ id: a.uplink.switch.id, name: shortHostName(a.uplink.switch.name) })} ${escapeHtml(a.uplink.port)}` : '—'}</td>
        <td>${fmtUptimeShort(a.uptimeSec)}</td></tr>`).join('')}</tbody>
    </table></div>
    <h4>Clients (${d.clients.length})</h4>${clientsTable(d.clients, true, apsByMac)}`;
}
