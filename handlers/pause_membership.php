<?php
/**
 * handlers/pause_membership.php
 * ─────────────────────────────────────────────────────────────────
 * Pauses a gym member's membership for a chosen date range.
 * The number of paused days is added to the END DATE of the member's
 * latest membership_payments row, and the pause is logged in
 * membership_pauses for history / audit.
 *
 * POST fields:
 *   _csrf_token   session CSRF token
 *   member_id     members.id
 *   pause_start   YYYY-MM-DD
 *   pause_end     YYYY-MM-DD   (inclusive; must be >= pause_start)
 *   reason        optional text
 *   redirect      one of: members.php | user_info.php | person_info.php
 *   redirect_id   optional int, appended as ?id= (used by person_info.php)
 */

require_once __DIR__ . '/../auth/auth_check.php';
require_role(['admin', 'trainer']);
require_once __DIR__ . '/../config.php';

$allowed_redirects = ['members.php', 'user_info.php', 'person_info.php'];

$redirect_page = $_POST['redirect'] ?? 'members.php';
if (!in_array($redirect_page, $allowed_redirects, true)) {
    $redirect_page = 'members.php';
}
$redirect_id = isset($_POST['redirect_id']) ? (int) $_POST['redirect_id'] : 0;

function back(string $page, int $id, string $key, string $val = '1'): void
{
    $q = $id > 0 ? "?id={$id}&" : "?";
    header("Location: ../templates/{$page}{$q}{$key}=" . urlencode($val));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    back($redirect_page, $redirect_id, 'pause_err', 'Invalid request.');
}

// ── CSRF (non-rotating so multiple pauses in a row keep working) ──
$submitted = $_POST['_csrf_token'] ?? '';
$stored    = $_SESSION['_csrf_token'] ?? '';
if (!$stored || !hash_equals($stored, $submitted)) {
    back($redirect_page, $redirect_id, 'pause_err', 'Security token expired. Please refresh and try again.');
}

// ── Validate input ──────────────────────────────────────────────
$member_id = (int) ($_POST['member_id'] ?? 0);
$p_start   = trim($_POST['pause_start'] ?? '');
$p_end     = trim($_POST['pause_end'] ?? '');
$reason    = trim($_POST['reason'] ?? '');
if (mb_strlen($reason) > 255) {
    $reason = mb_substr($reason, 0, 255);
}

$d1 = DateTime::createFromFormat('Y-m-d', $p_start);
$d2 = DateTime::createFromFormat('Y-m-d', $p_end);

if ($member_id <= 0 || !$d1 || !$d2 || $d1->format('Y-m-d') !== $p_start || $d2->format('Y-m-d') !== $p_end) {
    back($redirect_page, $redirect_id, 'pause_err', 'Please pick a valid start and end date.');
}
if ($d2 < $d1) {
    back($redirect_page, $redirect_id, 'pause_err', 'End date cannot be before the start date.');
}

$days = (int) $d1->diff($d2)->days + 1; // inclusive
if ($days < 1 || $days > 365) {
    back($redirect_page, $redirect_id, 'pause_err', 'Pause length must be between 1 and 365 days.');
}

// ── Find the member's latest membership plan ─────────────────────
$stmt = $conn->prepare("
    SELECT payment_id, end_date
    FROM member_payments
    WHERE member_id = ?
    ORDER BY created_at DESC
    LIMIT 1
");
$stmt->bind_param('i', $member_id);
$stmt->execute();
$plan = $stmt->get_result()->fetch_assoc();

if (!$plan || empty($plan['end_date'])) {
    back($redirect_page, $redirect_id, 'pause_err', 'This member has no membership plan with an end date to pause.');
}

$payment_id      = (int) $plan['payment_id'];
$end_date_before = $plan['end_date'];
$end_date_after  = (new DateTime($end_date_before))->modify("+{$days} day")->format('Y-m-d');

// Try to look up the linked login account (by email) for the log
$user_id = null;
$uq = $conn->prepare("
    SELECT ud.id
    FROM user_data ud
    JOIN members m ON m.email = ud.email
    WHERE m.id = ?
    LIMIT 1
");
$uq->bind_param('i', $member_id);
$uq->execute();
if ($urow = $uq->get_result()->fetch_assoc()) {
    $user_id = (int) $urow['id'];
}

$created_by = (int) ($_SESSION['user_id'] ?? 0) ?: null;

// ── Apply ───────────────────────────────────────────────────────
$conn->begin_transaction();
try {
    $u1 = $conn->prepare("UPDATE member_payments SET end_date = ? WHERE payment_id = ?");
    $u1->bind_param('si', $end_date_after, $payment_id);
    $u1->execute();

    // If the extension pushes the plan back into the future, reactivate the member
    $conn->query("
        UPDATE members
        SET status = 'active', inactive_date = NULL
        WHERE id = " . $member_id . "
        AND status IN ('inactive', 'recycled')
        AND '" . $conn->real_escape_string($end_date_after) . "' > CURDATE()
    ");

    $ins = $conn->prepare("
        INSERT INTO membership_pauses
            (member_id, payment_id, user_id, pause_start, pause_end, days, reason, end_date_before, end_date_after, created_by)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    // types: member_id i, payment_id i, user_id i, pause_start s, pause_end s,
    //        days i, reason s, end_date_before s, end_date_after s, created_by i
    $ins->bind_param(
        'iiississsi',
        $member_id, $payment_id, $user_id, $p_start, $p_end, $days, $reason, $end_date_before, $end_date_after, $created_by
    );
    $ins->execute();

    $conn->commit();
} catch (Throwable $e) {
    $conn->rollback();
    error_log('[PAUSE] ' . $e->getMessage());
    back($redirect_page, $redirect_id, 'pause_err', 'Could not pause the membership. Please try again.');
}

back($redirect_page, $redirect_id, 'pause_ok', (string) $days);
