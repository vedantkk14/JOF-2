<?php
/**
 * Enquiry Confirmation Email
 * * Sends a "Thank You" email when a user submits the enquiry form.
 * Uses the standard JOF branding and layout.
 */

require_once __DIR__ . '/PHPMailer/Exception.php';
require_once __DIR__ . '/PHPMailer/PHPMailer.php';
require_once __DIR__ . '/PHPMailer/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

/**
 * Send enquiry confirmation email to the user
 */
function sendEnquiryEmail($full_name, $email)
{
    $result = ['success' => false, 'error' => null];

    try {
        $mail = new PHPMailer(true);

        // SMTP Configuration
        $mail->isSMTP();
        $mail->Host = 'smtp.gmail.com';
        $mail->SMTPAuth = true;
        $mail->Username = 'iglmembershipid@gmail.com';
        $mail->Password = 'hclvlxtfmfxnywwm';
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        $mail->Port = 465;
        $mail->CharSet = 'UTF-8';

        // Email Settings
        $mail->setFrom('iglmembershipid@gmail.com', 'JOF INDIA');
        $mail->addReplyTo('iglmembershipid@gmail.com', 'JOF INDIA Support');
        $mail->addAddress($email, $full_name);
        $mail->isHTML(true);
        $mail->Subject = 'Thank You for Reaching Out to JOF INDIA!';

        $enquiry_date = date("M d, Y");

        // HTML Email Template — Matching JOF Design
        $mail->Body = "
        <!DOCTYPE html>
        <html>
        <head>
            <style>
                body { margin: 0; padding: 0; background-color: #F0F2F5; font-family: 'Segoe UI', Helvetica, Arial, sans-serif; }
                .email-container { max-width: 600px; margin: 0 auto; background: #ffffff; }
                .content { padding: 30px 40px 40px 40px; color: #333333; }
                .welcome-text { font-size: 16px; margin: 0 0 20px 0; color: #4b5563; line-height: 1.6; }
                .status-box { background: #1a1512; border-left: 5px solid #F25C2A; border-radius: 4px; padding: 20px; margin: 25px 0; }
                .status-header { font-size: 12px; color: #F25C2A; font-weight: bold; letter-spacing: 2px; text-transform: uppercase; margin-bottom: 10px; }
                .status-text { font-size: 14px; color: #ffffff; line-height: 1.8; }
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
                                                <p style='margin: 0 0 4px 0; font-size: 13px; color: #ffffff; opacity: 0.85; text-transform: uppercase; letter-spacing: 3px; font-weight: 600;'>ENQUIRY RECEIVED</p>
                                                <h1 style='margin: 0; font-size: 28px; color: #ffffff; font-weight: 800; font-family: Arial, Helvetica, sans-serif;'>" . htmlspecialchars($full_name) . "</h1>
                                                <p style='margin: 10px 0 0 0; font-size: 14px; color: #ffffff; opacity: 0.9; line-height: 1.5;'>Thank you for reaching out! 🏋️‍♂️</p>
                                            </td>
                                        </tr>
                                    </table>
                                </td>
                            </tr>
                            <tr>
                                <td class='content'>
                                    <p class='welcome-text'>Hello <strong>" . htmlspecialchars($full_name) . "</strong>,</p>
                                    <p class='welcome-text'>Thank you for showing interest in <strong>JOF INDIA</strong>! We have successfully received your enquiry on <strong>$enquiry_date</strong>.</p>

                                    <div class='status-box'>
                                        <div class='status-header'>WHAT HAPPENS NEXT?</div>
                                        <p class='status-text'>
                                            📞 <strong>Callback:</strong> Our fitness consultant will call you within 24 hours.<br>
                                            🎯 <strong>Consultation:</strong> We will discuss your health and fitness goals.<br>
                                            </p>
                                    </div>

                                    <div class='info-box'>
                                        <p class='info-title'>💡 Why Choose JOF INDIA?</p>
                                        <p class='info-text'>✓ Expert, certified personal trainers</p>
                                        <p class='info-text'>✓ Custom diet & nutrition planning</p>
                                        <p class='info-text'>✓ Premium indoor & outdoor functional training</p>
                                        <p class='info-text'>✓ A highly motivating community environment</p>
                                    </div>

                                    <p class='welcome-text' style='text-align: center; margin-top: 30px;'>
                                        Can't wait? Feel free to call us directly at the number below.
                                    </p>
                                </td>
                            </tr>
                            <tr>
                                <td class='footer'>
                                    <p class='footer-contact'>📞 +91 779-848-7209 &nbsp;|&nbsp; ✉️ iglmembershipid@gmail.com</p>
                                    <p class='footer-text'>© " . date("Y") . " JOF INDIA. All rights reserved.</p>
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

        $mail->send();
        $result['success'] = true;

    } catch (Exception $e) {
        $result['error'] = 'Email Error: ' . $mail->ErrorInfo;
        error_log('Enquiry Email Failed: ' . $mail->ErrorInfo);
    }

    return $result;
}
?>