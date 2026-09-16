<?php
/**
 * auth/revenue_helper.php
 * ─────────────────────────────────────────────────────────────────
 * Single source of truth for "what money came in, and when" across every
 * admin analytics page (dashboard.php, reports_pg.php, revenue_source.php).
 *
 * Why this exists:
 * member_payments.amount_received is a RUNNING TOTAL — update_installment.php
 * adds each later installment straight into it — but member_payments.created_at
 * never moves off the row's original registration/renewal date. Summing
 * amount_received filtered by created_at therefore either misses installment
 * money entirely (a payment made this month against a plan bought 3 months ago
 * doesn't count as "this month") or, if installment_payments is also summed
 * alongside it (as reports_pg.php used to), double-counts it.
 *
 * The fix: split every member_payments row into its own real transactions —
 * the amount actually collected at signup/renewal (dated by created_at), plus
 * one transaction per later installment (dated by installment_payments.payment_date,
 * the date that money actually came in) — then union in add-on bookings and
 * consultations. Every caller filters/aggregates on this single `tx_date` and
 * gets an accurate, non-duplicated ledger no matter the date range.
 */

if (!function_exists('revenue_ledger_sql')) {
    /**
     * Returns the raw SQL text of the unified revenue ledger as a derived table
     * with columns: source_type, member_name, label, amount, tx_date, member_id,
     * is_renewal. Wrap it in "SELECT ... FROM (" . revenue_ledger_sql() . ") AS <alias>"
     * and filter/aggregate on tx_date / amount as needed.
     *
     * is_renewal: 1 when a 'Membership'/'Membership Installment' row's plan wasn't
     * the member's very first member_payments row (i.e. it's a renewal, not their
     * original signup) — so callers can label renewal transactions distinctly.
     * Always 0 for Add-on Service / Consultation rows (not applicable).
     */
    function revenue_ledger_sql(): string
    {
        return "
            -- Amount actually collected at signup/renewal (net of any installments
            -- paid later against the same payment_id), dated when that happened.
            SELECT
                'Membership' COLLATE utf8mb4_unicode_ci AS source_type,
                m.full_name COLLATE utf8mb4_unicode_ci AS member_name,
                mp.membership_type COLLATE utf8mb4_unicode_ci AS label,
                (mp.amount_received - COALESCE(inst.total_installments, 0)) AS amount,
                mp.created_at AS tx_date,
                mp.member_id AS member_id,
                (mp.payment_id > (SELECT MIN(mp0.payment_id) FROM member_payments mp0 WHERE mp0.member_id = mp.member_id)) AS is_renewal
            FROM member_payments mp
            JOIN members m ON m.id = mp.member_id
            LEFT JOIN (
                SELECT payment_id, SUM(installment_amount) AS total_installments
                FROM installment_payments
                GROUP BY payment_id
            ) inst ON inst.payment_id = mp.payment_id
            WHERE (mp.amount_received - COALESCE(inst.total_installments, 0)) > 0

            UNION ALL

            -- Each later installment: its own transaction, on the day it was paid.
            -- Inherits is_renewal from the plan it's an installment against.
            SELECT
                'Membership Installment' COLLATE utf8mb4_unicode_ci AS source_type,
                m.full_name COLLATE utf8mb4_unicode_ci AS member_name,
                mp.membership_type COLLATE utf8mb4_unicode_ci AS label,
                ip.installment_amount AS amount,
                ip.payment_date AS tx_date,
                mp.member_id AS member_id,
                (mp.payment_id > (SELECT MIN(mp0.payment_id) FROM member_payments mp0 WHERE mp0.member_id = mp.member_id)) AS is_renewal
            FROM installment_payments ip
            JOIN member_payments mp ON mp.payment_id = ip.payment_id
            JOIN members m ON m.id = mp.member_id

            UNION ALL

            SELECT
                'Add-on Service' COLLATE utf8mb4_unicode_ci AS source_type,
                member_name COLLATE utf8mb4_unicode_ci AS member_name,
                service_type COLLATE utf8mb4_unicode_ci AS label,
                price AS amount,
                created_at AS tx_date,
                member_id AS member_id,
                0 AS is_renewal
            FROM addon_services_bookings
            WHERE status != 'cancelled'

            UNION ALL

            SELECT
                'Consultation' COLLATE utf8mb4_unicode_ci AS source_type,
                member_name COLLATE utf8mb4_unicode_ci AS member_name,
                'Consultation' COLLATE utf8mb4_unicode_ci AS label,
                total_amount AS amount,
                created_at AS tx_date,
                NULL AS member_id,
                0 AS is_renewal
            FROM consultations
        ";
    }
}
