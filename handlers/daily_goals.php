<?php
/**
 * handlers/daily_goals.php
 * ─────────────────────────────────────────────────────────────────
 * Member to-do list endpoint (JSON). POST only, CSRF required, user accounts only.
 *
 *   POST action=add    title=…            → adds a goal for today
 *   POST action=toggle id=…               → flips achieved for one of the user's goals
 *   POST action=delete id=…               → removes one of the user's goals
 *
 * Every response returns { success, message?, stats, today: [...] } so the
 * dashboard can re-render the card and the banner counts from one reply.
 */

require_once __DIR__ . '/../auth/auth_check.php';
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../auth/daily_goals_schema.php';

header('Content-Type: application/json');

function goals_reply(array $payload): void
{
    echo json_encode($payload);
    exit;
}

$me = get_session_user();
if (!$me || $me['role'] !== 'user') {
    http_response_code(403);
    goals_reply(['success' => false, 'message' => 'Not authorised.']);
}
$user_id = (int) $me['id'];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    goals_reply(['success' => false, 'message' => 'Method not allowed.']);
}

// Same non-rotating check as workout_log.php — the dashboard reuses one token for
// every tap on the page, so validate_csrf_token() (which consumes it) would break the second tap.
$submitted = $_POST['_csrf_token'] ?? '';
$stored    = $_SESSION['_csrf_token'] ?? '';
if (!$stored || !hash_equals($stored, $submitted)) {
    http_response_code(403);
    goals_reply(['success' => false, 'message' => 'Invalid security token. Please refresh and try again.']);
}
ensure_daily_goals_schema($conn);

// Dates are server-side in Asia/Kolkata (set in config.php), so "today" matches the dashboard.
$today  = date('Y-m-d');
$action = $_POST['action'] ?? '';

$respond = function (bool $ok, string $message = '') use ($conn, $user_id, $today) {
    goals_reply([
        'success' => $ok,
        'message' => $message,
        'stats'   => daily_goals_stats($conn, $user_id),
        'today'   => daily_goals_for_date($conn, $user_id, $today),
    ]);
};

if ($action === 'add') {
    $title = trim($_POST['title'] ?? '');
    if ($title === '') {
        $respond(false, 'Write a goal first.');
    }
    if (mb_strlen($title) > 255) {
        $title = mb_substr($title, 0, 255);
    }
    $stmt = $conn->prepare("INSERT INTO daily_goals (user_id, goal_date, title) VALUES (?, ?, ?)");
    $stmt->bind_param('iss', $user_id, $today, $title);
    $stmt->execute();
    $respond(true);
}

$goal_id = (int) ($_POST['id'] ?? 0);
if ($goal_id <= 0) {
    $respond(false, 'Missing goal.');
}

// Goals can only be changed on the day they were set; past days are a read-only record
if ($action === 'toggle') {
    // Flip in SQL so two quick taps can't both read the same old value
    $stmt = $conn->prepare("UPDATE daily_goals
                            SET is_achieved = 1 - is_achieved,
                                achieved_at = IF(is_achieved = 0, NOW(), NULL)
                            WHERE id = ? AND user_id = ? AND goal_date = ?");
    $stmt->bind_param('iis', $goal_id, $user_id, $today);
    $stmt->execute();
    $respond($stmt->affected_rows > 0, $stmt->affected_rows > 0 ? '' : 'Goal not found.');
}

if ($action === 'delete') {
    $stmt = $conn->prepare("DELETE FROM daily_goals WHERE id = ? AND user_id = ? AND goal_date = ?");
    $stmt->bind_param('iis', $goal_id, $user_id, $today);
    $stmt->execute();
    $respond($stmt->affected_rows > 0, $stmt->affected_rows > 0 ? '' : 'Goal not found.');
}

http_response_code(400);
$respond(false, 'Unknown action.');
