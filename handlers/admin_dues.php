<?php
/**
 * handlers/admin_dues.php
 * ─────────────────────────────────────────────────────────────────
 * Admin/trainer side of member-submitted dues payments (JSON).
 *
 *   GET  ?action=list                     every pending submission, newest first
 *   GET  ?action=detail&id=N               one submission's full detail, incl. screenshot URL
 *   POST action=verify&id=N                applies the amount to the plan's balance_pending
 *   POST action=reject&id=N&note=...       marks it rejected; member can resubmit
 */

require_once __DIR__ . '/../auth/auth_check.php';
require_role(['admin', 'trainer']);
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../auth/dues_schema.php';

header('Content-Type: application/json');

ensure_dues_schema($conn);
$admin = get_session_user();
$admin_id = (int) $admin['id'];

$action = $_GET['action'] ?? $_POST['action'] ?? 'list';

if ($action === 'list') {
    $rows = due_payments_pending_list($conn);
    echo json_encode([
        'status' => 'success',
        'items'  => array_map(static function (array $r): array {
            $phase = explode(' - ', (string) $r['plan_name']);
            return [
                'id'          => (int) $r['id'],
                'payment_id'  => (int) $r['payment_id'],
                'member_id'   => (int) $r['member_id'],
                'member_name' => $r['member_name'],
                'plan_name'   => $r['plan_name'],
                'amount'      => (float) $r['amount'],
                'transaction_id' => $r['transaction_id'],
                'created_at'  => $r['created_at'],
            ];
        }, $rows),
        'count' => count($rows),
    ]);
    exit;
}

if ($action === 'detail') {
    $id = (int) ($_GET['id'] ?? 0);
    $stmt = $conn->prepare("SELECT d.*, m.full_name AS member_name, m.id AS mem_id,
                                    mp.membership_type AS plan_name, mp.total_amount, mp.amount_received,
                                    mp.balance_pending, mp.start_date, mp.end_date
                             FROM member_due_payments d
                             JOIN members m ON m.id = d.member_id
                             JOIN member_payments mp ON mp.payment_id = d.payment_id
                             WHERE d.id = ? LIMIT 1");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    if (!$row) {
        echo json_encode(['status' => 'error', 'message' => 'Not found.']);
        exit;
    }
    $row['screenshot_url'] = $row['screenshot_path'] ? '../uploads/dues/' . rawurlencode($row['screenshot_path']) : null;
    echo json_encode(['status' => 'success', 'item' => $row]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['status' => 'error', 'message' => 'Invalid request.']);
    exit;
}

$submitted = $_POST['_csrf_token'] ?? '';
$stored    = $_SESSION['_csrf_token'] ?? '';
if (!$stored || !hash_equals($stored, $submitted)) {
    echo json_encode(['status' => 'error', 'message' => 'Security token expired. Please refresh the page.']);
    exit;
}

$id = (int) ($_POST['id'] ?? 0);

if ($action === 'verify') {
    $conn->begin_transaction();

    $dstmt = $conn->prepare("SELECT * FROM member_due_payments WHERE id = ? AND status = 'pending' LIMIT 1 FOR UPDATE");
    $dstmt->bind_param('i', $id);
    $dstmt->execute();
    $due = $dstmt->get_result()->fetch_assoc();
    if (!$due) {
        $conn->rollback();
        echo json_encode(['status' => 'error', 'message' => 'That submission was not found, or has already been handled.']);
        exit;
    }

    $amount = (float) $due['amount'];
    $payment_id = (int) $due['payment_id'];
    $pstmt = $conn->prepare("SELECT balance_pending FROM member_payments WHERE payment_id = ? LIMIT 1 FOR UPDATE");
    $pstmt->bind_param('i', $payment_id);
    $pstmt->execute();
    $plan = $pstmt->get_result()->fetch_assoc();
    if (!$plan || $amount > (float) $plan['balance_pending']) {
        $conn->rollback();
        echo json_encode(['status' => 'error', 'message' => 'The plan balance changed. Review the current balance before verifying this payment.']);
        exit;
    }

    $u = $conn->prepare("UPDATE member_payments
                         SET amount_received = amount_received + ?, balance_pending = balance_pending - ?
                         WHERE payment_id = ?");
    $u->bind_param('ddi', $amount, $amount, $payment_id);
    $ok = $u->execute();

    if ($ok) {
        $ledger = $conn->prepare("INSERT INTO installment_payments (payment_id, installment_amount, payment_mode, transaction_id)
                                  VALUES (?, ?, 'upi', ?)");
        $ledger->bind_param('ids', $payment_id, $amount, $due['transaction_id']);
        $ok = $ledger->execute();
    }

    if ($ok) {
        $m = $conn->prepare("UPDATE member_due_payments SET status = 'verified', verified_by = ?, verified_at = NOW() WHERE id = ? AND status = 'pending'");
        $m->bind_param('ii', $admin_id, $id);
        $ok = $m->execute() && $m->affected_rows === 1;
    }

    if (!$ok) {
        $conn->rollback();
        echo json_encode(['status' => 'error', 'message' => 'Could not verify this payment. No balance changes were saved.']);
        exit;
    }

    // If this was a PARTIAL payment (money's still owed), the due-date notice on the
    // member's dashboard needs to move on to the NEXT scheduled installment, not stay
    // frozen on the date that was just paid. planned_installments is the schedule the
    // admin set when the plan was activated (see templates/payment_details.php).
    $remaining = $conn->query("SELECT balance_pending, next_due_date FROM member_payments WHERE payment_id = $payment_id")->fetch_assoc();
    if ($remaining && (float) $remaining['balance_pending'] > 0) {
        $old_due = $remaining['next_due_date'];
        $next = $conn->prepare("SELECT due_date FROM planned_installments
                                WHERE payment_id = ? AND (? IS NULL OR due_date > ?)
                                ORDER BY due_date ASC LIMIT 1");
        $next->bind_param('iss', $payment_id, $old_due, $old_due);
        $next->execute();
        $next_row = $next->get_result()->fetch_assoc();
        if ($next_row) {
            $adv = $conn->prepare("UPDATE member_payments SET next_due_date = ? WHERE payment_id = ?");
            $adv->bind_param('si', $next_row['due_date'], $payment_id);
            if (!$adv->execute()) {
                $conn->rollback();
                echo json_encode(['status' => 'error', 'message' => 'Could not advance the next due date. No balance changes were saved.']);
                exit;
            }
        }
    }

    $conn->commit();

    echo json_encode(['status' => 'success', 'verified' => true]);
    exit;
}

if ($action === 'reject') {
    $dstmt = $conn->prepare("SELECT id FROM member_due_payments WHERE id = ? AND status = 'pending' LIMIT 1");
    $dstmt->bind_param('i', $id);
    $dstmt->execute();
    if (!$dstmt->get_result()->fetch_assoc()) {
        echo json_encode(['status' => 'error', 'message' => 'That submission was not found, or has already been handled.']);
        exit;
    }

    $note = trim((string) ($_POST['note'] ?? ''));
    $note = $note !== '' ? mb_substr($note, 0, 255) : null;
    $m = $conn->prepare("UPDATE member_due_payments SET status = 'rejected', admin_note = ?, verified_by = ?, verified_at = NOW() WHERE id = ?");
    $m->bind_param('sii', $note, $admin_id, $id);
    $m->execute();

    echo json_encode(['status' => 'success', 'rejected' => true]);
    exit;
}

echo json_encode(['status' => 'error', 'message' => 'Unknown action.']);
