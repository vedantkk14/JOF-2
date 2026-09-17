<?php
/**
 * auth/auth_check.php
 * ─────────────────────────────────────────────────────────────────
 * Central authentication guard for all protected pages.
 *
 * Usage (at the very top of every protected PHP file):
 *   require_once __DIR__ . '/../auth/auth_check.php';   // from /templates/
 *   require_role(['admin', 'trainer']);
 *
 * Or from /templates/sub-dir/:
 *   require_once __DIR__ . '/../../auth/auth_check.php';
 *   require_role(['counsellor']);
 */

// ── 1. Secure Session Configuration ──────────────────────────────
if (session_status() === PHP_SESSION_NONE) {
    $cookie_params = [
        'lifetime' => 0,                // Browser-session cookie (expires when browser closes)
        'path'     => '/',
        'domain'   => '',               // Current domain only
        'secure'   => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on', // HTTPS only if available
        'httponly' => true,             // No JS access
        'samesite' => 'Strict',         // CSRF mitigation
    ];
    session_set_cookie_params($cookie_params);
    session_start();
}

// ── 2. Session Timeout (30-minute idle) ──────────────────────────
define('SESSION_TIMEOUT_SECONDS', 1800); // 30 minutes

if (isset($_SESSION['user_id'])) {
    $last_activity = $_SESSION['_last_activity'] ?? time();
    if ((time() - $last_activity) > SESSION_TIMEOUT_SECONDS) {
        // Session expired — destroy and redirect
        _auth_destroy_session();
        header('Location: ' . _auth_login_url());
        exit;
    }
    $_SESSION['_last_activity'] = time();
}

// ── 3. Role-based Access Guard ────────────────────────────────────
/**
 * Enforces that the logged-in user has one of the allowed roles.
 * Call immediately after including this file.
 *
 * @param string[] $allowed_roles   e.g. ['admin', 'trainer']
 * @param string   $redirect_to     Override redirect URL (default: login page)
 */
function require_role(array $allowed_roles, string $redirect_to = ''): void
{
    // Not logged in at all
    if (!isset($_SESSION['user_id'], $_SESSION['user_role'])) {
        header('Location: ' . ($redirect_to ?: _auth_login_url()));
        exit;
    }

    $role = $_SESSION['user_role'];

    // Role not in allowed list
    if (!in_array($role, $allowed_roles, true)) {
        // Send to their correct dashboard instead of login
        $correct = _auth_dashboard_url($role);
        header('Location: ' . ($redirect_to ?: $correct));
        exit;
    }
}

// ── 4. CSRF Helpers ───────────────────────────────────────────────
/**
 * Generate (or return existing) CSRF token stored in session.
 */
function generate_csrf_token(): string
{
    if (empty($_SESSION['_csrf_token'])) {
        $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['_csrf_token'];
}

/**
 * Validate the CSRF token submitted in a POST request.
 * Kills request with 403 on failure.
 */
function validate_csrf_token(): void
{
    $submitted = $_POST['_csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    $stored    = $_SESSION['_csrf_token'] ?? '';

    if (!$stored || !hash_equals($stored, $submitted)) {
        http_response_code(403);
        // Regenerate token after failure
        unset($_SESSION['_csrf_token']);
        die(json_encode(['success' => false, 'message' => 'Invalid security token. Please refresh and try again.']));
    }

    // Rotate token after every validated POST for extra security
    unset($_SESSION['_csrf_token']);
}

// ── 5. Helper: Current User Info ──────────────────────────────────
/**
 * Returns an array with the current session user's info, or null.
 *
 * @return array{id:int, name:string, role:string}|null
 */
function get_session_user(): ?array
{
    if (!isset($_SESSION['user_id'])) {
        return null;
    }
    return [
        'id'   => (int) $_SESSION['user_id'],
        'name' => htmlspecialchars($_SESSION['user_name'] ?? '', ENT_QUOTES, 'UTF-8'),
        'role' => $_SESSION['user_role'] ?? '',
    ];
}

// ── 6. Internal Helpers ───────────────────────────────────────────
function _auth_destroy_session(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(), '', time() - 42000,
            $params['path'], $params['domain'],
            $params['secure'], $params['httponly']
        );
    }
    session_destroy();
}

/**
 * Number of "../" needed to get from the currently-executing script's directory
 * back to the project root — computed by comparing real filesystem paths
 * (this file always lives at <project root>/auth/auth_check.php), so it works
 * no matter what the project's folder is actually named or how deeply nested
 * the calling script is. Replaces the old approach of matching a hardcoded
 * list of expected folder names, which silently produced wrong redirect URLs
 * (and 404s) whenever the project directory didn't match one of those names.
 */
function _auth_relative_prefix(): string
{
    $project_root = str_replace('\\', '/', rtrim(dirname(__DIR__), '\\/'));
    $script_path  = $_SERVER['SCRIPT_FILENAME'] ?? '';
    if ($script_path === '') {
        return '../'; // best-effort fallback if the server didn't provide it
    }
    $script_dir = str_replace('\\', '/', rtrim(dirname($script_path), '\\/'));

    $root_parts   = array_values(array_filter(explode('/', $project_root)));
    $script_parts = array_values(array_filter(explode('/', $script_dir)));

    $common = 0;
    while (
        $common < count($root_parts) && $common < count($script_parts)
        && strcasecmp($root_parts[$common], $script_parts[$common]) === 0
    ) {
        $common++;
    }

    $levels_below = count($script_parts) - $common;
    return str_repeat('../', max($levels_below, 0));
}

/**
 * Determine the correct dashboard URL for a given role.
 * Returns a path relative to the currently-executing script.
 */
function _auth_dashboard_url(string $role): string
{
    $prefix = _auth_relative_prefix();

    switch ($role) {
        case 'admin':
        case 'trainer':
            return $prefix . 'templates/dashboard.php';
        case 'user':
            return $prefix . 'templates/user_side/user_dashboard.php';
        case 'counsellor':
            return $prefix . 'templates/counsellor_side/counsellor_dashboard.php';
        default:
            return $prefix . 'index.php';
    }
}

function _auth_login_url(): string
{
    return _auth_relative_prefix() . 'index.php';
}
