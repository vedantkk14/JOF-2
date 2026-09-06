<?php
session_start();
require '../config.php';

// PHPMailer includes
require __DIR__ . '/../auth/PHPMailer/Exception.php';
require __DIR__ . '/../auth/PHPMailer/PHPMailer.php';
require __DIR__ . '/../auth/PHPMailer/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// 1. Security Check
if (!isset($_SESSION['user_id'])) {
    header("Location: ../templates/login_page.html");
    exit;
}

// 2. Flow Check
if (!isset($_SESSION['new_member_id'])) {
    header("Location: add_member.php");
    exit;
}

$member_id = $_SESSION['new_member_id'];
$error_msg = '';
$show_success_modal = false;

// Fetch existing data (if edit-on-back)
$payment_mode = '';
$transaction_id = '';
$payer_name = '';
$existing_screenshot = NULL;
$payment_record_exists = false;

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
                    $show_success_modal = true;
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
                    $show_success_modal = true;
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
    <title>JOF India | Member Payment Details</title>
    <link rel="stylesheet" href="../static/root.css">

</head>

<body class="page-metrics">

    <?php if ($show_success_modal): ?>
        <div class="modal-overlay active">
            <div class="modal-card">
                <div class="success-icon-container"><img src="../icons/check-solid-full.svg"
                        class="fa-solid fa-check icon-white" width="24"></div>
                <h2 class="color-dark mb-10">Registration Complete!</h2>
                <p class="color-muted mb-30">Member details have been saved successfully.</p>
                <button onclick="window.location.href='members.php'" class="btn-success">
                    Go to Members page <img src="../icons/arrow-right-solid-full.svg" class="fa-solid fa-arrow-right ml-8">
                </button>
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
            <a href="members.php" class="close-btn"><img src="../icons/xmark-solid-full.svg" alt="close"></a>
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

        <form action="" method="POST" enctype="multipart/form-data">
            <div class="card-body">
                <?php if (!empty($error_msg)): ?>
                    <div class="alert-error"><?= $error_msg ?></div><?php endif; ?>

                <div class="form-grid">
                    <!-- Removed Diet & Payment Preferences Header and Diet Dropdown -->

                    <div class="input-group full-width mt-15">
                        <h3 class="section-title" style="justify-content: space-between; width: 100%;">
                            <span style="display: flex; align-items: center; gap: 10px;">
                                <img src="../icons/wallet-solid-full.svg" class="fa-solid fa-wallet icon-blue"> Payment
                                Mode Details
                            </span>
                            <button type="button" onclick="openQrModal()" id="scan_pay_btn" title="Pay via QR Code"
                                class="btn-scan-pay" style="margin-left: 0; margin-right: 0;">
                                <span class="scan-pay-badge">
                                    <img src="../icons/qrcode-solid-full.svg" class="fa-solid fa-qrcode icon-white">
                                </span>
                                <span class="scan-pay-text">Scan & Pay</span>
                            </button>
                        </h3>
                    </div>

                    <div class="input-group">
                        <label>Payment Mode *</label>
                        <div class="input-wrapper">
                            <select name="payment_mode" id="payment_mode" class="form-input" required>
                                <option value="" disabled <?= empty($payment_mode) ? 'selected' : '' ?>>Select Payment
                                    Mode</option>
                                <option value="upi" <?= $payment_mode === 'upi' ? 'selected' : '' ?>>UPI
                                    (GPay/PhonePe/Paytm)</option>
                            </select>
                            <img src="../icons/wallet-solid-full.svg" class="fa-solid fa-wallet input-icon icon-blue">
                        </div>
                    </div>

                    <div class="input-group" id="group_transaction_id">
                        <label>Transaction ID / UPI Ref *</label>
                        <div class="input-wrapper">
                            <input type="text" name="transaction_id" id="transaction_id" class="form-input"
                                placeholder="Last 4-6 digits" value="<?= htmlspecialchars($transaction_id) ?>">
                            <img src="../icons/receipt-solid-full.svg"
                                class="fa-solid fa-receipt input-icon icon-muted">
                        </div>
                    </div>

                    <div class="input-group">
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
            </div>

            <div class="card-footer" style="padding: 30px 40px 40px 40px;">
                <a href="measurements.php" class="btn btn-secondary">Back to Measurements</a>
                <button type="submit" class="btn btn-primary" style="margin-left: auto;">Finish Registration →</button>
            </div>
        </form>
    </div>

    <!-- ===== QR PAYMENT MODAL ===== -->
    <div id="qrPayModal" class="qr-modal-overlay">
        <div class="qr-modal-card">
            <div class="qr-modal-header">
                <div>
                    <div class="qr-modal-title">📲 Scan & Pay</div>
                    <div class="qr-modal-subtitle">Use any UPI app to complete payment</div>
                </div>
                <button type="button" onclick="closeQrModal()" class="btn-close-qr">
                    <img src="../icons/xmark-solid-full.svg" class="fa-solid fa-times icon-white">
                </button>
            </div>
            <div class="qr-modal-body">
                <img src="../icons/images/qr_code.jpeg" alt="Payment QR Code" class="qr-code-img">
                <div class="qr-modal-footer-text">GPay &nbsp;·&nbsp;
                    PhonePe &nbsp;·&nbsp; Paytm &nbsp;·&nbsp; any UPI app</div>
            </div>
        </div>
    </div>

    <script>
        function openQrModal() { document.getElementById('qrPayModal').classList.add('active'); }
        function closeQrModal() { document.getElementById('qrPayModal').classList.remove('active'); }
        document.getElementById('qrPayModal').addEventListener('click', function (e) { if (e.target === this) closeQrModal(); });

        function updateFileName(input) {
            const fileName = input.files[0] ? input.files[0].name : "Click to upload screenshot (Optional)";
            document.getElementById('file-text').innerText = fileName;
            document.getElementById('file-text').style.color = "#111827";
        }

        const paymentModeSelect = document.getElementById('payment_mode');
        const groupTransactionId = document.getElementById('group_transaction_id');
        const groupScreenshot = document.getElementById('group_screenshot');
        const scanPayBtn = document.getElementById('scan_pay_btn');
        const transactionInput = document.getElementById('transaction_id');

        function toggleOnlineFields() {
            const mode = paymentModeSelect.value;
            if (mode === 'upi' || mode === 'bank') {
                groupTransactionId.style.display = 'block';
                groupScreenshot.style.display = 'block';
                transactionInput.required = true;
                if (mode === 'upi') scanPayBtn.style.display = 'inline-flex';
                else scanPayBtn.style.display = 'none';
            } else {
                groupTransactionId.style.display = 'none';
                groupScreenshot.style.display = 'none';
                scanPayBtn.style.display = 'none';
                transactionInput.required = false;
            }
        }

        paymentModeSelect.addEventListener('change', toggleOnlineFields);
        toggleOnlineFields();
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