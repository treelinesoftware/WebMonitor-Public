<?php
require_once __DIR__ . '/db.php';

/**
 * WebMonitor Pro: optional features that live in pro/ (not part of the
 * open-source release). This file is the only link between the core and
 * pro/:
 *
 *   pro_available()      pro/ is installed
 *   pro_unlocked()       ... and unlocked (Settings -> WebMonitor Pro)
 *   pro_feature($name)   ... and that feature is switched on
 *
 * Pro modules are loaded only for features that are on. Everything the core
 * calls into Pro has a do-nothing stand-in below, so the core runs the same
 * with pro/ missing, locked or with a feature off.
 */

const PRO_DIR = __DIR__ . '/../pro';
const PRO_FEATURES = [
    'changes' => ['label' => 'Change management & audit log', 'default' => true,
                  'desc' => 'Change records for every update, change emails, optional approval, and a tamper-evident audit log.'],
    'remote' => ['label' => 'Remote access', 'default' => false,
                 'desc' => 'Remote buttons on host cards: Remote Desktop, SSH, VNC and the device\'s web page.'],
    'vulns' => ['label' => 'Vulnerability scanning', 'default' => true,
                'desc' => 'The Security tab: scheduled nmap / CVE scans and a security report.'],
    'flexlm' => ['label' => 'Licence-server monitoring', 'default' => true,
                 'desc' => 'The Licences tab: FlexLM servers, seats in use, expiry and alerts.'],
    'group_schedules' => ['label' => 'Update schedules by group', 'default' => true,
                          'desc' => 'Target update schedules at card groups.'],
];

function pro_available(): bool {
    static $a = null;
    return $a ??= is_file(PRO_DIR . '/pro.php');
}

/** Random id for this installation, made once; unlocking is tied to it. */
function pro_install_id(): string {
    $id = db()->query("SELECT v FROM settings WHERE k = 'install_id'")->fetchColumn();
    if (!$id) {
        $id = bin2hex(random_bytes(16));
        db()->prepare("INSERT IGNORE INTO settings (k, v) VALUES ('install_id', ?)")->execute([$id]);
        $id = db()->query("SELECT v FROM settings WHERE k = 'install_id'")->fetchColumn();
    }
    return (string) $id;
}

function pro_state(): array {
    $raw = db()->query("SELECT v FROM settings WHERE k = 'pro'")->fetchColumn();
    return $raw ? (json_decode($raw, true) ?: []) : [];
}

function pro_unlocked(): bool {
    static $u = null;
    if ($u !== null) return $u;
    if (!pro_available()) return $u = false;
    require_once PRO_DIR . '/pro.php';
    $state = pro_state();
    return $u = isset($state['token']) && function_exists('pro_check_token') && pro_check_token((string) $state['token']);
}

/** Feature switches (Settings -> WebMonitor Pro), defaults filled in. */
function pro_feature_settings(): array {
    $saved = pro_state()['features'] ?? [];
    $out = [];
    foreach (PRO_FEATURES as $k => $f) $out[$k] = array_key_exists($k, $saved) ? (bool) $saved[$k] : $f['default'];
    return $out;
}

function pro_feature(string $name): bool {
    static $cache = [];
    return $cache[$name] ??= pro_unlocked() && (pro_feature_settings()[$name] ?? false);
}

/** For the page (meta tag) and api/pro.php. */
function pro_status(): array {
    $state = pro_state();
    $unlocked = pro_unlocked();
    return [
        'available' => pro_available(),
        'unlocked' => $unlocked,
        'passwordSet' => pro_available() && function_exists('pro_password_set') && pro_password_set(),
        'unlockedBy' => $unlocked ? ($state['by'] ?? null) : null,
        'unlockedAt' => $unlocked ? ($state['at'] ?? null) : null,
        'features' => array_map(fn($on) => $on && $unlocked, pro_feature_settings()),
        'catalog' => PRO_FEATURES,
    ];
}

/** Outputs a Pro page fragment (pro/views/NAME.php) if its feature is on. */
function pro_view(string $name, string $feature): void {
    if (pro_feature($feature) && is_file(PRO_DIR . "/views/$name.php")) include PRO_DIR . "/views/$name.php";
}

/** For api/*.php stand-ins: runs pro/api/NAME.php, or answers 404 when the feature is off. */
function pro_api(string $name, string $feature): void {
    if (!pro_feature($feature) || !is_file(PRO_DIR . "/api/$name.php")) {
        http_response_code(404);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'This is a WebMonitor Pro feature' . (pro_unlocked() ? ' that is switched off (Settings → WebMonitor Pro).' : '.')]);
        exit;
    }
    require PRO_DIR . "/api/$name.php";
}

// ---------------------------------------------------------------- load

if (pro_feature('changes')) {
    require_once PRO_DIR . '/includes/audit.php';
    require_once PRO_DIR . '/includes/changes.php';
}
if (pro_feature('flexlm')) require_once PRO_DIR . '/includes/flexlm.php';
if (pro_feature('remote')) require_once PRO_DIR . '/includes/remote.php';

// ---------------------------------------------------------------- stand-ins

if (!function_exists('audit_log')) {
    function audit_actor(?array $user = null, bool $set = false): ?array { return null; }
    function audit_log(string $action, string $summary, ?string $target = null, ?array $details = null,
                       string $outcome = 'ok', ?array $actor = null, bool $system = false): void {}
    function audit_api_request(array $user): void {}
}
if (!function_exists('change_process')) {
    define('CHANGE_JOB_TYPES', []);   // no change records: schedules run as they always did
    function change_record_manual(array $res, string $type, array $params, ?array $user, string $title,
                                  ?int $scheduleId = null, ?int $runId = null): ?int { return null; }
    function change_gate(array $sched, DateTimeImmutable $occ): array { return ['run' => true, 'change' => null]; }
    function change_started(array $c, int $runId, array $res): void {}
    function change_process(): void {}
}
if (!function_exists('flexlm_run_due')) {
    function flexlm_run_due(): void {}
    function flexlm_problems(callable $add, array &$frozenHosts): void {}
    function flexlm_alert_live(array $a): bool { return false; }   // open licence alerts resolve
    function flexlm_alert_name(array $a): string { return 'Licence server'; }
}
if (!function_exists('pro_host_extras')) {
    function pro_host_extras(array $hosts): array { return []; }
}
