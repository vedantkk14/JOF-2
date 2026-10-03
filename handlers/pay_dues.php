<?php
/**
 * handlers/pay_dues.php
 * ─────────────────────────────────────────────────────────────────
 * Member submits proof of paying off some or all of an outstanding balance on
 * one of their own plans (POST → JSON). The amount is whatever the member says
 * they're paying THIS time — a member splitting a balance across more than one
 * installment pays however much they have, not necessarily all of it, and can
 * submit again afterward for what's left. This never touches balance_pending
 * directly — it only records the claim as 'pending'; an admin verifies it via
 * handlers/admin_dues.php before the balance actually moves, the same two-step
 * trust model subscribe_payment.php uses for new plans.
 *
 * POST: payment_id, amount, transaction_id, payer_name?, payment_screenshot?
 */

require_once __DIR__ . '/../auth/auth_check.php';
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../auth/dues_schema.php';

header('Content-Type: application/json');

$user = get_session_user();
if (!$user || $user['role'] !== 'user') {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$submitted = $_POST['_csrf_token'] ?? '';
$stored    = $_SESSION['_csrf_token'] ?? '';
if (!$stored || !hash_equals($stored, $submitted)) {
    echo json_encode(['success' => false, 'error' => 'Security token expired. Please refresh the page.']);
    exit;
}

$uid = (int) $user['id'];
$mstmt = $conn->prepare("SELECT id FROM members WHERE user_id = ? ORDER BY id DESC LIMIT 1");
$mstmt->bind_param('i', $uid);
$mstmt->execute();
$member = $mstmt->get_result()->fetch_assoc();
$member_id = $member ? (int) $member['id'] : 0;
if (!$member_id) {
    echo json_encode(['success' => false, 'error' => 'Please complete your profile first.']);
    exit;
}

$payment_id = (int) ($_POST['payment_id'] ?? 0);
$pstmt = $conn->prepare("SELECT * FROM member_payments WHERE payment_id = ? AND member_id = ? AND membership_type != 'Pending Setup' LIMIT 1");
$pstmt->bind_param('ii', $payment_id, $member_id);
$pstmt->execute();
$plan = $pstmt->get_result()->fetch_assoc();
if (!$plan) {
    echo json_encode(['success' => false, 'error' => 'That plan was not found on your account.']);
    exit;
}

$balance = (float) $plan['balance_pending'];
if ($balance <= 0) {
    echo json_encode(['success' => false, 'error' => 'This plan has no balance due.']);
    exit;
}

if (due_payment_pending_for($conn, $payment_id)) {
    echo json_encode(['success' => false, 'error' => 'You already have a payment awaiting verification for this plan.']);
    exit;
}

// How much THIS installment covers — not necessarily the whole balance. A member on
// multiple installments may be splitting what's left across more than one payment
// (e.g. ₹7,000 left, paying ₹5,000 now and ₹2,000 later), so this has to be whatever
// they say they paid, not an assumed full payoff.
$amount = (float) ($_POST['amount'] ?? 0);
if ($amount <= 0 || !is_finite($amount)) {
    echo json_encode(['success' => false, 'error' => 'Enter how much you\'re paying.']);
    exit;
}
// Round to paise. Refuse anything above what's actually left — the server's balance is
// the source of truth, and silently recording a different amount than the member entered
// would put wrong numbers in front of the admin. Client-side max stops this in the form;
// this is the backstop for anything that bypasses it.
$amount = round($amount, 2);
if ($amount > $balance) {
    echo json_encode(['success' => false, 'error' => 'That\'s more than the ₹' . number_format($balance, 0) . ' left on this plan. Enter a smaller amount.']);
    exit;
}

$transaction_id = trim($_POST['transaction_id'] ?? '');
$payer_name     = trim($_POST['payer_name'] ?? '');
if ($transaction_id === '' || !preg_match('/^[A-Za-z0-9]{4,20}$/', $transaction_id)) {
    echo json_encode(['success' => false, 'error' => 'Enter the last 4-6 digits of your UPI transaction reference.']);
    exit;
}

$screenshot_path = null;
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
    $upload_dir = __DIR__ . '/../uploads/dues/';
    if (!is_dir($upload_dir)) {
        mkdir($upload_dir, 0777, true);
    }
    $new_filename = 'due_' . $member_id . '_' . time() . '.' . $ext;
    if (move_uploaded_file($f['tmp_name'], $upload_dir . $new_filename)) {
        $screenshot_path = $new_filename;
    } else {
        echo json_encode(['success' => false, 'error' => 'Could not save the uploaded screenshot. Please try again.']);
        exit;
    }
}

$stmt = $conn->prepare("INSERT INTO member_due_payments (payment_id, member_id, amount, transaction_id, payer_name, screenshot_path)
                         VALUES (?, ?, ?, ?, ?, ?)");
$stmt->bind_param('iidsss', $payment_id, $member_id, $amount, $transaction_id, $payer_name, $screenshot_path);

if ($stmt->execute()) {
    echo json_encode(['success' => true]);
} else {
    echo json_encode(['success' => false, 'error' => 'Could not submit your payment. Please try again.']);
}
