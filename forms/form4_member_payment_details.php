<?php
session_start();
require '../config.php';

// PHPMailer includes
require __DIR__ . '/../auth/PHPMailer/Exception.php';
require __DIR__ . '/../auth/PHPMailer/PHPMailer.php';
require __DIR__ . '/../auth/PHPMailer/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// // 1. Security Check
// if (!isset($_SESSION['user_id'])) {
//     header("Location: ../templates/login_page.html");
//     exit;
// }

// Handle Success Modal via GET (PRG Pattern)
$show_success_modal = false;
if (isset($_GET['success']) && $_GET['success'] == 1) {
    if (isset($_SESSION['show_success_step4'])) {
        $show_success_modal = true;
        unset($_SESSION['show_success_step4']);
        $member_id = 0;
    } else {
        header("Location: form0_welcome_user.php");
        exit;
    }
} else {
    // Enforce sequential access
    if (!isset($_SESSION['allowed_step']) || $_SESSION['allowed_step'] < 4) {
        header("Location: form3_member_measurements.php");
        exit;
    }

    // 2. Flow Check
    if (!isset($_SESSION['new_member_id'])) {
        header("Location: form1_add_member.php");
        exit;
    }
    $member_id = $_SESSION['new_member_id'];
}

$error_msg = '';

// Fetch existing data (if edit-on-back)
$payment_mode = '';
$transaction_id = '';
$payer_name = '';
$existing_screenshot = NULL;
$payment_record_exists = false;

if (!$show_success_modal) {
    $check_sql = "SELECT * FROM member_payments WHERE member_id = ? ORDER BY payment_id DESC LIMIT 1";
    if ($check_stmt = $conn->prepare($check_sql)) {
        $check_stmt->bind_param("i", $member_id);
        $check_stmt->execute();
        $check_res = $check_stmt->get_result();
        if ($row = $check_res->fetch_assoc()) {
            $payment_record_exists = true;
            $payment_mode = $row['payment_mode'];
            $transaction_id = $row['transaction_id'];
            $payer_name = $row['payer_name'];
            $existing_screenshot = $row['screenshot_path'];
        }
        $check_stmt->close();
    }
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $payment_mode = $_POST['payment_mode'] ?? '';
    $transaction_id = $_POST['transaction_id'] ?? '';
    $payer_name = $_POST['payer_name'] ?? '';

    if (empty($payment_mode)) {
        $error_msg = "Please select a payment mode.";
    } else {

        // File upload logic for screenshot
        $screenshot_path = $existing_screenshot; // Keep existing if no new one
        if (isset($_FILES['payment_screenshot']) && $_FILES['payment_screenshot']['error'] == 0) {
            $upload_dir = "../uploads/payments/";
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0777, true);
            }
            $file_ext = pathinfo($_FILES['payment_screenshot']['name'], PATHINFO_EXTENSION);
            $new_filename = "pay_" . $member_id . "_" . time() . "." . $file_ext;
            if (move_uploaded_file($_FILES['payment_screenshot']['tmp_name'], $upload_dir . $new_filename)) {
                $screenshot_path = $new_filename;
            }
        }

        // Dummy data for removed fields so the DB insertion works if columns are NOT NULL
        $membership_type = 'Pending Setup';
        $duration = 0;
        $start_date = date('Y-m-d'); // Use current date instead of NULL
        $end_date = date('Y-m-d'); // Use current date instead of NULL
        $total_amount = 0;
        $discount = 0;
        $installments = 1;
        $amount_received = 0;
        $balance_pending = 0;
        $next_due_date = NULL;
        $remarks = NULL;

        if ($payment_record_exists) {
            $sql = "UPDATE member_payments 
                SET payment_mode=?, transaction_id=?, payer_name=?, screenshot_path=?
                WHERE member_id=? ORDER BY payment_id DESC LIMIT 1";
            if ($stmt = $conn->prepare($sql)) {
                $stmt->bind_param("ssssi", $payment_mode, $transaction_id, $payer_name, $screenshot_path, $member_id);
                if ($stmt->execute()) {
                    sendRegistrationCompleteEmail($member_id, $conn);
                    unset($_SESSION['new_member_id']);
                    unset($_SESSION['allowed_step']);
                    $_SESSION['show_success_step4'] = true;
                    header("Location: form4_member_payment_details.php?success=1");
                    exit;
                } else {
                    $error_msg = "Failed to update details: " . $stmt->error;
                }
                $stmt->close();
            } else {
                $error_msg = "Database Error: " . $conn->error;
            }
        } else {
            $sql = "INSERT INTO member_payments 
                (member_id, membership_type, duration_months, start_date, end_date, 
                 total_amount, discount, installments_count, amount_received, 
                 payment_mode, transaction_id, payer_name, balance_pending, next_due_date, screenshot_path, remarks, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";

            if ($stmt = $conn->prepare($sql)) {
                $stmt->bind_param(
                    "isissddidsssdsss",
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

                if ($stmt->execute()) {
                    sendRegistrationCompleteEmail($member_id, $conn);
                    unset($_SESSION['new_member_id']);
                    unset($_SESSION['allowed_step']);
                    $_SESSION['show_success_step4'] = true;
                    header("Location: form4_member_payment_details.php?success=1");
                    exit;
                } else {
                    $error_msg = "Failed to save details: " . $stmt->error;
                }
                $stmt->close();
            } else {
                $error_msg = "Database Error: " . $conn->error;
            }
        }
    }
}

function sendRegistrationCompleteEmail($member_id, $conn)
{
    $m_sql = "SELECT full_name, email FROM members WHERE id = ?";
    if ($m_stmt = $conn->prepare($m_sql)) {
        $m_stmt->bind_param("i", $member_id);
        $m_stmt->execute();
        $m_res = $m_stmt->get_result();
        if ($m_row = $m_res->fetch_assoc()) {
            $m_name = $m_row['full_name'];
            $m_email = $m_row['email'];
            if (!empty($m_email)) {
                require_once __DIR__ . '/../auth/send_registration_email.php';
                $result = sendRegistrationEmail($m_name, $m_email);
                if (!$result['success']) {
                    error_log('Registration Email Error: ' . $result['error']);
                }
            }
        }
        $m_stmt->close();
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" size="16x16" href="../icons/favicon-dark-logo.png" type="image/png">
    <title>JOF INDIA | Member Payment Details</title>
    <link rel="stylesheet" href="../static/root.css">
    <style>
        .page-metrics .container {
            overflow: visible;
        }

        .page-metrics .card-body {
            overflow: visible;
        }

        .page-metrics .input-wrapper select.form-input {
            appearance: none;
            -webkit-appearance: none;
            -moz-appearance: none;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            padding-right: 40px;
            max-width: 100%;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%236B7280' d='M6 8.825a.75.75 0 0 1-.53-.22L1.22 4.36a.75.75 0 1 1 1.06-1.06L6 7.02l3.72-3.72a.75.75 0 1 1 1.06 1.06L6.53 8.61a.75.75 0 0 1-.53.22Z'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 14px center;
            background-size: 12px;
        }

        @media (max-width: 768px) {
            .page-metrics .input-wrapper select.form-input {
                font-size: 13px;
                padding: 12px 36px 12px 42px;
            }
        }

        /* === FIX: Success Modal Match Reference === */
        .f4-modal-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.45);
            backdrop-filter: blur(6px);
            display: flex;
            justify-content: center;
            align-items: center;
            z-index: 9999;
            animation: f4FadeIn 0.3s ease;
            padding: 20px;
        }

        .f4-modal-card {
            background: #ffffff;
            border-radius: 20px;
            padding: 44px 36px 36px;
            text-align: center;
            width: 100%;
            max-width: 400px;
            box-shadow: 0 25px 60px rgba(0, 0, 0, 0.18), 0 0 0 1px rgba(0, 0, 0, 0.04);
            animation: f4ScaleIn 0.35s cubic-bezier(0.34, 1.56, 0.64, 1) forwards;
            transform: scale(0.85);
        }

        .f4-icon-circle {
            width: 88px;
            height: 88px;
            border-radius: 50%;
            background: radial-gradient(circle, #e0f5e9 0%, #c6efd4 50%, #ddf5e5 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 24px;
            box-shadow: 0 0 0 12px rgba(200, 240, 215, 0.35);
        }

        .f4-icon-circle svg {
            width: 36px;
            height: 36px;
            color: #22a65b;
            stroke: #22a65b;
            fill: none;
            stroke-width: 3;
            stroke-linecap: round;
            stroke-linejoin: round;
        }

        .f4-modal-card h2 {
            font-size: 22px;
            font-weight: 700;
            color: #1a1a1a;
            margin: 0 0 8px;
            font-family: 'Poppins', sans-serif;
        }

        .f4-modal-card p {
            font-size: 14px;
            color: #6b7280;
            margin: 0 0 28px;
            line-height: 1.5;
            font-family: 'Poppins', sans-serif;
        }

        .f4-btn-success {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            width: 100%;
            padding: 15px 24px;
            background: #F25C2A;
            color: #ffffff;
            border: none;
            border-radius: 12px;
            font-size: 15px;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.2s ease, transform 0.15s ease, box-shadow 0.2s ease;
            box-shadow: 0 4px 14px rgba(242, 92, 42, 0.35);
            font-family: 'Poppins', sans-serif;
        }

        .f4-btn-success:hover {
            background: #d64615;
            transform: translateY(-1px);
            box-shadow: 0 6px 20px rgba(242, 92, 42, 0.45);
        }

        .f4-btn-success:active {
            transform: translateY(0);
        }

        .f4-btn-success svg {
            width: 16px;
            height: 16px;
            fill: #ffffff;
            flex-shrink: 0;
        }

        @keyframes f4FadeIn {
            from {
                opacity: 0;
            }

            to {
                opacity: 1;
            }
        }

        @keyframes f4ScaleIn {
            from {
                transform: scale(0.85);
                opacity: 0;
            }

            to {
                transform: scale(1);
                opacity: 1;
            }
        }

        /* === FORM4 MOBILE RESPONSIVENESS === */
        .page-metrics .left-panel,
        .page-metrics .right-panel,
        .page-metrics .input-wrapper,
        .page-metrics .form-input,
        .page-metrics .input-group,
        .page-metrics .form-grid {
            box-sizing: border-box;
        }

        .page-metrics .qr-display-area img {
            max-width: 100%;
            height: auto;
        }

        @media (max-width: 768px) {
            .page-metrics .container {
                max-width: 100%;
                margin: 10px;
            }

            .page-metrics .form-grid {
                display: flex !important;
                flex-direction: column !important;
                gap: 20px;
            }

            .page-metrics .left-panel,
            .page-metrics .right-panel {
                width: 100% !important;
                min-width: 0;
            }

            .page-metrics .right-panel.qr-display-area {
                padding: 25px 20px !important;
                margin-top: 5px !important;
                text-align: center !important;
                align-items: center !important;
                justify-content: center !important;
                display: flex !important;
                flex-direction: column !important;
            }

            .page-metrics .qr-display-area img[alt="Payment QR Code"] {
                width: 100% !important;
                max-width: 320px !important;
                margin: 0 auto;
            }

            .page-metrics .card-body {
                padding: 20px !important;
            }

            .page-metrics .card-footer {
                padding: 20px !important;
                flex-direction: column-reverse !important;
                gap: 12px;
            }

            .page-metrics .card-footer .btn {
                width: 100% !important;
                margin-left: 0 !important;
                text-align: center;
                justify-content: center;
            }

            .page-metrics .form-input {
                font-size: 13px;
                padding: 12px 14px 12px 40px;
                max-width: 100%;
            }

            .page-metrics .file-upload-wrapper {
                padding: 15px !important;
            }

            .page-metrics .stepper-container {
                padding: 0 10px;
            }
        }

        @media (max-width: 480px) {
            .page-metrics .container {
                margin: 8px !important;
            }

            .page-metrics .card-header {
                padding: 20px !important;
            }

            .page-metrics .brand-text h2 {
                font-size: 20px;
            }

            .page-metrics .qr-display-area img[alt="Payment QR Code"] {
                width: 100% !important;
                max-width: 100% !important;
                margin: 0 auto;
            }

            .qr-code-wrapper {
                max-width: 100% !important;
                width: 100% !important;
                padding: 4px !important;
            }

            .page-metrics .section-title {
                font-size: 16px !important;
            }

            .mobile-only-btn {
                display: flex !important;
            }

            .desktop-only-btn {
                display: none !important;
            }

            /* Adjust padding to look nicer on mobile for right panel */
            .page-metrics .right-panel.qr-display-area {
                padding: 20px 15px !important;
            }
        }

        .mobile-only-btn {
            display: none !important;
        }
    </style>

</head>

<body class="page-metrics">

    <div id="lockdown-overlay"
        style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.35); backdrop-filter: blur(8px); -webkit-backdrop-filter: blur(8px); z-index: 99999; align-items: center; justify-content: center; padding: 20px; box-sizing: border-box;">
        <div
            style="background: white; padding: 40px; border-radius: 20px; text-align: center; box-shadow: 0 20px 40px rgba(0,0,0,0.1); max-width: 400px; margin: 20px; animation: f4ScaleIn 0.35s cubic-bezier(0.34, 1.56, 0.64, 1) forwards;">
            <div
                style="width: 70px; height: 70px; background: #e0f5e9; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 20px;">
                <svg viewBox="0 0 24 24"
                    style="width: 34px; height: 34px; stroke: #22a65b; fill: none; stroke-width: 3; stroke-linecap: round; stroke-linejoin: round;">
                    <polyline points="20 6 9 17 4 12"></polyline>
                </svg>
            </div>
            <h2
                style="margin: 0 0 10px; font-family: 'Poppins', sans-serif; color: #111827; font-size: 22px; line-height: 1.2;">
                Registration Successful</h2>
            <p
                style="margin: 0; color: #6b7280; font-family: 'Poppins', sans-serif; font-size: 14px; line-height: 1.5;">
                Your data has already been recorded securely. You may safely close this window.</p>
        </div>
    </div>

    <script>
        <?php if (!$show_success_modal): ?>
            // Fresh legitimate load — clear any stale registration-completed flag from a previous session
            sessionStorage.removeItem('jof_registration_completed');
        <?php endif; ?>

        window.addEventListener("pageshow", function (event) {
            <?php if ($show_success_modal): ?>
                // Only re-show lockdown if navigating back AFTER success
                if (event.persisted && sessionStorage.getItem('jof_registration_completed') === 'true') {
                    document.getElementById('lockdown-overlay').style.display = 'flex';
                    document.body.style.overflow = 'hidden';
                }
            <?php endif; ?>
        });
    </script>

    <?php if ($show_success_modal): ?>
        <script>
            // Set the completion flag so if they use 'Back', they see the blur lockdown
            sessionStorage.setItem('jof_registration_completed', 'true');
        </script>
        <div class="f4-modal-overlay" id="success_modal_overlay">
            <div class="f4-modal-card">
                <div class="f4-icon-circle">
                    <svg viewBox="0 0 24 24">
                        <polyline points="20 6 9 17 4 12"></polyline>
                    </svg>
                </div>
                <h2 style="color: #22a65b;">Registration Complete!</h2>
                <p style="margin-bottom: 12px; font-weight: 500; color: #111827;">Your profile has been created and your
                    details have been saved successfully.</p>
                <p style="margin-bottom: 28px; font-size: 13px;">You will be automatically redirected to our main website
                    shortly to explore more.</p>
                <button onclick="window.location.href='https://jofindia.com/'" class="f4-btn-success" id="redirect_btn">
                    Continue to Main Site (<span id="countdown_timer">5</span>s)
                    <svg viewBox="0 0 448 512">
                        <path
                            d="M438.6 278.6c12.5-12.5 12.5-32.8 0-45.3l-160-160c-12.5-12.5-32.8-12.5-45.3 0s-12.5 32.8 0 45.3L338.7 224H32c-17.7 0-32 14.3-32 32s14.3 32 32 32h306.7L233.4 393.4c-12.5 12.5-12.5 32.8 0 45.3s32.8 12.5 45.3 0l160-160z" />
                    </svg>
                </button>
                <script>
                    let timeLeft = 5;
                    const timerEl = document.getElementById('countdown_timer');
                    let countdownInterval = setInterval(() => {
                        timeLeft--;
                        if (timerEl) timerEl.textContent = timeLeft;
                        if (timeLeft <= 0) {
                            clearInterval(countdownInterval);
                            window.location.replace('https://jofindia.com/');
                        }
                    }, 1000);

                    // If user presses Back from jofindia.com (BFCache restore), cancel the redirect
                    // and show ONLY the static lockdown overlay — nothing else
                    window.addEventListener("pageshow", function (event) {
                        if (event.persisted) {
                            clearInterval(countdownInterval);
                            document.body.style.overflow = 'hidden';
                            // Hide the animated success modal (has the countdown that re-redirects)
                            const overlay = document.getElementById('success_modal_overlay');
                            if (overlay) overlay.style.display = 'none';
                            // Show the blurred lockdown overlay on top of the form
                            const lockdown = document.getElementById('lockdown-overlay');
                            if (lockdown) lockdown.style.display = 'flex';
                        }
                    });
                </script>
            </div>
        </div>
    <?php endif; ?>

    <div class="container">
        <div class="card-header">
            <div class="brand-area">
                <img src="../icons/logo-dark(1).png" class="brand-logo" alt="JOF">
                <div class="brand-text">
                    <h2>Payment Details</h2>
                    <p>Provide payment info to finish registration.</p>
                </div>
            </div>
        </div>

        <div class="stepper-container">
            <div class="stepper stepper-narrow">
                <div class="step-item">
                    <div class="step-circle">1</div><span class="step-label">Health</span>
                </div>
                <div class="step-item">
                    <div class="step-circle">2</div><span class="step-label">Metrics</span>
                </div>
                <div class="step-item">
                    <div class="step-circle">3</div><span class="step-label">Measurements</span>
                </div>
                <div class="step-item active">
                    <div class="step-circle">4</div><span class="step-label">Payment</span>
                </div>
            </div>
        </div>

        <form action="" method="POST" enctype="multipart/form-data" id="paymentForm">
            <div class="card-body">
                <?php if (!empty($error_msg)): ?>
                    <div class="alert-error"><?= $error_msg ?></div><?php endif; ?>

                <div class="form-grid">
                    <!-- Left Column: Payment Fields -->
                    <div class="left-panel" style="display: flex; flex-direction: column; gap: 20px;">
                        <div class="input-group full-width mt-15">
                            <h3 class="section-title" style="margin-bottom: 0;">
                                <span style="display: flex; align-items: center; gap: 10px;">
                                    <img src="../icons/wallet-solid-full.svg" class="fa-solid fa-wallet icon-blue">
                                    Payment Mode Details
                                </span>
                            </h3>
                        </div>

                        <div class="input-group full-width">
                            <label>Payment Mode *</label>
                            <div class="input-wrapper">
                                <select name="payment_mode" id="payment_mode" class="form-input" required>
                                    <option value="" disabled <?= empty($payment_mode) ? 'selected' : '' ?>>Select
                                        Payment Mode</option>
                                    <option value="upi" <?= $payment_mode === 'upi' ? 'selected' : '' ?>>UPI
                                        (GPay/PhonePe/Paytm)</option>
                                </select>
                                <img src="../icons/wallet-solid-full.svg"
                                    class="fa-solid fa-wallet input-icon icon-blue">
                            </div>
                        </div>

                        <div class="input-group full-width" id="group_transaction_id">
                            <label>Transaction ID / UPI Ref *</label>
                            <div class="input-wrapper">
                                <input type="text" name="transaction_id" id="transaction_id" class="form-input"
                                    placeholder="Last 4-6 digits" value="<?= htmlspecialchars($transaction_id) ?>">
                                <img src="../icons/receipt-solid-full.svg"
                                    class="fa-solid fa-receipt input-icon icon-muted">
                            </div>
                        </div>

                        <div class="input-group full-width">
                            <label>Paid By (If different)</label>
                            <div class="input-wrapper">
                                <input type="text" name="payer_name" class="form-input"
                                    placeholder="e.g. Father/Friend Name" value="<?= htmlspecialchars($payer_name) ?>">
                                <img src="../icons/user-tag-solid-full.svg"
                                    class="fa-solid fa-user-tag input-icon icon-muted">
                            </div>
                        </div>

                        <div class="input-group full-width" id="group_screenshot">
                            <label>Upload Payment Screenshot</label>
                            <div class="file-upload-wrapper"
                                style="position: relative; border: 2px dashed #E5E7EB; border-radius: 8px; padding: 20px; text-align: center; margin-top: 10px;">
                                <input type="file" name="payment_screenshot" id="payment_screenshot" accept="image/*"
                                    onchange="updateFileName(this)"
                                    style="position: absolute; width: 100%; height: 100%; top: 0; left: 0; opacity: 0; cursor: pointer;">
                                <div class="file-label">
                                    <img src="../icons/cloud-upload-alt-solid-full.svg"
                                        class="fa-solid fa-cloud-upload-alt upload-icon-primary">
                                    <span id="file-text"
                                        style="color: <?= $existing_screenshot ? '#111827' : '#6B7280' ?>; font-size: 14px;">
                                        <?= $existing_screenshot ? 'Existing: ' . htmlspecialchars($existing_screenshot) . ' (Click to replace)' : 'Click to upload screenshot (Optional)' ?>
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Right Column: QR Code Display -->
                    <div class="right-panel qr-display-area"
                        style="text-align: center; background: #f9fafb; padding: 30px; border-radius: 12px; border: 1px dashed #d1d5db; margin-top: 15px; display: flex; flex-direction: column; align-items: center; justify-content: center;">
                        <h4 style="margin-bottom: 10px; font-size: 18px; color: #111827; margin-top: 0;">Scan to Pay
                        </h4>
                        <p style="font-size: 13px; color: #6b7280; margin-bottom: 20px; margin-top: 0;">Use any UPI app
                            to complete payment</p>

                        <!-- Download QR Code -->
                        <a href="../icons/images/qr_code.jpeg" download="JOF_India_QR.jpeg"
                            style="display: inline-flex; align-items: center; justify-content: center; gap: 8px; background: #F25C2A; color: #fff; padding: 8px 16px; border-radius: 6px; text-decoration: none; font-size: 14px; font-weight: 600; margin-bottom: 25px; transition: background 0.2s;">
                            <img src="../icons/download-solid-full.svg" class="fa-solid fa-download"
                                style="width: 14px; height: 14px; filter: brightness(0) invert(1);"> Download QR Code
                        </a>

                        <!-- Mobile View (Pay via UPI App) -->
                        <div class="mobile-only-btn" style="width: 100%; margin-bottom: 25px;">
                            <a href="upi://pay?pa=7798487212@hdfc&pn=JOSHUA%20SUNIL%20SADANANDAN&cu=INR"
                                style="display: flex; align-items: center; justify-content: center; gap: 8px; background: #22a65b; color: #fff; padding: 14px; border-radius: 10px; text-decoration: none; font-size: 16px; font-weight: 700; width: 100%; box-sizing: border-box; box-shadow: 0 4px 14px rgba(34, 166, 91, 0.3);">
                                <svg viewBox="0 0 24 24"
                                    style="width:20px;height:20px;stroke:white;fill:none;stroke-width:2;stroke-linecap:round;stroke-linejoin:round;">
                                    <rect x="5" y="2" width="14" height="20" rx="2" ry="2"></rect>
                                    <line x1="12" y1="18" x2="12.01" y2="18"></line>
                                </svg> Pay directly via UPI App
                            </a>
                        </div>

                        <div class="qr-code-wrapper"
                            style="background: #ffffff; padding: 6px; border-radius: 12px; box-shadow: 0 4px 15px rgba(0,0,0,0.05); display: block; max-width: 300px; margin: 0 auto;">
                            <img src="../icons/images/qr_code.jpeg" alt="Payment QR Code"
                                style="width: 100%; border-radius: 8px; display: block;">
                        </div>
                        <div style="margin-top: 20px; font-size: 13px; color: #4b5563; font-weight: 500;">GPay
                            &nbsp;·&nbsp; PhonePe &nbsp;·&nbsp; Paytm &nbsp;·&nbsp; any UPI app</div>
                    </div>

                </div>
            </div>

            <div class="card-footer" style="padding: 30px 40px 40px 40px;">
                <a href="form3_member_measurements.php" class="btn btn-secondary">Back to Measurements</a>
                <button type="button" onclick="openSubmitModal()" class="btn btn-primary"
                    style="margin-left: auto;">Finish Registration →</button>
            </div>
        </form>
    </div>

    <!-- Confirm Submission Modal -->
    <div class="modal-overlay-generic" id="submit_confirm_modal">
        <div class="modal-content-generic">
            <div class="modal-icon-circle" style="background: #D1FAE5;">
                <svg viewBox="0 0 24 24" width="32" height="32"
                    style="stroke: #10B981; fill: none; stroke-width: 2.5; stroke-linecap: round; stroke-linejoin: round;">
                    <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path>
                    <polyline points="22 4 12 14.01 9 11.01"></polyline>
                </svg>
            </div>
            <h2 style="font-size: 20px; font-weight: 700; color: #111827; margin: 0 0 10px;">Almost Done!</h2>
            <p style="font-size: 14px; color: #4B5563; margin: 0 0 25px; line-height: 1.5;">
                You're about to finish your registration. Please ensure your payment is completed before submitting.
                <br><br><b>Ready to join JOF India?</b>
            </p>
            <div class="modal-btn-group">
                <button type="button" class="btn-outline" onclick="closeSubmitModal()">Go Back</button>
                <button type="button"
                    style="padding: 10px 24px; border-radius: 10px; border: none; background: #10B981; color: #fff; font-size: 14px; font-weight: 600; cursor: pointer; display: flex; align-items: center; justify-content: center; gap: 8px; box-shadow: 0 4px 14px rgba(16, 185, 129, 0.3); transition: transform 0.2s, background 0.2s;"
                    onmouseover="this.style.background='#059669'; this.style.transform='translateY(-1px)';"
                    onmouseout="this.style.background='#10B981'; this.style.transform='translateY(0)';"
                    onclick="confirmSubmit()">
                    Yes, Complete
                    <svg viewBox="0 0 24 24"
                        style="width:16px;height:16px;stroke:white;fill:none;stroke-width:2.5;stroke-linecap:round;stroke-linejoin:round;">
                        <line x1="5" y1="12" x2="19" y2="12"></line>
                        <polyline points="12 5 19 12 12 19"></polyline>
                    </svg>
                </button>
            </div>
        </div>
    </div>

    <script>
        function updateFileName(input) {
            const fileName = input.files[0] ? input.files[0].name : "Click to upload screenshot (Optional)";
            document.getElementById('file-text').innerText = fileName;
            document.getElementById('file-text').style.color = "#111827";
        }

        const paymentModeSelect = document.getElementById('payment_mode');
        const groupTransactionId = document.getElementById('group_transaction_id');
        const groupScreenshot = document.getElementById('group_screenshot');
        const transactionInput = document.getElementById('transaction_id');

        function toggleOnlineFields() {
            const mode = paymentModeSelect.value;
            if (mode === 'upi' || mode === 'bank') {
                groupTransactionId.style.display = 'block';
                groupScreenshot.style.display = 'block';
                transactionInput.required = true;
            } else {
                groupTransactionId.style.display = 'none';
                groupScreenshot.style.display = 'none';
                transactionInput.required = false;
            }
        }

        paymentModeSelect.addEventListener('change', toggleOnlineFields);

        function openSubmitModal() {
            const form = document.getElementById('paymentForm');
            if (form.reportValidity()) {
                document.getElementById('submit_confirm_modal').style.display = 'flex';
            }
        }

        function closeSubmitModal() {
            document.getElementById('submit_confirm_modal').style.display = 'none';
        }

        function confirmSubmit() {
            const stickinessFields = ['payment_mode', 'transaction_id', 'payer_name'];
            stickinessFields.forEach(field => sessionStorage.removeItem('jof_form4_' + field));
            closeSubmitModal();
            document.getElementById('paymentForm').submit();
        }

        window.addEventListener("pageshow", function (event) {
            if (event.persisted) {
                closeSubmitModal();
            }
        });

        document.addEventListener("DOMContentLoaded", function () {
            const stickinessFields = ['payment_mode', 'transaction_id', 'payer_name'];
            stickinessFields.forEach(field => {
                const el = document.getElementById(field) || document.querySelector(`[name="${field}"]`);
                if (el) {
                    const savedValue = sessionStorage.getItem('jof_form4_' + field);
                    if (savedValue && el.value === '') {
                        el.value = savedValue;
                    }
                    el.addEventListener('input', () => sessionStorage.setItem('jof_form4_' + field, el.value));
                    el.addEventListener('change', () => sessionStorage.setItem('jof_form4_' + field, el.value));
                }
            });
            toggleOnlineFields();
        });
    </script>
    <script>
        function replaceSVG() {
            var images = document.querySelectorAll('img.fa-solid, img.fa-regular, img[class*="fa-"]');
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