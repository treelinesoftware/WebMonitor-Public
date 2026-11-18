<?php
// User management (admins only).
//   GET              -> [{id, username, role, createdAt, lastLoginAt}]
//   POST             {username, password, role}       -> create
//   PATCH  ?id=N     {role?, password?}               -> change role / reset password
//   DELETE ?id=N                                      -> delete
// There must always be at least one admin, and you can't delete yourself.
require_once __DIR__ . '/../includes/auth.php';
header('Content-Type: application/json');
$me = require_api_auth('admin');

const MIN_PASSWORD_LEN = 10;

function fail(int $code, string $msg): void {
    http_response_code($code);
    echo json_encode(['error' => $msg]);
    exit;
}

function admin_count(): int {
    return (int) db()->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn();
}

function check_password(string $pw): void {
    if (strlen($pw) < MIN_PASSWORD_LEN) fail(400, 'Password must be at least ' . MIN_PASSWORD_LEN . ' characters.');
}

$pdo = db();
$method = $_SERVER['REQUEST_METHOD'];
$input = $method === 'GET' ? [] : (json_decode(file_get_contents('php://input'), true) ?? []);

if ($method === 'GET') {
    $rows = $pdo->query('SELECT id, username, role, created_at, last_login_at FROM users ORDER BY username')->fetchAll();
    echo json_encode(array_map(fn($r) => [
        'id' => (int) $r['id'],
        'username' => $r['username'],
        'role' => $r['role'],
        'createdAt' => $r['created_at'],
        'lastLoginAt' => $r['last_login_at'],
        'isMe' => (int) $r['id'] === $me['id'],
    ], $rows));
    exit;
}

if ($method === 'POST') {
    $username = trim((string) ($input['username'] ?? ''));
    $role = (string) ($input['role'] ?? 'viewer');
    if (!preg_match('/^[A-Za-z0-9._@-]{1,60}$/', $username)) {
        fail(400, 'Username: 1-60 characters, letters, digits and . _ @ - only.');
    }
    if (!in_array($role, ROLES, true)) fail(400, 'Role must be admin or viewer.');
    check_password((string) ($input['password'] ?? ''));
    $exists = $pdo->prepare('SELECT 1 FROM users WHERE username = ?');
    $exists->execute([$username]);
    if ($exists->fetchColumn()) fail(409, "User '$username' already exists.");
    $pdo->prepare('INSERT INTO users (username, password_hash, role) VALUES (?, ?, ?)')
        ->execute([$username, password_hash((string) $input['password'], PASSWORD_DEFAULT), $role]);
    http_response_code(201);
    echo json_encode(['id' => (int) $pdo->lastInsertId(), 'username' => $username, 'role' => $role]);
    exit;
}

$id = (int) ($_GET['id'] ?? 0);
$stmt = $pdo->prepare('SELECT id, username, role FROM users WHERE id = ?');
$stmt->execute([$id]);
$target = $stmt->fetch();
if (!$target) fail(404, 'User not found.');

if ($method === 'PATCH') {
    if (isset($input['role'])) {
        $role = (string) $input['role'];
        if (!in_array($role, ROLES, true)) fail(400, 'Role must be admin or viewer.');
        if ($target['role'] === 'admin' && $role !== 'admin' && admin_count() <= 1) {
            fail(400, 'This is the only admin. Make someone else an admin first.');
        }
        $pdo->prepare('UPDATE users SET role = ? WHERE id = ?')->execute([$role, $id]);
    }
    if (isset($input['password'])) {
        check_password((string) $input['password']);
        $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
            ->execute([password_hash((string) $input['password'], PASSWORD_DEFAULT), $id]);
    }
    echo json_encode(['ok' => true]);
    exit;
}

if ($method === 'DELETE') {
    if ((int) $target['id'] === $me['id']) fail(400, "You can't delete your own account.");
    if ($target['role'] === 'admin' && admin_count() <= 1) fail(400, "Can't delete the only admin.");
    $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
    http_response_code(204);
    exit;
}

fail(405, 'method not allowed');
