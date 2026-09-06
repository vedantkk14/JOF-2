<?php
/**
 * Registration Confirmation Email
 * 
 * Sends a confirmation email when a new member is added via the Add Member form.
 * Uses the same JOF branding and layout as the welcome email.
 * No invoice attachment — payment hasn't been confirmed yet.
 */

require_once __DIR__ . '/PHPMailer/Exception.php';
require_once __DIR__ . '/PHPMailer/PHPMailer.php';
require_once __DIR__ . '/PHPMailer/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

/**
 * Send registration confirmation email to a newly added member
 */
function sendRegistrationEmail($member_name, $email)
{
    $result = ['success' => false, 'error' => null];

    try {
        $mail = new PHPMailer(true);

        // SMTP Configuration (same as welcome email)
        $mail->isSMTP();
        $mail->Host = 'smtp.gmail.com';
        $mail->SMTPAuth = true;
        $mail->Username = 'info@jofindia.com';
        $mail->Password = 'tzhiwibunjrfgfjj';
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = 587;
        $mail->CharSet = 'UTF-8';

        // Email Settings
        $mail->setFrom('info@jofindia.com', 'JOF INDIA');
        $mail->addAddress($email, $member_name);
        $mail->isHTML(true);
        $mail->Subject = 'Registration Successful — Welcome to JOF INDIA!';

        $registration_date = date("M d, Y");

        // HTML Email Template — same layout as send_welcome_email.php
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
                .highlight-box { background: #FFFBEB; border: 1px solid #FCD34D; border-radius: 8px; padding: 20px; margin: 25px 0; text-align: center; }
                .highlight-text { font-size: 18px; font-weight: 700; color: #92400E; margin: 0; }
                .highlight-sub { font-size: 13px; color: #B45309; margin: 8px 0 0 0; }
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
                            <!-- Orange Banner with Logo -->
                            <tr>
                                <td style='background-color: #F25C2A; padding: 30px 35px;'>
                                    <table width='100%' border='0' cellspacing='0' cellpadding='0'>
                                        <tr>
                                            <td width='120' valign='middle' style='padding-right: 25px;'>
                                                <img src='https://www.jofindia.com/assets/img/f-logo.png' alt='JOF' width='110' style='width: 110px; max-width: 110px; height: auto; display: block;'>
                                            </td>
                                            <td valign='middle'>
                                                <p style='margin: 0 0 4px 0; font-size: 13px; color: #ffffff; opacity: 0.85; text-transform: uppercase; letter-spacing: 3px; font-weight: 600;'>REGISTRATION CONFIRMED</p>
                                                <h1 style='margin: 0; font-size: 28px; color: #ffffff; font-weight: 800; font-family: Arial, Helvetica, sans-serif;'>" . htmlspecialchars($member_name) . "</h1>
                                                <p style='margin: 10px 0 0 0; font-size: 14px; color: #ffffff; opacity: 0.9; line-height: 1.5;'>You've been successfully registered! 🎉</p>
                                            </td>
                                        </tr>
                                    </table>
                                </td>
                            </tr>
                            <tr>
                                <td class='content'>
                                    <p class='welcome-text'>Hello <strong>" . htmlspecialchars($member_name) . "</strong>,</p>
                                    <p class='welcome-text'>Congratulations! Your registration at <strong>JOF INDIA</strong> has been successfully completed on <strong>$registration_date</strong>.</p>

                                    

                                    <div class='status-box'>
                                        <div class='status-header'>REGISTRATION STATUS</div>
                                        <p class='status-text'>
                                            ✅ <strong>Registered:</strong> Your profile has been created<br>
                                            ⏳ <strong>Payment:</strong> Awaiting admin confirmation<br>
                                            🔒 <strong>Plan Activation:</strong> Within 48 hours of payment verification
                                        </p>
                                    </div>

                                    <div class='info-box'>
                                        <p class='info-title'>📋 What's Next?</p>
                                        <p class='info-text'>✓ Your payment will be verified by our admin team</p>
                                        <p class='info-text'>✓ Once confirmed, you'll receive a welcome email with your invoice</p>
                                        <p class='info-text'>✓ Your membership plan will be activated within 48 hours</p>
                                        <p class='info-text'>✓ For queries, contact us at the number below</p>
                                    </div>

                                    <p class='welcome-text' style='text-align: center; margin-top: 30px;'>
                                        Have questions? We're here to help! Contact us anytime.
                                    </p>
                                </td>
                            </tr>
                            <tr>
                                <td class='footer'>
                                    <p class='footer-contact'>📞 +91 779-848-7209 &nbsp;|&nbsp; ✉️ info@jofindia.com</p>
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

        $mail->send();
        $result['success'] = true;

    } catch (Exception $e) {
        $result['error'] = 'Email Error: ' . $mail->ErrorInfo;
        error_log('Registration Email Failed: ' . $mail->ErrorInfo);
    }

    return $result;
}
?>
