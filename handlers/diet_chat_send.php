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

$plan_id = (int) ($_POST['plan_id'] ?? 0);
$role    = $user['role'];
$message = trim($_POST['message'] ?? '');

if ($role === 'user') {
    $stmt = $conn->prepare("SELECT id FROM members WHERE user_id = ? ORDER BY id DESC LIMIT 1");
    $stmt->bind_param('i', $user['id']);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $member_id  = $row ? (int) $row['id'] : 0;
    $sender_role = 'user';
} elseif (in_array($role, ['admin', 'trainer'], true)) {
    $member_id   = (int) ($_POST['member_id'] ?? 0);
    $sender_role = 'admin';
} else {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Forbidden']);
    exit;
}

if ($plan_id <= 0 || $member_id <= 0 || $message === '') {
    echo json_encode(['success' => false, 'error' => 'Invalid request']);
    exit;
}
if (mb_strlen($message) > 2000) {
    echo json_encode(['success' => false, 'error' => 'Message too long']);
    exit;
}

$check = $conn->prepare("SELECT id FROM diet_plan_assignments WHERE plan_id = ? AND member_id = ?");
$check->bind_param('ii', $plan_id, $member_id);
$check->execute();
if ($check->get_result()->num_rows === 0) {
    echo json_encode(['success' => false, 'error' => 'No such plan assignment']);
    exit;
}

$stmt = $conn->prepare("INSERT INTO diet_plan_messages (plan_id, member_id, sender_role, message) VALUES (?, ?, ?, ?)");
$stmt->bind_param('iiss', $plan_id, $member_id, $sender_role, $message);

if ($stmt->execute()) {
    echo json_encode([
        'success' => true,
        'message' => [
            'id'          => $stmt->insert_id,
            'sender_role' => $sender_role,
            'message'     => $message,
            'created_at'  => date('Y-m-d H:i:s'),
        ],
    ]);
} else {
    echo json_encode(['success' => false, 'error' => 'Failed to send message']);
}
