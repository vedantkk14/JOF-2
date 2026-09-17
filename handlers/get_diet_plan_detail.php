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

$plan_id = (int) ($_GET['id'] ?? 0);
if ($plan_id <= 0) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid plan id']);
    exit;
}

$stmt = $conn->prepare("SELECT * FROM diet_plans WHERE id = ?");
$stmt->bind_param('i', $plan_id);
$stmt->execute();
$plan = $stmt->get_result()->fetch_assoc();

if (!$plan) {
    echo json_encode(['status' => 'error', 'message' => 'Plan not found']);
    exit;
}

$parts = explode(' - ', $plan['plan_name']);
$resources = [];
if (!empty($plan['resources'])) {
    $decoded = json_decode($plan['resources'], true);
    if (is_array($decoded)) {
        $resources = $decoded;
    }
}

echo json_encode([
    'status' => 'success',
    'plan'   => [
        'id'         => (int) $plan['id'],
        'client'     => $parts[0] ?? $plan['plan_name'],
        'phase'      => $parts[1] ?? $plan['plan_name'],
        'goal'       => $plan['goal'],
        'diet_type'  => $plan['diet_type'],
        'calories'   => $plan['calories'],
        'duration'   => $plan['duration'],
        'breakfast'  => $plan['breakfast'],
        'lunch'      => $plan['lunch'],
        'snack'      => $plan['snack'],
        'dinner'     => $plan['dinner'],
        'resources'  => $resources,
    ],
]);
