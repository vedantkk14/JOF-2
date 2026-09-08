<?php
/**
 * handlers/workout_log.php
 * ─────────────────────────────────────────────────────────────────
 * Member portal workout-streak endpoint (JSON).
 *
 *   GET               → { success, stats }
 *   POST date=Y-m-d   → toggles that day, returns { success, logged, date, stats }
 *                       (date defaults to today; CSRF required)
 *
 * User accounts only.
 */

require_once __DIR__ . '/../auth/auth_check.php';
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../auth/workout_helper.php';

header('Content-Type: application/json');

$me = get_session_user();
if (!$me || $me['role'] !== 'user') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Not authorised.']);
    exit;
}
$user_id = (int) $me['id'];

// ── Read current stats ──────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    echo json_encode(['success' => true, 'stats' => workout_streak_stats($conn, $user_id)]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

// ── CSRF (validate only — the dashboard is a long-lived page) ────
$submitted = $_POST['_csrf_token'] ?? '';
$stored    = $_SESSION['_csrf_token'] ?? '';
if (!$stored || !hash_equals($stored, $submitted)) {
    echo json_encode(['success' => false, 'message' => 'Your session expired. Please refresh the page.']);
    exit;
}

// ── Validate the date ──────────────────────────────────────────
$date = $_POST['date'] ?? date('Y-m-d');
$d = DateTime::createFromFormat('Y-m-d', $date);
if (!$d || $d->format('Y-m-d') !== $date) {
    echo json_encode(['success' => false, 'message' => 'Invalid date.']);
    exit;
}
$today = new DateTime('today');
if ($d > $today) {
    echo json_encode(['success' => false, 'message' => "You can't log a workout in the future."]);
    exit;
}
if ($d < (clone $today)->modify('-1 year')) {
    echo json_encode(['success' => false, 'message' => 'That date is too far in the past.']);
    exit;
}

$logged = workout_toggle_day($conn, $user_id, $date);

echo json_encode([
    'success' => true,
    'logged'  => $logged,
    'date'    => $date,
    'stats'   => workout_streak_stats($conn, $user_id),
]);
