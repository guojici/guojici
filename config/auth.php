<?php
require_once __DIR__ . '/db.php';

function auth_start() {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
}

function auth_login($user) {
    auth_start();
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['username'] = $user['username'];
    $_SESSION['email'] = $user['email'];
    $_SESSION['login_time'] = time();
}

function auth_check() {
    auth_start();
    return isset($_SESSION['user_id']) && $_SESSION['user_id'];
}

function auth_user() {
    if (!auth_check()) return null;
    static $cached_user = null;
    if ($cached_user !== null && $cached_user['id'] == $_SESSION['user_id']) {
        return $cached_user;
    }
    $pdo = db();
    static $role_checked = false;
    if (!$role_checked) {
        try {
            $col_exists = $pdo->query("SHOW COLUMNS FROM users LIKE 'role'")->fetch();
            if (!$col_exists) {
                $pdo->exec("ALTER TABLE users ADD COLUMN role ENUM('user','admin') DEFAULT 'user'");
            }
        } catch (Exception $e) {}
        $role_checked = true;
    }
    $stmt = $pdo->prepare("SELECT id, username, email, phone, balance, status, role, created_at FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch();
    if ($user) {
        $cached_user = $user;
    }
    return $user;
}

function auth_id() {
    auth_start();
    return $_SESSION['user_id'] ?? 0;
}

function auth_logout() {
    auth_start();
    $_SESSION = [];
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params["path"], $params["domain"], $params["secure"], $params["httponly"]);
    }
    session_destroy();
}

function require_auth($redirect = '/login.php') {
    if (!auth_check()) {
        header("Location: $redirect");
        exit;
    }
    // 鉴权完成后立即释放Session锁，避免阻塞同用户的并发请求
    session_write_close();
}

function admin_auth_start() {
    if (session_status() === PHP_SESSION_NONE) {
        session_name('admin_session');
        session_start();
    }
}

function admin_login($admin) {
    admin_auth_start();
    $_SESSION['admin_id'] = $admin['id'];
    $_SESSION['admin_username'] = $admin['username'];
    $_SESSION['admin_role'] = $admin['role'];
}

function admin_check() {
    admin_auth_start();
    return isset($_SESSION['admin_id']) && $_SESSION['admin_id'];
}

function admin_user() {
    if (!admin_check()) return null;
    static $cached_admin = null;
    if ($cached_admin !== null && $cached_admin['id'] == $_SESSION['admin_id']) {
        return $cached_admin;
    }
    $pdo = db();
    $stmt = $pdo->prepare("SELECT id, username, role, last_login FROM admin_users WHERE id = ?");
    $stmt->execute([$_SESSION['admin_id']]);
    $admin = $stmt->fetch();
    if ($admin) {
        $cached_admin = $admin;
    }
    return $admin;
}

function require_admin($redirect = '/admin/login.php') {
    if (!admin_check()) {
        header("Location: $redirect");
        exit;
    }
    // 鉴权完成后立即释放Session锁，避免阻塞同用户的并发请求
    session_write_close();
}

function admin_logout() {
    admin_auth_start();
    $_SESSION = [];
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params["path"], $params["domain"], $params["secure"], $params["httponly"]);
    }
    session_destroy();
}

function csrf_token() {
    auth_start();
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_check($token) {
    auth_start();
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}
