<?php
require_once __DIR__ . '/../auth/auth_check.php';
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../auth/diet_plan_schema.php';
require_once __DIR__ . '/../auth/send_diet_plan.php';

$user = get_session_user();
if (!$user) {
    http_response_code(401);
    exit('Unauthorized');
}

$plan_id = (int) ($_GET['id'] ?? 0);
if ($plan_id <= 0) {
    http_response_code(400);
    exit('Invalid plan id');
}

$role = $user['role'];

if ($role === 'user') {
    // Ownership check: the plan must actually be assigned to this member
    $uid = (int) $user['id'];
    $mstmt = $conn->prepare("SELECT id FROM members WHERE user_id = ? ORDER BY id DESC LIMIT 1");
    $mstmt->bind_param('i', $uid);
    $mstmt->execute();
    $mrow = $mstmt->get_result()->fetch_assoc();
    $member_id = $mrow ? (int) $mrow['id'] : 0;

    $check = $conn->prepare("SELECT id FROM diet_plan_assignments WHERE plan_id = ? AND member_id = ?");
    $check->bind_param('ii', $plan_id, $member_id);
    $check->execute();
    if ($check->get_result()->num_rows === 0) {
        http_response_code(403);
        exit('Forbidden');
    }
} elseif (!in_array($role, ['admin', 'trainer'], true)) {
    http_response_code(403);
    exit('Forbidden');
}

$pdf_content = generateDietPlanPDF($plan_id);
if (!$pdf_content) {
    http_response_code(500);
    exit('Could not generate PDF');
}

$stmt = $conn->prepare("SELECT plan_name FROM diet_plans WHERE id = ?");
$stmt->bind_param('i', $plan_id);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$plan_name = $row['plan_name'] ?? 'diet_plan';
$filename = preg_replace('/[^A-Za-z0-9_\- ]/', '', $plan_name) ?: 'diet_plan';
$disposition = (!empty($_GET['inline'])) ? 'inline' : 'attachment';

header('Content-Type: application/pdf');
header('Content-Disposition: ' . $disposition . '; filename="' . $filename . '.pdf"');
header('Content-Length: ' . strlen($pdf_content));
header('Cache-Control: private, max-age=0, must-revalidate');
echo $pdf_content;
