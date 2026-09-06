<?php
/**
 * auth/rate_limiter.php
 * ─────────────────────────────────────────────────────────────────
 * Simple IP-based login rate limiter using PHP session storage.
 * Max 5 failed attempts per 15-minute window per IP.
 *
 * Usage (in login.php):
 *   require_once __DIR__ . '/rate_limiter.php';
 *   if (is_rate_limited()) {
 *       echo json_encode(['success'=>false, 'message'=>'Too many attempts...']);
 *       exit;
 *   }
 *   // ... validate credentials ...
 *   if ($login_failed) {
 *       record_failed_attempt();
 *   } else {
 *       clear_failed_attempts();
 *   }
 */

define('RATE_LIMIT_MAX_ATTEMPTS',   5);
define('RATE_LIMIT_WINDOW_SECONDS', 900); // 15 minutes

/**
 * Returns true if the current IP is rate-limited (too many recent failures).
 */
function is_rate_limited(): bool
{
    $key  = _rl_session_key();
    $data = $_SESSION[$key] ?? null;

    if (!$data) {
        return false;
    }

    // If window has expired, auto-clear
    if ((time() - $data['window_start']) > RATE_LIMIT_WINDOW_SECONDS) {
        unset($_SESSION[$key]);
        return false;
    }

    return $data['attempts'] >= RATE_LIMIT_MAX_ATTEMPTS;
}

/**
 * Record one failed login attempt for the current IP.
 */
function record_failed_attempt(): void
{
    $key  = _rl_session_key();
    $data = $_SESSION[$key] ?? ['attempts' => 0, 'window_start' => time()];

    // Reset window if expired
    if ((time() - $data['window_start']) > RATE_LIMIT_WINDOW_SECONDS) {
        $data = ['attempts' => 0, 'window_start' => time()];
    }

    $data['attempts']++;
    $_SESSION[$key] = $data;
}

/**
 * Clear failed attempts for the current IP (on successful login).
 */
function clear_failed_attempts(): void
{
    unset($_SESSION[_rl_session_key()]);
}

/**
 * Returns remaining seconds in the lockout window, or 0 if not locked.
 */
function rate_limit_retry_after(): int
{
    $key  = _rl_session_key();
    $data = $_SESSION[$key] ?? null;
    if (!$data || $data['attempts'] < RATE_LIMIT_MAX_ATTEMPTS) {
        return 0;
    }
    $elapsed = time() - $data['window_start'];
    return max(0, RATE_LIMIT_WINDOW_SECONDS - $elapsed);
}

// ── Internal ──────────────────────────────────────────────────────
function _rl_session_key(): string
{
    // Key is per IP; use a hashed form to avoid storing raw IP in session key
    $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    // Take first IP if comma-separated (proxy chain)
    $ip = trim(explode(',', $ip)[0]);
    return '_rl_' . hash('sha256', $ip);
}
