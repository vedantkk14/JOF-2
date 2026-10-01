<?php
/**
 * handlers/google_calendar_connect.php
 * ─────────────────────────────────────────────────────────────────
 * Starts the "Enable Meal Reminders" flow: a member clicks a button
 * that hits this file, which redirects them to Google's consent
 * screen for Calendar access. Independent of how they logged in
 * (Google Sign-In or plain email/password) — see google_calendar_helper.php.
 */

session_start();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../auth/google_calendar_helper.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../index.php');
    exit;
}

if (GOOGLE_CLIENT_ID === '' || GOOGLE_CLIENT_SECRET === '') {
    http_response_code(500);
    echo 'Google Calendar reminders are not configured on this server yet.';
    exit;
}

// CSRF-style state: bound to this session, verified in the callback so a
// forged callback request can't attach some other Google account's tokens
// to this session.
$state = bin2hex(random_bytes(24));
$_SESSION['google_calendar_state'] = $state;
// Remember where to send them back to after connecting.
$_SESSION['google_calendar_return_to'] = $_SERVER['HTTP_REFERER'] ?? '../templates/user_side/user_diet_plans.php';

header('Location: ' . google_calendar_auth_url($state));
exit;
