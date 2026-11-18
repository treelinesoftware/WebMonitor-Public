// Focused UI checks without a browser or live server.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');

const elements = {
  'scan-status': { textContent: '' },
  'scan-queue-body': { innerHTML: '', querySelectorAll: () => [] },
  'scan-queue-summary': { textContent: '' },
  'scans-view': { classList: { contains: () => false } },
};
const calls = [];
const context = vm.createContext({
  document: { getElementById: id => elements[id] },
  IS_ADMIN: true,
  escapeHtml: s => String(s).replaceAll('&', '&amp;').replaceAll('<', '&lt;').replaceAll('>', '&gt;'),
  devQuery: d => new URLSearchParams({ ip: d.ip }).toString(),
  api: async (url, opts) => { calls.push({ url, opts }); return { ok: true, json: async () => ({ ok: true, active: [], requested: 0, devices: [], dueDevices: [], subnets: [] }) }; },
  setTimeout: () => 1,
  clearTimeout: () => {},
  console,
  alert: message => { throw new Error(message); },
});
const source = fs.readFileSync(path.join(__dirname, '../assets/scans.js'), 'utf8');
vm.runInContext(source.slice(0, source.indexOf("document.getElementById('scan-queue-refresh')")), context);

context.fixture = {
  active: [{ kind: 'device', target: '192.0.2.1', startedAt: 1 }],
  devices: [{ ip: '192.0.2.1', requestedAt: 1 }, { ip: '192.0.2.2', requestedAt: 1, priority: true }],
  dueDevices: [{ ip: '192.0.2.3', name: '<device>', scannedAt: null }],
  subnets: [{ subnet: '192.0.2.0/24', name: null, requestedAt: null, dueAt: 0 }],
};
vm.runInContext('renderScanQueue(fixture)', context);
assert.match(elements['scan-queue-body'].innerHTML, /&lt;device&gt;/);
assert.match(elements['scan-queue-body'].innerHTML, /Prioritized/);
assert.match(elements['scan-queue-summary'].textContent, /1 running · 1 requested assets · 1 due assets/);

(async () => {
  context.button = { disabled: false, textContent: '' };
  await vm.runInContext("requestAssetScan({ip: '192.0.2.3'}, button)", context);
  assert.equal(calls[0].url, 'api/device-info.php?ip=192.0.2.3&scan=1');
  assert.equal(calls[0].opts.method, 'POST');
  assert.equal(context.button.textContent, 'Requested');
  console.log('Scan status UI checks passed.');
})().catch(err => { console.error(err); process.exitCode = 1; });
