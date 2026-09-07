<?php
session_start();
require '../config.php';

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

// ── Rate Limiting (5 failed attempts per 10 min per IP) ───────────
$ip       = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$rl_key   = '_rl_' . md5($ip);
$rl_data  = $_SESSION[$rl_key] ?? ['count' => 0, 'since' => time()];

// Reset window if older than 10 minutes
if ((time() - $rl_data['since']) > 600) {
    $rl_data = ['count' => 0, 'since' => time()];
}

if ($rl_data['count'] >= 5) {
    $wait = (int) ceil((600 - (time() - $rl_data['since'])) / 60);
    echo json_encode(['success' => false, 'message' => "Too many failed attempts. Try again in {$wait} minute(s).", 'csrf_token' => $new_csrf]);
    exit;
}

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

// ── DB Lookup ─────────────────────────────────────────────────────
$stmt = mysqli_prepare($conn, "SELECT id, full_name, password, role FROM user_data WHERE email = ? LIMIT 1");
mysqli_stmt_bind_param($stmt, 's', $email);
mysqli_stmt_execute($stmt);
$user = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

$valid_roles = ['admin', 'trainer', 'user', 'counsellor'];

if ($user && password_verify($password, $user['password']) && in_array($user['role'], $valid_roles, true)) {
    // Success — clear failed attempts
    unset($_SESSION[$rl_key]);

    session_regenerate_id(true);
    $_SESSION['user_id']   = $user['id'];
    $_SESSION['user_name'] = $user['full_name'];
    $_SESSION['user_role'] = $user['role'];

    echo json_encode(['success' => true, 'redirect' => _redirect($user['role'])]);
} else {
    // Failed — increment counter
    $rl_data['count']++;
    $_SESSION[$rl_key] = $rl_data;

    echo json_encode(['success' => false, 'message' => 'Invalid email or password.', 'csrf_token' => $new_csrf]);
}

function _redirect(string $role): string {
    return match($role) {
        'user'       => 'templates/user_side/user_dashboard.php',
        'counsellor' => 'templates/counsellor_side/counsellor_dashboard.php',
        default      => 'templates/dashboard.php',
    };
}