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

// DEBUG: If SQL fails, show the exact error
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

// 3. Prepare Variables (independent serial number per payment mode)
$invoice_no = getInvoiceSerialNumber($conn, $data['payment_id'], $data['payment_mode'], $data['created_at']);
$invoice_date = date("M d, Y", strtotime($data['created_at']));

// Use Payer Name if available, otherwise Member Name
$client_name = !empty($data['payer_name']) ? $data['payer_name'] : $data['full_name'];
$client_email = $data['email'];
$client_phone = !empty($data['phone_number']) ? $data['phone_number'] : "N/A";

// Calculate Tax (Assuming 18% GST is included in Total)
$total_amount = floatval($data['total_amount']);
$tax_rate = 0.18;
$base_amount = $total_amount / (1 + $tax_rate);
$tax_amount = $total_amount - $base_amount;

// Status Logic
$status = 'UNPAID';
$status_class = 'overdue';

// Precision Check for Floating Point Math
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
  <title>Invoice #<?= $data['payment_id'] ?> | JOF INDIA</title>
  <style>
    body {
      font-family: 'Segoe UI', Helvetica, Arial, sans-serif;
      background: #fff;
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
      box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
    }

    .footer-actions {
      margin-top: 30px;
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
      }
    }
  </style>
</head>

<body>

  <div class="invoice-wrapper">
    <table width="100%">
      <tr>
        <td width="60%">
          <h2 style="color:#F25C2A; margin:0 0 5px 0;">JOF INDIA </h2>
          <p style="font-size:12px; color:#555; margin:0;">Pune, Maharashtra - India<br>support@joffitness.com</p>
        </td>
        <td width="40%" align="right">
          <h2 style="color:#555; margin:0 0 5px 0;">INVOICE</h2>
          <p style="font-size:12px; margin:0;">
            Invoice: <b><?= $invoice_no ?></b><br>
            GST NO: 27AAAAP0267H2ZN<br>
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
            <tr>
              <td align="left">Subtotal (Base)</td>
              <td align="right">₹<?= number_format($base_amount, 2) ?></td>
            </tr>
            <tr>
              <td align="left">Tax (18% GST)</td>
              <td align="right">₹<?= number_format($tax_amount, 2) ?></td>
            </tr>
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
                <td align="right" style="color:#EF4444">₹<?= number_format($data['balance_pending'], 2) ?></td>
              </tr>
            <?php endif; ?>
          </table>
        </td>
      </tr>
    </table>
    <br><br>
    <div style="text-align:center; font-size:14px; font-weight:bold; color:#F25C2A; margin-top:20px;">
      Thank you for choosing JOF!
    </div>

    <div class="footer-actions">
      <button class="btn btn-secondary" onclick="window.print()">Print Invoice</button>
      <button class="btn btn-primary" onclick="window.print()">Download PDF</button>
    </div>
  </div>

</body></html>