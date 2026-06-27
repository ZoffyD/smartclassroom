<?php
// Dashboard accounts: login, sign up, and edit username/password.
// Accounts live in the MySQL `users` table; passwords are stored as bcrypt
// hashes (never plain text). The ESP32 endpoints stay public - see dbconnect.php.

date_default_timezone_set('Asia/Kuala_Lumpur');

if (session_status() === PHP_SESSION_NONE) {
    $lifetime = 8 * 3600;   // keep the login alive for 8 hours (so it won't time out mid-demo)
    ini_set('session.gc_maxlifetime', $lifetime);
    session_set_cookie_params(['lifetime' => $lifetime, 'path' => '/Eric/smartclassroom/']);
    session_start();
}

// own small DB connection so this file works on the login pages too (which do
// not include dbconnect.php). reuses the same credentials from config.php.
function authdb() {
    static $pdo = null;
    if ($pdo) return $pdo;
    $cfg = require __DIR__ . '/config.php';
    $pdo = new PDO(
        "mysql:host={$cfg['host']};dbname={$cfg['name']};charset={$cfg['charset']}",
        $cfg['user'], $cfg['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
    return $pdo;
}

function isLoggedIn()      { return !empty($_SESSION['uid']); }
function currentUsername() { return $_SESSION['username'] ?? ''; }

// API guard: stop with 401 if not logged in (only checks the session, no DB)
function requireLogin() {
    if (!isLoggedIn()) {
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'login required']);
        exit;
    }
}

// verify credentials against the users table and start the session
function checkLogin($user, $pass) {
    $st = authdb()->prepare("SELECT * FROM users WHERE username = ?");
    $st->execute([trim($user)]);
    $u = $st->fetch();
    if ($u && password_verify($pass, $u['password_hash'])) {
        $_SESSION['uid']      = $u['id'];
        $_SESSION['username'] = $u['username'];
        return true;
    }
    return false;
}

// create a new account (then it can log in)
function registerUser($user, $pass, &$err) {
    $user = trim($user);
    if (strlen($user) < 3) { $err = 'Username must be at least 3 characters.'; return false; }
    if (strlen($pass) < 6) { $err = 'Password must be at least 6 characters.'; return false; }
    $st = authdb()->prepare("SELECT 1 FROM users WHERE username = ?");
    $st->execute([$user]);
    if ($st->fetch()) { $err = 'That username is already taken.'; return false; }
    authdb()->prepare("INSERT INTO users (username, password_hash, created_at) VALUES (?,?,?)")
            ->execute([$user, password_hash($pass, PASSWORD_DEFAULT), date('Y-m-d H:i:s')]);
    return true;
}

// change the logged-in user's username and (optionally) password
function updateAccount($newUser, $newPass, &$err) {
    if (!isLoggedIn()) { $err = 'Not logged in.'; return false; }
    $newUser = trim($newUser);
    if (strlen($newUser) < 3) { $err = 'Username must be at least 3 characters.'; return false; }
    $st = authdb()->prepare("SELECT 1 FROM users WHERE username = ? AND id <> ?");
    $st->execute([$newUser, $_SESSION['uid']]);
    if ($st->fetch()) { $err = 'That username is already taken.'; return false; }

    if ($newPass !== '') {
        if (strlen($newPass) < 6) { $err = 'Password must be at least 6 characters.'; return false; }
        authdb()->prepare("UPDATE users SET username = ?, password_hash = ? WHERE id = ?")
                ->execute([$newUser, password_hash($newPass, PASSWORD_DEFAULT), $_SESSION['uid']]);
    } else {
        authdb()->prepare("UPDATE users SET username = ? WHERE id = ?")
                ->execute([$newUser, $_SESSION['uid']]);
    }
    $_SESSION['username'] = $newUser;
    return true;
}

// reset a password by username (simple "forgot password" - no email link).
// note: a production system would email a one-time reset link instead.
function resetPassword($user, $newPass, &$err) {
    $user = trim($user);
    if (strlen($newPass) < 6) { $err = 'Password must be at least 6 characters.'; return false; }
    $st = authdb()->prepare("SELECT id FROM users WHERE username = ?");
    $st->execute([$user]);
    $u = $st->fetch();
    if (!$u) { $err = 'No account with that username.'; return false; }
    authdb()->prepare("UPDATE users SET password_hash = ? WHERE id = ?")
            ->execute([password_hash($newPass, PASSWORD_DEFAULT), $u['id']]);
    return true;
}
