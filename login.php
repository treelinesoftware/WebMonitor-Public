<?php
require_once __DIR__ . '/includes/auth.php';

if (current_user()) {
    header('Location: ' . app_base_path());
    exit;
}

$error = null;
$username = '';
$noUsers = !users_exist();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$noUsers) {
    $username = (string) ($_POST['username'] ?? '');
    if (!hash_equals(csrf_token(), (string) ($_POST['csrf'] ?? ''))) {
        $error = 'Your session expired. Please try again.';
    } else {
        $error = attempt_login($username, (string) ($_POST['password'] ?? ''));
        if ($error === null) {
            header('Location: ' . app_base_path());
            exit;
        }
    }
}

function h(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}
?><!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
<!-- No user yet: reuse the theme last chosen in this browser. -->
<script>try { var t = localStorage.getItem("wm-theme"); if (t) document.documentElement.dataset.theme = t; } catch (e) {}</script>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<title>Sign in — WebMonitor</title>
<link rel="icon" href="favicon.ico" sizes="48x48" />
<link rel="icon" href="assets/favicon.svg" type="image/svg+xml" />
<link rel="apple-touch-icon" href="assets/apple-touch-icon.png" />
<link rel="stylesheet" href="assets/style.css?v=8" />
</head>
<body class="login-page">
<main class="login-box">
  <img src="assets/favicon.svg" alt="" class="login-logo" width="48" height="48" />
  <h1>WebMonitor</h1>
<?php if ($noUsers): ?>
  <p class="login-note">No accounts exist yet. Create the first one on the server:</p>
  <pre class="login-cmd">php <?= h(__DIR__) ?>/cli/create-user.php admin</pre>
  <p class="login-note">Then reload this page.</p>
<?php else: ?>
  <form method="post" autocomplete="on">
    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>" />
    <label>Username
      <input type="text" name="username" value="<?= h($username) ?>" autocomplete="username" required autofocus maxlength="60" />
    </label>
    <label>Password
      <input type="password" name="password" autocomplete="current-password" required />
    </label>
<?php if ($error): ?>
    <div class="login-error" role="alert"><?= h($error) ?></div>
<?php endif; ?>
    <button type="submit">Sign in</button>
  </form>
<?php endif; ?>
</main>
</body>
</html>
