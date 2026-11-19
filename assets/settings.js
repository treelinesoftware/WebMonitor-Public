// Settings window (⚙): account, users, notifications, add hosts.
// Uses api(), escapeHtml(), fmtAgoDate() and IS_ADMIN from app.js.

const settingsBackdrop = document.getElementById('settings-backdrop');
const settingsTabs = document.querySelectorAll('.settings-nav-item');
const settingsPanels = document.querySelectorAll('.settings-panel');

// Each panel's blocks become cards: a block starts at each heading (h3) or
// nested section (an "Add …" details stays with the block above it). A first heading that only repeats the section's name is
// dropped, since the window's header already shows it.
settingsPanels.forEach(panel => {
  const label = document.querySelector(`.settings-nav-item[data-panel="${panel.dataset.panel}"]`)?.textContent.trim().toLowerCase();
  const first = panel.firstElementChild;
  if (first && first.tagName === 'H3' && first.textContent.trim().toLowerCase() === label) first.remove();
  let card = null;
  for (const el of [...panel.children]) {
    if (el.classList.contains('settings-raw') || el.classList.contains('settings-intro')) {   // laid out by hand
      card = null;
      continue;
    }
    if (el.tagName === 'SECTION') {
      // A heading left on its own just above the section belongs inside it.
      if (card && card.children.length === 1 && card.firstElementChild.tagName === 'H3') {
        el.prepend(card.firstElementChild);
        card.remove();
      }
      el.classList.add('settings-card');
      card = null;
      continue;
    }
    if (el.tagName === 'H3' || !card) {
      card = document.createElement('div');
      card.className = 'settings-card';
      panel.insertBefore(card, el);
    }
    card.appendChild(el);
  }
});

function openSettings(panel = 'account') {
  settingsBackdrop.classList.remove('hidden');
  showSettingsPanel(panel);
}

function closeSettings() {
  settingsBackdrop.classList.add('hidden');
}

function showSettingsPanel(name) {
  if (!IS_ADMIN && !['account', 'display', 'schedules'].includes(name)) name = 'account';
  settingsTabs.forEach(b => b.classList.toggle('active', b.dataset.panel === name));
  settingsPanels.forEach(p => p.classList.toggle('hidden', p.dataset.panel !== name));
  const tab = document.querySelector(`.settings-nav-item[data-panel="${name}"]`);
  document.getElementById('settings-title').textContent = tab ? tab.textContent.trim() : 'Settings';
  document.getElementById('settings-desc').textContent = tab ? tab.dataset.desc || '' : '';
  document.getElementById('settings-body').scrollTop = 0;
  tab?.scrollIntoView({ block: 'nearest', inline: 'nearest' });   // phones: the strip of sections scrolls sideways
  if (name === 'users') loadUsers();
  if (name === 'notifications') loadNotifications();
  if (name === 'schedules') loadSchedules();   // updates.js
  if (name === 'subnets') loadSubnets();
  if (name === 'locations') loadLocations();
  if (name === 'groups') loadGroups();
  if (name === 'changes') loadChangeSettings();   // changes.js
  if (name === 'homeassistant') loadHomeAssistant();
}

document.getElementById('settings-btn').addEventListener('click', () => openSettings());
document.getElementById('settings-close').addEventListener('click', closeSettings);
settingsBackdrop.addEventListener('click', e => { if (e.target === settingsBackdrop) closeSettings(); });
document.addEventListener('keydown', e => {
  if (e.key === 'Escape' && !settingsBackdrop.classList.contains('hidden')) closeSettings();
});
settingsTabs.forEach(b => b.addEventListener('click', () => showSettingsPanel(b.dataset.panel)));

function setMsg(el, text, ok = true) {
  el.textContent = text;
  el.className = 'form-msg ' + (ok ? 'ok' : 'err');
}

async function jsonApi(url, method, body) {
  const resp = await api(url, {
    method,
    headers: body !== undefined ? { 'Content-Type': 'application/json' } : {},
    body: body !== undefined ? JSON.stringify(body) : undefined,
  });
  const data = resp.status === 204 ? {} : await resp.json().catch(() => ({}));
  if (!resp.ok) throw new Error(data.error || `Request failed (${resp.status})`);
  return data;
}

// ---- Display (theme, columns) ----

const displayForm = document.getElementById('display-form');
const displayMsg = document.getElementById('display-msg');

function currentColumns() {
  const m = document.body.className.match(/\bcols-(auto|2|3|4)\b/);
  return m ? m[1] : 'auto';
}

function syncDisplayForm() {
  displayForm.querySelector(`input[name="theme"][value="${document.documentElement.dataset.theme || 'dark'}"]`).checked = true;
  displayForm.querySelector(`input[name="columns"][value="${currentColumns()}"]`).checked = true;
}

function applyDisplay(theme, columns) {
  if (theme) {
    document.documentElement.dataset.theme = theme;
    // Lets the sign-in page (no user yet) use the same theme.
    try { localStorage.setItem('wm-theme', theme); } catch (e) { /* private mode */ }
    refreshChartTheme();
  }
  if (columns) {
    document.body.className = document.body.className.replace(/\bcols-\S+/, '') + ' cols-' + columns;
  }
}

displayForm.addEventListener('change', async e => {
  const theme = e.target.name === 'theme' ? e.target.value : null;
  const columns = e.target.name === 'columns' ? e.target.value : null;
  applyDisplay(theme, columns);   // instant; then save to the account
  try {
    await jsonApi('api/account.php', 'PATCH', { prefs: theme ? { theme } : { columns } });
    setMsg(displayMsg, 'Saved.');
  } catch (err) {
    setMsg(displayMsg, `Not saved: ${err.message}`, false);
  }
});

syncDisplayForm();
try { localStorage.setItem('wm-theme', document.documentElement.dataset.theme); } catch (e) { /* private mode */ }

// ---- Account ----

document.getElementById('account-form').addEventListener('submit', async e => {
  e.preventDefault();
  const msg = document.getElementById('account-msg');
  const current = document.getElementById('acct-current').value;
  const next = document.getElementById('acct-new').value;
  if (next !== document.getElementById('acct-new2').value) {
    setMsg(msg, "New passwords don't match.", false);
    return;
  }
  try {
    await jsonApi('api/account.php', 'POST', { currentPassword: current, newPassword: next });
    e.target.reset();
    setMsg(msg, 'Password changed.');
  } catch (err) {
    setMsg(msg, err.message, false);
  }
});

// ---- Users ----

const usersList = document.getElementById('users-list');
const usersMsg = document.getElementById('users-msg');

async function loadUsers() {
  try {
    renderUsers(await jsonApi('api/users.php', 'GET'));
  } catch (err) {
    usersList.innerHTML = `<div class="switch-info-status details-error">${escapeHtml(err.message)}</div>`;
  }
}

function renderUsers(users) {
  usersList.innerHTML = `<table class="switch-info-table settings-table">
    <thead><tr><th>User</th><th>Role</th><th>Last sign-in</th><th></th></tr></thead>
    <tbody>${users.map(u => `
      <tr data-id="${u.id}">
        <td>${escapeHtml(u.username)}${u.isMe ? ' <span class="muted">(you)</span>' : ''}</td>
        <td><select class="user-role-select">
          <option value="admin" ${u.role === 'admin' ? 'selected' : ''}>Admin</option>
          <option value="viewer" ${u.role === 'viewer' ? 'selected' : ''}>Viewer</option>
        </select></td>
        <td class="muted">${u.lastLoginAt ? escapeHtml(u.lastLoginAt) : 'never'}</td>
        <td class="row-actions">
          <button type="button" class="section-btn user-reset">Reset password</button>
          ${u.isMe ? '' : '<button type="button" class="section-btn danger user-delete">Delete</button>'}
        </td>
      </tr>`).join('')}</tbody></table>`;

  usersList.querySelectorAll('tr[data-id]').forEach(tr => {
    const id = tr.dataset.id;
    const name = tr.cells[0].textContent.replace(' (you)', '');
    const roleSel = tr.querySelector('.user-role-select');
    const prevRole = roleSel.value;
    roleSel.addEventListener('change', async () => {
      try {
        await jsonApi(`api/users.php?id=${id}`, 'PATCH', { role: roleSel.value });
        setMsg(usersMsg, `${name} is now ${roleSel.value === 'admin' ? 'an admin' : 'a viewer'}.`);
      } catch (err) {
        roleSel.value = prevRole;
        setMsg(usersMsg, err.message, false);
      }
    });
    tr.querySelector('.user-reset').addEventListener('click', async () => {
      const pw = prompt(`New password for ${name} (at least 10 characters):`);
      if (!pw) return;
      try {
        await jsonApi(`api/users.php?id=${id}`, 'PATCH', { password: pw });
        setMsg(usersMsg, `Password reset for ${name}.`);
      } catch (err) {
        setMsg(usersMsg, err.message, false);
      }
    });
    tr.querySelector('.user-delete')?.addEventListener('click', async () => {
      if (!confirm(`Delete user "${name}"? They will be signed out immediately.`)) return;
      try {
        await jsonApi(`api/users.php?id=${id}`, 'DELETE');
        setMsg(usersMsg, `Deleted ${name}.`);
        loadUsers();
      } catch (err) {
        setMsg(usersMsg, err.message, false);
      }
    });
  });
}

document.getElementById('user-form').addEventListener('submit', async e => {
  e.preventDefault();
  const username = document.getElementById('user-name').value.trim();
  try {
    await jsonApi('api/users.php', 'POST', {
      username,
      password: document.getElementById('user-pass').value,
      role: document.getElementById('user-role').value,
    });
    e.target.reset();
    setMsg(usersMsg, `Added ${username}.`);
    loadUsers();
  } catch (err) {
    setMsg(usersMsg, err.message, false);
  }
});

// ---- Notifications ----

const channelsList = document.getElementById('channels-list');
const channelAdd = document.getElementById('channel-add');
const channelForm = document.getElementById('channel-form');
const alertSettingsForm = document.getElementById('alert-settings-form');
const CHANNEL_TYPE_LABELS = { email: 'Email', ntfy: 'ntfy', webhook: 'Webhook' };
let editingChannel = null;   // channel being edited, or null when adding

async function loadNotifications() {
  try {
    const data = await jsonApi('api/notifications.php', 'GET');
    renderChannels(data.channels);
    renderAlertSettings(data.settings);
    renderNotifyLog(data.log);
    if (!editingChannel && !channelForm.innerHTML) renderChannelForm('email');
  } catch (err) {
    channelsList.innerHTML = `<div class="switch-info-status details-error">${escapeHtml(err.message)}</div>`;
  }
  loadAlertHistory();
}

function channelTarget(ch) {
  const c = ch.config;
  if (ch.type === 'email') return `${c.to} via ${c.host}:${c.port}`;
  return c.url || '';
}

function renderChannels(channels) {
  if (!channels.length) {
    channelsList.innerHTML = `<div class="switch-info-status">No destinations yet — alerts are recorded but not sent.
      Add one below (email, ntfy, or a Discord/Slack/Teams webhook).</div>`;
    channelAdd.open = true;
    return;
  }
  channelsList.innerHTML = `<table class="switch-info-table settings-table">
    <thead><tr><th>Name</th><th>Type</th><th>Sends to</th><th>On</th><th></th></tr></thead>
    <tbody>${channels.map(ch => `
      <tr data-id="${ch.id}">
        <td>${escapeHtml(ch.name)}</td>
        <td>${CHANNEL_TYPE_LABELS[ch.type] || escapeHtml(ch.type)}</td>
        <td class="wrap muted">${escapeHtml(channelTarget(ch))}</td>
        <td><input type="checkbox" class="ch-enabled" ${ch.enabled ? 'checked' : ''} title="Enabled" /></td>
        <td class="row-actions">
          <button type="button" class="section-btn ch-test">Send test</button>
          <button type="button" class="section-btn ch-edit">Edit</button>
          <button type="button" class="section-btn danger ch-delete">Delete</button>
        </td>
      </tr>`).join('')}</tbody></table>
    <span class="form-msg" id="channels-msg"></span>`;
  const msg = document.getElementById('channels-msg');

  channelsList.querySelectorAll('tr[data-id]').forEach(tr => {
    const ch = channels.find(c => c.id === +tr.dataset.id);
    tr.querySelector('.ch-enabled').addEventListener('change', async e => {
      try {
        await jsonApi(`api/notifications.php?id=${ch.id}`, 'PATCH', { enabled: e.target.checked });
        setMsg(msg, `${ch.name} ${e.target.checked ? 'enabled' : 'disabled'}.`);
      } catch (err) {
        e.target.checked = !e.target.checked;
        setMsg(msg, err.message, false);
      }
    });
    tr.querySelector('.ch-test').addEventListener('click', async e => {
      const btn = e.target;
      btn.disabled = true;
      btn.textContent = 'Sending…';
      try {
        await jsonApi(`api/notifications.php?id=${ch.id}&test=1`, 'POST', {});
        setMsg(msg, `Test sent to ${ch.name}. Check that it arrived.`);
      } catch (err) {
        setMsg(msg, `${ch.name}: ${err.message}`, false);
      } finally {
        btn.disabled = false;
        btn.textContent = 'Send test';
        loadNotifyLogOnly();
      }
    });
    tr.querySelector('.ch-edit').addEventListener('click', () => {
      editingChannel = ch;
      renderChannelForm(ch.type, ch);
      channelAdd.open = true;
      channelAdd.scrollIntoView({ block: 'nearest' });
    });
    tr.querySelector('.ch-delete').addEventListener('click', async () => {
      if (!confirm(`Delete the "${ch.name}" destination?`)) return;
      try {
        await jsonApi(`api/notifications.php?id=${ch.id}`, 'DELETE');
        loadNotifications();
      } catch (err) {
        setMsg(msg, err.message, false);
      }
    });
  });
}

function field(label, id, value = '', attrs = '') {
  return `<label>${label} <input id="${id}" value="${escapeHtml(value == null ? '' : String(value))}" ${attrs} /></label>`;
}

function renderChannelForm(type, ch = null) {
  const c = ch ? ch.config : {};
  const secretHint = set => (set ? 'placeholder="(unchanged)"' : '');
  let fields = '';
  if (type === 'email') {
    fields = `
      ${field('SMTP server', 'ch-host', c.host, 'placeholder="smtp.gmail.com" required')}
      <div class="form-grid">
        ${field('Port', 'ch-port', c.port || 587, 'type="number" min="1" max="65535" required')}
        <label>Security <select id="ch-security">
          <option value="starttls" ${c.security !== 'ssl' && c.security !== 'none' ? 'selected' : ''}>STARTTLS (587)</option>
          <option value="ssl" ${c.security === 'ssl' ? 'selected' : ''}>SSL/TLS (465)</option>
          <option value="none" ${c.security === 'none' ? 'selected' : ''}>None (internal relay)</option>
        </select></label>
      </div>
      <div class="form-grid">
        ${field('Username', 'ch-username', c.username, 'autocomplete="off"')}
        <label>Password <input id="ch-password" type="password" autocomplete="new-password" ${secretHint(c.password_set)} /></label>
      </div>
      ${field('From address', 'ch-from', c.from, 'type="email" placeholder="alerts@example.com" required')}
      ${field('Send to (comma-separated)', 'ch-to', c.to, 'placeholder="you@example.com, team@example.com" required')}
      <label class="check"><input type="checkbox" id="ch-verify" ${c.verify_tls === false ? '' : 'checked'} />
        Verify the server's TLS certificate (untick only for an internal relay with a self-signed certificate)</label>
      <p class="hint">Gmail / Microsoft 365 need an app password, not your normal password.</p>`;
  } else if (type === 'ntfy') {
    fields = `
      ${field('Topic URL', 'ch-url', c.url, 'placeholder="https://ntfy.sh/your-secret-topic" required')}
      <label>Access token (optional) <input id="ch-token" type="password" autocomplete="off" ${secretHint(c.token_set)} /></label>
      <p class="hint">Install the ntfy app on your phone and subscribe to the same topic. On the public ntfy.sh
        server anyone who knows the topic name can read it, so pick a long, random one.</p>`;
  } else {
    fields = `
      ${field('Webhook URL', 'ch-url', c.url, 'placeholder="https://discord.com/api/webhooks/…" required')}
      <p class="hint">Works with Discord and Slack incoming webhooks, Microsoft Teams workflows, and anything that
        accepts a JSON POST (fields: title, text, priority).</p>`;
  }

  channelForm.innerHTML = `
    <div class="form-grid">
      <label>Type <select id="ch-type" ${ch ? 'disabled' : ''}>
        ${Object.entries(CHANNEL_TYPE_LABELS).map(([v, l]) => `<option value="${v}" ${v === type ? 'selected' : ''}>${l}</option>`).join('')}
      </select></label>
      ${field('Name', 'ch-name', ch ? ch.name : '', 'maxlength="60" placeholder="e.g. My phone" required')}
    </div>
    ${fields}
    <div class="form-row">
      <button type="submit" class="primary-btn">${ch ? 'Save changes' : 'Add destination'}</button>
      ${ch ? '<button type="button" class="section-btn" id="ch-cancel">Cancel</button>' : ''}
      <span class="form-msg" id="channel-form-msg"></span>
    </div>`;
  channelAdd.querySelector('summary').textContent = ch ? `Edit “${ch.name}”` : 'Add a destination…';
  document.getElementById('ch-type').addEventListener('change', e => renderChannelForm(e.target.value));
  document.getElementById('ch-cancel')?.addEventListener('click', () => {
    editingChannel = null;
    renderChannelForm('email');
    channelAdd.open = false;
  });
}

channelForm.addEventListener('submit', async e => {
  e.preventDefault();
  const type = editingChannel ? editingChannel.type : document.getElementById('ch-type').value;
  const val = id => document.getElementById(id)?.value ?? '';
  let config;
  if (type === 'email') {
    config = {
      host: val('ch-host'), port: +val('ch-port'), security: val('ch-security'),
      username: val('ch-username'), password: val('ch-password'),
      from: val('ch-from'), to: val('ch-to'), verify_tls: document.getElementById('ch-verify').checked,
    };
  } else if (type === 'ntfy') {
    config = { url: val('ch-url'), token: val('ch-token') };
  } else {
    config = { url: val('ch-url') };
  }
  const msg = document.getElementById('channel-form-msg');
  try {
    if (editingChannel) {
      await jsonApi(`api/notifications.php?id=${editingChannel.id}`, 'PATCH', { name: val('ch-name'), config });
    } else {
      await jsonApi('api/notifications.php', 'POST', { type, name: val('ch-name'), config, enabled: true });
    }
    editingChannel = null;
    renderChannelForm('email');
    channelAdd.open = false;
    loadNotifications();
  } catch (err) {
    setMsg(msg, err.message, false);
  }
});

function renderAlertSettings(st) {
  const num = (id, label, value, unit, attrs = '') =>
    `<label>${label} <span class="num-unit"><input type="number" id="${id}" value="${value}" min="0" ${attrs} /> ${unit}</span></label>`;
  const chk = (id, label, value) =>
    `<label class="check"><input type="checkbox" id="${id}" ${value ? 'checked' : ''} /> ${label}</label>`;
  alertSettingsForm.innerHTML = `
    <div class="form-grid">
      ${num('as-down', 'Host down after', st.down_after_min, 'minutes', 'max="1440"')}
      ${num('as-agent', 'Agent silent after', st.agent_offline_after_min, 'minutes', 'max="1440"')}
      ${num('as-disk-warn', 'Disk warning at', st.disk_warn_pct, '% full', 'max="100"')}
      ${num('as-disk-crit', 'Disk critical at', st.disk_crit_pct, '% full', 'max="100"')}
      ${num('as-remind', 'Remind about open problems every', st.remind_every_hours, 'hours (0 = never)', 'max="168"')}
      ${num('as-bw', 'High bandwidth: a device above', st.bw_mbps, 'Mbit/s (0 = off)', 'max="100000"')}
      ${num('as-bw-min', '… averaged over', st.bw_minutes, 'minutes', 'max="1440"')}
      ${num('as-bw-day', 'or a device using more than', st.bw_daily_gb, 'GB in a day (0 = off)', 'max="100000"')}
    </div>
    ${chk('as-bw-int', 'Count traffic between my own networks too (default: internet traffic only)', st.bw_internal)}
    ${chk('as-crash', 'Crashes and unexpected shutdowns (always on) and planned reboots', st.alert_reboots)}
    ${chk('as-failed', 'Failed services', st.alert_failed_services)}
    ${chk('as-av', 'Antivirus off, out of date, or missing on Windows', st.alert_antivirus)}
    ${chk('as-services', 'Monitored services down, or certificates expiring (Services tab)', st.alert_services)}
    ${chk('as-flexlm', 'FlexLM licence servers down, licences all in use, or expiring (Licences tab)', st.alert_flexlm)}
    ${chk('as-config', 'Switch configuration changes (found by configuration backups)', st.alert_config_changes)}
    ${chk('as-resolved', 'Also notify when a problem is resolved', st.notify_resolved)}
    <div class="form-row"><button type="submit" class="primary-btn">Save</button><span class="form-msg" id="alert-settings-msg"></span></div>`;
}

alertSettingsForm.addEventListener('submit', async e => {
  e.preventDefault();
  const n = id => +document.getElementById(id).value;
  const b = id => document.getElementById(id).checked;
  const msg = () => document.getElementById('alert-settings-msg');
  try {
    const data = await jsonApi('api/notifications.php?what=settings', 'PUT', {
      down_after_min: n('as-down'), agent_offline_after_min: n('as-agent'),
      disk_warn_pct: n('as-disk-warn'), disk_crit_pct: n('as-disk-crit'),
      remind_every_hours: n('as-remind'), alert_reboots: b('as-crash'),
      alert_failed_services: b('as-failed'), alert_antivirus: b('as-av'), alert_services: b('as-services'), alert_flexlm: b('as-flexlm'), notify_resolved: b('as-resolved'), alert_config_changes: b('as-config'),
      bw_mbps: n('as-bw'), bw_minutes: n('as-bw-min'), bw_daily_gb: n('as-bw-day'), bw_internal: b('as-bw-int'),
    });
    renderAlertSettings(data.settings);
    setMsg(msg(), 'Saved. Takes effect within a minute.');
  } catch (err) {
    setMsg(msg(), err.message, false);
  }
});

async function loadAlertHistory() {
  const el = document.getElementById('alert-history');
  try {
    const data = await jsonApi('api/alerts.php', 'GET');
    if (!data.recent.length) {
      el.innerHTML = '<div class="switch-info-status">No alerts yet.</div>';
      return;
    }
    el.innerHTML = `<div class="details-table-wrap"><table class="switch-info-table settings-table">
      <thead><tr><th>When</th><th>Alert</th><th>Status</th></tr></thead>
      <tbody>${data.recent.map(a => `
        <tr>
          <td class="nowrap muted">${escapeHtml(new Date(a.startedAt).toLocaleString())}</td>
          <td class="wrap"><span class="badge ${a.severity === 'critical' ? 'crit' : a.severity === 'warning' ? 'warn' : ''}">${escapeHtml(a.typeLabel)}</span>
            ${escapeHtml(a.message)}</td>
          <td class="nowrap">${a.type === 'crash' || a.type === 'reboot' ? '<span class="muted">event</span>'
            : a.resolvedAt ? `<span class="muted">resolved ${escapeHtml(fmtAgoDate(a.resolvedAt))}</span>` : '<strong>open</strong>'}</td>
        </tr>`).join('')}</tbody></table></div>`;
  } catch (err) {
    el.innerHTML = `<div class="switch-info-status details-error">${escapeHtml(err.message)}</div>`;
  }
}

function renderNotifyLog(log) {
  const el = document.getElementById('notify-log');
  if (!log.length) {
    el.innerHTML = '<div class="switch-info-status">Nothing sent yet.</div>';
    return;
  }
  el.innerHTML = `<div class="details-table-wrap"><table class="switch-info-table settings-table">
    <thead><tr><th>When</th><th>Destination</th><th>Result</th></tr></thead>
    <tbody>${log.map(l => `
      <tr>
        <td class="nowrap muted">${escapeHtml(new Date(l.ts).toLocaleString())}</td>
        <td>${escapeHtml(l.channel || '(deleted)')}</td>
        <td class="wrap ${l.ok ? '' : 'details-error'}">${escapeHtml(l.detail)}</td>
      </tr>`).join('')}</tbody></table></div>`;
}

async function loadNotifyLogOnly() {
  try {
    renderNotifyLog((await jsonApi('api/notifications.php', 'GET')).log);
  } catch (err) { /* the main view shows errors */ }
}

// ---- Subnet names ----

/** "e.g. 192.168.1.1 (core-switch)" when a monitored host sits on .1. */
function gatewayHint(subnet) {
  const dotOne = subnet.replace(/0\/24$/, '1');
  const h = [...hostsById.values()].find(x => x.ip === dotOne);
  return h ? `e.g. ${dotOne}${h.name !== h.ip ? ` (${h.name})` : ''}` : 'Gateway IP (optional)';
}

let subnetScans = {};
let extraSubnets = [];   // added with "Add subnet", not saved yet

const SCAN_EVERY_LABELS = { 15: '15 minutes', 30: '30 minutes', 60: 'hour', 180: '3 hours', 360: '6 hours', 720: '12 hours', 1440: 'day' };

async function loadSubnets() {
  renderSubnetForm();   // right away from what the page knows, then with scan state
  try {
    const data = await jsonApi('api/subnets.php', 'GET');
    applySubnetState(data);
  } catch (err) {
    setMsg(document.getElementById('subnet-msg'), err.message, false);
  }
}

function applySubnetState(data) {
  subnetNames = data.names || {};
  subnetGateways = data.gateways || {};
  subnetScans = data.scans || {};
  renderSubnetForm();
  renderDiscoveryLog(data.log || []);
  render();   // app.js: tabs and headings
}

function fmtAgoDate(ms) {
  const min = Math.round((Date.now() - ms) / 60000);
  if (min < 1) return 'just now';
  if (min < 60) return `${min} min ago`;
  if (min < 1440) return `${Math.round(min / 60)} h ago`;
  return new Date(ms).toLocaleString([], { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' });
}

function scanStatusText(sc) {
  const r = sc && sc.lastResult;
  if (!r) return sc && sc.enabled ? 'Not scanned yet — first scan within 5 minutes.' : '';
  const parts = [`Last scan ${fmtAgoDate(r.at)}: ${r.up} of ${r.scanned} addresses answered`];
  if (r.added && r.added.length) parts.push(`added ${r.added.length}`);
  if (r.removed && r.removed.length) parts.push(`removed ${r.removed.length}`);
  let html = escapeHtml(parts.join(' · '));
  if (r.new && r.new.length) {
    html += `<br><span class="subnet-new">${r.new.length} device${r.new.length === 1 ? '' : 's'} not monitored:
      ${escapeHtml(r.new.slice(0, 12).join(', '))}${r.new.length > 12 ? ', …' : ''}</span>`;
  }
  if (r.skipped) html += `<br><span class="subnet-warn">${escapeHtml(r.skipped)}</span>`;
  return html;
}

function renderSubnetForm() {
  const hostSubnets = [...hostsById.values()].map(h => subnetOf(h.ip));
  const subnets = [...new Set(hostSubnets.concat(Object.keys(subnetNames), Object.keys(subnetScans), extraSubnets))]
    .sort((a, b) => ipSortKey(a) - ipSortKey(b));
  const count = s => hostSubnets.filter(x => x === s).length;
  document.getElementById('subnet-rows').innerHTML = subnets.length ? subnets.map(s => {
    const e = escapeHtml(s);
    const sc = subnetScans[s] || { enabled: false, everyMin: 60, autoAdd: false, removeDays: null, removeScanOnly: true };
    const r = sc.lastResult;
    return `
    <div class="subnet-block" data-subnet="${e}">
      <div class="subnet-row">
        <span class="subnet-addr">${e} <span class="muted small">${count(s)} host${count(s) === 1 ? '' : 's'}</span></span>
        <label class="subnet-field"><span class="muted small">Name</span>
          <input type="text" maxlength="40" data-f="name" value="${escapeHtml(subnetNames[s] || '')}" placeholder="e.g. Servers" /></label>
        <label class="subnet-field"><span class="muted small">Gateway</span>
          <input type="text" maxlength="15" data-f="gateway" value="${escapeHtml(subnetGateways[s] || '')}"
            placeholder="${escapeHtml(gatewayHint(s))}" list="gw-list-${e}" /></label>
        <datalist id="gw-list-${e}">${[...hostsById.values()].filter(h => subnetOf(h.ip) === s)
          .sort((a, b) => ipSortKey(a.ip) - ipSortKey(b.ip))
          .map(h => `<option value="${escapeHtml(h.ip)}">${escapeHtml(h.name)}</option>`).join('')}</datalist>
      </div>
      <div class="subnet-scan">
        <label class="check"><input type="checkbox" data-f="enabled" ${sc.enabled ? 'checked' : ''} /> Scan every</label>
        <select data-f="everyMin" aria-label="Scan interval">${Object.entries(SCAN_EVERY_LABELS).map(([v, l]) =>
          `<option value="${v}" ${Number(v) === sc.everyMin ? 'selected' : ''}>${l}</option>`).join('')}</select>
        <label class="check"><input type="checkbox" data-f="autoAdd" ${sc.autoAdd ? 'checked' : ''} /> Add new devices</label>
        <label class="check">Remove cards not seen for
          <input type="number" min="1" max="365" data-f="removeDays" value="${sc.removeDays ?? ''}" placeholder="never" class="days-input" /> days</label>
        <label class="check"><input type="checkbox" data-f="removeScanOnly" ${sc.removeScanOnly !== false ? 'checked' : ''} />
          only cards the scanner added</label>
        <label class="check" title="Cards known only by their address get a name and type from what the device is (e.g. 'Blink camera .12'); cards you rename are left alone">
          <input type="checkbox" data-f="autoName" ${sc.autoName ? 'checked' : ''} /> Name and type cards from what they are</label>
        <button type="button" class="section-btn" data-scan-now>Scan now</button>
        ${r && r.new && r.new.length ? '<button type="button" class="section-btn" data-review>Review new devices</button>' : ''}
      </div>
      <div class="subnet-status muted small">${scanStatusText(sc)}</div>
    </div>`;
  }).join('') : '<p class="muted">No hosts yet. Add a subnet below to scan it.</p>';
  document.getElementById('subnet-msg').textContent = '';
  document.querySelectorAll('#subnet-rows [data-scan-now]').forEach(b => b.addEventListener('click', () => scanSubnetNow(b)));
  document.querySelectorAll('#subnet-rows [data-review]').forEach(b => b.addEventListener('click', () => {
    reviewSubnet(b.closest('.subnet-block').dataset.subnet);
  }));
}

function renderDiscoveryLog(log) {
  const box = document.getElementById('discovery-log');
  if (!log.length) {
    box.innerHTML = '<p class="muted small">No cards added or removed by scanning yet.</p>';
    return;
  }
  const icon = { added: '＋', removed: '－', skipped: '⚠' };
  box.innerHTML = log.map(l => `
    <div class="discovery-row ${escapeHtml(l.action)}">
      <span class="muted small">${escapeHtml(fmtAgoDate(l.ts))}</span>
      <span>${icon[l.action] || '•'} ${l.action === 'skipped' ? escapeHtml(l.subnet) + ': ' + escapeHtml(l.detail || '')
        : `${escapeHtml(l.action === 'added' ? 'Added' : 'Removed')} <strong>${escapeHtml(l.name)}</strong> ${l.name !== l.ip ? escapeHtml(l.ip) : ''}
           <span class="muted small">${escapeHtml(l.detail || '')}</span>`}</span>
    </div>`).join('');
}

/** Collects the form into the PUT body. */
function subnetFormBody() {
  const names = {}, gateways = {}, scans = {};
  document.querySelectorAll('#subnet-rows .subnet-block').forEach(b => {
    const s = b.dataset.subnet;
    const f = k => b.querySelector(`[data-f="${k}"]`);
    names[s] = f('name').value.trim();
    gateways[s] = f('gateway').value.trim();
    const days = f('removeDays').value.trim();
    const sc = {
      enabled: f('enabled').checked, everyMin: Number(f('everyMin').value), autoAdd: f('autoAdd').checked,
      removeDays: days === '' ? null : Number(days), removeScanOnly: f('removeScanOnly').checked, autoName: f('autoName').checked,
    };
    // Only keep rows that do something (or were saved before).
    if (sc.enabled || sc.removeDays !== null || sc.autoName || subnetScans[s]) scans[s] = sc;
  });
  return { names, gateways, scans };
}

async function saveSubnets() {
  const data = await jsonApi('api/subnets.php', 'PUT', subnetFormBody());
  extraSubnets = [];
  applySubnetState(data);
}

document.getElementById('subnet-form').addEventListener('submit', async e => {
  e.preventDefault();
  const msg = document.getElementById('subnet-msg');
  try {
    await saveSubnets();
    setMsg(msg, 'Saved');
  } catch (err) {
    setMsg(msg, err.message, false);
  }
});

async function scanSubnetNow(btn) {
  const subnet = btn.closest('.subnet-block').dataset.subnet;
  const msg = document.getElementById('subnet-msg');
  btn.disabled = true;
  btn.textContent = 'Scanning…';
  try {
    await saveSubnets();   // scan with the settings on screen
    const data = await jsonApi(`api/subnets.php?scan=${encodeURIComponent(subnet)}`, 'POST', {});
    applySubnetState(data);
    const r = data.result || {};
    setMsg(msg, `${subnet}: ${r.up ?? 0} answered${r.added && r.added.length ? `, added ${r.added.length}` : ''}${r.removed && r.removed.length ? `, removed ${r.removed.length}` : ''}`);
    loadHosts().catch(() => {});   // app.js
  } catch (err) {
    setMsg(msg, err.message, false);
    btn.disabled = false;
    btn.textContent = 'Scan now';
  }
}

/** Opens Add hosts with a scan of the subnet, to pick which new devices to add. */
function reviewSubnet(subnet) {
  showSettingsPanel('hosts');
  document.getElementById('scan-networks').value = subnet;
  document.getElementById('scan-form').requestSubmit();
}

document.getElementById('subnet-add-btn').addEventListener('click', () => {
  const input = document.getElementById('subnet-add-input');
  const m = input.value.trim().match(/^(\d{1,3})\.(\d{1,3})\.(\d{1,3})\.\d{1,3}(\/24)?$/);
  if (!m || [m[1], m[2], m[3]].some(n => Number(n) > 255)) {
    setMsg(document.getElementById('subnet-msg'), 'Enter a /24 subnet, e.g. 192.168.1.0/24', false);
    return;
  }
  const subnet = `${m[1]}.${m[2]}.${m[3]}.0/24`;
  if (!extraSubnets.includes(subnet)) extraSubnets.push(subnet);
  input.value = '';
  const body = subnetFormBody();   // keep unsaved edits
  renderSubnetForm();
  document.querySelectorAll('#subnet-rows .subnet-block').forEach(b => {
    const s = b.dataset.subnet;
    b.querySelector('[data-f="name"]').value = body.names[s] ?? '';
    b.querySelector('[data-f="gateway"]').value = body.gateways[s] ?? '';
    const sc = body.scans[s];
    if (!sc) return;
    b.querySelector('[data-f="enabled"]').checked = sc.enabled;
    b.querySelector('[data-f="everyMin"]').value = String(sc.everyMin);
    b.querySelector('[data-f="autoAdd"]').checked = sc.autoAdd;
    b.querySelector('[data-f="removeDays"]').value = sc.removeDays ?? '';
    b.querySelector('[data-f="removeScanOnly"]').checked = sc.removeScanOnly;
  });
  const added = document.querySelector(`#subnet-rows .subnet-block[data-subnet="${subnet}"] [data-f="enabled"]`);
  if (added) { added.checked = true; added.focus(); }
  setMsg(document.getElementById('subnet-msg'), `Added ${subnet} — click Save to keep it.`);
});

// ---- Locations ----

function applyLocations(list) {
  locationNames = Object.fromEntries(list.map(l => [l.id, l.name]));   // app.js: cards and the editor
  const box = document.getElementById('location-list');
  box.innerHTML = list.length ? `<table class="switch-info-table loc-table"><tbody>${list.map(l => `
      <tr data-id="${l.id}">
        <td><input type="text" maxlength="60" value="${escapeHtml(l.name)}" aria-label="Location name" /></td>
        <td class="muted small">${l.hosts} card${l.hosts === 1 ? '' : 's'}</td>
        <td class="loc-actions"><button type="button" class="section-btn" data-act="save">Rename</button>
          <button type="button" class="section-btn danger" data-act="del">Remove</button></td>
      </tr>`).join('')}</tbody></table>`
    : '<p class="muted">No locations yet.</p>';
  box.querySelectorAll('tr[data-id]').forEach(tr => {
    const id = tr.dataset.id;
    tr.querySelector('[data-act="save"]').addEventListener('click', () => locationRequest('PATCH', id, { name: tr.querySelector('input').value }));
    tr.querySelector('[data-act="del"]').addEventListener('click', () => {
      const n = tr.querySelector('input').value;
      if (confirm(`Remove the location "${n}"? Its cards keep no location.`)) locationRequest('DELETE', id);
    });
  });
  render();   // app.js
}

async function loadLocations() {
  try { applyLocations((await jsonApi('api/locations.php', 'GET')).locations); }
  catch (err) { setMsg(document.getElementById('location-msg'), err.message, false); }
}

async function locationRequest(method, id, body) {
  const msg = document.getElementById('location-msg');
  try {
    const data = await jsonApi(`api/locations.php${id ? `?id=${id}` : ''}`, method, body);
    applyLocations(data.locations);
    setMsg(msg, method === 'POST' ? 'Added' : method === 'DELETE' ? 'Removed' : 'Renamed');
    loadHosts().catch(() => {});
  } catch (err) {
    setMsg(msg, err.message, false);
  }
}

document.getElementById('location-form').addEventListener('submit', e => {
  e.preventDefault();
  const input = document.getElementById('location-name');
  if (!input.value.trim()) return;
  locationRequest('POST', null, { name: input.value }).then(() => { input.value = ''; });
});

// ---- Groups and screens ----

function applyGroups({ groups, screens: list }) {
  groupNames = Object.fromEntries(groups.map(g => [g.id, g.name]));   // app.js: cards, the editor, screens
  screens = list;
  const box = document.getElementById('group-list');
  box.innerHTML = groups.length ? `<table class="switch-info-table loc-table"><tbody>${groups.map(g => `
      <tr data-id="${g.id}">
        <td><input type="text" maxlength="60" value="${escapeHtml(g.name)}" aria-label="Group name" /></td>
        <td class="muted small">${g.hosts} card${g.hosts === 1 ? '' : 's'}</td>
        <td class="loc-actions"><button type="button" class="section-btn" data-act="save">Rename</button>
          <button type="button" class="section-btn danger" data-act="del">Remove</button></td>
      </tr>`).join('')}</tbody></table>`
    : '<p class="muted">No groups yet.</p>';
  box.querySelectorAll('tr[data-id]').forEach(tr => {
    const id = tr.dataset.id;
    tr.querySelector('[data-act="save"]').addEventListener('click', () => groupRequest('group-msg', 'PATCH', `id=${id}`, { name: tr.querySelector('input').value }));
    tr.querySelector('[data-act="del"]').addEventListener('click', () => {
      const n = tr.querySelector('input').value;
      if (confirm(`Remove the group "${n}"? It's taken off its cards, screens and update schedules (a schedule left with no groups is turned off).`)) groupRequest('group-msg', 'DELETE', `id=${id}`);
    });
  });

  const sbox = document.getElementById('screen-list');
  sbox.innerHTML = list.length ? list.map(sc => `
      <div class="screen-block" data-id="${sc.id}">
        <div class="screen-block-head">
          <input type="text" maxlength="60" value="${escapeHtml(sc.name)}" aria-label="Screen name" />
          <a class="muted small" href="?screen=${sc.id}" title="Link that opens straight to this screen">?screen=${sc.id}</a>
          <button type="button" class="section-btn" data-act="save">Save</button>
          <button type="button" class="section-btn danger" data-act="del">Remove</button>
        </div>
        <div class="screen-groups">${groups.length ? [...sc.groupIds.filter(id => groupNames[id]), ...groups.map(g => g.id).filter(id => !sc.groupIds.includes(id))]
          .map(id => `<label><input type="checkbox" value="${id}" ${sc.groupIds.includes(id) ? 'checked' : ''} /> ${escapeHtml(groupNames[id])}</label>`).join('')
          : '<span class="muted small">Add some groups above first.</span>'}</div>
      </div>`).join('')
    : '<p class="muted">No screens yet.</p>';
  sbox.querySelectorAll('.screen-block').forEach(b => {
    const id = b.dataset.id;
    const picked = () => [...b.querySelectorAll('.screen-groups input:checked')].map(i => Number(i.value));
    b.querySelector('[data-act="save"]').addEventListener('click', () =>
      groupRequest('screen-msg', 'PATCH', `type=screen&id=${id}`, { name: b.querySelector('input[type=text]').value, groupIds: picked() }));
    b.querySelector('[data-act="del"]').addEventListener('click', () => {
      if (confirm(`Remove the screen "${b.querySelector('input[type=text]').value}"? The groups and cards stay.`)) groupRequest('screen-msg', 'DELETE', `type=screen&id=${id}`);
    });
  });
  render();   // app.js
}

async function loadGroups() {
  try { applyGroups(await jsonApi('api/groups.php', 'GET')); }
  catch (err) { setMsg(document.getElementById('group-msg'), err.message, false); }
}

async function groupRequest(msgId, method, query, body) {
  const msg = document.getElementById(msgId);
  try {
    applyGroups(await jsonApi(`api/groups.php${query ? `?${query}` : ''}`, method, body));
    setMsg(document.getElementById(msgId), method === 'POST' ? 'Added' : method === 'DELETE' ? 'Removed' : 'Saved');
    loadHosts().catch(() => {});
  } catch (err) {
    setMsg(msg, err.message, false);
  }
}

document.getElementById('group-form').addEventListener('submit', e => {
  e.preventDefault();
  const input = document.getElementById('group-name');
  if (!input.value.trim()) return;
  groupRequest('group-msg', 'POST', '', { name: input.value }).then(() => { input.value = ''; });
});

document.getElementById('screen-form').addEventListener('submit', e => {
  e.preventDefault();
  const input = document.getElementById('screen-name');
  if (!input.value.trim()) return;
  groupRequest('screen-msg', 'POST', 'type=screen', { name: input.value, groupIds: [] }).then(() => { input.value = ''; });
});

// ---- Home Assistant ----

function applyHa(s) {
  document.getElementById('ha-url').value = s.url || '';
  const token = document.getElementById('ha-token');
  token.value = '';
  token.placeholder = s.hasToken ? 'Saved — paste a new token to replace it' : 'Paste the long-lived access token';
  document.getElementById('ha-sync').disabled = !s.hasToken;
  document.getElementById('ha-unlink').disabled = !s.hasToken;
  const parts = [];
  if (s.syncedAt) parts.push(`Last sync ${fmtAgoDate(s.syncedAt)}`);
  if (s.hasToken && s.syncedAt && !s.error) parts.push(`${s.devices} devices in Home Assistant${s.matched != null ? `, ${s.matched} matched to the device list` : ''}`);
  document.getElementById('ha-status').innerHTML = escapeHtml(parts.join(' · '))
    + (s.error ? `<p class="details-error">${escapeHtml(s.error)}</p>` : '');
}

async function loadHomeAssistant() {
  try { applyHa(await jsonApi('api/homeassistant.php', 'GET')); }
  catch (err) { setMsg(document.getElementById('ha-msg'), err.message, false); }
}

async function haRequest(method, url, body, done) {
  const msg = document.getElementById('ha-msg');
  setMsg(msg, 'Connecting to Home Assistant…');
  try {
    const s = await jsonApi(url, method, body);
    applyHa(s);
    setMsg(msg, s.error ? 'Saved, but the sync failed' : done, !s.error);
  } catch (err) {
    setMsg(msg, err.message, false);
  }
}

document.getElementById('ha-form').addEventListener('submit', e => {
  e.preventDefault();
  const body = { url: document.getElementById('ha-url').value };
  const token = document.getElementById('ha-token').value.trim();
  if (token) body.token = token;
  haRequest('PUT', 'api/homeassistant.php', body, 'Saved and synced');
});
document.getElementById('ha-sync').addEventListener('click', () => haRequest('POST', 'api/homeassistant.php?sync=1', undefined, 'Synced'));
document.getElementById('ha-unlink').addEventListener('click', () => {
  if (confirm('Remove the Home Assistant link? The saved token and device list are deleted.')) {
    haRequest('PUT', 'api/homeassistant.php', { token: '' }, 'Link removed');
  }
});
