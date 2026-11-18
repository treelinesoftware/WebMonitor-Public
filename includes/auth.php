<?php
require_once __DIR__ . '/db.php';

/**
 * Session-based login for the dashboard and its API.
 *
 * - Passwords: password_hash()/password_verify() (bcrypt), rehashed when the
 *   default algorithm changes.
 * - Sessions: HttpOnly, SameSite=Strict cookie scoped to the app's path,
 *   Secure when served over HTTPS, new ID on login, idle timeout.
 * - CSRF: every non-GET API request must send the session's token in an
 *   X-CSRF-Token header (app.js does this for every call).
 * - Throttling: too many failed logins per IP or per username locks out
 *   further attempts for LOGIN_LOCK_SEC.
 */

const SESSION_NAME = 'WEBMONSESS';
const SESSION_IDLE_SEC = 12 * 3600;
const LOGIN_LOCK_SEC = 15 * 60;
const LOGIN_MAX_FAILS_PER_USER = 5;
const LOGIN_MAX_FAILS_PER_IP = 20;

function is_https(): bool {
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (int) ($_SERVER['SERVER_PORT'] ?? 0) === 443;
}

/** URL path of the app root, e.g. "/webmonitor/" (works from api/ too). */
function app_base_path(): string {
    $dir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/'));
    if (basename($dir) === 'api') $dir = dirname($dir);
    return rtrim($dir, '/') . '/';
}

function start_session(): void {
    if (session_status() === PHP_SESSION_ACTIVE) return;
    session_name(SESSION_NAME);
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => app_base_path(),
        'secure' => is_https(),
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    ini_set('session.use_strict_mode', '1');
    session_start();

    $now = time();
    if (isset($_SESSION['user_id']) && $now - ($_SESSION['last_seen'] ?? 0) > SESSION_IDLE_SEC) {
        $_SESSION = [];
        session_regenerate_id(true);
    }
    $_SESSION['last_seen'] = $now;
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

const ROLES = ['admin', 'viewer'];

/**
 * The logged-in user (id, username, role) or null. Starts the session.
 * Re-read from the database each request, so role changes and deleted
 * accounts take effect immediately.
 */
function current_user(): ?array {
    static $cached = false;
    if ($cached !== false) return $cached;
    start_session();
    if (empty($_SESSION['user_id'])) return $cached = null;
    $stmt = db()->prepare('SELECT id, username, role, prefs FROM users WHERE id = ?');
    $stmt->execute([(int) $_SESSION['user_id']]);
    $row = $stmt->fetch();
    if (!$row) {
        unset($_SESSION['user_id'], $_SESSION['username']);
        return $cached = null;
    }
    return $cached = ['id' => (int) $row['id'], 'username' => $row['username'], 'role' => $row['role'],
                      'prefs' => normalize_prefs(json_decode((string) $row['prefs'], true) ?: [])];
}

const THEMES = ['system', 'dark', 'light'];
const COLUMN_CHOICES = ['auto', '2', '3', '4'];
const GROUPINGS = ['subnet', 'host', 'location'];

/** Display preferences with defaults; unknown values fall back to defaults. */
function normalize_prefs(array $p): array {
    $theme = (string) ($p['theme'] ?? 'dark');
    $cols = (string) ($p['columns'] ?? 'auto');
    return [
        'theme' => in_array($theme, THEMES, true) ? $theme : 'dark',
        'columns' => in_array($cols, COLUMN_CHOICES, true) ? $cols : 'auto',
        'grouping' => in_array($p['grouping'] ?? '', GROUPINGS, true) ? $p['grouping'] : 'subnet',
    ];
}

function is_admin(?array $user): bool {
    return $user !== null && $user['role'] === 'admin';
}

function csrf_token(): string {
    start_session();
    return $_SESSION['csrf'];
}

/**
 * For api/*.php: 401 unless logged in, 403 on a bad CSRF token for anything
 * but GET, 403 if $role is 'admin' and the user isn't one. Releases the
 * session lock so concurrent API calls don't queue up behind each other.
 */
function require_api_auth(string $role = 'viewer'): array {
    $user = current_user();
    if (!$user) {
        session_write_close();
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'not logged in']);
        exit;
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        $sent = (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
        if (!hash_equals($_SESSION['csrf'], $sent)) {
            session_write_close();
            http_response_code(403);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'invalid CSRF token — reload the page']);
            exit;
        }
    }
    session_write_close();
    if ($role === 'admin' && !is_admin($user)) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'admin access required']);
        exit;
    }
    return $user;
}

/** require_api_auth() with admin required for anything but GET. */
function require_api_auth_admin_writes(): array {
    return require_api_auth($_SERVER['REQUEST_METHOD'] === 'GET' ? 'viewer' : 'admin');
}

/** For pages: redirect to the login page unless logged in. */
function require_login_page(): array {
    $user = current_user();
    if (!$user) {
        header('Location: ' . app_base_path() . 'login.php');
        exit;
    }
    return $user;
}

function users_exist(): bool {
    return (bool) db()->query('SELECT 1 FROM users LIMIT 1')->fetchColumn();
}

function client_ip(): string {
    return substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
}

/** Seconds until login is allowed again for this IP/username, or 0. */
function login_locked_for(string $username, string $ip): int {
    $since = (int) round(microtime(true) * 1000) - LOGIN_LOCK_SEC * 1000;
    $stmt = db()->prepare(
        'SELECT
           SUM(username = ?) AS user_fails, MIN(CASE WHEN username = ? THEN ts END) AS user_first,
           SUM(ip = ?) AS ip_fails, MIN(CASE WHEN ip = ? THEN ts END) AS ip_first
         FROM login_attempts WHERE success = 0 AND ts > ? AND (username = ? OR ip = ?)'
    );
    $stmt->execute([$username, $username, $ip, $ip, $since, $username, $ip]);
    $r = $stmt->fetch();

    $first = null;
    if ((int) $r['user_fails'] >= LOGIN_MAX_FAILS_PER_USER) $first = (int) $r['user_first'];
    if ((int) $r['ip_fails'] >= LOGIN_MAX_FAILS_PER_IP) {
        $first = $first === null ? (int) $r['ip_first'] : min($first, (int) $r['ip_first']);
    }
    if ($first === null) return 0;
    return max(1, (int) ceil(($first + LOGIN_LOCK_SEC * 1000 - microtime(true) * 1000) / 1000));
}

/**
 * Checks credentials and logs the user in. Returns null on success or an
 * error message for the login form.
 */
function attempt_login(string $username, string $password): ?string {
    $username = substr(trim($username), 0, 60);
    $ip = client_ip();
    $pdo = db();

    $wait = login_locked_for($username, $ip);
    if ($wait > 0) {
        return 'Too many failed attempts. Try again in ' . max(1, (int) ceil($wait / 60)) . ' minute(s).';
    }

    $stmt = $pdo->prepare('SELECT id, username, password_hash FROM users WHERE username = ?');
    $stmt->execute([$username]);
    $user = $stmt->fetch();

    // Verify against a dummy hash for unknown users so response timing
    // doesn't reveal which usernames exist.
    $hash = $user ? $user['password_hash'] : '$2y$10$f/pr/MSwG83ynxyXqBjJjeCbL8e/6Tk5Lwhs1LARv.UT2JDWNhBIm';
    $ok = password_verify($password, $hash) && $user;

    $pdo->prepare('INSERT INTO login_attempts (ip, username, ts, success) VALUES (?, ?, ?, ?)')
        ->execute([$ip, $username, (int) round(microtime(true) * 1000), $ok ? 1 : 0]);
    // Keep the table small.
    $pdo->prepare('DELETE FROM login_attempts WHERE ts < ?')
        ->execute([(int) round(microtime(true) * 1000) - 7 * 86400 * 1000]);

    if (!$ok) return 'Incorrect username or password.';

    if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
        $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
            ->execute([password_hash($password, PASSWORD_DEFAULT), $user['id']]);
    }
    $pdo->prepare('UPDATE users SET last_login_at = NOW() WHERE id = ?')->execute([$user['id']]);

    start_session();
    session_regenerate_id(true);
    $_SESSION['user_id'] = (int) $user['id'];
    $_SESSION['username'] = $user['username'];
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
    $_SESSION['last_seen'] = time();
    return null;
}

function logout(): void {
    start_session();
    $_SESSION = [];
    $p = session_get_cookie_params();
    setcookie(session_name(), '', [
        'expires' => time() - 3600, 'path' => $p['path'], 'secure' => $p['secure'],
        'httponly' => true, 'samesite' => 'Strict',
    ]);
    session_destroy();
}
