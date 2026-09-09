<?php
session_start();
header('Content-Type: application/json');

require __DIR__ . '/../config.php';
require __DIR__ . '/../auth/PHPMailer/Exception.php';
require __DIR__ . '/../auth/PHPMailer/PHPMailer.php';
require __DIR__ . '/../auth/PHPMailer/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$member_id = isset($_POST['member_id']) ? intval($_POST['member_id']) : 0;
$action = isset($_POST['action']) ? $_POST['action'] : 'send';

if ($member_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid member ID']);
    exit;
}

// 1. Fetch member details
$stmt = $conn->prepare("SELECT id, full_name, email FROM members WHERE id = ?");
$stmt->bind_param("i", $member_id);
$stmt->execute();
$member = $stmt->get_result()->fetch_assoc();

if (!$member || empty($member['email'])) {
    echo json_encode(['success' => false, 'message' => 'Member email not found']);
    exit;
}

// 2. Fetch upcoming sessions
$today = date('Y-m-d');
$sessions_query = "
    SELECT
        ps.session_date,
        ps.session_time,
        ps.status,
        ps.notes,
        COALESCE(t.full_name, 'TBD') AS trainer_name
    FROM pt_sessions ps
    JOIN personal_training pt ON ps.pt_id = pt.id
    LEFT JOIN trainers t ON pt.trainer_id = t.id
    WHERE ps.member_id = ?
      AND ps.session_date >= ?
      AND ps.status NOT IN ('Cancelled', 'Completed')
    ORDER BY ps.session_date ASC, ps.session_time ASC
    LIMIT 30
";
$s2 = $conn->prepare($sessions_query);
$s2->bind_param("is", $member_id, $today);
$s2->execute();
$sessions_result = $s2->get_result();

$sessions = [];
while ($row = $sessions_result->fetch_assoc()) {
    $sessions[] = $row;
}

if (empty($sessions)) {
    echo json_encode(['success' => false, 'message' => 'No upcoming sessions found for this member.']);
    exit;
}

// ── PREVIEW: return session data as JSON (no email sent) ──────────────────────
if ($action === 'preview') {
    $preview = [];
    foreach ($sessions as $s) {
        $preview[] = [
            'date' => date('l, F j, Y', strtotime($s['session_date'])),
            'date_short' => date('D, M j', strtotime($s['session_date'])),
            'time' => date('h:i A', strtotime($s['session_time'])),
            'trainer' => $s['trainer_name'],
            'status' => $s['status'],
            'notes' => $s['notes'],
        ];
    }
    echo json_encode([
        'success' => true,
        'member_name' => $member['full_name'],
        'member_email' => $member['email'],
        'count' => count($sessions),
        'sessions' => $preview,
    ]);
    exit;
}

// ── SEND: full email path below ───────────────────────────────────────────────

// 3. Build session rows HTML for the email
function statusBadge($status)
{
    $map = [
        'Scheduled' => ['#DBEAFE', '#1D4ED8'],
        'Rescheduled-Member' => ['#FEE2E2', '#DC2626'],
        'Rescheduled-Trainer' => ['#FEF3C7', '#92400E'],
    ];
    $colors = $map[$status] ?? ['#F3F4F6', '#374151'];
    return '<span style="display:inline-block;padding:3px 10px;border-radius:20px;font-size:11px;font-weight:700;'
        . 'background:' . $colors[0] . ';color:' . $colors[1] . ';">' . htmlspecialchars($status) . '</span>';
}

$session_rows = '';
$prev_date = '';
foreach ($sessions as $s) {
    $date_fmt = date('l, F j, Y', strtotime($s['session_date']));
    $time_fmt = date('h:i A', strtotime($s['session_time']));
    $is_new_date = ($s['session_date'] !== $prev_date);

    if ($is_new_date) {
        if ($prev_date !== '')
            $session_rows .= '<tr><td colspan="3" style="height:6px;"></td></tr>';
        $session_rows .= '
        <tr>
            <td colspan="3" style="padding:10px 20px 6px;background:#F9FAFB;font-size:11px;font-weight:700;
                color:#F25C2A;letter-spacing:1px;text-transform:uppercase;border-top:1px solid #F3F4F6;">
                📅 ' . htmlspecialchars($date_fmt) . '
            </td>
        </tr>';
        $prev_date = $s['session_date'];
    }

    $notes_html = !empty($s['notes'])
        ? '<div style="font-size:11px;color:#9CA3AF;margin-top:3px;">📝 ' . htmlspecialchars($s['notes']) . '</div>'
        : '';

    $session_rows .= '
    <tr>
        <td style="padding:12px 20px;border-bottom:1px solid #F9FAFB;vertical-align:middle;">
            <div style="font-weight:600;font-size:14px;color:#111827;">⏰ ' . $time_fmt . '</div>
            ' . $notes_html . '
        </td>
        <td style="padding:12px 20px;border-bottom:1px solid #F9FAFB;vertical-align:middle;font-size:13px;color:#6B7280;">
            <i>👤</i> ' . htmlspecialchars($s['trainer_name']) . '
        </td>
        <td style="padding:12px 20px;border-bottom:1px solid #F9FAFB;vertical-align:middle;text-align:right;">
            ' . statusBadge($s['status']) . '
        </td>
    </tr>';
}

$total_sessions = count($sessions);
$member_name = htmlspecialchars($member['full_name']);
$first_session = date('M j', strtotime($sessions[0]['session_date']));
$last_session = date('M j, Y', strtotime($sessions[$total_sessions - 1]['session_date']));

// 4. Build full HTML email
$email_html = "
<!DOCTYPE html>
<html>
<head><meta charset='UTF-8'></head>
<body bgcolor='#F0F2F5' style='margin:0;padding:0;font-family:Segoe UI,Helvetica,Arial,sans-serif;'>
<table width='100%' border='0' cellspacing='0' cellpadding='0' bgcolor='#F0F2F5'>
<tr><td align='center' style='padding:40px 0;'>
<table width='600' border='0' cellspacing='0' cellpadding='0'
    style='background:#fff;border-radius:16px;overflow:hidden;box-shadow:0 4px 20px rgba(0,0,0,0.1);'>

    <!-- Header with Logo -->
    <tr>
        <td style='background:linear-gradient(135deg,#F25C2A 0%,#ff8c69 100%);padding:30px 35px;'>
            <table width='100%' border='0' cellspacing='0' cellpadding='0'>
                <tr>
                    <td width='120' valign='middle' style='padding-right:25px;'>
                        <img src='https://www.jofindia.com/assets/img/f-logo.png' alt='JOF' width='110' style='width:110px;max-width:110px;height:auto;display:block;'>
                    </td>
                    <td valign='middle'>
                        <p style='margin:0 0 4px 0;font-size:13px;color:#ffffff;opacity:0.85;text-transform:uppercase;letter-spacing:3px;font-weight:600;'>PERSONAL TRAINING</p>
                        <div style='font-size:28px;font-weight:900;letter-spacing:3px;color:#fff;'>SESSION SCHEDULE</div>
                    </td>
                </tr>
            </table>
        </td>
    </tr>

    <!-- Intro -->
    <tr>
        <td style='padding:32px 30px 20px;'>
            <h1 style='margin:0 0 10px;font-size:22px;color:#111827;'>Hi $member_name! 👋</h1>
            <p style='margin:0;font-size:15px;color:#4B5563;line-height:1.7;'>
                Here's your upcoming PT session schedule at <strong>JOF INDIA</strong>. 
                You have <strong>$total_sessions session" . ($total_sessions > 1 ? 's' : '') . "</strong> 
                scheduled from <strong>$first_session</strong> to <strong>$last_session</strong>.
                Come prepared and crush your goals! 💪
            </p>
        </td>
    </tr>

    <!-- Session Table -->
    <tr>
        <td style='padding:0 30px 20px;'>
            <table width='100%' border='0' cellspacing='0' cellpadding='0'
                style='border:1px solid #E5E7EB;border-radius:12px;overflow:hidden;'>
                <tr style='background:#1F2937;'>
                    <th style='padding:12px 20px;text-align:left;font-size:11px;font-weight:700;color:#9CA3AF;letter-spacing:1px;text-transform:uppercase;'>TIME</th>
                    <th style='padding:12px 20px;text-align:left;font-size:11px;font-weight:700;color:#9CA3AF;letter-spacing:1px;text-transform:uppercase;'>TRAINER</th>
                    <th style='padding:12px 20px;text-align:right;font-size:11px;font-weight:700;color:#9CA3AF;letter-spacing:1px;text-transform:uppercase;'>STATUS</th>
                </tr>
                $session_rows
            </table>
        </td>
    </tr>

    <!-- Tips -->
    <tr>
        <td style='padding:0 30px 28px;'>
            <div style='background:#FFF4EF;border-left:4px solid #F25C2A;border-radius:8px;padding:16px 20px;'>
                <div style='font-size:13px;font-weight:700;color:#F25C2A;margin-bottom:8px;'>💡 Tips for your sessions:</div>
                <ul style='margin:0;padding-left:18px;font-size:13px;color:#6B7280;line-height:2;'>
                    <li>Stay hydrated — bring a water bottle</li>
                    <li>Arrive 5 minutes early to warm up</li>
                    <li>Wear comfortable workout clothing</li>
                    <li>Contact us if you need to reschedule</li>
                </ul>
            </div>
        </td>
    </tr>

    <!-- Footer -->
    <tr>
        <td style='background:#111827;padding:28px;text-align:center;'>
            <p style='margin:0 0 8px;font-size:16px;color:#fff;font-weight:700;'>📞 +91 779-848-7209 &nbsp;|&nbsp; ✉️ iglmembershipid@gmail.com</p>
            <p style='margin:4px 0;font-size:12px;color:#6B7280;'>© 2026 JOF INDIA. All rights reserved.</p>
            <p style='margin:4px 0;font-size:12px;color:#6B7280;'> Aurelia, Pancard Road, Baner, Pune-411045, Maharashtra</p>
        </td>
    </tr>

</table>
</td></tr>
</table>
</body>
</html>";

// 5. Send via PHPMailer
try {
    $mail = new PHPMailer(true);

    // Shared SMTP credentials (auth/mail_config.php → .env)
    require_once __DIR__ . '/../auth/mail_config.php';
    jof_configure_mailer($mail);

    $mail->addAddress($member['email'], $member['full_name']);
    $mail->isHTML(true);
    $mail->Subject = '🏋️ Your Upcoming PT Sessions – JOF INDIA';
    $mail->Body = $email_html;

    $mail->send();
    echo json_encode([
        'success' => true,
        'message' => 'Schedule emailed to ' . htmlspecialchars($member['full_name']) . ' (' . $member['email'] . ')',
        'count' => $total_sessions
    ]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Email Error: ' . $mail->ErrorInfo]);
}
