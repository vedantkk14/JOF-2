<?php

session_start();

error_reporting(0);
ini_set('display_errors', 0);

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}

session_write_close();

require '../config.php';

// ── IMAP Config ──────────────────────────────────────────────────────────────
$imap_host = '{imap.gmail.com:993/imap/ssl}INBOX';
$imap_user = 'vedantkolhapure111@gmail.com';
$imap_pass = 'tzhiwibunjrfgfjj';

if (!function_exists('imap_open')) {
    echo json_encode(['status' => 'warning', 'message' => 'PHP IMAP extension not enabled.', 'new' => 0]);
    exit;
}

// ── Connect to Mailbox ───────────────────────────────────────────────────────
$inbox = @imap_open($imap_host, $imap_user, $imap_pass, 0, 1, ['DISABLE_AUTHENTICATOR' => 'GSSAPI']);

if (!$inbox) {
    echo json_encode(['status' => 'error', 'message' => 'IMAP connect failed. Ensure IMAP is enabled in your Gmail Settings.', 'new' => 0]);
    exit;
}

// Function to clean up the email body (remove previous reply chains)
function stripEmailQuotes($text)
{
    $lines = preg_split('/\r?\n/', $text);
    $clean = [];
    $quoting = false;
    foreach ($lines as $line) {
        $trimmed = trim($line);
        if (preg_match('/^On .{10,} wrote:\s*$/i', $trimmed) || preg_match('/^-{3,}[\s\w]*(Original|Forwarded)/i', $trimmed) || preg_match('/^_{5,}/', $trimmed) || preg_match('/^From:\s+/i', $line)) {
            $quoting = true;
        }
        if ($quoting || strncmp($trimmed, '>', 1) === 0)
            continue;
        $clean[] = $line;
    }
    return trim(preg_replace('/\n{3,}/', "\n\n", implode("\n", $clean)));
}

$newCount = 0;

// ── Search for emails from the last 2 days (Ignores Read/Unread Status) ─────
$sinceDate = date('d-M-Y', strtotime('-2 days'));
$emails = imap_search($inbox, 'SINCE "' . $sinceDate . '"');

if ($emails) {
    // Process oldest to newest, but limit to last 30 to prevent server lag
    if (count($emails) > 30) {
        $emails = array_slice($emails, -30);
    }

    foreach ($emails as $msg_no) {
        $header = imap_headerinfo($inbox, $msg_no);
        $subject = isset($header->subject) ? imap_utf8($header->subject) : '(No Subject)';

        $fromObj = isset($header->from[0]) ? $header->from[0] : null;
        $fromEmail = $fromObj ? strtolower($fromObj->mailbox . '@' . $fromObj->host) : 'unknown@email.com';
        $fromName = ($fromObj && isset($fromObj->personal)) ? imap_utf8($fromObj->personal) : $fromEmail;

        // Skip emails sent by your own system
        if (strpos($fromEmail, 'vedantkolhapure111@gmail.com') !== false || strpos($fromEmail, 'no-reply') !== false) {
            continue;
        }

        // Extract Body
        $rawBody = '';
        $structure = imap_fetchstructure($inbox, $msg_no);

        if ($structure->type === 0) {
            $rawBody = imap_fetchbody($inbox, $msg_no, '1');
        }
        elseif (isset($structure->parts)) {
            foreach ($structure->parts as $i => $part) {
                if ($part->subtype === 'PLAIN') {
                    $rawBody = imap_fetchbody($inbox, $msg_no, (string)($i + 1));
                    break;
                }
            }
        }

        if (empty(trim($rawBody))) {
            $rawBody = imap_body($inbox, $msg_no);
        }

        // Decode Body correctly
        $encoding = isset($structure->parts[0]->encoding) ? $structure->parts[0]->encoding : ($structure->encoding ?? 0);
        if ($encoding == 3)
            $rawBody = base64_decode($rawBody);
        elseif ($encoding == 4)
            $rawBody = quoted_printable_decode($rawBody);

        $bodyClean = stripEmailQuotes(strip_tags($rawBody));
        $bodyFull = mb_substr($bodyClean, 0, 5000);

        // Preview for the notification card
        $preview = mb_strlen($bodyClean) > 150 ? mb_substr($bodyClean, 0, 150) . '…' : $bodyClean;

        // ── Duplicate Check (Prevents identical replies from showing twice) ──
        $checkStmt = $conn->prepare("SELECT id FROM notifications WHERE member_email = ? AND title = ? AND message = ? LIMIT 1");
        $checkStmt->bind_param('sss', $fromEmail, $subject, $preview);
        $checkStmt->execute();
        $checkStmt->store_result();

        if ($checkStmt->num_rows === 0) {
            // Check if body_full column exists in DB
            $colCheck = $conn->query("SELECT COUNT(*) c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'notifications' AND COLUMN_NAME = 'body_full'");
            $hasBodyFull = $colCheck && (int)$colCheck->fetch_assoc()['c'] > 0;

            if (!$hasBodyFull) {
                $conn->query("ALTER TABLE `notifications` ADD COLUMN `body_full` TEXT AFTER `message`");
                $hasBodyFull = ($conn->errno === 0);
            }

            $type = 'email_reply';
            if ($hasBodyFull) {
                $ins = $conn->prepare("INSERT INTO notifications (type, title, message, body_full, member_name, member_email) VALUES (?, ?, ?, ?, ?, ?)");
                $ins->bind_param('ssssss', $type, $subject, $preview, $bodyFull, $fromName, $fromEmail);
            }
            else {
                $ins = $conn->prepare("INSERT INTO notifications (type, title, message, member_name, member_email) VALUES (?, ?, ?, ?, ?)");
                $ins->bind_param('sssss', $type, $subject, $preview, $fromName, $fromEmail);
            }
            $ins->execute();
            $ins->close();
            $newCount++;
        }
        $checkStmt->close();
    }
}

imap_close($inbox);

echo json_encode([
    'status' => 'success',
    'new' => $newCount,
]);
?>