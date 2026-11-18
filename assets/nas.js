// NAS hosts (SNMP): card summary, Details section, and the host settings
// editor (name, device type, OS, SNMP community) in Details.
// Uses escapeHtml(), fmtBytes(), fmtDuration(), meterRow(), kvRows(),
// hostsById, openHostId, IS_ADMIN, api(), render(), updateModal() from app.js.

const DEVICE_LABELS_EDIT = {
  server: 'Server', desktop: 'Desktop', laptop: 'Laptop', mobile: 'Mobile', router: 'Router', switch: 'Switch',
  ap: 'Access Point', printer: 'Printer', camera: 'Camera', nas: 'NAS', ups: 'UPS',
  iot: 'IoT / smart home', console: 'Game console', tv: 'TV / streaming',
};
const OS_LABELS_EDIT = { linux: 'Linux', windows: 'Windows', macos: 'macOS', other: 'Other / Unknown' };

/** One line on the card: "QNAP TS-1231XU-RP · 8 drives OK · DataVol1 95% full". */
function renderCardNas(n) {
  if (!n) return '';
  const parts = [[n.vendor, n.model].filter(Boolean).join(' ') || 'NAS'];
  parts.push(n.badDisks ? `${n.badDisks} of ${n.disks} drives need attention` : `${n.disks} drives OK`);
  if (!n.poolsOk) parts.push('pool degraded');
  const f = n.fullest;
  if (f) parts.push(`${f.name} ${Math.round(f.usedPct)}% full`);
  const bad = n.badDisks || !n.poolsOk || (f && f.usedPct >= 95);
  const warn = !bad && f && f.usedPct >= 85;
  return `<div class="card-agent ${bad ? 'offline' : warn ? 'warn' : 'online'}">${escapeHtml(parts.join(' · '))}</div>`;
}

function renderNasDetails(n) {
  if (!n) return '';
  let html = `<h4>${escapeHtml(n.vendor || 'NAS')} ${escapeHtml(n.model || '')}</h4><dl class="details-grid">${kvRows([
    ['Name', n.name],
    ['Serial', n.serial],
    ['Firmware', n.firmware],
    ['Drive bays', n.bays],
    ['System temperature', n.systemTempC != null ? `${n.systemTempC} °C` : null],
  ])}</dl>`;
  let res = '';
  if (n.cpuPct != null) res += meterRow('CPU', `${n.cpuPct}%`, n.cpuPct);
  if (n.memory && n.memory.total) {
    res += meterRow('Memory', `${fmtBytes(n.memory.used)} / ${fmtBytes(n.memory.total)} (excl. cache)`, n.memory.used / n.memory.total * 100);
  }
  if (res) html += `<div class="storage-list">${res}</div>`;

  if ((n.volumes || []).length) {
    html += '<h4>Volumes</h4><div class="storage-list">' + n.volumes.map(v => {
      const status = v.status && v.status !== 'Ready' ? ` · ${v.status}` : '';
      return meterRow(`${v.name}${v.pool != null ? ` (pool ${v.pool})` : ''}`,
        `${fmtBytes(v.bytes - v.freeBytes)} / ${fmtBytes(v.bytes)} (${v.usedPct}%)${status}`, v.usedPct || 0);
    }).join('') + '</div>';
  }
  if ((n.pools || []).length) {
    html += `<h4>Storage pools</h4><div class="details-table-wrap"><table class="switch-info-table">
      <thead><tr><th>Pool</th><th>RAID</th><th>Size</th><th>Status</th></tr></thead><tbody>${n.pools.map(p => `
        <tr><td>Pool ${escapeHtml(String(p.id))}</td>
          <td>${escapeHtml(p.level)}${p.level === 'Single' ? ' <span class="badge warn" title="One drive, no redundancy">no redundancy</span>' : ''}</td>
          <td>${p.bytes ? fmtBytes(p.bytes) : '—'}</td>
          <td><span class="port-status ${p.status === 'Ready' ? 'up' : 'down'}">${escapeHtml(p.status)}</span></td></tr>`).join('')}
      </tbody></table></div>`;
  }
  if ((n.disks || []).length) {
    html += `<h4>Drives</h4><div class="details-table-wrap"><table class="switch-info-table">
      <thead><tr><th>Bay</th><th>Model</th><th>Size</th><th>Temp</th><th>SMART</th></tr></thead><tbody>${n.disks.map(d => {
        const ok = String(d.smart).toUpperCase() === 'GOOD' && d.status === 0;
        const hot = d.tempC != null && d.tempC >= 55;
        return `<tr><td>${escapeHtml(d.slot)}</td><td class="small">${escapeHtml(d.model)}</td>
          <td>${d.bytes ? fmtBytes(d.bytes) : '—'}</td>
          <td class="${hot ? 'warn-text' : ''}">${d.tempC != null ? d.tempC + ' °C' : '—'}</td>
          <td><span class="port-status ${ok ? 'up' : 'down'}">${escapeHtml(d.smart || '?')}</span></td></tr>`;
      }).join('')}</tbody></table></div>`;
  }
  if ((n.fans || []).length) {
    html += `<h4>Fans</h4><dl class="details-grid">${kvRows(n.fans.map(f => [f.name, f.rpm != null ? `${f.rpm} RPM` : '—']))}</dl>`;
  }
  return html;
}

// ---- Host settings editor (Details title "Edit" button) ----

function renderHostEditor(host) {
  const box = document.getElementById('host-edit');
  box.classList.remove('hidden');
  box.innerHTML = `
    <form id="host-edit-form" class="settings-form host-edit-form">
      <div class="form-grid">
        <label>Name <input id="he-name" maxlength="60" required value="${escapeHtml(host.name === host.ip ? '' : host.name)}" placeholder="${escapeHtml(host.ip)}" /></label>
        <label>Device type <select id="he-device">${Object.entries(DEVICE_LABELS_EDIT).map(([v, l]) =>
          `<option value="${v}" ${v === host.device ? 'selected' : ''}>${l}</option>`).join('')}</select></label>
        <label>OS <select id="he-os">${Object.entries(OS_LABELS_EDIT).map(([v, l]) =>
          `<option value="${v}" ${v === host.os ? 'selected' : ''}>${l}</option>`).join('')}</select></label>
        <label>SNMP community <input id="he-community" maxlength="64" value="${escapeHtml(host.community || '')}" autocomplete="off" /></label>
      </div>
      <label>Location <select id="he-location">
          <option value="">— none —</option>
          ${Object.entries(locationNames).map(([id, n]) => `<option value="${id}" ${Number(id) === host.locationId ? 'selected' : ''}>${escapeHtml(n)}</option>`).join('')}
        </select> ${IS_ADMIN ? '<span class="muted small">(manage in Settings → Locations)</span>' : ''}</label>
      <label class="check"><input type="checkbox" id="he-bw-exempt" ${host.bwExempt ? 'checked' : ''} />
        Expected high traffic — no bandwidth alerts for this host (e.g. a download or backup server)</label>
      <p class="hint">Set the device type to <strong>NAS</strong> for QNAP storage: WebMonitor then reads its volumes,
        drives, RAID pools, fans and temperatures over SNMP every 5 minutes and alerts on them (no agent needed).</p>
      <div class="form-row">
        <button type="submit" class="primary-btn">Save</button>
        <button type="button" class="section-btn" id="he-cancel">Cancel</button>
        <span class="form-msg" id="he-msg"></span>
      </div>
    </form>`;
  document.getElementById('he-cancel').addEventListener('click', () => box.classList.add('hidden'));
  document.getElementById('host-edit-form').addEventListener('submit', async e => {
    e.preventDefault();
    const name = document.getElementById('he-name').value.trim() || host.ip;
    const changes = {};
    if (name !== host.name) changes.name = name;
    for (const [field, id] of [['device', 'he-device'], ['os', 'he-os'], ['community', 'he-community']]) {
      const v = document.getElementById(id).value.trim();
      if (v !== (host[field] || '')) changes[field] = v;
    }
    const loc = document.getElementById('he-location').value;
    if ((loc === '' ? null : Number(loc)) !== (host.locationId ?? null)) changes.locationId = loc === '' ? null : Number(loc);
    const bw = document.getElementById('he-bw-exempt').checked;
    if (bw !== !!host.bwExempt) changes.bwExempt = bw;
    const msg = document.getElementById('he-msg');
    if (!Object.keys(changes).length) { box.classList.add('hidden'); return; }
    const resp = await api(`api/host.php?id=${host.id}`, {
      method: 'PATCH', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(changes),
    });
    const data = await resp.json().catch(() => ({}));
    if (!resp.ok) {
      msg.textContent = data.error || 'Could not save';
      msg.className = 'form-msg err';
      return;
    }
    Object.assign(host, data);
    box.classList.add('hidden');
    render();
    updateModal();
    // New community or newly a NAS: fetch fresh SNMP details right away.
    if (changes.community !== undefined || changes.device === 'nas') collectHostDetails(host.id);
  });
}

document.getElementById('rename-btn').addEventListener('click', () => {
  const host = hostsById.get(openHostId);
  if (host) renderHostEditor(host);
});
