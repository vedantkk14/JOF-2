<?php
require_once __DIR__ . '/../auth/auth_check.php';
require_role(['admin', 'trainer']);

// Database connection
require_once __DIR__ . '/../config.php';

// Staff who may create new login accounts (admin + trainer share this tier app-wide)
$current_user     = get_session_user();
$can_manage_staff = in_array($current_user['role'] ?? '', ['admin', 'trainer'], true);
$csrf_token       = generate_csrf_token();

// Fetch Total Members
$total_members = 0;
$total_members_result = mysqli_query($conn, "SELECT COUNT(*) as total FROM members WHERE status = 'active'");
if ($total_members_result) {
    $total_members = mysqli_fetch_assoc($total_members_result)['total'];
}

// Calculate month-over-month member growth
$member_growth_pct = 0;
$member_growth_direction = 'neutral';
$this_month_members = 0;
$last_month_members = 0;
$res = mysqli_query($conn, "SELECT COUNT(*) as total FROM members WHERE status = 'active' AND MONTH(created_at) = MONTH(CURDATE()) AND YEAR(created_at) = YEAR(CURDATE())");
if ($res)
    $this_month_members = mysqli_fetch_assoc($res)['total'];
$res = mysqli_query($conn, "SELECT COUNT(*) as total FROM members WHERE status = 'active' AND MONTH(created_at) = MONTH(CURDATE() - INTERVAL 1 MONTH) AND YEAR(created_at) = YEAR(CURDATE() - INTERVAL 1 MONTH)");
if ($res)
    $last_month_members = mysqli_fetch_assoc($res)['total'];
if ($last_month_members > 0) {
    $member_growth_pct = round((($this_month_members - $last_month_members) / $last_month_members) * 100);
} elseif ($this_month_members > 0) {
    $member_growth_pct = 100;
}
$member_growth_direction = $member_growth_pct > 0 ? 'positive' : ($member_growth_pct < 0 ? 'negative' : 'neutral');


// Fetch Active Plans (using member_payments table)
$active_plans = 0;
// Count active memberships where the end_date is in the future
$active_plans_result = mysqli_query($conn, "SELECT COUNT(*) as total FROM member_payments WHERE end_date >= CURDATE()");

if ($active_plans_result) {
    $active_plans = mysqli_fetch_assoc($active_plans_result)['total'];
}

// Fetch Membership Plans
$membership_plans = [];
$plan_count = 0;
$plans_result = mysqli_query($conn, "SELECT id, plan_name, duration_unit, duration_value, price FROM membership_plans ORDER BY price ASC");
if ($plans_result) {
    while ($row = mysqli_fetch_assoc($plans_result)) {
        $membership_plans[] = $row;
        $plan_count++;
    }
} else {
    // Store error for debugging
    $plans_error = mysqli_error($conn);
}


// Fetch Total Revenue (sum of all payments received + addon services + consultations)
$total_revenue = 0;
$revenue_query = "
    SELECT SUM(total) as total FROM (
        SELECT COALESCE(SUM(amount_received), 0) as total FROM member_payments
        UNION ALL
        SELECT COALESCE(SUM(price), 0) as total FROM addon_services_bookings WHERE status != 'cancelled'
        UNION ALL
        SELECT COALESCE(SUM(total_amount), 0) as total FROM consultations
    ) as combined_revenue
";
$revenue_result = mysqli_query($conn, $revenue_query);

if ($revenue_result) {
    $total_revenue = mysqli_fetch_assoc($revenue_result)['total'] ?? 0;
}

// Calculate month-over-month revenue growth
$revenue_growth_pct = 0;
$revenue_growth_direction = 'neutral';
$rev_this = 0;
$rev_last = 0;

$rev_this_query = "
    SELECT SUM(total) as total FROM (
        SELECT COALESCE(SUM(amount_received), 0) as total FROM member_payments WHERE MONTH(created_at) = MONTH(CURDATE()) AND YEAR(created_at) = YEAR(CURDATE())
        UNION ALL
        SELECT COALESCE(SUM(price), 0) as total FROM addon_services_bookings WHERE status != 'cancelled' AND MONTH(created_at) = MONTH(CURDATE()) AND YEAR(created_at) = YEAR(CURDATE())
        UNION ALL
        SELECT COALESCE(SUM(total_amount), 0) as total FROM consultations WHERE MONTH(created_at) = MONTH(CURDATE()) AND YEAR(created_at) = YEAR(CURDATE())
    ) as combined_revenue
";
$res = mysqli_query($conn, $rev_this_query);
if ($res)
    $rev_this = mysqli_fetch_assoc($res)['total'] ?? 0;

$rev_last_query = "
    SELECT SUM(total) as total FROM (
        SELECT COALESCE(SUM(amount_received), 0) as total FROM member_payments WHERE MONTH(created_at) = MONTH(CURDATE() - INTERVAL 1 MONTH) AND YEAR(created_at) = YEAR(CURDATE() - INTERVAL 1 MONTH)
        UNION ALL
        SELECT COALESCE(SUM(price), 0) as total FROM addon_services_bookings WHERE status != 'cancelled' AND MONTH(created_at) = MONTH(CURDATE() - INTERVAL 1 MONTH) AND YEAR(created_at) = YEAR(CURDATE() - INTERVAL 1 MONTH)
        UNION ALL
        SELECT COALESCE(SUM(total_amount), 0) as total FROM consultations WHERE MONTH(created_at) = MONTH(CURDATE() - INTERVAL 1 MONTH) AND YEAR(created_at) = YEAR(CURDATE() - INTERVAL 1 MONTH)
    ) as combined_revenue
";
$res = mysqli_query($conn, $rev_last_query);
if ($res)
    $rev_last = mysqli_fetch_assoc($res)['total'] ?? 0;
if ($rev_last > 0) {
    $revenue_growth_pct = round((($rev_this - $rev_last) / $rev_last) * 100);
} elseif ($rev_this > 0) {
    $revenue_growth_pct = 100;
}
$revenue_growth_direction = $revenue_growth_pct > 0 ? 'positive' : ($revenue_growth_pct < 0 ? 'negative' : 'neutral');

// Fetch Recent Revenue History (Top 10)
$recent_revenue_history = [];
$recent_revenue_query = "
    SELECT * FROM (
        SELECT 
            'Membership' COLLATE utf8mb4_unicode_ci as source_type,
            m.full_name COLLATE utf8mb4_unicode_ci as name,
            mp.membership_type COLLATE utf8mb4_unicode_ci as description,
            mp.amount_received as amount,
            mp.created_at as tx_date
        FROM member_payments mp
        JOIN members m ON mp.member_id = m.id
        
        UNION ALL
        
        SELECT 
            'Add-on Service' COLLATE utf8mb4_unicode_ci as source_type,
            member_name COLLATE utf8mb4_unicode_ci as name,
            service_type COLLATE utf8mb4_unicode_ci as description,
            price as amount,
            created_at as tx_date
        FROM addon_services_bookings 
        WHERE status != 'cancelled'
    ) as combined_history
    ORDER BY tx_date DESC
    LIMIT 10
";
$recent_revenue_result = mysqli_query($conn, $recent_revenue_query);
if ($recent_revenue_result) {
    while ($row = mysqli_fetch_assoc($recent_revenue_result)) {
        $recent_revenue_history[] = $row;
    }
}

$pt_sessions_grouped = [];
$pt_sessions_query = "
    SELECT
        ps.id,
        ps.session_date,
        ps.session_time,
        ps.status,
        m.full_name  AS member_name,
        COALESCE(t.full_name, 'Unassigned') AS trainer_name
    FROM pt_sessions ps
    JOIN members m  ON ps.member_id = m.id
    JOIN personal_training pt ON ps.pt_id = pt.id
    LEFT JOIN trainers t ON pt.trainer_id = t.id
    WHERE ps.session_date IN (CURDATE(), CURDATE() - INTERVAL 1 DAY, CURDATE() + INTERVAL 1 DAY)
      AND ps.status != 'Cancelled'
    ORDER BY ps.session_date DESC, ps.session_time ASC
";
$pt_result = mysqli_query($conn, $pt_sessions_query);
if ($pt_result) {
    while ($r = mysqli_fetch_assoc($pt_result)) {
        $pt_sessions_grouped[$r['session_date']][] = $r;
    }
}
$today_sessions_count = isset($pt_sessions_grouped[date('Y-m-d')]) ? count($pt_sessions_grouped[date('Y-m-d')]) : 0;
?>


<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" size="16x16" href="../icons/favicon-dark-logo.png" type="image/png">
    <title>JOF INDIA | Premium Dashboard</title>
    <link rel="stylesheet" href="../static/root.css">
    <style>
        img[class*="fa-"],
        svg.replaced-svg {
            width: 1em;
            height: 1em;
            vertical-align: -0.125em;
        }

        /* Dashboard specific color overrides for inline SVGs */
        svg.fa-envelope {
            color: #4C6EF5 !important;
        }

        .replaced-svg {
            display: inline-block;
        }

        .replaced-svg path {
            fill: currentColor;
        }

        .pt-notif-btn img,
        .notification-btn img,
        .enquiry-notif-btn img {
            width: 22px;
            height: 22px;
            object-fit: contain;
            display: block;
        }

        .notification-btn img {
            width: 25px;
            height: 25px;
        }

        /* Enquiry Notification Button */
        .enquiry-notif-container {
            position: relative;
        }

        .enquiry-notif-btn {
            position: relative;
            width: 42px;
            height: 42px;
            border-radius: 12px;
            border: none;
            background: #fff;
            color: #10b981;
            font-size: 16px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.07);
            transition: background 0.2s, transform 0.2s;
        }

        .enquiry-notif-btn:hover {
            background: #ECFDF5;
            transform: translateY(-1px);
        }

        .enquiry-notif-badge {
            position: absolute;
            top: -5px;
            right: -5px;
            background: #10b981;
            color: #fff;
            font-size: 9px;
            font-weight: 700;
            min-width: 18px;
            height: 18px;
            padding: 0 4px;
            border-radius: 9px;
            display: flex;
            align-items: center;
            justify-content: center;
            border: 2px solid #fff;
            box-shadow: 0 2px 8px rgba(16, 185, 129, 0.35);
            transition: transform 0.3s ease;
        }

        .enquiry-notif-badge.hidden {
            display: none;
        }

        .enquiry-notif-badge.badge-pop {
            animation: badgeBounce 0.7s ease;
        }

        /* Enquiry Dropdown */
        .enquiry-dropdown {
            position: absolute;
            right: 0;
            top: calc(100% + 10px);
            width: 370px;
            background: #fff;
            border-radius: 14px;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.14);
            z-index: 9999;
            display: none;
            overflow: hidden;
            border: 1px solid #e2e8f0;
        }

        .enquiry-dropdown.active {
            display: block;
            animation: slideDown 0.2s ease;
        }

        .enquiry-dropdown .enq-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 14px 18px;
            border-bottom: 1px solid #f1f5f9;
            background: #f0fdf4;
        }

        .enquiry-dropdown .enq-header h4 {
            margin: 0;
            font-size: 0.95rem;
            font-weight: 700;
            color: #1e293b;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .enquiry-dropdown .enq-header h4 img {
            width: 16px;
            height: 16px;
        }

        .enquiry-dropdown .close-enq-btn {
            background: none;
            border: none;
            cursor: pointer;
            color: #94a3b8;
            font-size: 1rem;
            padding: 4px;
            border-radius: 6px;
            transition: color 0.2s;
        }

        .enquiry-dropdown .close-enq-btn:hover {
            color: #ef4444;
        }

        .enquiry-dropdown .enq-list {
            max-height: 380px;
            overflow-y: auto;
        }

        .enquiry-dropdown .enq-item {
            display: flex;
            gap: 12px;
            align-items: flex-start;
            padding: 13px 16px;
            border-bottom: 1px solid #f8fafc;
            transition: background 0.15s;
            cursor: pointer;
            text-decoration: none;
            color: inherit;
        }

        .enquiry-dropdown .enq-item:hover {
            background: #f0fdf4;
        }

        .enquiry-dropdown .enq-item.unseen {
            border-left: 3px solid #10b981;
            background: #f0fdf4;
        }

        .enquiry-dropdown .enq-item.unseen:hover {
            background: #dcfce7;
        }

        .enquiry-dropdown .enq-avatar {
            width: 38px;
            height: 38px;
            border-radius: 50%;
            background: linear-gradient(135deg, #10b981, #34d399);
            display: flex;
            align-items: center;
            justify-content: center;
            color: #fff;
            font-weight: 700;
            font-size: 0.85rem;
            flex-shrink: 0;
        }

        .enquiry-dropdown .enq-body {
            flex: 1;
            min-width: 0;
        }

        .enquiry-dropdown .enq-name {
            font-weight: 600;
            font-size: 0.82rem;
            color: #1e293b;
            margin-bottom: 2px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .enquiry-dropdown .enq-contact {
            font-size: 0.78rem;
            color: #64748b;
            margin-bottom: 2px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .enquiry-dropdown .enq-phone {
            font-size: 0.75rem;
            color: #94a3b8;
            display: flex;
            align-items: center;
            gap: 4px;
        }

        .enquiry-dropdown .enq-phone img {
            width: 11px;
            height: 11px;
            opacity: 0.5;
        }

        .enquiry-dropdown .enq-time {
            font-size: 0.7rem;
            color: #94a3b8;
            margin-top: 4px;
            display: flex;
            align-items: center;
            gap: 3px;
        }

        .enquiry-dropdown .enq-time img {
            width: 11px;
            height: 11px;
            opacity: 0.5;
        }

        .enquiry-dropdown .enq-footer {
            padding: 10px 18px;
            border-top: 1px solid #f1f5f9;
            text-align: center;
            background: #f9fafb;
        }

        .enquiry-dropdown .enq-footer a {
            font-size: 0.8rem;
            color: #10b981;
            text-decoration: none;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }

        .enquiry-dropdown .enq-footer a:hover {
            text-decoration: underline;
        }

        .enquiry-dropdown .enq-footer a img {
            width: 13px;
            height: 13px;
        }

        .enquiry-dropdown .enq-empty {
            text-align: center;
            padding: 40px 20px;
            color: #94a3b8;
        }

        .enquiry-dropdown .enq-empty img {
            width: 40px;
            height: 40px;
            opacity: 0.3;
            margin-bottom: 10px;
        }

        /* Mobile responsive for notification dropdowns */
        @media (max-width: 600px) {
            .page-dashboard .header-actions {
                position: relative;
            }

            .enquiry-notif-container,
            .notification-container {
                position: static !important;
            }

            .enquiry-dropdown,
            .notification-dropdown {
                position: absolute !important;
                top: calc(100% + 10px) !important;
                left: 0 !important;
                right: 0 !important;
                width: auto !important;
                transform: none !important;
                max-width: none;
                border-radius: 14px;
                z-index: 10000 !important;
            }
        }

        /* ── Delete Event Confirmation Modal ── */
        .delete-confirm-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(15, 23, 42, 0.55);
            backdrop-filter: blur(4px);
            -webkit-backdrop-filter: blur(4px);
            z-index: 9999;
            justify-content: center;
            align-items: center;
            animation: fadeIn 0.2s ease;
        }

        .delete-confirm-overlay.active {
            display: flex;
        }

        .delete-confirm-card {
            background: #fff;
            border-radius: 18px;
            width: 90%;
            max-width: 400px;
            padding: 32px 28px 24px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.18), 0 4px 16px rgba(0, 0, 0, 0.08);
            text-align: center;
            transform: scale(0.9) translateY(20px);
            opacity: 0;
            transition: transform 0.25s cubic-bezier(0.34, 1.56, 0.64, 1), opacity 0.2s ease;
        }

        .delete-confirm-overlay.active .delete-confirm-card {
            transform: scale(1) translateY(0);
            opacity: 1;
        }

        .delete-confirm-icon {
            width: 56px;
            height: 56px;
            border-radius: 50%;
            background: linear-gradient(135deg, #FEE2E2, #FECACA);
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 18px;
            box-shadow: 0 4px 14px rgba(239, 68, 68, 0.15);
        }

        .delete-confirm-icon svg {
            width: 24px;
            height: 24px;
            color: #DC2626;
            fill: #DC2626;
        }

        .delete-confirm-card h3 {
            font-size: 1.15rem;
            font-weight: 700;
            color: #1E293B;
            margin: 0 0 8px;
        }

        .delete-confirm-card p {
            font-size: 0.88rem;
            color: #64748B;
            margin: 0 0 6px;
            line-height: 1.5;
        }

        .delete-confirm-event-name {
            font-weight: 600;
            color: #334155;
            font-size: 0.92rem;
            background: #F8FAFC;
            border: 1px solid #E2E8F0;
            border-radius: 8px;
            padding: 8px 14px;
            margin: 12px 0 20px;
            display: inline-block;
            max-width: 100%;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .delete-confirm-actions {
            display: flex;
            gap: 10px;
            justify-content: center;
        }

        .delete-confirm-actions button {
            padding: 10px 24px;
            border-radius: 10px;
            font-size: 0.88rem;
            font-weight: 600;
            cursor: pointer;
            border: none;
            transition: all 0.2s ease;
        }

        .btn-cancel-delete {
            background: #F1F5F9;
            color: #475569;
        }

        .btn-cancel-delete:hover {
            background: #E2E8F0;
            color: #334155;
        }

        .btn-confirm-delete {
            background: linear-gradient(135deg, #EF4444, #DC2626);
            color: #fff;
            box-shadow: 0 4px 12px rgba(220, 38, 38, 0.25);
        }

        .btn-confirm-delete:hover {
            background: linear-gradient(135deg, #DC2626, #B91C1C);
            box-shadow: 0 6px 18px rgba(220, 38, 38, 0.35);
            transform: translateY(-1px);
        }

        .btn-confirm-delete:active {
            transform: translateY(0);
        }

        /* ══ Add Admin / Add Counsellor top-bar buttons ══ */
        .page-dashboard .staff-quick-btn {
            display: inline-flex;
            align-items: center;
            gap: 9px;
            padding: 8px 15px 8px 8px;
            border-radius: 12px;
            background: #fff;
            border: 1px solid #EEF0F3;
            cursor: pointer;
            font-size: 13px;
            font-weight: 600;
            color: #334155;
            box-shadow: 0 2px 8px rgba(15, 23, 42, 0.06);
            transition: transform .18s ease, box-shadow .18s ease, background .18s ease;
            flex-shrink: 0;
            white-space: nowrap;
        }

        .page-dashboard .staff-quick-btn .sqb-icon {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 28px;
            height: 28px;
            border-radius: 9px;
            background: #FFF4EF;
            color: #F25C2A;
            flex-shrink: 0;
        }

        .page-dashboard .staff-quick-btn .sqb-icon img,
        .page-dashboard .staff-quick-btn .sqb-icon svg {
            width: 15px;
            height: 15px;
        }

        .page-dashboard .staff-quick-btn:hover {
            transform: translateY(-1px);
            box-shadow: 0 6px 16px rgba(15, 23, 42, 0.1);
        }

        .page-dashboard .staff-quick-btn.primary {
            background: linear-gradient(135deg, #F25C2A, #E5502B);
            border-color: transparent;
            color: #fff;
            box-shadow: 0 4px 12px rgba(242, 92, 42, 0.28);
        }

        .page-dashboard .staff-quick-btn.primary .sqb-icon {
            background: rgba(255, 255, 255, 0.22);
            color: #fff;
        }

        .page-dashboard .staff-quick-btn.primary:hover {
            box-shadow: 0 8px 20px rgba(242, 92, 42, 0.38);
        }

        @media (max-width: 640px) {
            .page-dashboard .staff-quick-btn span {
                display: none;
            }

            .page-dashboard .staff-quick-btn {
                padding: 8px;
                gap: 0;
            }
        }

        /* ══ Create-account modal cards ══ */
        .page-dashboard .staff-modal {
            background: rgba(15, 23, 42, 0.55);
            backdrop-filter: blur(4px);
            -webkit-backdrop-filter: blur(4px);
            padding: 20px;
        }

        .page-dashboard .staff-modal.active {
            animation: staffFade .2s ease;
        }

        @keyframes staffFade {
            from {
                opacity: 0;
            }

            to {
                opacity: 1;
            }
        }

        .page-dashboard .staff-modal-card {
            position: relative;
            background: #fff;
            width: 100%;
            max-width: 430px;
            border-radius: 20px;
            padding: 28px 26px 24px;
            box-shadow: 0 24px 60px rgba(15, 23, 42, 0.24), 0 4px 14px rgba(15, 23, 42, 0.08);
            animation: staffPop .28s cubic-bezier(.34, 1.56, .64, 1);
            max-height: calc(100vh - 40px);
            overflow-y: auto;
        }

        @keyframes staffPop {
            from {
                opacity: 0;
                transform: translateY(16px) scale(.96);
            }

            to {
                opacity: 1;
                transform: translateY(0) scale(1);
            }
        }

        .page-dashboard .staff-modal-close {
            position: absolute;
            top: 16px;
            right: 16px;
            width: 32px;
            height: 32px;
            border: none;
            border-radius: 9px;
            background: #F1F5F9;
            color: #64748B;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: background .18s ease, color .18s ease;
        }

        .page-dashboard .staff-modal-close:hover {
            background: #FEE2E2;
            color: #DC2626;
        }

        .page-dashboard .staff-modal-close img,
        .page-dashboard .staff-modal-close svg {
            width: 13px;
            height: 13px;
        }

        .page-dashboard .staff-modal-head {
            display: flex;
            align-items: center;
            gap: 14px;
            margin-bottom: 20px;
            padding-right: 34px;
        }

        .page-dashboard .staff-modal-icon {
            width: 46px;
            height: 46px;
            border-radius: 13px;
            flex-shrink: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, #FFF4EF, #FFE4D6);
            color: #F25C2A;
        }

        .page-dashboard .staff-modal-icon img,
        .page-dashboard .staff-modal-icon svg {
            width: 20px;
            height: 20px;
        }

        .page-dashboard .staff-modal-head h3 {
            margin: 0 0 3px;
            font-size: 1.05rem;
            font-weight: 700;
            color: #1E293B;
        }

        .page-dashboard .staff-modal-head p {
            margin: 0;
            font-size: 0.8rem;
            color: #64748B;
            line-height: 1.4;
        }

        .page-dashboard .staff-field {
            margin-bottom: 14px;
        }

        .page-dashboard .staff-field label {
            display: block;
            margin-bottom: 6px;
            font-size: 0.72rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .04em;
            color: #94A3B8;
        }

        .page-dashboard .staff-field input,
        .page-dashboard .staff-field select {
            width: 100%;
            padding: 11px 13px;
            border: 1.5px solid #E2E8F0;
            border-radius: 10px;
            font-size: 0.92rem;
            font-family: inherit;
            color: #1E293B;
            background: #fff;
            outline: none;
            transition: border-color .15s ease, box-shadow .15s ease;
        }

        .page-dashboard .staff-field input::placeholder {
            color: #CBD5E1;
        }

        .page-dashboard .staff-field input:focus,
        .page-dashboard .staff-field select:focus {
            border-color: #F25C2A;
            box-shadow: 0 0 0 3px rgba(242, 92, 42, 0.12);
        }

        .page-dashboard .staff-modal-actions {
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            margin-top: 22px;
        }

        .page-dashboard .staff-btn-ghost,
        .page-dashboard .staff-btn-primary {
            padding: 10px 18px;
            border-radius: 10px;
            font-size: 0.88rem;
            font-weight: 600;
            cursor: pointer;
            border: none;
            transition: background .18s ease, box-shadow .18s ease, transform .18s ease;
        }

        .page-dashboard .staff-btn-ghost {
            background: #F1F5F9;
            color: #475569;
        }

        .page-dashboard .staff-btn-ghost:hover {
            background: #E2E8F0;
            color: #334155;
        }

        .page-dashboard .staff-btn-primary {
            background: linear-gradient(135deg, #F25C2A, #E5502B);
            color: #fff;
            box-shadow: 0 4px 12px rgba(242, 92, 42, 0.28);
        }

        .page-dashboard .staff-btn-primary:hover {
            box-shadow: 0 7px 18px rgba(242, 92, 42, 0.38);
            transform: translateY(-1px);
        }

        .page-dashboard .staff-btn-primary:disabled {
            opacity: .65;
            cursor: not-allowed;
            transform: none;
        }

        .page-dashboard .staff-form-msg {
            padding: 10px 13px;
            border-radius: 10px;
            font-size: 0.83rem;
            font-weight: 600;
            margin-bottom: 16px;
        }
    </style>
</head>

<body class="page-dashboard">
    <button class="mobile-toggle" id="mobileToggle">
        <img src="../icons/bars-solid-full.svg" class="fa-solid fa-bars">
    </button>

    <button class="toggle-sidebar-btn" id="toggleBtn">
        <img src="../icons/chevron-left-solid-full.svg" class="fa-solid fa-chevron-left">
    </button>

    <div class="dashboard-container">

        <?php include 'sidebar.php'; ?>

        <main class="main-content">
            <header class="header-banner">
                <div class="header-text">
                    <h1>Joshuaa's Outdoor Fitness💪🏋️</h1>
                    <p>Here's what's happening at <b>JOF INDIA</b> today.</p>
                </div>
                <div class="header-actions">
                    <?php if ($can_manage_staff): ?>
                        <!-- Create staff login accounts -->
                        <button type="button" class="staff-quick-btn primary" id="openAdminModalBtn"
                            title="Create an admin or trainer login">
                            <span class="sqb-icon"><img src="../icons/user-tie-solid-full.svg"
                                    class="fa-solid fa-user-tie"></span>
                            <span>Add Admin</span>
                        </button>
                        <button type="button" class="staff-quick-btn" id="openCounsellorModalBtn"
                            title="Create a counsellor login">
                            <span class="sqb-icon"><img src="../icons/clipboard-user-solid-full.svg"
                                    class="fa-solid fa-clipboard-user"></span>
                            <span>Add Counsellor</span>
                        </button>
                    <?php endif; ?>
                    <!-- PT Sessions Notification Button -->
                    <div class="pt-notif-container" id="ptNotifContainer">
                        <button class="pt-notif-btn" id="ptNotifBtn" title="Today's PT Sessions">
                            <img src="../icons/bell.png" alt="Reports" width="22">
                            <?php if ($today_sessions_count > 0): ?>
                                <span class="pt-notif-badge"><?= $today_sessions_count ?></span>
                            <?php endif; ?>
                        </button>
                    </div>
                    <!-- Enquiry Notification Button -->
                    <div class="enquiry-notif-container" id="enquiryNotifContainer">
                        <button class="enquiry-notif-btn" id="enquiryNotifBtn" title="New Enquiries">
                            <img src="../icons/customer-survey-colored.png" alt="Enquiries" width="22">
                            <span class="enquiry-notif-badge hidden" id="enquiryNotifBadge">0</span>
                        </button>
                        <div class="enquiry-dropdown" id="enquiryDropdown">
                            <div class="enq-header">
                                <h4><img src="../icons/comments-solid-full.svg" class="fa-solid fa-comments"
                                        style="color:#10b981;"> New Enquiries</h4>
                                <button class="close-enq-btn" id="closeEnqBtn">
                                    <img src="../icons/xmark-solid-full.svg" class="fa-solid fa-xmark">
                                </button>
                            </div>
                            <div class="enq-list" id="enquiryList">
                                <div class="enq-empty"><img src="../icons/inbox-solid-full.svg"
                                        class="fa-solid fa-inbox"><br>Loading...</div>
                            </div>
                            <div class="enq-footer">
                                <a href="view_enquiries.php"><img src="../icons/arrow-right-solid-full.svg"
                                        class="fa-solid fa-arrow-right"> View All Enquiries</a>
                            </div>
                        </div>
                    </div>
                    <div class="notification-container">
                        <button class="notification-btn" id="notificationBtn" title="Email Notifications">
                            <img src="../icons/email.png" alt="Reports" width="25">
                            <span class="notification-badge hidden" id="notificationBadge">0</span>
                        </button>
                        <div class="notification-dropdown" id="notificationDropdown">
                            <div class="notification-header">
                                <h4><img src="../icons/envelope-solid-full.svg" class="fa-solid fa-envelope"
                                        style="margin-right:6px;color:#4C6EF5;">Email
                                    Replies</h4>
                                <button class="close-notification-btn" id="closeNotifBtn">
                                    <img src="../icons/xmark-solid-full.svg" class="fa-solid fa-xmark">
                                </button>
                            </div>
                            <div class="notification-list" id="notificationList">
                                <div class="notif-loading"><img src="../icons/circle-notch-solid-full.svg"
                                        class="fa-solid fa-circle-notch"></div>
                            </div>
                            <div class="notification-footer">
                                <a href="#" id="refreshNotifBtn"><img src="../icons/rotate-right-solid-full.svg"
                                        class="fa-solid fa-rotate-right" style="margin-right:4px;">Refresh Inbox</a>
                            </div>
                        </div>
                    </div>
                    <!-- Logout Button -->
                    <a href="../auth/logout.php" id="logoutBtn" title="Logout"
                        style="display:inline-flex;align-items:center;gap:8px;padding:8px 11px 8px 11px;border-radius:14px;background:#fff;border:none;cursor:pointer;text-decoration:none;transition:background 0.2s,transform 0.2s,box-shadow 0.2s;flex-shrink:0;box-shadow:0 2px 8px rgba(0,0,0,0.07);"
                        onmouseover="this.style.background='#FEF2F2';this.style.boxShadow='0 4px 14px rgba(220,38,38,0.13)';this.style.transform='translateY(-1px)';"
                        onmouseout="this.style.background='#fff';this.style.boxShadow='0 2px 8px rgba(0,0,0,0.07)';this.style.transform='translateY(0)';">
                        <span
                            style="display:inline-flex;align-items:center;justify-content:center;width:28px;height:28px;border-radius:8px;background:#FEE2E2;flex-shrink:0;">
                            <img src="../icons/logout.png" alt="Logout" width="23">
                        </span>
                        <span style="font-size:13px;font-weight:600;color:#DC2626;letter-spacing:0.2px;">Logout</span>
                    </a>
                </div>
            </header>

            <section class="kpi-grid">
                <div class="stat-card">
                    <div class="stat-header">
                        <div class="stat-icon orange-gradient">
                            <img src="../icons/dumbbell-solid-full.svg" alt="JO" width="20">
                        </div>
                        <div class="menu-dots"><img src="../icons/ellipsis-solid-full.svg" alt="img" width="20"></div>
                    </div>
                    <div class="stat-body">
                        <h3>Total Members</h3>
                        <h2><?= $total_members ?></h2>
                        <p class="growth <?= $member_growth_direction ?>">
                            <?php if ($member_growth_pct >= 0): ?>
                                <img src="../icons/arrow-trend-up-solid-full.svg" alt="Up" width="20"> +
                                <?= $member_growth_pct ?>%
                            <?php else: ?>
                                <img src="../icons/arrow-trend-up-solid-full.svg" alt="Down" width="20"
                                    style="transform: rotate(180deg);">
                                <?= $member_growth_pct ?>%
                            <?php endif; ?>
                            <span>vs last month</span>
                        </p>
                    </div>
                </div>

                <div class="stat-card">
                    <div class="stat-header">
                        <div class="stat-icon purple-gradient">
                            <img src="../icons/fire-solid-full.svg" alt="JO" width="20">
                        </div>
                        <div class="menu-dots"><img src="../icons/ellipsis-solid-full.svg" alt="img" width="20"></div>
                    </div>
                    <div class="stat-body">
                        <h3>Membership Plans</h3>
                        <h2><?= $plan_count ?></h2>
                        <p class="growth neutral">
                            <img src="../icons/circle-exclamation-solid-full.svg" alt="img" width="20">
                            <a href="membership.php" style="color: inherit; text-decoration: none;">View all plans</a>
                        </p>
                    </div>
                </div>

                <div class="stat-card clickable" onclick="openRevenueModal()" title="View Recent Revenue History">
                    <div class="stat-header">
                        <div class="stat-icon green-gradient">
                            <img src="../icons/wallet-solid-full.svg" alt="JO" width="20">
                        </div>
                        <div class="menu-dots"><img src="../icons/ellipsis-solid-full.svg" alt="img" width="20"></div>
                    </div>
                    <div class="stat-body">
                        <h3>Revenue</h3>
                        <h2>₹<?= number_format($total_revenue, 0) ?></h2>
                        <p class="growth <?= $revenue_growth_direction ?>">
                            <?php if ($revenue_growth_pct >= 0): ?>
                                <img src="../icons/arrow-trend-up-solid-full.svg" alt="Up" width="20"> +
                                <?= $revenue_growth_pct ?>%
                            <?php else: ?>
                                <img src="../icons/arrow-trend-up-solid-full.svg" alt="Down" width="20"
                                    style="transform: rotate(180deg);">
                                <?= $revenue_growth_pct ?>%
                            <?php endif; ?>
                            <span>vs last month</span>
                        </p>
                    </div>
                </div>
            </section>

            <section class="quick-actions-row">
                <a href="add_member.php" class="quick-action-card">
                    <div class="quick-action-icon">
                        <img src="../icons/user-plus-solid-full.svg" alt="Add Member" width="20">
                    </div>
                    <span>Add Member</span>
                </a>
                <a href="consultation.php" class="quick-action-card">
                    <div class="quick-action-icon">
                        <img src="../icons/file-invoice-solid-full.svg" alt="Create Invoice" width="20">
                    </div>
                    <span>Consultation</span>
                </a>
                <a href="create_diet_plan.php" class="quick-action-card">
                    <div class="quick-action-icon">
                        <img src="../icons/calendar-plus-solid-full.svg" alt="New Diet Plan" width="20">
                    </div>
                    <span>New Diet Plan</span>
                </a>
            </section>

            <section class="content-grid">

                <div class="panel calendar-panel">
                    <div class="panel-header">
                        <h3>Calendar</h3>
                        <div class="cal-actions">
                            <button class="add-event-btn" id="openEventModalBtn"><img src="../icons/plus-solid-full.svg"
                                    class="fa-solid fa-plus"></button>

                            <button class="cal-btn" id="prevBtn"><img src="../icons/chevron-left-solid-full.svg"
                                    class="fa-solid fa-chevron-left"></button>
                            <button class="cal-btn" id="todayBtn">Today</button>
                            <button class="cal-btn" id="nextBtn"><img src="../icons/chevron-right-solid-full.svg"
                                    class="fa-solid fa-chevron-right"></button>
                        </div>
                    </div>
                    <div class="mini-calendar">
                        <div class="month-year">
                            <h4 id="monthYear">January 2026</h4>
                        </div>
                        <div class="days-grid">
                            <div>Sun</div>
                            <div>Mon</div>
                            <div>Tue</div>
                            <div>Wed</div>
                            <div>Thu</div>
                            <div>Fri</div>
                            <div>Sat</div>
                        </div>
                        <div class="dates-grid" id="datesGrid"></div>
                    </div>
                    <div class="calendar-footer">
                        <a href="calendar.php" class="view-full-btn">View Full Calendar</a>
                    </div>
                </div>

                <div class="panel side-panel">
                    <div class="panel-header">
                        <h3>Upcoming Events</h3>
                    </div>
                    <div class="event-list-dashboard" id="eventListDashboard">
                        <div class="empty-state">Loading events...</div>
                    </div>
                </div>

            </section>

        </main>
    </div>

    <div class="modal-overlay" id="eventModal">
        <div class="modal-content">
            <h3>Add New Event</h3>
            <form id="addEventForm">
                <div class="form-group">
                    <label>Event Title</label>
                    <input type="text" name="title" required placeholder="e.g. Morning Yoga">
                </div>
                <div class="form-group">
                    <label>Date</label>
                    <input type="date" name="date" id="eventDateInput" required>
                </div>
                <div class="form-group">
                    <label>Time</label>
                    <input type="time" name="time" required>
                </div>
                <div class="form-group">
                    <label>Type</label>
                    <select name="type">
                        <option value="other">Other</option>
                        <option value="personal">Personal</option>
                        <option value="work">Work</option>
                        <option value="gym">Gym</option>
                    </select>
                </div>
                <div class="modal-actions">
                    <button type="button" class="btn-cancel" id="closeModalBtn">Cancel</button>
                    <button type="submit" class="btn-save">Save Event</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Delete Event Confirmation Modal -->
    <div class="delete-confirm-overlay" id="deleteConfirmModal">
        <div class="delete-confirm-card">
            <div class="delete-confirm-icon">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 448 512">
                    <path
                        d="M135.2 17.7L128 32H32C14.3 32 0 46.3 0 64s14.3 32 32 32h384c17.7 0 32-14.3 32-32s-14.3-32-32-32H320l-7.2-14.3C307.4 6.8 296.3 0 284.2 0H163.8c-12.1 0-23.2 6.8-28.6 17.7zM416 128H32l21.2 339C55.5 487.8 73.8 512 100.4 512h247.2c26.5 0 44.9-24.2 47.2-45l21.2-339z" />
                </svg>
            </div>
            <h3>Delete Event</h3>
            <p>Are you sure you want to remove this event?</p>
            <div class="delete-confirm-event-name" id="deleteEventName"></div>
            <p style="font-size:0.8rem;color:#94A3B8;margin-bottom:0;">This action cannot be undone.</p>
            <div class="delete-confirm-actions" style="margin-top:18px;">
                <button class="btn-cancel-delete" id="cancelDeleteBtn">Cancel</button>
                <button class="btn-confirm-delete" id="confirmDeleteBtn">Delete Event</button>
            </div>
        </div>
    </div>

    <?php if ($can_manage_staff): ?>
        <!-- Create Admin / Trainer Account Modal -->
        <div class="modal-overlay staff-modal" id="adminModal">
            <div class="staff-modal-card">
                <button type="button" class="staff-modal-close" data-close="adminModal" aria-label="Close">
                    <img src="../icons/xmark-solid-full.svg" class="fa-solid fa-xmark">
                </button>
                <div class="staff-modal-head">
                    <div class="staff-modal-icon">
                        <img src="../icons/user-tie-solid-full.svg" class="fa-solid fa-user-tie">
                    </div>
                    <div>
                        <h3>Create Admin Account</h3>
                        <p>Add a new admin or trainer login for the panel.</p>
                    </div>
                </div>
                <div class="staff-form-msg" id="adminFormMsg" style="display:none;"></div>
                <form id="addAdminForm" autocomplete="off">
                    <input type="hidden" name="_csrf_token"
                        value="<?= htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="account_type" value="admin">
                    <div class="staff-field">
                        <label>Full Name</label>
                        <input type="text" name="full_name" required placeholder="First Last">
                    </div>
                    <div class="staff-field">
                        <label>Email Address</label>
                        <input type="email" name="email" required placeholder="name@example.com">
                    </div>
                    <div class="staff-field">
                        <label>Password</label>
                        <input type="password" name="password" required minlength="8" placeholder="Min. 8 characters">
                    </div>
                    <div class="staff-field">
                        <label>Role</label>
                        <select name="role" required>
                            <option value="admin">Admin</option>
                            <option value="trainer">Trainer</option>
                        </select>
                    </div>
                    <div class="staff-modal-actions">
                        <button type="button" class="staff-btn-ghost" data-close="adminModal">Cancel</button>
                        <button type="submit" class="staff-btn-primary">Create Account</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Create Counsellor Account Modal -->
        <div class="modal-overlay staff-modal" id="counsellorModal">
            <div class="staff-modal-card">
                <button type="button" class="staff-modal-close" data-close="counsellorModal" aria-label="Close">
                    <img src="../icons/xmark-solid-full.svg" class="fa-solid fa-xmark">
                </button>
                <div class="staff-modal-head">
                    <div class="staff-modal-icon">
                        <img src="../icons/clipboard-user-solid-full.svg" class="fa-solid fa-clipboard-user">
                    </div>
                    <div>
                        <h3>Create Counsellor Account</h3>
                        <p>Add a new counsellor login for the panel.</p>
                    </div>
                </div>
                <div class="staff-form-msg" id="counsellorFormMsg" style="display:none;"></div>
                <form id="addCounsellorForm" autocomplete="off">
                    <input type="hidden" name="_csrf_token"
                        value="<?= htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="account_type" value="counsellor">
                    <div class="staff-field">
                        <label>Full Name</label>
                        <input type="text" name="full_name" required placeholder="First Last">
                    </div>
                    <div class="staff-field">
                        <label>Email Address</label>
                        <input type="email" name="email" required placeholder="name@example.com">
                    </div>
                    <div class="staff-field">
                        <label>Password</label>
                        <input type="password" name="password" required minlength="8" placeholder="Min. 8 characters">
                    </div>
                    <div class="staff-modal-actions">
                        <button type="button" class="staff-btn-ghost" data-close="counsellorModal">Cancel</button>
                        <button type="submit" class="staff-btn-primary">Create Account</button>
                    </div>
                </form>
            </div>
        </div>
    <?php endif; ?>

    <script>
        // Global Events Array
        let events = [];

        document.addEventListener('DOMContentLoaded', function () {

            // ══════════════════════════════════════════════════
            //  NOTIFICATION / EMAIL REPLY SYSTEM
            // ══════════════════════════════════════════════════
            let notifOpen = false;
            const notifBtn = document.getElementById('notificationBtn');
            const notifDrop = document.getElementById('notificationDropdown');
            const notifBadge = document.getElementById('notificationBadge');
            const notifList = document.getElementById('notificationList');
            const closeNotifBtn = document.getElementById('closeNotifBtn');
            const refreshNotifBtn = document.getElementById('refreshNotifBtn');

            if (notifBtn) {
                notifBtn.addEventListener('click', function (e) {
                    e.stopPropagation();
                    notifOpen = !notifOpen;
                    notifDrop.classList.toggle('active', notifOpen);
                    if (notifOpen) openNotificationPanel();
                });
            }

            if (closeNotifBtn) {
                closeNotifBtn.addEventListener('click', function (e) {
                    e.stopPropagation();
                    notifOpen = false;
                    notifDrop.classList.remove('active');
                });
            }

            if (refreshNotifBtn) {
                refreshNotifBtn.addEventListener('click', function (e) {
                    e.preventDefault();
                    e.stopPropagation();
                    openNotificationPanel();
                });
            }

            // Close when clicking outside
            document.addEventListener('click', function (e) {
                if (notifOpen && notifDrop && !notifDrop.contains(e.target) && e.target !== notifBtn) {
                    notifOpen = false;
                    notifDrop.classList.remove('active');
                }
            });

            async function openNotificationPanel() {
                showNotifLoading();
                const oldWarn = notifDrop.querySelector('.notif-warning');
                if (oldWarn) oldWarn.remove();
                try {
                    // Added { cache: 'no-store' } to ensure a fresh fetch bypassing browser cache
                    const pollRes = await fetch('../handlers/fetch_email_replies.php', { cache: 'no-store' });
                    const pollData = await pollRes.json();

                    if (pollData.status === 'warning') {
                        await loadNotificationsFromDB();
                        showImapWarning();
                        return;
                    }

                    await loadNotificationsFromDB();
                    // Mark read instantly on panel open
                    await fetch('../handlers/mark_notifications_read.php', { method: 'POST', cache: 'no-store' });
                    updateBadge(0);
                } catch (err) {
                    notifList.innerHTML = '<div class="notif-empty"><img src="../icons/triangle-exclamation-solid-full.svg" class="fa-solid fa-triangle-exclamation"><br>Could not connect. Check server.</div>';
                    console.error('Notification error:', err);
                }
            }

            async function loadNotificationsFromDB() {
                // Ensure fresh data from DB
                const res = await fetch('../handlers/get_notifications.php', { cache: 'no-store' });
                const data = await res.json();
                if (data.status === 'success') renderNotifications(data.notifications);
            }

            // -- Notification helpers --
            const emailModalOverlay = document.getElementById('emailModalOverlay');
            const emailModalClose = document.getElementById('emailModalClose');
            const emailModalSubject = document.getElementById('emailModalSubject');
            const emailModalFrom = document.getElementById('emailModalFrom');
            const emailModalBodyText = document.getElementById('emailModalBodyText');

            if (emailModalClose) {
                emailModalClose.addEventListener('click', function () {
                    emailModalOverlay.classList.remove('open');
                });
            }
            if (emailModalOverlay) {
                emailModalOverlay.addEventListener('click', function (e) {
                    if (e.target === emailModalOverlay) emailModalOverlay.classList.remove('open');
                });
            }

            function renderNotifications(items) {
                if (!items || items.length === 0) {
                    notifList.innerHTML = '<div class="notif-empty"><img src="../icons/inbox-solid-full.svg" class="fa-solid fa-inbox"><br>No email replies yet.<br><small>Replies from members appear here.</small></div>';
                    return;
                }
                var html = '';
                items.forEach(function (n) {
                    var name = n.member_name || n.member_email || '?';
                    var initials = name.split(' ').map(function (w) { return w[0]; }).join('').toUpperCase().slice(0, 2);
                    var cls = (n.is_read == 0) ? 'unread' : '';
                    var sender = escHtml(name);
                    var subject = escHtml(n.title || '(No Subject)');
                    var preview = escHtml(n.message || '');
                    var ago = timeAgo(n.created_at);
                    html += '<div class="notif-item ' + cls + '" data-id="' + n.id + '" style="cursor:pointer;">' +
                        '<div class="notif-avatar">' + initials + '</div>' +
                        '<div class="notif-body">' +
                        '<div class="notif-sender">' + sender + '</div>' +
                        '<div class="notif-subject">&#9993; ' + subject + '</div>' +
                        (preview ? '<div class="notif-preview">' + preview + '</div>' : '') +
                        '<div class="notif-time"><img src="../icons/clock-solid-full.svg" class="fa-regular fa-clock" style="margin-right:3px;">' + ago + '</div>' +
                        '</div></div>';
                });
                notifList.innerHTML = html;
                notifList.querySelectorAll('.notif-item').forEach(function (el) {
                    el.addEventListener('click', function () {
                        var id = el.getAttribute('data-id');
                        var n = items.find(function (x) { return String(x.id) === id; });
                        if (n) openEmailModal(n);
                    });
                });
            }

            function openEmailModal(n) {
                if (!emailModalOverlay) return;
                emailModalSubject.textContent = n.title || '(No Subject)';
                emailModalFrom.textContent = (n.member_name || n.member_email || 'Unknown') +
                    (n.member_email ? ' <' + n.member_email + '>' : '');
                emailModalBodyText.textContent = n.body_full || n.message || '(No message body)';
                emailModalOverlay.classList.add('open');
                notifOpen = false;
                notifDrop.classList.remove('active');
            }

            function showNotifLoading() {
                notifList.innerHTML = '<div class="notif-loading" style="display:flex;flex-direction:column;align-items:center;justify-content:center;padding:40px 0;">' +
                    '<svg class="notif-spinner" width="40" height="40" viewBox="0 0 40 40" style="animation:spin 1s linear infinite;">' +
                    '<circle cx="20" cy="20" r="16" stroke="#111" stroke-width="4" fill="none" stroke-dasharray="80" stroke-dashoffset="60"></circle>' +
                    '</svg>' +
                    '<p style="margin-top:18px;font-size:1rem;color:#6B7280;">Checking inbox...</p>' +
                    '</div>';
            }

            function showImapWarning() {
                var warn = document.createElement('div');
                warn.className = 'notif-warning';
                warn.innerHTML = '<img src="../icons/triangle-exclamation-solid-full.svg" class="fa-solid fa-triangle-exclamation"> IMAP not enabled. Enable <code>extension=imap</code> in php.ini and restart Apache.';
                var footer = notifDrop.querySelector('.notification-footer');
                if (footer) notifDrop.insertBefore(warn, footer);
            }

            function updateBadge(count) {
                if (!notifBadge) return;
                if (count > 0) {
                    notifBadge.textContent = count > 99 ? '99+' : count;
                    notifBadge.classList.remove('hidden');
                } else {
                    notifBadge.classList.add('hidden');
                }
            }

            async function pollBadgeCount() {
                try {
                    // Check DB for unread notifications aggressively, bypassing cache
                    var res = await fetch('../handlers/get_notifications.php', { cache: 'no-store' });
                    var data = await res.json();

                    if (data.status === 'success') {
                        var newCount = data.unread_count;
                        // Determine current count shown on screen
                        var oldCount = parseInt(notifBadge && !notifBadge.classList.contains('hidden') ? notifBadge.textContent : '0') || 0;

                        if (newCount > oldCount && newCount > 0) {
                            // Play animation only when the count actually goes UP
                            if (notifBadge) {
                                notifBadge.classList.add('badge-pulse');
                                setTimeout(function () { notifBadge.classList.remove('badge-pulse'); }, 800);
                            }
                            if (notifBtn) {
                                notifBtn.classList.add('bell-ring');
                                setTimeout(function () { notifBtn.classList.remove('bell-ring'); }, 800);
                            }

                            // Live Update: If user is looking at the dropdown, update the list instantly!
                            if (notifOpen) {
                                loadNotificationsFromDB();
                            }
                        }

                        // Update badge ONLY IF the user isn't currently looking at the open panel
                        // (Because opening the panel clears the unread count)
                        if (!notifOpen) {
                            updateBadge(newCount);
                        }
                    }
                } catch (e) { }
            }

            async function fetchNewEmails() {
                try {
                    // Hit the actual IMAP email server periodically, no caching
                    await fetch('../handlers/fetch_email_replies.php', { cache: 'no-store' });
                    // Immediately check local DB to see if anything new arrived
                    await pollBadgeCount();
                } catch (e) { }
            }

            // Kick off instantly on page load
            pollBadgeCount();
            fetchNewEmails();

            // Set faster polling intervals to simulate instant push
            setInterval(pollBadgeCount, 5000);   // Check Local DB every 5 seconds
            setInterval(fetchNewEmails, 15000);  // Scan IMAP server every 15 seconds


            // ══════════════════════════════════════════════════
            //  ENQUIRY NOTIFICATION SYSTEM
            // ══════════════════════════════════════════════════
            let enqOpen = false;
            const enqBtn = document.getElementById('enquiryNotifBtn');
            const enqDrop = document.getElementById('enquiryDropdown');
            const enqBadge = document.getElementById('enquiryNotifBadge');
            const enqList = document.getElementById('enquiryList');
            const closeEnqBtn = document.getElementById('closeEnqBtn');

            if (enqBtn) {
                enqBtn.addEventListener('click', function (e) {
                    e.stopPropagation();
                    enqOpen = !enqOpen;
                    enqDrop.classList.toggle('active', enqOpen);
                    if (enqOpen) openEnquiryPanel();
                });
            }

            if (closeEnqBtn) {
                closeEnqBtn.addEventListener('click', function (e) {
                    e.stopPropagation();
                    enqOpen = false;
                    enqDrop.classList.remove('active');
                });
            }

            // Close enquiry dropdown when clicking outside
            document.addEventListener('click', function (e) {
                if (enqOpen && enqDrop && !enqDrop.contains(e.target) && e.target !== enqBtn) {
                    enqOpen = false;
                    enqDrop.classList.remove('active');
                }
            });

            async function openEnquiryPanel() {
                enqList.innerHTML = '<div class="enq-empty" style="padding:30px 20px;">' +
                    '<svg class="notif-spinner" width="40" height="40" viewBox="0 0 40 40" style="animation:spin 1s linear infinite;">' +
                    '<circle cx="20" cy="20" r="16" stroke="#10b981" stroke-width="4" fill="none" stroke-dasharray="80" stroke-dashoffset="60"></circle>' +
                    '</svg>' +
                    '<p style="margin-top:14px;font-size:0.85rem;color:#6B7280;">Loading enquiries...</p>' +
                    '</div>';
                try {
                    var res = await fetch('../handlers/get_enquiry_notifications.php', { cache: 'no-store' });
                    var data = await res.json();
                    if (data.status === 'success') {
                        renderEnquiries(data.enquiries);
                        // Mark all as seen
                        await fetch('../handlers/mark_enquiries_seen.php', { method: 'POST', cache: 'no-store' });
                        updateEnqBadge(0);
                    }
                } catch (err) {
                    enqList.innerHTML = '<div class="enq-empty"><img src="../icons/triangle-exclamation-solid-full.svg" class="fa-solid fa-triangle-exclamation"><br>Could not load enquiries.</div>';
                    console.error('Enquiry notification error:', err);
                }
            }

            function renderEnquiries(items) {
                if (!items || items.length === 0) {
                    enqList.innerHTML = '<div class="enq-empty"><img src="../icons/inbox-solid-full.svg" class="fa-solid fa-inbox"><br>No enquiries yet.<br><small>New enquiries from your website will appear here.</small></div>';
                    return;
                }
                var html = '';
                items.forEach(function (enq) {
                    var name = enq.full_name || 'Unknown';
                    var initials = name.split(' ').map(function (w) { return w[0]; }).join('').toUpperCase().slice(0, 2);
                    var cls = (enq.is_seen == 0) ? 'unseen' : '';
                    var ago = timeAgo(enq.created_at);
                    html += '<a href="view_enquiries.php" class="enq-item ' + cls + '">' +
                        '<div class="enq-avatar">' + initials + '</div>' +
                        '<div class="enq-body">' +
                        '<div class="enq-name">' + escHtml(name) + '</div>' +
                        '<div class="enq-contact">&#9993; ' + escHtml(enq.email || 'No email') + '</div>' +
                        '<div class="enq-phone"><img src="../icons/phone-solid-full.svg" class="fa-solid fa-phone"> ' + escHtml(enq.phone_number || 'N/A') + '</div>' +
                        '<div class="enq-time"><img src="../icons/clock-solid-full.svg" class="fa-solid fa-clock"> ' + ago + '</div>' +
                        '</div></a>';
                });
                enqList.innerHTML = html;
            }

            function updateEnqBadge(count) {
                if (!enqBadge) return;
                if (count > 0) {
                    enqBadge.textContent = '+' + (count > 99 ? '99' : count);
                    enqBadge.classList.remove('hidden');
                } else {
                    enqBadge.classList.add('hidden');
                }
            }

            async function pollEnquiryBadge() {
                try {
                    var res = await fetch('../handlers/get_enquiry_notifications.php', { cache: 'no-store' });
                    var data = await res.json();
                    if (data.status === 'success') {
                        var newCount = data.unread_count;
                        var oldCount = parseInt(enqBadge && !enqBadge.classList.contains('hidden') ? enqBadge.textContent.replace('+', '') : '0') || 0;

                        if (newCount > oldCount && newCount > 0) {
                            if (enqBadge) {
                                enqBadge.classList.add('badge-pop');
                                setTimeout(function () { enqBadge.classList.remove('badge-pop'); }, 800);
                            }
                            // If panel is open, refresh the list live
                            if (enqOpen) {
                                openEnquiryPanel();
                            }
                        }

                        if (!enqOpen) {
                            updateEnqBadge(newCount);
                        }
                    }
                } catch (e) { }
            }

            // Kick off enquiry polling on load
            pollEnquiryBadge();
            setInterval(pollEnquiryBadge, 10000);  // Poll every 10 seconds


            function timeAgo(dateStr) {
                var now = new Date();
                // created_at is now sent as ISO 8601 (e.g. 2026-03-27T10:30:00+05:30)
                // so new Date() parses it correctly with timezone info.
                var past = new Date(dateStr);
                if (isNaN(past.getTime())) return '';
                var diff = Math.floor((now - past) / 1000);
                if (diff < 0) diff = 0;
                if (diff < 60) return diff + 's ago';
                if (diff < 3600) return Math.floor(diff / 60) + 'm ago';
                if (diff < 86400) return Math.floor(diff / 3600) + 'h ago';
                return Math.floor(diff / 86400) + 'd ago';
            }

            function escHtml(str) {
                return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
            }


            // --- 1. MODAL LOGIC (Same as before) ---

            const modal = document.getElementById('eventModal');
            const openBtn = document.getElementById('openEventModalBtn');
            const closeBtn = document.getElementById('closeModalBtn');
            const form = document.getElementById('addEventForm');

            if (openBtn) {
                openBtn.addEventListener('click', () => {
                    const today = new Date();
                    const year = today.getFullYear();
                    const month = String(today.getMonth() + 1).padStart(2, '0');
                    const day = String(today.getDate()).padStart(2, '0');
                    const dateInput = document.getElementById('eventDateInput');
                    if (dateInput) dateInput.value = `${year}-${month}-${day}`;
                    modal.classList.add('active');
                });
            }

            if (closeBtn) {
                closeBtn.addEventListener('click', () => modal.classList.remove('active'));
            }

            // --- 2. ADD EVENT ---
            if (form) {
                form.addEventListener('submit', function (e) {
                    e.preventDefault();
                    const formData = new FormData(form);

                    fetch('../handlers/add_event.php', {
                        method: 'POST',
                        body: formData
                    })
                        .then(response => response.json())
                        .then(data => {
                            if (data.status === 'success') {
                                modal.classList.remove('active');
                                form.reset();
                                loadEventsFromServer();
                            } else {
                                alert('Error: ' + data.message);
                            }
                        })
                        .catch(error => console.error('Error:', error));
                });
            }


            const toggleBtn = document.getElementById('toggleBtn');
            const mobileToggle = document.getElementById('mobileToggle');
            const body = document.body;

            if (toggleBtn) toggleBtn.addEventListener('click', () => body.classList.toggle('collapsed'));
            if (mobileToggle) mobileToggle.addEventListener('click', () => body.classList.toggle('sidebar-open'));

            const dropdownItems = document.querySelectorAll('.nav-item-dropdown');
            dropdownItems.forEach(item => {
                const link = item.querySelector('.nav-link');
                const arrow = link ? link.querySelector('.nav-arrow') : null;
                if (arrow) {
                    arrow.addEventListener('click', function (e) {
                        e.preventDefault();
                        e.stopPropagation();
                        dropdownItems.forEach(otherItem => {
                            if (otherItem !== item) otherItem.classList.remove('active');
                        });
                        item.classList.toggle('active');
                    });
                }
            });



            // Load Data
            loadEventsFromServer();
        });



        // --- 4. FETCH EVENTS ---
        async function loadEventsFromServer() {
            try {
                const response = await fetch("../handlers/fetch_events.php");
                events = await response.json();
                renderCalendar();
                renderUpcomingEvents();
            } catch (error) {
                console.error("Error loading events:", error);
            }
        }

        // --- 5. DELETE EVENT FUNCTION (with confirmation modal) ---
        let pendingDeleteId = null;

        const deleteModal = document.getElementById('deleteConfirmModal');
        const deleteEventNameEl = document.getElementById('deleteEventName');
        const cancelDeleteBtn = document.getElementById('cancelDeleteBtn');
        const confirmDeleteBtn = document.getElementById('confirmDeleteBtn');

        window.deleteEvent = function (id) {
            pendingDeleteId = id;
            // Find event title to display in the modal
            const evt = events.find(e => String(e.id) === String(id));
            deleteEventNameEl.textContent = evt ? evt.title : 'Event #' + id;
            deleteModal.classList.add('active');
        }

        // Close modal on Cancel
        cancelDeleteBtn.addEventListener('click', function () {
            deleteModal.classList.remove('active');
            pendingDeleteId = null;
        });

        // Close modal when clicking the backdrop
        deleteModal.addEventListener('click', function (e) {
            if (e.target === deleteModal) {
                deleteModal.classList.remove('active');
                pendingDeleteId = null;
            }
        });

        // Confirm deletion
        confirmDeleteBtn.addEventListener('click', function () {
            if (!pendingDeleteId) return;

            const formData = new FormData();
            formData.append('id', pendingDeleteId);

            fetch('../handlers/delete_event.php', {
                method: 'POST',
                body: formData
            })
                .then(response => response.json())
                .then(data => {
                    deleteModal.classList.remove('active');
                    pendingDeleteId = null;
                    if (data.status === 'success') {
                        loadEventsFromServer(); // Refresh list
                    } else {
                        alert("Failed to delete: " + data.message);
                    }
                })
                .catch(err => {
                    deleteModal.classList.remove('active');
                    pendingDeleteId = null;
                    alert("Server error");
                });
        });

        // --- 6. RENDER UPCOMING EVENTS (Updated with Delete Button) ---
        function renderUpcomingEvents() {
            const eventListDashboard = document.getElementById('eventListDashboard');
            if (!eventListDashboard) return;

            const today = new Date();
            today.setHours(0, 0, 0, 0);

            let upcomingEvents = events.filter(e => {
                if (!e.date) return false;
                const eventDate = new Date(e.date.replace(/-/g, '/'));
                return eventDate >= today;
            });

            upcomingEvents.sort((a, b) => {
                const dateA = new Date(a.date + ' ' + (a.time || '00:00'));
                const dateB = new Date(b.date + ' ' + (b.time || '00:00'));
                return dateA - dateB;
            });

            if (upcomingEvents.length === 0) {
                eventListDashboard.innerHTML = '<div class="empty-state">No upcoming events</div>';
                return;
            }

            eventListDashboard.innerHTML = '';
            upcomingEvents.slice(0, 5).forEach((event) => {
                const d = new Date(event.date);
                const dayNum = d.getDate();
                const timeStr = event.time ? event.time.substring(0, 5) : "--:--";

                // Format Title for capitalization
                const typeDisplay = (event.type || 'other').charAt(0).toUpperCase() + (event.type || 'other').slice(1);

                const eventHTML = `
                <div class="event-card-dashboard ${event.type}">
                    <div class="event-time-dashboard">
                        <span>${dayNum}</span>
                        <small>${timeStr}</small>
                    </div>
                    <div class="event-info-dashboard">
                        <h4>${event.title}</h4>
                        <p>${typeDisplay}</p>
                    </div>
                    <button class="delete-event-btn" onclick="deleteEvent(${event.id})" title="Remove Event">
                        <img src="../icons/trash-solid-full.svg" class="fa-solid fa-trash">
                    </button>
                </div>
            `;
                eventListDashboard.innerHTML += eventHTML;
            });
        }

        // --- 7. RENDER CALENDAR (Same as before) ---
        // --- 7. RENDER CALENDAR (Optimized for Instant Load) ---
        const dateGrid = document.getElementById('datesGrid');
        const monthYear = document.getElementById('monthYear');
        let currentDate = new Date();

        if (document.getElementById('prevBtn')) {
            document.getElementById('prevBtn').addEventListener('click', () => { currentDate.setMonth(currentDate.getMonth() - 1); renderCalendar(); });
            document.getElementById('nextBtn').addEventListener('click', () => { currentDate.setMonth(currentDate.getMonth() + 1); renderCalendar(); });
            document.getElementById('todayBtn').addEventListener('click', () => { currentDate = new Date(); renderCalendar(); });
        }

        function renderCalendar() {
            if (!dateGrid) return;

            const year = currentDate.getFullYear();
            const month = currentDate.getMonth();
            const firstDay = new Date(year, month, 1).getDay();
            const lastDate = new Date(year, month + 1, 0).getDate();
            const lastDayIndex = new Date(year, month + 1, 0).getDay();
            const prevLastDate = new Date(year, month, 0).getDate();
            const months = ["January", "February", "March", "April", "May", "June", "July", "August", "September", "October", "November", "December"];

            if (monthYear) monthYear.innerHTML = `${months[month]} ${year}`;

            // OPTIMIZATION: Build HTML in a string first (prevents UI stutter)
            let htmlContent = "";

            for (let i = firstDay; i > 0; i--) {
                htmlContent += `<div class="date prev-date">${prevLastDate - i + 1}</div>`;
            }

            for (let i = 1; i <= lastDate; i++) {
                let isToday = i === new Date().getDate() && month === new Date().getMonth() && year === new Date().getFullYear() ? "today" : "";
                let monthStr = String(month + 1).padStart(2, '0');
                let dayStr = String(i).padStart(2, '0');
                let checkDate = `${year}-${monthStr}-${dayStr}`;

                let hasEvent = events.some(e => e.date === checkDate);
                let eventDot = hasEvent ? '<div class="event-dot"></div>' : '';
                htmlContent += `<div class="date ${isToday}">${i}${eventDot}</div>`;
            }

            for (let i = 1; i <= 7 - lastDayIndex - 1; i++) {
                htmlContent += `<div class="date next-date">${i}</div>`;
            }

            // Update the screen exactly ONCE
            dateGrid.innerHTML = htmlContent;
        }

        // OPTIMIZATION: Call this immediately so the empty grid shows BEFORE the server fetch finishes
        renderCalendar();
    </script>

    <!-- ===== PT SESSIONS DRAWER ===== -->

    <!-- Overlay backdrop -->
    <div class="pt-drawer-overlay" id="ptDrawerOverlay"></div>

    <!-- The drawer itself -->
    <div class="pt-drawer" id="ptDrawer">
        <div class="pt-drawer-header">
            <div class="pt-drawer-title">
                <img src="../icons/dumbbell-solid-full.svg" class="fa-solid fa-dumbbell">
                PT Sessions
            </div>
            <button class="pt-drawer-close" id="ptDrawerClose"><img src="../icons/xmark-solid-full.svg"
                    class="fa-solid fa-xmark"></button>
        </div>
        <div class="pt-drawer-body" id="ptDrawerBody">
            <?php
            $today_str = date('Y-m-d');
            $yesterday_str = date('Y-m-d', strtotime('-1 day'));
            $tomorrow_str = date('Y-m-d', strtotime('+1 day'));
            $day_labels = [
                $tomorrow_str => 'Tomorrow — ' . date('l, M j', strtotime('+1 day')),
                $today_str => 'Today — ' . date('l, M j'),
                $yesterday_str => 'Yesterday — ' . date('l, M j', strtotime('-1 day')),
            ];
            $day_is_future = [$tomorrow_str => true, $today_str => false, $yesterday_str => false];

            $has_any = !empty($pt_sessions_grouped);
            if (!$has_any):
                ?>
                <div class="pt-drawer-empty">
                    <img src="../icons/calendar-xmark-solid-full.svg" class="fa-solid fa-calendar-xmark">
                    <p style="margin:0;font-size:0.9rem;font-weight:600;color:#374151;">No sessions today or yesterday</p>
                    <p style="margin:6px 0 0;font-size:0.8rem;">Schedule sessions from the PT Sessions page.</p>
                </div>
            <?php else:
                foreach ($day_labels as $date_key => $label):
                    if (empty($pt_sessions_grouped[$date_key]))
                        continue;
                    $is_today = ($date_key === $today_str);
                    $is_future = ($day_is_future[$date_key] ?? false);
                    $accent = $is_future ? '#7C3AED' : ($is_today ? '#F25C2A' : '#6B7280');
                    $bg_accent = $is_future ? '#F5F3FF' : ($is_today ? '#FFF4EF' : '#F3F4F6');
                    $labelClass = $is_today ? 'today-label' : ($is_future ? 'tomorrow-label' : '');
                    ?>
                    <div class="pt-day-group">
                        <div class="pt-day-label <?= $labelClass ?>">
                            <?= htmlspecialchars($label) ?>
                            <span
                                style="margin-left:6px;font-size:10px;background:<?= $bg_accent ?>;color:<?= $accent ?>;padding:2px 7px;border-radius:20px;">
                                <?= count($pt_sessions_grouped[$date_key]) ?>
                                session<?= count($pt_sessions_grouped[$date_key]) !== 1 ? 's' : '' ?>
                            </span>
                        </div>
                        <?php foreach ($pt_sessions_grouped[$date_key] as $s):
                            $statusClass = 'pt-status-' . strtolower($s['status']);
                            $timeFormatted = date('h:i A', strtotime($s['session_time']));
                            ?>
                            <div class="pt-session-row">
                                <div class="pt-session-icon"><img src="../icons/person-running-solid-full.svg"
                                        class="fa-solid fa-person-running"></div>
                                <div class="pt-session-info">
                                    <div class="pt-session-member"><?= htmlspecialchars($s['member_name']) ?></div>
                                    <div class="pt-session-trainer"><img src="../icons/user-tie-solid-full.svg"
                                            class="fa-solid fa-user-tie"
                                            style="font-size:10px;margin-right:3px;"><?= htmlspecialchars($s['trainer_name']) ?>
                                    </div>
                                </div>
                                <div class="pt-session-meta">
                                    <div class="pt-session-time"><?= $timeFormatted ?></div>
                                    <span class="pt-session-status <?= $statusClass ?>"><?= htmlspecialchars($s['status']) ?></span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        <div class="pt-drawer-footer">
            <a href="pt_session.php"><img src="../icons/arrow-right-solid-full.svg" class="fa-solid fa-arrow-right">
                View All PT Sessions</a>
        </div>
    </div>

    <script>
        (function () {
            const btn = document.getElementById('ptNotifBtn');
            const drawer = document.getElementById('ptDrawer');
            const overlay = document.getElementById('ptDrawerOverlay');
            const closeBtn = document.getElementById('ptDrawerClose');

            function openDrawer() { drawer.classList.add('open'); overlay.classList.add('open'); }
            function closeDrawer() { drawer.classList.remove('open'); overlay.classList.remove('open'); }

            if (btn) btn.addEventListener('click', function (e) { e.stopPropagation(); openDrawer(); });
            if (closeBtn) closeBtn.addEventListener('click', closeDrawer);
            if (overlay) overlay.addEventListener('click', closeDrawer);
        })();
    </script>

    <div id="emailModalOverlay" class="email-modal-overlay">
        <div class="email-modal">
            <div class="email-modal-header">
                <div class="email-modal-meta">
                    <div id="emailModalSubject" class="email-modal-subject"></div>
                    <div id="emailModalFrom" class="email-modal-from"></div>
                </div>
                <button id="emailModalClose" class="email-modal-close" title="Close">
                    <img src="../icons/xmark-solid-full.svg" class="fa-solid fa-xmark">
                </button>
            </div>
            <div class="email-modal-body">
                <pre id="emailModalBodyText"
                    style="font-family:inherit;margin:0;white-space:pre-wrap;word-break:break-word;"></pre>
            </div>
        </div>
    </div>

    <!-- Revenue History Modal -->
    <div id="revenueModalOverlay" class="revenue-history-modal-overlay">
        <div class="revenue-history-modal">
            <div class="rev-modal-header">
                <div class="rev-modal-title">
                    <img src="../icons/clock-rotate-left-solid-full.svg" class="fa-solid fa-clock-rotate-left"> Recent
                    Transactions
                </div>
                <button id="revenueModalClose" class="rev-modal-close" onclick="closeRevenueModal()" title="Close">
                    <img src="../icons/xmark-solid-full.svg" class="fa-solid fa-xmark">
                </button>
            </div>
            <div class="rev-modal-body">
                <?php if (empty($recent_revenue_history)): ?>
                    <div class="notif-empty" style="padding: 40px 20px;">
                        <img src="../icons/receipt-solid-full.svg" class="fa-solid fa-receipt"
                            style="font-size: 2.5rem; color: #cbd5e1; margin-bottom: 12px; display: block;">
                        <p style="color: #94a3b8; margin: 0;">No recent transactions found.</p>
                    </div>
                <?php else: ?>
                    <ul class="rev-history-list">
                        <?php foreach ($recent_revenue_history as $tx):
                            $isMembership = ($tx['source_type'] === 'Membership');
                            $iconClass = $isMembership ? 'membership' : 'addon';
                            $iconGraphic = $isMembership ? '<img src="../icons/id-card-solid-full.svg" class="fa-solid fa-id-card">' : '<img src="../icons/spa-solid-full.svg" class="fa-solid fa-spa">';
                            $descText = htmlspecialchars($tx['description']);
                            if (!$isMembership)
                                $descText .= " (Add-on)";
                            $txDate = date('M j, g:i A', strtotime($tx['tx_date']));
                            ?>
                            <li class="rev-history-item">
                                <div class="rev-history-icon <?= $iconClass ?>">
                                    <?= $iconGraphic ?>
                                </div>
                                <div class="rev-history-info">
                                    <div class="rev-history-name"><?= htmlspecialchars($tx['name']) ?></div>
                                    <div class="rev-history-desc"><?= $descText ?></div>
                                </div>
                                <div class="rev-history-meta">
                                    <div class="rev-history-amount">+₹<?= number_format($tx['amount']) ?></div>
                                    <div class="rev-history-date"><?= $txDate ?></div>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script>
        function openRevenueModal() {
            document.getElementById('revenueModalOverlay').classList.add('open');
        }

        function closeRevenueModal() {
            document.getElementById('revenueModalOverlay').classList.remove('open');
        }

        // Close when clicking outside of the modal
        document.getElementById('revenueModalOverlay').addEventListener('click', function (e) {
            if (e.target === this) {
                closeRevenueModal();
            }
        });
    </script>

    <!-- SVG Inline Injection for Coloring -->
    <script>
        document.addEventListener("DOMContentLoaded", () => {
            document.querySelectorAll('img[class*="fa-"]').forEach(img => {
                const imgID = img.id;
                const imgClass = img.className;
                const imgURL = img.src;

                fetch(imgURL)
                    .then(r => r.text())
                    .then(text => {
                        const parser = new DOMParser();
                        const xmlDoc = parser.parseFromString(text, "text/xml");
                        const svg = xmlDoc.getElementsByTagName("svg")[0];

                        if (!svg) return;

                        if (imgID) svg.setAttribute('id', imgID);
                        if (imgClass) svg.setAttribute('class', imgClass + ' replaced-svg');

                        svg.removeAttribute('xmlns:a');

                        // We remove the width and height explicitly set on SVG 
                        // so our CSS can control it using typography sizing.
                        svg.removeAttribute('width');
                        svg.removeAttribute('height');

                        img.replaceWith(svg);
                    })
                    .catch(err => console.error(err));
            });
        });
    </script>

    <?php if ($can_manage_staff): ?>
        <!-- Create staff login accounts (Add Admin / Add Counsellor) -->
        <script>
            (function () {
                const configs = [
                    { btn: 'openAdminModalBtn', modal: 'adminModal', form: 'addAdminForm', msg: 'adminFormMsg' },
                    { btn: 'openCounsellorModalBtn', modal: 'counsellorModal', form: 'addCounsellorForm', msg: 'counsellorFormMsg' },
                ];

                function showMsg(box, text, ok) {
                    box.textContent = text;
                    box.style.display = 'block';
                    box.style.background = ok ? '#d1fae5' : '#fee2e2';
                    box.style.color = ok ? '#065f46' : '#b91c1c';
                }

                function clearFields(form) {
                    form.querySelectorAll('input[type="text"], input[type="email"], input[type="password"]')
                        .forEach(i => { i.value = ''; });
                    const sel = form.querySelector('select');
                    if (sel) sel.selectedIndex = 0;
                }

                configs.forEach(cfg => {
                    const openBtn = document.getElementById(cfg.btn);
                    const modal = document.getElementById(cfg.modal);
                    const form = document.getElementById(cfg.form);
                    const msgBox = document.getElementById(cfg.msg);
                    if (!openBtn || !modal || !form) return;

                    const submitBtn = form.querySelector('.staff-btn-primary');
                    const close = () => modal.classList.remove('active');

                    openBtn.addEventListener('click', () => {
                        clearFields(form);
                        msgBox.style.display = 'none';
                        modal.classList.add('active');
                    });

                    modal.querySelectorAll('[data-close]').forEach(el => el.addEventListener('click', close));
                    modal.addEventListener('click', e => { if (e.target === modal) close(); });

                    form.addEventListener('submit', e => {
                        e.preventDefault();
                        submitBtn.disabled = true;
                        submitBtn.textContent = 'Creating…';
                        msgBox.style.display = 'none';

                        fetch('../handlers/create_staff_account.php', { method: 'POST', body: new FormData(form) })
                            .then(r => r.json())
                            .then(data => {
                                if (data.success) {
                                    showMsg(msgBox, data.message, true);
                                    clearFields(form);
                                } else {
                                    showMsg(msgBox, data.message || 'Could not create the account.', false);
                                }
                            })
                            .catch(() => showMsg(msgBox, 'Could not reach the server. Please try again.', false))
                            .finally(() => {
                                submitBtn.disabled = false;
                                submitBtn.textContent = 'Create Account';
                            });
                    });
                });

                // Esc closes any open create-account modal
                document.addEventListener('keydown', e => {
                    if (e.key === 'Escape') {
                        document.querySelectorAll('.staff-modal.active').forEach(m => m.classList.remove('active'));
                    }
                });
            })();
        </script>
    <?php endif; ?>
</body>

</html>