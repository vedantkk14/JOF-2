<?php
require_once __DIR__ . '/../auth/auth_check.php';
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../auth/diet_plan_schema.php';

header('Content-Type: application/json');

$user = get_session_user();
if (!$user || $user['role'] !== 'user') {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}

$uid = (int) $user['id'];
$mstmt = $conn->prepare("SELECT id FROM members WHERE user_id = ? ORDER BY id DESC LIMIT 1");
$mstmt->bind_param('i', $uid);
$mstmt->execute();
$mrow = $mstmt->get_result()->fetch_assoc();
$member_id = $mrow ? (int) $mrow['id'] : 0;

if ($member_id) {
    $stmt = $conn->prepare("UPDATE diet_plan_messages SET is_read = 1 WHERE sender_role = 'admin' AND is_read = 0 AND member_id = ?");
    $stmt->bind_param('i', $member_id);
    $stmt->execute();
}

echo json_encode(['status' => 'success']);
