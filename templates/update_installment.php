<?php
session_start();
require '../config.php';

// 1. Auth Check
if (!isset($_SESSION['user_id'])) {
    header("Location: ../index.php");
    exit;
}

// 2. Validate ID
if (!isset($_GET['id']) || empty($_GET['id'])) {
    die("Error: No payment record selected.");
}

$payment_id = intval($_GET['id']);
$success_msg = "";
$error_msg = "";

// 3. Handle Form Submission (UPDATE)
if ($_SERVER["REQUEST_METHOD"] == "POST") {

    $installment_amount = floatval($_POST['installment_amount']);
    $payment_mode = $_POST['payment_mode'];
    $transaction_id = $_POST['transaction_id'];
    $next_due_date = !empty($_POST['next_due_date']) ? $_POST['next_due_date'] : NULL;
    $remarks = $_POST['remarks'];

    // FETCH CURRENT DATA First
    $fetch_sql = "SELECT m.full_name, m.email, mp.amount_received, mp.balance_pending, mp.membership_type, mp.duration_months, mp.start_date, mp.end_date, mp.total_amount, mp.discount, mp.installments_count 
                  FROM member_payments mp 
                  JOIN members m ON mp.member_id = m.id 
                  WHERE mp.payment_id = ?";
    $stmt_fetch = $conn->prepare($fetch_sql);
    $stmt_fetch->bind_param("i", $payment_id);
    $stmt_fetch->execute();
    $current_payment = $stmt_fetch->get_result()->fetch_assoc();
    $stmt_fetch->close();

    $total_amount = floatval($current_payment['total_amount']) - floatval($current_payment['discount']);

    $new_amount_received = floatval($current_payment['amount_received']) + $installment_amount;

    // Recalculate Balance
    $new_balance = max(0, $total_amount - $new_amount_received);

    // Validate final installment logic
    $inst_count_sql = "SELECT COUNT(*) as total_installments FROM installment_payments WHERE payment_id = ?";
    $inst_count_stmt = $conn->prepare($inst_count_sql);
    $inst_count_stmt->bind_param("i", $payment_id);
    $inst_count_stmt->execute();
    $inst_count_result = $inst_count_stmt->get_result()->fetch_assoc();
    $paid_installments = $inst_count_result['total_installments'] ?? 0;
    $inst_count_stmt->close();

    if ($current_payment['amount_received'] > 0 || $paid_installments > 0) {
        $paid_installments += 1;
    }

    $pending_installments = max(0, intval($current_payment['installments_count']) - $paid_installments);
    $validation_passed = true;

    if ($pending_installments == 1 && $new_balance > 0 && $installment_amount > 0) {
        $validation_passed = false;
        $error_msg = "This is the final installment. The remaining balance must be paid in full (₹" . number_format($current_payment['balance_pending'], 0) . ").";
    }

    if ($validation_passed) {
        // Handle File Upload
        $screenshot_sql_part = "";
        $params = [$new_amount_received, $new_balance, $payment_mode, $transaction_id, $next_due_date, $remarks];
        $types = "ddssss";

        if (isset($_FILES['payment_screenshot']) && $_FILES['payment_screenshot']['error'] == 0) {
            $upload_dir = "../uploads/payments/";
            if (!is_dir($upload_dir))
                mkdir($upload_dir, 0777, true);

            $file_ext = pathinfo($_FILES['payment_screenshot']['name'], PATHINFO_EXTENSION);
            $new_filename = "payup_" . $payment_id . "_" . time() . "." . $file_ext;

            if (move_uploaded_file($_FILES['payment_screenshot']['tmp_name'], $upload_dir . $new_filename)) {
                $screenshot_sql_part = ", screenshot_path = ?";
                $params[] = $new_filename;
                $types .= "s";
            }
        }

        $params[] = $payment_id;
        $types .= "i";

        $sql = "UPDATE member_payments SET 
            amount_received = ?, 
            balance_pending = ?, 
            payment_mode = ?, 
            transaction_id = ?, 
            next_due_date = ?, 
            remarks = ? 
            $screenshot_sql_part
            WHERE payment_id = ?";

        if ($stmt = $conn->prepare($sql)) {
            $stmt->bind_param($types, ...$params);
            if ($stmt->execute()) {

                // ---> NEW: RECORD THE INSTALLMENT <---
                if ($installment_amount > 0) {
                    $inst_sql = "INSERT INTO installment_payments (payment_id, installment_amount, payment_mode, transaction_id) VALUES (?, ?, ?, ?)";
                    $new_installment_id = 0;
                    if ($inst_stmt = $conn->prepare($inst_sql)) {
                        $inst_stmt->bind_param("idss", $payment_id, $installment_amount, $payment_mode, $transaction_id);
                        $inst_stmt->execute();
                        $new_installment_id = $conn->insert_id;
                        $inst_stmt->close();
                    }
                }

                $success_msg = "Payment details updated successfully!";

                if ($installment_amount > 0) {
                    // Prepare payment data for email
                    $payment_data = array(
                        'payment_mode' => $payment_mode,
                        'membership_type' => $current_payment['membership_type'],
                        'duration_months' => $current_payment['duration_months'],
                        'total_amount' => $current_payment['total_amount'],
                        'amount_received' => $new_amount_received,
                        'balance_pending' => $new_balance,
                        'start_date' => $current_payment['start_date'],
                        'end_date' => $current_payment['end_date'],
                        'discount' => $current_payment['discount']
                    );

                    // Send email
                    require_once __DIR__ . '/../auth/send_installment_email.php';
                    $email_res = sendInstallmentEmail($current_payment['full_name'], $current_payment['email'], $payment_id, $payment_data, $installment_amount, $new_installment_id);

                    if ($email_res['success']) {
                        $success_msg .= " Receipt sent to member successfully!";
                    } else {
                        $error_msg .= " Payment updated, but receipt email failed.";
                    }
                }

            } else {
                $error_msg = "Update failed: " . $stmt->error;
            }
        } else {
            $error_msg = "Database Prepare Error (Update): " . $conn->error;
        }
    } // end validation if
}

// 4. Fetch Existing Data (The part that was failing)
// I removed 'm.phone' just in case that column doesn't exist.
$sql = "SELECT 
            mp.*, 
            m.full_name, 
            m.email
        FROM member_payments mp
        JOIN members m ON mp.member_id = m.id
        WHERE mp.payment_id = ?";

$stmt = $conn->prepare($sql);

// --- DEBUGGING BLOCK: If prepare fails, stop and show the SQL error ---
if ($stmt === false) {
    die("<b>Database Error:</b> " . $conn->error . "<br><b>Query:</b> " . $sql);
}
// ---------------------------------------------------------------------

$stmt->bind_param("i", $payment_id);
$stmt->execute();
$result = $stmt->get_result();
$data = $result->fetch_assoc();

if (!$data)
    die("Payment record not found.");

// 5. Count total installments paid so far
$inst_count_sql = "SELECT COUNT(*) as total_installments FROM installment_payments WHERE payment_id = ?";
$inst_count_stmt = $conn->prepare($inst_count_sql);
$inst_count_stmt->bind_param("i", $payment_id);
$inst_count_stmt->execute();
$inst_count_result = $inst_count_stmt->get_result()->fetch_assoc();
$total_installments = $inst_count_result['total_installments'] ?? 0;
$inst_count_stmt->close();

// The first payment at registration is stored in amount_received but not in installment_payments,
// so count it as installment #1 if any amount was received initially.
if ($data['amount_received'] > 0 || $total_installments > 0) {
    $total_installments += 1;
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" size="16x16" href="../icons/favicon-dark-logo.png" type="image/png">
    <title>Edit Payment | <?= htmlspecialchars($data['full_name']) ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="../static/root.css">

</head>

<body class="page-update_installment">

    <div class="dashboard-container" style="width: 100%;">

        <div class="edit-container">
            <div class="edit-header">
                <div>
                    <h2 style="margin:0; font-size: 20px;">Update Payment</h2>
                    <p style="margin: 5px 0 0; opacity: 0.8; font-size: 13px;">Transaction ID:
                        #<?= $data['payment_id'] ?></p>
                </div>
                <a href="payment_installments.php" style="color: white; text-decoration: none;">
                    <i class="fa-solid fa-xmark" style="font-size: 20px;"></i>
                </a>
            </div>

            <div class="edit-body">

                <?php if ($success_msg): ?>
                    <div class="alert-success">
                        <i class="fa-solid fa-circle-check"></i> <?= $success_msg ?>
                        <a href="payment_installments.php"
                            style="float:right; text-decoration:none; color:#03543F; font-weight:bold;">Back to List</a>
                    </div>
                <?php endif; ?>

                <?php if ($error_msg): ?>
                    <div class="alert-error">
                        <i class="fa-solid fa-circle-exclamation"></i> <?= $error_msg ?>
                    </div>
                <?php endif; ?>

                <div class="info-grid">
                    <div class="info-item">
                        <label>Member Name</label>
                        <span><?= htmlspecialchars($data['full_name']) ?></span>
                    </div>
                    <div class="info-item">
                        <label>Membership Plan</label>
                        <span><?= htmlspecialchars(ucfirst($data['membership_type'])) ?>
                            (<?= $data['duration_months'] ?> Wks)</span>
                    </div>
                    <div class="info-item">
                        <label>Total Plan Amount</label>
                        <span style="color:#111827;">₹<?= number_format($data['total_amount']) ?></span>
                    </div>
                    <div class="info-item">
                        <label>Start Date</label>
                        <span><?= date("d M, Y", strtotime($data['start_date'])) ?></span>
                    </div>
                </div>

                <div class="info-grid" style="margin-top: -10px;">
                    <div class="info-item">
                        <label>Total Previously Received</label>
                        <span style="color:#10B981;">₹<?= number_format($data['amount_received']) ?></span>
                    </div>
                    <div class="info-item">
                        <label>Balance Pending</label>
                        <span style="color:#EF4444;">₹<?= number_format($data['balance_pending']) ?></span>
                    </div>
                    <div class="info-item">
                        <label>Installments Paid So Far</label>
                        <span style="color:#6366F1; font-size:18px;"><i class="fa-solid fa-receipt"
                                style="margin-right:4px;"></i>
                            <?= $total_installments ?>
                        </span>
                    </div>
                    <?php
                    $installments_count_display = max(1, intval($data['installments_count']));
                    $pending_display = max(0, $installments_count_display - $total_installments);
                    ?>
                    <div class="info-item">
                        <label>Installments Pending</label>
                        <span style="color:#F59E0B; font-size:18px;"><i class="fa-solid fa-hourglass-half"
                                style="margin-right:4px;"></i>
                            <?= $pending_display ?>
                        </span>
                    </div>
                </div>

                <form method="POST" enctype="multipart/form-data">
                    <div class="form-grid">

                        <input type="hidden" name="total_amount_hidden"
                            value="<?= $data['total_amount'] - $data['discount'] ?>">
                        <input type="hidden" id="current_amount_received" value="<?= $data['amount_received'] ?>">

                        <?php
                        // Calculate the pre-decided per-installment amount
                        $installments_count = max(1, intval($data['installments_count']));
                        $net_total = floatval($data['total_amount']) - floatval($data['discount']);
                        $per_installment = ceil($net_total / $installments_count);
                        // Cap at remaining balance so we don't overshoot
                        $prefill_amount = min($per_installment, floatval($data['balance_pending']));
                        ?>

                        <div class="form-group">
                            <label>Next Installment Amount (₹)</label>
                            <input type="number" name="installment_amount" id="installment_amount" class="form-input"
                                value="<?= number_format($prefill_amount, 0, '', '') ?>" required
                                oninput="calculatePending()">
                            <small style="color:#6B7280; font-size:11px;">Per installment: ₹
                                <?= number_format($per_installment) ?> (
                                <?= $installments_count ?> installments). Adjust if needed.
                            </small>
                        </div>

                        <div class="form-group">
                            <label>Balance Pending</label>
                            <input type="text" id="balance_view" class="form-input"
                                value="₹<?= number_format($data['balance_pending']) ?>" style="background:#f3f4f6;">
                        </div>

                        <div class="form-group">
                            <label>Payment Mode</label>
                            <select name="payment_mode" id="payment_mode" class="form-input"
                                onchange="togglePaymentModeFields()">
                                <option value="upi" <?= $data['payment_mode'] == 'upi' ? 'selected' : '' ?>>UPI</option>
                                <option value="cash" <?= $data['payment_mode'] == 'cash' ? 'selected' : '' ?>>Cash</option>
                                <option value="bank" <?= $data['payment_mode'] == 'bank' ? 'selected' : '' ?>>Bank Transfer
                                </option>
                            </select>
                        </div>

                        <div class="form-group" id="due-date-group">
                            <label>Next Due Date</label>
                            <input type="date" name="next_due_date" class="form-input"
                                value="<?= $data['next_due_date'] ?>">
                        </div>

                        <div class="form-group full-width" id="txn-id-group">
                            <label>Transaction ID / Reference</label>
                            <input type="text" name="transaction_id" class="form-input"
                                value="<?= htmlspecialchars($data['transaction_id']) ?>">
                        </div>

                        <div class="form-group full-width" id="screenshot-group">
                            <label>Update Screenshot (Optional)</label>
                            <input type="file" name="payment_screenshot" class="form-input" accept="image/*">
                            <?php if (!empty($data['screenshot_path'])): ?>
                                <div class="screenshot-box">
                                    <small>Current file:</small><br>
                                    <a href="../uploads/payments/<?= $data['screenshot_path'] ?>" target="_blank">
                                        <img src="../uploads/payments/<?= $data['screenshot_path'] ?>" alt="Receipt">
                                    </a>
                                </div>
                            <?php endif; ?>
                        </div>

                        <div class="form-group full-width">
                            <label>Remarks</label>
                            <textarea name="remarks" class="form-input"
                                rows="2"><?= htmlspecialchars($data['remarks']) ?></textarea>
                        </div>

                    </div>

                    <div style="margin-top: 25px;">
                        <button type="submit" class="btn-update">
                            <i class="fa-solid fa-floppy-disk"></i> Update Payment Record
                        </button>
                    </div>
                </form>

            </div>
        </div>
    </div>

    <script>
        function calculatePending() {
            // Get Total (Net of Discount)
            const total = <?= $data['total_amount'] - $data['discount'] ?>;
            const currentReceived = parseFloat(document.getElementById('current_amount_received').value) || 0;
            const installmentInput = document.getElementById('installment_amount');
            const balanceView = document.getElementById('balance_view');
            const dueDateGroup = document.getElementById('due-date-group');

            let installment = parseFloat(installmentInput.value) || 0;
            let totalReceived = currentReceived + installment;
            let pending = Math.max(0, total - totalReceived);
            let pending_display = <?= $pending_display ?>;

            balanceView.value = "₹" + pending.toLocaleString('en-IN');

            if (pending === 0) {
                balanceView.style.color = "#10B981";
                balanceView.style.fontWeight = "bold";
                balanceView.value += " (Paid)";
                // Hide next due date when fully paid
                dueDateGroup.style.display = "none";
            } else {
                balanceView.style.color = "#EF4444";
                balanceView.style.fontWeight = "";

                // Hide next due date if this is the final installment (even if balance remains, they can't schedule it)
                if (pending_display === 1) {
                    dueDateGroup.style.display = "none";
                } else {
                    dueDateGroup.style.display = "";
                }
            }
        }

        function togglePaymentModeFields() {
            const mode = document.getElementById('payment_mode').value;
            const txnGroup = document.getElementById('txn-id-group');
            const screenshotGroup = document.getElementById('screenshot-group');

            if (mode === 'cash') {
                // Hide transaction ID and screenshot for cash payments
                txnGroup.style.display = 'none';
                screenshotGroup.style.display = 'none';
            } else {
                // Show for UPI / Bank Transfer
                txnGroup.style.display = '';
                screenshotGroup.style.display = '';
            }
        }

        // Run on page load to set correct initial state
        document.addEventListener('DOMContentLoaded', function () {
            calculatePending();
            togglePaymentModeFields();
        });
    </script>

</body>

</html>