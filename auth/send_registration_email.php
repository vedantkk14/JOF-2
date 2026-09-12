<?php
/**
 * Registration Confirmation Email
 *
 * Sends a portal account confirmation email when a new user registers.
 * Uses the same PHPMailer pattern as send_reset_link.php (full namespace, no use-imports).
 */

function sendRegistrationEmail($member_name, $email)
{
    $result = ['success' => false, 'error' => null];

    try {
        // Require PHPMailer files directly
        require_once __DIR__ . '/PHPMailer/Exception.php';
        require_once __DIR__ . '/PHPMailer/PHPMailer.php';
        require_once __DIR__ . '/PHPMailer/SMTP.php';
        require_once __DIR__ . '/mail_config.php';

        $mail = new PHPMailer\PHPMailer\PHPMailer(true);

        // Shared SMTP credentials (auth/mail_config.php → .env)
        jof_configure_mailer($mail);

        // Email Settings
        $mail->addAddress($email, $member_name);
        $mail->isHTML(true);
        $mail->Subject = 'Account Created — Welcome to JOF INDIA Portal!';

        $registration_date = date("M d, Y");

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
                            <!-- Orange Banner with Logo -->
                            <tr>
                                <td style='background-color: #F25C2A; padding: 30px 35px;'>
                                    <table width='100%' border='0' cellspacing='0' cellpadding='0'>
                                        <tr>
                                            <td width='120' valign='middle' style='padding-right: 25px;'>
                                                <img src='https://www.jofindia.com/assets/img/f-logo.png' alt='JOF' width='110' style='width: 110px; max-width: 110px; height: auto; display: block;'>
                                            </td>
                                            <td valign='middle'>
                                                <p style='margin: 0 0 4px 0; font-size: 13px; color: #ffffff; opacity: 0.85; text-transform: uppercase; letter-spacing: 3px; font-weight: 600;'>ACCOUNT CREATED</p>
                                                <h1 style='margin: 0; font-size: 28px; color: #ffffff; font-weight: 800; font-family: Arial, Helvetica, sans-serif;'>" . htmlspecialchars($member_name) . "</h1>
                                                <p style='margin: 10px 0 0 0; font-size: 14px; color: #ffffff; opacity: 0.9; line-height: 1.5;'>Your portal account is ready! 🎉</p>
                                            </td>
                                        </tr>
                                    </table>
                                </td>
                            </tr>
                            <tr>
                                <td class='content'>
                                    <p class='welcome-text'>Hello <strong>" . htmlspecialchars($member_name) . "</strong>,</p>
                                    <p class='welcome-text'>Your account on the <strong>JOF INDIA Portal</strong> has been successfully created on <strong>$registration_date</strong>. You can now log in and access the system.</p>

                                    <div class='status-box'>
                                        <div class='status-header'>ACCOUNT STATUS</div>
                                        <p class='status-text'>
                                            ✅ <strong>Account Created:</strong> Your profile is active<br>
                                            🔐 <strong>Role:</strong> User &mdash; contact admin to update permissions<br>
                                            📱 <strong>Access:</strong> Sign in using your registered email
                                        </p>
                                    </div>

                                    <div class='info-box'>
                                        <p class='info-title'>📋 What You Can Do Next</p>
                                        <p class='info-text'>✓ Log in using your registered email and password</p>
                                        <p class='info-text'>✓ If you need elevated access, contact your system administrator</p>
                                        <p class='info-text'>✓ Keep your password safe and do not share it with anyone</p>
                                        <p class='info-text'>✓ For support, reach us at the contact details below</p>
                                    </div>

                                    <p class='welcome-text' style='text-align: center; margin-top: 30px;'>
                                        Welcome aboard! We're glad to have you with us.
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

        $mail->AltBody = "Hello $member_name,\n\nYour JOF INDIA Portal account has been successfully created on $registration_date.\n\nYou can now log in using your registered email and password.\n\nWelcome aboard!\n\nJOF INDIA Team\n+91 779-848-7209";

        $mail->send();
        $result['success'] = true;

    } catch (\Exception $e) {
        $result['error'] = 'Email Error: ' . $e->getMessage();
        error_log('[REGISTER] Registration email failed for ' . $email . ': ' . $e->getMessage());
    }

    return $result;
}
?>
