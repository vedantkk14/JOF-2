<?php
/**
 * auth/logout.php
 * ─────────────────────────────────────────────────────────────────
 * Securely destroys the session and redirects to login.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on',
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    session_start();
}

// Log the logout event before destroying
if (isset($_SESSION['user_id'])) {
    error_log(sprintf(
        '[AUTH] Logout | user_id=%d | role=%s | ip=%s',
        $_SESSION['user_id'],
        $_SESSION['user_role'] ?? 'unknown',
        $_SERVER['REMOTE_ADDR'] ?? 'unknown'
    ));
}

// 1. Unset all session variables
$_SESSION = [];

// 2. Expire the session cookie immediately
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params['path'],
        $params['domain'],
        $params['secure'],
        $params['httponly']
    );
}

// 3. Destroy the session on the server
session_destroy();

// 4. Redirect to login page
header('Location: ../index.php');
exit;
