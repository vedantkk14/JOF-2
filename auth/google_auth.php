<?php
/**
 * auth/google_auth.php
 * ─────────────────────────────────────────────────────────────────
 * Handles "Continue with Google" from templates/registration_page.php.
 *
 * Flow:
 *   1. Google Identity Services renders the button on the page and,
 *      after the user picks an account, hands a signed ID token (JWT)
 *      to a JS callback.
 *   2. The page POSTs that token here as `credential`.
 *   3. We verify the token with Google, then find-or-create a
 *      `user_data` row (role = 'user') and log the person straight in.
 *
 * Returns JSON: { success, message, redirect }
 */

session_start();
require __DIR__ . '/../config.php';
require __DIR__ . '/google_config.php';

header('Content-Type: application/json');

// ── Already logged in ───────────────────────────────────────────
if (isset($_SESSION['user_id'], $_SESSION['user_role'])) {
    echo json_encode(['success' => true, 'redirect' => _google_redirect($_SESSION['user_role'])]);
    exit;
}

// ── Method / config guards ─────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

if (GOOGLE_CLIENT_ID === '') {
    echo json_encode(['success' => false, 'message' => 'Google sign-in is not configured on this server.']);
    exit;
}

// ── CSRF (token issued by registration_page.php) ───────────────
$submitted = $_POST['_csrf_token'] ?? '';
$stored    = $_SESSION['_csrf_token'] ?? '';
if (!$stored || !hash_equals($stored, $submitted)) {
    echo json_encode(['success' => false, 'message' => 'Your session expired. Please refresh the page and try again.']);
    exit;
}

// ── The Google ID token ───────────────────────────────────────
$credential = $_POST['credential'] ?? '';
if ($credential === '') {
    echo json_encode(['success' => false, 'message' => 'No Google credential received.']);
    exit;
}

// ── Verify the token with Google ──────────────────────────────
$claims = google_verify_id_token($credential);
if ($claims === null) {
    echo json_encode(['success' => false, 'message' => 'Could not verify your Google account. Please try again.']);
    exit;
}

// Audience must be OUR client, issuer must be Google, email must be verified
$aud = $claims['aud'] ?? '';
$iss = $claims['iss'] ?? '';
$email_verified = ($claims['email_verified'] ?? 'false');
$email_verified = ($email_verified === true || $email_verified === 'true');

if (!hash_equals(GOOGLE_CLIENT_ID, (string) $aud)) {
    echo json_encode(['success' => false, 'message' => 'Google account could not be validated for this site.']);
    exit;
}
if ($iss !== 'accounts.google.com' && $iss !== 'https://accounts.google.com') {
    echo json_encode(['success' => false, 'message' => 'Invalid Google token issuer.']);
    exit;
}
if (!isset($claims['exp']) || (int) $claims['exp'] < time()) {
    echo json_encode(['success' => false, 'message' => 'Your Google session expired. Please try again.']);
    exit;
}

$email = trim(strtolower($claims['email'] ?? ''));
if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || !$email_verified) {
    echo json_encode(['success' => false, 'message' => 'Your Google account has no verified email address.']);
    exit;
}

$full_name = trim($claims['name'] ?? '');
if ($full_name === '') {
    $full_name = ucfirst(explode('@', $email)[0]);
}
$full_name = mb_substr($full_name, 0, 100);

// ── Find or create the account ───────────────────────────────
$stmt = mysqli_prepare($conn, "SELECT id, full_name, role FROM user_data WHERE email = ? LIMIT 1");
mysqli_stmt_bind_param($stmt, 's', $email);
mysqli_stmt_execute($stmt);
$user = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

$is_new = false;
if (!$user) {
    // No password login for Google accounts — store an unusable random hash
    $random_hash = password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT);
    $ins = mysqli_prepare(
        $conn,
        "INSERT INTO user_data (full_name, email, password, role) VALUES (?, ?, ?, 'user')"
    );
    mysqli_stmt_bind_param($ins, 'sss', $full_name, $email, $random_hash);

    if (mysqli_stmt_execute($ins)) {
        $is_new = true;
        $user = ['id' => mysqli_insert_id($conn), 'full_name' => $full_name, 'role' => 'user'];
    } else {
        // Possible race: another request created it a moment ago — re-select
        mysqli_stmt_execute($stmt);
        $user = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        if (!$user) {
            error_log('[GOOGLE-AUTH] insert failed for ' . $email . ': ' . mysqli_error($conn));
            echo json_encode(['success' => false, 'message' => 'Could not create your account. Please try again.']);
            exit;
        }
    }
}

// ── Log them in ─────────────────────────────────────────────
unset($_SESSION['_csrf_token']);
session_regenerate_id(true);
$_SESSION['user_id']   = (int) $user['id'];
$_SESSION['user_name'] = $user['full_name'];
$_SESSION['user_role'] = $user['role'];

error_log(sprintf(
    '[GOOGLE-AUTH] %s login | user_id=%d | role=%s',
    $is_new ? 'new' : 'existing',
    $user['id'],
    $user['role']
));

// Fire the registration confirmation email for brand-new accounts (best effort)
if ($is_new) {
    require_once __DIR__ . '/send_registration_email.php';
    $mail_res = sendRegistrationEmail($user['full_name'], $email);
    if (!$mail_res['success']) {
        error_log('[GOOGLE-AUTH] welcome email failed for ' . $email . ': ' . ($mail_res['error'] ?? 'unknown'));
    }
}

echo json_encode(['success' => true, 'redirect' => _google_redirect($user['role'])]);
exit;


/* ───────────────────────── helpers ───────────────────────── */

/**
 * Verify a Google ID token via Google's tokeninfo endpoint.
 * Returns the decoded claims array on success, or null on failure.
 */
function google_verify_id_token(string $id_token): ?array
{
    $url = 'https://oauth2.googleapis.com/tokeninfo?id_token=' . urlencode($id_token);
    $body = false;

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($resp !== false && $code === 200) {
            $body = $resp;
        }
    }

    if ($body === false) {
        $ctx = stream_context_create(['http' => ['timeout' => 10, 'ignore_errors' => true]]);
        $resp = @file_get_contents($url, false, $ctx);
        if ($resp !== false && strpos($http_response_header[0] ?? '', '200') !== false) {
            $body = $resp;
        }
    }

    if ($body === false) {
        error_log('[GOOGLE-AUTH] tokeninfo request failed');
        return null;
    }

    $data = json_decode($body, true);
    if (!is_array($data) || isset($data['error']) || empty($data['sub'])) {
        return null;
    }
    return $data;
}

/**
 * Absolute (app-root-relative) dashboard URL for a role, so the value
 * works whether the caller was /index.php or /templates/registration_page.php.
 * Base is derived from this script's own location:
 *   /jof-phase_2/auth/google_auth.php  →  /jof-phase_2
 */
function _google_redirect(string $role): string
{
    $base = str_replace('\\', '/', dirname(dirname($_SERVER['SCRIPT_NAME'] ?? '')));
    $base = ($base === '/' || $base === '.' || $base === '') ? '' : rtrim($base, '/');

    $path = match ($role) {
        'user'       => '/templates/user_side/user_dashboard.php',
        'counsellor' => '/templates/counsellor_side/counsellor_dashboard.php',
        default      => '/templates/dashboard.php',
    };

    return $base . $path;
}
