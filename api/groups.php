<?php
// Card groups (Settings -> Groups) and screens: named sets of groups the host
// view can switch to.
//   GET                                 -> {groups: [{id, name, hosts}], screens: [{id, name, groupIds}]}
//   POST   {name}                       -> add a group (admins)
//   PATCH  ?id=N {name}                 -> rename a group (admins)
//   DELETE ?id=N                        -> remove a group: off its cards, screens and update schedules (admins)
//   POST   ?type=screen {name, groupIds}        -> add a screen (admins)
//   PATCH  ?type=screen&id=N {name?, groupIds?} -> change a screen (admins)
//   DELETE ?type=screen&id=N                    -> remove a screen (admins)
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
header('Content-Type: application/json');
require_api_auth_admin_writes();

function fail(int $code, string $msg): void {
    http_response_code($code);
    echo json_encode(['error' => $msg]);
    exit;
}

$pdo = db();
$method = $_SERVER['REQUEST_METHOD'];
$id = (int) ($_GET['id'] ?? 0);
$isScreen = ($_GET['type'] ?? '') === 'screen';
$in = $method === 'GET' || $method === 'DELETE' ? [] : (json_decode(file_get_contents('php://input'), true) ?? []);
$name = trim(preg_replace('/\s+/', ' ', (string) ($in['name'] ?? '')));
$checkName = function () use ($name): void {
    if ($name === '' || mb_strlen($name) > 60) fail(400, 'name must be 1-60 characters');
};

/** Screen's groups, in the order given; unknown ids are dropped. */
function save_screen_groups(int $screenId, array $groupIds): void {
    $pdo = db();
    $known = array_flip(array_map('intval', $pdo->query('SELECT id FROM card_groups')->fetchAll(PDO::FETCH_COLUMN)));
    $pdo->prepare('DELETE FROM screen_groups WHERE screen_id = ?')->execute([$screenId]);
    $ins = $pdo->prepare('INSERT IGNORE INTO screen_groups (screen_id, group_id, sort) VALUES (?, ?, ?)');
    $sort = 0;
    foreach ($groupIds as $g) {
        if (isset($known[(int) $g])) $ins->execute([$screenId, (int) $g, $sort++]);
    }
}

try {
    if ($isScreen) {
        if ($method === 'POST') {
            $checkName();
            $pdo->prepare('INSERT INTO screens (name, sort) VALUES (?, (SELECT COALESCE(MAX(sort), 0) + 1 FROM screens s2))')->execute([$name]);
            save_screen_groups((int) $pdo->lastInsertId(), (array) ($in['groupIds'] ?? []));
        } elseif ($method === 'PATCH') {
            if (array_key_exists('name', $in)) {
                $checkName();
                $pdo->prepare('UPDATE screens SET name = ? WHERE id = ?')->execute([$name, $id]);
            }
            if (array_key_exists('groupIds', $in)) save_screen_groups($id, (array) $in['groupIds']);
        } elseif ($method === 'DELETE') {
            $pdo->prepare('DELETE FROM screens WHERE id = ?')->execute([$id]);
        } elseif ($method !== 'GET') {
            fail(405, 'method not allowed');
        }
    } else {
        if ($method === 'POST') {
            $checkName();
            $pdo->prepare('INSERT INTO card_groups (name, sort) VALUES (?, (SELECT COALESCE(MAX(sort), 0) + 1 FROM card_groups g2))')->execute([$name]);
        } elseif ($method === 'PATCH') {
            $checkName();
            $pdo->prepare('UPDATE card_groups SET name = ? WHERE id = ?')->execute([$name, $id]);
        } elseif ($method === 'DELETE') {
            $pdo->prepare('DELETE FROM card_groups WHERE id = ?')->execute([$id]);
            // Update schedules that targeted it lose it; one left with no groups is turned off.
            foreach ($pdo->query("SELECT id, group_ids FROM update_schedules WHERE targets = 'groups'")->fetchAll() as $s) {
                $ids = array_map('intval', json_decode((string) $s['group_ids'], true) ?: []);
                if (!in_array($id, $ids, true)) continue;
                $ids = array_values(array_diff($ids, [$id]));
                $pdo->prepare('UPDATE update_schedules SET group_ids = ?, enabled = IF(? = 0, 0, enabled) WHERE id = ?')
                    ->execute([json_encode($ids), count($ids), $s['id']]);
            }
        } elseif ($method !== 'GET') {
            fail(405, 'method not allowed');
        }
    }
} catch (PDOException $e) {
    if ($e->getCode() === '23000') fail(409, 'there is already a ' . ($isScreen ? 'screen' : 'group') . " called \"$name\"");
    throw $e;
}
echo json_encode(['groups' => card_groups_list(), 'screens' => screens_list()]);
