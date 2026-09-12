<?php
/**
 * Welcome Email Function with Invoice PDF Attachment
 */

require __DIR__ . '/PHPMailer/Exception.php';
require __DIR__ . '/PHPMailer/PHPMailer.php';
require __DIR__ . '/PHPMailer/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require_once __DIR__ . '/invoice_helper.php';

function sendWelcomeEmail($member_name, $email, $payment_id, $payment_data)
{
    global $conn; // Secure the global connection
    $result = ['success' => false, 'error' => null];

    try {
        $mail = new PHPMailer(true);

        // Shared SMTP credentials (auth/mail_config.php → .env)
        require_once __DIR__ . '/mail_config.php';
        jof_configure_mailer($mail);

        $mail->addAddress($email, $member_name);
        $mail->isHTML(true);
        $mail->Subject = 'Welcome to JOF India! Registration Complete';

        $pay_mode = 'cash';

        $local_conn = $conn ?? $GLOBALS['conn'] ?? null;

        // Fetch securely 
        if (!empty($payment_data['payment_mode']) && strtolower(trim($payment_data['payment_mode'])) !== 'n/a') {
            $pay_mode = strtolower(trim($payment_data['payment_mode']));
        } else {
            if ($local_conn) {
                $stmt = $local_conn->prepare("SELECT payment_mode FROM member_payments WHERE payment_id = ?");
                if ($stmt) {
                    $stmt->bind_param("i", $payment_id);
                    $stmt->execute();
                    $res = $stmt->get_result();
                    if ($row = $res->fetch_assoc()) {
                        if (!empty($row['payment_mode']) && strtolower(trim($row['payment_mode'])) !== 'n/a') {
                            $pay_mode = strtolower(trim($row['payment_mode']));
                        }
                    }
                    $stmt->close();
                }
            }
        }

        // Pass $local_conn securely
        $invoice_no = getInvoiceSerialNumber($local_conn, $payment_id, $pay_mode);
        $payment_data['payment_mode'] = ucfirst($pay_mode);
        $invoice_date = date("M d, Y");

        $membership_type = ucfirst($payment_data['membership_type'] ?? 'General');
        $duration = $payment_data['duration'] ?? 1;
        $total_amount = $payment_data['total_amount'] ?? 0;
        $amount_received = $payment_data['amount_received'] ?? 0;
        $balance_pending = $payment_data['balance_pending'] ?? 0;
        $start_date = date("M d, Y", strtotime($payment_data['start_date'] ?? 'now'));
        $end_date = date("M d, Y", strtotime($payment_data['end_date'] ?? 'now'));

        // HTML Email Template
        $mail->Body = "
        <!DOCTYPE html>
        <html>
        <head>
            <style>
                body { margin: 0; padding: 0; background-color: #F0F2F5; font-family: 'Segoe UI', Helvetica, Arial, sans-serif; }
                .email-container { max-width: 600px; margin: 0 auto; background: #ffffff; }
                .content { padding: 30px 40px 40px 40px; color: #333333; }
                .welcome-text { font-size: 16px; margin: 0 0 20px 0; color: #4b5563; line-height: 1.6; }
                .invoice-box { background: #1a1512; border-left: 5px solid #F25C2A; border-radius: 4px; padding: 20px; margin: 25px 0; }
                .invoice-header { font-size: 12px; color: #F25C2A; font-weight: bold; letter-spacing: 2px; text-transform: uppercase; margin-bottom: 10px; }
                .invoice-row { display: table; width: 100%; margin-bottom: 8px; font-size: 14px; }
                .invoice-label { display: table-cell; color: #9ca3af; width: 40%; }
                .invoice-value { display: table-cell; color: #ffffff; font-weight: 600; text-align: right; }
                .total-row { border-top: 2px solid #374151; margin-top: 12px; padding-top: 12px; }
                .total-row .invoice-value { font-size: 18px; color: #F25C2A; }
                .info-box { background: #F9FAFB; border: 1px solid #E5E7EB; border-radius: 8px; padding: 20px; margin: 25px 0; }
                .info-title { font-size: 16px; font-weight: bold; color: #1a202c; margin: 0 0 10px 0; }
                .info-text { font-size: 14px; color: #6b7280; margin: 5px 0; line-height: 1.5; }
                .footer { background-color: #000000; padding: 30px; text-align: center; color: #9ca3af; font-size: 14px; }
                .footer-contact { margin: 0 0 10px 0; font-size: 16px; color: #ffffff; font-weight: bold; }
                .footer-text { margin: 5px 0; font-size: 12px; }
            </style>
        </head>
        <body bgcolor='#F0F2F5'>
            <table width='100%' border='0' cellspacing='0' cellpadding='0' bgcolor='#F0F2F5'>
                <tr>
                    <td align='center' style='padding: 40px 0;'>
                        <table class='email-container' width='600' border='0' cellspacing='0' cellpadding='0' style='background-color: #ffffff; border-radius: 12px; overflow: hidden; box-shadow: 0 4px 10px rgba(0,0,0,0.1);'>
                            <tr>
                                <td style='background-color: #F25C2A; padding: 30px 35px;'>
                                    <table width='100%' border='0' cellspacing='0' cellpadding='0'>
                                        <tr>
                                            <td width='120' valign='middle' style='padding-right: 25px;'>
                                                <img src='https://www.jofindia.com/assets/img/f-logo.png' alt='JOF' width='110' style='width: 110px; max-width: 110px; height: auto; display: block;'>
                                            </td>
                                            <td valign='middle'>
                                                <p style='margin: 0 0 4px 0; font-size: 13px; color: #ffffff; opacity: 0.85; text-transform: uppercase; letter-spacing: 3px; font-weight: 600;'>WELCOME TO THE FAMILY</p>
                                                <h1 style='margin: 0; font-size: 28px; color: #ffffff; font-weight: 800; font-family: Arial, Helvetica, sans-serif;'>" . htmlspecialchars($member_name) . "</h1>
                                                <p style='margin: 10px 0 0 0; font-size: 14px; color: #ffffff; opacity: 0.9; line-height: 1.5;'>Your membership is now <strong>active</strong> &mdash; let&rsquo;s get started! 💪</p>
                                            </td>
                                        </tr>
                                    </table>
                                </td>
                            </tr>
                            <tr>
                                <td class='content'>
                                    <p class='welcome-text'>Congratulations on taking the first step towards a healthier, stronger you! Your membership at <strong>JOF India</strong> is now active.</p>
                                    <p class='welcome-text'>We're thrilled to have you on board and can't wait to see you achieve your fitness goals with our expert trainers and state-of-the-art facilities.</p>
                                    
                                    <div class='invoice-box'>
                                        <div class='invoice-header'>PAYMENT SUMMARY</div>
                                        <div class='invoice-row'>
                                            <div class='invoice-label'>Invoice Number:</div>
                                            <div class='invoice-value'>$invoice_no</div>
                                        </div>
                                        <div class='invoice-row'>
                                            <div class='invoice-label'>Date:</div>
                                            <div class='invoice-value'>$invoice_date</div>
                                        </div>
                                        <div class='invoice-row'>
                                            <div class='invoice-label'>Plan:</div>
                                            <div class=\"invoice-value\">$membership_type ($duration Weeks)</div>
                                        </div>
                                        <div class=\"invoice-row\">
                                            <div class=\"invoice-label\">Duration:</div>
                                            <div class=\"invoice-value\">$start_date - $end_date</div>
                                        </div>
                                        <div class=\"invoice-row\">
                                            <div class=\"invoice-label\">Total Amount:</div>
                                            <div class=\"invoice-value\">₹" . number_format($total_amount, 2) . "</div>
                                        </div>
                                        <div class=\"invoice-row\">
                                            <div class=\"invoice-label\">Amount Paid:</div>
                                            <div class=\"invoice-value\" style=\"color: #10b981;\">₹" . number_format($amount_received, 2) . "</div>
                                        </div>
                                        <div class=\"invoice-row total-row\">
                                            <div class=\"invoice-label\">Balance Due:</div>
                                            <div class=\"invoice-value\">₹" . number_format($balance_pending, 2) . "</div>
                                        </div>
                                    </div>
                                    
                                    " . ($balance_pending > 0 ? "
                                    <div class='info-box' style='background: #FEF2F2; border-color: #FCA5A5;'>
                                        <p class='info-title' style='color: #DC2626;'>⚠️ Pending Payment</p>
                                        <p class='info-text'>You have a pending balance of <strong>₹" . number_format($balance_pending, 2) . "</strong>. Please clear this at your earliest convenience to enjoy uninterrupted services.</p>
                                    </div>
                                    " : "") . "
                                    
                                    <div class='info-box'>
                                        <p class='info-title'>📋 What's Next?</p>
                                        <p class='info-text'>✓ Your detailed invoice is attached to this email</p>
                                        <p class='info-text'>✓ Our trainers will guide you through your personalized fitness plan</p>
                                    </div>
                                    
                                    <p class='welcome-text' style='text-align: center; margin-top: 30px;'>
                                        Have questions? We're here to help! Contact us anytime.
                                    </p>
                                </td>
                            </tr>
                            <tr>
                                <td class='footer'>
                                    <p class='footer-contact'>📞 +91 779-848-7209 &nbsp;|&nbsp; ✉️ vrishabhchadchan1@gmail.com</p>
                                    <p class='footer-text'>© 2026 JOF INDIA. All rights reserved.</p>
                                    <p class='footer-text'>Aurelia, Pancard Road, Baner, Pune-411045, Maharashtra</p>
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>
            </table>
        </body>
        </html>
        ";

        // Generate and Attach PDF Invoice (Explicitly pass $invoice_no so TCPDF doesn't recalculate it)
        $pdfContent = generateInvoicePDF($payment_id, $payment_data, $member_name, $email, $invoice_no);
        if ($pdfContent) {
            $mail->addStringAttachment($pdfContent, "Invoice_$invoice_no.pdf", 'base64', 'application/pdf');
        }

        $mail->send();
        $result['success'] = true;

    } catch (Exception $e) {
        $result['error'] = 'Email Error: ' . $mail->ErrorInfo;
        error_log('Welcome Email Failed: ' . $mail->ErrorInfo);
    }

    return $result;
}

function generateInvoicePDF($payment_id, $payment_data, $member_name, $email, $invoice_no = '')
{
    global $conn;
    $tcpdfPath = __DIR__ . '/../vendor/tcpdf/tcpdf.php';

    if (!file_exists($tcpdfPath)) {
        error_log('TCPDF not found at: ' . $tcpdfPath);
        return false;
    }

    try {
        require_once($tcpdfPath);

        $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);

        $pay_mode = strtolower(trim($payment_data['payment_mode'] ?? 'cash'));

        // If not passed, fetch it properly using globals
        if (empty($invoice_no)) {
            $local_conn = $conn ?? $GLOBALS['conn'] ?? null;
            $invoice_no = getInvoiceSerialNumber($local_conn, $payment_id, $pay_mode);
        }

        $mode_display = ucfirst($pay_mode);
        if ($pay_mode === 'bank_transfer' || $pay_mode === 'bank transfer') {
            $mode_display = 'Bank Transfer';
        }

        $pdf->SetCreator('JOF India');
        $pdf->SetAuthor('JOF India');
        $pdf->SetTitle("Invoice $invoice_no");
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetMargins(15, 15, 15);
        $pdf->SetAutoPageBreak(true, 15);
        $pdf->AddPage();

        $invoice_date = date("M d, Y");
        $membership_type = ucfirst($payment_data['membership_type'] ?? 'General');
        $duration = $payment_data['duration'] ?? 1;
        $total_amount = $payment_data['total_amount'] ?? 0;
        $amount_received = $payment_data['amount_received'] ?? 0;
        $balance_pending = $payment_data['balance_pending'] ?? 0;

        $status = 'UNPAID';
        $status_color = '#EF4444';
        if ($balance_pending <= 0.1) {
            $status = 'PAID';
            $status_color = '#10B981';
        } elseif ($amount_received > 0) {
            $status = 'PARTIAL';
            $status_color = '#F97316';
        }

        // ==========================================
        // 1. BULLETPROOF LOGO - Use TCPDF native Image()
        // ==========================================
        $logo_path = '';
        $search_paths = [
            __DIR__ . '/../icons/images/logo-invoice.jpeg',
            __DIR__ . '/../icons/logo-invoice.jpeg',
            __DIR__ . '/../icons/images/logo-invoice.jpg',
            __DIR__ . '/../icons/logo-invoice.jpg',
        ];

        foreach ($search_paths as $path) {
            $resolved = realpath($path);
            if ($resolved && file_exists($resolved)) {
                $logo_path = $resolved;
                break;
            }
        }

        // Place logo using TCPDF's native Image() method
        $headerY = $pdf->GetY();
        if (!empty($logo_path)) {
            $pdf->Image($logo_path, 15, $headerY, 30, 0, 'JPEG', '', '', false, 300, '', false, false, 0, false, false, false);
        }

        // Write INVOICE info on the right side only
        $headerHtml = '
        <table width="100%" cellpadding="0" cellspacing="0">
            <tr>
                <td width="60%">&nbsp;</td>
                <td width="40%" align="right" valign="top">
                    <span style="font-size:20px; font-weight:bold; color:#555;">INVOICE</span><br><br>
                    <span style="font-size:10px;">Invoice: <b>' . $invoice_no . '</b><br>
                    Date: ' . $invoice_date . '<br>
                    <b style="color:' . $status_color . ';">' . $status . '</b></span>
                </td>
            </tr>
        </table>';

        $pdf->writeHTML($headerHtml, true, false, true, false, '');

        // Position cursor just below the logo and write address aligned to logo
        $logoBottomY = $headerY + 26;
        $pdf->SetY($logoBottomY);
        $pdf->SetX(15);
        $addressHtml = '<span style="font-size:9px; color:#555;">Aurelia, Pancard Road, Baner, Pune-411045, Maharashtra<br>vrishabhchadchan1@gmail.com</span>';
        $pdf->writeHTML($addressHtml, true, false, true, false, '');

        // Rest of the invoice body
        $html = '
        <hr color="#E5E7EB">
        <br>
        <table width="100%">
            <tr>
                <td>
                    <p style="font-size:10px; color:#888;">BILL TO</p>
                    <h3 style="margin:0;">' . htmlspecialchars($member_name) . '</h3>
                    <p style="font-size:10px; color:#555;">' . htmlspecialchars($email) . '<br>
                    <b>Mode:</b> ' . $mode_display . '</p>
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
                <td>' . $membership_type . ' Plan<br><span style="color:#666;">' . $duration . ' Week(s) Access</span></td>
                <td align="center">1</td>
                <td align="right">Rs. ' . number_format($total_amount, 2) . '</td>
                <td align="right">Rs. ' . number_format($total_amount, 2) . '</td>
            </tr>';

        if ($balance_pending > 0) {
            $html .= '
            <tr>
                <td>Amount Received<br><span style="color:#666;">via ' . $mode_display . '</span></td>
                <td align="center">-</td>
                <td align="right">-</td>
                <td align="right" style="color:#10B981;">- Rs. ' . number_format($amount_received, 2) . '</td>
            </tr>';
        }

        $html .= '
        </table>
        <br><br>

        <table width="100%">
            <tr>
                <td width="60%">
                    <p style="font-size:10px; font-weight:bold;">PAYMENT DETAILS</p>
                    <p style="font-size:10px; color:#555;">Name: Joshua Sunil Sadanandan<br>Bank: HDFC Bank<br>Branch: Pancard Club Road<br>A/C No: 50100390953022<br>IFSC: HDFC0004793<br>Swift Code: HDFCINBB</p>
                </td>
                <td width="40%">
                    <table cellpadding="4" width="100%" style="font-size:10px;">
                        <tr><td colspan="2"><hr color="#E5E7EB"></td></tr>
                        <tr style="font-weight:bold; font-size:12px;">
                            <td align="left" color="#F25C2A">Total Plan Value</td>
                            <td align="right">Rs. ' . number_format($total_amount, 2) . '</td>
                        </tr>';

        if ($balance_pending > 0) {
            $html .= '
                        <tr style="font-weight:bold; font-size:11px;">
                            <td align="left" color="#EF4444">Balance Due</td>
                            <td align="right" color="#EF4444">Rs. ' . number_format($balance_pending, 2) . '</td>
                        </tr>';
        }

        $html .= '
                    </table>
                </td>
            </tr>
        </table>
        <br><br><br>
        <div style="text-align:center; font-size:12px; font-weight:bold; color:#F25C2A;">Thank you for choosing JOF!</div>
        ';

        $pdf->writeHTML($html, true, false, true, false, '');
        return $pdf->Output('', 'S');

    } catch (Exception $e) {
        error_log('PDF Generation Error: ' . $e->getMessage());
        return false;
    }
}
?>