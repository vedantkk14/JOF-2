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

if (!isset($_SESSION['user_id'])) {
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

    $success = 0;

    foreach ($member_ids as $mid) {
        $mid = intval($mid);

        $stmt_member->bind_param("i", $mid);
        $stmt_member->execute();
        $member = $stmt_member->get_result()->fetch_assoc();

        if (!$member) continue;

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