<?php
// The signed-in user's own account.
//   GET                                   -> {username, role, prefs}
//   POST {currentPassword, newPassword}   -> change own password
//   PATCH {prefs: {theme, columns}}       -> save display preferences
require_once __DIR__ . '/../includes/auth.php';
header('Content-Type: application/json');
$me = require_api_auth();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    echo json_encode(['username' => $me['username'], 'role' => $me['role'], 'prefs' => $me['prefs']]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'PATCH') {
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $prefs = normalize_prefs(array_merge($me['prefs'], (array) ($input['prefs'] ?? [])));
    db()->prepare('UPDATE users SET prefs = ? WHERE id = ?')->execute([json_encode($prefs), $me['id']]);
    echo json_encode(['prefs' => $prefs]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true) ?? [];
$current = (string) ($input['currentPassword'] ?? '');
$new = (string) ($input['newPassword'] ?? '');

$stmt = db()->prepare('SELECT password_hash FROM users WHERE id = ?');
$stmt->execute([$me['id']]);
if (!password_verify($current, (string) $stmt->fetchColumn())) {
    http_response_code(400);
    echo json_encode(['error' => 'Current password is incorrect.']);
    exit;
}
if (strlen($new) < 10) {
    http_response_code(400);
    echo json_encode(['error' => 'New password must be at least 10 characters.']);
    exit;
}
db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
    ->execute([password_hash($new, PASSWORD_DEFAULT), $me['id']]);
echo json_encode(['ok' => true]);
