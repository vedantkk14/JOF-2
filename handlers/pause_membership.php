<?php
/**
 * handlers/pause_membership.php
 * ─────────────────────────────────────────────────────────────────
 * Adjusts a member's latest plan end date and logs the change in
 * membership_pauses (history / audit).
 *
 * kind = pause  (default)
 *   Freezes the plan for a chosen date range; the paused days are added
 *   to the plan end date.
 *   POST: pause_start, pause_end   (YYYY-MM-DD, inclusive)
 *
 * kind = extension
 *   Grants extra days as a courtesy. Counted from the current end date,
 *   or from TODAY if the plan has already lapsed — so an expired member is
 *   active again straight away, for exactly that many days.
 *   POST: days   (1–365)
 *
 * Common POST fields:
 *   _csrf_token   session CSRF token
 *   member_id     members.id
 *   reason        optional text
 *   redirect      one of the allow-listed pages below
 *   redirect_id   optional int, appended as ?id= (used by person_info.php)
 */

require_once __DIR__ . '/../auth/auth_check.php';
require_role(['admin', 'trainer']);
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../auth/membership_helper.php';
membership_pauses_ensure_schema($conn);

$allowed_redirects = ['members.php', 'user_info.php', 'person_info.php', 'inactive_members.php'];

$redirect_page = $_POST['redirect'] ?? 'members.php';
if (!in_array($redirect_page, $allowed_redirects, true)) {
    $redirect_page = 'members.php';
}
$redirect_id = isset($_POST['redirect_id']) ? (int) $_POST['redirect_id'] : 0;

function back(string $page, int $id, array $params): void
{
    if ($id > 0) {
        $params = ['id' => $id] + $params;
    }
    header('Location: ../templates/' . $page . '?' . http_build_query($params));
    exit;
}

$kind = ($_POST['kind'] ?? 'pause') === 'extension' ? 'extension' : 'pause';
$verb = $kind === 'extension' ? 'extend' : 'pause';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    back($redirect_page, $redirect_id, ['pause_err' => 'Invalid request.']);
}

// ── CSRF (non-rotating so several actions in a row keep working) ──
$submitted = $_POST['_csrf_token'] ?? '';
$stored    = $_SESSION['_csrf_token'] ?? '';
if (!$stored || !hash_equals($stored, $submitted)) {
    back($redirect_page, $redirect_id, ['pause_err' => 'Security token expired. Please refresh and try again.']);
}

// ── Validate input ──────────────────────────────────────────────
$member_id = (int) ($_POST['member_id'] ?? 0);
$reason    = trim($_POST['reason'] ?? '');
if (mb_strlen($reason) > 255) {
    $reason = mb_substr($reason, 0, 255);
}
if ($member_id <= 0) {
    back($redirect_page, $redirect_id, ['pause_err' => 'Invalid member.']);
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
    back($redirect_page, $redirect_id, ['pause_err' => "This member has no membership plan with an end date to {$verb}."]);
}

$payment_id      = (int) $plan['payment_id'];
$end_date_before = $plan['end_date'];
$today           = new DateTime('today');
$old_end         = new DateTime($end_date_before);

if ($kind === 'extension') {
    $days = (int) ($_POST['days'] ?? 0);
    if ($days < 1 || $days > 365) {
        back($redirect_page, $redirect_id, ['pause_err' => 'Extension must be between 1 and 365 days.']);
    }
    $lapsed         = $old_end < $today;
    $base           = $lapsed ? clone $today : clone $old_end;
    $p_start        = $lapsed ? $today->format('Y-m-d') : (clone $old_end)->modify('+1 day')->format('Y-m-d');
    $end_date_after = (clone $base)->modify("+{$days} day")->format('Y-m-d');
    $p_end          = $end_date_after;
} else {
    $p_start = trim($_POST['pause_start'] ?? '');
    $p_end   = trim($_POST['pause_end'] ?? '');
    $d1 = DateTime::createFromFormat('Y-m-d', $p_start);
    $d2 = DateTime::createFromFormat('Y-m-d', $p_end);

    if (!$d1 || !$d2 || $d1->format('Y-m-d') !== $p_start || $d2->format('Y-m-d') !== $p_end) {
        back($redirect_page, $redirect_id, ['pause_err' => 'Please pick a valid start and end date.']);
    }
    if ($d2 < $d1) {
        back($redirect_page, $redirect_id, ['pause_err' => 'End date cannot be before the start date.']);
    }

    $days = (int) $d1->diff($d2)->days + 1; // inclusive
    if ($days < 1 || $days > 365) {
        back($redirect_page, $redirect_id, ['pause_err' => 'Pause length must be between 1 and 365 days.']);
    }
    $end_date_after = (clone $old_end)->modify("+{$days} day")->format('Y-m-d');
}

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

    // If the change pushes the plan back into the future, reactivate the member
    $conn->query("
        UPDATE members
        SET status = 'active', inactive_date = NULL
        WHERE id = " . $member_id . "
        AND status IN ('inactive', 'recycled')
        AND '" . $conn->real_escape_string($end_date_after) . "' > CURDATE()
    ");

    $ins = $conn->prepare("
        INSERT INTO membership_pauses
            (member_id, payment_id, kind, user_id, pause_start, pause_end, days, reason, end_date_before, end_date_after, created_by)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    // types: member_id i, payment_id i, kind s, user_id i, pause_start s, pause_end s,
    //        days i, reason s, end_date_before s, end_date_after s, created_by i
    $ins->bind_param(
        'iisississsi',
        $member_id, $payment_id, $kind, $user_id, $p_start, $p_end, $days, $reason, $end_date_before, $end_date_after, $created_by
    );
    $ins->execute();

    $conn->commit();
} catch (Throwable $e) {
    $conn->rollback();
    error_log('[PAUSE] ' . $e->getMessage());
    back($redirect_page, $redirect_id, ['pause_err' => "Could not {$verb} the membership. Please try again."]);
}

if ($kind === 'extension') {
    // An extended member is active again, so an extension started from the
    // inactive list lands on the Active Members list.
    $dest    = $redirect_page === 'inactive_members.php' ? 'members.php' : $redirect_page;
    $dest_id = $dest === 'members.php' ? 0 : $redirect_id;
    back($dest, $dest_id, ['ext_ok' => $days, 'ext_member' => $member_id]);
}

back($redirect_page, $redirect_id, ['pause_ok' => $days]);
