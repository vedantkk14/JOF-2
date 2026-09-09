<?php
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params(['lifetime'=>0,'path'=>'/','secure'=>isset($_SERVER['HTTPS'])&&$_SERVER['HTTPS']==='on','httponly'=>true,'samesite'=>'Strict']);
    session_start();
}

// 1. Connect to Database
require_once "../config.php";

if ($_SERVER["REQUEST_METHOD"] == "POST") {

    // ── CSRF Validation ──────────────────────────────────────────
    $submitted_csrf = $_POST['_csrf_token'] ?? '';
    $stored_csrf    = $_SESSION['_csrf_token'] ?? '';
    if (!$stored_csrf || !hash_equals($stored_csrf, $submitted_csrf)) {
        $_SESSION['status_msg']  = 'Invalid security token. Please try again.';
        $_SESSION['status_type'] = 'error';
        header('Location: ../templates/forgot_password.php');
        exit;
    }
    unset($_SESSION['_csrf_token']);

    $email = trim(filter_var($_POST['email'] ?? '', FILTER_SANITIZE_EMAIL));
    $table_name = "user_data";

    // Check if table exists
    $check_table = $conn->query("SHOW TABLES LIKE '$table_name'");
    if ($check_table->num_rows == 0) {
        die("Error: Table '$table_name' does not exist.");
    }

    // Check if user exists
    $sql = "SELECT id, full_name FROM $table_name WHERE email = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        $user_row = $result->fetch_assoc();
        $member_name = $user_row['full_name'] ?? 'User';

        // Generate cryptographically secure 6-digit OTP
        $otp = sprintf('%06d', random_int(100000, 999999));
        $token_hash = hash('sha256', $otp); // Hash OTP for storage

        // Update DB: 15-minute expiry
        $update_sql = "UPDATE $table_name SET reset_token_hash = ?, reset_token_expires_at = DATE_ADD(NOW(), INTERVAL 15 MINUTE) WHERE email = ?";
        $update_stmt = $conn->prepare($update_sql);

        $update_stmt->bind_param("ss", $token_hash, $email);

        if ($update_stmt->execute()) {

            // Send Email using PHPMailer
            require_once __DIR__ . '/../auth/PHPMailer/Exception.php';
            require_once __DIR__ . '/../auth/PHPMailer/PHPMailer.php';
            require_once __DIR__ . '/../auth/PHPMailer/SMTP.php';
            require_once __DIR__ . '/../auth/mail_config.php';

            $mail = new PHPMailer\PHPMailer\PHPMailer(true);

            try {
                // Shared SMTP credentials (auth/mail_config.php → .env)
                jof_configure_mailer($mail);

                // Recipient
                $mail->addAddress($email, $member_name);

                // Content
                $mail->isHTML(true);
                $mail->Subject = 'Password Reset OTP - JOF INDIA';

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
                        .otp-box { background: #1a1512; border-left: 5px solid #F25C2A; border-radius: 4px; padding: 30px; margin: 25px 0; text-align: center; }
                        .otp-header { font-size: 12px; color: #F25C2A; font-weight: bold; letter-spacing: 2px; text-transform: uppercase; margin-bottom: 15px; }
                        .otp-code { font-size: 38px; font-weight: 800; letter-spacing: 8px; color: #ffffff; font-family: 'Courier New', Courier, monospace; }
                        .info-box { background: #F9FAFB; border: 1px solid #E5E7EB; border-radius: 8px; padding: 20px; margin: 25px 0; }
                        .info-title { font-size: 16px; font-weight: bold; color: #1a202c; margin: 0 0 10px 0; }
                        .info-text { font-size: 14px; color: #6b7280; margin: 5px 0; line-height: 1.5; }
                        .footer { background-color: #000000; padding: 30px; text-align: center; color: #9ca3af; font-size: 14px; }
                        .footer-contact { margin: 0 0 10px 0; font-size: 16px; color: #ffffff; font-weight: bold; }
                        .footer-text { margin: 5px 0; font-size: 12px; }
                        .warning-text { color: #ef4444; font-size: 13px; margin-top: 20px; font-style: italic; }
                    </style>
                </head>
                <body bgcolor='#F0F2F5'>
                    <table width='100%' border='0' cellspacing='0' cellpadding='0' bgcolor='#F0F2F5'>
                        <tr>
                            <td align='center' style='padding: 40px 0;'>
                                <table class='email-container' width='600' border='0' cellspacing='0' cellpadding='0' style='background-color: #ffffff; border-radius: 12px; overflow: hidden; box-shadow: 0 4px 10px rgba(0,0,0,0.1);'>
                                    <!-- Orange Header Banner with Logo -->
                                    <tr>
                                        <td style='background-color: #F25C2A; padding: 30px 35px;'>
                                            <table width='100%' border='0' cellspacing='0' cellpadding='0'>
                                                <tr>
                                                    <td width='120' valign='middle' style='padding-right: 25px;'>
                                                        <img src='https://www.jofindia.com/assets/img/f-logo.png' alt='JOF INDIA' width='110' style='width: 110px; max-width: 110px; height: auto; display: block;'>
                                                    </td>
                                                    <td valign='middle'>
                                                        <p style='margin: 0 0 4px 0; font-size: 13px; color: #ffffff; opacity: 0.85; text-transform: uppercase; letter-spacing: 3px; font-weight: 600;'>SECURITY VERIFICATION</p>
                                                        <h1 style='margin: 0; font-size: 24px; color: #ffffff; font-weight: 800; font-family: Arial, Helvetica, sans-serif;'>Password Reset</h1>
                                                    </td>
                                                </tr>
                                            </table>
                                        </td>
                                    </tr>
                                    <tr>
                                        <td class='content'>
                                            <p class='welcome-text'>Hello <strong>" . htmlspecialchars($member_name) . "</strong>,</p>
                                            <p class='welcome-text'>We received a request to reset the password for your <strong>JOF INDIA</strong> account. Please use the following One-Time Password (OTP) to proceed with the reset process:</p>
                                            
                                            <div class='otp-box'>
                                                <div class='otp-header'>YOUR OTP CODE</div>
                                                <div class='otp-code'>$otp</div>
                                            </div>
                                            
                                            <div class='info-box'>
                                                <p class='info-title'>🕒 Code Validity</p>
                                                <p class='info-text'>This code is valid for <strong>15 minutes</strong>. If the code expires, you will need to request a new one from the forgot password page.</p>
                                            </div>
                                            
                                            <p class='warning-text'>
                                                ⚠️ <strong>Security Notice:</strong> If you did not request this password reset, please ignore this email or contact our support team immediately if you suspect unauthorized access.
                                            </p>
                                        </td>
                                    </tr>
                                    <tr>
                                        <td class='footer'>
                                            <p class='footer-contact'>📞 +91 779-848-7209 &nbsp;|&nbsp; ✉️ iglmembershipid@gmail.com</p>
                                            <p class='footer-text'>© 2026 JOF INDIA. All rights reserved.</p>
                                            <p class='footer-text'>Aurelia, Pancard Road, Baner, Pune-411045, Maharashtra</p>
                                        </td>
                                    </tr>
                                </table>
                            </td>
                        </tr>
                    </table>
                </body>
                </html>";

                $mail->AltBody = "Hello $member_name,\n\nYour OTP for password reset at JOF INDIA is: $otp\n\nThis code is valid for 15 minutes.\n\nIf you did not request this, please ignore this email.";

                $mail->send();

                // Store email in session for the verification page
                $_SESSION['reset_email'] = $email;
                $_SESSION['status_msg'] = "An OTP has been sent to your email address.";
                $_SESSION['status_type'] = "success";

                header("Location: ../templates/verify_otp.php");
                exit;

            } catch (Exception $e) {
                $_SESSION['status_msg'] = "Error sending email. Please try again.";
                $_SESSION['status_type'] = "error";
                error_log("OTP Email failed: {$mail->ErrorInfo}");
            }

        } else {
            $_SESSION['status_msg'] = "Database error: Could not save OTP.";
            $_SESSION['status_type'] = "error";
        }
    } else {
        $_SESSION['status_msg'] = "Email not found in database.";
        $_SESSION['status_type'] = "error";
    }

    header("Location: ../templates/forgot_password.php");
    exit;
}
?>
