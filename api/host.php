<?php
// DELETE ?id=N                                     -> remove the host
// PATCH  ?id=N {name: "..."}                       -> rename the host
// PATCH  ?id=N {mutedUntil: ms|null, muteReason}   -> mute / unmute alerts
// PATCH  ?id=N {device?, os?, community?, bwExempt?, locationId?, groupIds?} -> host settings
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/devicescan.php';
header('Content-Type: application/json');
require_api_auth('admin');

$method = $_SERVER['REQUEST_METHOD'];
if ($method !== 'DELETE' && $method !== 'PATCH') {
    http_response_code(405);
    echo json_encode(['error' => 'method not allowed']);
    exit;
}

$id = (int) ($_GET['id'] ?? 0);
if (!$id) {
    http_response_code(400);
    echo json_encode(['error' => 'id required']);
    exit;
}

if ($method === 'PATCH') {
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $exists = db()->prepare('SELECT 1 FROM hosts WHERE id = ?');
    $exists->execute([$id]);
    if (!$exists->fetchColumn()) {
        http_response_code(404);
        echo json_encode(['error' => 'not found']);
        exit;
    }
    $out = ['id' => $id];
    if (array_key_exists('name', $input)) {
        $name = trim((string) $input['name']);
        if ($name === '' || mb_strlen($name) > 60) {
            http_response_code(400);
            echo json_encode(['error' => 'name must be 1-60 characters']);
            exit;
        }
        db()->prepare('UPDATE hosts SET name = ?, auto_named = 0 WHERE id = ?')->execute([$name, $id]);
        $out['name'] = $name;
    }
    if (array_key_exists('mutedUntil', $input)) {
        $until = $input['mutedUntil'];
        $nowMs = (int) round(microtime(true) * 1000);
        if ($until !== null && (!is_numeric($until) || (int) $until <= $nowMs)) {
            http_response_code(400);
            echo json_encode(['error' => 'mutedUntil must be a future time (ms) or null']);
            exit;
        }
        $reason = trim((string) ($input['muteReason'] ?? ''));
        db()->prepare('UPDATE hosts SET muted_until = ?, mute_reason = ? WHERE id = ?')
            ->execute([$until === null ? null : (int) $until, $until === null || $reason === '' ? null : mb_substr($reason, 0, 120), $id]);
        $out['mutedUntil'] = $until === null ? null : (int) $until;
    }
    $allowedOs = ['windows', 'macos', 'linux', 'other'];
    $allowedDevice = ['server', 'desktop', 'laptop', 'mobile', 'router', 'switch', 'ap', 'printer', 'camera', 'nas', 'ups', 'iot', 'console', 'tv'];
    foreach (['os' => $allowedOs, 'device' => $allowedDevice] as $field => $allowed) {
        if (!array_key_exists($field, $input)) continue;
        if (!in_array($input[$field], $allowed, true)) {
            http_response_code(400);
            echo json_encode(['error' => "unknown $field"]);
            exit;
        }
        db()->prepare("UPDATE hosts SET $field = ? WHERE id = ?")->execute([$input[$field], $id]);
        $out[$field] = $input[$field];
        if ($field === 'device' && in_array($input[$field], DEVICE_SCAN_TYPES, true)) {
            $ipq = db()->prepare('SELECT ip_address FROM hosts WHERE id = ?');
            $ipq->execute([$id]);
            request_device_scan(null, (string) $ipq->fetchColumn(), false);   // find out what it is (devicescan.php)
        }
    }
    if (array_key_exists('locationId', $input)) {
        $loc = $input['locationId'] === null || $input['locationId'] === '' ? null : (int) $input['locationId'];
        if ($loc !== null) {
            $ok = db()->prepare('SELECT 1 FROM locations WHERE id = ?');
            $ok->execute([$loc]);
            if (!$ok->fetchColumn()) {
                http_response_code(400);
                echo json_encode(['error' => 'unknown location']);
                exit;
            }
        }
        db()->prepare('UPDATE hosts SET location_id = ? WHERE id = ?')->execute([$loc, $id]);
        $out['locationId'] = $loc;
    }
    if (array_key_exists('groupIds', $input)) {
        $want = array_values(array_unique(array_filter(array_map('intval', (array) $input['groupIds']))));
        $known = array_map('intval', db()->query('SELECT id FROM card_groups')->fetchAll(PDO::FETCH_COLUMN));
        if (array_diff($want, $known)) {
            http_response_code(400);
            echo json_encode(['error' => 'unknown group']);
            exit;
        }
        db()->prepare('DELETE FROM host_group_members WHERE host_id = ?')->execute([$id]);
        $ins = db()->prepare('INSERT INTO host_group_members (host_id, group_id) VALUES (?, ?)');
        foreach ($want as $g) $ins->execute([$id, $g]);
        sort($want);
        $out['groupIds'] = $want;
    }
    if (array_key_exists('bwExempt', $input)) {
        db()->prepare('UPDATE hosts SET bw_exempt = ? WHERE id = ?')->execute([$input['bwExempt'] ? 1 : 0, $id]);
        $out['bwExempt'] = (bool) $input['bwExempt'];
    }
    if (array_key_exists('community', $input)) {
        $community = substr(trim((string) $input['community']), 0, 64);
        db()->prepare('UPDATE hosts SET community = ? WHERE id = ?')->execute([$community === '' ? 'public' : $community, $id]);
        // Details collected with the old community are no longer trustworthy.
        db()->prepare('DELETE FROM host_details WHERE host_id = ?')->execute([$id]);
        $out['community'] = $community === '' ? 'public' : $community;
    }
    echo json_encode($out);
    exit;
}

if (!delete_host($id)) {   // db.php
    http_response_code(404);
    echo json_encode(['error' => 'not found']);
    exit;
}

http_response_code(204);
