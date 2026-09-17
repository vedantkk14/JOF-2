<?php
require_once __DIR__ . '/../../auth/auth_check.php';
require_role(['user']);
require_once __DIR__ . '/../../config.php';

$user = get_session_user();
$uid  = (int) $user['id'];

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
</style>

<div class="wrap">

    <?php if (empty($payments)): ?>
        <div class="empty-state">
            <div class="big">💳</div>
            <h3>No payment history yet</h3>
            <p>Once you subscribe to a membership plan, your payments and invoices will show up here.</p>
        </div>
    <?php else: ?>

        <div class="summary-row">
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
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

    <?php endif; ?>

</div>

<?php require __DIR__ . '/_shell_bottom.php'; ?>
