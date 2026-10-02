<?php
/**
 * auth/dues_schema.php
 * ─────────────────────────────────────────────────────────────────
 * Schema + shared queries for member-submitted "I've paid my outstanding
 * balance" requests (member_due_payments).
 *
 * A member_payments row already tracks balance_pending for a plan, but it has
 * nowhere to hold a NEW, not-yet-verified payment attempt against that balance
 * without overwriting the plan's own original transaction_id/screenshot. This
 * table is the same idea as the 'Pending Setup' row subscribe_payment.php uses
 * for new plans, applied to paying off an existing one instead.
 */

if (!function_exists('ensure_dues_schema')) {
    function ensure_dues_schema(mysqli $conn): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;

        $conn->query("CREATE TABLE IF NOT EXISTS member_due_payments (
            id INT AUTO_INCREMENT PRIMARY KEY,
            payment_id INT NOT NULL,
            member_id INT NOT NULL,
            amount DECIMAL(10,2) NOT NULL,
            transaction_id VARCHAR(50) NOT NULL,
            payer_name VARCHAR(100) DEFAULT NULL,
            screenshot_path VARCHAR(255) DEFAULT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            admin_note VARCHAR(255) DEFAULT NULL,
            verified_by INT DEFAULT NULL,
            verified_at TIMESTAMP NULL DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_mdp_member (member_id),
            INDEX idx_mdp_payment (payment_id),
            INDEX idx_mdp_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
}

if (!function_exists('due_payment_pending_for')) {
    /**
     * The member's own not-yet-verified submission against one specific plan
     * row, if any — so the "Pay via QR" button can turn into a "Pending
     * verification" state instead of letting them submit a second one.
     */
    function due_payment_pending_for(mysqli $conn, int $payment_id): ?array
    {
        ensure_dues_schema($conn);
        $stmt = $conn->prepare("SELECT id, amount, created_at FROM member_due_payments
                                 WHERE payment_id = ? AND status = 'pending' ORDER BY id DESC LIMIT 1");
        $stmt->bind_param('i', $payment_id);
        $stmt->execute();
        return $stmt->get_result()->fetch_assoc() ?: null;
    }
}

if (!function_exists('due_payments_pending_list')) {
    /**
     * Every not-yet-verified dues submission, across all members, newest first
     * — the admin dashboard card's popup list.
     */
    function due_payments_pending_list(mysqli $conn, int $limit = 50): array
    {
        ensure_dues_schema($conn);
        $stmt = $conn->prepare("SELECT d.id, d.payment_id, d.member_id, d.amount, d.transaction_id, d.created_at,
                                        m.full_name AS member_name, mp.membership_type AS plan_name
                                 FROM member_due_payments d
                                 JOIN members m ON m.id = d.member_id
                                 JOIN member_payments mp ON mp.payment_id = d.payment_id
                                 WHERE d.status = 'pending'
                                 ORDER BY d.id DESC LIMIT ?");
        $stmt->bind_param('i', $limit);
        $stmt->execute();
        $res = $stmt->get_result();
        $out = [];
        while ($row = $res->fetch_assoc()) {
            $out[] = $row;
        }
        return $out;
    }
}

if (!function_exists('due_payments_for_plan')) {
    /**
     * Every installment top-up submitted against one plan — verified, still
     * pending, and rejected — oldest first. This is what makes a plan paid in,
     * say, three separate installments show as three separate installments on
     * the member's own Payments & Invoices page, instead of one payment that
     * silently "cleared everything."
     */
    function due_payments_for_plan(mysqli $conn, int $payment_id): array
    {
        ensure_dues_schema($conn);
        $stmt = $conn->prepare("SELECT id, amount, status, transaction_id, created_at, verified_at
                                 FROM member_due_payments WHERE payment_id = ? ORDER BY id ASC");
        $stmt->bind_param('i', $payment_id);
        $stmt->execute();
        $res = $stmt->get_result();
        $out = [];
        while ($row = $res->fetch_assoc()) {
            $out[] = $row;
        }
        return $out;
    }
}
