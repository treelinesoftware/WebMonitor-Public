<?php
// Locations for cards (Settings -> Locations).
//   GET                    -> {locations: [{id, name, hosts}]}
//   POST   {name}          -> add (admins)
//   PATCH  ?id=N {name}    -> rename (admins)
//   DELETE ?id=N           -> remove; its cards keep no location (admins)
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
header('Content-Type: application/json');
require_api_auth_admin_writes();

function fail(int $code, string $msg): void {
    http_response_code($code);
    echo json_encode(['error' => $msg]);
    exit;
}

function locations_list(): array {
    return array_map(fn($r) => ['id' => (int) $r['id'], 'name' => $r['name'], 'hosts' => (int) $r['n']],
        db()->query('SELECT l.id, l.name, COUNT(h.id) n FROM locations l LEFT JOIN hosts h ON h.location_id = l.id
                     GROUP BY l.id, l.name ORDER BY l.sort, l.name')->fetchAll());
}

$pdo = db();
$method = $_SERVER['REQUEST_METHOD'];
$id = (int) ($_GET['id'] ?? 0);
$in = $method === 'GET' || $method === 'DELETE' ? [] : (json_decode(file_get_contents('php://input'), true) ?? []);
$name = trim(preg_replace('/\s+/', ' ', (string) ($in['name'] ?? '')));

try {
    if ($method === 'POST') {
        if ($name === '' || mb_strlen($name) > 60) fail(400, 'name must be 1-60 characters');
        $pdo->prepare('INSERT INTO locations (name, sort) VALUES (?, (SELECT COALESCE(MAX(sort), 0) + 1 FROM locations l2))')->execute([$name]);
    } elseif ($method === 'PATCH') {
        if ($name === '' || mb_strlen($name) > 60) fail(400, 'name must be 1-60 characters');
        $pdo->prepare('UPDATE locations SET name = ? WHERE id = ?')->execute([$name, $id]);
    } elseif ($method === 'DELETE') {
        $pdo->prepare('DELETE FROM locations WHERE id = ?')->execute([$id]);
    } elseif ($method !== 'GET') {
        fail(405, 'method not allowed');
    }
} catch (PDOException $e) {
    if ($e->getCode() === '23000') fail(409, "there is already a location called \"$name\"");
    throw $e;
}
echo json_encode(['locations' => locations_list()]);
