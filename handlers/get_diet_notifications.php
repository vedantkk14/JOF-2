<?php
/**
 * handlers/get_diet_notifications.php
 * ─────────────────────────────────────────────────────────────────
 * Members with unread diet-plan messages, for the "Diet Messages" card on the
 * admin dashboard. One row per member (not per message), newest first.
 * Messages are marked read when the admin opens that member's conversation
 * on diet_messages.php, not here.
 */
require_once __DIR__ . '/../auth/auth_check.php';
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../auth/diet_plan_schema.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['user_role'] ?? '', ['admin', 'trainer'], true)) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}

$res = $conn->query("SELECT dpm.member_id, m.full_name AS member_name,
                            COUNT(*) AS unread, MAX(dpm.id) AS last_id
                     FROM diet_plan_messages dpm
                     JOIN members m ON m.id = dpm.member_id
                     WHERE dpm.sender_role = 'user' AND dpm.is_read = 0
                     GROUP BY dpm.member_id, m.full_name
                     ORDER BY last_id DESC
                     LIMIT 50");

$members = [];
$unread_count = 0;
while ($row = $res->fetch_assoc()) {
    $members[] = [
        'member_id'   => (int) $row['member_id'],
        'member_name' => $row['member_name'],
        'unread'      => (int) $row['unread'],
    ];
    $unread_count += (int) $row['unread'];
}

echo json_encode([
    'status'       => 'success',
    'members'      => $members,
    'unread_count' => $unread_count,
]);
