<?php
// handlers/consultation_handler.php
session_start();
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

require '../config.php';
require '../vendor/tcpdf/tcpdf.php';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {

    // --- Collect Inputs (new form fields) ---
    $member_name = trim($_POST['member_name'] ?? '');
    $trainer_name = trim($_POST['trainer_name'] ?? '');
    $payment_method = trim($_POST['payment_method'] ?? '');
    $price_per_session = floatval($_POST['price_per_session'] ?? 0);
    $base_price = floatval($_POST['base_price'] ?? 0);
    $tax_amount = floatval($_POST['tax_amount'] ?? 0);
    $total_amount = floatval($_POST['total_amount'] ?? 0);

    if (empty($member_name) || empty($trainer_name) || empty($payment_method)) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Please fill in all required fields.']);
        exit;
    }

    try {
        // 1. Insert into consultations table
        $stmt = $conn->prepare("
            INSERT INTO consultations
                (member_name, trainer_name, payment_method, price_per_session, base_price, tax_amount, total_amount)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->bind_param(
            "sssdddd",
            $member_name,
            $trainer_name,
            $payment_method,
            $price_per_session,
            $base_price,
            $tax_amount,
            $total_amount
        );
        $stmt->execute();
        $consult_id = $conn->insert_id;


        // 2. Generate Invoice PDF
        $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
        $pdf->SetCreator("Joshuaa's Outdoor Fitness");
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->AddPage();

        $invoice_no = "INV-" . date('Y') . "-" . str_pad($consult_id, 4, '0', STR_PAD_LEFT);
        $date_str = date('M d, Y');

        // ---- LOGO using TCPDF native Image() ----
        $logo_path = '';
        $logo_search = [
            __DIR__ . '/../icons/images/logo-invoice.jpeg',
            __DIR__ . '/../icons/logo-invoice.jpeg',
        ];
        foreach ($logo_search as $lp) {
            $resolved = realpath($lp);
            if ($resolved && file_exists($resolved)) {
                $logo_path = $resolved;
                break;
            }
        }

        $headerY = $pdf->GetY();
        if (!empty($logo_path)) {
            $pdf->Image($logo_path, 15, $headerY, 30, 0, 'JPEG', '', '', false, 300, '', false, false, 0, false, false, false);
        }

        // Write receipt info on the right side only
        $headerHtml = '
        <table width="100%" cellpadding="0" cellspacing="0">
            <tr>
                <td width="60%">&nbsp;</td>
                <td width="40%" align="right" valign="top">
                    <span style="font-size:20px; font-weight:bold; color:#555;">INVOICE</span><br><br>
                    <span style="font-size:10px;">Invoice: <b>' . $invoice_no . '</b><br>
                    Date: ' . $date_str . '<br>
                    <b style="color:#10B981;">PAID</b></span>
                </td>
            </tr>
        </table>';

        $pdf->writeHTML($headerHtml, true, false, true, false, '');

        // Position tighter below logo, write address (X shifted slightly to visually align with logo padding)
        $logoBottomY = $headerY + 24;
        $pdf->SetY($logoBottomY);
        $addressHtml = '<div style="font-size:9px; color:#555; line-height:1.2; margin:0;">Aurelia, Pancard Road, Baner, Pune-411045, Maharashtra<br>vrishabhchadchan1@gmail.com</div>';
        $pdf->writeHTMLCell(0, 0, 13, $logoBottomY, $addressHtml, 0, 1, false, true, 'L', true);

        $html = '
        <hr color="#E5E7EB">
        <br>
        <table width="100%">
            <tr>
                <td>
                    <p style="font-size:10px; color:#888;">BILL TO</p>
                    <h3 style="margin:0;">' . htmlspecialchars($member_name) . '</h3>
                </td>
                <td align="right">
                    <p style="font-size:10px; color:#888;">PAYMENT METHOD</p>
                    <p style="font-size:12px; font-weight:bold;">' . htmlspecialchars($payment_method) . '</p>
                </td>
            </tr>
        </table>
        <br><br>

        <table border="1" cellpadding="6" cellspacing="0" bordercolor="#E5E7EB" width="100%" style="font-size:10px;">
            <tr style="background-color:#F9FAFB; font-weight:bold; color:#333;">
                <th width="50%">DESCRIPTION</th>
                <th width="10%" align="center">QTY</th>
                <th width="20%" align="right">PRICE</th>
                <th width="20%" align="right">TOTAL</th>
            </tr>
            <tr>
                <td>Lifestyle Consultation Plan<br><span style="color:#666;">Trainer: ' . htmlspecialchars($trainer_name) . '</span></td>
                <td align="center">1</td>
                <td align="right">Rs. ' . number_format($base_price, 2) . '</td>
                <td align="right">Rs. ' . number_format($base_price, 2) . '</td>
            </tr>
        </table>
        <br><br>

        <table width="100%">
            <tr>
                <td width="60%">
                    <p style="font-size:10px; font-weight:bold;">PAYMENT DETAILS</p>
                    <p style="font-size:10px; color:#555;">Bank: HDFC Bank<br>Account: 556677889900<br>IFSC: HDFC0001234<br>UPI ID: jof@okaxis</p>
                </td>
                <td width="40%">
                    <table cellpadding="4" width="100%" style="font-size:10px;">
                        <tr><td align="left">Subtotal (Base)</td><td align="right">Rs. ' . number_format($base_price, 2) . '</td></tr>
                        <tr><td align="left">Tax (18% GST)</td><td align="right">Rs. ' . number_format($tax_amount, 2) . '</td></tr>
                        <tr><td colspan="2"><hr color="#E5E7EB"></td></tr>
                        <tr style="font-weight:bold; font-size:12px;">
                            <td align="left">Total Payable</td>
                            <td align="right">Rs. ' . number_format($total_amount, 2) . '</td>
                        </tr>
                    </table>
                </td>
            </tr>
        </table>
        <br><br><br>
        <div style="text-align:center; font-size:12px; font-weight:bold; color:#F25C2A;">Thank you for choosing JOF!</div>
        ';

        $pdf->writeHTML($html, true, false, true, false, '');
        $pdf_content = $pdf->Output($invoice_no . '.pdf', 'S');

        // 3. Return PDF as base64 via JSON
        $pdf_base64 = base64_encode($pdf_content);

        while (ob_get_level()) {
            ob_end_clean();
        }

        header('Content-Type: application/json');
        echo json_encode([
            'success' => true,
            'message' => 'Consultation booked successfully',
            'invoice_no' => $invoice_no,
            'pdf_base64' => $pdf_base64
        ]);
        exit;

    } catch (Exception $e) {
        if (!headers_sent()) {
            header('Content-Type: application/json');
        }
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
        exit;
    }
}
?>