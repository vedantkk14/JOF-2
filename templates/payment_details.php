<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/../auth/auth_check.php';
require_role(['admin', 'trainer']);
require_once __DIR__ . '/../config.php';

// 2. Flow Check — ONLY accessible via activate_id from inactive members page
if (!isset($_GET['activate_id']) && !isset($_SESSION['activate_member_id'])) {
    header("Location: inactive_members.php");
    exit;
}

if (isset($_GET['activate_id'])) {
    $_SESSION['activate_member_id'] = intval($_GET['activate_id']);
    $_SESSION['new_member_id'] = intval($_GET['activate_id']);
    unset($_SESSION['wizard_plan']);
}

$member_id = $_SESSION['activate_member_id'];
$show_success_modal = false;
$error_msg = '';

// Fetch membership plans for dropdown and pricing
$plans_sql = "SELECT plan_name, price FROM membership_plans ORDER BY price ASC";
$plans_result = $conn->query($plans_sql);

// Fetch Member Name for display
$member_name = "Member";
$name_stmt = $conn->prepare("SELECT full_name FROM members WHERE id = ?");
$name_stmt->bind_param("i", $member_id);
$name_stmt->execute();
$name_res = $name_stmt->get_result();
if ($name_row = $name_res->fetch_assoc()) {
    $member_name = $name_row['full_name'];
}
$name_stmt->close();

// The plan (and installment preference) the member picked when they subscribed via the member
// portal is recorded in member_payments.remarks as "Member requested: <plan name> (<N>
// installment(s))" and installments_count (see handlers/subscribe_payment.php). Surface both here
// so admin can see/pre-select them instead of guessing — still fully editable in case the member
// picked the wrong plan or installment count by mistake.
$requested_plan_name = '';
$requested_installments = 0;
$rp_stmt = $conn->prepare("SELECT remarks, installments_count FROM member_payments WHERE member_id = ? ORDER BY payment_id DESC LIMIT 1");
$rp_stmt->bind_param("i", $member_id);
$rp_stmt->execute();
$rp_row = $rp_stmt->get_result()->fetch_assoc();
$rp_stmt->close();
if ($rp_row && !empty($rp_row['remarks'])) {
    $remarks_trimmed = trim($rp_row['remarks']);
    if (preg_match('/^Member requested:\s*(.+?)\s*\(\d+\s+installments?\)$/i', $remarks_trimmed, $rp_match)
        || preg_match('/^Member requested:\s*(.+)$/i', $remarks_trimmed, $rp_match)) {
        $requested_plan_name = trim($rp_match[1]);
        $requested_installments = (int) ($rp_row['installments_count'] ?? 0);
    }
}

// 3. Handle Form Submission
if ($_SERVER["REQUEST_METHOD"] == "POST") {

    // Retrieve & Sanitize Input
    $membership_type = $_POST['membership_type'] ?? '';
    $duration = intval($_POST['duration'] ?? 1);
    $start_date = $_POST['start_date'];

    // Auto-calculate End Date
    $start_dt_obj = new DateTime($start_date);
    $start_dt_obj->modify("+$duration weeks"); // Changed to weeks to match dropdown options
    $end_date = $start_dt_obj->format('Y-m-d');

    // Financials
    $total_amount = floatval($_POST['total_amount'] ?? 0);
    $discount = floatval($_POST['discount'] ?? 0);
    $installments = intval($_POST['installments'] ?? 1);
    $amount_received = floatval($_POST['amount_received'] ?? 0);

    // Balance Calculation
    $net_total = $total_amount - $discount;
    $balance_pending = max(0, $net_total - $amount_received);

    // Some fields might have arrived in the first form, we only update financials and membership here
    $next_due_date = !empty($_POST['next_due_date']) ? $_POST['next_due_date'] : NULL;
    $remarks = $_POST['remarks'] ?? '';
    $payment_mode = $_POST['payment_mode'] ?? 'Cash';

    // For new activations, the payment_mode dropdown is hidden and defaults to 'Cash'.
    // Read the actual payment_mode saved by member_payments.php from the database instead.
    $activation_type_post = $_GET['type'] ?? 'new';
    if ($activation_type_post === 'new') {
        $pm_check = $conn->query("SELECT payment_mode FROM member_payments WHERE member_id = $member_id ORDER BY payment_id DESC LIMIT 1");
        if ($pm_check && $pm_row = $pm_check->fetch_assoc()) {
            if (!empty($pm_row['payment_mode'])) {
                $payment_mode = $pm_row['payment_mode'];
            }
        }
    }

    // 4.x Fetch existing diet_type from members table
    $diet_type = '';
    $dt_stmt = $conn->prepare("SELECT diet_type FROM members WHERE id = ?");
    $dt_stmt->bind_param("i", $member_id);
    $dt_stmt->execute();
    $dt_res = $dt_stmt->get_result();
    if ($dt_row = $dt_res->fetch_assoc()) {
        $diet_type = $dt_row['diet_type'];
    }
    $dt_stmt->close();

    // 5. Determine INSERT vs UPDATE based on activation type.
    //    For 'renewal': ALWAYS insert a new payment row so that renewal payments
    //    are tracked as separate records and reflected in revenue/reports.
    //    For 'new' (first-time setup): update the placeholder row if one exists
    //    (created earlier in the wizard), otherwise insert fresh.
    $activation_type_post = $_GET['type'] ?? 'new';

    $check_res = $conn->query("SELECT payment_id, payment_mode FROM member_payments WHERE member_id = $member_id ORDER BY payment_id DESC LIMIT 1");
    $existing_payment = $check_res ? $check_res->fetch_assoc() : null;

    // For renewals, force a new INSERT so history is preserved and revenue accumulates
    if ($existing_payment && $activation_type_post !== 'renewal') {
        // UPDATE existing placeholder row (only for first-time 'new' wizard flow)
        $sql = "UPDATE member_payments 
                SET membership_type = ?, 
                    diet_type = ?,
                    duration_months = ?, 
                    start_date = ?, 
                    end_date = ?, 
                    total_amount = ?, 
                    discount = ?, 
                    installments_count = ?, 
                    amount_received = ?, 
                    balance_pending = ?, 
                    next_due_date = ?, 
                    remarks = ?,
                    payment_mode = ?
                WHERE payment_id = ?";
        $stmt = $conn->prepare($sql);
        $pid = $existing_payment['payment_id'];
        $stmt->bind_param(
            "ssissddiddssss",
            $membership_type,
            $diet_type,
            $duration,
            $start_date,
            $end_date,
            $total_amount,
            $discount,
            $installments,
            $amount_received,
            $balance_pending,
            $next_due_date,
            $remarks,
            $payment_mode,
            $pid
        );
    } else {
        // INSERT new payment row:
        //   - Always for 'renewal' type (new renewal = new record)
        //   - Also for 'new' when no prior payment row exists at all
        $sql = "INSERT INTO member_payments 
                (member_id, membership_type, diet_type, duration_months, start_date, end_date, 
                 total_amount, discount, installments_count, amount_received, 
                 balance_pending, next_due_date, remarks, payment_mode)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            die("MySQL prepare error: " . $conn->error . " | SQL: " . $sql);
        }
        $stmt->bind_param(
            // i=member_id s=membership_type s=diet_type i=duration s=start_date s=end_date d=total_amount d=discount i=installments d=amount_received d=balance_pending s=next_due_date s=remarks s=payment_mode
            "ississddiddsss",
            $member_id,
            $membership_type,
            $diet_type,
            $duration,
            $start_date,
            $end_date,
            $total_amount,
            $discount,
            $installments,
            $amount_received,
            $balance_pending,
            $next_due_date,
            $remarks,
            $payment_mode
        );
        // Clear $existing_payment so downstream code uses insert_id for the new row
        $existing_payment = null;
    }

    if ($stmt) {
        if ($stmt->execute()) {
            $new_payment_id = 0;
            if ($existing_payment) {
                $new_payment_id = $existing_payment['payment_id'];
            } else {
                $new_payment_id = $conn->insert_id;
            }

            // === Save Planned Installments ===
            if (isset($_POST['inst_date']) && isset($_POST['inst_amount']) && $installments > 1 && $balance_pending > 0) {
                $dates = $_POST['inst_date'];
                $amounts = $_POST['inst_amount'];

                $conn->query("DELETE FROM planned_installments WHERE payment_id = $new_payment_id");
                $inst_stmt = $conn->prepare("INSERT INTO planned_installments (payment_id, expected_amount, due_date) VALUES (?, ?, ?)");

                if ($inst_stmt) {
                    $first_dueDate = null;
                    for ($i = 0; $i < count($dates); $i++) {
                        $d = $dates[$i];
                        $a = floatval($amounts[$i]);
                        if (empty($first_dueDate))
                            $first_dueDate = $d;
                        $inst_stmt->bind_param("ids", $new_payment_id, $a, $d);
                        $inst_stmt->execute();
                    }
                    if (!empty($first_dueDate)) {
                        $conn->query("UPDATE member_payments SET next_due_date = '$first_dueDate' WHERE payment_id = $new_payment_id");
                    }
                    $inst_stmt->close();
                }
            }

            $mem_sql = "SELECT full_name, email, personal_training FROM members WHERE id = ?";
            $mem_stmt = $conn->prepare($mem_sql);
            $mem_stmt->bind_param("i", $member_id);
            $mem_stmt->execute();
            $member = $mem_stmt->get_result()->fetch_assoc();

            if ($member && $member['personal_training'] == 1) {
                // Fetch the first available trainer
                $trainer_id = 1;
                $t_sql = "SELECT id FROM trainers LIMIT 1";
                if ($t_res = $conn->query($t_sql)) {
                    if ($t_row = $t_res->fetch_assoc())
                        $trainer_id = $t_row['id'];
                }

                // Create an empty PT record
                $pt_sql = "INSERT INTO personal_training (member_id, full_name, trainer_id, total_sessions, pt_fees, sessions_used, start_date, end_date) VALUES (?, ?, ?, 0, 0, 0, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 1 MONTH))";
                if ($pt_stmt = $conn->prepare($pt_sql)) {
                    $pt_stmt->bind_param("isi", $member_id, $member['full_name'], $trainer_id);
                    $pt_stmt->execute();
                    $pt_stmt->close();
                }
            }

            if ($member && !empty($member['email'])) {
                require_once __DIR__ . '/../auth/send_welcome_email.php';
                $email_res = sendWelcomeEmail($member['full_name'], $member['email'], $new_payment_id, [
                    'membership_type' => $membership_type,
                    'duration' => $duration,
                    'total_amount' => $total_amount,
                    'amount_received' => $amount_received,
                    'balance_pending' => $balance_pending,
                    'start_date' => $start_date,
                    'end_date' => $end_date,
                    'payment_mode' => $payment_mode
                ]);

                if (!$email_res['success']) {
                    error_log("Welcome Email Failed for {$member['email']}: " . $email_res['error']);
                }
            }

            // === RENEW PT LOGIC (for renewals) ===
            $activation_type = $_GET['type'] ?? 'new';
            $renew_pt = $_POST['renew_pt'] ?? 'no';

            if ($activation_type === 'renewal' && $renew_pt === 'yes') {
                // Remove from Expired / Move to Pending
                $check_pt = $conn->prepare("SELECT id FROM personal_training WHERE member_id = ?");
                $check_pt->bind_param("i", $member_id);
                $check_pt->execute();
                $pt_res = $check_pt->get_result();

                if ($pt_row = $pt_res->fetch_assoc()) {
                    // Reset existing record to 0 sessions (Pending)
                    $pt_id = $pt_row['id'];
                    $update_pt = $conn->prepare("UPDATE personal_training SET total_sessions = 0, sessions_used = 0, pt_fees = 0, start_date = CURDATE(), end_date = DATE_ADD(CURDATE(), INTERVAL 1 MONTH) WHERE id = ?");
                    $update_pt->bind_param("i", $pt_id);
                    $update_pt->execute();
                    $update_pt->close();
                } else {
                    // No existing record, create a new pending one
                    $trainer_id = 1;
                    $t_sql = "SELECT id FROM trainers LIMIT 1";
                    if ($t_res_inner = $conn->query($t_sql)) {
                        if ($t_row_inner = $t_res_inner->fetch_assoc())
                            $trainer_id = $t_row_inner['id'];
                    }
                    $insert_pt = $conn->prepare("INSERT INTO personal_training (member_id, full_name, trainer_id, total_sessions, pt_fees, sessions_used, start_date, end_date) VALUES (?, ?, ?, 0, 0, 0, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 1 MONTH))");
                    $insert_pt->bind_param("isi", $member_id, $member_name, $trainer_id);
                    $insert_pt->execute();
                    $insert_pt->close();
                }
                $check_pt->close();

                // Update members table: mark as PT opted-in
                $conn->query("UPDATE members SET personal_training = 1 WHERE id = $member_id");

            } elseif ($activation_type === 'renewal' && $renew_pt === 'no') {
                // Member opted OUT of PT during renewal — remove their PT record
                $conn->query("DELETE FROM personal_training WHERE member_id = $member_id");
                // Update members table: mark as PT opted-out
                $conn->query("UPDATE members SET personal_training = 0 WHERE id = $member_id");
            }

            // === ACTIVATE MEMBER ===
            $activate_sql = "UPDATE members SET status = 'active', membership = ?, inactive_date = NULL WHERE id = ?";
            if ($act_stmt = $conn->prepare($activate_sql)) {
                $act_stmt->bind_param("si", $membership_type, $member_id);
                $act_stmt->execute();
                $act_stmt->close();
            }

            unset($_SESSION['new_member_id']);
            $show_success_modal = true;

        } else {
            $error_msg = "Error executing query: " . $stmt->error;
        }
        $stmt->close();
    } else {
        $error_msg = "Database Error: " . $conn->error;
    }
    $conn->close();
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment Details | JOF INDIA</title>
    <link rel="stylesheet" href="../static/root.css">
    <!-- <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"> -->

</head>

<body class="page-metrics">

    <?php if ($show_success_modal): ?>
        <div class="modal-overlay active">
            <div class="modal-card p-32">
                <div class="success-icon-container"><img src="../icons/check-solid-full.svg"
                        class="fa-solid fa-check icon-white" width="24"></div>
                <h2 class="color-dark mb-10">Registration Complete!</h2>
                <p class="color-muted mb-30">Member added, PT package assigned, and invoice emailed.</p>
                <button onclick="redirectToMembers()" class="btn-success">Go to Members page <img
                        src="../icons/arrow-right-solid-full.svg" class="fa-solid fa-arrow-right ml-8 icon-white"
                        width="16"></button>
            </div>
        </div>
    <?php endif; ?>

    <div class="container">
        <div class="card-header">
            <div class="brand-area">
                <img src="../icons/logo-dark(1).png" class="brand-logo" alt="JOF">
                <div class="brand-text">
                    <h2>Payments</h2>
                    <p>Finalizing for: <span class="text-primary fw-bold"><?= htmlspecialchars($member_name) ?></span>
                    </p>
                </div>
            </div>
            <a href="inactive_members.php" class="close-btn"><img src="../icons/xmark-solid-full.svg" alt="close"></a>
        </div>

        <div class="stepper-container">
            <div class="step-item active">
                <div class="step-circle">1</div><span class="step-label">Payments</span>
            </div>
        </div>

        <form action="" method="POST" enctype="multipart/form-data" id="paymentForm">
            <div class="card-body">
                <?php if (!empty($error_msg)): ?>
                    <div class="alert-error"><?php echo $error_msg; ?></div><?php endif; ?>

                <div class="form-grid">
                    <div class="input-group full-width">
                        <h3 class="section-title"><img src="../icons/dumbbell-solid-full.svg"
                                class="fa-solid fa-dumbbell mr-8"> Membership & Diet</h3>
                    </div>

                    <div class="input-group"><label>Membership Type *</label>
                        <div class="input-wrapper"><select name="membership_type" id="membership_type"
                                class="form-input" required>
                                <option value="" disabled <?= $requested_plan_name === '' ? 'selected' : '' ?>>Select Plan</option>
                                <?php
                                if ($plans_result && $plans_result->num_rows > 0) {
                                    while ($plan = $plans_result->fetch_assoc()):
                                        $is_requested = $requested_plan_name !== '' && strcasecmp(trim($plan['plan_name']), $requested_plan_name) === 0;
                                        ?>
                                        <option value="<?= htmlspecialchars($plan['plan_name']) ?>"
                                            data-price="<?= $plan['price'] ?>" <?= $is_requested ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($plan['plan_name']) ?> - ₹<?= number_format($plan['price']) ?>
                                        </option>
                                        <?php
                                    endwhile;
                                } else {
                                    echo '<option value="" disabled>No plans available</option>';
                                }
                                ?>
                            </select><img src="../icons/users-solid-full.svg"
                                class="fa-solid fa-users input-icon text-primary"></div>
                        <?php if ($requested_plan_name !== ''): ?>
                            <p style="font-size:12px;color:#6B7280;margin-top:6px;">
                                📋 Member selected this plan when subscribing — change it above if that was a mistake.
                            </p>
                        <?php endif; ?>
                    </div>
                    <div class="input-group"><label>Duration</label>
                        <div class="input-wrapper"><select name="duration" class="form-input" required>
                                <option value="4">4 WEEKS</option>
                                <option value="8">8 WEEKS</option>
                                <option value="12">12 WEEKS</option>
                                <option value="24">24 WEEKS</option>
                                <option value="52">52 WEEKS</option>
                            </select><img src="../icons/calendar-days-solid-full.svg"
                                class="fa-solid fa-calendar-days input-icon text-purple"></div>
                    </div>
                    <div class="input-group"><label>Start Date</label>
                        <div class="input-wrapper"><input type="date" name="start_date" class="form-input"
                                value="<?php echo date('Y-m-d'); ?>" required></div>
                    </div>

                    <div class="input-group full-width mt-15">
                        <h3 class="section-title d-flex align-center gap-10">
                            <img src="../icons/file-invoice-dollar-solid-full.svg"
                                class="fa-solid fa-file-invoice-dollar"> Transaction Details
                        </h3>
                    </div>

                    <div class="input-group"><label>Total Plan Amount (₹)</label>
                        <div class="input-wrapper"><input type="number" name="total_amount" id="total_amount"
                                class="form-input" placeholder="e.g. 5000" required><img
                                src="../icons/tag-solid-full.svg" class="fa-solid fa-tag input-icon text-muted"></div>
                    </div>
                    <div class="input-group"><label>Discount (Optional)</label>
                        <div class="input-wrapper"><input type="number" name="discount" id="discount" class="form-input"
                                placeholder="0" value="0"><img src="../icons/indian-rupee-sign-solid-full.svg"
                                class="fa-solid fa-indian-rupee-sign input-icon text-danger"></div>
                    </div>
                    <div class="input-group"><label>No. of Installments</label>
                        <div class="input-wrapper"><select name="installments" id="installments" class="form-input">
                                <option value="1" <?= $requested_installments === 1 ? 'selected' : '' ?>>1 (Full Payment)</option>
                                <option value="2" <?= $requested_installments === 2 ? 'selected' : '' ?>>2 Installments</option>
                                <option value="3" <?= $requested_installments === 3 ? 'selected' : '' ?>>3 Installments</option>
                                <option value="4" <?= $requested_installments === 4 ? 'selected' : '' ?>>4 Installments</option>
                            </select><img src="../icons/layer-group-solid-full.svg"
                                class="fa-solid fa-layer-group input-icon text-primary"></div>
                        <?php if ($requested_installments > 1): ?>
                            <p style="font-size:12px;color:#6B7280;margin-top:6px;">
                                📋 Member requested <?= $requested_installments ?> installments — adjust if needed.
                            </p>
                        <?php endif; ?>
                    </div>
                    <div class="input-group"><label>Min. Due Per Installment (₹)</label>
                        <div class="input-wrapper"><input type="text" id="per_installment_view"
                                class="form-input readonly-input" readonly value="0"><img
                                src="../icons/calculator-solid-full.svg"
                                class="fa-solid fa-calculator input-icon text-muted"></div>
                    </div>

                    <?php
                    $activation_type = $_GET['type'] ?? 'new';
                    $payment_mode_style = ($activation_type === 'renewal') ? 'display:block;' : 'display:none;';
                    ?>
                    <div class="input-group" id="payment_mode_container" style="<?= $payment_mode_style ?>">
                        <label>Payment Mode *</label>
                        <div class="input-wrapper">
                            <select name="payment_mode" id="payment_mode" class="form-input">
                                <?php
                                $existing_pm = isset($existing_payment['payment_mode']) ? strtolower($existing_payment['payment_mode']) : 'online';
                                // $pm_cash = ($existing_pm === 'cash') ? 'selected' : '';
                                $pm_online = ($existing_pm === 'online' || $existing_pm === 'upi') ? 'selected' : '';
                                // $pm_bank = ($existing_pm === 'bank transfer' || $existing_pm === 'bank_transfer' || $existing_pm === 'bank') ? 'selected' : '';
                                // $pm_card = ($existing_pm === 'card') ? 'selected' : '';
                                ?>
                                <!-- <option value="Cash" <?= $pm_cash ?>>Cash</option> -->
                                <option value="Online" <?= $pm_online ?>>UPI (GPay/PhonePe/Paytm)</option>
                                <!-- <option value="Bank Transfer" <?= $pm_bank ?>>Bank Transfer</option> -->
                                <!-- <option value="Card" <?= $pm_card ?>>Credit/Debit Card</option> -->
                            </select>
                            <img src="../icons/wallet-solid-full.svg"
                                class="fa-solid fa-wallet input-icon text-primary">
                        </div>
                    </div>

                    <div class="input-group" id="renew_pt_container" style="<?= $payment_mode_style ?>">
                        <label>Renew Personal Training? *</label>
                        <div class="input-wrapper">
                            <select name="renew_pt" id="renew_pt" class="form-input">
                                <option value="no" selected>No</option>
                                <option value="yes">Yes</option>
                            </select>
                            <img src="../icons/dumbbell-solid-full.svg"
                                class="fa-solid fa-dumbbell input-icon text-primary">
                        </div>
                    </div>

                    <div class="input-group full-width" style="border-top: 1px dashed #E5E7EB; margin: 10px 0;"></div>

                    <div class="input-group"><label>Amount Received Now (₹) *</label>
                        <div class="input-wrapper"><input type="number" name="amount_received" id="amount_received"
                                class="form-input highlight-input" placeholder="Enter amount" required><img
                                src="../icons/indian-rupee-sign-solid-full.svg"
                                class="fa-solid fa-indian-rupee-sign input-icon text-success"></div>
                    </div>
                    <div class="input-group"><label>Balance Pending (₹)</label>
                        <div class="input-wrapper"><input type="number" name="balance_pending" id="balance_pending"
                                class="form-input readonly-input" readonly value="0"><img
                                src="../icons/scale-unbalanced-solid-full.svg"
                                class="fa-solid fa-scale-unbalanced input-icon text-danger"></div>
                    </div>
                    <div class="input-group" id="next_due_date_container" style="display:none;"><label>Next Due
                            Date</label>
                        <div class="input-wrapper"><input type="date" name="next_due_date" id="next_due_date"
                                class="form-input"></div>
                    </div>

                    <div id="dynamic_installments" class="full-width"
                        style="grid-column: 1 / -1; display:none; flex-direction:column; gap:15px; margin-top:10px;">
                    </div>

                    <div class="input-group full-width"><label>Remarks / Notes</label>
                        <div class="input-wrapper"><textarea name="remarks" class="form-input" rows="2"
                                placeholder="e.g. Payment promised on..."
                                style="padding-left: 16px; height: auto;"></textarea></div>
                    </div>
                </div>
            </div>
            <div class="card-footer" style="display:flex; justify-content:space-between; align-items:center;">
                <a href="inactive_members.php" class="btn btn-secondary" style="border:1px solid #d1d5db;">Cancel</a>
                <button type="submit" class="btn btn-primary" style="border:none; margin-left:auto;"><img
                        src="../icons/check-circle-solid-full.svg" class="fa-solid fa-check-circle mr-8"> Finish
                    Registration</button>
            </div>
        </form>
    </div>

    <script>
        const totalInput = document.getElementById('total_amount');
        const discountInput = document.getElementById('discount');
        const installmentsInput = document.getElementById('installments');
        const perInstallmentView = document.getElementById('per_installment_view');
        const receivedInput = document.getElementById('amount_received');
        const balanceInput = document.getElementById('balance_pending');
        const dueDateInput = document.getElementById('next_due_date');

        function calculateMetrics() {
            const total = parseFloat(totalInput.value) || 0;
            const discount = parseFloat(discountInput.value) || 0;
            const installments = parseInt(installmentsInput.value) || 1;

            const netTotal = Math.max(0, total - discount);
            const perInstallment = netTotal / installments;
            perInstallmentView.value = perInstallment > 0 ? Math.ceil(perInstallment) : 0;

            const received = parseFloat(receivedInput.value) || 0;
            const balance = Math.max(0, netTotal - received);

            balanceInput.value = balance;

            const count = parseInt(installmentsInput.value) || 1;
            const remainingInstallments = Math.max(1, count - 1);
            const remainingPerInstallment = balance > 0 ? Math.ceil(balance / remainingInstallments) : 0;

            const container = document.getElementById('dynamic_installments');
            const nextDueContainer = document.getElementById('next_due_date_container');

            if (balance > 0) {
                if (count > 1) {
                    nextDueContainer.style.display = 'none';
                    document.getElementById('next_due_date').required = false;
                    container.style.display = 'flex';
                    renderInstallmentFields(count, remainingPerInstallment);
                } else {
                    container.style.display = 'none';
                    container.innerHTML = '';
                    nextDueContainer.style.display = 'block';
                    document.getElementById('next_due_date').style.borderColor = "#F25C2A";
                    document.getElementById('next_due_date').required = true;
                }
            } else {
                nextDueContainer.style.display = 'none';
                document.getElementById('next_due_date').style.borderColor = "#E5E7EB";
                document.getElementById('next_due_date').required = false;
                document.getElementById('next_due_date').value = '';
                container.style.display = 'none';
                container.innerHTML = '';
            }
        }

        function renderInstallmentFields(count, defaultAmount) {
            const container = document.getElementById('dynamic_installments');
            // Only rebuild HTML if the number of inputs differs (to avoid wiping selected dates)
            const currentInputs = container.querySelectorAll('.inst-block').length;
            if (currentInputs === count - 1) {
                // Just update amounts
                const amountInputs = container.querySelectorAll('input[name="inst_amount[]"]');
                amountInputs.forEach(inp => inp.value = defaultAmount);
                return;
            }

            let html = '<h4 style="margin-bottom:5px; color:#1f2937; font-size:14px;"><img src="../icons/calendar-days-solid-full.svg" class="fa-solid fa-calendar-days" style="color:#F25C2A; margin-right:5px; width: 14px; vertical-align: middle;"> Upcoming Installment Dates & Amounts</h4><div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(200px, 1fr)); gap:15px; background:#f9fafb; padding:15px; border-radius:12px; border:1px dashed #d1d5db;">';
            for (let i = 2; i <= count; i++) {
                html += `
                    <div class="inst-block" style="background:#fff; padding:12px; border-radius:8px; border:1px solid #e5e7eb; box-shadow:0 1px 2px rgba(0,0,0,0.03);">
                        <label style="font-size:12px; font-weight:600; color:#4b5563; margin-bottom:5px; display:block;">Inst. ${i} Due Date</label>
                        <input type="date" name="inst_date[]" class="form-input" style="margin-bottom:10px; border-color:#d1d5db;" required>
                        <label style="font-size:12px; font-weight:600; color:#4b5563; margin-bottom:5px; display:block;">Amount (₹)</label>
                        <div style="position:relative;">
                            <img src="../icons/indian-rupee-sign-solid-full.svg" class="fa-solid fa-indian-rupee-sign" style="position:absolute; left:12px; top:50%; transform:translateY(-50%); color:#9ca3af; font-size:12px; width: 10px;">
                            <input type="number" name="inst_amount[]" class="form-input highlight-input" value="${defaultAmount}" style="padding-left:26px;" required placeholder="0">
                        </div>
                    </div>
                `;
            }
            html += '</div>';
            container.innerHTML = html;
        }

        installmentsInput.addEventListener('change', function () {
            calculateMetrics();
        });

        function redirectToMembers() { window.location.href = 'members.php'; }
        totalInput.addEventListener('input', calculateMetrics);
        discountInput.addEventListener('input', calculateMetrics);
        receivedInput.addEventListener('input', calculateMetrics);

        // Auto-fill price when membership is selected
        const membershipTypeSelect = document.getElementById('membership_type');
        membershipTypeSelect.addEventListener('change', function () {
            const selectedOption = this.options[this.selectedIndex];
            const price = selectedOption.getAttribute('data-price');
            if (price) {
                document.getElementById('total_amount').value = price;
                calculateMetrics();
            }
        });
        // If the member's requested plan was pre-selected server-side, fill the price for it too
        if (membershipTypeSelect.value) {
            membershipTypeSelect.dispatchEvent(new Event('change'));
        }
    </script>
    <script>
        function replaceSVG() {
            var images = document.querySelectorAll('img.fa-solid, img.fa-regular, img.fa-brands, img[class*="fa-"]');
            images.forEach(function (img) {
                if (img.classList.contains('svg-replaced')) return;
                img.classList.add('svg-replaced');

                var imgID = img.id;
                var imgClass = img.className;
                var imgURL = img.src;

                if (!imgURL.endsWith('.svg')) return;

                fetch(imgURL)
                    .then(response => response.text())
                    .then(text => {
                        var parser = new DOMParser();
                        var xmlDoc = parser.parseFromString(text, "text/xml");
                        var svg = xmlDoc.getElementsByTagName('svg')[0];

                        if (!svg) return;

                        if (typeof imgID !== 'undefined' && imgID !== '') {
                            svg.setAttribute('id', imgID);
                        }
                        if (typeof imgClass !== 'undefined' && imgClass !== '') {
                            svg.setAttribute('class', imgClass + ' replaced-svg');
                        }

                        svg.removeAttribute('xmlns:a');
                        svg.removeAttribute('width');
                        svg.removeAttribute('height');

                        var paths = svg.querySelectorAll('path');
                        paths.forEach(function (path) {
                            path.setAttribute('fill', 'currentColor');
                        });

                        img.parentNode.replaceChild(svg, img);
                    })
                    .catch(err => console.error('Error fetching SVG:', err));
            });
        }

        replaceSVG();

        var observer = new MutationObserver(function (mutations) {
            var shouldRun = false;
            mutations.forEach(function (mutation) {
                if (mutation.addedNodes.length) {
                    shouldRun = true;
                }
            });
            if (shouldRun) replaceSVG();
        });

        observer.observe(document.body, { childList: true, subtree: true });
    </script>
</body>

</html>