<?php
/**
 * handlers/create_staff_account.php
 * ─────────────────────────────────────────────────────────────────
 * Creates a new staff login account in `user_data`.
 * Called via fetch() from the two "Add Admin" / "Add Counsellor"
 * modals on the admin dashboard top bar.
 *
 *   account_type = 'admin'      → role must be 'admin' or 'trainer'
 *   account_type = 'counsellor' → role is forced to 'counsellor'
 *
 * Admin-only. Returns JSON.
 */

require_once __DIR__ . '/../auth/auth_check.php';
require_once __DIR__ . '/../config.php';

header('Content-Type: application/json');

// ── Auth: admin + trainer may create accounts (same tier app-wide) ─
$me = get_session_user();
if (!$me || !in_array($me['role'], ['admin', 'trainer'], true)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'You are not authorised to create accounts.']);
    exit;
}

// ── Only POST ───────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

// ── CSRF: validate only (do NOT rotate — the dashboard is a long-lived
//    page and the token is shared app-wide; rotating it here would make
//    every already-open dashboard tab fail its next submit) ───────────
$submitted = $_POST['_csrf_token'] ?? '';
$stored    = $_SESSION['_csrf_token'] ?? '';

if (!$stored || !hash_equals($stored, $submitted)) {
    echo json_encode([
        'success' => false,
        'message' => 'Your session has expired. Please refresh the page and try again.',
    ]);
    exit;
}

// ── Input ──────────────────────────────────────────────────────
$account_type = $_POST['account_type'] ?? '';
$full_name    = trim($_POST['full_name'] ?? '');
$email        = trim($_POST['email'] ?? '');
$password     = $_POST['password'] ?? '';
$role         = $_POST['role'] ?? '';

$fail = function (string $msg) {
    echo json_encode(['success' => false, 'message' => $msg]);
    exit;
};

// Resolve + constrain the role based on which form was submitted
if ($account_type === 'counsellor') {
    $role = 'counsellor';
} elseif ($account_type === 'admin') {
    if (!in_array($role, ['admin', 'trainer'], true)) {
        $fail('Please choose a valid role (Admin or Trainer).');
    }
} else {
    $fail('Unknown account type.');
}

// ── Validation ────────────────────────────────────────────────
if ($full_name === '' || $email === '' || $password === '') {
    $fail('All fields are required.');
}
if (mb_strlen($full_name) > 100) {
    $fail('Full name is too long.');
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 150) {
    $fail('Please enter a valid email address.');
}
if (strlen($password) < 8) {
    $fail('Password must be at least 8 characters long.');
}

// ── Uniqueness ───────────────────────────────────────────────
$check = mysqli_prepare($conn, "SELECT id FROM user_data WHERE email = ? LIMIT 1");
mysqli_stmt_bind_param($check, 's', $email);
mysqli_stmt_execute($check);
if (mysqli_fetch_assoc(mysqli_stmt_get_result($check))) {
    $fail('An account with this email already exists.');
}

// ── Insert ──────────────────────────────────────────────────
$hash = password_hash($password, PASSWORD_DEFAULT);
$stmt = mysqli_prepare($conn, "INSERT INTO user_data (full_name, email, password, role) VALUES (?, ?, ?, ?)");
mysqli_stmt_bind_param($stmt, 'ssss', $full_name, $email, $hash, $role);

if (mysqli_stmt_execute($stmt)) {
    $label = ($role === 'counsellor') ? 'Counsellor' : ucfirst($role);
    echo json_encode([
        'success' => true,
        'message' => $label . ' account for "' . $full_name . '" created successfully.',
    ]);
} else {
    error_log('[create_staff_account] insert failed: ' . mysqli_error($conn));
    $fail('Could not create the account. Please try again.');
}
