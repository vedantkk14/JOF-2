<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require '../config.php';

require __DIR__ . '/PHPMailer/Exception.php';
require __DIR__ . '/PHPMailer/PHPMailer.php';
require __DIR__ . '/PHPMailer/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// 1. Calculate Target Date
$target_join_date = date('Y-m-d', strtotime('-27 days'));

echo "<h3>Sending reminders for members who joined on: $target_join_date</h3><hr>";

$sql = "SELECT * FROM members WHERE DATE(created_at) = '$target_join_date'";
$result = mysqli_query($conn, $sql);

if (mysqli_num_rows($result) > 0) {

    $mail = new PHPMailer(true);

    try {
        // --- SMTP CONFIGURATION ---
        $mail->isSMTP();
        $mail->Host = 'smtp.gmail.com';
        $mail->SMTPAuth = true;
        $mail->Username = 'vrishabhchadchan1@gmail.com';
        $mail->Password = 'qmhbeaswhyhsjbao';
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = 587;
        $mail->CharSet = 'UTF-8';

        $mail->setFrom('vrishabhchadchan1@gmail.com', 'JOF INDIA');
        $mail->isHTML(true);

        while ($row = mysqli_fetch_assoc($result)) {
            $name = $row['full_name'];
            $email = $row['email'];

            $join_ts = strtotime($row['created_at']);
            $expiry_date = date("d M Y", strtotime('+30 days', $join_ts));

            $mail->clearAddresses();
            $mail->addAddress($email, $name);

            $mail->Subject = "Membership Renewal at JOF INDIA";

            // --- PREMIUM EMAIL TEMPLATE ---
            $mail->Body = "
            <!DOCTYPE html>
            <html>
            <head>
                <style>
                    body { margin: 0; padding: 0; background-color: #F0F2F5; font-family: 'Segoe UI', Helvetica, Arial, sans-serif; }
                    .email-container { max-width: 600px; margin: 0 auto; background: #ffffff; }
                    .content { padding: 40px; color: #333333; }
                    .welcome-title { font-size: 24px; margin: 0 0 15px 0; color: #1a202c; font-weight: 700; }
                    .welcome-text { font-size: 16px; margin: 0 0 20px 0; color: #4b5563; line-height: 1.6; }
                    .status-box { background: #1a1512; border-left: 5px solid #F25C2A; border-radius: 4px; padding: 20px; margin: 25px 0; }
                    .status-header { font-size: 12px; color: #F25C2A; font-weight: bold; letter-spacing: 2px; text-transform: uppercase; margin-bottom: 10px; }
                    .status-text { font-size: 24px; color: #ffffff; font-weight: bold; margin-bottom: 5px; }
                    .cta-section { text-align: center; margin: 30px 0; }
                    .btn { display: inline-block; background-color: #F25C2A; color: #ffffff !important; padding: 14px 30px; text-decoration: none; border-radius: 6px; font-weight: bold; font-size: 16px; }
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
                                                    <h1 style='margin: 0; font-size: 26px; color: #ffffff; font-weight: 800; font-family: Arial, Helvetica, sans-serif;'>Membership Renewal at JOF INDIA</h1>
                                                </td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                                <tr>
                                    <td class='content'>
                                        <h1 class='welcome-title'>Hello $name,</h1>
                                        <p class='welcome-text'>We hope you're crushing your goals! This is a reminder that your membership at <strong>JOF INDIA</strong> is expiring soon.</p>
                                        
                                        <div class='status-box'>
                                            <div class='status-header'>EXPIRY DATE</div>
                                            <div class='status-text'>$expiry_date</div>
                                            <div style='font-size: 14px; color: #d97706; font-weight: 600;'>3 Days Remaining</div>
                                        </div>
                                        
                                        <p class='welcome-text'>To ensure uninterrupted access to the gym and your personalized training plans, please renew your membership before it expires.</p>
                                        
                                        <div class='cta-section'>
                                            <a href='https://jof-india.com/renew' class='btn'>Renew Membership Now</a>
                                        </div>
                                        
                                        <p class='welcome-text' style='text-align: center; margin-top: 30px;'>
                                            Need help? Our team is always here for you!
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

            $mail->send();
            echo "<p style='color:green;'>✅ Reminder sent to <strong>$name</strong> ($email)</p>";
        }

    } catch (Exception $e) {
        echo "<p style='color:red;'>❌ Mailer Error: {$mail->ErrorInfo}</p>";
    }

} else {
    echo "<p>No members found who joined exactly 27 days ago.</p>";
}
?>
