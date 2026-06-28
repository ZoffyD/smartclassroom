<?php

//   login (default) | signup | account | forgot | logout
require __DIR__ . '/api/auth_lib.php';

$action = $_GET['action'] ?? 'login';
$error = '';
$ok = '';

// logout
if ($action === 'logout') {
    $_SESSION = [];
    session_destroy();
    header('Location: auth.php?action=login');
    exit;
}

// editing the account needs a login first
if ($action === 'account' && !isLoggedIn()) { header('Location: auth.php?action=login'); exit; }
// if already logged in, skip login / signup
if (in_array($action, ['login', 'signup', 'forgot'], true) && isLoggedIn()) { header('Location: index.html'); exit; }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $u  = $_POST['user'] ?? '';
    $p  = $_POST['pass'] ?? '';
    $p2 = $_POST['pass2'] ?? '';

    if ($action === 'login') {
        if (checkLogin($u, $p)) { header('Location: index.html'); exit; }
        $error = 'Wrong username or password.';

    } elseif ($action === 'signup') {
        if ($p !== $p2) $error = 'The two passwords do not match.';
        elseif (registerUser($u, $p, $error)) { checkLogin($u, $p); header('Location: index.html'); exit; }

    } elseif ($action === 'account') {
        if ($p !== $p2) $error = 'The two passwords do not match.';
        elseif (updateAccount($u, $p, $error)) { header('Location: index.html'); exit; }  // back to dashboard

    } elseif ($action === 'forgot') {
        if ($p !== $p2) $error = 'The two passwords do not match.';
        elseif (resetPassword($u, $p, $error)) { $ok = 'Password reset. You can log in now.'; }
    }
}
$current = currentUsername();

// per-action page text
$titles = [
    'login'   => ['🏫 Smart Classroom', 'Please log in to view the dashboard'],
    'signup'  => ['🏫 Create Account',   'Sign up to access the dashboard'],
    'account' => ['👤 Edit Account',     'Change your username or password'],
    'forgot'  => ['🔑 Reset Password',   'Enter your username and a new password'],
];
[$title, $sub] = $titles[$action] ?? $titles['login'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Smart Classroom - <?= htmlspecialchars($title) ?></title>
  <link rel="stylesheet" href="style.css?v=6" />
</head>
<body class="auth">
  <div class="box">
    <h1><?= $title ?></h1>
    <p class="sub"><?= htmlspecialchars($sub) ?></p>

    <form method="POST" action="auth.php?action=<?= htmlspecialchars($action) ?>">
      <?php if ($action === 'account'): ?>
        <label>Username</label>
        <input name="user" value="<?= htmlspecialchars($current) ?>" required />
        <label>New password (leave blank to keep current)</label>
        <input name="pass" type="password" placeholder="••••••" />
        <label>Confirm new password</label>
        <input name="pass2" type="password" placeholder="••••••" />
        <button type="submit">Save changes</button>

      <?php elseif ($action === 'signup'): ?>
        <label>Username (min 3 characters)</label>
        <input name="user" autofocus required />
        <label>Password (min 6 characters)</label>
        <input name="pass" type="password" required />
        <label>Confirm password</label>
        <input name="pass2" type="password" required />
        <button type="submit">Sign up</button>

      <?php elseif ($action === 'forgot'): ?>
        <label>Username</label>
        <input name="user" autofocus required />
        <label>New password (min 6 characters)</label>
        <input name="pass" type="password" required />
        <label>Confirm new password</label>
        <input name="pass2" type="password" required />
        <button type="submit">Reset password</button>

      <?php else: /* login */ ?>
        <label>Username</label>
        <input name="user" autofocus required />
        <label>Password</label>
        <input name="pass" type="password" required />
        <button type="submit">Log in</button>
      <?php endif; ?>

      <?php if ($error): ?><div class="err"><?= htmlspecialchars($error) ?></div><?php endif; ?>
      <?php if ($ok): ?><div class="ok"><?= htmlspecialchars($ok) ?></div><?php endif; ?>
    </form>

    <div class="alt">
      <?php if ($action === 'login'): ?>
        No account yet? <a href="auth.php?action=signup">Sign up</a><br>
        <a href="auth.php?action=forgot">Forgot password?</a>
      <?php elseif ($action === 'signup'): ?>
        Already have an account? <a href="auth.php?action=login">Log in</a>
      <?php elseif ($action === 'forgot'): ?>
        <a href="auth.php?action=login">← Back to log in</a>
      <?php else: /* account */ ?>
        <a href="index.html">← Back to dashboard</a>
      <?php endif; ?>
    </div>
  </div>
</body>
</html>
