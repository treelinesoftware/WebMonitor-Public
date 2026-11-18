// Switch/router configuration backups in host Details (admins only).
// Uses api(), escapeHtml(), copyText(), hostsById and IS_ADMIN from app.js.

let cfgHostId = null;
let cfgData = null;
let cfgPoll = null;
let cfgEditing = false;

const CFG_SCHEDULE_LABELS = { off: 'Only when I click Back up now', daily: 'Every day', weekly: 'Every week' };

/** Called from updateModal() for every open host. */
function showConfigSection(host) {
  const el = document.getElementById('config-section');
  const applies = IS_ADMIN && ['switch', 'router', 'ap'].includes(host.device);
  el.classList.toggle('hidden', !applies);
  if (!applies) { stopConfigSection(); return; }
  if (cfgHostId !== host.id) {
    stopConfigSection();
    cfgHostId = host.id;
    document.getElementById('config-body').innerHTML = '<div class="switch-info-status">Loading…</div>';
    loadConfigBackups();
  }
}

function stopConfigSection() {
  clearTimeout(cfgPoll);
  cfgPoll = null;
  cfgHostId = null;
  cfgData = null;
  cfgEditing = false;
}

async function loadConfigBackups() {
  const id = cfgHostId;
  if (id == null) return;
  let data;
  try {
    const resp = await api(`api/switch-config.php?host=${id}`);
    data = await resp.json();
    if (!resp.ok) throw new Error(data.error || 'Could not load backups');
  } catch (err) {
    if (cfgHostId === id) document.getElementById('config-body').innerHTML = `<div class="switch-info-status details-error">${escapeHtml(err.message)}</div>`;
    return;
  }
  if (cfgHostId !== id) return;
  cfgData = data;
  if (!cfgEditing) renderConfigSection();
  // While a backup is pending, check back every few seconds.
  clearTimeout(cfgPoll);
  if (data.settings && data.settings.requestedAt) cfgPoll = setTimeout(loadConfigBackups, 4000);
}

function fmtCfgTime(ms) {
  return new Date(ms).toLocaleString([], { year: 'numeric', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' });
}

function vendorLabel(id) {
  return (cfgData.vendors.find(v => v.id === id) || {}).label || id;
}

function renderConfigSection() {
  const body = document.getElementById('config-body');
  const actions = document.getElementById('config-actions');
  const s = cfgData.settings;
  if (!s) {
    actions.innerHTML = '';
    body.innerHTML = `<p class="muted">Save this device's configuration as a new version every time it's downloaded, and see what changed
      between versions. First tell WebMonitor what kind of device it is and how to log in.</p>
      <button type="button" class="primary-btn" id="cfg-setup">Set up configuration backups</button>`;
    document.getElementById('cfg-setup').addEventListener('click', () => renderConfigForm());
    return;
  }
  actions.innerHTML = `
    <button type="button" class="section-btn" id="cfg-backup" ${s.requestedAt ? 'disabled' : ''}>${s.requestedAt ? 'Backing up…' : 'Back up now'}</button>
    <button type="button" class="section-btn" id="cfg-edit">Settings</button>`;
  document.getElementById('cfg-backup').addEventListener('click', backupNow);
  document.getElementById('cfg-edit').addEventListener('click', () => renderConfigForm());

  let status = `${escapeHtml(vendorLabel(s.vendor))} · ${escapeHtml(s.username)}@port ${s.port} · ${s.auth === 'key' ? 'SSH key' : 'password'}
    · ${escapeHtml(CFG_SCHEDULE_LABELS[s.schedule] || s.schedule)}${s.legacySsh ? ' · <span class="cfg-legacy" title="SHA-1 SSH allowed for this device">old SSH encryption allowed</span>' : ''}`;
  if (s.requestedAt) status += '<br>Backup requested — it runs within a minute.';
  else if (s.lastError && (!s.lastOkAt || s.lastAttemptAt >= s.lastOkAt)) {
    status += `<div class="cfg-error">Last attempt ${escapeHtml(fmtCfgTime(s.lastAttemptAt))} failed: ${escapeHtml(s.lastError)}
      ${/host key changed/.test(s.lastError) ? '<button type="button" class="section-btn" id="cfg-forget">Forget host key</button>' : ''}</div>`;
  }
  const v = cfgData.versions;
  body.innerHTML = `<div class="cfg-status muted small">${status}</div>
    ${v.length ? `<div class="details-table-wrap"><table class="switch-info-table cfg-table">
      <thead><tr><th>Version</th><th>Saved</th><th>Size</th><th>Changes</th><th></th></tr></thead>
      <tbody>${v.map((x, i) => `<tr data-v="${x.id}">
        <td>#${v.length - i}</td>
        <td>${escapeHtml(fmtCfgTime(x.fetchedAt))}${x.source === 'schedule' ? ' <span class="muted small">scheduled</span>' : ''}</td>
        <td>${(x.bytes / 1024).toFixed(1)} KB</td>
        <td>${i === v.length - 1 ? '<span class="muted">first</span>' : x.changed ? '<span class="cfg-changed">changed</span>' : '<span class="muted">no changes</span>'}</td>
        <td class="cfg-actions">
          <button type="button" class="link-btn" data-act="view">View</button>
          ${i < v.length - 1 ? '<button type="button" class="link-btn" data-act="diff">Compare</button>' : ''}
          <a class="link-btn" href="api/switch-config.php?version=${x.id}&download=1">Download</a>
          <button type="button" class="link-btn danger" data-act="delete" title="Delete this version">✕</button>
        </td></tr>`).join('')}</tbody></table></div>`
      : '<p class="muted">No versions saved yet. Click <strong>Back up now</strong>.</p>'}
    <div id="cfg-viewer"></div>`;
  document.getElementById('cfg-forget')?.addEventListener('click', forgetHostKey);
  body.querySelectorAll('[data-act]').forEach(b => b.addEventListener('click', () => {
    const vid = Number(b.closest('tr').dataset.v);
    if (b.dataset.act === 'view') viewVersion(vid);
    if (b.dataset.act === 'diff') diffVersion(vid);
    if (b.dataset.act === 'delete') deleteVersion(vid);
  }));
}

async function backupNow() {
  const resp = await api(`api/switch-config.php?host=${cfgHostId}&backup=1`, { method: 'POST' });
  if (!resp.ok) { alert((await resp.json().catch(() => ({}))).error || 'Could not start the backup'); return; }
  loadConfigBackups();
}

async function forgetHostKey() {
  if (!confirm('Forget this device\'s SSH host key? Only do this if the device was replaced or re-keyed; the next backup trusts whatever key it presents.')) return;
  await api(`api/switch-config.php?host=${cfgHostId}&forget_key=1`, { method: 'POST' });
  backupNow();
}

async function viewVersion(vid) {
  const box = document.getElementById('cfg-viewer');
  box.innerHTML = '<div class="switch-info-status">Loading…</div>';
  const data = await (await api(`api/switch-config.php?version=${vid}`)).json();
  box.innerHTML = `<div class="cfg-viewer-head"><strong>Saved ${escapeHtml(fmtCfgTime(data.version.fetchedAt))}</strong>
      <button type="button" class="link-btn" id="cfg-close">Close</button></div>
    <pre class="cfg-pre">${escapeHtml(data.content)}</pre>`;
  document.getElementById('cfg-close').addEventListener('click', () => { box.innerHTML = ''; });
  box.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
}

/** Changes from the previous version, with 3 lines of context around each change. */
async function diffVersion(vid) {
  const box = document.getElementById('cfg-viewer');
  box.innerHTML = '<div class="switch-info-status">Comparing…</div>';
  const data = await (await api(`api/switch-config.php?version=${vid}&diff=1`)).json();
  const d = data.diff || [];
  const keep = new Array(d.length).fill(false);
  d.forEach(([op], i) => { if (op !== '=') for (let k = Math.max(0, i - 3); k <= Math.min(d.length - 1, i + 3); k++) keep[k] = true; });
  let html = '';
  let skipped = 0;
  d.forEach(([op, line], i) => {
    if (!keep[i]) { skipped++; return; }
    if (skipped) { html += `<div class="diff-skip">… ${skipped} unchanged line${skipped === 1 ? '' : 's'} …</div>`; skipped = 0; }
    const cls = op === '+' ? 'diff-add' : op === '-' ? 'diff-del' : 'diff-ctx';
    html += `<div class="${cls}">${op === '=' ? ' ' : op} ${escapeHtml(line)}</div>`;
  });
  if (skipped && html) html += `<div class="diff-skip">… ${skipped} unchanged line${skipped === 1 ? '' : 's'} …</div>`;
  const adds = d.filter(x => x[0] === '+').length;
  const dels = d.filter(x => x[0] === '-').length;
  box.innerHTML = `<div class="cfg-viewer-head"><strong>${escapeHtml(fmtCfgTime(data.previous.fetchedAt))} → ${escapeHtml(fmtCfgTime(data.version.fetchedAt))}</strong>
      <span class="muted small">${adds} added, ${dels} removed</span>
      <button type="button" class="link-btn" id="cfg-close">Close</button></div>
    <div class="cfg-pre cfg-diff">${html || '<div class="muted">No differences (only timestamps changed).</div>'}</div>`;
  document.getElementById('cfg-close').addEventListener('click', () => { box.innerHTML = ''; });
  box.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
}

async function deleteVersion(vid) {
  if (!confirm('Delete this saved configuration version? This can\'t be undone.')) return;
  await api(`api/switch-config.php?version=${vid}`, { method: 'DELETE' });
  loadConfigBackups();
}

/** Setup instructions for WebMonitor's SSH key, per device type. */
function keyHelp(vendor, user) {
  const u = escapeHtml(user || 'webmonitor');
  const help = {
    cisco_ios: `Cisco IOS 15.x/IOS-XE 16.x only accept RSA keys for login; with an older release use <strong>Password</strong> login
      with a read-only privilege-15 account instead.`,
    fortinet: `<code>config system admin</code> → <code>edit ${u}</code> → <code>set ssh-public-key1 "&lt;key&gt;"</code> → <code>end</code>`,
    juniper: `<code>set system login user ${u} authentication ssh-ed25519 "&lt;key&gt;"</code>`,
    mikrotik: `Upload the key as a file, then <code>/user ssh-keys import public-key-file=webmonitor.pub user=${u}</code>`,
    aruba_cx: `<code>user ${u} authorized-key "&lt;key&gt;"</code>`,
    aruba_instant: `Aruba Instant only accepts password logins over SSH: use <strong>Password</strong> with the cluster's admin account.`,
    pfsense: `System → User Manager → edit <strong>${u}</strong> → paste the key into <em>Authorized SSH Keys</em> → Save.
      SSH must be enabled (System → Advanced → Admin Access → Secure Shell), and the account needs shell access.`,
  };
  return help[vendor] || 'Add the key to the account\'s authorized keys on the device (see its manual for "SSH public key authentication").';
}

function renderConfigForm() {
  cfgEditing = true;
  const s = cfgData.settings || { vendor: '', port: 22, username: '', auth: 'password', schedule: 'daily', command: '' };
  const actions = document.getElementById('config-actions');
  actions.innerHTML = '';
  document.getElementById('config-body').innerHTML = `
    <form id="cfg-form" class="settings-form cfg-form">
      <div class="form-grid">
        <label>Device type <select id="cfg-vendor" required>
          <option value="">Choose…</option>
          ${cfgData.vendors.map(v => `<option value="${v.id}" ${v.id === s.vendor ? 'selected' : ''}>${escapeHtml(v.label)}</option>`).join('')}
        </select></label>
        <label>Back up automatically <select id="cfg-schedule">${cfgData.schedules.map(k =>
          `<option value="${k}" ${k === s.schedule ? 'selected' : ''}>${escapeHtml(CFG_SCHEDULE_LABELS[k] || k)}</option>`).join('')}</select></label>
        <label>Username <input id="cfg-user" maxlength="64" required value="${escapeHtml(s.username)}" autocomplete="off" /></label>
        <label>SSH port <input id="cfg-port" type="number" min="1" max="65535" value="${s.port}" /></label>
        <label class="cfg-command hidden">Command that prints the configuration
          <input id="cfg-command" maxlength="255" value="${escapeHtml(s.command || '')}" placeholder="e.g. show running-config" /></label>
      </div>
      <div class="cfg-auth">
        <label class="check"><input type="radio" name="cfg-auth" value="password" ${s.auth !== 'key' ? 'checked' : ''} /> Password</label>
        <label class="check"><input type="radio" name="cfg-auth" value="key" ${s.auth === 'key' ? 'checked' : ''} /> WebMonitor's SSH key</label>
      </div>
      <div class="cfg-pw form-grid">
        <label>Password <input id="cfg-pw" type="password" autocomplete="new-password"
          placeholder="${s.hasPassword ? 'saved — leave blank to keep' : ''}" /></label>
      </div>
      <div class="cfg-enable form-grid">
        <label>Enable password <span class="muted small">(only if the login lands at a &gt; prompt)</span>
          <input id="cfg-enable" type="password" autocomplete="new-password" placeholder="${s.hasEnablePassword ? 'saved — leave blank to keep' : 'not needed for privilege 15 accounts'}" /></label>
        ${s.hasEnablePassword ? '<label class="check"><input type="checkbox" id="cfg-enable-clear" /> Remove the saved enable password</label>' : ''}
      </div>
      <div class="cfg-key hidden">
        ${cfgData.publicKey ? `<p class="hint">Add this key to the <strong>${escapeHtml(s.username || 'backup')}</strong> account on the device:</p>
          <pre class="install-cmd" id="cfg-pubkey">${escapeHtml(cfgData.publicKey)}</pre>
          <button type="button" class="section-btn" id="cfg-copy-key">Copy key</button>
          <p class="hint" id="cfg-key-help"></p>`
        : '<p class="hint">WebMonitor creates its SSH key with the first backup; save with Password for now, or save and click Back up now, then come back here to copy the key.</p>'}
      </div>
      <label class="check cfg-legacy-opt"><input type="checkbox" id="cfg-legacy" ${s.legacySsh ? 'checked' : ''} />
        Allow old SSH encryption (SHA-1) for this device</label>
      <p class="hint">Only tick this for devices whose firmware offers nothing newer — e.g. Cisco IOS 15 and IOS-XE 16.3 and
        earlier, where backups otherwise fail with "old SSH encryption". It applies to this device's backups only; updating
        the firmware is the better fix.</p>
      <p class="hint">Use an account that can read the configuration and nothing more where the device allows it.
        Passwords are stored encrypted and are never shown again.</p>
      <div class="form-row">
        <button type="submit" class="primary-btn">Save</button>
        <button type="button" class="section-btn" id="cfg-cancel">Cancel</button>
        <span class="form-msg" id="cfg-msg"></span>
      </div>
    </form>`;
  const form = document.getElementById('cfg-form');
  const sync = () => {
    const vendor = cfgData.vendors.find(v => v.id === form.querySelector('#cfg-vendor').value);
    const auth = form.querySelector('input[name="cfg-auth"]:checked').value;
    form.querySelector('.cfg-command').classList.toggle('hidden', !(vendor && vendor.needsCommand));
    form.querySelector('.cfg-pw').classList.toggle('hidden', auth !== 'password');
    form.querySelector('.cfg-key').classList.toggle('hidden', auth !== 'key');
    form.querySelector('.cfg-enable').classList.toggle('hidden', !(vendor && vendor.enable));
    const help = document.getElementById('cfg-key-help');
    if (help) help.innerHTML = keyHelp(vendor ? vendor.id : '', form.querySelector('#cfg-user').value);
  };
  form.addEventListener('change', sync);
  form.querySelector('#cfg-user').addEventListener('input', sync);
  sync();
  document.getElementById('cfg-copy-key')?.addEventListener('click', e => copyText(cfgData.publicKey, e.target));
  document.getElementById('cfg-cancel').addEventListener('click', () => { cfgEditing = false; renderConfigSection(); });
  form.addEventListener('submit', async e => {
    e.preventDefault();
    const body = {
      vendor: form.querySelector('#cfg-vendor').value,
      schedule: form.querySelector('#cfg-schedule').value,
      username: form.querySelector('#cfg-user').value.trim(),
      port: Number(form.querySelector('#cfg-port').value || 22),
      auth: form.querySelector('input[name="cfg-auth"]:checked').value,
      command: form.querySelector('#cfg-command').value.trim(),
      password: form.querySelector('#cfg-pw').value,
      enablePassword: form.querySelector('#cfg-enable').value,
      clearEnablePassword: !!form.querySelector('#cfg-enable-clear')?.checked,
      legacySsh: form.querySelector('#cfg-legacy').checked,
    };
    const resp = await api(`api/switch-config.php?host=${cfgHostId}`, {
      method: 'PUT', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body),
    });
    const data = await resp.json().catch(() => ({}));
    if (!resp.ok) {
      const msg = document.getElementById('cfg-msg');
      msg.textContent = data.error || 'Could not save';
      msg.className = 'form-msg err';
      return;
    }
    cfgEditing = false;
    loadConfigBackups();
  });
}
