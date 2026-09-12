<?php
require_once __DIR__ . '/../../auth/auth_check.php';
require_role(['user']);
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../auth/membership_helper.php';

$user = get_session_user();
$uid  = (int) $user['id'];

$mstmt = $conn->prepare("SELECT id, full_name, membership FROM members WHERE user_id = ? ORDER BY id DESC LIMIT 1");
$mstmt->bind_param('i', $uid);
$mstmt->execute();
$member = $mstmt->get_result()->fetch_assoc();
$member_id = $member ? (int) $member['id'] : 0;

// Latest CONFIRMED payment row = source of truth for the member's current plan/expiry/dues.
// A 'Pending Setup' placeholder from an unverified subscribe/renewal request is excluded here
// so it can never override a plan that's actually active — see auth/membership_helper.php.
$latest_payment = null;
$pending_plan_request = null;
if ($member_id) {
    $paystmt = $conn->prepare("SELECT * FROM member_payments
                                WHERE member_id = ? AND membership_type != 'Pending Setup'
                                ORDER BY created_at DESC LIMIT 1");
    $paystmt->bind_param('i', $member_id);
    $paystmt->execute();
    $latest_payment = $paystmt->get_result()->fetch_assoc();

    $pending_plan_request = membership_pending_request($conn, $member_id);
}

// Same COALESCE convention used on the admin side (templates/membership.php, templates/members.php):
// latest member_payments.membership_type wins, falls back to members.membership.
// The real expiry/days-remaining is derived from the plan's declared duration in
// membership_plans (start_date + duration), not blindly trusted from end_date,
// since that column is sometimes stale/incorrect in member_payments.
$membership_info   = membership_status_info($conn, $latest_payment, $member['membership'] ?? '');
$current_plan_name = $membership_info['plan_name'];
$days_remaining    = $membership_info['days_remaining'];
$expiry_status      = $membership_info['status'];
$membership_pause   = $member_id ? membership_pause_info($conn, $member_id) : null;
$membership_extension = ($member_id && !$membership_pause) ? membership_extension_info($conn, $member_id) : null;

// Full plan catalog, same ordering as the admin page
$plans = [];
$pres = $conn->query("SELECT * FROM membership_plans ORDER BY price ASC");
if ($pres) {
    while ($row = $pres->fetch_assoc()) {
        $plans[] = $row;
    }
}

// Enrich the current plan with its catalog details (price/features/etc), if the plan still exists there
$current_plan_details = null;
foreach ($plans as $p) {
    if ($current_plan_name !== '' && strcasecmp(trim($p['plan_name']), $current_plan_name) === 0) {
        $current_plan_details = $p;
        break;
    }
}

function e($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }

function membershipPeriodLabel($plan) {
    $unit = $plan['duration_unit'] ?? '';
    if (strcasecmp($unit, 'Month') === 0) return '/mo';
    if (strcasecmp($unit, 'Year') === 0) return '/yr';
    return '/' . (int) ($plan['duration_value'] ?? 0) . 'w';
}

function membershipFeatures($plan) {
    $raw = trim($plan['features'] ?? '');
    if ($raw === '') return [];
    return array_values(array_filter(array_map('trim', explode("\n", $raw)), fn($f) => $f !== ''));
}

// Plan data for the in-page Subscribe modal (avoids a page navigation / extra fetch)
$plans_js = [];
foreach ($plans as $p) {
    $plans_js[] = [
        'id'         => (int) $p['id'],
        'plan_name'  => $p['plan_name'],
        'price'      => number_format((float) $p['price']),
        'period'     => membershipPeriodLabel($p),
        'duration'   => (int) $p['duration_value'] . ' ' . $p['duration_unit'] . ' plan',
    ];
}

$ACTIVE_NAV = 'membership';
$PAGE_TITLE = 'Membership';
require __DIR__ . '/_shell_top.php';
?>
<style>
    .wrap { max-width: 1080px; margin: 0 auto; }
    .page-head { margin-bottom: 20px; }
    .page-head h1 { font-size: 24px; }
    .page-head p { color: var(--ink-soft); font-size: 13.5px; margin-top: 4px; }

    /* ===== Current plan hero ===== */
    .current-hero {
        border-radius: var(--radius); padding: 26px 28px; margin-bottom: 24px; color: #fff; position: relative; overflow: hidden;
        background: linear-gradient(135deg, #FF6B47 0%, #FF8A5B 55%, #FFA94D 100%);
    }
    .current-hero.expired { background: linear-gradient(135deg, #6B7280 0%, #4B5563 100%); }
    .current-hero.none { background: linear-gradient(135deg, #9CA3AF 0%, #6B7280 100%); }
    .current-hero::after { content: ''; position: absolute; right: -60px; top: -60px; width: 200px; height: 200px; border-radius: 50%; background: rgba(255,255,255,.12); }
    .current-hero-row { position: relative; z-index: 1; display: flex; flex-wrap: wrap; justify-content: space-between; gap: 20px; }
    .current-hero .tag { display: inline-flex; align-items: center; gap: 6px; font-size: 11px; font-weight: 800; text-transform: uppercase; letter-spacing: .04em; background: rgba(255,255,255,.22); padding: 4px 12px; border-radius: 999px; margin-bottom: 10px; margin-right: 6px; }
    .current-hero .tag.pending-tag { background: #fff; color: #4338CA; }
    .current-hero h2 { font-size: 22px; }
    .current-hero .sub { font-size: 13px; opacity: .92; margin-top: 4px; }
    .current-hero-stats { display: flex; gap: 28px; flex-wrap: wrap; }
    .current-hero-stats .num { font-family: 'Sora', sans-serif; font-size: 22px; font-weight: 800; }
    .current-hero-stats .lbl { font-size: 11px; opacity: .85; margin-top: 2px; }

    /* ===== Explore plans grid ===== */
    .section-title {
        display: flex; align-items: center; gap: 12px; margin: 6px 0 16px;
    }
    .section-title .ic {
        width: 34px; height: 34px; border-radius: 10px; flex-shrink: 0; background: var(--coral-tint);
        color: var(--coral-dark); display: flex; align-items: center; justify-content: center;
    }
    .section-title .ic svg { width: 17px; height: 17px; }
    .section-title h2 { font-size: 15.5px; font-weight: 700; }
    .section-title p { font-size: 12px; color: var(--ink-soft); margin-top: 1px; }
    .tag svg { width: 11px; height: 11px; }
    .plans-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(230px, 1fr)); gap: 16px; }
    .member-plan-card {
        background: var(--card); border-radius: 18px; box-shadow: var(--shadow); border: 1px solid var(--border);
        display: flex; flex-direction: column; overflow: hidden; position: relative; transition: transform .15s ease;
    }
    .member-plan-card:hover { transform: translateY(-3px); }
    .member-plan-card.is-current { border: 2px solid var(--coral); }
    .plan-strip { height: 6px; background: var(--coral); }
    .member-plan-card:nth-child(4n+2) .plan-strip { background: #3B82F6; }
    .member-plan-card:nth-child(4n+3) .plan-strip { background: #8B5CF6; }
    .member-plan-card:nth-child(4n+4) .plan-strip { background: #10B981; }
    .current-badge {
        position: absolute; top: 14px; right: 14px; background: var(--coral); color: #fff; font-size: 10px; font-weight: 800;
        text-transform: uppercase; letter-spacing: .04em; padding: 4px 10px; border-radius: 999px;
    }
    .plan-card-head { padding: 18px 18px 10px; text-align: center; }
    .plan-card-head h3 { font-size: 14.5px; margin-bottom: 6px; }
    .plan-price { font-family: 'Sora', sans-serif; font-size: 22px; font-weight: 800; }
    .plan-price .period { font-size: 11px; font-weight: 500; color: var(--ink-soft); }
    .plan-desc { font-size: 11.5px; color: var(--ink-soft); margin-top: 6px; line-height: 1.4; }
    .plan-stats-row { display: flex; justify-content: space-around; padding: 10px; background: var(--bg); border-top: 1px solid var(--border); border-bottom: 1px solid var(--border); font-size: 11px; color: var(--ink-soft); }
    .plan-stats-row b { color: var(--ink); }
    .plan-features { list-style: none; padding: 12px 18px 16px; flex: 1; }
    .plan-features li { display: flex; align-items: flex-start; gap: 7px; font-size: 11.5px; margin-bottom: 7px; color: var(--ink); }
    .plan-features li::before { content: '✓'; color: var(--green); font-weight: 800; flex-shrink: 0; }

    .plan-subscribe { padding: 0 18px 18px; }
    .subscribe-btn {
        display: block; width: 100%; text-align: center; padding: 10px 14px; border-radius: 10px;
        background: var(--coral); color: #fff; font-weight: 700; font-size: 13px; border: none; cursor: pointer; font-family: inherit;
    }
    .subscribe-btn:hover { background: var(--coral-dark); }

    .empty-card { text-align: center; padding: 50px 20px; background: var(--card); border-radius: var(--radius); box-shadow: var(--shadow); border: 1px solid var(--border); color: var(--ink-faint); }

    /* ===== Subscribe modal (blurred backdrop, in-page popup) ===== */
    .subscribe-overlay {
        display: none; position: fixed; inset: 0; background: rgba(15,20,32,.45); backdrop-filter: blur(6px); -webkit-backdrop-filter: blur(6px);
        z-index: 3000; align-items: flex-start; justify-content: center; padding: 40px 16px; overflow-y: auto;
    }
    .subscribe-overlay.active { display: flex; }
    .popup-shell { max-width: 780px; width: 100%; margin: auto; background: var(--card); border-radius: 22px; box-shadow: 0 25px 60px -20px rgba(20,20,30,.45); overflow: hidden; }

    .popup-header { display: flex; align-items: center; justify-content: space-between; padding: 22px 28px; border-bottom: 1px solid var(--border); }
    .popup-header .brand { display: flex; align-items: center; gap: 14px; }
    .popup-header .brand img { width: 42px; height: 42px; object-fit: contain; }
    .popup-header h2 { font-size: 19px; }
    .popup-header p { font-size: 12.5px; color: var(--ink-soft); margin-top: 2px; }
    .popup-close { width: 36px; height: 36px; border-radius: 10px; border: 1px solid var(--border); background: #fff; color: var(--ink-soft); font-size: 16px; display: flex; align-items: center; justify-content: center; }
    .popup-close:hover { color: var(--coral); border-color: var(--coral); }

    .modal-plan-strip { display: flex; align-items: center; justify-content: space-between; gap: 14px; padding: 16px 28px; background: linear-gradient(135deg, #FF6B47 0%, #FF8A5B 55%, #FFA94D 100%); color: #fff; }
    .modal-plan-strip .name { font-weight: 700; font-size: 15px; }
    .modal-plan-strip .sub { font-size: 12px; opacity: .9; }
    .modal-plan-strip .amount { font-family: 'Sora', sans-serif; font-weight: 800; font-size: 20px; }
    .modal-plan-strip .amount .period { font-size: 11px; font-weight: 500; opacity: .85; }

    .popup-body { padding: 26px 28px 30px; }
    .form-alert { display: none; background: #FCEBEC; color: #9b1c1c; padding: 12px 15px; border-radius: 11px; font-size: 13px; font-weight: 600; margin-bottom: 18px; }
    .form-alert.show { display: block; }

    .pm-section-title { display: flex; align-items: center; justify-content: space-between; gap: 10px; margin-bottom: 18px; }
    .pm-section-title h3 { font-size: 15px; display: flex; align-items: center; gap: 9px; }

    .scan-pay-btn {
        display: inline-flex; align-items: center; gap: 8px; background: #0F1420; color: #fff;
        border: none; border-radius: 999px; padding: 9px 16px 9px 9px; font-weight: 700; font-size: 13px;
    }
    .scan-pay-btn .badge { width: 26px; height: 26px; border-radius: 50%; background: var(--coral); display: flex; align-items: center; justify-content: center; font-size: 13px; }
    .scan-pay-btn:hover { background: #1c2233; }

    .pm-form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
    @media (max-width: 560px) { .pm-form-grid { grid-template-columns: 1fr; } }
    .pm-fld.full { grid-column: 1 / -1; }
    .pm-fld label { display: block; font-size: 12.5px; font-weight: 700; color: var(--ink-soft); margin-bottom: 7px; }
    .pm-fld .opt { font-weight: 400; color: var(--ink-faint); }
    .pm-fld select, .pm-fld input[type=text] {
        width: 100%; padding: 12px 13px; border: 1.5px solid var(--border); border-radius: 11px;
        font-size: 14px; outline: none; font-family: inherit; background: #fff;
    }
    .pm-fld select:focus, .pm-fld input[type=text]:focus { border-color: var(--coral); box-shadow: 0 0 0 3px var(--coral-tint); }

    .pm-upload-zone { position: relative; border: 2px dashed var(--border); border-radius: 12px; padding: 20px; text-align: center; }
    .pm-upload-zone:hover { border-color: var(--coral); }
    .pm-upload-zone input[type=file] { position: absolute; inset: 0; opacity: 0; cursor: pointer; }
    .pm-upload-zone .u-icon { font-size: 20px; margin-bottom: 6px; }
    .pm-upload-zone .u-text { font-size: 13px; color: var(--ink-soft); }

    .pm-submit-row { margin-top: 22px; }
    .pm-submit-btn { width: 100%; padding: 14px; border-radius: 12px; border: none; background: var(--coral); color: #fff; font-weight: 700; font-size: 15px; }
    .pm-submit-btn:hover:not(:disabled) { background: var(--coral-dark); }
    .pm-submit-btn:disabled { opacity: .6; cursor: not-allowed; }

    /* ===== QR modal ===== */
    .qr-modal-overlay {
        display: none; position: fixed; inset: 0; background: rgba(15,20,32,.6); backdrop-filter: blur(4px);
        z-index: 3100; align-items: center; justify-content: center; padding: 20px;
    }
    .qr-modal-overlay.active { display: flex; }
    .qr-modal-card { background: #0F1420; border-radius: 20px; max-width: 360px; width: 100%; overflow: hidden; }
    .qr-modal-header { display: flex; align-items: flex-start; justify-content: space-between; padding: 20px 22px 4px; color: #fff; }
    .qr-modal-title { font-size: 16px; font-weight: 700; }
    .qr-modal-subtitle { font-size: 12px; color: #9aa3b5; margin-top: 3px; }
    .qr-modal-close { width: 30px; height: 30px; border-radius: 9px; border: none; background: rgba(255,255,255,.12); color: #fff; font-size: 14px; flex-shrink: 0; display: flex; align-items: center; justify-content: center; }
    .qr-modal-close:hover { background: rgba(255,255,255,.22); }
    .qr-modal-body { padding: 18px 22px 26px; text-align: center; }
    .qr-code-img { width: 100%; max-width: 220px; border-radius: 12px; background: #fff; padding: 8px; }
    .qr-upi-row { display: flex; align-items: center; justify-content: center; gap: 8px; margin-top: 16px; font-size: 13px; color: #cbd5e1; }
    .qr-upi-row b { color: #fff; user-select: all; }
    .qr-copy-icon {
        width: 26px; height: 26px; border-radius: 7px; border: none; background: rgba(255,255,255,.12);
        color: #fff; display: inline-flex; align-items: center; justify-content: center; flex-shrink: 0; font-size: 12px;
    }
    .qr-copy-icon:hover { background: rgba(255,255,255,.25); }
    .qr-copy-icon.copied { background: var(--green); }
    .qr-payee { font-size: 11.5px; color: #9aa3b5; margin-top: 4px; }
    .qr-modal-footer-text { font-size: 12px; color: #9aa3b5; margin-top: 18px; }
    .qr-download-link {
        display: inline-flex; align-items: center; gap: 7px; margin-top: 16px; background: var(--coral); color: #fff;
        text-decoration: none; padding: 9px 16px; border-radius: 9px; font-size: 12.5px; font-weight: 700;
    }
    .qr-download-link:hover { background: var(--coral-dark); }

    /* ===== Success modal ===== */
    .sp-modal-overlay {
        display: none; position: fixed; inset: 0; background: rgba(15,20,32,.6); backdrop-filter: blur(3px);
        z-index: 3200; align-items: center; justify-content: center; padding: 20px;
    }
    .sp-modal-overlay.active { display: flex; }
    .sp-modal { background: #fff; border-radius: 20px; max-width: 400px; width: 100%; padding: 32px 28px; text-align: center; }
    .sp-modal .ic { width: 64px; height: 64px; border-radius: 50%; background: var(--green-tint); color: var(--green); font-size: 30px; display: flex; align-items: center; justify-content: center; margin: 0 auto 18px; }
    .sp-modal h3 { font-size: 18px; margin-bottom: 10px; }
    .sp-modal p { font-size: 13.5px; color: var(--ink-soft); line-height: 1.6; margin-bottom: 22px; }
    .sp-modal button { width: 100%; padding: 12px; border-radius: 11px; border: none; background: var(--coral); color: #fff; font-weight: 700; }
    .sp-modal button:hover { background: var(--coral-dark); }

    /* ===== Mobile hardening ===== */
    @media (max-width: 640px) {
        .current-hero { padding: 20px; }
        .current-hero h2 { font-size: 19px; }
        .current-hero-stats { width: 100%; justify-content: space-between; gap: 14px; }
        .subscribe-overlay { padding: 20px 12px; }
        .popup-header { padding: 16px 18px; flex-wrap: wrap; gap: 10px; }
        .popup-header .brand { min-width: 0; }
        .popup-header .brand img { width: 34px; height: 34px; }
        .popup-header h2 { font-size: 16.5px; }
        .popup-header p { font-size: 12px; }
        .modal-plan-strip { padding: 14px 18px; flex-wrap: wrap; gap: 10px; }
        .popup-body { padding: 20px 18px 24px; }
        .qr-upi-row { flex-wrap: wrap; justify-content: center; text-align: center; }
        .qr-upi-row b { word-break: break-all; }
        .sp-modal { padding: 26px 20px; }
        .pm-section-title { flex-wrap: wrap; }
    }

    @media (max-width: 420px) {
        .plans-grid { grid-template-columns: 1fr; }
        .plan-card-head { padding: 16px 16px 8px; }
    }
</style>

<div class="wrap">
    <div class="page-head">
        <p>Your current plan, and everything else we offer.</p>
    </div>

    <div class="current-hero <?= $expiry_status === 'none' ? 'none' : ($expiry_status === 'expired' ? 'expired' : '') ?>">
        <div class="current-hero-row">
            <div>
                <?php if ($membership_pause): ?>
                    <div class="tag" style="background:#FDF3E2;color:#B87814;">
                        <svg viewBox="0 0 24 24" fill="currentColor"><rect x="6" y="4" width="4" height="16" rx="1"/><rect x="14" y="4" width="4" height="16" rx="1"/></svg>
                        Paused
                    </div>
                <?php elseif ($membership_extension && ($expiry_status === 'active' || $expiry_status === 'expiring')): ?>
                    <div class="tag" style="background:#fff;color:#0369A1;">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="17" rx="2"/><path d="M16 2v4M8 2v4M3 10h18M12 13v5M9.5 15.5h5"/></svg>
                        Extended
                    </div>
                <?php elseif ($expiry_status === 'active'): ?>
                    <div class="tag">
                        <svg viewBox="0 0 24 24" fill="currentColor"><circle cx="12" cy="12" r="6"/></svg>
                        Active membership
                    </div>
                <?php elseif ($expiry_status === 'expiring'): ?>
                    <div class="tag">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M12 9v4M12 17h.01"/><path d="M10.3 4L2 18a2 2 0 001.7 3h16.6A2 2 0 0022 18L13.7 4a2 2 0 00-3.4 0z"/></svg>
                        Expiring soon
                    </div>
                <?php elseif ($expiry_status === 'expired'): ?>
                    <div class="tag">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M15 9l-6 6M9 9l6 6"/></svg>
                        Expired
                    </div>
                <?php else: ?>
                    <div class="tag">No active plan</div>
                <?php endif; ?>
                <?php if ($pending_plan_request): ?>
                    <div class="tag pending-tag" title="<?= $pending_plan_request['plan_name'] !== '' ? e($pending_plan_request['plan_name']) : 'New plan requested' ?> — awaiting your trainer's verification">+ New Plan Pending</div>
                <?php endif; ?>
                <h2><?= $current_plan_name !== '' ? e($current_plan_name) : 'No membership yet' ?></h2>
                <div class="sub">
                    <?php if ($membership_pause): ?>
                        Paused until <?= e(date('d M Y', strtotime($membership_pause['pause_end']))) ?> — it will resume automatically after that.
                    <?php elseif ($membership_info['valid_until']): ?>
                        Valid until <?= e(date('d M Y', strtotime($membership_info['valid_until']))) ?>
                        <?php if ($membership_extension): ?>
                            — extended by <?= (int) $membership_extension['days'] ?> day<?= (int) $membership_extension['days'] === 1 ? '' : 's' ?>
                            on <?= e(date('d M Y', strtotime($membership_extension['created_at']))) ?>
                        <?php endif; ?>
                    <?php else: ?>
                        Talk to your trainer to get started on a plan.
                    <?php endif; ?>
                </div>
            </div>
            <?php if ($latest_payment): ?>
                <div class="current-hero-stats">
                    <?php if ($days_remaining !== null): ?>
                        <div>
                            <div class="num"><?= $days_remaining >= 0 ? $days_remaining : 0 ?></div>
                            <div class="lbl"><?= $expiry_status === 'expired' ? 'Days overdue' : 'Days remaining' ?></div>
                        </div>
                    <?php endif; ?>
                    <?php if ((float) ($latest_payment['balance_pending'] ?? 0) > 0): ?>
                        <div>
                            <div class="num">₹<?= number_format((float) $latest_payment['balance_pending']) ?></div>
                            <div class="lbl">Balance due</div>
                        </div>
                    <?php endif; ?>
                    <?php if (!empty($latest_payment['total_amount'])): ?>
                        <div>
                            <div class="num">₹<?= number_format((float) $latest_payment['total_amount']) ?></div>
                            <div class="lbl">Plan value</div>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="section-title">
        <span class="ic">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3 10h18"/>
            </svg>
        </span>
        <div>
            <h2>Explore Plans</h2>
            <p>Every membership plan we currently offer.</p>
        </div>
    </div>

    <?php if (empty($plans)): ?>
        <div class="empty-card">No membership plans have been published yet — check back soon.</div>
    <?php else: ?>
        <div class="plans-grid">
            <?php foreach ($plans as $p):
                $is_current = $current_plan_name !== '' && strcasecmp(trim($p['plan_name']), $current_plan_name) === 0;
                $features = membershipFeatures($p);
            ?>
                <div class="member-plan-card <?= $is_current ? 'is-current' : '' ?>">
                    <div class="plan-strip"></div>
                    <?php if ($is_current): ?><span class="current-badge">Your Plan</span><?php endif; ?>
                    <div class="plan-card-head">
                        <h3><?= e($p['plan_name']) ?></h3>
                        <div class="plan-price">₹<?= number_format((float) $p['price']) ?><span class="period"><?= e(membershipPeriodLabel($p)) ?></span></div>
                        <?php if (!empty($p['description'])): ?>
                            <div class="plan-desc"><?= nl2br(e($p['description'])) ?></div>
                        <?php endif; ?>
                    </div>
                    <div class="plan-stats-row">
                        <span><b><?= (int) $p['duration_value'] ?></b> <?= e($p['duration_unit']) ?></span>
                        <?php if (!empty($p['max_classes'])): ?>
                            <span><b><?= e($p['max_classes']) ?></b> classes</span>
                        <?php endif; ?>
                    </div>
                    <?php if ($features): ?>
                        <ul class="plan-features">
                            <?php foreach ($features as $f): ?>
                                <li><?= e($f) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                    <?php if (!$is_current): ?>
                        <div class="plan-subscribe">
                            <button type="button" class="subscribe-btn" data-plan-id="<?= (int) $p['id'] ?>" onclick="openSubscribeModal(this.dataset.planId)">Subscribe</button>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<!-- Subscribe / Payment modal (blurred backdrop, in-page popup) -->
<div class="subscribe-overlay" id="subscribeOverlay">
    <div class="popup-shell">
        <div class="popup-header">
            <div class="brand">
                <img src="../../icons/logo-dark(1).png" alt="JOF">
                <div>
                    <h2>Payment Details</h2>
                    <p>Provide payment info to subscribe to this plan.</p>
                </div>
            </div>
            <button type="button" class="popup-close" id="subscribeCloseBtn" title="Close">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M6 6l12 12M18 6L6 18"/></svg>
            </button>
        </div>

        <div class="modal-plan-strip">
            <div>
                <div class="name" id="modalPlanName">—</div>
                <div class="sub" id="modalPlanDuration">—</div>
            </div>
            <div class="amount">₹<span id="modalPlanPrice">0</span><span class="period" id="modalPlanPeriod"></span></div>
        </div>

        <div class="popup-body">
            <div class="form-alert" id="subscribeError"></div>

            <div class="pm-section-title">
                <h3>
                    <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20"/></svg>
                    Payment Mode Details
                </h3>
                <button type="button" class="scan-pay-btn" id="scanPayBtn">
                    <span class="badge">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><rect x="5" y="2" width="14" height="20" rx="2"/><path d="M11 18h2"/></svg>
                    </span> Scan &amp; Pay
                </button>
            </div>

            <form id="subscribeForm">
                <input type="hidden" name="plan_id" id="modalPlanId" value="">
                <div class="pm-form-grid">
                    <div class="pm-fld">
                        <label>Payment Mode *</label>
                        <select name="payment_mode" required>
                            <option value="upi" selected>UPI (GPay/PhonePe/Paytm)</option>
                        </select>
                    </div>
                    <div class="pm-fld">
                        <label>Transaction ID / UPI Ref *</label>
                        <input type="text" name="transaction_id" maxlength="20" placeholder="Last 4-6 digits" required>
                    </div>
                    <div class="pm-fld">
                        <label>Paid By <span class="opt">(if different)</span></label>
                        <input type="text" name="payer_name" maxlength="100" placeholder="e.g. Father/Friend Name">
                    </div>
                    <div class="pm-fld">
                        <label>Pay In *</label>
                        <select name="installments" required>
                            <option value="1" selected>1 Installment (Full Payment)</option>
                            <option value="2">2 Installments</option>
                            <option value="3">3 Installments</option>
                        </select>
                    </div>
                    <div class="pm-fld full">
                        <label>Upload Payment Screenshot <span class="opt">(optional)</span></label>
                        <div class="pm-upload-zone">
                            <input type="file" name="payment_screenshot" id="screenshotInput" accept="image/jpeg,image/png,application/pdf">
                            <div class="u-icon">
                                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M7 18a4.5 4.5 0 01-1.5-8.74A5.5 5.5 0 0116 8a4 4 0 01.5 7.97"/><path d="M12 12v9M9 15l3-3 3 3"/></svg>
                            </div>
                            <div class="u-text" id="screenshotLabel">Click to upload screenshot (Optional)</div>
                        </div>
                    </div>
                </div>

                <div class="pm-submit-row">
                    <button type="submit" class="pm-submit-btn" id="subscribeSubmitBtn">Finish Subscription →</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- QR modal -->
<div class="qr-modal-overlay" id="qrPayModal">
    <div class="qr-modal-card">
        <div class="qr-modal-header">
            <div>
                <div class="qr-modal-title">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-3px;margin-right:6px;"><rect x="5" y="2" width="14" height="20" rx="2"/><path d="M11 18h2"/></svg>
                    Scan &amp; Pay
                </div>
                <div class="qr-modal-subtitle">Use any UPI app to complete payment</div>
            </div>
            <button type="button" class="qr-modal-close" id="qrCloseBtn">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="M6 6l12 12M18 6L6 18"/></svg>
            </button>
        </div>
        <div class="qr-modal-body">
            <img src="../../icons/images/qr_code.jpeg" alt="Payment QR Code" class="qr-code-img">
            <div class="qr-upi-row">
                UPI ID: <b id="upiId">7798487212@hdfc</b>
                <button type="button" class="qr-copy-icon" id="copyUpiBtn" title="Copy UPI ID">
                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><rect x="8" y="8" width="13" height="13" rx="2"/><path d="M4 16V4a2 2 0 012-2h10"/></svg>
                </button>
            </div>
            <div class="qr-payee">JOSHUA SUNIL SADANANDAN</div>
            <a class="qr-download-link" href="../../icons/images/qr_code.jpeg" download="JOF-Payment-QR.jpg">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3v12M7 10l5 5 5-5M5 21h14"/></svg>
                Download QR Code
            </a>
            <div class="qr-modal-footer-text">GPay &nbsp;·&nbsp; PhonePe &nbsp;·&nbsp; Paytm &nbsp;·&nbsp; any UPI app</div>
        </div>
    </div>
</div>

<!-- Success modal -->
<div class="sp-modal-overlay" id="successOverlay">
    <div class="sp-modal">
        <div class="ic">
            <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6L9 17l-5-5"/></svg>
        </div>
        <h3>Payment Submitted!</h3>
        <p>Please wait till your trainer verifies and activates your membership. Once approved, you're all set to begin your journey!</p>
        <button type="button" id="successOkBtn">Got it</button>
    </div>
</div>

<script>
    const MEMBERSHIP_PLANS = <?= json_encode($plans_js) ?>;

    function findMembershipPlan(id) {
        return MEMBERSHIP_PLANS.find(p => p.id === Number(id));
    }

    function openSubscribeModal(planId) {
        const plan = findMembershipPlan(planId);
        if (!plan) return;

        document.getElementById('modalPlanId').value = plan.id;
        document.getElementById('modalPlanName').textContent = plan.plan_name;
        document.getElementById('modalPlanDuration').textContent = plan.duration;
        document.getElementById('modalPlanPrice').textContent = plan.price;
        document.getElementById('modalPlanPeriod').textContent = plan.period;

        const errorBox = document.getElementById('subscribeError');
        errorBox.classList.remove('show');
        errorBox.textContent = '';
        document.getElementById('subscribeForm').reset();
        document.getElementById('screenshotLabel').textContent = 'Click to upload screenshot (Optional)';

        document.getElementById('subscribeOverlay').classList.add('active');
    }

    function closeSubscribeModal() {
        document.getElementById('subscribeOverlay').classList.remove('active');
    }

    document.addEventListener('DOMContentLoaded', function () {
        const overlay = document.getElementById('subscribeOverlay');
        const closeBtn = document.getElementById('subscribeCloseBtn');
        closeBtn.addEventListener('click', closeSubscribeModal);
        overlay.addEventListener('click', function (e) { if (e.target === overlay) closeSubscribeModal(); });

        const qrModal = document.getElementById('qrPayModal');
        const scanPayBtn = document.getElementById('scanPayBtn');
        const qrCloseBtn = document.getElementById('qrCloseBtn');
        scanPayBtn.addEventListener('click', () => qrModal.classList.add('active'));
        qrCloseBtn.addEventListener('click', () => qrModal.classList.remove('active'));
        qrModal.addEventListener('click', function (e) { if (e.target === qrModal) qrModal.classList.remove('active'); });

        const copyBtn = document.getElementById('copyUpiBtn');
        const upiId = document.getElementById('upiId').textContent.trim();
        const copyIconHTML = copyBtn.innerHTML;
        const checkIconHTML = '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6L9 17l-5-5"/></svg>';
        copyBtn.addEventListener('click', function () {
            navigator.clipboard.writeText(upiId).then(function () {
                copyBtn.innerHTML = checkIconHTML;
                copyBtn.classList.add('copied');
                setTimeout(function () {
                    copyBtn.innerHTML = copyIconHTML;
                    copyBtn.classList.remove('copied');
                }, 1800);
            }).catch(function () { });
        });

        const screenshotInput = document.getElementById('screenshotInput');
        const screenshotLabel = document.getElementById('screenshotLabel');
        screenshotInput.addEventListener('change', function () {
            screenshotLabel.textContent = screenshotInput.files[0] ? screenshotInput.files[0].name : 'Click to upload screenshot (Optional)';
        });

        const successOverlay = document.getElementById('successOverlay');
        document.getElementById('successOkBtn').addEventListener('click', function () {
            successOverlay.classList.remove('active');
            window.location.reload();
        });

        const form = document.getElementById('subscribeForm');
        const submitBtn = document.getElementById('subscribeSubmitBtn');
        const errorBox = document.getElementById('subscribeError');

        form.addEventListener('submit', function (e) {
            e.preventDefault();
            errorBox.classList.remove('show');
            submitBtn.disabled = true;
            submitBtn.textContent = 'Submitting…';

            fetch('../../handlers/subscribe_payment.php', {
                method: 'POST',
                body: new FormData(form)
            })
                .then(res => res.json())
                .then(data => {
                    if (data.success) {
                        closeSubscribeModal();
                        successOverlay.classList.add('active');
                    } else {
                        errorBox.textContent = data.error || 'Something went wrong. Please try again.';
                        errorBox.classList.add('show');
                    }
                })
                .catch(() => {
                    errorBox.textContent = 'Network error. Please try again.';
                    errorBox.classList.add('show');
                })
                .finally(() => {
                    submitBtn.disabled = false;
                    submitBtn.textContent = 'Finish Subscription →';
                });
        });
    });
</script>

<?php require __DIR__ . '/_shell_bottom.php'; ?>
