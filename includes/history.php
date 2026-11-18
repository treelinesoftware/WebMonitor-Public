<?php
require_once __DIR__ . '/db.php';

/**
 * History for graphs: per-minute points (8 days) rolled into hourly
 * avg/min/max (400 days). A series is (host, metric, subject), e.g.
 * (7, 'disk', '/'), (5, 'temp_disk', 'HDD3'), (11, 'net_rx', 'ens18').
 *
 * Writers: store_agent_metrics() (every agent report), record_ping_points()
 * and the NAS poll. cli/cron.php runs rollup_history() every minute (it only
 * does work once per hour).
 */

const HISTORY_RAW_DAYS = 8;
const HISTORY_HOURLY_DAYS = 400;
// Ranges the dashboard offers: [seconds, bucket seconds, source].
const HISTORY_RANGES = [
    '1h' => [3600, 60, 'raw'],
    '6h' => [6 * 3600, 60, 'raw'],
    '24h' => [86400, 300, 'raw'],
    '7d' => [7 * 86400, 1800, 'raw'],
    '30d' => [30 * 86400, 3 * 3600, 'hourly'],
    '90d' => [90 * 86400, 12 * 3600, 'hourly'],
    '1y' => [365 * 86400, 86400, 'hourly'],
];

/** Id of a series, created on first use (cached per request). */
function series_id(int $hostId, string $metric, string $subject, string $unit): int {
    static $cache = [];
    $subject = mb_substr($subject, 0, 120);
    $key = "$hostId|$metric|$subject";
    if (isset($cache[$key])) return $cache[$key];
    $pdo = db();
    $pdo->prepare('INSERT IGNORE INTO metric_series (host_id, metric, subject, unit) VALUES (?, ?, ?, ?)')
        ->execute([$hostId, $metric, $subject, $unit]);
    $stmt = $pdo->prepare('SELECT id FROM metric_series WHERE host_id = ? AND metric = ? AND subject = ?');
    $stmt->execute([$hostId, $metric, $subject]);
    return $cache[$key] = (int) $stmt->fetchColumn();
}

/**
 * Records points for one host at one time (rounded to the minute).
 * $points: [[metric, subject, unit, value], ...]; null values are skipped.
 */
function record_points(int $hostId, array $points, ?int $ts = null): void {
    $ts = intdiv($ts ?? time(), 60) * 60;
    $rows = [];
    foreach ($points as [$metric, $subject, $unit, $value]) {
        if ($value === null || !is_numeric($value) || !is_finite((float) $value)) continue;
        $rows[] = [series_id($hostId, $metric, (string) $subject, $unit), $ts, (float) $value];
    }
    if (!$rows) return;
    // INSERT IGNORE: a second report in the same minute keeps the first.
    db()->prepare('INSERT IGNORE INTO metric_points (series_id, ts, value) VALUES '
        . implode(',', array_fill(0, count($rows), '(?, ?, ?)')))
        ->execute(array_merge(...$rows));
}

/** Response time and availability of every host, from its latest check. Cron, every minute. */
function record_ping_points(): void {
    $cutoff = (int) round(microtime(true) * 1000) - 120000;
    $latest = db()->query("SELECT c.host_id, c.up, c.ms FROM checks c
        JOIN (SELECT host_id, MAX(ts) ts FROM checks GROUP BY host_id) m ON m.host_id = c.host_id AND m.ts = c.ts
        WHERE c.ts > $cutoff")->fetchAll();
    foreach ($latest as $c) {
        record_points((int) $c['host_id'], [
            ['up', '', '%', $c['up'] ? 100 : 0],
            ['ping_ms', '', 'ms', $c['up'] && $c['ms'] !== null ? (int) $c['ms'] : null],
        ]);
    }
}

/** Rolls finished hours into metric_hourly and purges old data. Cheap when there's nothing to do. */
function rollup_history(): void {
    $pdo = db();
    $done = (int) $pdo->query("SELECT v FROM settings WHERE k = 'history_rolled_until'")->fetchColumn();
    $thisHour = intdiv(time(), 3600) * 3600;
    if ($done >= $thisHour) return;
    if (!(int) $pdo->query("SELECT GET_LOCK('webmonitor_rollup', 0)")->fetchColumn()) return;
    try {
        $from = $done ?: (int) $pdo->query('SELECT COALESCE(MIN(ts), 0) FROM metric_points')->fetchColumn();
        $from = intdiv($from, 3600) * 3600;
        if ($from > 0 && $from < $thisHour) {
            $pdo->prepare('INSERT INTO metric_hourly (series_id, ts, avg_value, min_value, max_value, samples)
                           SELECT series_id, ts - MOD(ts, 3600), AVG(value), MIN(value), MAX(value), COUNT(*)
                           FROM metric_points WHERE ts >= ? AND ts < ?
                           GROUP BY series_id, ts - MOD(ts, 3600)
                           ON DUPLICATE KEY UPDATE avg_value = VALUES(avg_value), min_value = VALUES(min_value),
                             max_value = VALUES(max_value), samples = VALUES(samples)')
                ->execute([$from, $thisHour]);
        }
        $pdo->prepare("INSERT INTO settings (k, v) VALUES ('history_rolled_until', ?) ON DUPLICATE KEY UPDATE v = VALUES(v)")
            ->execute([(string) $thisHour]);
        $pdo->prepare('DELETE FROM metric_points WHERE ts < ?')->execute([time() - HISTORY_RAW_DAYS * 86400]);
        $pdo->prepare('DELETE FROM metric_hourly WHERE ts < ?')->execute([time() - HISTORY_HOURLY_DAYS * 86400]);
    } finally {
        $pdo->query("SELECT RELEASE_LOCK('webmonitor_rollup')");
    }
}

/**
 * History of a host for a range: [[metric, subject, unit, points: [[ts, avg, min, max], ...]], ...].
 * Points are averaged into buckets so each line has a few hundred points at most.
 */
function query_history(int $hostId, string $range): array {
    [$span, $bucket, $source] = HISTORY_RANGES[$range] ?? HISTORY_RANGES['24h'];
    $pdo = db();
    // Per-port switch traffic has its own charts (Network tab, includes/netlive.php).
    $stmt = $pdo->prepare("SELECT id, metric, subject, unit FROM metric_series WHERE host_id = ? AND metric NOT IN ('port_in', 'port_out')");
    $stmt->execute([$hostId]);
    $series = [];
    foreach ($stmt->fetchAll() as $s) {
        $series[(int) $s['id']] = ['metric' => $s['metric'], 'subject' => $s['subject'], 'unit' => $s['unit'], 'points' => []];
    }
    if (!$series) return [];

    $from = time() - $span;
    $ids = implode(',', array_keys($series));
    $sql = $source === 'raw'
        ? "SELECT series_id, ts - MOD(ts, $bucket) AS t, AVG(value) a, MIN(value) mn, MAX(value) mx
           FROM metric_points WHERE series_id IN ($ids) AND ts >= ? GROUP BY series_id, t ORDER BY t"
        : "SELECT series_id, ts - MOD(ts, $bucket) AS t, SUM(avg_value * samples) / SUM(samples) a, MIN(min_value) mn, MAX(max_value) mx
           FROM metric_hourly WHERE series_id IN ($ids) AND ts >= ? GROUP BY series_id, t ORDER BY t";
    $q = $pdo->prepare($sql);
    $q->execute([$from]);
    foreach ($q->fetchAll(PDO::FETCH_NUM) as [$sid, $t, $a, $mn, $mx]) {
        $series[(int) $sid]['points'][] = [(int) $t, round((float) $a, 2), round((float) $mn, 2), round((float) $mx, 2)];
    }
    return array_values(array_filter($series, fn($s) => $s['points']));
}
