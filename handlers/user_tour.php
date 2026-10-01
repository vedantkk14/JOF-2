<?php
/**
 * handlers/user_tour.php
 * ─────────────────────────────────────────────────────────────────
 * Saves the member's place in the first-login walkthrough (POST → JSON).
 *
 *   step=N     → show step N next (the tour spans pages, so it's saved on every move)
 *   step=-1    → finished or skipped
 *   action=restart → start again from the beginning
 *
 * Member accounts only; a member can only ever move their own marker.
 */

require_once __DIR__ . '/../auth/auth_check.php';
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../auth/tour_helper.php';

header('Content-Type: application/json');

$me = get_session_user();
if (!$me || $me['role'] !== 'user') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Not authorised.']);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

// CSRF — validate only (portal pages are long-lived, same as workout_log.php)
$submitted = $_POST['_csrf_token'] ?? '';
$stored    = $_SESSION['_csrf_token'] ?? '';
if (!$stored || !hash_equals($stored, $submitted)) {
    echo json_encode(['success' => false, 'message' => 'Security token expired. Please refresh the page.']);
    exit;
}

$user_id = (int) $me['id'];
$step = ($_POST['action'] ?? '') === 'restart' ? 1 : (int) ($_POST['step'] ?? TOUR_DONE);

set_user_tour_step($conn, $user_id, $step);

echo json_encode(['success' => true, 'step' => max(TOUR_DONE, $step)]);
