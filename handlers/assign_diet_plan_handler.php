<?php
session_start();

error_reporting(E_ALL);
ini_set('display_errors', 1);

header('Content-Type: application/json');

require '../config.php';

if (!file_exists('../auth/send_diet_plan.php')) {
    echo json_encode(['success' => false, 'error' => 'send_diet_plan.php not found']);
    exit;
}

require '../auth/send_diet_plan.php';
require_once '../auth/diet_plan_schema.php';

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['user_role'] ?? '', ['admin', 'trainer'], true)) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$plan_id = intval($_POST['plan_id'] ?? 0);
$member_ids = $_POST['member'] ?? [];

if ($plan_id <= 0) {
    echo json_encode(['success' => false, 'error' => 'Invalid plan']);
    exit;
}

if (empty($member_ids)) {
    echo json_encode(['success' => false, 'error' => 'No members']);
    exit;
}

try {

    if (!function_exists('generateDietPlanPDF')) {
        throw new Exception("PDF function missing");
    }

    $pdf_content = generateDietPlanPDF($plan_id);

    if (!$pdf_content) {
        throw new Exception("PDF generation failed");
    }

    $stmt = $conn->prepare("SELECT plan_name FROM diet_plans WHERE id = ?");
    $stmt->bind_param("i", $plan_id);
    $stmt->execute();
    $plan_row = $stmt->get_result()->fetch_assoc();
    $plan_name = $plan_row['plan_name'] ?? 'Diet Plan';

    $stmt_member = $conn->prepare("SELECT full_name, email FROM members WHERE id = ?");
    $stmt_assign = $conn->prepare("INSERT IGNORE INTO diet_plan_assignments (plan_id, member_id) VALUES (?, ?)");
    $stmt_notify = $conn->prepare("INSERT INTO diet_plan_messages (plan_id, member_id, sender_role, message, is_read) VALUES (?, ?, 'admin', ?, 0)");

    $success = 0;

    foreach ($member_ids as $mid) {
        $mid = intval($mid);

        $stmt_member->bind_param("i", $mid);
        $stmt_member->execute();
        $member = $stmt_member->get_result()->fetch_assoc();

        if (!$member) continue;

        // Persist the assignment regardless of email outcome so the plan
        // shows up on the member's in-app page.
        $stmt_assign->bind_param("ii", $plan_id, $mid);
        $stmt_assign->execute();

        // Surface it in the member's notifications bell too, not just via email.
        if ($stmt_assign->affected_rows > 0) {
            $notify_msg = "A new diet plan (\"{$plan_name}\") has been assigned to you.";
            $stmt_notify->bind_param("iis", $plan_id, $mid, $notify_msg);
            $stmt_notify->execute();
        }

        if (!function_exists('sendDietPlanEmail')) {
            throw new Exception("Email function missing");
        }

        $res = sendDietPlanEmail($member['email'], $member['full_name'], $pdf_content, $plan_name);

        if ($res['success']) $success++;
    }

    echo json_encode([
        "success" => true,
        "message" => "Sent to $success users"
    ]);

} catch (Throwable $e) {
    echo json_encode([
        "success" => false,
        "error" => $e->getMessage()
    ]);
}