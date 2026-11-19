<?php
require_once __DIR__ . '/includes/auth.php';
$user = require_login_page();
?><!DOCTYPE html>
<html lang="en" data-theme="<?= htmlspecialchars($user['prefs']['theme'], ENT_QUOTES) ?>">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<meta name="user-role" content="<?= htmlspecialchars($user['role'], ENT_QUOTES) ?>" />
<meta name="user-grouping" content="<?= htmlspecialchars($user['prefs']['grouping'], ENT_QUOTES) ?>" />
<meta name="subnet-names" content="<?= htmlspecialchars(json_encode((object) subnet_names()), ENT_QUOTES) ?>" />
<meta name="subnet-gateways" content="<?= htmlspecialchars(json_encode((object) subnet_gateways()), ENT_QUOTES) ?>" />
<meta name="app-version" content="<?= htmlspecialchars(app_version(), ENT_QUOTES) ?>" />
<meta name="locations" content="<?= htmlspecialchars(json_encode(db()->query('SELECT id, name FROM locations ORDER BY sort, name')->fetchAll(PDO::FETCH_KEY_PAIR) ?: new stdClass()), ENT_QUOTES) ?>" />
<meta name="card-groups" content="<?= htmlspecialchars(json_encode(db()->query('SELECT id, name FROM card_groups ORDER BY sort, name')->fetchAll(PDO::FETCH_KEY_PAIR) ?: new stdClass()), ENT_QUOTES) ?>" />
<meta name="screens" content="<?= htmlspecialchars(json_encode(screens_list()), ENT_QUOTES) ?>" />
<meta name="csrf-token" content="<?= htmlspecialchars(csrf_token(), ENT_QUOTES) ?>" />
<title>WebMonitor</title>
<link rel="icon" href="favicon.ico" sizes="48x48" />
<link rel="icon" href="assets/favicon.svg" type="image/svg+xml" />
<link rel="apple-touch-icon" href="assets/apple-touch-icon.png" />
<link rel="stylesheet" href="assets/style.css?v=59" />
<script src="https://cdn.jsdelivr.net/npm/chart.js@4"></script>
</head>
<body class="role-<?= $user['role'] === 'admin' ? 'admin' : 'viewer' ?> cols-<?= htmlspecialchars($user['prefs']['columns'], ENT_QUOTES) ?>">
<header class="app-bar">
  <div class="app-bar-inner">
    <a class="brand" href="#" title="WebMonitor">
      <svg class="brand-mark" viewBox="0 0 64 64" aria-hidden="true"><rect x="2" y="2" width="60" height="60" rx="14"/>
        <polyline points="9,35 21,35 26,22 34,46 40,28 44,35 55,35"/></svg>
      <span class="brand-name">WebMonitor</span>
    </a>
    <nav class="app-nav" id="app-nav" aria-label="Sections">
      <button class="view-btn nav-btn active" data-view="hosts" data-subnet="" type="button">Hosts</button>
      <button class="view-btn nav-btn" data-view="services" type="button">Services <span class="tab-count" id="count-services"></span></button>
      <button class="view-btn nav-btn" data-view="network" type="button">Network</button>
      <button class="view-btn nav-btn" data-view="updates" type="button">Updates <span class="tab-count" id="count-updates"></span></button>
      <button class="view-btn nav-btn" data-view="reports" type="button">Reports</button>
      <button class="view-btn nav-btn" data-view="scans" type="button">Scans</button>
      <button class="view-btn nav-btn" data-view="security" type="button">Security <span class="tab-count" id="count-security"></span></button>
      <button class="view-btn nav-btn" data-view="flexlm" type="button">Licences <span class="tab-count" id="count-flexlm"></span></button>
    </nav>
    <div class="app-bar-right">
      <button type="button" class="scan-pill no-print" id="scan-pill" title="Open the scan queue">Scans · checking…</button>
      <time id="header-clock" class="header-clock" title="Your local time"></time>
      <div class="user-bar">
        <span class="user-avatar" aria-hidden="true"><?= htmlspecialchars(strtoupper(substr($user['username'], 0, 1)), ENT_QUOTES) ?></span>
        <span class="user-meta"><span class="user-name"><?= htmlspecialchars($user['username'], ENT_QUOTES) ?></span>
          <span class="role-badge"><?= $user['role'] === 'admin' ? 'Admin' : 'Viewer' ?></span></span>
        <button id="help-btn" class="icon-btn" type="button" title="Help" aria-label="Help">
          <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9.5"/><path d="M9.3 9.2a2.8 2.8 0 0 1 5.4.9c0 1.9-2.7 2.5-2.7 4.1"/><path d="M12 17.4h.01"/></svg>
        </button>
        <button id="settings-btn" class="icon-btn" type="button" title="Settings" aria-label="Settings">
          <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1.1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.5-1.1 1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z"/></svg>
        </button>
        <button id="logout-btn" class="icon-btn" type="button" title="Sign out" aria-label="Sign out">
          <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="m16 17 5-5-5-5"/><path d="M21 12H9"/></svg>
        </button>
      </div>
    </div>
  </div>
</header>

<main>
  <section id="alerts-bar" class="alerts-bar hidden" aria-live="polite"></section>

  <section id="services-view" class="hidden">
    <div class="report-head">
      <div><h2 class="report-title">Services</h2><div class="muted small" id="svc-summary-line"></div></div>
      <div class="section-actions no-print">
        <button type="button" class="primary-btn admin-only" id="svc-add">Add check</button>
      </div>
    </div>
    <div class="svc-tiles" id="svc-tiles"></div>
    <div class="host-toolbar">
      <label class="search-box"><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
        <input type="search" id="svc-search" placeholder="Search service, host, address…" autocomplete="off" aria-label="Search services" /></label>
      <div class="segmented" role="group" aria-label="Show" id="svc-filter">
        <button class="tab-btn active" data-svc-filter="all" type="button">All</button>
        <button class="tab-btn" data-svc-filter="problem" type="button">Problems</button>
        <button class="tab-btn" data-svc-filter="http" type="button">Web</button>
        <button class="tab-btn" data-svc-filter="tcp" type="button">Ports</button>
        <button class="tab-btn" data-svc-filter="dns" type="button">DNS</button>
        <button class="tab-btn" data-svc-filter="agent" type="button">Agent</button>
      </div>
    </div>
    <div id="svc-body"></div>
    <details class="no-print sec-about"><summary>How service checks work</summary>
      <p class="hint"><strong>Web</strong> checks load a page from this server: up when it answers with the expected status
        (any 2xx or 3xx by default) and, if set, contains a keyword. HTTPS checks also track when the certificate expires.
        <strong>Port</strong> checks open a TCP connection. <strong>DNS</strong> checks look a name up, on a server you choose or this
        server's resolver. A network check shows down after two failures in a row, so a single dropped packet doesn't alert.</p>
      <p class="hint"><strong>Agent</strong> services are systemd units (Linux) or Windows services that must be running. The agent
        reports them every minute; a stopped service is down straight away, even if someone stopped it on purpose (pause the
        check while you work on it). Restart asks the agent to restart it and is logged in Updates → Job history. Agents only
        restart services on their watched list. Needs agent 0.6 (Linux) or 0.5 (Windows); upgrade from the Updates tab.</p>
    </details>
  </section>

  <section id="network-view" class="hidden">
    <div class="report-head">
      <div><h2 class="report-title">Network</h2><div class="muted small" id="net-meta"></div></div>
      <div class="section-actions no-print">
        <label class="net-live-toggle" title="Read the switches' port counters every few seconds while this page is open">
          <input type="checkbox" id="net-live" checked /> <span class="net-live-dot"></span> Live</label>
        <button type="button" class="section-btn" id="net-refresh">Refresh map</button>
      </div>
    </div>

    <div class="net-speed panel-box" id="net-speed">
      <div class="net-speed-head">
        <h3>Internet speed</h3>
        <div class="section-actions">
          <div class="seg" role="group" aria-label="Range" id="net-speed-range">
            <button type="button" class="group-btn" data-days="1">24 h</button>
            <button type="button" class="group-btn active" data-days="7">7 d</button>
            <button type="button" class="group-btn" data-days="30">30 d</button>
            <button type="button" class="group-btn" data-days="90">90 d</button>
          </div>
          <button type="button" class="section-btn admin-only" id="net-speed-settings">Schedule…</button>
          <button type="button" class="primary-btn admin-only" id="net-speed-run">Run now</button>
        </div>
      </div>
      <div class="net-speed-body" id="net-speed-body"><div class="switch-info-status">Loading…</div></div>
    </div>

    <div class="net-map-wrap panel-box">
      <div class="net-speed-head">
        <h3>Network map</h3>
        <div class="section-actions">
          <label class="net-chk"><input type="checkbox" id="net-show-devices" /> Show devices</label>
          <button type="button" class="section-btn icon-only" id="net-zoom-out" title="Zoom out" aria-label="Zoom out">−</button>
          <button type="button" class="section-btn icon-only" id="net-zoom-in" title="Zoom in" aria-label="Zoom in">+</button>
          <button type="button" class="section-btn" id="net-zoom-fit">Fit</button>
        </div>
      </div>
      <div class="net-map" id="net-map"><div class="switch-info-status">Building the network map…</div></div>
      <div class="net-legend muted small"><span>Link load right now:</span>
        <span><span class="lg lg0"></span> idle</span> <span><span class="lg lg1"></span> under 10%</span> <span><span class="lg lg2"></span> under 50%</span>
        <span><span class="lg lg3"></span> under 80%</span> <span><span class="lg lg4"></span> busier</span>
        <span class="net-legend-tip">· Click a switch to see its ports, double-click to open its card. Drag to pan, scroll to zoom.</span></div>
    </div>

    <div class="net-switch panel-box hidden" id="net-switch"></div>
  </section>

  <section id="flexlm-view" class="hidden">
    <div class="report-head">
      <div><h2 class="report-title">Licences</h2><div class="muted small" id="flm-summary-line">FlexLM licence servers</div></div>
      <div class="section-actions no-print">
        <button type="button" class="primary-btn admin-only" id="flm-add">Add licence server</button>
      </div>
    </div>
    <div id="flm-notice"></div>
    <div class="svc-tiles" id="flm-tiles"></div>
    <div class="host-toolbar">
      <div class="segmented" role="group" aria-label="Show" id="flm-tabs">
        <button class="tab-btn active" data-flm-tab="servers" type="button">Servers</button>
        <button class="tab-btn" data-flm-tab="inuse" type="button">In use now <span class="tab-count" id="flm-count-inuse"></span></button>
        <button class="tab-btn" data-flm-tab="history" type="button">History</button>
      </div>
      <label class="search-box"><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
        <input type="search" id="flm-search" placeholder="Search server, feature, user, computer…" autocomplete="off" aria-label="Search licences" /></label>
    </div>
    <div id="flm-body"></div>
    <details class="no-print sec-about"><summary>How licence monitoring works</summary>
      <p class="hint">WebMonitor runs <code>lmutil lmstat -a</code> against each licence server on its schedule (every 5 minutes by default).
        It records whether the licence server and its vendor daemons are up, how many licences of each feature are in use, when
        they expire and who has them checked out. A server shows down after two failed checks in a row.</p>
      <p class="hint">lmutil comes from Flexera and isn't included with WebMonitor. If the page says it's missing, see
        <a href="#help=flexlm" data-help-link="flexlm">Help → FlexLM licences</a> for where to put it.</p>
    </details>
  </section>

  <section id="help-view" class="hidden">
    <div class="report-head">
      <div><h2 class="report-title">Help</h2><div class="muted small">Guides for installing, setting up and using WebMonitor</div></div>
      <div class="section-actions no-print">
        <input type="search" id="help-search" class="help-search" placeholder="Search help…" aria-label="Search help" />
      </div>
    </div>
    <div class="help-layout">
      <nav class="help-nav" id="help-nav" aria-label="Help topics"></nav>
      <article class="help-content" id="help-content"></article>
    </div>
  </section>

  <section id="security-view" class="hidden">
    <div class="report-head">
      <div><h2 class="report-title">Vulnerability report</h2><div class="muted small" id="sec-summary-line"></div></div>
      <div class="section-actions no-print">
        <select id="sec-scope" class="admin-only" aria-label="What to scan"></select>
        <button type="button" class="section-btn admin-only" id="sec-start">Start scan</button>
        <button type="button" class="section-btn" id="sec-print">Print / PDF</button>
      </div>
    </div>
    <div id="sec-progress"></div>
    <div id="sec-tiles" class="sec-tiles"></div>
    <div id="sec-body"></div>
    <details class="no-print sec-about"><summary>How scanning works</summary>
      <p class="hint">nmap checks each device's top 1,000 TCP ports and identifies the services and versions. Built-in checks then look for
        cleartext logins (Telnet, FTP), services open without a password (VNC, Redis, MongoDB, Docker), SMBv1, old TLS versions and weak
        ciphers, expired certificates, login pages over plain HTTP and SNMP readable with "public".</p>
      <p class="hint">Devices without an agent: known CVEs are looked up for each service version in the NVD database. These are
        <em>version-based</em> and may include issues the vendor fixed without changing the version. Hosts with an agent: pending
        security updates with the CVEs they fix (OSV database), antivirus state and pending reboots, like a logged-in scan.
        Only product names and versions are sent to NVD and OSV, never addresses.</p>
      <p class="hint">Severity follows CVSS: <strong>Critical</strong> 9.0–10, <strong>High</strong> 7.0–8.9, <strong>Medium</strong> 4.0–6.9,
        <strong>Low</strong> 0.1–3.9, <strong>Info</strong> 0 (worth knowing, not a vulnerability). A full scan of every device takes a while;
        it runs in the background and the page updates as it goes.</p>
    </details>
  </section>

  <section id="scans-view" class="hidden">
    <div class="report-head">
      <div><h2 class="report-title">Scan queue</h2><div class="muted small" id="scan-queue-summary"></div></div>
      <button type="button" class="section-btn" id="scan-queue-refresh">Refresh</button>
    </div>
    <p class="hint">Requested asset scans run in the background, normally within a minute. Subnet and daily asset scans run during the five-minute background poll. Scan now prioritizes a waiting item; it does not interrupt an active scan.</p>
    <div id="scan-queue-body"></div>
  </section>

  <section id="updates-view" class="hidden">
    <div class="updates-toolbar admin-only">
      <span class="muted" id="updates-selected">0 selected</span>
      <button type="button" class="section-btn" data-job="refresh">Check for updates</button>
      <button type="button" class="section-btn" data-job="updates" data-security="1">Install security updates</button>
      <button type="button" class="section-btn" data-job="updates">Install all updates</button>
      <button type="button" class="section-btn" data-job="reboot">Reboot</button>
      <button type="button" class="section-btn" data-job="agent_upgrade">Upgrade agent</button>
      <label class="check"><input type="checkbox" id="updates-reboot" /> Reboot afterwards if required</label>
    </div>
    <div id="updates-table"></div>
    <div class="schedule-summary" id="schedule-summary"></div>
    <div class="updates-h-row">
      <h3 class="updates-h">Change records <span class="tab-count" id="changes-pending"></span></h3>
      <div class="section-actions">
        <div class="segmented" role="group" aria-label="Show changes">
          <button type="button" class="group-btn active" data-changes="open">Open</button>
          <button type="button" class="group-btn" data-changes="all">All</button>
        </div>
        <button type="button" class="section-btn admin-only" id="changes-csv">Export CSV</button>
      </div>
    </div>
    <p class="hint" id="changes-hint"></p>
    <div id="changes-table"></div>
    <h3 class="updates-h">Job history</h3>
    <div id="jobs-table"></div>
  </section>

  <section id="reports-view" class="hidden">
    <div class="report-picker no-print" role="tablist" aria-label="Reports">
      <button type="button" class="group-btn active" data-report="topology">Network topology</button>
      <button type="button" class="group-btn" data-report="devices">Devices</button>
      <button type="button" class="group-btn" data-report="traffic">Traffic</button>
      <button type="button" class="group-btn admin-only" data-report="audit">Audit log</button>
    </div>
    <div class="report-panel hidden" data-report="audit">
      <div class="report-head">
        <div>
          <h2 class="report-title">Audit log</h2>
          <div class="muted small" id="audit-meta"></div>
        </div>
        <div class="section-actions no-print">
          <button type="button" class="section-btn" id="audit-verify">Verify integrity</button>
          <button type="button" class="section-btn" id="audit-csv">Export CSV</button>
        </div>
      </div>
      <p class="hint no-print">Every change made in WebMonitor — sign-ins, settings, hosts, users, update jobs, schedules and change
        records — with who, when and from where. Entries can't be edited from WebMonitor, and each one is chained to the one before
        by a hash, so <strong>Verify integrity</strong> shows whether any were changed or removed in the database.</p>
      <form class="audit-filters no-print" id="audit-filters">
        <input type="search" id="audit-q" placeholder="Search summary, target, details…" />
        <select id="audit-user"><option value="">Anyone</option><option value="(system)">WebMonitor (automatic)</option></select>
        <select id="audit-action">
          <option value="">All actions</option>
          <option value="change.">Change records</option>
          <option value="schedule.">Schedule runs</option>
          <option value="auth.">Sign-ins</option>
          <option value="api.">Changes made in the dashboard</option>
          <option value="audit.">Audit log checks / exports</option>
        </select>
        <select id="audit-outcome"><option value="">Any outcome</option><option value="ok">OK</option><option value="denied">Denied</option><option value="failed">Failed</option></select>
        <label>From <input type="date" id="audit-from" /></label>
        <label>To <input type="date" id="audit-to" /></label>
      </form>
      <div id="audit-body"></div>
    </div>
    <div class="report-panel" data-report="topology">
    <div class="report-head">
      <div>
        <h2 class="report-title">Network topology</h2>
        <div class="muted small" id="topo-meta"></div>
      </div>
      <div class="section-actions no-print">
        <button type="button" class="section-btn" id="topo-refresh">Refresh</button>
        <button type="button" class="section-btn" id="topo-csv">Export CSV</button>
        <button type="button" class="section-btn" id="topo-print">Print / PDF</button>
      </div>
    </div>
    <p class="hint no-print">Built from the switches' and routers' port maps (refreshed every 15 minutes): which network device
      connects to which, through which ports, and what's plugged in where. Proxmox guests are shown under their host.</p>
    <div id="topo-body"></div>
    </div>

    <div class="report-panel hidden" data-report="devices">
      <div class="report-head">
        <div>
          <h2 class="report-title">Devices</h2>
          <div class="muted small" id="dev-meta"></div>
        </div>
        <div class="section-actions no-print">
          <button type="button" class="section-btn" id="dev-refresh">Refresh</button>
          <button type="button" class="section-btn" id="dev-csv">Export CSV</button>
          <button type="button" class="section-btn" id="dev-print">Print / PDF</button>
        </div>
      </div>
      <p class="hint no-print">Everything seen on the switches and Wi-Fi, identified from its name, the maker of its network
        card (MAC address), what the Wi-Fi controller recognised, and its network. Confidence shows how sure the guess is.</p>
      <div id="dev-body"></div>
    </div>

    <div class="report-panel hidden" data-report="traffic">
      <div id="traffic-body"></div>
    </div>
  </section>

  <div id="hosts-view">
  <section id="kpis" class="kpis" aria-label="Summary"></section>

  <nav class="view-tabs" id="view-tabs" aria-label="Subnets">
    <button class="view-btn active" data-view="hosts" data-subnet="" type="button">All <span class="tab-count" id="count-view-all"></span></button>
  </nav>

  <div class="host-toolbar" id="host-tabs">
    <label class="search-box"><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
      <input type="search" id="host-search" placeholder="Search name, IP, maker, model…" autocomplete="off" aria-label="Search hosts" /></label>
    <div class="segmented" role="group" aria-label="Show">
      <button class="tab-btn active" data-tab="all" type="button">All <span class="tab-count" id="count-all">0</span></button>
      <button class="tab-btn" data-tab="up" type="button">Up <span class="tab-count" id="count-up">0</span></button>
      <button class="tab-btn" data-tab="down" type="button">Down <span class="tab-count" id="count-down">0</span></button>
    </div>
    <div class="group-switch segmented" id="group-switch" role="group" aria-label="Group hosts by">
      <span class="seg-label">Group</span>
      <button class="group-btn" data-group="subnet" type="button">Subnet</button>
      <button class="group-btn" data-group="host" type="button">Host</button>
      <button class="group-btn" data-group="location" type="button">Location</button>
      <button class="group-btn" data-group="group" type="button">Group</button>
    </div>
    <label class="screen-pick" id="screen-pick" title="Show only the groups picked for a screen (Settings → Groups)">
      <span class="seg-label">Screen</span>
      <select id="screen-select" aria-label="Screen"><option value="">All cards</option></select>
    </label>
  </div>

  <section id="grid"></section>
  </div>
</main>

<div id="settings-backdrop" class="modal-backdrop hidden">
  <div class="modal settings-modal" role="dialog" aria-labelledby="settings-title">
    <aside class="settings-side">
      <h2 class="settings-side-title">Settings</h2>
      <nav class="settings-nav" aria-label="Settings sections">
        <div class="settings-nav-group">
          <div class="settings-nav-label">You</div>
          <button type="button" class="settings-nav-item active" data-panel="account" data-desc="Your sign-in password.">Account</button>
          <button type="button" class="settings-nav-item" data-panel="display" data-desc="Theme and how many host cards fit on a row. Saved for your account.">Display</button>
        </div>
        <div class="settings-nav-group admin-only">
          <div class="settings-nav-label">Monitoring</div>
          <button type="button" class="settings-nav-item" data-panel="hosts" data-desc="Add hosts one at a time, or scan a network for them.">Add hosts</button>
          <button type="button" class="settings-nav-item" data-panel="subnets" data-desc="Names, gateways and automatic discovery for each network.">Subnets</button>
          <button type="button" class="settings-nav-item" data-panel="locations" data-desc="Rooms, buildings or racks to show on cards.">Locations</button>
          <button type="button" class="settings-nav-item" data-panel="groups" data-desc="Your own card groups, and screens that show only some of them.">Groups &amp; screens</button>
        </div>
        <div class="settings-nav-group">
          <div class="settings-nav-label">Updates</div>
          <button type="button" class="settings-nav-item" data-panel="schedules" data-desc="Recurring update windows for hosts with an agent.">Update schedules</button>
          <button type="button" class="settings-nav-item admin-only" data-panel="changes" data-desc="Change records, the change email list and optional approval.">Change management</button>
        </div>
        <div class="settings-nav-group admin-only">
          <div class="settings-nav-label">Alerts</div>
          <button type="button" class="settings-nav-item" data-panel="notifications" data-desc="Where alerts are sent, what triggers them, and what was sent.">Notifications</button>
        </div>
        <div class="settings-nav-group admin-only">
          <div class="settings-nav-label">Administration</div>
          <button type="button" class="settings-nav-item" data-panel="users" data-desc="Who can sign in, and whether they can change things or only look.">Users</button>
          <button type="button" class="settings-nav-item" data-panel="homeassistant" data-desc="Link WebMonitor to Home Assistant.">Home Assistant</button>
        </div>
      </nav>
    </aside>
    <div class="settings-main">
      <header class="settings-head">
        <div>
          <h3 class="settings-head-title" id="settings-title">Account</h3>
          <p class="settings-head-desc" id="settings-desc"></p>
        </div>
        <button id="settings-close" class="modal-close" type="button" aria-label="Close settings">&times;</button>
      </header>
      <div class="settings-body" id="settings-body">

    <section class="settings-panel" data-panel="account">
      <h3>Change your password</h3>
      <form id="account-form" class="settings-form">
        <label>Current password <input type="password" id="acct-current" autocomplete="current-password" required /></label>
        <label>New password <input type="password" id="acct-new" autocomplete="new-password" minlength="10" required /></label>
        <label>Repeat new password <input type="password" id="acct-new2" autocomplete="new-password" minlength="10" required /></label>
        <div class="form-row"><button type="submit" class="primary-btn">Change password</button><span class="form-msg" id="account-msg"></span></div>
      </form>
    </section>

    <section class="settings-panel hidden" data-panel="display">
      <form id="display-form" class="settings-form">
        <h3>Theme</h3>
        <div class="choice-group">
          <label><input type="radio" name="theme" value="system" /> System</label>
          <label><input type="radio" name="theme" value="dark" /> Dark</label>
          <label><input type="radio" name="theme" value="light" /> Light</label>
        </div>
        <p class="hint">“System” follows your computer or phone's light/dark setting.</p>
        <h3>Host cards per row</h3>
        <div class="choice-group">
          <label><input type="radio" name="columns" value="auto" /> Auto</label>
          <label><input type="radio" name="columns" value="2" /> 2</label>
          <label><input type="radio" name="columns" value="3" /> 3</label>
          <label><input type="radio" name="columns" value="4" /> 4</label>
        </div>
        <p class="hint">Saved to your account, so it follows you to other browsers. Small screens still show fewer columns.</p>
        <span class="form-msg" id="display-msg"></span>
      </form>
    </section>

    <section class="settings-panel hidden" data-panel="schedules">
      <h3>Update schedule <span class="muted small" id="schedules-tz"></span></h3>
      <div id="schedule-week" class="schedule-week"></div>
      <div id="schedule-conflicts"></div>
      <h3>Schedules</h3>
      <div id="schedules-list"></div>
      <details id="schedule-add" class="channel-add admin-only">
        <summary>Add a schedule…</summary>
        <form id="schedule-form" class="settings-form"></form>
      </details>
    </section>

    <section class="settings-panel admin-only hidden" data-panel="users">
      <div id="users-list"></div>
      <h3>Add a user</h3>
      <form id="user-form" class="settings-form inline">
        <input type="text" id="user-name" placeholder="Username" maxlength="60" required />
        <input type="password" id="user-pass" placeholder="Password (10+ characters)" minlength="10" autocomplete="new-password" required />
        <select id="user-role">
          <option value="viewer" selected>Viewer — can only view</option>
          <option value="admin">Admin — full access</option>
        </select>
        <button type="submit" class="primary-btn">Add user</button>
      </form>
      <span class="form-msg" id="users-msg"></span>
    </section>

    <section class="settings-panel admin-only hidden" data-panel="notifications">
      <h3>Send alerts to</h3>
      <div id="channels-list"></div>
      <details id="channel-add" class="channel-add">
        <summary>Add a destination…</summary>
        <form id="channel-form" class="settings-form"></form>
      </details>
      <h3>When to alert</h3>
      <form id="alert-settings-form" class="settings-form"></form>
      <h3>Recent alerts</h3>
      <div id="alert-history"></div>
      <h3>Delivery log</h3>
      <div id="notify-log"></div>
    </section>

    <section class="settings-panel admin-only hidden" data-panel="subnets">
      <h3>Subnets</h3>
      <p class="hint">Give each subnet a name. It's shown with the address, e.g. <strong>Servers</strong> · 192.168.1.0/24,
        on the tabs and group headings. Leave a name blank to show just the address.</p>
      <p class="hint">Set a subnet's <strong>gateway</strong> and it moves from the host cards into the subnet's heading,
        with its status and response time. It's still checked and alerted on; click it for its details.</p>
      <p class="hint"><strong>Scanning</strong> sweeps the subnet on a schedule. With <em>Add new devices</em> on, devices it
        finds get a card (named from SNMP when they answer); otherwise they're listed here for you to review.
        <strong>Remove cards not seen</strong> deletes cards that haven't answered a check, a scan or ARP for that many days.
        Never removed: hosts with an agent, the subnet's gateway and muted hosts. If a quarter of a subnet goes quiet at
        once, nothing is removed (that looks like an outage). Changes are logged below and sent to your notification channels.</p>
      <form id="subnet-form" class="settings-form">
        <div id="subnet-rows"></div>
        <div class="subnet-add">
          <input type="text" id="subnet-add-input" placeholder="Add a subnet to scan, e.g. 192.168.1.0/24" maxlength="18" />
          <button type="button" class="section-btn" id="subnet-add-btn">Add subnet</button>
        </div>
        <div class="form-row">
          <button type="submit" class="primary-btn">Save</button>
          <span class="form-msg" id="subnet-msg"></span>
        </div>
      </form>
      <h3>Recent changes</h3>
      <div id="discovery-log" class="discovery-log"></div>
    </section>

    <section class="settings-panel admin-only hidden" data-panel="locations">
      <h3>Locations</h3>
      <p class="hint">Where things are (rooms, buildings, racks). Pick one for a card in its <strong>Edit</strong>; it shows on the card.
        Removing a location just clears it from its cards.</p>
      <div id="location-list"></div>
      <form id="location-form" class="subnet-add">
        <input type="text" id="location-name" maxlength="60" placeholder="New location, e.g. Kitchen, Garage, Server rack" />
        <button type="submit" class="section-btn">Add location</button>
        <span class="form-msg" id="location-msg"></span>
      </form>
    </section>

    <section class="settings-panel admin-only hidden" data-panel="groups">
      <h3>Groups</h3>
      <p class="hint">Sort cards into your own groups (e.g. Servers, Cameras, Office PCs). Tick a card's groups in its <strong>Edit</strong>
        (a card can be in several), show the host view by <strong>Group</strong>, or target a group with an update schedule.
        Removing a group takes it off its cards, screens and schedules.</p>
      <div id="group-list"></div>
      <form id="group-form" class="subnet-add">
        <input type="text" id="group-name" maxlength="60" placeholder="New group, e.g. Servers, Cameras, Office PCs" />
        <button type="submit" class="section-btn">Add group</button>
        <span class="form-msg" id="group-msg"></span>
      </form>

      <h3>Screens</h3>
      <p class="hint">A screen shows only the groups you tick, one section per group, in the order listed. Pick it from
        <strong>Screen</strong> above the cards, or open <code>?screen=N</code> on a wall display to start on it.</p>
      <div id="screen-list"></div>
      <form id="screen-form" class="subnet-add">
        <input type="text" id="screen-name" maxlength="60" placeholder="New screen, e.g. NOC wall, Cameras" />
        <button type="submit" class="section-btn">Add screen</button>
        <span class="form-msg" id="screen-msg"></span>
      </form>
    </section>

    <section class="settings-panel admin-only hidden" data-panel="changes">
      <p class="hint settings-intro">Every update window and every manual update, reboot, agent upgrade or service restart gets a change
        record (Updates → Change records) and an entry in the audit log (Reports → Audit log).</p>
      <div id="cm-email-warning" class="settings-raw"></div>
      <form id="cm-form" class="settings-form settings-raw settings-cards">
        <div class="settings-card">
          <h3>Change emails</h3>
          <p class="hint">Sent to their own list through your email channel in <strong>Notifications</strong>; alert emails are unaffected.</p>
          <label>Email addresses (comma-separated)
            <textarea id="cm-recipients" rows="2" placeholder="changes@example.com, it-manager@example.com"></textarea></label>
          <label class="check"><input type="checkbox" id="cm-manual" /> Also email when a manual change starts</label>
        </div>
        <div class="settings-card">
          <h3>Advance notice</h3>
          <div class="num-unit"><input type="number" id="cm-lead" min="0" max="720" step="1" aria-label="Hours before" /> hours before each scheduled window</div>
          <p class="hint">The change record is raised and emailed then (0 = when the window runs), listing the hosts and pending packages
            at that moment. Hosts are checked again when the window runs.</p>
        </div>
        <div class="settings-card">
          <h3>Approval</h3>
          <label class="check"><input type="checkbox" id="cm-approval" /> Require approval before scheduled windows run</label>
          <p class="hint check-hint">An admin approves each window in Updates → Change records; one not approved in time is skipped and
            recorded. Manual changes count as approved by the admin who starts them. Any planned window can be rejected either way.</p>
        </div>
        <div class="form-row settings-actions">
          <button type="submit" class="primary-btn">Save</button>
          <button type="button" class="section-btn" id="cm-test">Send test email</button>
          <span class="form-msg" id="cm-msg"></span>
        </div>
      </form>
    </section>

    <section class="settings-panel admin-only hidden" data-panel="homeassistant">
      <h3>Home Assistant</h3>
      <p class="hint">WebMonitor reads Home Assistant's device list every hour: the names you gave devices, maker, model,
        firmware, room and what each one does. It covers every brand Home Assistant is linked to (Tuya, Google, Alexa, Hue, …)
        and is matched to the device list by MAC address, IP address or name. Read-only: WebMonitor changes nothing in Home Assistant.</p>
      <p class="hint">Create a token in Home Assistant under your profile → <strong>Security</strong> → <strong>Long-lived access tokens</strong>.
        It's stored encrypted and never shown again.</p>
      <form id="ha-form" class="settings-form">
        <label>Address <input type="url" id="ha-url" placeholder="http://192.168.1.20:8123" maxlength="200" required /></label>
        <label>Access token <input type="password" id="ha-token" autocomplete="off" placeholder="Paste the long-lived access token" /></label>
        <div class="form-row">
          <button type="submit" class="primary-btn">Save and sync</button>
          <button type="button" class="section-btn" id="ha-sync">Sync now</button>
          <button type="button" class="section-btn" id="ha-unlink">Remove link</button>
          <span class="form-msg" id="ha-msg"></span>
        </div>
      </form>
      <div id="ha-status" class="muted small"></div>
    </section>

    <section class="settings-panel admin-only hidden" data-panel="hosts">
      <h3>Add a host</h3>
      <section class="add-host">
        <form id="add-form">
          <input type="text" id="name" placeholder="Name (e.g. Home NAS)" required maxlength="60" />
          <input type="text" id="ip" placeholder="IP address (e.g. 192.168.1.10)" required
                 pattern="^([0-9]{1,3}\.){3}[0-9]{1,3}$|^[0-9a-fA-F:]+$" />
          <select id="os">
            <option value="windows">Windows</option>
            <option value="macos">macOS</option>
            <option value="linux">Linux</option>
            <option value="other" selected>Other / Unknown</option>
          </select>
          <select id="device">
            <option value="server" selected>Server</option>
            <option value="desktop">Desktop</option>
            <option value="laptop">Laptop</option>
            <option value="mobile">Mobile</option>
            <option value="router">Router</option>
            <option value="switch">Switch</option>
            <option value="ap">Access Point</option>
            <option value="printer">Printer</option>
            <option value="camera">Camera</option>
            <option value="nas">NAS</option>
            <option value="ups">UPS</option>
            <option value="iot">IoT / smart home</option>
            <option value="console">Game console</option>
            <option value="tv">TV / streaming</option>
          </select>
          <input type="text" id="community" placeholder="SNMP community (optional, default public)" maxlength="64" />
          <button type="submit">+ Add</button>
        </form>
      </section>

      <section class="scan-section">
        <h3>Scan network</h3>
        <form id="scan-form">
          <textarea id="scan-networks" placeholder="192.168.1.0/24, 10.0.0.0/28 — CIDR ranges or single IPs, comma or newline separated" required></textarea>
          <div class="scan-options">
            <label><input type="checkbox" id="scan-snmp" checked /> Query SNMP for device info</label>
            <input type="text" id="scan-community" placeholder="SNMP community" value="public" />
            <button type="submit" id="scan-btn">Scan</button>
          </div>
        </form>
        <div id="scan-status" class="scan-status"></div>
        <div id="scan-results"></div>
      </section>
    </section>
      </div>
    </div>
  </div>
</div>

<div id="svc-backdrop" class="modal-backdrop hidden">
  <div class="modal svc-modal" role="dialog" aria-labelledby="svc-form-title">
    <button id="svc-close" class="modal-close" aria-label="Close">&times;</button>
    <h2 id="svc-form-title">Add check</h2>
    <form id="svc-form" class="settings-form" autocomplete="off"></form>
  </div>
</div>

<div id="flm-backdrop" class="modal-backdrop hidden">
  <div class="modal svc-modal" role="dialog" aria-labelledby="flm-form-title">
    <button id="flm-close" class="modal-close" aria-label="Close">&times;</button>
    <h2 id="flm-form-title">Add licence server</h2>
    <form id="flm-form" class="settings-form" autocomplete="off"></form>
  </div>
</div>

<div id="flm-detail-backdrop" class="modal-backdrop hidden">
  <div class="modal modal-wide" role="dialog" aria-labelledby="flm-detail-title">
    <button id="flm-detail-close" class="modal-close" aria-label="Close">&times;</button>
    <div id="flm-detail"></div>
  </div>
</div>

<div id="netspeed-backdrop" class="modal-backdrop hidden">
  <div class="modal svc-modal" role="dialog" aria-labelledby="netspeed-title">
    <button id="netspeed-close" class="modal-close" aria-label="Close">&times;</button>
    <h2 id="netspeed-title">Internet speed tests</h2>
    <form id="netspeed-form" class="settings-form" autocomplete="off"></form>
  </div>
</div>

<div id="device-backdrop" class="modal-backdrop hidden">
  <div class="modal modal-wide device-modal">
    <button id="device-close" class="modal-close" aria-label="Close">&times;</button>
    <div id="device-body"></div>
  </div>
</div>

<div id="modal-backdrop" class="modal-backdrop hidden">
  <div class="modal">
    <button id="modal-close" class="modal-close">&times;</button>
    <div class="modal-title-row">
      <h2 id="modal-title"></h2>
      <button id="rename-btn" class="section-btn admin-only" type="button" title="Name, device type, OS, SNMP community">Edit</button>
      <button id="delete-host-btn" class="section-btn danger admin-only" type="button" title="Remove this host and everything recorded for it">Delete</button>
    </div>
    <div id="host-edit" class="host-edit hidden"></div>
    <p id="modal-meta" class="modal-meta"></p>
    <div id="mute-box" class="mute-box hidden"></div>
    <div id="workload-section" class="switch-info workload-section hidden"></div>
    <div id="devinfo-section" class="switch-info hidden">
      <div class="switch-info-header">
        <h3>Device info</h3>
        <div class="section-actions" id="devinfo-actions"></div>
      </div>
      <div id="devinfo-body"></div>
    </div>
    <div id="host-services" class="switch-info hidden"></div>
    <canvas id="modal-chart" height="120"></canvas>
    <div id="history-section" class="switch-info history-section"></div>
    <div id="agent-section" class="switch-info">
      <div class="switch-info-header">
        <h3>Agent <span id="agent-age" class="details-age"></span></h3>
        <div class="section-actions" id="agent-actions"></div>
      </div>
      <div id="agent-body"></div>
    </div>
    <div id="host-details" class="switch-info">
      <div class="switch-info-header">
        <h3>Server Details <span id="host-details-age" class="details-age"></span></h3>
        <button id="host-details-refresh" class="section-btn" type="button">Refresh</button>
      </div>
      <div id="host-details-body"></div>
    </div>
    <div id="switch-info" class="switch-info hidden">
      <div class="switch-info-header">
        <h3>Ports <span id="portmap-age" class="details-age"></span></h3>
        <div class="section-actions">
          <div class="range-picker" role="group" aria-label="Port view">
            <button type="button" class="group-btn active" data-pmview="diagram">Diagram</button>
            <button type="button" class="group-btn" data-pmview="list">List</button>
          </div>
          <button id="switch-info-refresh" class="section-btn" type="button">Refresh</button>
        </div>
      </div>
      <div id="switch-info-body"></div>
    </div>
    <div id="wifi-section" class="switch-info hidden">
      <div class="switch-info-header">
        <h3>Wireless <span id="wifi-age" class="details-age"></span></h3>
      </div>
      <div id="wifi-body"></div>
    </div>
    <div id="config-section" class="switch-info hidden">
      <div class="switch-info-header">
        <h3>Configuration backups</h3>
        <div class="section-actions" id="config-actions"></div>
      </div>
      <div id="config-body"></div>
    </div>
  </div>
</div>

<script src="assets/app.js?v=54"></script>
<script src="assets/topology.js?v=46"></script>
<script src="assets/nas.js?v=47"></script>
<script src="assets/history.js?v=45"></script>
<script src="assets/settings.js?v=55"></script>
<script src="assets/updates.js?v=55"></script>
<script src="assets/configbackup.js?v=45"></script>
<script src="assets/portmap.js?v=45"></script>
<script src="assets/reports.js?v=46"></script>
<script src="assets/wireless.js?v=45"></script>
<script src="assets/devices.js?v=50"></script>
<script src="assets/traffic.js?v=45"></script>
<script src="assets/scans.js?v=48"></script>
<script src="assets/vulns.js?v=2"></script>
<script src="assets/services.js?v=1"></script>
<script src="assets/network.js?v=3"></script>
<script src="assets/flexlm.js?v=1"></script>
<script src="assets/changes.js?v=1"></script>
<script src="assets/help.js?v=3"></script>
</body>
</html>
