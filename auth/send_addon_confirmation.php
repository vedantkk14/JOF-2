<?php
require __DIR__ . '/PHPMailer/Exception.php';
require __DIR__ . '/PHPMailer/PHPMailer.php';
require __DIR__ . '/PHPMailer/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

function sendAddonConfirmation($member_name, $member_email, $service_type, $scheduled_date, $scheduled_time, $price = 0)
{
    $mail = new PHPMailer(true);

    try {
        // SMTP Configuration
        $mail->isSMTP();
        $mail->Host = 'smtp.gmail.com';
        $mail->SMTPAuth = true;
        $mail->Username = 'vrishabhchadchan1@gmail.com';
        $mail->Password = 'qmhbeaswhyhsjbao';
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = 587;

        //Recipients



        $mail->setFrom('vrishabhchadchan1@gmail.com', 'JOF INDIA');

        $mail->addAddress($member_email, $member_name);

        // Format date and time
        $formatted_date = date('F j, Y', strtotime($scheduled_date));
        $formatted_time = date('g:i A', strtotime($scheduled_time));

        // Content
        $mail->isHTML(true);
        $mail->Subject = "Service Booking Confirmed at JOF INDIA";

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
                .invoice-box { background: #1a1512; border-left: 5px solid #F25C2A; border-radius: 4px; padding: 20px; margin: 25px 0; }
                .invoice-header { font-size: 12px; color: #F25C2A; font-weight: bold; letter-spacing: 2px; text-transform: uppercase; margin-bottom: 10px; }
                .invoice-row { display: table; width: 100%; margin-bottom: 8px; font-size: 14px; }
                .invoice-label { display: table-cell; color: #9ca3af; width: 40%; }
                .invoice-value { display: table-cell; color: #ffffff; font-weight: 600; text-align: right; }
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
                                                <h1 style='margin: 0; font-size: 26px; color: #ffffff; font-weight: 800; font-family: Arial, Helvetica, sans-serif;'>Service Booking Confirmed at JOF INDIA</h1>
                                            </td>
                                        </tr>
                                    </table>
                                </td>
                            </tr>
                            <tr>
                                <td class='content'>
                                    <h1 class='welcome-title'>Hello $member_name,</h1>
                                    <p class='welcome-text'>We are excited to confirm your upcoming service booking at <strong>JOF INDIA</strong>. We look forward to seeing you!</p>
                                    
                                    <div class='invoice-box'>
                                        <div class='invoice-header'>BOOKING DETAILS</div>
                                        <div class='invoice-row'>
                                            <div class='invoice-label'>Service:</div>
                                            <div class='invoice-value'>$service_type</div>
                                        </div>
                                        <div class='invoice-row'>
                                            <div class='invoice-label'>Date:</div>
                                            <div class='invoice-value'>$formatted_date</div>
                                        </div>
                                        <div class='invoice-row'>
                                            <div class='invoice-label'>Time:</div>
                                            <div class='invoice-value'>$formatted_time</div>
                                        </div>
                                        " . ($price > 0 ? "
                                        <div class='invoice-row' style='border-top: 2px solid #374151; margin-top: 12px; padding-top: 12px;'>
                                            <div class='invoice-label'>Price:</div>
                                            <div class='invoice-value' style='color: #F25C2A;'>₹" . number_format($price, 2) . "</div>
                                        </div>
                                        " : "") . "
                                    </div>
                                    
                                    <div style='background: #FFFBEB; border: 1px solid #FDE68A; border-radius: 8px; padding: 20px; margin: 25px 0;'>
                                        <p style='font-size: 16px; font-weight: bold; color: #92400E; margin: 0 0 10px 0;'>📋 Important Instructions</p>
                                        <p style='font-size: 14px; color: #B45309; margin: 5px 0; line-height: 1.5;'>✓ Please arrive 5-10 minutes early for your appointment.</p>
                                        <p style='font-size: 14px; color: #B45309; margin: 5px 0; line-height: 1.5;'>✓ If you need to reschedule, please contact us at least 24 hours in advance.</p>
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

        $mail->AltBody = "Hello $member_name,\n\n"
            . "Your booking has been confirmed!\n\n"
            . "Service: $service_type\n"
            . "Date: $formatted_date\n"
            . "Time: $formatted_time\n"
            . ($price > 0 ? "Price: ₹" . number_format($price, 2) . "\n\n" : "\n")
            . "Please arrive 5-10 minutes early.\n\n"
            . "Best regards,\nJOF India";

        $mail->send();
        return ['success' => true];
    } catch (Exception $e) {
        error_log("Email failed: " . $mail->ErrorInfo);
        return ['success' => false, 'error' => $mail->ErrorInfo];
    }
}
?>
