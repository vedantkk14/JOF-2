<?php
require_once __DIR__ . '/../auth/auth_check.php';
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../auth/profile_helper.php';

header('Content-Type: application/json');

$user = get_session_user();
if (!$user || $user['role'] !== 'user') {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$uid = (int) $user['id'];
$member_id = get_user_member_id($conn, $uid);
if (!$member_id) {
    echo json_encode(['success' => false, 'error' => 'Please complete your profile first.']);
    exit;
}

$plan_id = (int) ($_POST['plan_id'] ?? 0);
$pstmt = $conn->prepare("SELECT plan_name FROM membership_plans WHERE id = ?");
$pstmt->bind_param('i', $plan_id);
$pstmt->execute();
$plan = $pstmt->get_result()->fetch_assoc();
if (!$plan) {
    echo json_encode(['success' => false, 'error' => 'Invalid plan selected.']);
    exit;
}

$transaction_id = trim($_POST['transaction_id'] ?? '');
$payer_name     = trim($_POST['payer_name'] ?? '');
$installments   = (int) ($_POST['installments'] ?? 1);
if (!in_array($installments, [1, 2, 3], true)) {
    $installments = 1;
}

if ($transaction_id === '' || !preg_match('/^[A-Za-z0-9]{4,20}$/', $transaction_id)) {
    echo json_encode(['success' => false, 'error' => 'Enter the last 4-6 digits of your UPI transaction reference.']);
    exit;
}

// Latest payment row for this member, if any (mirrors forms/form4_member_payment_details.php).
// Only reuse/overwrite it if it's still an unverified 'Pending Setup' placeholder — a row
// admin has already confirmed (a real activated plan) must never be touched by a new
// subscribe/renewal request, so that case always inserts a fresh row instead.
$check = $conn->prepare("SELECT * FROM member_payments WHERE member_id = ? ORDER BY payment_id DESC LIMIT 1");
$check->bind_param('i', $member_id);
$check->execute();
$latest = $check->get_result()->fetch_assoc();
$existing = ($latest && $latest['membership_type'] === 'Pending Setup') ? $latest : null;
$screenshot_path = $existing['screenshot_path'] ?? null;

if (!empty($_FILES['payment_screenshot']['name']) && ($_FILES['payment_screenshot']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
    $f = $_FILES['payment_screenshot'];
    $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['jpg', 'jpeg', 'png', 'pdf'], true)) {
        echo json_encode(['success' => false, 'error' => 'Screenshot must be a JPG, PNG, or PDF file.']);
        exit;
    }
    if ($f['size'] > 5 * 1024 * 1024) {
        echo json_encode(['success' => false, 'error' => 'Screenshot must be under 5 MB.']);
        exit;
    }
    $upload_dir = __DIR__ . '/../uploads/payments/';
    if (!is_dir($upload_dir)) {
        mkdir($upload_dir, 0777, true);
    }
    $new_filename = 'pay_' . $member_id . '_' . time() . '.' . $ext;
    if (move_uploaded_file($f['tmp_name'], $upload_dir . $new_filename)) {
        $screenshot_path = $new_filename;
    } else {
        echo json_encode(['success' => false, 'error' => 'Could not save the uploaded screenshot. Please try again.']);
        exit;
    }
}

$payment_mode = 'upi';
$remarks      = 'Member requested: ' . $plan['plan_name'] . ' (' . $installments . ' installment' . ($installments > 1 ? 's' : '') . ')';

if ($existing) {
    $sql = "UPDATE member_payments
            SET payment_mode = ?, transaction_id = ?, payer_name = ?, screenshot_path = ?, remarks = ?, installments_count = ?
            WHERE member_id = ? ORDER BY payment_id DESC LIMIT 1";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('sssssii', $payment_mode, $transaction_id, $payer_name, $screenshot_path, $remarks, $installments, $member_id);
    $ok = $stmt->execute();
} else {
    $membership_type = 'Pending Setup';
    $duration         = 0;
    $start_date       = date('Y-m-d');
    $end_date         = date('Y-m-d');
    $total_amount     = 0;
    $discount         = 0;
    $amount_received  = 0;
    $balance_pending  = 0;
    $next_due_date    = null;

    $sql = "INSERT INTO member_payments
            (member_id, membership_type, duration_months, start_date, end_date,
             total_amount, discount, installments_count, amount_received,
             payment_mode, transaction_id, payer_name, balance_pending, next_due_date, screenshot_path, remarks, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param(
        'isissddidsssdsss',
        $member_id,
        $membership_type,
        $duration,
        $start_date,
        $end_date,
        $total_amount,
        $discount,
        $installments,
        $amount_received,
        $payment_mode,
        $transaction_id,
        $payer_name,
        $balance_pending,
        $next_due_date,
        $screenshot_path,
        $remarks
    );
    $ok = $stmt->execute();
}

if ($ok) {
    // members.status is deliberately left untouched here — an active member's ongoing
    // membership must not be disrupted by submitting a renewal request. Admin sees the
    // pending renewal via templates/inactive_members.php's renewal-aware query instead.
    echo json_encode(['success' => true]);
} else {
    echo json_encode(['success' => false, 'error' => 'Something went wrong saving your payment details. Please try again.']);
}
