<?php
require_once __DIR__ . '/../auth/auth_check.php';
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../auth/diet_plan_schema.php';

header('Content-Type: application/json');

$user = get_session_user();
if (!$user) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$plan_id = (int) ($_GET['plan_id'] ?? 0);
$role    = $user['role'];

if ($role === 'user') {
    $stmt = $conn->prepare("SELECT id FROM members WHERE user_id = ? ORDER BY id DESC LIMIT 1");
    $stmt->bind_param('i', $user['id']);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $member_id = $row ? (int) $row['id'] : 0;
} elseif (in_array($role, ['admin', 'trainer'], true)) {
    $member_id = (int) ($_GET['member_id'] ?? 0);
} else {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Forbidden']);
    exit;
}

if ($plan_id <= 0 || $member_id <= 0) {
    echo json_encode(['success' => false, 'error' => 'Invalid plan or member']);
    exit;
}

// Only allow access to a thread the member is actually assigned to
$check = $conn->prepare("SELECT id FROM diet_plan_assignments WHERE plan_id = ? AND member_id = ?");
$check->bind_param('ii', $plan_id, $member_id);
$check->execute();
if ($check->get_result()->num_rows === 0) {
    echo json_encode(['success' => false, 'error' => 'No such plan assignment']);
    exit;
}

$stmt = $conn->prepare("SELECT id, sender_role, message, created_at FROM diet_plan_messages WHERE plan_id = ? AND member_id = ? ORDER BY created_at ASC, id ASC");
$stmt->bind_param('ii', $plan_id, $member_id);
$stmt->execute();
$res = $stmt->get_result();

$messages = [];
while ($m = $res->fetch_assoc()) {
    $messages[] = [
        'id'          => (int) $m['id'],
        'sender_role' => $m['sender_role'],
        'message'     => $m['message'],
        'created_at'  => $m['created_at'],
    ];
}

// Mark the other side's messages as read
$other_role = $role === 'user' ? 'admin' : 'user';
$mark = $conn->prepare("UPDATE diet_plan_messages SET is_read = 1 WHERE plan_id = ? AND member_id = ? AND sender_role = ?");
$mark->bind_param('iis', $plan_id, $member_id, $other_role);
$mark->execute();

echo json_encode(['success' => true, 'messages' => $messages]);
