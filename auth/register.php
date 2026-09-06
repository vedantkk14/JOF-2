<?php
/**
 * auth/register.php
 * ─────────────────────────────────────────────────────────────────
 * Handles AJAX registration requests (POST → JSON response).
 * Security: CSRF, email validation, min-length password, prepared
 * statements, password_hash, default role = 'user'.
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

require_once __DIR__ . '/../config.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

// ── CSRF Validation ───────────────────────────────────────────────
$submitted_csrf = $_POST['_csrf_token'] ?? '';
$stored_csrf    = $_SESSION['_csrf_token'] ?? '';

if (!$stored_csrf || !hash_equals($stored_csrf, $submitted_csrf)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Invalid security token. Please refresh the page.']);
    exit;
}
unset($_SESSION['_csrf_token']);

// ── Input Collection & Validation ────────────────────────────────
$full_name = trim(htmlspecialchars($_POST['full_name'] ?? '', ENT_QUOTES, 'UTF-8'));
$email     = trim(filter_var($_POST['email'] ?? '', FILTER_SANITIZE_EMAIL));
$password  = $_POST['password'] ?? '';

if ($full_name === '' || $email === '' || $password === '') {
    echo json_encode(['success' => false, 'message' => 'All fields are required.']);
    exit;
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['success' => false, 'message' => 'Please enter a valid email address.']);
    exit;
}

if (strlen($password) < 8) {
    echo json_encode(['success' => false, 'message' => 'Password must be at least 8 characters.']);
    exit;
}

if (strlen($full_name) < 2 || strlen($full_name) > 100) {
    echo json_encode(['success' => false, 'message' => 'Full name must be between 2 and 100 characters.']);
    exit;
}

// ── Check Duplicate Email ─────────────────────────────────────────
$check_stmt = mysqli_prepare($conn, 'SELECT id FROM user_data WHERE email = ? LIMIT 1');
mysqli_stmt_bind_param($check_stmt, 's', $email);
mysqli_stmt_execute($check_stmt);
mysqli_stmt_store_result($check_stmt);

if (mysqli_stmt_num_rows($check_stmt) > 0) {
    mysqli_stmt_close($check_stmt);
    // Generic message — avoid account enumeration
    echo json_encode(['success' => false, 'message' => 'An account with that email already exists.']);
    exit;
}
mysqli_stmt_close($check_stmt);

// ── Hash Password ─────────────────────────────────────────────────
$hashed_password = password_hash($password, PASSWORD_DEFAULT);

// ── Insert User (default role = 'user') ──────────────────────────
$insert_stmt = mysqli_prepare($conn,
    "INSERT INTO user_data (full_name, email, password, role) VALUES (?, ?, ?, 'user')"
);
mysqli_stmt_bind_param($insert_stmt, 'sss', $full_name, $email, $hashed_password);

if (mysqli_stmt_execute($insert_stmt)) {
    $new_id = mysqli_insert_id($conn);
    mysqli_stmt_close($insert_stmt);

    error_log(sprintf('[AUTH] Registration | user_id=%d | email_hash=%s', $new_id, hash('sha256', $email)));

    // Send welcome / registration confirmation email using exact template from forms/
    require_once __DIR__ . '/send_registration_email.php';
    $email_res = sendRegistrationEmail($full_name, $email);
    if (!$email_res['success']) {
        error_log('[AUTH] Welcome email failed for user_id=' . $new_id . ': ' . ($email_res['error'] ?? 'unknown error'));
    }

    echo json_encode(['success' => true, 'message' => 'Account created successfully.']);
    exit;
}

mysqli_stmt_close($insert_stmt);
error_log('[AUTH] register.php: insert failed — ' . mysqli_error($conn));
echo json_encode(['success' => false, 'message' => 'Registration failed. Please try again.']);
exit;
