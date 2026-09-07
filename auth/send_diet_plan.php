<?php
/**
 * Send Diet Plan Email
 * * Handles generating a cumulative diet plan PDF (history) and sending it 
 * to the member via email.
 */

require_once __DIR__ . '/../config.php';
require __DIR__ . '/PHPMailer/Exception.php';
require __DIR__ . '/PHPMailer/PHPMailer.php';
require __DIR__ . '/PHPMailer/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

/**
 * Generate Cumulative Diet Plan PDF
 */
function generateDietPlanPDF($current_plan_id)
{
    global $conn;

    // Check if TCPDF is available
    $tcpdfPath = __DIR__ . '/../vendor/tcpdf/tcpdf.php';
    if (!file_exists($tcpdfPath)) {
        error_log('TCPDF not found at: ' . $tcpdfPath);
        return false;
    }

    require_once($tcpdfPath);

    // 1. Fetch Current Plan to get Client Name
    $stmt = $conn->prepare("SELECT * FROM diet_plans WHERE id = ?");
    $stmt->bind_param("i", $current_plan_id);
    $stmt->execute();
    $current_plan = $stmt->get_result()->fetch_assoc();

    if (!$current_plan)
        return false;

    // Extract Client Name
    $name_parts = explode(" - ", $current_plan['plan_name']);
    $client_name = trim($name_parts[0]);

    // 2. Fetch History (Cumulative)
    $history_sql = "SELECT * FROM diet_plans WHERE plan_name LIKE ? AND id <= ? ORDER BY id ASC";
    $search_name = $client_name . "%";
    $stmt_hist = $conn->prepare($history_sql);
    $stmt_hist->bind_param("si", $search_name, $current_plan_id);
    $stmt_hist->execute();
    $all_plans = $stmt_hist->get_result();

    // 3. Create PDF
    $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
    $pdf->SetCreator('JOF INDIA');
    $pdf->SetAuthor('JOF INDIA');
    $pdf->SetTitle("Diet Plan - $client_name");
    $pdf->setPrintHeader(false);
    $pdf->setPrintFooter(false);
    $pdf->SetMargins(15, 15, 15);
    $pdf->SetAutoPageBreak(true, 15);

    // Use a font that supports standard characters securely without rendering ? boxes
    $pdf->SetFont('dejavusans', '', 10);

    // 4. Format Text Function (Crucial for fixing the chaotic layout)
    $formatText = function ($text) {
        if (empty($text))
            return '<span style="color:#999;">Not specified</span>';

        // Remove Emojis and unwanted characters that break TCPDF
        $text = preg_replace('/[\x{10000}-\x{10FFFF}]/u', '', $text);

        // Clean up bad CSV/database string artifacts if present
        $text = trim($text, '"\' ');
        $text = str_replace(['","', '", "'], "\n", $text);

        $lines = explode("\n", $text);
        $formatted = [];

        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line))
                continue;

            // Strip leading question marks (failed emojis)
            $line = ltrim($line, '? ');
            // Strip markdown bold markers (** prefix/suffix)
            $line = preg_replace('/\*\*([^*]*)\*\*/', '$1', $line);
            $line = str_replace('**', '', $line);
            $safe_line = htmlspecialchars($line, ENT_QUOTES, 'UTF-8');

            // Detect Headings: If line is ALL CAPS or ends with a colon (e.g. WAKE UP (6 AM):)
            if (preg_match('/^[A-Z0-9\s\(\)\-\&]+:/', $line) || (strlen($line) > 3 && strtoupper($line) === $line)) {
                $prefix = empty($formatted) ? '' : '<br><br>';
                // Bold and Orange text for headers
                $formatted[] = $prefix . '<b style="color:#F25C2A; font-size:11px;">' . $safe_line . '</b>';
            } else {
                $formatted[] = '<span style="color:#333333; font-size:10px;">' . $safe_line . '</span>';
            }
        }

        return implode("<br>\n", $formatted);
    };

    // 5. Loop through plans and build clean strict HTML Tables
    while ($plan = $all_plans->fetch_assoc()) {
        $pdf->AddPage();
        $p_parts = explode(" - ", $plan['plan_name']);
        $phase_title = isset($p_parts[1]) ? $p_parts[1] : $plan['plan_name'];

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

        // Header: right side has client name + phase
        $headerHtml = '
        <table width="100%" cellpadding="0" cellspacing="0">
            <tr>
                <td width="50%">&nbsp;</td>
                <td width="50%" align="right" valign="top">
                    <b style="font-size:16px;">' . htmlspecialchars(strtoupper($client_name)) . '</b><br>
                    <span style="font-size:11px; color:#F25C2A;"><b>LIFESTYLE CHART &bull; ' . htmlspecialchars(strtoupper($phase_title)) . '</b></span>
                </td>
            </tr>
        </table>';

        $pdf->writeHTML($headerHtml, true, false, true, false, '');

        // Position below logo, write address
        $logoBottomY = $headerY + 26;
        if ($pdf->GetY() < $logoBottomY && !empty($logo_path)) {
            $pdf->SetY($logoBottomY);
        }
        $pdf->SetX(15);
        $addressHtml = '<span style="font-size:9px; color:#555;">Aurelia, Pancard Road, Baner, Pune-411045, Maharashtra<br>vedantkolhapure111@gmail.com</span>';
        $pdf->writeHTML($addressHtml, true, false, true, false, '');

        // ---- Rest of the page content ----
        $html = '
        <hr color="#E5E7EB" size="1">
        <br><br>

        <table width="100%" cellpadding="10" bgcolor="#F9FAFB" border="1" bordercolor="#E5E7EB">
            <tr>
                <td width="25%" align="center"><span style="font-size:10px; color:#666;">Goal</span><br><b>' . htmlspecialchars($plan['goal']) . '</b></td>
                <td width="25%" align="center"><span style="font-size:10px; color:#666;">Duration</span><br><b>' . $plan['duration'] . ' Weeks</b></td>
                <td width="25%" align="center"><span style="font-size:10px; color:#666;">Calories</span><br><b>' . $plan['calories'] . ' kcal</b></td>
                <td width="25%" align="center"><span style="font-size:10px; color:#666;">Trainer</span><br><b>' . htmlspecialchars($plan['trainer_name']) . '</b></td>
            </tr>
        </table>
        <br><br>

        <table width="100%" cellpadding="12" border="1" bordercolor="#E5E7EB">
            <tr>
                <td width="22%" align="center" bgcolor="#F9FAFB" style="border-right: 2px solid #F25C2A;">
                    <br><br><b style="color:#6B7280; font-size:11px;">MORNING<br>BLOCK</b>
                </td>
                <td width="78%" bgcolor="#FFFFFF">
                    ' . $formatText($plan['breakfast']) . '
                </td>
            </tr>
            <tr>
                <td width="22%" align="center" bgcolor="#F9FAFB" style="border-right: 2px solid #F25C2A;">
                    <br><br><b style="color:#6B7280; font-size:11px;">LUNCH<br>BLOCK</b>
                </td>
                <td width="78%" bgcolor="#FFFFFF">
                    ' . $formatText($plan['lunch']) . '
                </td>
            </tr>
            <tr>
                <td width="22%" align="center" bgcolor="#F9FAFB" style="border-right: 2px solid #F25C2A;">
                    <br><br><b style="color:#6B7280; font-size:11px;">MID MEAL</b>
                </td>
                <td width="78%" bgcolor="#FFFFFF">
                    ' . $formatText($plan['snack']) . '
                </td>
            </tr>
            <tr>
                <td width="22%" align="center" bgcolor="#F9FAFB" style="border-right: 2px solid #F25C2A;">
                    <br><br><b style="color:#6B7280; font-size:11px;">NIGHT<br>BLOCK</b>
                </td>
                <td width="78%" bgcolor="#FFFFFF">
                    ' . $formatText($plan['dinner']) . '
                </td>
            </tr>
        </table>

        <br><br>
        <div style="text-align:center; font-size:9px; color:#9CA3AF;">
            &copy; ' . date('Y') . ' JOF INDIA. All rights reserved. &bull; Trainer Contact: ' . htmlspecialchars($plan['trainer_name']) . '
        </div>
        ';

        $pdf->writeHTML($html, true, false, true, false, '');
    }

    return $pdf->Output('diet_plan.pdf', 'S');
}

/**
 * Send Diet Plan Email
 */
function sendDietPlanEmail($member_email, $member_name, $pdf_content, $plan_name)
{
    if (empty($pdf_content)) {
        return ['success' => false, 'error' => 'PDF Content empty'];
    }

    $result = ['success' => false, 'error' => null];
    $mail = new PHPMailer(true);

    try {
        // Shared SMTP credentials (auth/mail_config.php → .env)
        require_once __DIR__ . '/mail_config.php';
        jof_configure_mailer($mail);

        $mail->addAddress($member_email, $member_name);
        $mail->isHTML(true);
        $mail->Subject = 'Your Diet Plan - JOF INDIA';

        $safe_name = htmlspecialchars($member_name);
        $safe_plan = htmlspecialchars($plan_name);

        $mail->Body = "
<!DOCTYPE html>
<html>
<head><meta charset='UTF-8'></head>
<body bgcolor='#F0F2F5' style='margin:0;padding:0;font-family:Segoe UI,Helvetica,Arial,sans-serif;'>
<table width='100%' border='0' cellspacing='0' cellpadding='0' bgcolor='#F0F2F5'>
<tr><td align='center' style='padding:40px 0;'>
<table width='600' border='0' cellspacing='0' cellpadding='0' style='background:#ffffff;border-radius:12px;overflow:hidden;box-shadow:0 4px 10px rgba(0,0,0,0.1);'>

    <!-- Header with Logo -->
    <tr>
        <td style='background-color:#F25C2A;padding:30px 35px;'>
            <table width='100%' border='0' cellspacing='0' cellpadding='0'>
                <tr>
                    <td width='120' valign='middle' style='padding-right:25px;'>
                        <img src='https://www.jofindia.com/assets/img/f-logo.png' alt='JOF' width='110' style='width:110px;max-width:110px;height:auto;display:block;'>
                    </td>
                    <td valign='middle'>
                        <p style='margin:0 0 4px 0;font-size:13px;color:#ffffff;opacity:0.85;text-transform:uppercase;letter-spacing:3px;font-weight:600;'>YOUR PERSONALIZED</p>
                        <div style='font-size:28px;font-weight:800;letter-spacing:2px;color:#ffffff;'>DIET PLAN</div>
                    </td>
                </tr>
            </table>
        </td>
    </tr>

    <!-- Content -->
    <tr>
        <td style='padding:30px 40px 40px 40px;color:#333333;'>
            <h2 style='color:#F25C2A;margin:0 0 20px 0;'>Your New Diet Plan is Ready!</h2>
            <p style='font-size:16px;color:#4b5563;line-height:1.6;margin:0 0 15px 0;'>Hi <b>$safe_name</b>,</p>
            <p style='font-size:16px;color:#4b5563;line-height:1.6;margin:0 0 15px 0;'>Unlock your potential with your new personalized diet plan: <b>$safe_plan</b>.</p>
            <p style='font-size:16px;color:#4b5563;line-height:1.6;margin:0 0 15px 0;'>We have designed this plan to help you reach your specific fitness goals. Please find the detailed PDF attached to this email.</p>
            <p style='font-size:16px;color:#4b5563;line-height:1.6;margin:0 0 20px 0;'>This document includes your complete history for this plan, so you can track your progress.</p>
            <div style='background:#FFF7ED;padding:15px;border-left:4px solid #F25C2A;border-radius:4px;margin:0 0 20px 0;'>
                <strong>Tip:</strong> Consistency is key! Stick to the schedule and stay hydrated.
            </div>
            <p style='font-size:16px;color:#4b5563;line-height:1.6;margin:0;'>Best regards,<br>The JOF INDIA Team</p>
        </td>
    </tr>

    <!-- Footer -->
    <tr>
        <td style='background:#000000;padding:28px;text-align:center;'>
            <p style='margin:0 0 8px;font-size:16px;color:#fff;font-weight:700;'>&#128222; +91 779-848-7209 &nbsp;|&nbsp; &#9993;&#65039; vedantkolhapure111@gmail.com</p>
            <p style='margin:4px 0;font-size:12px;color:#6B7280;'>&copy; 2026 JOF INDIA. All rights reserved.</p>
            <p style='margin:4px 0;font-size:12px;color:#6B7280;'>Aurelia, Pancard Road, Baner, Pune-411045, Maharashtra</p>
        </td>
    </tr>

</table>
</td></tr>
</table>
</body>
</html>";

        // Attach PDF
        $mail->addStringAttachment($pdf_content, "Diet_Plan_$member_name.pdf", 'base64', 'application/pdf');

        $mail->send();
        $result['success'] = true;

    } catch (Exception $e) {
        $result['error'] = $mail->ErrorInfo;
        error_log("Diet Plan Email Error: " . $mail->ErrorInfo);
    }

    return $result;
}
?>