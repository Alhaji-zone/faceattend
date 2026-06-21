<?php
// ============================================================
//  auth.php — Authentication & Authorization helpers
// ============================================================
require_once __DIR__ . '/config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => SESSION_LIFETIME,
        'path'     => '/',
        'secure'   => false,   // set true in production (HTTPS)
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    session_start();
}

// ── Require a logged-in session, optionally checking role ────
function requireLogin(string ...$roles): void {
    if (empty($_SESSION['user_id'])) {
        header('Location: ' . APP_URL . '/login.php');
        exit;
    }
    if (!empty($roles) && !in_array($_SESSION['role'], $roles, true)) {
        http_response_code(403);
        die('Access denied.');
    }
}

// ── Log in a user ────────────────────────────────────────────
function login(string $email, string $password): array|false {
    $stmt = db()->prepare('SELECT * FROM users WHERE email = ? AND is_active = 1 LIMIT 1');
    $stmt->execute([$email]);
    $user = $stmt->fetch();
    if (!$user || !password_verify($password, $user['password'])) {
        return false;
    }
    $_SESSION['user_id']   = $user['id'];
    $_SESSION['full_name'] = $user['full_name'];
    $_SESSION['email']     = $user['email'];
    $_SESSION['role']      = $user['role'];
    logActivity('login', 'User logged in');
    return $user;
}

// ── Log out ──────────────────────────────────────────────────
function logout(): void {
    logActivity('logout', 'User logged out');
    session_destroy();
    header('Location: ' . APP_URL . '/login.php');
    exit;
}

// ── Current user shorthand ───────────────────────────────────
function currentUser(): array {
    return [
        'id'        => $_SESSION['user_id']   ?? null,
        'full_name' => $_SESSION['full_name'] ?? '',
        'role'      => $_SESSION['role']      ?? '',
    ];
}

// ── Activity logger ──────────────────────────────────────────
function logActivity(string $action, string $detail = ''): void {
    try {
        $stmt = db()->prepare(
            'INSERT INTO activity_log (user_id, action, detail, ip_address) VALUES (?,?,?,?)'
        );
        $stmt->execute([
            $_SESSION['user_id'] ?? null,
            $action,
            $detail,
            $_SERVER['REMOTE_ADDR'] ?? null,
        ]);
    } catch (Throwable) { /* non-fatal */ }
}

