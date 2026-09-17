<?php
require_once __DIR__ . '/../auth/auth_check.php';
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../auth/diet_plan_schema.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['user_role'] ?? '', ['admin', 'trainer'], true)) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}

$sql = "SELECT dpm.id, dpm.plan_id, dpm.member_id, dpm.message, dpm.created_at,
               m.full_name AS member_name, dp.plan_name
        FROM diet_plan_messages dpm
        JOIN members m ON m.id = dpm.member_id
        JOIN diet_plans dp ON dp.id = dpm.plan_id
        WHERE dpm.sender_role = 'user' AND dpm.is_read = 0
        ORDER BY dpm.created_at DESC
        LIMIT 20";
$res = $conn->query($sql);

$notifications = [];
while ($row = $res->fetch_assoc()) {
    $parts = explode(' - ', $row['plan_name']);
    $notifications[] = [
        'id'          => (int) $row['id'],
        'plan_id'     => (int) $row['plan_id'],
        'member_id'   => (int) $row['member_id'],
        'member_name' => $row['member_name'],
        'phase'       => $parts[1] ?? $row['plan_name'],
        'message'     => $row['message'],
        'created_at'  => $row['created_at'],
    ];
}

$count_res = $conn->query("SELECT COUNT(*) AS c FROM diet_plan_messages WHERE sender_role = 'user' AND is_read = 0");
$unread_count = (int) ($count_res->fetch_assoc()['c'] ?? 0);

echo json_encode([
    'status'        => 'success',
    'notifications' => $notifications,
    'unread_count'  => $unread_count,
]);
