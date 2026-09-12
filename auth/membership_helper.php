<?php
/**
 * auth/membership_helper.php
 * Shared membership-status calculation for the member portal.
 */

if (!function_exists('membership_pauses_ensure_schema')) {
    /**
     * Creates membership_pauses if it is missing and adds the `kind` column
     * ('pause' | 'extension') on installs that predate it, so no page fatals
     * on an unknown table/column.
     */
    function membership_pauses_ensure_schema(mysqli $conn): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;

        $conn->query("CREATE TABLE IF NOT EXISTS membership_pauses (
            id INT NOT NULL AUTO_INCREMENT,
            member_id INT NOT NULL,
            payment_id INT NOT NULL,
            kind VARCHAR(20) NOT NULL DEFAULT 'pause',
            user_id INT DEFAULT NULL,
            pause_start DATE NOT NULL,
            pause_end DATE NOT NULL,
            days INT NOT NULL,
            reason VARCHAR(255) DEFAULT NULL,
            end_date_before DATE NOT NULL,
            end_date_after DATE NOT NULL,
            created_by INT DEFAULT NULL,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_mp_member (member_id),
            KEY idx_mp_payment (payment_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $col = $conn->query("SELECT COUNT(*) AS c FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'membership_pauses' AND COLUMN_NAME = 'kind'");
        if ($col && (int) $col->fetch_assoc()['c'] === 0) {
            $conn->query("ALTER TABLE membership_pauses ADD COLUMN kind VARCHAR(20) NOT NULL DEFAULT 'pause' AFTER payment_id");
        }
    }
}

if (!function_exists('membership_status_info')) {
    /**
     * Computes the real expiry / days-remaining for a member's current plan.
     *
     * member_payments.end_date is sometimes stale or wrong (e.g. left at a default
     * from an earlier/shorter plan), so the true expiry is derived from the plan's
     * actual declared duration in membership_plans (start_date + duration_value *
     * duration_unit) whenever that plan can be found in the catalog. The stored
     * end_date is only used as a fallback when the plan can't be matched.
     *
     * Pauses and extensions logged in membership_pauses against this payment are
     * then applied on top: a pause adds its days, an extension moves the expiry
     * out to the date it granted.
     *
     * @param mysqli     $conn
     * @param array|null $latest_payment  Latest row from member_payments (or null)
     * @param string     $fallback_plan_name  members.membership, used if no payment exists yet
     * @return array{plan_name:string, valid_until:?string, days_remaining:?int, status:string, start_date:?string}
     *         status is one of: none | active | expiring | expired
     */
    function membership_status_info(mysqli $conn, ?array $latest_payment, string $fallback_plan_name = ''): array
    {
        $plan_name = trim($latest_payment['membership_type'] ?? '') ?: trim($fallback_plan_name);

        $result = [
            'plan_name'      => $plan_name,
            'valid_until'    => null,
            'days_remaining' => null,
            'status'         => 'none',
            'start_date'     => $latest_payment['start_date'] ?? null,
        ];

        if ($plan_name === '' || !$latest_payment) {
            return $result;
        }

        $start_date = $latest_payment['start_date'] ?? $latest_payment['created_at'] ?? null;
        $valid_until = null;

        if ($start_date) {
            $pstmt = $conn->prepare("SELECT duration_value, duration_unit FROM membership_plans WHERE LOWER(plan_name) = LOWER(?) LIMIT 1");
            $pstmt->bind_param('s', $plan_name);
            $pstmt->execute();
            $prow = $pstmt->get_result()->fetch_assoc();

            if ($prow && (int) $prow['duration_value'] > 0) {
                $multiplier = match (strtolower($prow['duration_unit'] ?? '')) {
                    'week'  => 7,
                    'month' => 30,
                    'year'  => 365,
                    default => 1,
                };
                $total_days = (int) $prow['duration_value'] * $multiplier;
                $valid_until = date('Y-m-d', strtotime($start_date . " +{$total_days} days"));
            }
        }

        // Fall back to the stored end_date only if the plan couldn't be matched in the catalog
        if (!$valid_until && !empty($latest_payment['end_date'])) {
            $valid_until = $latest_payment['end_date'];
        }

        // Apply pauses / extensions recorded against this exact payment, in order
        if ($valid_until && !empty($latest_payment['payment_id'])) {
            membership_pauses_ensure_schema($conn);
            $payment_id = (int) $latest_payment['payment_id'];
            $astmt = $conn->prepare("SELECT kind, days, end_date_after FROM membership_pauses WHERE payment_id = ? ORDER BY id ASC");
            $astmt->bind_param('i', $payment_id);
            $astmt->execute();
            $ares = $astmt->get_result();
            while ($adj = $ares->fetch_assoc()) {
                if ($adj['kind'] === 'extension') {
                    if ($adj['end_date_after'] > $valid_until) {
                        $valid_until = $adj['end_date_after'];
                    }
                } else {
                    $valid_until = date('Y-m-d', strtotime($valid_until . ' +' . (int) $adj['days'] . ' days'));
                }
            }
        }

        $result['valid_until'] = $valid_until;

        if ($valid_until) {
            $days_remaining = (int) floor((strtotime($valid_until) - strtotime('today')) / 86400);
            $result['days_remaining'] = $days_remaining;
            if ($days_remaining < 0) {
                $result['status'] = 'expired';
            } elseif ($days_remaining <= 14) {
                $result['status'] = 'expiring';
            } else {
                $result['status'] = 'active';
            }
        }

        return $result;
    }
}

if (!function_exists('membership_pause_info')) {
    /**
     * Returns the member's currently-active pause window (today falls inside
     * a kind='pause' membership_pauses row), if any. Pausing only extends
     * member_payments.end_date — there is no persistent "paused" status column —
     * so this is derived at read time the same way the admin members list
     * (templates/members.php) does it. Extensions are NOT pauses and are ignored.
     *
     * @return array{pause_end:string, pause_start:string}|null
     */
    function membership_pause_info(mysqli $conn, int $member_id): ?array
    {
        membership_pauses_ensure_schema($conn);
        $stmt = $conn->prepare("SELECT pause_start, pause_end FROM membership_pauses
                                 WHERE member_id = ? AND kind = 'pause'
                                   AND CURDATE() BETWEEN pause_start AND pause_end
                                 ORDER BY pause_end DESC LIMIT 1");
        $stmt->bind_param('i', $member_id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        return $row ?: null;
    }
}

if (!function_exists('membership_extension_info')) {
    /**
     * Returns the member's most recent plan extension that is still running
     * (its granted end date is today or later), if any.
     *
     * @return array{days:int, reason:?string, end_date_before:string, end_date_after:string, created_at:string}|null
     */
    function membership_extension_info(mysqli $conn, int $member_id): ?array
    {
        membership_pauses_ensure_schema($conn);
        $stmt = $conn->prepare("SELECT days, reason, end_date_before, end_date_after, created_at
                                 FROM membership_pauses
                                 WHERE member_id = ? AND kind = 'extension' AND end_date_after >= CURDATE()
                                 ORDER BY id DESC LIMIT 1");
        $stmt->bind_param('i', $member_id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        return $row ?: null;
    }
}

if (!function_exists('membership_pending_request')) {
    /**
     * Returns the member's pending, not-yet-verified subscribe/renewal request (a
     * member_payments row with membership_type='Pending Setup'), if any — so the UI can show
     * a "+ New Plan Pending" tag alongside their real current plan without letting the
     * unverified request override what's actually active (see membership_status_info()).
     *
     * @return array{plan_name:string}|null
     */
    function membership_pending_request(mysqli $conn, int $member_id): ?array
    {
        $stmt = $conn->prepare("SELECT remarks FROM member_payments
                                 WHERE member_id = ? AND membership_type = 'Pending Setup'
                                 ORDER BY payment_id DESC LIMIT 1");
        $stmt->bind_param('i', $member_id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        if (!$row) {
            return null;
        }

        $plan_name = '';
        $remarks = trim($row['remarks'] ?? '');
        if ($remarks !== '' && (
            preg_match('/^Member requested:\s*(.+?)\s*\(\d+\s+installments?\)$/i', $remarks, $m)
            || preg_match('/^Member requested:\s*(.+)$/i', $remarks, $m)
        )) {
            $plan_name = trim($m[1]);
        }

        return ['plan_name' => $plan_name];
    }
}
