<?php
require_once __DIR__ . '/../../auth/auth_check.php';
require_role(['user']);
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../auth/dues_schema.php';
ensure_dues_schema($conn);

$user = get_session_user();
$uid  = (int) $user['id'];
$csrf = generate_csrf_token();

$mstmt = $conn->prepare("SELECT id FROM members WHERE user_id = ? ORDER BY id DESC LIMIT 1");
$mstmt->bind_param('i', $uid);
$mstmt->execute();
$member = $mstmt->get_result()->fetch_assoc();
$member_id = $member ? (int) $member['id'] : 0;

// All payment/membership records for this member, newest first. 'Pending Setup' rows are
// unverified subscribe/renewal requests (see handlers/subscribe_payment.php) — kept in the
// same list but flagged distinctly since they have no real amount/dates yet.
$payments = [];
$total_months = 0;
if ($member_id) {
    $pstmt = $conn->prepare("SELECT * FROM member_payments WHERE member_id = ? ORDER BY created_at DESC, payment_id DESC");
    $pstmt->bind_param('i', $member_id);
    $pstmt->execute();
    $res = $pstmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $payments[] = $row;
        if ($row['membership_type'] !== 'Pending Setup' && !empty($row['start_date']) && !empty($row['end_date'])) {
            $days = (strtotime($row['end_date']) - strtotime($row['start_date'])) / 86400;
            if ($days > 0) {
                $total_months += round($days / 30);
            }
        }
    }
}

// The admin's original installment schedule (set when the plan was activated/renewed),
// for the upcoming-installments hint — see templates/payment_details.php.
function plan_schedule($conn, $payment_id) {
    $s = $conn->prepare("SELECT expected_amount, due_date FROM planned_installments WHERE payment_id = ? ORDER BY due_date ASC");
    $s->bind_param('i', $payment_id);
    $s->execute();
    $res = $s->get_result();
    $out = [];
    while ($row = $res->fetch_assoc()) {
        $out[] = $row;
    }
    return $out;
}

function e($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
function fmt_money($n) { return '₹' . number_format((float) $n, 0); }
function fmt_date($d) { return $d ? date('d M Y', strtotime($d)) : '—'; }

$ACTIVE_NAV = 'payments';
$PAGE_TITLE = 'Payments & Invoices';
require __DIR__ . '/_shell_top.php';
?>
<style>
    .wrap { max-width: 960px; margin: 0 auto; }

    .summary-row {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        gap: 16px;
        margin-bottom: 22px;
    }
    .summary-card {
        background: var(--card);
        border: 1px solid var(--border);
        border-radius: var(--radius);
        box-shadow: var(--shadow);
        padding: 18px 20px;
    }
    .summary-card .lbl {
        font-size: 12.5px;
        color: var(--ink-soft);
        font-weight: 600;
        margin-bottom: 6px;
    }
    .summary-card .val {
        font-size: 22px;
        font-weight: 800;
        font-family: 'Sora', sans-serif;
        color: var(--ink);
    }

    .history-card {
        background: var(--card);
        border: 1px solid var(--border);
        border-radius: var(--radius);
        box-shadow: var(--shadow);
        overflow: hidden;
    }
    .history-head {
        padding: 18px 22px;
        border-bottom: 1px solid var(--border);
        font-size: 15px;
        font-weight: 700;
    }

    .pay-row {
        display: flex;
        align-items: flex-start;
        gap: 16px;
        padding: 18px 22px;
        border-bottom: 1px solid var(--border);
    }
    .pay-row:last-child { border-bottom: none; }

    .pay-icon {
        width: 40px;
        height: 40px;
        border-radius: 11px;
        background: var(--coral-tint);
        color: var(--coral-dark);
        display: flex;
        align-items: center;
        justify-content: center;
        flex-shrink: 0;
    }
    .pay-icon svg { width: 19px; height: 19px; }

    .pay-main { flex: 1; min-width: 0; }
    .pay-top {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 10px;
        flex-wrap: wrap;
        margin-bottom: 6px;
    }
    .pay-plan { font-size: 14.5px; font-weight: 700; }
    .pay-meta {
        font-size: 12.5px;
        color: var(--ink-soft);
        display: flex;
        flex-wrap: wrap;
        gap: 4px 14px;
        margin-bottom: 8px;
    }
    .pay-meta span b { color: var(--ink); font-weight: 600; }

    .pay-amounts {
        display: flex;
        gap: 20px;
        flex-wrap: wrap;
        font-size: 13px;
    }
    .pay-amounts div span { display: block; color: var(--ink-soft); font-size: 11.5px; margin-bottom: 2px; }
    .pay-amounts div b { font-size: 14.5px; font-family: 'Sora', sans-serif; }

    .status-pill {
        font-size: 11.5px;
        font-weight: 700;
        padding: 4px 10px;
        border-radius: 20px;
        display: inline-flex;
        align-items: center;
        gap: 6px;
        white-space: nowrap;
    }
    .status-pill.green { background: var(--green-tint); color: var(--green); }
    .status-pill.amber { background: var(--amber-tint); color: #B87814; }
    .status-pill.blue { background: #EEF2FF; color: #4338CA; }
    .dot { width: 6px; height: 6px; border-radius: 50%; background: currentColor; }

    .empty-state {
        background: var(--card); border-radius: var(--radius); padding: 60px 20px; text-align: center;
        box-shadow: var(--shadow); border: 1px solid var(--border); color: var(--ink-faint);
    }
    .empty-state .big { font-size: 36px; margin-bottom: 12px; }
    .empty-state h3 { color: var(--ink); font-size: 17px; margin-bottom: 8px; }

    @media (max-width: 700px) {
        .summary-row { grid-template-columns: 1fr; }
        .pay-row { flex-direction: column; }
        .pay-top { flex-direction: column; align-items: flex-start; }
    }

    /* ── Pay dues: QR button + two-step modal ── */
    .pay-dues-btn {
        display: inline-flex; align-items: center; gap: 7px; margin-top: 10px; padding: 8px 15px;
        background: var(--coral); color: #fff; border: none; border-radius: 10px; font-size: 12.5px;
        font-weight: 700; cursor: pointer; font-family: inherit;
    }
    .pay-dues-btn:hover { background: var(--coral-dark); }
    .pay-dues-btn svg { width: 14px; height: 14px; }
    .pay-dues-btn-sub { font-weight: 600; opacity: .85; font-size: 11.5px; }
    .dues-pending-note {
        display: inline-flex; align-items: center; gap: 7px; margin-top: 10px; padding: 7px 14px;
        background: #EEF2FF; color: #4338CA; border-radius: 10px; font-size: 12px; font-weight: 600;
    }

    /* Installment-by-installment breakdown, so partial payments stay visible instead
       of looking like one payment silently cleared the whole balance */
    .installment-breakdown {
        margin-top: 10px; padding: 12px 14px; background: var(--bg); border: 1px solid var(--border);
        border-radius: 11px; font-size: 12px;
    }
    .installment-breakdown-head {
        font-weight: 700; color: var(--ink); margin-bottom: 6px; padding-bottom: 6px; border-bottom: 1px dashed var(--border);
        display: flex; align-items: center; gap: 6px; flex-wrap: wrap;
    }
    .installment-breakdown-head b { color: var(--ink); }
    .installment-when { color: var(--ink-faint); font-weight: 500; }
    .installment-breakdown-row {
        display: flex; align-items: center; gap: 8px; padding: 5px 0; color: var(--ink-soft);
    }
    .installment-breakdown-row b { color: var(--ink); margin-left: auto; }

    .dues-modal-overlay {
        display: none; position: fixed; inset: 0; background: rgba(15, 20, 32, .55); backdrop-filter: blur(4px);
        z-index: 3000; align-items: center; justify-content: center; padding: 20px;
    }
    .dues-modal-overlay.active { display: flex; }
    .dues-modal-overlay .step { display: none; }
    .dues-modal-overlay .step.active { display: block; }

    /* Step 1 — QR, dark card, mirrors the membership page's Scan & Pay modal */
    .dues-qr-card { background: #0F1420; border-radius: 20px; max-width: 360px; width: 100%; overflow: hidden; }
    .dues-qr-head { padding: 20px 22px 4px; color: #fff; }
    .dues-qr-head b { font-size: 16px; font-weight: 700; display: block; }
    .dues-qr-head span { font-size: 12px; color: #9aa3b5; }
    .dues-qr-body { padding: 18px 22px 22px; text-align: center; }
    .dues-qr-body img { width: 100%; max-width: 220px; border-radius: 12px; background: #fff; padding: 8px; }
    .dues-qr-amount { margin-top: 14px; font-size: 22px; font-weight: 800; font-family: 'Sora', sans-serif; color: #fff; }
    .dues-qr-amount small { display: block; font-size: 11px; font-weight: 500; color: #9aa3b5; margin-top: 2px; }
    .dues-qr-upi { margin-top: 12px; font-size: 13px; color: #cbd5e1; }
    .dues-qr-upi b { color: #fff; user-select: all; }
    .dues-qr-acts { display: flex; gap: 10px; margin-top: 20px; }
    .dues-qr-acts button { flex: 1; padding: 11px; border-radius: 11px; font-size: 13.5px; font-weight: 700; border: none; cursor: pointer; font-family: inherit; }
    .dues-qr-cancel { background: rgba(255,255,255,.1); color: #fff; }
    .dues-qr-cancel:hover { background: rgba(255,255,255,.18); }
    .dues-qr-next { background: var(--coral); color: #fff; }
    .dues-qr-next:hover { background: var(--coral-dark); }

    /* Step 2 — payment details, light card */
    .dues-form-card {
        background: #fff; border-radius: 22px; max-width: 430px; width: 100%; padding: 28px 26px;
        max-height: 88vh; overflow-y: auto; box-shadow: 0 30px 70px -20px rgba(20,20,30,.3);
    }
    .dues-form-head { display: flex; align-items: center; gap: 13px; margin-bottom: 20px; }
    .dues-form-icon {
        width: 38px; height: 38px; border-radius: 11px; background: var(--green-tint); color: var(--green);
        display: flex; align-items: center; justify-content: center; flex-shrink: 0;
    }
    .dues-form-card h3 { font-size: 16.5px; font-weight: 800; letter-spacing: -.01em; }
    .dues-form-card .sub { font-size: 12.5px; color: var(--ink-soft); margin-top: 1px; }
    .dues-form-card .sub span { font-weight: 600; color: var(--ink); }

    /* The amount field is what the member is most likely to get wrong, so it
       gets its own prominent treatment instead of blending into the other fields */
    .dues-amount-fld { margin-bottom: 18px; }
    .dues-amount-fld > label { display: block; font-size: 12px; font-weight: 700; color: var(--ink-soft); margin-bottom: 7px; }
    .dues-amount-input-wrap {
        display: flex; align-items: center; gap: 2px; border: 2px solid var(--border); border-radius: 13px;
        padding: 10px 16px; background: var(--bg); transition: border-color .15s, background .15s;
    }
    .dues-amount-input-wrap:focus-within { border-color: var(--coral); background: #fff; box-shadow: 0 0 0 4px var(--coral-tint); }
    .dues-amount-input-wrap span { font-size: 22px; font-weight: 800; color: var(--ink-soft); font-family: 'Sora', sans-serif; }
    .dues-amount-input-wrap input {
        flex: 1; border: none; background: transparent; outline: none; font-size: 24px; font-weight: 800;
        font-family: 'Sora', sans-serif; color: var(--ink); min-width: 0; -moz-appearance: textfield;
    }
    .dues-amount-input-wrap input::-webkit-outer-spin-button, .dues-amount-input-wrap input::-webkit-inner-spin-button { -webkit-appearance: none; margin: 0; }
    .dues-amount-hint { font-size: 11.5px; color: var(--ink-faint); margin-top: 7px; }
    .dues-amount-hint b { color: var(--coral-dark); font-weight: 700; }

    .dues-fld { margin-bottom: 14px; }
    .dues-fld label { display: block; font-size: 12px; font-weight: 700; color: var(--ink-soft); margin-bottom: 6px; }
    .dues-fld input[type="text"] {
        width: 100%; padding: 10px 12px; border: 1.5px solid var(--border); border-radius: 10px; font-size: 13.5px; font-family: inherit;
        transition: border-color .15s;
    }
    .dues-fld input[type="text"]:focus { outline: none; border-color: var(--coral); }
    .dues-upload { border: 1.5px dashed var(--border); border-radius: 10px; padding: 14px; text-align: center; cursor: pointer; position: relative; transition: border-color .15s, background .15s; }
    .dues-upload:hover { border-color: var(--coral); background: var(--coral-tint); }
    .dues-upload input { position: absolute; inset: 0; opacity: 0; cursor: pointer; }
    .dues-upload-label { font-size: 12px; color: var(--ink-soft); }
    .dues-error { display: none; background: #FEF2F2; color: #B91C1C; border: 1px solid #FECACA; border-radius: 9px; padding: 9px 12px; font-size: 12.5px; margin-bottom: 14px; }
    .dues-error.show { display: block; }
    .dues-form-acts { display: flex; gap: 10px; margin-top: 20px; }
    .dues-form-acts button { flex: 1; padding: 12px; border-radius: 12px; font-size: 13.5px; font-weight: 700; border: none; cursor: pointer; font-family: inherit; transition: background .15s; }
    .dues-back-btn { background: var(--bg); color: var(--ink-soft); }
    .dues-back-btn:hover { background: var(--border); }
    .dues-submit-btn { background: var(--coral); color: #fff; box-shadow: 0 8px 20px -8px rgba(255,107,71,.7); }
    .dues-submit-btn:hover { background: var(--coral-dark); }
    .dues-submit-btn:disabled { opacity: .6; cursor: not-allowed; box-shadow: none; }

    .dues-success-overlay {
        display: none; position: fixed; inset: 0; background: rgba(15, 20, 32, .55); z-index: 3000;
        align-items: center; justify-content: center; padding: 20px;
    }
    .dues-success-overlay.active { display: flex; }
    .dues-success-card { background: #fff; border-radius: 18px; padding: 30px 26px; max-width: 340px; width: 100%; text-align: center; }
    .dues-success-card .ic { width: 52px; height: 52px; border-radius: 50%; background: var(--green-tint); color: var(--green); display: flex; align-items: center; justify-content: center; margin: 0 auto 14px; }
    .dues-success-card h3 { font-size: 17px; margin-bottom: 8px; }
    .dues-success-card p { font-size: 13px; color: var(--ink-soft); margin-bottom: 18px; line-height: 1.5; }
    .dues-success-card button { background: var(--coral); color: #fff; border: none; border-radius: 10px; padding: 10px 22px; font-weight: 700; font-size: 13.5px; cursor: pointer; font-family: inherit; }
</style>

<div class="wrap">

    <?php if (empty($payments)): ?>
        <div class="empty-state" data-tour="payments">
            <div class="big">💳</div>
            <h3>No payment history yet</h3>
            <p>Once you subscribe to a membership plan, your payments and invoices will show up here.</p>
        </div>
    <?php else: ?>

        <div class="summary-row" data-tour="payments">
            <div class="summary-card">
                <div class="lbl">Total Membership Duration</div>
                <div class="val"><?= (int) $total_months ?> month<?= $total_months == 1 ? '' : 's' ?></div>
            </div>
            <div class="summary-card">
                <div class="lbl">Memberships on Record</div>
                <div class="val"><?= count(array_filter($payments, fn($p) => $p['membership_type'] !== 'Pending Setup')) ?></div>
            </div>
            <?php
                $latest_confirmed = null;
                foreach ($payments as $p) {
                    if ($p['membership_type'] !== 'Pending Setup') { $latest_confirmed = $p; break; }
                }
                $latest_balance = $latest_confirmed ? (float) $latest_confirmed['balance_pending'] : 0;
            ?>
            <div class="summary-card">
                <div class="lbl">Balance Due (Latest Plan)</div>
                <div class="val" style="<?= $latest_balance > 0 ? 'color:var(--red);' : '' ?>"><?= fmt_money($latest_balance) ?></div>
            </div>
        </div>

        <div class="history-card">
            <div class="history-head">Payment History</div>

            <?php foreach ($payments as $p):
                $is_pending_request = $p['membership_type'] === 'Pending Setup';

                if ($is_pending_request) {
                    $plan_label = 'New plan request';
                    if (!empty($p['remarks']) && preg_match('/^Member requested:\s*(.+?)\s*\(\d+\s+installments?\)$/i', trim($p['remarks']), $m)) {
                        $plan_label = $m[1];
                    }
                } else {
                    $plan_label = $p['membership_type'];
                }

                $total_amount = (float) $p['total_amount'];
                $amount_received = (float) $p['amount_received'];
                $balance_pending = (float) $p['balance_pending'];
            ?>
                <div class="pay-row">
                    <div class="pay-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <rect x="3" y="5" width="18" height="14" rx="2" />
                            <path d="M3 10h18" />
                        </svg>
                    </div>
                    <div class="pay-main">
                        <div class="pay-top">
                            <div class="pay-plan"><?= e($plan_label) ?></div>
                            <?php if ($is_pending_request): ?>
                                <span class="status-pill blue"><span class="dot"></span>Pending Verification</span>
                            <?php elseif ($balance_pending > 0): ?>
                                <span class="status-pill amber"><span class="dot"></span>Balance Due</span>
                            <?php else: ?>
                                <span class="status-pill green"><span class="dot"></span>Paid in Full</span>
                            <?php endif; ?>
                        </div>

                        <?php if ($is_pending_request): ?>
                            <div class="pay-meta">
                                <span>Requested on <b><?= fmt_date($p['created_at']) ?></b></span>
                                <?php if (!empty($p['transaction_id'])): ?><span>Ref <b><?= e($p['transaction_id']) ?></b></span><?php endif; ?>
                            </div>
                            <div class="pay-meta" style="color:var(--ink-faint);">Awaiting your trainer's confirmation — amount and dates will appear once verified.</div>
                        <?php else: ?>
                            <div class="pay-meta">
                                <span>Start <b><?= fmt_date($p['start_date']) ?></b></span>
                                <span>End <b><?= fmt_date($p['end_date']) ?></b></span>
                                <span>Installments <b><?= (int) $p['installments_count'] ?: 1 ?></b></span>
                                <?php if (!empty($p['payment_mode'])): ?><span>Mode <b><?= e(ucfirst($p['payment_mode'])) ?></b></span><?php endif; ?>
                                <?php if (!empty($p['transaction_id'])): ?><span>Ref <b><?= e($p['transaction_id']) ?></b></span><?php endif; ?>
                                <?php if ($balance_pending > 0 && !empty($p['next_due_date'])): ?><span>Next due <b><?= fmt_date($p['next_due_date']) ?></b></span><?php endif; ?>
                            </div>
                            <div class="pay-amounts">
                                <div><span>Total</span><b><?= fmt_money($total_amount) ?></b></div>
                                <?php if ((float) $p['discount'] > 0): ?><div><span>Discount</span><b>-<?= fmt_money($p['discount']) ?></b></div><?php endif; ?>
                                <div><span>Paid</span><b><?= fmt_money($amount_received) ?></b></div>
                                <?php if ($balance_pending > 0): ?><div><span>Balance</span><b style="color:var(--red);"><?= fmt_money($balance_pending) ?></b></div><?php endif; ?>
                            </div>
                            <?php
                                $installments_total = (int) $p['installments_count'] ?: 1;
                                $due_history = $installments_total > 1 ? due_payments_for_plan($conn, (int) $p['payment_id']) : [];
                            ?>
                            <?php if ($due_history):
                                // amount_received already includes every verified top-up, so the
                                // original first installment is whatever's left after backing those out.
                                $verified_total = array_sum(array_map(fn($d) => $d['status'] === 'verified' ? (float) $d['amount'] : 0, $due_history));
                                $first_installment = $amount_received - $verified_total;
                            ?>
                                <div class="installment-breakdown">
                                    <div class="installment-breakdown-head">Installment 1 <b><?= fmt_money($first_installment) ?></b> <span class="installment-when">· <?= fmt_date($p['start_date']) ?></span></div>
                                    <?php $n = 1; foreach ($due_history as $d): ?>
                                        <div class="installment-breakdown-row">
                                            <span><?= $d['status'] === 'rejected' ? 'Rejected attempt' : 'Installment ' . (++$n) ?></span>
                                            <b><?= fmt_money($d['amount']) ?></b>
                                            <?php if ($d['status'] === 'verified'): ?>
                                                <span class="status-pill green" style="padding:2px 8px;font-size:10.5px;">Paid <?= fmt_date($d['verified_at']) ?></span>
                                            <?php elseif ($d['status'] === 'pending'): ?>
                                                <span class="status-pill blue" style="padding:2px 8px;font-size:10.5px;">Awaiting verification</span>
                                            <?php else: ?>
                                                <span class="status-pill" style="padding:2px 8px;font-size:10.5px;background:#FEF2F2;color:#B91C1C;">Rejected</span>
                                            <?php endif; ?>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                            <?php if ($balance_pending > 0):
                                $pending_due = due_payment_pending_for($conn, (int) $p['payment_id']);
                                $paid_installments = count(array_filter($due_history, fn($d) => $d['status'] !== 'rejected')) + 1;
                                $remaining_slots = max(1, $installments_total - $paid_installments);

                                // The admin's schedule for the installments still to come. Once a partial
                                // payment is verified, next_due_date moves to the next row, so anything from
                                // next_due_date onward is what's still outstanding on the schedule.
                                $upcoming = [];
                                if ($installments_total > 1) {
                                    foreach (plan_schedule($conn, (int) $p['payment_id']) as $s) {
                                        if (empty($p['next_due_date']) || $s['due_date'] >= $p['next_due_date']) {
                                            $upcoming[] = $s;
                                        }
                                    }
                                }
                            ?>
                                <?php if ($upcoming): ?>
                                    <div class="installment-breakdown installment-upcoming">
                                        <div class="installment-breakdown-head">Still to pay <b><?= fmt_money($balance_pending) ?></b> in <?= count($upcoming) ?> installment<?= count($upcoming) === 1 ? '' : 's' ?></div>
                                        <?php foreach ($upcoming as $i => $s): ?>
                                            <div class="installment-breakdown-row">
                                                <span>Installment <?= $installments_total - count($upcoming) + $i + 1 ?></span>
                                                <span class="installment-when"><?= fmt_date($s['due_date']) ?></span>
                                                <b><?= fmt_money($s['expected_amount']) ?></b>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                                <?php if ($pending_due): ?>
                                    <div class="dues-pending-note">
                                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 3"/></svg>
                                        Payment of <?= fmt_money($pending_due['amount']) ?> submitted — awaiting verification
                                    </div>
                                <?php else: ?>
                                    <button type="button" class="pay-dues-btn" data-tour="pay-dues" data-payment-id="<?= (int) $p['payment_id'] ?>"
                                        data-plan="<?= e($plan_label) ?>" data-balance="<?= (int) round($balance_pending) ?>"
                                        data-installments-left="<?= $installments_total > 1 ? $remaining_slots : 0 ?>"
                                        onclick="openDuesModal(this)">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><rect x="5" y="2" width="14" height="20" rx="2"/><path d="M11 18h2"/></svg>
                                        Pay via QR <span class="pay-dues-btn-sub">up to <?= fmt_money($balance_pending) ?></span>
                                    </button>
                                <?php endif; ?>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

    <?php endif; ?>

</div>

<!-- Pay dues modal: step 1 (QR), step 2 (payment details) -->
<div class="dues-modal-overlay" id="duesModal">
    <div class="step active" id="duesStepQr">
        <div class="dues-qr-card">
            <div class="dues-qr-head">
                <b>Scan &amp; Pay</b>
                <span>Clear your outstanding balance</span>
            </div>
            <div class="dues-qr-body">
                <img src="../../icons/images/qr_code.jpeg" alt="Payment QR Code">
                <div class="dues-qr-amount">₹<span id="duesQrAmount">0</span><small id="duesQrPlan"></small></div>
                <div class="dues-qr-upi">UPI ID: <b>7798487212@hdfc</b></div>
            </div>
            <div style="padding:0 22px 22px;">
                <div class="dues-qr-acts">
                    <button type="button" class="dues-qr-cancel" id="duesCancelBtn">Cancel</button>
                    <button type="button" class="dues-qr-next" id="duesNextBtn">Next →</button>
                </div>
            </div>
        </div>
    </div>

    <div class="step" id="duesStepForm">
        <div class="dues-form-card">
            <div class="dues-form-head">
                <div class="dues-form-icon">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6L9 17l-5-5"/></svg>
                </div>
                <div>
                    <h3>Confirm Your Payment</h3>
                    <div class="sub">Toward <span id="duesFormPlan"></span></div>
                </div>
            </div>

            <div class="dues-error" id="duesError"></div>

            <form id="duesForm">
                <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="payment_id" id="duesPaymentId" value="">

                <div class="dues-amount-fld">
                    <label>Amount you're paying now *</label>
                    <div class="dues-amount-input-wrap">
                        <span>₹</span>
                        <input type="number" name="amount" id="duesAmountInput" min="1" step="1" required>
                    </div>
                    <div class="dues-amount-hint" id="duesAmountHint"></div>
                </div>

                <div class="dues-fld">
                    <label>Transaction ID / UPI Ref *</label>
                    <input type="text" name="transaction_id" maxlength="20" placeholder="Last 4-6 digits" required>
                </div>
                <div class="dues-fld">
                    <label>Paid By <span style="font-weight:500;">(if different)</span></label>
                    <input type="text" name="payer_name" maxlength="100" placeholder="e.g. Father/Friend Name">
                </div>
                <div class="dues-fld">
                    <label>Upload Payment Screenshot <span style="font-weight:500;">(optional)</span></label>
                    <div class="dues-upload">
                        <input type="file" name="payment_screenshot" id="duesScreenshotInput" accept="image/jpeg,image/png,application/pdf">
                        <div class="dues-upload-label" id="duesScreenshotLabel">Click to upload screenshot</div>
                    </div>
                </div>
                <div class="dues-form-acts">
                    <button type="button" class="dues-back-btn" id="duesBackBtn">← Back</button>
                    <button type="submit" class="dues-submit-btn" id="duesSubmitBtn">Submit Payment</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="dues-success-overlay" id="duesSuccessOverlay">
    <div class="dues-success-card">
        <div class="ic">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6L9 17l-5-5"/></svg>
        </div>
        <h3>Payment Submitted!</h3>
        <p>Your trainer will verify this shortly and your balance will update once approved.</p>
        <button type="button" id="duesSuccessOkBtn">Got it</button>
    </div>
</div>

<script>
    const duesModal = document.getElementById('duesModal');
    const duesStepQr = document.getElementById('duesStepQr');
    const duesStepForm = document.getElementById('duesStepForm');
    const duesForm = document.getElementById('duesForm');
    const duesError = document.getElementById('duesError');
    const duesSubmitBtn = document.getElementById('duesSubmitBtn');
    const duesSuccessOverlay = document.getElementById('duesSuccessOverlay');

    function openDuesModal(btn) {
        const paymentId = btn.dataset.paymentId;
        const balance = Number(btn.dataset.balance);
        const installmentsLeft = Number(btn.dataset.installmentsLeft || 0);
        const plan = btn.dataset.plan;

        document.getElementById('duesQrAmount').textContent = balance.toLocaleString('en-IN');
        document.getElementById('duesQrPlan').textContent = plan;
        document.getElementById('duesFormPlan').textContent = plan;
        document.getElementById('duesPaymentId').value = paymentId;

        const amountInput = document.getElementById('duesAmountInput');
        amountInput.value = balance;
        amountInput.max = balance;
        const hint = document.getElementById('duesAmountHint');
        hint.innerHTML = installmentsLeft > 1
            ? `You can split this — up to <b>₹${balance.toLocaleString('en-IN')}</b> left, over ${installmentsLeft} more installments if needed.`
            : `Up to <b>₹${balance.toLocaleString('en-IN')}</b> remaining on this plan.`;

        duesError.classList.remove('show');
        duesForm.reset();
        amountInput.value = balance; // reset() above would've cleared it
        document.getElementById('duesPaymentId').value = paymentId;
        document.getElementById('duesScreenshotLabel').textContent = 'Click to upload screenshot';

        duesStepQr.classList.add('active');
        duesStepForm.classList.remove('active');
        duesModal.classList.add('active');
    }

    function closeDuesModal() {
        duesModal.classList.remove('active');
    }

    document.getElementById('duesCancelBtn').addEventListener('click', closeDuesModal);
    document.getElementById('duesNextBtn').addEventListener('click', () => {
        duesStepQr.classList.remove('active');
        duesStepForm.classList.add('active');
    });
    document.getElementById('duesBackBtn').addEventListener('click', () => {
        duesStepForm.classList.remove('active');
        duesStepQr.classList.add('active');
    });
    duesModal.addEventListener('click', (e) => { if (e.target === duesModal) closeDuesModal(); });

    const duesScreenshotInput = document.getElementById('duesScreenshotInput');
    duesScreenshotInput.addEventListener('change', () => {
        document.getElementById('duesScreenshotLabel').textContent =
            duesScreenshotInput.files[0] ? duesScreenshotInput.files[0].name : 'Click to upload screenshot';
    });

    duesForm.addEventListener('submit', (e) => {
        e.preventDefault();
        duesError.classList.remove('show');

        const amountInput = document.getElementById('duesAmountInput');
        const amt = Number(amountInput.value);
        const max = Number(amountInput.max);
        if (!amt || amt <= 0) {
            duesError.textContent = 'Enter how much you\'re paying.';
            duesError.classList.add('show');
            amountInput.focus();
            return;
        }
        if (amt > max) {
            duesError.textContent = `That's more than the ₹${max.toLocaleString('en-IN')} remaining on this plan.`;
            duesError.classList.add('show');
            amountInput.focus();
            return;
        }

        duesSubmitBtn.disabled = true;
        duesSubmitBtn.textContent = 'Submitting…';

        fetch('../../handlers/pay_dues.php', { method: 'POST', body: new FormData(duesForm) })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    closeDuesModal();
                    duesSuccessOverlay.classList.add('active');
                } else {
                    duesError.textContent = data.error || 'Something went wrong. Please try again.';
                    duesError.classList.add('show');
                }
            })
            .catch(() => {
                duesError.textContent = 'Network error. Please try again.';
                duesError.classList.add('show');
            })
            .finally(() => {
                duesSubmitBtn.disabled = false;
                duesSubmitBtn.textContent = 'Submit Payment';
            });
    });

    document.getElementById('duesSuccessOkBtn').addEventListener('click', () => {
        duesSuccessOverlay.classList.remove('active');
        window.location.reload();
    });
</script>

<?php require __DIR__ . '/_shell_bottom.php'; ?>
