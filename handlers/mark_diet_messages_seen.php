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

$conn->query("UPDATE diet_plan_messages SET is_read = 1 WHERE sender_role = 'user' AND is_read = 0");

echo json_encode(['status' => 'success']);
