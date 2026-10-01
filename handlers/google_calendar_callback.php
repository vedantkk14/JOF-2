<?php
/**
 * handlers/google_calendar_callback.php
 * ─────────────────────────────────────────────────────────────────
 * Google redirects here after the member approves (or denies) the
 * Calendar consent screen started by google_calendar_connect.php.
 * Exchanges the authorization code for tokens and stores them, then
 * sends the member back to wherever they clicked "Enable" from.
 */

session_start();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../auth/google_calendar_helper.php';

$return_to = $_SESSION['google_calendar_return_to'] ?? '../templates/user_side/user_diet_plans.php';
unset($_SESSION['google_calendar_return_to']);

function _calendar_redirect_with_status(string $url, string $status): void
{
    $sep = str_contains($url, '?') ? '&' : '?';
    header('Location: ' . $url . $sep . 'calendar_status=' . urlencode($status));
    exit;
}

if (!isset($_SESSION['user_id'])) {
    header('Location: ../index.php');
    exit;
}

// Member clicked "Cancel" on Google's consent screen.
if (isset($_GET['error'])) {
    _calendar_redirect_with_status($return_to, 'denied');
}

$state = $_GET['state'] ?? '';
$expected_state = $_SESSION['google_calendar_state'] ?? '';
unset($_SESSION['google_calendar_state']);

if ($state === '' || $expected_state === '' || !hash_equals($expected_state, $state)) {
    error_log('[GOOGLE-CALENDAR] state mismatch for user_id=' . ($_SESSION['user_id'] ?? 0));
    _calendar_redirect_with_status($return_to, 'invalid_state');
}

$code = $_GET['code'] ?? '';
if ($code === '') {
    _calendar_redirect_with_status($return_to, 'no_code');
}

$tokens = google_calendar_exchange_code($code);
if (!$tokens || empty($tokens['access_token'])) {
    error_log('[GOOGLE-CALENDAR] token exchange failed for user_id=' . $_SESSION['user_id']);
    _calendar_redirect_with_status($return_to, 'exchange_failed');
}

if (empty($tokens['refresh_token']) && !google_calendar_is_connected($conn, (int) $_SESSION['user_id'])) {
    // No refresh_token and we don't already have one stored — without it we
    // can't create events later without asking again every hour. This can
    // happen if Google decided not to re-prompt; our auth URL always sends
    // prompt=consent specifically to avoid this, but guard anyway.
    _calendar_redirect_with_status($return_to, 'no_refresh_token');
}

google_calendar_store_tokens($conn, (int) $_SESSION['user_id'], $tokens);

_calendar_redirect_with_status($return_to, 'connected');
