<?php
// Keep the session cookie on the site root so index.php, /auth/ and /templates/
// all read & write the SAME session (prevents "Security token mismatch").
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}
require '../config.php';
require_once __DIR__ . '/rate_limiter.php';

header('Content-Type: application/json');

// ── Already logged in ─────────────────────────────────────────────
if (isset($_SESSION['user_id'], $_SESSION['user_role'])) {
    echo json_encode(['success' => true, 'redirect' => _redirect($_SESSION['user_role'])]);
    exit;
}

// ── Only POST allowed ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

// ── CSRF Check ────────────────────────────────────────────────────
$submitted = $_POST['_csrf_token'] ?? '';
$stored    = $_SESSION['_csrf_token'] ?? '';
if (!$stored || !hash_equals($stored, $submitted)) {
    // Issue a fresh token so the user can retry without a full page reload
    $new_csrf = bin2hex(random_bytes(32));
    $_SESSION['_csrf_token'] = $new_csrf;
    echo json_encode(['success' => false, 'message' => 'Security token expired. Please try again.', 'csrf_token' => $new_csrf]);
    exit;
}

// ── Rotate CSRF: consume old token, issue a fresh one for next attempt ─
unset($_SESSION['_csrf_token']);
$new_csrf = bin2hex(random_bytes(32));
$_SESSION['_csrf_token'] = $new_csrf;

// ── Input Validation ──────────────────────────────────────────────
$email    = trim(filter_var($_POST['email'] ?? '', FILTER_SANITIZE_EMAIL));
$password = $_POST['password'] ?? '';

if ($email === '' || $password === '') {
    echo json_encode(['success' => false, 'message' => 'Email and password are required.', 'csrf_token' => $new_csrf]);
    exit;
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['success' => false, 'message' => 'Invalid email or password.', 'csrf_token' => $new_csrf]);
    exit;
}

// ── Rate Limiting (database-backed, per IP and per account — see auth/rate_limiter.php) ─
$retry = login_retry_after($conn, $email);
if ($retry > 0) {
    echo json_encode(['success' => false, 'message' => 'Too many failed login attempts. Please try again in ' . rl_wait_text($retry) . '.', 'csrf_token' => $new_csrf]);
    exit;
}

// ── DB Lookup ─────────────────────────────────────────────────────
$stmt = mysqli_prepare($conn, "SELECT id, full_name, password, role, is_active FROM user_data WHERE email = ? LIMIT 1");
mysqli_stmt_bind_param($stmt, 's', $email);
mysqli_stmt_execute($stmt);
$user = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

$valid_roles = ['admin', 'trainer', 'user', 'counsellor'];

// Normalise the stored role: lower-case, trimmed, and map spelling variants
$user_role = $user ? strtolower(trim($user['role'] ?? '')) : '';
if ($user_role === 'councillor') {
    $user_role = 'counsellor';
}

// ── Suspended account check ──────────────────────────────────────
if ($user && password_verify($password, $user['password']) && (int) $user['is_active'] !== 1) {
    echo json_encode(['success' => false, 'message' => 'Your account has been suspended. Please contact an administrator.', 'csrf_token' => $new_csrf]);
    exit;
}

if ($user && password_verify($password, $user['password']) && in_array($user_role, $valid_roles, true)) {
    // Success — clear this account's failed attempts + consume the CSRF token
    login_record_success($conn, $email);
    unset($_SESSION['_csrf_token']);

    session_regenerate_id(true);
    $_SESSION['user_id']   = $user['id'];
    $_SESSION['user_name'] = $user['full_name'];
    $_SESSION['user_role'] = $user_role;

    echo json_encode(['success' => true, 'redirect' => _redirect($user_role)]);
} else {
    // Failed — counted even when the email has no account, so responses never reveal which emails exist
    login_record_failure($conn, $email);

    $retry = login_retry_after($conn, $email);
    $message = $retry > 0
        ? 'Too many failed login attempts. Please try again in ' . rl_wait_text($retry) . '.'
        : 'Invalid email or password.';
    echo json_encode(['success' => false, 'message' => $message, 'csrf_token' => $new_csrf]);
}

function _redirect(string $role): string {
    return match($role) {
        'user'       => 'templates/user_side/user_dashboard.php',
        'counsellor' => 'templates/counsellor_side/counsellor_dashboard.php',
        default      => 'templates/dashboard.php',
    };
}