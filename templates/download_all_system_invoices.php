<?php
require_once __DIR__ . '/../auth/auth_check.php';
require_role(['admin', 'trainer']);
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../auth/invoice_helper.php';

// Fetch ALL Invoices (serial order)
$sql = "SELECT 
            mp.*, 
            m.full_name, 
            m.email
        FROM member_payments mp
        JOIN members m ON mp.member_id = m.id
        ORDER BY mp.payment_id ASC";

$stmt = $conn->prepare($sql);

if (!$stmt) {
    die("<b>Database Error:</b> " . $conn->error);
}

$stmt->execute();
$result = $stmt->get_result();

$invoices = [];
while ($row = $result->fetch_assoc()) {
    $invoices[] = $row;
}

// Remove die() to allow showing a friendly message in the body instead
if (empty($invoices)) {
    // We'll handle this in the UI below
}

// Fetch Installment History for ALL members
$inst_sql = "SELECT ip.*, mp.membership_type, mp.duration_months, mp.total_amount, mp.discount, m.full_name, m.email
             FROM installment_payments ip
             JOIN member_payments mp ON ip.payment_id = mp.payment_id
             JOIN members m ON mp.member_id = m.id
             ORDER BY ip.payment_date ASC";

$inst_stmt = $conn->prepare($inst_sql);
$inst_stmt->execute();
$inst_result = $inst_stmt->get_result();

$installment_receipts = [];
while ($row = $inst_result->fetch_assoc()) {
    $installment_receipts[] = $row;
}
$inst_stmt->close();

?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <link rel="icon" size="16x16" href="../icons/favicon-dark-logo.png" type="image/png">
    <title>All System Invoices | JOF INDIA</title>
    <style>
        body {
            font-family: 'Segoe UI', Helvetica, Arial, sans-serif;
            background: #f9fafb;
            color: #333;
            margin: 0;
            padding: 20px;
        }

        .invoice-wrapper {
            max-width: 800px;
            margin: 40px auto;
            padding: 40px;
            border: 1px solid #E5E7EB;
            border-radius: 8px;
            background: #fff;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
        }

        .footer-actions {
            margin-bottom: 30px;
            text-align: center;
        }

        .btn {
            display: inline-block;
            padding: 10px 20px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            text-decoration: none;
            font-weight: bold;
            margin: 0 5px;
        }

        .btn-primary {
            background: #F25C2A;
            color: #fff;
        }

        .btn-secondary {
            background: #e5e7eb;
            color: #374151;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        td,
        th {
            padding: 8px;
        }

        .page-break {
            page-break-before: always;
            margin-top: 40px;
        }

        @media print {
            .footer-actions {
                display: none !important;
            }

            body {
                padding: 0;
                background: #fff;
            }

            .invoice-wrapper {
                margin: 0;
                padding: 0;
                border: none;
                box-shadow: none;
                max-width: 100%;
                page-break-inside: avoid;
            }

            .page-break {
                margin-top: 0;
            }
        }
    </style>
</head>

<body>

    <div class="footer-actions">
        <h2 style="margin: 0 0 10px 0; color: #111827;">All System Invoices</h2>
        <p style="margin: 0 0 20px 0; color: #6b7280; font-size: 14px;">Total Invoices:
            <?= count($invoices) + count($installment_receipts) ?>
        </p>
        <div>
            <?php if (!empty($invoices) || !empty($installment_receipts)): ?>
                <button class="btn btn-secondary" onclick="window.print()">Print All</button>
                <button class="btn btn-primary" onclick="window.print()">Download All as PDF</button>
            <?php endif; ?>
            <a href="payment_installments.php" class="btn btn-secondary"
                style="background:white; border:1px solid #d1d5db;">Back to List</a>
        </div>
    </div>

    <?php if (empty($invoices) && empty($installment_receipts)): ?>
        <div
            style="text-align: center; margin-top: 50px; padding: 40px; background: white; border-radius: 12px; box-shadow: 0 4px 6px rgba(0,0,0,0.05); max-width: 600px; margin-left: auto; margin-right: auto;">
            <img src="../icons/receipt-solid-full.svg" alt="No Invoices"
                style="width: 60px; opacity: 0.2; margin-bottom: 20px;">
            <h3 style="color: #374151; margin: 0 0 10px 0;">No Invoices Found</h3>
            <p style="color: #6B7280; font-size: 14px; margin: 0;">It looks like there are no invoices or installment
                receipts available in the system yet.</p>
        </div>
    <?php endif; ?>

    <?php foreach ($invoices as $index => $data):
        // Prepare Variables for each invoice
        $invoice_no = getInvoiceSerialNumber($conn, $data['payment_id'], $data['payment_mode'], $data['created_at']);
        $invoice_date = date("M d, Y", strtotime($data['created_at']));

        $client_name = !empty($data['payer_name']) ? $data['payer_name'] : $data['full_name'];
        $client_email = $data['email'];
        $client_phone = "N/A"; // Set default since phone column is missing
    
        // Calculate Tax (Assuming 18% GST is included in Total)
        $total_amount = floatval($data['total_amount']);
        // $tax_rate = 0.18;
        // $base_amount = $total_amount / (1 + $tax_rate);
        // $tax_amount = $total_amount - $base_amount;
    
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

        $page_break_class = ($index > 0) ? 'page-break' : '';
        ?>

        <div class="invoice-wrapper <?= $page_break_class ?>">
            <table width="100%">
                <tr>
                    <td width="60%" valign="top">
                        <img src="../icons/images/logo-invoice.jpeg" alt="JOF"
                            style="width:80px; height:auto; margin-bottom:8px;"><br>
                        <span style="font-size:11px; color:#555;">Aurelia, Pancard Road, Baner, Pune-411045,
                            Maharashtra<br>iglmembershipid@gmail.com</span>
                    </td>
                    <td width="40%" align="right" valign="top">
                        <h2 style="color:#555; margin:0 0 5px 0;">INVOICE</h2>
                        <p style="font-size:12px; margin:0;">
                            Invoice: <b><?= $invoice_no ?></b><br>
                            Date: <?= $invoice_date ?><br>
                            <b
                                style="color:<?= $status === 'PAID' ? '#10B981' : ($status === 'PARTIAL' ? '#F97316' : '#EF4444') ?>;"><?= $status ?></b>
                        </p>
                    </td>
                </tr>
            </table>
            <hr style="border:0; border-top:1px solid #E5E7EB; margin:15px 0;">

            <table width="100%">
                <tr>
                    <td>
                        <p style="font-size:12px; color:#888; margin:0 0 5px 0;">BILL TO</p>
                        <h3 style="margin:0 0 5px 0;"><?= htmlspecialchars($client_name) ?></h3>
                        <p style="font-size:12px; color:#555; margin:0;"><?= htmlspecialchars($client_email) ?><br>
                            <b>Mode:</b> <?= ucfirst($data['payment_mode'] ?? 'cash') ?>
                        </p>
                    </td>
                </tr>
            </table>
            <br><br>

            <table border="1" cellpadding="8" cellspacing="0" bordercolor="#E5E7EB" width="100%" style="font-size:12px;">
                <tr style="background-color:#F9FAFB; font-weight:bold; color:#333;">
                    <th width="50%" align="left">DESCRIPTION</th>
                    <th width="10%" align="center">QTY</th>
                    <th width="20%" align="right">PRICE</th>
                    <th width="20%" align="right">TOTAL</th>
                </tr>
                <tr>
                    <td><?= ucfirst($data['membership_type']) ?> Plan<br><span
                            style="color:#666; font-size:11px;"><?= $data['duration_months'] ?> Week(s) Access</span></td>
                    <td align="center">1</td>
                    <td align="right">₹<?= number_format($total_amount, 2) ?></td>
                    <td align="right">₹<?= number_format($total_amount, 2) ?></td>
                </tr>
                <?php if ($data['balance_pending'] > 0): ?>
                    <tr>
                        <td>Amount Received<br><span style="color:#666; font-size:11px;">via
                                <?= ucfirst($data['payment_mode']) ?></span></td>
                        <td align="center">-</td>
                        <td align="right">-</td>
                        <td align="right" style="color:#10B981;">- ₹<?= number_format($data['amount_received'], 2) ?></td>
                    </tr>
                <?php endif; ?>
            </table>
            <br><br>

            <table width="100%">
                <tr>
                    <td width="50%" valign="top">
                        <p style="font-size:12px; font-weight:bold; margin:0 0 5px 0;">PAYMENT DETAILS</p>
                        <p style="font-size:12px; color:#555; margin:0;">Bank: HDFC Bank<br>Account: 556677889900<br>IFSC:
                            HDFC0001234<br>UPI ID: jof@okaxis</p>
                    </td>
                    <td width="50%">
                        <table cellpadding="4" width="100%" style="font-size:12px;">
                            <!-- <tr><td align="left">Subtotal (Base)</td><td align="right">₹<?= number_format($base_amount, 2) ?></td></tr>
                            <tr><td align="left">Tax (18% GST)</td><td align="right">₹<?= number_format($tax_amount, 2) ?></td></tr> -->
                            <?php if ($data['discount'] > 0): ?>
                                <tr>
                                    <td align="left">Discount</td>
                                    <td align="right">- ₹<?= number_format($data['discount'], 2) ?></td>
                                </tr>
                            <?php endif; ?>
                            <tr>
                                <td colspan="2">
                                    <hr style="border:0; border-top:1px solid #E5E7EB; margin:5px 0;">
                                </td>
                            </tr>
                            <tr style="font-weight:bold; font-size:14px;">
                                <td align="left" style="color:#F25C2A">Total Plan Value</td>
                                <td align="right">₹<?= number_format($total_amount, 2) ?></td>
                            </tr>
                            <?php if ($data['balance_pending'] > 0): ?>
                                <tr style="font-weight:bold; font-size:13px;">
                                    <td align="left" style="color:#EF4444">Balance Due</td>
                                    <td align="right" style="color:#EF4444">₹<?= number_format($data['balance_pending'], 2) ?>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </table>
                    </td>
                </tr>
            </table>
            <br><br>
            <div style="text-align:center; font-size:14px; font-weight:bold; color:#F25C2A; margin-top:20px;">
                Thank you for choosing JOF INDIA!
            </div>
            <?php if ($index < count($invoices) - 1): ?>
                <hr style="border:0; border-top:1px dashed #E5E7EB; margin:40px 0;">
            <?php endif; ?>
        </div> <!-- end invoice-container -->

    <?php endforeach; ?>

    <!-- RENDER INSTALLMENT RECEIPTS -->
    <?php foreach ($installment_receipts as $index => $data):
        // Prepare Variables for each installment receipt
        $invoice_no = getInstallmentSerialNumber($conn, $data['id'], $data['payment_mode'], $data['payment_date']);
        $invoice_date = date("M d, Y", strtotime($data['payment_date']));

        $client_name = $data['full_name'];
        $client_email = $data['email'];

        $total_amount = floatval($data['total_amount']);
        $installment_amount = floatval($data['installment_amount']);
        $title_text = (strtolower($data['payment_mode']) === 'cash') ? 'CASH RECEIPT' : 'ONLINE RECEIPT';

        ?>
        <div class="invoice-wrapper page-break">
            <table width="100%">
                <tr>
                    <td width="60%" valign="top">
                        <img src="../icons/images/logo-invoice.jpeg" alt="JOF"
                            style="width:80px; height:auto; margin-bottom:8px;"><br>
                        <span style="font-size:11px; color:#555;">Aurelia, Pancard Road, Baner, Pune-411045,
                            Maharashtra<br>iglmembershipid@gmail.com</span>
                    </td>
                    <td width="40%" align="right" valign="top">
                        <h2 style="color:#555; margin:0 0 5px 0;"><?= $title_text ?></h2>
                        <p style="font-size:12px; margin:0;">
                            Receipt No: <b><?= $invoice_no ?></b><br>
                            Date: <?= $invoice_date ?><br>
                            <b style="color:#F97316;">INSTALLMENT</b>
                        </p>
                    </td>
                </tr>
            </table>
            <hr style="border:0; border-top:1px solid #E5E7EB; margin:15px 0;">

            <table width="100%">
                <tr>
                    <td>
                        <p style="font-size:12px; color:#888; margin:0 0 5px 0;">BILL TO</p>
                        <h3 style="margin:0 0 5px 0;"><?= htmlspecialchars($client_name) ?></h3>
                        <p style="font-size:12px; color:#555; margin:0;"><?= htmlspecialchars($client_email) ?><br>
                            <b>Payment Mode:</b> <?= ucfirst($data['payment_mode']) ?>
                        </p>
                    </td>
                </tr>
            </table>
            <br><br>

            <table border="1" cellpadding="8" cellspacing="0" bordercolor="#E5E7EB" width="100%" style="font-size:12px;">
                <tr style="background-color:#F9FAFB; font-weight:bold; color:#333;">
                    <th width="50%" align="left">DESCRIPTION</th>
                    <th width="10%" align="center">QTY</th>
                    <th width="20%" align="right">RATE</th>
                    <th width="20%" align="right">AMOUNT</th>
                </tr>
                <tr>
                    <td>Installment Payment - <?= ucfirst($data['membership_type']) ?> Plan<br><span
                            style="color:#666; font-size:11px;">Total Plan Value:
                            ₹<?= number_format($total_amount, 2) ?></span></td>
                    <td align="center">1</td>
                    <td align="right">₹<?= number_format($installment_amount, 2) ?></td>
                    <td align="right">₹<?= number_format($installment_amount, 2) ?></td>
                </tr>
            </table>
            <br><br>

            <table width="100%">
                <tr>
                    <td width="55%" valign="top">
                        <p style="font-size:12px; font-weight:bold; margin:0 0 5px 0;">PAYMENT DETAILS</p>
                        <p style="font-size:12px; color:#555; margin:0;">Bank: HDFC Bank<br>Account: 556677889900<br>IFSC:
                            HDFC0001234<br>UPI ID: jof@okaxis</p>
                        <br>
                        <p style="font-size:11px; color:#888;">Note: This receipt is for a partial installment payment.
                            Please refer to your account for the overall balance.</p>
                    </td>
                    <td width="45%">
                        <table cellpadding="4" width="100%" style="font-size:12px; border: 1px solid #E5E7EB;">
                            <tr>
                                <td align="left">Total Plan Value</td>
                                <td align="right">₹<?= number_format($total_amount, 2) ?></td>
                            </tr>
                            <tr>
                                <td colspan="2">
                                    <hr style="border:0; border-top:1px solid #E5E7EB; margin:5px 0;">
                                </td>
                            </tr>
                            <tr style="font-weight:bold; font-size:14px;">
                                <td align="left" style="color:#F25C2A">Amount Paid Now</td>
                                <td align="right">₹<?= number_format($installment_amount, 2) ?></td>
                            </tr>
                        </table>
                    </td>
                </tr>
            </table>
            <br><br>
            <div style="text-align:center; font-size:14px; font-weight:bold; color:#F25C2A; margin-top:20px;">
                Thank you for choosing JOF INDIA!
            </div>

        </div> <!-- end invoice-container -->
    <?php endforeach; ?>

</body>

</html>