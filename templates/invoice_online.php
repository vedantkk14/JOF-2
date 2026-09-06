<?php
session_start();
require '../config.php';
require_once '../auth/invoice_helper.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: ../index.php");
    exit;
}

// 1. Get Payment ID
if (!isset($_GET['payment_id'])) {
    die("Error: Invoice ID missing.");
}
$payment_id = intval($_GET['payment_id']);

// 2. Fetch Payment & Member Data
$sql = "SELECT 
            mp.*, 
            m.full_name, 
            m.email,
            m.phone_number
        FROM member_payments mp
        JOIN members m ON mp.member_id = m.id
        WHERE mp.payment_id = ?";

$stmt = $conn->prepare($sql);

if (!$stmt) {
    die("<b>Database Error:</b> " . $conn->error);
}

$stmt->bind_param("i", $payment_id);
$stmt->execute();
$result = $stmt->get_result();
$data = $result->fetch_assoc();

if (!$data) {
    die("Error: Invoice not found.");
}

// 3. Prepare Variables — ONLINE invoice serial number (independent sequence)
$invoice_no = getInvoiceSerialNumber($conn, $data['payment_id'], $data['payment_mode'], $data['created_at']);
$invoice_date = date("M d, Y", strtotime($data['created_at']));

// Use Payer Name if available, otherwise Member Name
$client_name = !empty($data['payer_name']) ? $data['payer_name'] : $data['full_name'];
$client_email = $data['email'];
$client_phone = !empty($data['phone_number']) ? $data['phone_number'] : "N/A";

$total_amount = floatval($data['total_amount']);

// Payment mode display
$payment_mode_display = ucfirst($data['payment_mode'] ?? 'Online');

// Status Logic
$status = 'UNPAID';
$status_class = 'overdue';

if ($data['balance_pending'] <= 0.1) {
    $status = 'PAID';
    $status_class = 'paid';
} elseif ($data['amount_received'] > 0) {
    $status = 'PARTIAL';
    $status_class = 'partial';
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <link rel="icon" size="16x16" href="../icons/favicon-dark-logo.png" type="image/png">
    <title>Online Invoice #
        <?= $data['payment_id'] ?> | JOF INDIA
    </title>
    <link rel="stylesheet" href="../static/root.css">
    <style>
        .status-badge.partial {
            background: #FFF7ED;
            color: #EA580C;
            border: 1px solid #FFEDD5;
        }

        .payment-mode-badge {
            display: inline-block;
            padding: 6px 16px;
            border-radius: 6px;
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 1.5px;
            text-transform: uppercase;
            background: #DBEAFE;
            color: #1E40AF;
            border: 1px solid #93C5FD;
            margin-top: 8px;
        }

        @media print {
            .footer-actions {
                display: none !important;
            }

            body {
                padding: 0;
                background: white;
            }

            .invoice-container {
                box-shadow: none;
                border: none;
                padding: 0;
            }
        }
    </style>
</head>

<body class="page-invoice">

    <div class="mobile-app-header">
        <img src="../icons/logo-light(1).png" alt="JoF Logo">
        <span>JOF India</span>
    </div>

    <div class="invoice-container">

        <header class="invoice-header">
            <div class="header-brand">
                <div class="logo-wrapper">
                    <img src="../icons/logo-light(1).png" alt="JoF Logo" class="brand-logo">
                </div>
                <div class="company-address" style="display: flex; align-items: center; gap: 10px;">
                    <img src="../icons/logo-dark(1).png" alt="Joshuaa's Logo"
                        style="width: 40px; height: 40px; object-fit: contain;">
                    <div>
                        <p><b>JOF INDIA</b></p>
                        <p>Pune, Maharashtra - India</p>
                        <p>info@jofindia.com</p>
                    </div>
                </div>
            </div>

            <div class="header-details">
                <h1 class="invoice-title">INVOICE</h1>
                <div class="meta-grid">
                    <div class="meta-item">
                        <span class="label">Invoice :</span>
                        <span class="value">
                            <?= $invoice_no ?>
                        </span>
                    </div>

                    <div class="meta-item">
                        <span class="label">Date :</span>
                        <span class="value">
                            <?= $invoice_date ?>
                        </span>
                    </div>
                </div>
                <span class="payment-mode-badge">🌐 Online Payment (
                    <?= $payment_mode_display ?>)
                </span>
            </div>
        </header>

        <hr class="divider">

        <section class="billing-section">
            <div class="bill-to">
                <h3>Bill To</h3>
                <p class="client-name">
                    <?= htmlspecialchars($client_name) ?>
                </p>
                <p>
                    <?= htmlspecialchars($client_email) ?>
                </p>
                <p>
                    <?= htmlspecialchars($client_phone) ?>
                </p>
            </div>
            <div class="status-box">
                <span class="status-badge <?= $status_class ?>">
                    <?= $status ?>
                </span>
            </div>
        </section>

        <section class="items-section">
            <div class="table-header">
                <div class="col-desc">Description</div>
                <div class="col-qty center">Qty</div>
                <div class="col-price right">Price</div>
                <div class="col-total right">Total</div>
            </div>

            <div class="table-row">
                <div class="col-desc">
                    <p class="item-name">
                        <?= htmlspecialchars(ucfirst($data['membership_type'])) ?> Plan
                    </p>
                    <p class="item-meta">
                        <?= $data['duration_months'] ?> Week(s) Access
                    </p>
                </div>
                <div class="col-qty center">1</div>
                <div class="col-price right">₹
                    <?= number_format($total_amount, 2) ?>
                </div>
                <div class="col-total right">₹
                    <?= number_format($total_amount, 2) ?>
                </div>
            </div>

            <?php if ($data['balance_pending'] > 0): ?>
                <div class="table-row" style="background-color: #f9fafb;">
                    <div class="col-desc">
                        <p class="item-name" style="color: #6B7280;">Amount Received</p>
                        <p class="item-meta">via
                            <?= $payment_mode_display ?>
                        </p>
                    </div>
                    <div class="col-qty center">-</div>
                    <div class="col-price right">-</div>
                    <div class="col-total right" style="color: #10B981;">- ₹
                        <?= number_format($data['amount_received'], 2) ?>
                    </div>
                </div>
            <?php endif; ?>

        </section>

        <section class="summary-section">
            <div class="bank-details">
                <h3>Payment Details</h3>
                <p><b>Payment Mode:</b>
                    <?= $payment_mode_display ?>
                </p>
                <p><b>Bank:</b> HDFC Bank</p>
                <p><b>Account:</b> 556677889900</p>
                <p><b>IFSC:</b> HDFC0001234</p>
                <p><b>UPI ID:</b> jof@okaxis</p>
            </div>

            <div class="totals-box">
                <?php if ($data['discount'] > 0): ?>
                    <div class="total-row">
                        <span>Discount</span>
                        <span>- ₹
                            <?= number_format($data['discount'], 2) ?>
                        </span>
                    </div>
                <?php endif; ?>

                <div class="total-row grand-total">
                    <span>Total Plan Value</span>
                    <span>₹
                        <?= number_format($total_amount, 2) ?>
                    </span>
                </div>

                <?php if ($data['balance_pending'] > 0): ?>
                    <div class="total-row" style="margin-top: 10px; color: #EF4444; font-weight: bold;">
                        <span>Balance Due</span>
                        <span>₹
                            <?= number_format($data['balance_pending'], 2) ?>
                        </span>
                    </div>
                <?php endif; ?>
            </div>
        </section>

        <div class="footer-actions">
            <p class="thank-you">Thank you for choosing JOF!</p>
            <div class="buttons">
                <button class="btn btn-secondary" onclick="window.print()">Print</button>
                <button class="btn btn-primary" onclick="window.print()">Download PDF</button>
            </div>
        </div>

    </div>

</body>

</html>