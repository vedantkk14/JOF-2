<?php
require_once __DIR__ . '/../auth/auth_check.php';
require_role(['admin', 'trainer']);
require_once __DIR__ . '/../config.php';

// PHPMailer includes
require __DIR__ . '/../auth/PHPMailer/Exception.php';
require __DIR__ . '/../auth/PHPMailer/PHPMailer.php';
require __DIR__ . '/../auth/PHPMailer/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// Handle AJAX email sending request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'send_email') {
    $response = ['success' => false, 'message' => ''];

    $member_id = isset($_POST['member_id']) ? intval($_POST['member_id']) : 0;
    $notification_type = isset($_POST['type']) ? $_POST['type'] : 'general';
    $subject = isset($_POST['subject']) ? trim($_POST['subject']) : '';
    $custom_message = isset($_POST['message']) ? trim($_POST['message']) : '';

    // Fetch member
    $stmt = $conn->prepare("SELECT * FROM members WHERE id = ?");
    $stmt->bind_param("i", $member_id);
    $stmt->execute();
    $member = $stmt->get_result()->fetch_assoc();

    if (!$member || empty($member['email'])) {
        $response['message'] = 'Member email not found.';
        echo json_encode($response);
        exit;
    }

    // Get payment data if needed
    $payment_data = null;
    if (in_array($notification_type, ['overdue', 'payment_due', 'expiry'])) {
        $pay_stmt = $conn->prepare("SELECT * FROM member_payments WHERE member_id = ? AND balance_pending > 0 ORDER BY next_due_date ASC LIMIT 1");
        $pay_stmt->bind_param("i", $member_id);
        $pay_stmt->execute();
        $payment_data = $pay_stmt->get_result()->fetch_assoc();
    }

    try {
        $mail = new PHPMailer(true);

        // Shared SMTP credentials (auth/mail_config.php → .env)
        require_once __DIR__ . '/../auth/mail_config.php';
        jof_configure_mailer($mail);

        $mail->addAddress($member['email'], $member['full_name']);
        $mail->isHTML(true);
        $mail->Subject = $subject;

        // Generate email body based on type
        $mail->Body = generateEmailBody($member, $notification_type, $custom_message, $payment_data);

        $mail->send();
        $response['success'] = true;
        $response['message'] = 'Email sent successfully to ' . htmlspecialchars($member['full_name']);

    } catch (Exception $e) {
        $response['message'] = 'Email Error: ' . $mail->ErrorInfo;
        error_log('Payment reminder email failed: ' . $mail->ErrorInfo);
    }

    echo json_encode($response);
    exit;
}

// Function to generate professional email body
function generateEmailBody($member, $type, $custom_message, $payment_data)
{
    global $conn;
    $member_name = htmlspecialchars($member['full_name']);

    // Determine theme colors and title based on type (ALL ORANGE NOW)
    $theme_color = '#F25C2A'; 
    $title = 'Important Notification';
    $subtitle = 'Official update from JOF INDIA';

    if ($type === 'overdue') {
        $title = 'Payment Overdue';
        $subtitle = 'Action required immediately';
    } elseif ($type === 'payment_due') {
        $title = 'Payment Reminder';
        $subtitle = 'Upcoming balance payment';
    } elseif ($type === 'expiry') {
        $title = 'Membership Expired';
        $subtitle = 'Action required';
    } elseif ($type === 'expiry_soon') {
        $title = 'Renewal Reminder';
        $subtitle = 'Don\'t stop your progress!';
    }

    // Build info box content
    $info_box_content = '';
    
    // Determine amount to show
    $display_amount = 0;
    $display_date = '';
    
    // ONLY show info box for payment-specific notifications, NOT for renewal/expiry
    if (in_array($type, ['overdue', 'payment_due'])) {
        if ($payment_data) {
            $display_amount = $payment_data['balance_pending'];
            $display_date = date('M d, Y', strtotime($payment_data['next_due_date']));
        } else {
            // Fallback: Fetch the price of the plan they are on
            $plan_name = $member['membership'];
            $plan_stmt = $conn->prepare("SELECT price FROM membership_plans WHERE plan_name = ?");
            $plan_stmt->bind_param("s", $plan_name);
            $plan_stmt->execute();
            $plan_res = $plan_stmt->get_result()->fetch_assoc();
            if ($plan_res) {
                $display_amount = $plan_res['price'];
            }
            $display_date = date('M d, Y'); // Today if no due date found
        }
    }

    if ($display_amount > 0) {
        $amount_fmt = number_format($display_amount, 2);
        
        $info_box_content = '
            <div style="background: #1a1512; border-left: 5px solid ' . $theme_color . '; border-radius: 4px; padding: 20px; margin: 25px 0;">
                <div style="font-size: 12px; color: ' . $theme_color . '; font-weight: bold; letter-spacing: 2px; text-transform: uppercase; margin-bottom: 10px;">PAYMENT DETAILS</div>
                <div style="display: table; width: 100%; margin-bottom: 8px; font-size: 14px;">
                    <div style="display: table-cell; color: #9ca3af; width: 40%;">Amount Due:</div>
                    <div style="display: table-cell; color: #ffffff; font-weight: 600; text-align: right;">₹' . $amount_fmt . '</div>
                </div>
                <div style="display: table; width: 100%; margin-bottom: 8px; font-size: 14px;">
                    <div style="display: table-cell; color: #9ca3af; width: 40%;">Date:</div>
                    <div style="display: table-cell; color: #ffffff; font-weight: 600; text-align: right;">' . $display_date . '</div>
                </div>
            </div>';
    }

    $html = "
    <!DOCTYPE html>
    <html>
    <head>
        <style>
            body { margin: 0; padding: 0; background-color: #F0F2F5; font-family: 'Segoe UI', Helvetica, Arial, sans-serif; }
            .email-container { max-width: 600px; margin: 0 auto; background: #ffffff; }
            .content { padding: 40px; color: #333333; }
            .welcome-title { font-size: 24px; margin: 0 0 15px 0; color: #1a202c; font-weight: 700; }
            .welcome-text { font-size: 16px; margin: 0 0 20px 0; color: #4b5563; line-height: 1.6; }
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
                        <!-- Banner Header -->
                        <tr>
                            <td style='background-color: $theme_color; padding: 30px 35px;'>
                                <table width='100%' border='0' cellspacing='0' cellpadding='0'>
                                    <tr>
                                        <td width='120' valign='middle' style='padding-right: 25px;'>
                                            <img src='https://www.jofindia.com/assets/img/f-logo.png' alt='JOF' width='110' style='width: 110px; max-width: 110px; height: auto; display: block;'>
                                        </td>
                                        <td valign='middle'>
                                            <h1 style='margin: 0; font-size: 26px; color: #ffffff; font-weight: 800; font-family: Arial, Helvetica, sans-serif;'>Notification from JOF INDIA</h1>
                                        </td>
                                    </tr>
                                </table>
                            </td>
                        </tr>
                        <tr>
                            <td class='content'>
                                <h1 class='welcome-title'>$title</h1>
                                <p class='welcome-text'>$custom_message</p>
                                $info_box_content
                                <p class='welcome-text' style='text-align: center; margin-top: 30px;'>
                                    We look forward to continue the fitness with JOF INDIA!
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
    </html>";

    return $html;
}

// 1. Get Details from URL
$member_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
$notification_type = isset($_GET['type']) ? $_GET['type'] : 'general';
$due_amount = isset($_GET['amount']) ? floatval($_GET['amount']) : 0;
$due_date = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d');

// 2. Fetch Member Info
$member = null;
if ($member_id > 0) {
    $stmt = $conn->prepare("SELECT * FROM members WHERE id = ?");
    $stmt->bind_param("i", $member_id);
    $stmt->execute();
    $member = $stmt->get_result()->fetch_assoc();
}

if (!$member)
    die("Member not found.");

// 2b. If amount wasn't passed in URL, fetch from DB for payment-related notifications
if ($due_amount <= 0 && in_array($notification_type, ['overdue', 'payment_due', 'expiry'])) {
    $pay_stmt = $conn->prepare("SELECT balance_pending, next_due_date FROM member_payments WHERE member_id = ? AND balance_pending > 0 ORDER BY next_due_date ASC LIMIT 1");
    $pay_stmt->bind_param("i", $member_id);
    $pay_stmt->execute();
    $pay_row = $pay_stmt->get_result()->fetch_assoc();
    if ($pay_row) {
        $due_amount = floatval($pay_row['balance_pending']);
        if (!isset($_GET['date']) || empty($_GET['date'])) {
            $due_date = $pay_row['next_due_date'];
        }
    }
}

// 3. Prepare Auto-Fill Messages
$formatted_date = date("d M, Y", strtotime($due_date));
$formatted_amount = "₹" . number_format($due_amount);
$renew_link = "https://jof-india.com/pay/" . $member_id; // Example Link

// Default Message Logic
$default_subject = "Update from JOF INDIA";
$default_message = "";

if ($notification_type === 'payment_due') {
    $default_subject = "Payment Due at JOF INDIA";
    $default_message = "Hi " . $member['full_name'] . ",\n\n" .
        "This is a reminder about your upcoming balance payment.\n\n" .
        "Please clear the dues to avoid any training interruptions.\n\n" .
        "Best regards,\nJOF Admin Team";
} elseif ($notification_type === 'overdue') {
    $default_subject = "Payment Overdue at JOF INDIA";
    $default_message = "Hi " . $member['full_name'] . ",\n\n" .
        "Our records show that your payment of $formatted_amount (due on $formatted_date) is now OVERDUE.\n\n" .
        "Please clear the outstanding balance immediately to maintain uninterrupted access to JOF INDIA.\n\n" .
        "Regards,\nJOF Management";
} else {
    // Standard Expiry / General Logic
    $default_subject = "Important Update from JOF INDIA";
    $default_message = "We appreciate your commitment to your fitness journey at JOF INDIA! We are reaching out regarding an important update to your membership plan...";
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" size="16x16" href="../icons/favicon-dark-logo.png" type="image/png">
    <title>Notify Member | JOF India</title>
    <link rel="stylesheet" href="../static/root.css">
    <style>
        /* Custom styles moved to root.css */
    </style>
</head>
<body class="page-members page-notify_member">
    <button class="mobile-toggle" id="mobileToggle"><img src="../icons/bars-solid-full.svg" class="fa-solid fa-bars"></button>
    <button class="toggle-sidebar-btn" id="toggleBtn"><img src="../icons/chevron-left-solid-full.svg" class="fa-solid fa-chevron-left"></button>

    <div class="dashboard-container">
        <?php include 'sidebar.php'; ?>
        <main class="main-content">
            <header class="header-banner">
                <div class="header-text">
                    <h1>Notify Member</h1>
                    <p>Send reminders for <b><?= ucfirst(str_replace('_', ' ', $notification_type)) ?></b></p>
                </div>
                <a href="members.php" class="btn-primary btn-back-custom">← Back</a>
            </header>

            <div class="notify-card">
                <div class="member-summary">
                    <div class="member-avatar bg-primary text-white d-flex align-center justify-center w-50 h-50 br-50 fw-bold">
                        <?= strtoupper(substr($member['full_name'], 0, 1)) ?>
                    </div>
                    <div class="member-details">
                        <h2><?= htmlspecialchars($member['full_name']) ?></h2>
                        <p><?= htmlspecialchars($member['email']) ?> | <?= htmlspecialchars($member['phone_number'] ?? 'N/A') ?></p>
                    </div>
                </div>

                <div class="templates-section">
                    <h3>📨 Quick-Send Templates</h3>
                    <div class="template-grid">
                        <div class="template-card" onclick="sendQuickTemplate('friendly')">
                            <h4 class="text-warning">⏰ Friendly Reminder</h4>
                            <p class="text-muted">Send a polite nudge regarding upcoming dues.</p>
                        </div>
                        <div class="template-card card-urgent" onclick="sendQuickTemplate('urgent')">
                            <h4 class="text-danger">🚨 Urgent: Overdue</h4>
                            <p class="text-muted">Notice for members with missed payments.</p>
                        </div>
                        <div class="template-card" onclick="sendQuickTemplate('renewal')">
                            <h4 class="text-success">🔔 Renewal</h4>
                            <p class="text-muted">Encourage members to renew their plans.</p>
                        </div>
                    </div>
                </div>

                <form id="notificationForm" class="notify-form">
                    <div class="form-group">
                        <label class="form-label">Notification Channel</label>
                        <div class="channel-options">
                            <label><input type="radio" name="channel" class="channel-radio" value="email" checked onchange="updateTemplate('email')"><div class="channel-card">Email</div></label>
                            <label><input type="radio" name="channel" class="channel-radio" value="whatsapp" onchange="updateTemplate('whatsapp')"><div class="channel-card">WhatsApp</div></label>
                        </div>
                    </div>

                    <div class="form-group" id="subjectGroup">
                        <label class="form-label">Subject</label>
                        <input type="text" class="form-control" id="subjectBox" value="<?= htmlspecialchars($default_subject) ?>">
                    </div>

                    <div class="form-group">
                        <label class="form-label">Message</label>
                        <textarea class="form-control" id="messageBox" rows="6"><?= htmlspecialchars($default_message) ?></textarea>
                    </div>

                    <div id="feedbackMessage" class="feedback-msg"></div>

                    <button type="button" class="btn-primary" id="sendBtn" onclick="sendEmail()">
                        Send Notification
                    </button>
                </form>
            </div>
        </main>
    </div>

     <script>
        document.addEventListener('DOMContentLoaded', function () {
            // Sidebar Toggles
            const toggleBtn = document.getElementById('toggleBtn');
            const mobileToggle = document.getElementById('mobileToggle');
            const body = document.body;

            if (toggleBtn) toggleBtn.addEventListener('click', () => body.classList.toggle('collapsed'));
            if (mobileToggle) mobileToggle.addEventListener('click', () => body.classList.toggle('sidebar-open'));

            // Sidebar Dropdowns
            const dropdownItems = document.querySelectorAll('.nav-item-dropdown');
            dropdownItems.forEach(item => {
                const link = item.querySelector('.nav-link');
                const arrow = link ? link.querySelector('.nav-arrow') : null;
                if (arrow) {
                    arrow.addEventListener('click', function (e) {
                        e.preventDefault();
                        e.stopPropagation();
                        // Close other dropdowns
                        dropdownItems.forEach(otherItem => {
                            if (otherItem !== item) otherItem.classList.remove('active');
                        });
                        item.classList.toggle('active');
                    });
                }
            });
        });

        const memberName = "<?= $member['full_name'] ?>";
        const amount = "<?= $formatted_amount ?>";
        const date = "<?= $formatted_date ?>";
        const link = "<?= $renew_link ?>";
        const type = "<?= $notification_type ?>";
        const memberId = <?= $member_id ?>;
        
        let isSending = false;

        // Quick-send template function
        function sendQuickTemplate(templateType) {
            if (isSending) return;
            
            let subject = '';
            let message = '';

            // Define templates
            if (templateType === 'friendly') {
                subject = 'Payment Reminder at JOF INDIA';
                message = `We appreciate your commitment to your fitness journey at JOF INDIA! We are reaching out regarding an important update to your membership plan...`;
            } else if (templateType === 'urgent') {
                subject = 'Payment Overdue at JOF INDIA';
                message = `Hi ${memberName},\n\nURGENT NOTICE: Your payment is now OVERDUE.\n\nPlease clear your outstanding dues immediately to continue your gym access without interruption.\n\nThank you for your immediate attention.\n\nJOF Management`;
            } else if (templateType === 'renewal') {
                subject = 'Membership Renewal at JOF INDIA';
                message = `We appreciate your commitment to your fitness journey at JOF INDIA! We are reaching out regarding an important update to your membership plan...`;
            }

            // Send directly without showing in form
            sendEmailDirect(subject, message);
        }

        // Direct send function (bypasses form)
        function sendEmailDirect(subject, message) {
            if (isSending) return;
            isSending = true;
            
            const feedbackMsg = document.getElementById('feedbackMessage');

            // Show sending state
            feedbackMsg.style.display = 'block';
            feedbackMsg.classList.add('bg-amber-light', 'text-amber-dark');
            feedbackMsg.classList.remove('bg-success-light', 'text-success-dark', 'bg-danger-light', 'text-danger');
            feedbackMsg.innerHTML = '<img src="../icons/circle-notch-solid-full.svg" class="fa-solid fa-spinner fa-spin"> Sending email...';

            // Send AJAX request
            const formData = new FormData();
            formData.append('action', 'send_email');
            formData.append('member_id', memberId);
            formData.append('type', type);
            formData.append('subject', subject);
            formData.append('message', message);

            fetch('notify_member.php', {
                method: 'POST',
                body: formData
            })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        feedbackMsg.classList.add('bg-success-light', 'text-success-dark');
                        feedbackMsg.classList.remove('bg-amber-light', 'text-amber-dark');
                        feedbackMsg.innerHTML = '<img src="../icons/check-circle-solid-full.svg" class="fa-solid fa-check-circle"> ' + data.message + ' - Redirecting...';

                        setTimeout(() => {
                            window.location.href = 'members.php';
                        }, 2000);
                    } else {
                        feedbackMsg.classList.add('bg-danger-light', 'text-danger');
                        feedbackMsg.classList.remove('bg-amber-light', 'text-amber-dark');
                        feedbackMsg.innerHTML = '<img src="../icons/exclamation-circle-solid-full.svg" class="fa-solid fa-exclamation-circle"> ' + data.message;
                    }
                })
                .catch(error => {
                    isSending = false;
                    feedbackMsg.classList.add('bg-danger-light', 'text-danger');
                    feedbackMsg.classList.remove('bg-amber-light', 'text-amber-dark');
                    feedbackMsg.textContent = 'Network error. Please try again.';
                });
        }

        function sendEmail() {
            if (isSending) return;
            
            const sendBtn = document.getElementById('sendBtn');
            const feedbackMsg = document.getElementById('feedbackMessage');
            const subject = document.getElementById('subjectBox').value;
            const message = document.getElementById('messageBox').value;

            // Validate
            if (!subject || !message) {
                feedbackMsg.style.display = 'block';
                feedbackMsg.classList.add('bg-danger-light', 'text-danger');
                feedbackMsg.textContent = 'Please fill in both subject and message.';
                return;
            }

            // Disable button
            isSending = true;
            sendBtn.disabled = true;
            sendBtn.innerHTML = '<img src="../icons/circle-notch-solid-full.svg" class="fa-solid fa-spinner fa-spin"> Sending...';

            // Send AJAX request
            const formData = new FormData();
            formData.append('action', 'send_email');
            formData.append('member_id', memberId);
            formData.append('type', type);
            formData.append('subject', subject);
            formData.append('message', message);

            fetch('notify_member.php', {
                method: 'POST',
                body: formData
            })
                .then(response => response.json())
                .then(data => {
                    feedbackMsg.style.display = 'block';

                    if (data.success) {
                        feedbackMsg.classList.add('bg-success-light', 'text-success-dark');
                        feedbackMsg.classList.remove('bg-danger-light', 'text-danger', 'bg-amber-light');
                        feedbackMsg.innerHTML = '<img src="../icons/check-circle-solid-full.svg" class="fa-solid fa-check-circle"> ' + data.message;

                        // Redirect after 2 seconds
                        setTimeout(() => {
                            window.location.href = 'members.php';
                        }, 2000);
                    } else {
                        // Re-enable button
                        isSending = false;
                        sendBtn.disabled = false;
                        sendBtn.innerHTML = '<img src="../icons/paper-plane-solid-full.svg" class="fa-solid fa-paper-plane"> Send Notification';
                    }
                })
                .catch(error => {
                    isSending = false;
                    feedbackMsg.style.display = 'block';
                    feedbackMsg.classList.add('bg-danger-light', 'text-danger');
                    feedbackMsg.textContent = 'Network error. Please try again.';

                    // Re-enable button
                    sendBtn.disabled = false;
                    sendBtn.innerHTML = '<img src="../icons/paper-plane-solid-full.svg" class="fa-solid fa-paper-plane"> Send Notification';
                });
        }

        function updateTemplate(channel) {
            const messageBox = document.getElementById('messageBox');
            const subjectBox = document.getElementById('subjectBox');
            const subjectGroup = document.getElementById('subjectGroup');

            let msg = "";
            let subj = "";

            if (channel === 'email') {
                subjectGroup.style.display = 'block';
                if (type === 'overdue') {
                    subj = "Payment Overdue at JOF INDIA";
                    msg = `Hi ${memberName},\n\nYour payment is now OVERDUE.\n\nPlease clear this immediately to continue your gym access.\n\nRegards,\nJOF Management`;
                } else if (type === 'payment_due') {
                    subj = "Payment Reminder at JOF INDIA";
                    msg = `Hi ${memberName},\n\nThis is a reminder about your upcoming balance payment.\n\nPlease visit the front desk to avoid interruptions.\n\nBest,\nJOF Admin Team`;
                } else {
                    subj = "Important Update from JOF INDIA";
                    msg = "We appreciate your commitment to your fitness journey at JOF INDIA! We are reaching out regarding an important update to your membership plan...";
                }
            } else {
                // WhatsApp (No Subject)
                subjectGroup.style.display = 'none';
                if (type === 'overdue') {
                    msg = `🚨 *Payment Overdue Alert*\n\nHi ${memberName}, your payment is now OVERDUE. Please pay immediately to avoid account suspension.`;
                } else if (type === 'payment_due') {
                    msg = `⏰ Hi ${memberName}, just a reminder about your upcoming balance payment. See you at the gym! 💪`;
                } else {
                    msg = `We appreciate your commitment to your fitness journey at JOF INDIA! We are reaching out regarding an important update to your membership plan...`;
                }
            }

            messageBox.value = msg;
            if (subj) subjectBox.value = subj;
        }
    </script>
    <script>
        function replaceSVG() {
            var images = document.querySelectorAll('img.fa-solid, img.fa-regular, img.fa-brands, img[class*="fa-"]');
            images.forEach(function (img) {
                if (img.classList.contains('svg-replaced')) return;
                img.classList.add('svg-replaced');

                var imgID = img.id;
                var imgClass = img.className;
                var imgURL = img.src;

                if (!imgURL.endsWith('.svg')) return;

                fetch(imgURL)
                    .then(response => response.text())
                    .then(text => {
                        var parser = new DOMParser();
                        var xmlDoc = parser.parseFromString(text, "text/xml");
                        var svg = xmlDoc.getElementsByTagName('svg')[0];

                        if (!svg) return;

                        if (typeof imgID !== 'undefined' && imgID !== '') {
                            svg.setAttribute('id', imgID);
                        }
                        if (typeof imgClass !== 'undefined' && imgClass !== '') {
                            svg.setAttribute('class', imgClass + ' replaced-svg');
                        }

                        svg.removeAttribute('xmlns:a');
                        svg.removeAttribute('width');
                        svg.removeAttribute('height');

                        var paths = svg.querySelectorAll('path');
                        paths.forEach(function (path) {
                            path.setAttribute('fill', 'currentColor');
                        });

                        img.parentNode.replaceChild(svg, img);
                    })
                    .catch(err => console.error('Error fetching SVG:', err));
            });
        }

        replaceSVG();

        var observer = new MutationObserver(function (mutations) {
            var shouldRun = false;
            mutations.forEach(function (mutation) {
                if (mutation.addedNodes.length) {
                    shouldRun = true;
                }
            });
            if (shouldRun) replaceSVG();
        });

        observer.observe(document.body, { childList: true, subtree: true });
    </script>
</body>


</html>
