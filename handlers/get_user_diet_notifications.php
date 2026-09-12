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

if (!$member_id) {
    echo json_encode(['status' => 'success', 'notifications' => [], 'unread_count' => 0]);
    exit;
}

$stmt = $conn->prepare("SELECT dpm.id, dpm.plan_id, dpm.message, dpm.created_at, dp.plan_name
                         FROM diet_plan_messages dpm
                         JOIN diet_plans dp ON dp.id = dpm.plan_id
                         WHERE dpm.sender_role = 'admin' AND dpm.is_read = 0 AND dpm.member_id = ?
                         ORDER BY dpm.created_at DESC
                         LIMIT 20");
$stmt->bind_param('i', $member_id);
$stmt->execute();
$res = $stmt->get_result();

$notifications = [];
while ($row = $res->fetch_assoc()) {
    $parts = explode(' - ', $row['plan_name']);
    $notifications[] = [
        'id'         => (int) $row['id'],
        'plan_id'    => (int) $row['plan_id'],
        'phase'      => $parts[1] ?? $row['plan_name'],
        'message'    => $row['message'],
        'created_at' => $row['created_at'],
    ];
}

$cstmt = $conn->prepare("SELECT COUNT(*) AS c FROM diet_plan_messages WHERE sender_role = 'admin' AND is_read = 0 AND member_id = ?");
$cstmt->bind_param('i', $member_id);
$cstmt->execute();
$unread_count = (int) ($cstmt->get_result()->fetch_assoc()['c'] ?? 0);

echo json_encode([
    'status'        => 'success',
    'notifications' => $notifications,
    'unread_count'  => $unread_count,
]);
