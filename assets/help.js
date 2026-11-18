// Help tab: shows the guides in docs/ (the same Markdown files that read as the
// project's documentation on GitHub), with a topic list and search.
// Opened from the ? button in the app bar, or with #help / #help=<topic>.

const HELP_TOPICS = [
  { id: 'README', title: 'Overview', group: 'Start' },
  { id: 'getting-started', title: 'Getting started', group: 'Start' },
  { id: 'requirements', title: 'System requirements', group: 'Install' },
  { id: 'install-rocky', title: 'Install on Rocky Linux', group: 'Install' },
  { id: 'install-docker', title: 'Install with Docker', group: 'Install' },
  { id: 'local-testing', title: 'Local test copy', group: 'Install' },
  { id: 'switches-and-snmp', title: 'Switches and SNMP', group: 'Use' },
  { id: 'netflow', title: 'NetFlow', group: 'Use' },
  { id: 'network-tab', title: 'Network tab', group: 'Use' },
  { id: 'alerts-and-services', title: 'Alerts and services', group: 'Use' },
  { id: 'agents-and-updates', title: 'Agents and updates', group: 'Use' },
  { id: 'devices-and-security', title: 'Devices and security', group: 'Use' },
  { id: 'troubleshooting', title: 'Troubleshooting', group: 'Help' },
];
const HELP_VERSION = 1;   // bump with docs changes so browsers fetch them again
const helpCache = {};
let helpTopic = 'README';
let helpReady = false;

const helpEsc = s => s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
const helpSlug = s => s.toLowerCase().replace(/<[^>]+>/g, '').replace(/&[a-z]+;/g, '').replace(/[^\w\s-]/g, '').trim().replace(/\s+/g, '-');

async function helpFetch(id) {
  if (!(id in helpCache)) {
    helpCache[id] = fetch(`docs/${id}.md?v=${HELP_VERSION}`, { credentials: 'same-origin' })
      .then(r => { if (!r.ok) throw new Error(`HTTP ${r.status}`); return r.text(); });
    helpCache[id].catch(() => delete helpCache[id]);
  }
  return helpCache[id];
}

/** Inline Markdown: code, links, bold, italics. Input is raw text; output is safe HTML. */
function helpInline(text) {
  const codes = [];
  let s = text.replace(/`([^`]+)`/g, (_, c) => { codes.push(c); return `\u0000${codes.length - 1}\u0000`; });
  s = helpEsc(s);
  s = s.replace(/\[([^\]]+)\]\(([^)\s]+)\)/g, (_, label, href) => {
    const h = href.replace(/&amp;/g, '&');
    const doc = h.match(/^(?:\.\/)?([\w-]+)\.md(?:#([\w-]+))?$/);
    if (doc && HELP_TOPICS.some(t => t.id === doc[1])) {
      return `<a href="#help=${doc[1]}${doc[2] ? '/' + doc[2] : ''}" data-help-topic="${doc[1]}" data-help-anchor="${doc[2] || ''}">${label}</a>`;
    }
    if (h.startsWith('#')) return `<a href="#help=${helpTopic}/${helpEsc(h.slice(1))}" data-help-topic="${helpTopic}" data-help-anchor="${helpEsc(h.slice(1))}">${label}</a>`;
    if (/^https?:\/\//.test(h)) return `<a href="${helpEsc(h)}" target="_blank" rel="noopener noreferrer">${label}</a>`;
    return label;   // repository files (../deploy/...) aren't served: plain text
  });
  s = s.replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>').replace(/(^|[^\w*])\*([^*\s][^*]*)\*/g, '$1<em>$2</em>');
  return s.replace(/\u0000(\d+)\u0000/g, (_, i) => `<code>${helpEsc(codes[+i])}</code>`);
}

/** The Markdown used in docs/: headings, paragraphs, lists, tables, code blocks, quotes, rules. */
function helpRender(md) {
  const lines = md.replace(/\r/g, '').split('\n');
  const out = [];
  let i = 0;
  const isBlockStart = l => /^(#{1,4} |```|> |\s*[-*] |\s*\d+\. |\|)/.test(l) || /^---+$/.test(l.trim());
  while (i < lines.length) {
    const line = lines[i];
    if (!line.trim()) { i++; continue; }
    let m;
    if ((m = line.match(/^(#{1,4}) (.*)$/))) {
      const level = Math.min(4, m[1].length + 1);   // the page's own title is h2 in the app
      const html = helpInline(m[2]);
      out.push(`<h${level} id="help-${helpSlug(m[2])}">${html}</h${level}>`);
      i++;
    } else if (line.startsWith('```')) {
      const body = [];
      for (i++; i < lines.length && !lines[i].startsWith('```'); i++) body.push(lines[i]);
      i++;
      out.push(`<div class="help-code"><button type="button" class="help-copy" title="Copy">Copy</button><pre><code>${helpEsc(body.join('\n'))}</code></pre></div>`);
    } else if (/^---+$/.test(line.trim())) {
      out.push('<hr>');
      i++;
    } else if (line.startsWith('|')) {
      const rows = [];
      for (; i < lines.length && lines[i].startsWith('|'); i++) rows.push(lines[i]);
      const cells = r => r.trim().replace(/^\||\|$/g, '').split(/(?<!\\)\|/).map(c => c.trim().replace(/\\\|/g, '|'));
      const head = cells(rows[0]);
      const body = rows.slice(rows[1] && /^\|[\s:|-]+\|$/.test(rows[1].trim()) ? 2 : 1);
      out.push(`<div class="help-table"><table><thead><tr>${head.map(c => `<th>${helpInline(c)}</th>`).join('')}</tr></thead><tbody>`
        + body.map(r => `<tr>${cells(r).map(c => `<td>${helpInline(c)}</td>`).join('')}</tr>`).join('') + '</tbody></table></div>');
    } else if (line.startsWith('> ')) {
      const body = [];
      for (; i < lines.length && lines[i].startsWith('>'); i++) body.push(lines[i].replace(/^> ?/, ''));
      out.push(`<blockquote>${helpInline(body.join(' '))}</blockquote>`);
    } else if ((m = line.match(/^(\s*)([-*]|\d+\.) /))) {
      const ordered = /\d/.test(m[2]);
      const items = [];
      for (; i < lines.length; i++) {
        const l = lines[i];
        const im = l.match(/^\s*([-*]|\d+\.) (.*)$/);
        if (im) items.push(im[2]);
        else if (l.trim() && /^\s{2,}/.test(l) && items.length) items[items.length - 1] += ' ' + l.trim();
        else break;
      }
      out.push(`<${ordered ? 'ol' : 'ul'}>${items.map(t => `<li>${helpInline(t)}</li>`).join('')}</${ordered ? 'ol' : 'ul'}>`);
    } else {
      const body = [];
      for (; i < lines.length && lines[i].trim() && !isBlockStart(lines[i]); i++) body.push(lines[i].trim());
      out.push(`<p>${helpInline(body.join(' '))}</p>`);
    }
  }
  return out.join('\n');
}

function helpNav() {
  const groups = [...new Set(HELP_TOPICS.map(t => t.group))];
  document.getElementById('help-nav').innerHTML = groups.map(g => `<div class="help-nav-group">${helpEsc(g)}</div>`
    + HELP_TOPICS.filter(t => t.group === g).map(t =>
      `<a href="#help=${t.id}" class="help-nav-link${t.id === helpTopic ? ' active' : ''}" data-help-topic="${t.id}">${helpEsc(t.title)}</a>`).join('')).join('');
}

async function openHelpTopic(id, anchor = '') {
  if (!HELP_TOPICS.some(t => t.id === id)) id = 'README';
  helpTopic = id;
  helpNav();
  const box = document.getElementById('help-content');
  document.getElementById('help-search').value = '';
  try {
    box.innerHTML = helpRender(await helpFetch(id));
  } catch (err) {
    box.innerHTML = `<p class="muted">Couldn't load this page (${helpEsc(err.message)}).</p>`;
  }
  if (history.replaceState) history.replaceState(null, '', `#help=${id}${anchor ? '/' + anchor : ''}`);
  const target = anchor && document.getElementById('help-' + anchor);
  if (target) target.scrollIntoView({ block: 'start' });
  else window.scrollTo(0, 0);
}

/** Search every guide; shows matching sections with a snippet. */
async function helpSearch(q) {
  const box = document.getElementById('help-content');
  q = q.trim().toLowerCase();
  if (q.length < 2) { openHelpTopic(helpTopic); return; }
  const words = q.split(/\s+/);
  const hits = [];
  await Promise.all(HELP_TOPICS.map(async t => {
    let md;
    try { md = await helpFetch(t.id); } catch { return; }
    let heading = t.title, anchor = '';
    for (const section of md.split(/\n(?=#{1,4} )/)) {
      const h = section.match(/^#{1,4} (.*)/);
      if (h) { heading = h[1].replace(/[`*]/g, ''); anchor = h[0].startsWith('# ') ? '' : helpSlug(h[1]); }
      const text = section.replace(/^#{1,4} .*\n?/, '').replace(/[`*|>#]/g, ' ').replace(/\[([^\]]+)\]\([^)]+\)/g, '$1').replace(/\s+/g, ' ');
      const hay = (heading + ' ' + text).toLowerCase();
      if (!words.every(w => hay.includes(w))) continue;
      const at = Math.max(0, text.toLowerCase().indexOf(words[0]) - 60);
      hits.push({ t, heading, anchor, snippet: (at ? '…' : '') + text.slice(at, at + 180).trim() + '…',
        score: words.filter(w => heading.toLowerCase().includes(w)).length });
    }
  }));
  if (document.getElementById('help-search').value.trim().toLowerCase() !== q) return;   // typed on meanwhile
  hits.sort((a, b) => b.score - a.score || HELP_TOPICS.indexOf(a.t) - HELP_TOPICS.indexOf(b.t));
  const mark = s => { let h = helpEsc(s); for (const w of words) h = h.replace(new RegExp(w.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'), 'gi'), m => `<mark>${m}</mark>`); return h; };
  box.innerHTML = `<h2>Search: ${helpEsc(q)}</h2>` + (hits.length
    ? hits.slice(0, 30).map(h => `<a class="help-hit" href="#help=${h.t.id}${h.anchor ? '/' + h.anchor : ''}" data-help-topic="${h.t.id}" data-help-anchor="${h.anchor}">
        <span class="help-hit-title">${helpEsc(h.t.title)}${h.heading !== h.t.title ? ' › ' + helpEsc(h.heading) : ''}</span>
        <span class="help-hit-text">${mark(h.snippet)}</span></a>`).join('')
    : '<p class="muted">Nothing found. Try fewer or different words.</p>');
}

/** Called by showView() (updates.js). */
function helpViewShown(shown, topic = '') {
  if (!shown) return;
  if (!helpReady) {
    helpReady = true;
    const view = document.getElementById('help-view');
    view.addEventListener('click', e => {
      const copy = e.target.closest('.help-copy');
      if (copy) {
        const text = copy.parentElement.querySelector('code').textContent;
        navigator.clipboard?.writeText(text).then(() => { copy.textContent = 'Copied'; setTimeout(() => { copy.textContent = 'Copy'; }, 1500); });
        return;
      }
      const a = e.target.closest('a[data-help-topic]');
      if (a) { e.preventDefault(); openHelpTopic(a.dataset.helpTopic, a.dataset.helpAnchor || ''); }
    });
    let timer;
    document.getElementById('help-search').addEventListener('input', e => {
      clearTimeout(timer);
      timer = setTimeout(() => helpSearch(e.target.value), 200);
    });
  }
  const [id, anchor] = (topic || helpTopic).split('/');
  openHelpTopic(id, anchor || '');
}

document.getElementById('help-btn')?.addEventListener('click', () => showView('help'));
if (location.hash.startsWith('#help')) showView('help', decodeURIComponent(location.hash.replace(/^#help=?/, '')));
