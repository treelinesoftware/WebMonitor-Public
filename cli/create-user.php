<?php
// Creates a dashboard login, or resets the password of an existing one:
//   php cli/create-user.php <username> [--role=admin|viewer]
// New users default to admin (this is how the first account is made);
// an existing user's role only changes if --role is given.
// The password is prompted for (hidden). For scripted installs it can come
// from the WEBMON_PASSWORD environment variable instead.
require_once __DIR__ . '/../includes/db.php';

if (PHP_SAPI !== 'cli') exit(1);

const MIN_PASSWORD_LEN = 10;

$username = trim($argv[1] ?? '');
$role = null;
foreach (array_slice($argv, 2) as $arg) {
    if (preg_match('/^--role=(admin|viewer)$/', $arg, $m)) {
        $role = $m[1];
    } else {
        fwrite(STDERR, "Unknown option: $arg (expected --role=admin or --role=viewer)\n");
        exit(1);
    }
}
if ($username === '' || !preg_match('/^[A-Za-z0-9._@-]{1,60}$/', $username)) {
    fwrite(STDERR, "Usage: php cli/create-user.php <username> [--role=admin|viewer]\n" .
        "Username: 1-60 characters, letters/digits/._@- only.\n");
    exit(1);
}

function prompt_hidden(string $label): string {
    echo $label;
    $tty = stream_isatty(STDIN);
    if ($tty) shell_exec('stty -echo');
    $value = rtrim((string) fgets(STDIN), "\r\n");
    if ($tty) {
        shell_exec('stty echo');
        echo "\n";
    }
    return $value;
}

$pdo = db();
$stmt = $pdo->prepare('SELECT id FROM users WHERE username = ?');
$stmt->execute([$username]);
$existingId = $stmt->fetchColumn();

$password = getenv('WEBMON_PASSWORD');
if ($password === false || $password === '') {
    if ($existingId) echo "User '$username' exists — this will reset their password.\n";
    $password = prompt_hidden('Password: ');
    if ($password !== prompt_hidden('Repeat password: ')) {
        fwrite(STDERR, "Passwords don't match.\n");
        exit(1);
    }
}
if (strlen($password) < MIN_PASSWORD_LEN) {
    fwrite(STDERR, 'Password must be at least ' . MIN_PASSWORD_LEN . " characters.\n");
    exit(1);
}

$hash = password_hash($password, PASSWORD_DEFAULT);
if ($existingId) {
    $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([$hash, $existingId]);
    if ($role !== null) $pdo->prepare('UPDATE users SET role = ? WHERE id = ?')->execute([$role, $existingId]);
    echo "Password updated for '$username'" . ($role !== null ? " (role: $role)" : '') . ".\n";
} else {
    $role = $role ?? 'admin';
    $pdo->prepare('INSERT INTO users (username, password_hash, role) VALUES (?, ?, ?)')->execute([$username, $hash, $role]);
    echo "User '$username' created ($role).\n";
}
