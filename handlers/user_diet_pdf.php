<?php
/**
 * handlers/user_diet_pdf.php
 * ─────────────────────────────────────────────────────────────────
 * Streams a member's cumulative diet-plan PDF to the logged-in portal
 * user. `?plan_id=` picks which phase — the PDF built by
 * generateDietPlanPDF() always includes every earlier phase for that
 * client, exactly like the email attachment the admin sends.
 *
 * A user may only download plans whose client name matches their own
 * linked members.full_name.
 */

require_once __DIR__ . '/../auth/auth_check.php';
require_role(['user']);
$user = get_session_user();

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../auth/profile_helper.php';

$plan_id = (int) ($_GET['plan_id'] ?? 0);
$mid     = get_user_member_id($conn, (int) $user['id']);

if (!$mid || $plan_id <= 0) {
    http_response_code(404);
    exit('Diet plan not found.');
}

// Member's registered name — plans are matched on the "<name> - <phase>" convention
$s = mysqli_prepare($conn, "SELECT full_name FROM members WHERE id = ?");
mysqli_stmt_bind_param($s, 'i', $mid);
mysqli_stmt_execute($s);
$full_name = mysqli_fetch_assoc(mysqli_stmt_get_result($s))['full_name'] ?? '';

// The requested plan must belong to this member
$s = mysqli_prepare(
    $conn,
    "SELECT plan_name FROM diet_plans
     WHERE id = ? AND TRIM(SUBSTRING_INDEX(plan_name, ' - ', 1)) = ?"
);
mysqli_stmt_bind_param($s, 'is', $plan_id, $full_name);
mysqli_stmt_execute($s);
$plan = mysqli_fetch_assoc(mysqli_stmt_get_result($s));

if (!$plan) {
    http_response_code(403);
    exit('This diet plan is not available to your account.');
}

require_once __DIR__ . '/../auth/send_diet_plan.php'; // defines generateDietPlanPDF()

$pdf = generateDietPlanPDF($plan_id);
if (!$pdf) {
    http_response_code(500);
    exit('Could not generate the PDF right now. Please try again.');
}

$fname = 'JOF-DietPlan-' . preg_replace('/[^A-Za-z0-9]+/', '-', trim($full_name) ?: 'plan') . '.pdf';

while (ob_get_level()) {
    ob_end_clean();
}
header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . $fname . '"');
header('Content-Length: ' . strlen($pdf));
header('Cache-Control: private, max-age=0, must-revalidate');
echo $pdf;
