<?php
// Docker images keep their generated config outside the app directory, so the app
// directory can be the read-only image (or a mounted checkout, for development).
require_once is_file('/etc/webmonitor/config.php') ? '/etc/webmonitor/config.php' : __DIR__ . '/../config.php';

// Times in alerts and logs use the server's own timezone (php.ini often
// says UTC regardless), unless config.php sets TIMEZONE.
(function () {
    $tz = defined('TIMEZONE') ? TIMEZONE : null;
    if ($tz === null && ($link = @readlink('/etc/localtime')) && preg_match('#zoneinfo/(.+)$#', $link, $m)) {
        $tz = $m[1];
    }
    if ($tz !== null && in_array($tz, timezone_identifiers_list(), true)) date_default_timezone_set($tz);
})();

/**
 * Gives a host named after its IP address a real name (short form of
 * $fullName). Hosts someone has named are left alone.
 */
function adopt_host_name(int $hostId, ?string $fullName): void {
    $short = substr(explode('.', trim((string) $fullName))[0], 0, 60);
    if ($short === '' || filter_var($short, FILTER_VALIDATE_IP)) return;
    db()->prepare('UPDATE hosts SET name = ? WHERE id = ? AND name = ip_address')->execute([$short, $hostId]);
}

function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = 'mysql:host=' . DB_HOST . (defined('DB_PORT') ? ';port=' . DB_PORT : '') . ';dbname=' . DB_NAME . ';charset=utf8mb4';
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }
    return $pdo;
}

/** Admin-given subnet names: ["192.168.10.0/24" => "Servers", ...]. */
function subnet_names(): array {
    $raw = db()->query("SELECT v FROM settings WHERE k = 'subnet_names'")->fetchColumn();
    return is_string($raw) ? (json_decode($raw, true) ?: []) : [];
}

/** Admin-given subnet gateways: ["192.168.10.0/24" => "192.168.10.1", ...]. */
function subnet_gateways(): array {
    $raw = db()->query("SELECT v FROM settings WHERE k = 'subnet_gateways'")->fetchColumn();
    return is_string($raw) ? (json_decode($raw, true) ?: []) : [];
}

/**
 * Deletes a host. Everything recorded for it (checks, history, agent, jobs,
 * alerts) goes with it through ON DELETE CASCADE; update schedules keep host
 * ids in JSON, so it's dropped there too, and a schedule left with no hosts
 * is switched off. Returns false if there was no such host.
 */
function delete_host(int $id): bool {
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('DELETE FROM hosts WHERE id = ?');
        $stmt->execute([$id]);
        if ($stmt->rowCount() === 0) {
            $pdo->rollBack();
            return false;
        }
        foreach ($pdo->query("SELECT id, host_ids FROM update_schedules WHERE targets = 'hosts'")->fetchAll() as $s) {
            $ids = array_map('intval', json_decode($s['host_ids'], true) ?: []);
            if (!in_array($id, $ids, true)) continue;
            $ids = array_values(array_diff($ids, [$id]));
            $pdo->prepare('UPDATE update_schedules SET host_ids = ?, enabled = IF(? = 0, 0, enabled) WHERE id = ?')
                ->execute([json_encode($ids), count($ids), $s['id']]);
        }
        $pdo->commit();
        return true;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/**
 * Changes whenever the dashboard's files are updated (deploys), so open pages
 * can tell they're running old code and offer a reload.
 */
function app_version(): string {
    $root = dirname(__DIR__);
    $files = array_merge([$root . '/index.php'], glob($root . '/assets/*.{js,css}', GLOB_BRACE) ?: []);
    return substr(md5(implode('|', array_map(fn($f) => $f . @filemtime($f), $files))), 0, 12);
}
