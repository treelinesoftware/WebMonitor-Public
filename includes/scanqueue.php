<?php
require_once __DIR__ . '/db.php';

function next_scan_priority(): int {
    if (db()->exec('UPDATE scan_queue_order SET sequence = LAST_INSERT_ID(sequence + 1) WHERE id = 1') !== 1) {
        throw new RuntimeException('Scan queue priority counter is missing');
    }
    return (int) db()->query('SELECT LAST_INSERT_ID()')->fetchColumn();
}

function scan_activity_start(string $lock, string $kind, string $target): void {
    db()->exec('DELETE FROM scan_activity WHERE IS_USED_LOCK(lock_name) IS NULL OR IS_USED_LOCK(lock_name) <> connection_id');
    db()->prepare('INSERT INTO scan_activity (lock_name, connection_id, kind, target, started_at)
                   VALUES (?, CONNECTION_ID(), ?, ?, ?)
                   ON DUPLICATE KEY UPDATE connection_id = VALUES(connection_id), kind = VALUES(kind),
                     target = VALUES(target), started_at = VALUES(started_at)')
        ->execute([$lock, $kind, $target, (int) round(microtime(true) * 1000)]);
}

function scan_activity_end(string $lock): void {
    db()->prepare('DELETE FROM scan_activity WHERE lock_name = ? AND connection_id = CONNECTION_ID()')->execute([$lock]);
}

function active_scans(): array {
    $rows = db()->query('SELECT kind, target, started_at FROM scan_activity
                         WHERE IS_USED_LOCK(lock_name) = connection_id ORDER BY started_at')->fetchAll();
    return array_map(fn($r) => ['kind' => $r['kind'], 'target' => $r['target'], 'startedAt' => (int) $r['started_at']], $rows);
}
