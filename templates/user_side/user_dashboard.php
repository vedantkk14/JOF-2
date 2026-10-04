<?php
require_once __DIR__ . '/../../auth/auth_check.php';
require_role(['user']);
$user = get_session_user();

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../auth/workout_helper.php';
require_once __DIR__ . '/../../auth/diet_plan_schema.php';
require_once __DIR__ . '/../../auth/daily_goals_schema.php';

$uid = (int) $user['id'];

// Daily goals: lifetime counts for the banner, and every day with goals for the calendar.
// Only today's list is editable in the UI; past days are shown read-only.
$goals_stats = daily_goals_stats($conn, $uid);
$goals_js = ['today' => date('Y-m-d'), 'days' => []];
foreach (daily_goals_history($conn, $uid, 400) as $__gd) {
    $goals_js['days'][$__gd['date']] = $__gd['goals'];
}

// Current assigned diet plan (latest), for the dashboard card
$current_diet_plan = null;
$current_diet_meals = [];
$mstmt2 = $conn->prepare("SELECT id, membership FROM members WHERE user_id = ? ORDER BY id DESC LIMIT 1");
$mstmt2->bind_param('i', $uid);
$mstmt2->execute();
$mrow2 = $mstmt2->get_result()->fetch_assoc();
if ($mrow2) {
    $dpstmt = $conn->prepare("SELECT DISTINCT dp.* FROM diet_plans dp
                               JOIN diet_plan_assignments dpa ON dpa.plan_id = dp.id
                               WHERE dpa.member_id IN (SELECT id FROM members WHERE user_id = ?)
                               ORDER BY dp.created_at DESC LIMIT 1");
    $current_member_id = (int) $mrow2['id'];
    $dpstmt->bind_param('i', $uid);
    $dpstmt->execute();
    $current_diet_plan = $dpstmt->get_result()->fetch_assoc();
    if ($current_diet_plan) {
        $current_diet_meals = diet_plan_meal_summary($current_diet_plan);
    }
}

// Current membership (latest CONFIRMED payment row = source of truth — a 'Pending Setup'
// placeholder from an unverified subscribe/renewal request must never override what's
// actually active, so it's explicitly excluded here; see auth/membership_helper.php)
require_once __DIR__ . '/../../auth/membership_helper.php';
$latest_payment = null;
$pending_plan_request = null;
if ($mrow2) {
    $paystmt = $conn->prepare("SELECT * FROM member_payments
                                WHERE member_id = ? AND membership_type != 'Pending Setup'
                                ORDER BY created_at DESC LIMIT 1");
    $paystmt->bind_param('i', $current_member_id);
    $paystmt->execute();
    $latest_payment = $paystmt->get_result()->fetch_assoc();

    $pending_plan_request = membership_pending_request($conn, $current_member_id);
}
$membership_info = membership_status_info($conn, $latest_payment, $mrow2['membership'] ?? '');
$current_plan_name = $membership_info['plan_name'];
$membership_days_remaining = $membership_info['days_remaining'];
$membership_status = $membership_info['status'];
$membership_pause = $mrow2 ? membership_pause_info($conn, (int) $mrow2['id']) : null;
$membership_extension = ($mrow2 && !$membership_pause) ? membership_extension_info($conn, (int) $mrow2['id']) : null;
// Across every plan the member has ever had, not just the latest — see auth/membership_helper.php
$unpaid_dues = $mrow2 ? membership_unpaid_summary($conn, (int) $mrow2['id']) : ['has_overdue' => false];

// Next upcoming PT session (card only ever shows a real, scheduled session)
$next_pt_session = null;
if ($mrow2) {
    $ptstmt = $conn->prepare("
        SELECT ps.session_date, ps.session_time, ps.status, ps.notes,
               COALESCE(t.full_name, 'Your trainer') AS trainer_name
        FROM pt_sessions ps
        JOIN personal_training pt ON pt.id = ps.pt_id
        LEFT JOIN trainers t ON t.id = pt.trainer_id
        WHERE ps.member_id = ?
          AND ps.session_date >= CURDATE()
          AND ps.status NOT IN ('Cancelled', 'Completed')
        ORDER BY ps.session_date ASC, ps.session_time ASC
        LIMIT 1
    ");
    $ptstmt->bind_param('i', $current_member_id);
    $ptstmt->execute();
    $next_pt_session = $ptstmt->get_result()->fetch_assoc();
}

require_once __DIR__ . '/../../auth/profile_helper.php';
$pc = $conn->query("SELECT full_name, email, profile_completed, profile_pic FROM user_data WHERE id = $uid")->fetch_assoc();
$profile_incomplete = !$pc || !user_profile_status($conn, $uid)['complete'];

// Latest payment record (any type, including a pending request) for the live Payment Status card
$latest_payment_any = null;
if ($mrow2) {
    $lpstmt = $conn->prepare("SELECT * FROM member_payments WHERE member_id = ? ORDER BY created_at DESC, payment_id DESC LIMIT 1");
    $lpstmt->bind_param('i', $current_member_id);
    $lpstmt->execute();
    $latest_payment_any = $lpstmt->get_result()->fetch_assoc();
}
$pfp_url = (!empty($pc['profile_pic']) && is_file(__DIR__ . '/../../uploads/profile_pics/' . $pc['profile_pic']))
    ? '../../uploads/profile_pics/' . rawurlencode($pc['profile_pic'])
    : null;
$u_name = $user['name'] ?: 'Member';
$u_initials = strtoupper(mb_substr($u_name, 0, 1) . (str_contains($u_name, ' ') ? mb_substr(strrchr($u_name, ' '), 1, 1) : ''));

$streak = workout_streak_stats($conn, $uid);
$csrf   = generate_csrf_token();

// ── When the profile is not done, prep the vars the wizard partial needs ──
if ($profile_incomplete) {
    $DIETS   = ['non-veg' => 'Non-Vegetarian', 'veg' => 'Vegetarian', 'vegan' => 'Vegan', 'eggetarian' => 'Eggetarian', 'keto' => 'Keto / Low Carb'];
    $GENDERS = ['Male', 'Female', 'Other'];
    $email   = $pc['email'];
    $done    = false;
    $errors  = [];
    $has_payment_step = true;
    $first_bad_step   = 1;
    $measure = null;
    $img_base = '../../uploads/progress_photos/';
    $pic = $pc['profile_pic'] ?? null;
    $has_pic = $pic && is_file(__DIR__ . '/../../uploads/profile_pics/' . $pic);
    $avatar  = $has_pic
        ? '../../uploads/profile_pics/' . rawurlencode($pic)
        : 'https://ui-avatars.com/api/?name=' . urlencode($u_name) . '&background=FF6B47&color=fff&size=160&bold=true';
    $v = [
        'full_name' => $pc['full_name'] ?: '', 'phone_number' => '', 'gender' => '', 'diet_type' => '',
        'personal_training' => 1, 'age' => '', 'height' => '', 'weight' => '', 'medical_issues' => '',
        'mood' => 5, 'sleep_quality' => 5, 'hunger_craving' => 5, 'energy_level' => 5,
        'chest' => '', 'waist' => '', 'hip' => '', 'thigh' => '',
        'transaction_id' => '', 'payer_name' => '',
    ];
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>JOF India — My Dashboard</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link
        href="https://fonts.googleapis.com/css2?family=Sora:wght@600;700;800&family=Inter:wght@400;500;600;700&display=swap"
        rel="stylesheet">
    <style>
        :root {
            --bg: #F4F5F7;
            --card: #FFFFFF;
            --border: #ECEEF1;
            --ink: #1E2230;
            --ink-soft: #6B7280;
            --ink-faint: #9CA3AF;
            --coral: #FF6B47;
            --coral-dark: #E5502B;
            --coral-tint: #FFEDE7;
            --green: #1FA971;
            --green-tint: #E7F8F0;
            --amber: #F0A93A;
            --amber-tint: #FDF3E2;
            --red: #E5484D;
            --red-tint: #FCEBEC;
            --shadow: 0 1px 2px rgba(20, 20, 30, .04), 0 8px 24px -12px rgba(20, 20, 30, .08);
            --radius: 18px;
            --sidebar-w: 264px;
            --sidebar-w-collapsed: 84px;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
            min-width: 0;
        }

        html,
        body {
            max-width: 100%;
            overflow-x: hidden;
        }

        img {
            max-width: 100%;
        }

        body {
            font-family: 'Inter', sans-serif;
            background: var(--bg);
            color: var(--ink);
            -webkit-font-smoothing: antialiased;
            overflow-x: hidden;
        }

        h1,
        h2,
        h3,
        .brand-name {
            font-family: 'Sora', sans-serif;
        }

        button {
            font-family: inherit;
            cursor: pointer;
        }

        a {
            text-decoration: none;
            color: inherit;
        }

        ul {
            list-style: none;
        }

        /* ===== Layout shell ===== */
        .shell {
            display: flex;
            min-height: 100vh;
        }

        /* ===== Sidebar ===== */
        .sidebar {
            width: var(--sidebar-w);
            flex-shrink: 0;
            background: var(--card);
            border-right: 1px solid var(--border);
            display: flex;
            flex-direction: column;
            padding: 22px 16px;
            transition: width .28s ease, transform .28s ease;
            position: relative;
            z-index: 40;
        }

        .shell.collapsed .sidebar {
            width: var(--sidebar-w-collapsed);
        }

        .brand {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 6px 10px 26px 10px;
        }

        .brand-mark {
            width: 40px;
            height: 40px;
            flex-shrink: 0;
            background: #fff;
            border: 1px solid var(--border);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
        }

        .brand-mark img {
            width: 26px;
            height: 26px;
            object-fit: contain;
            display: block;
        }

        .brand-text {
            overflow: hidden;
            white-space: nowrap;
        }

        .brand-name {
            font-size: 16px;
            font-weight: 700;
            line-height: 1.15;
        }

        .brand-sub {
            font-size: 11.5px;
            color: var(--ink-soft);
            font-weight: 500;
        }

        .shell.collapsed .brand-text {
            display: none;
        }

        .nav-group {
            flex: 1;
            display: flex;
            flex-direction: column;
            gap: 2px;
            margin-top: 6px;
        }

        .nav-item {
            display: flex;
            align-items: center;
            gap: 14px;
            padding: 12px 14px;
            border-radius: 12px;
            color: var(--ink-soft);
            font-weight: 600;
            font-size: 14.5px;
            white-space: nowrap;
            overflow: hidden;
            transition: background .15s ease, color .15s ease;
        }

        .nav-item svg {
            flex-shrink: 0;
            width: 20px;
            height: 20px;
        }

        .nav-item:hover {
            background: var(--coral-tint);
            color: var(--coral-dark);
        }

        .nav-item.active {
            background: var(--coral);
            color: #fff;
        }

        .shell.collapsed .nav-label {
            display: none;
        }

        .shell.collapsed .nav-item {
            justify-content: center;
            padding: 12px;
        }

        .nav-bottom {
            border-top: 1px solid var(--border);
            padding-top: 10px;
            margin-top: 10px;
        }

        /* Sidebar rail toggle — collapses the rail (desktop) / closes the drawer (mobile) */
        .rail-toggle {
            position: absolute;
            top: 16px;
            right: 12px;
            z-index: 3;
            width: 30px;
            height: 30px;
            flex-shrink: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            border: 1px solid var(--border);
            background: var(--card);
            border-radius: 9px;
            color: var(--ink-soft);
            cursor: pointer;
            transition: background .15s ease, color .15s ease, border-color .15s ease;
        }

        .rail-toggle:hover {
            background: var(--coral-tint);
            color: var(--coral-dark);
            border-color: var(--coral);
        }

        .rail-toggle svg {
            width: 16px;
            height: 16px;
        }

        .rail-toggle .ic-close {
            display: none;
        }

        .rail-toggle .ic-collapse {
            transition: transform .28s ease;
        }

        .shell.collapsed .rail-toggle .ic-collapse {
            transform: rotate(180deg);
        }

        /* keep the brand text clear of the toggle */
        .brand {
            padding-right: 46px;
        }

        /* collapsed rail: stack the toggle above the centred brand mark */
        .shell.collapsed .brand {
            flex-direction: column;
            gap: 10px;
            padding: 50px 8px 24px;
            align-items: center;
        }

        .shell.collapsed .rail-toggle {
            top: 14px;
            right: 50%;
            transform: translateX(50%);
        }

        /* ===== Mobile drawer ===== */
        .drawer-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(20, 22, 30, .45);
            z-index: 35;
        }

        .drawer-overlay.show {
            display: block;
        }

        .hamburger {
            display: none;
            width: 40px;
            height: 40px;
            align-items: center;
            justify-content: center;
            border-radius: 10px;
            border: 1px solid var(--border);
            background: var(--card);
        }

        /* ===== Main ===== */
        .main {
            flex: 1;
            min-width: 0;
            padding: 22px 30px 60px;
        }

        .topbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 22px;
            gap: 14px;
        }

        .topbar-left {
            display: flex;
            align-items: center;
            gap: 14px;
        }

        .page-title {
            font-size: 20px;
            font-weight: 700;
        }

        .topbar-right {
            display: flex;
            align-items: center;
            gap: 14px;
        }

        .icon-btn {
            position: relative;
            width: 42px;
            height: 42px;
            border-radius: 12px;
            background: var(--card);
            border: 1px solid var(--border);
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--ink-soft);
        }

        .icon-btn:hover {
            color: var(--coral-dark);
            border-color: var(--coral);
        }

        .unread-dot {
            position: absolute;
            top: 8px;
            right: 9px;
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: var(--coral);
            border: 2px solid var(--card);
        }

        .profile-chip {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 5px 12px 5px 5px;
            border-radius: 30px;
            background: var(--card);
            border: 1px solid var(--border);
        }

        .avatar {
            width: 34px;
            height: 34px;
            border-radius: 50%;
            background: var(--coral-tint);
            color: var(--coral-dark);
            font-weight: 700;
            font-size: 13px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .profile-name {
            font-size: 13.5px;
            font-weight: 600;
            line-height: 1.1;
        }

        .profile-role {
            font-size: 11px;
            color: var(--ink-soft);
        }

        /* ===== Messages/notification dropdowns ===== */
        .shell-dropdown-container { position: relative; }
        .shell-dropdown {
            display: none;
            position: absolute;
            top: 52px;
            right: 0;
            width: 340px;
            max-width: 88vw;
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 14px;
            box-shadow: 0 18px 40px -18px rgba(20,20,30,.35);
            z-index: 60;
            overflow: hidden;
        }
        .shell-dropdown.active { display: block; }
        .shell-dropdown-head { display: flex; justify-content: space-between; align-items: center; padding: 14px 16px; border-bottom: 1px solid var(--border); font-weight: 700; font-size: 13.5px; }
        .shell-dropdown-head button { background: none; border: none; color: var(--ink-faint); cursor: pointer; }
        .shell-dropdown-list { max-height: 320px; overflow-y: auto; }
        .shell-dropdown-item { display: block; padding: 12px 16px; border-bottom: 1px solid var(--border); }
        .shell-dropdown-item:hover { background: var(--coral-tint); }
        .shell-dropdown-item .n { font-size: 13px; font-weight: 700; }
        .shell-dropdown-item .m { font-size: 12px; color: var(--ink-soft); margin-top: 2px; }
        .shell-dropdown-item .t { font-size: 10.5px; color: var(--ink-faint); margin-top: 4px; }
        .shell-dropdown-empty { padding: 30px 16px; text-align: center; color: var(--ink-faint); font-size: 12.5px; }

        /* ===== Profile-incomplete warning ===== */
        .profile-warning {
            display: flex;
            align-items: center;
            gap: 14px;
            background: var(--amber-tint);
            border: 1px solid #F3D9A6;
            border-radius: 14px;
            padding: 14px 18px;
            margin-bottom: 18px;
        }

        .pw-icon {
            width: 34px;
            height: 34px;
            border-radius: 10px;
            background: #fff;
            color: #B87814;
            flex-shrink: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 16px;
        }

        .pw-text {
            flex: 1;
            font-size: 13.5px;
            color: #7A5417;
            line-height: 1.4;
        }

        .pw-text b {
            display: block;
            font-size: 14px;
            margin-bottom: 2px;
            color: #5C3F10;
        }

        .pw-btn {
            flex-shrink: 0;
            background: var(--coral);
            color: #fff;
            font-weight: 700;
            font-size: 13px;
            padding: 10px 18px;
            border-radius: 10px;
            white-space: nowrap;
        }

        .pw-btn:hover {
            background: var(--coral-dark);
        }

        /* Incomplete profile: the one thing a new member should do first, so it's
           red, sits at the top, and gently pulses to draw the eye. */
        .profile-warning.urgent {
            background: var(--red-tint);
            border: 2px solid var(--red);
            padding: 18px 20px;
            box-shadow: 0 6px 20px rgba(229, 72, 77, .22);
            animation: pwPulse 2.4s ease-in-out infinite;
        }

        .profile-warning.urgent .pw-icon {
            width: 42px;
            height: 42px;
            background: var(--red);
            color: #fff;
            font-size: 20px;
        }

        .profile-warning.urgent .pw-text {
            color: #8A2A2D;
            font-size: 14px;
        }

        .profile-warning.urgent .pw-text b {
            font-size: 16px;
            color: #6B1618;
        }

        .profile-warning.urgent .pw-btn {
            background: var(--red);
            font-size: 14px;
            padding: 12px 22px;
        }

        .profile-warning.urgent .pw-btn:hover {
            background: #C8383D;
        }

        @keyframes pwPulse {
            0%, 100% { box-shadow: 0 6px 20px rgba(229, 72, 77, .22); }
            50% { box-shadow: 0 6px 26px rgba(229, 72, 77, .42); }
        }

        @media (prefers-reduced-motion: reduce) {
            .profile-warning.urgent { animation: none; }
        }

        @media (max-width: 560px) {
            .profile-warning {
                flex-wrap: wrap;
            }

            .pw-btn {
                width: 100%;
                text-align: center;
            }
        }

        /* ===== Welcome banner ===== */
        .welcome-card {
            background: linear-gradient(120deg, #FF7A57 0%, #FF5B39 60%, #EF4B2C 100%);
            border-radius: var(--radius);
            padding: 26px 28px;
            color: #fff;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 20px;
            margin-bottom: 20px;
            box-shadow: var(--shadow);
        }

        .welcome-left h1 {
            font-size: 23px;
            font-weight: 700;
            margin-bottom: 6px;
        }

        .welcome-left p {
            font-size: 14px;
            opacity: .92;
            max-width: 480px;
            line-height: 1.5;
        }

        .welcome-stats {
            display: flex;
            flex-shrink: 0;
        }

        .welcome-stat {
            text-align: center;
            padding: 0 30px;
        }

        .welcome-stat + .welcome-stat {
            border-left: 1px solid rgba(255, 255, 255, .28);
        }

        .welcome-stat .num .of {
            font-size: 15px;
            font-weight: 600;
            opacity: .75;
        }

        .welcome-stat .num {
            font-size: 22px;
            font-weight: 800;
            font-family: 'Sora', sans-serif;
        }

        .welcome-stat .lbl {
            font-size: 11.5px;
            opacity: .85;
            margin-top: 2px;
        }

        /* ===== Grid ===== */
        .grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 18px;
        }

        .grid .span-2 {
            grid-column: span 2;
        }

        .card {
            background: var(--card);
            border-radius: var(--radius);
            padding: 22px;
            box-shadow: var(--shadow);
            border: 1px solid var(--border);
            min-width: 0;
        }

        .card-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            row-gap: 8px;
            margin-bottom: 16px;
        }

        .card-head > div:last-child:not(:only-child) {
            display: flex;
            flex-wrap: wrap;
            justify-content: flex-end;
            row-gap: 6px;
        }

        .card-title {
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 15px;
            font-weight: 700;
        }

        .card-icon {
            width: 34px;
            height: 34px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        .card-icon svg {
            width: 18px;
            height: 18px;
        }

        .status-pill {
            font-size: 12px;
            font-weight: 700;
            padding: 5px 11px;
            border-radius: 20px;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .status-pill.green {
            background: var(--green-tint);
            color: var(--green);
        }

        .status-pill.amber {
            background: var(--amber-tint);
            color: #B87814;
        }

        .status-pill.red {
            background: var(--red-tint);
            color: var(--red);
        }

        .dot {
            width: 6px;
            height: 6px;
            border-radius: 50%;
            background: currentColor;
        }

        /* Membership card */
        .big-line {
            font-size: 26px;
            font-weight: 800;
            font-family: 'Sora', sans-serif;
            margin: 4px 0 2px;
        }

        .sub-line {
            font-size: 13.5px;
            color: var(--ink-soft);
        }

        .membership-card .big-line {
            font-size: 20px;
        }

        .membership-card .sub-line,
        .membership-card .kv-row {
            font-size: 12.5px;
        }

        .divider {
            height: 1px;
            background: var(--border);
            margin: 16px 0;
        }

        .kv-row {
            display: flex;
            justify-content: space-between;
            font-size: 13.5px;
            padding: 6px 0;
            color: var(--ink-soft);
        }

        .kv-row b {
            color: var(--ink);
            font-weight: 600;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 11px 18px;
            border-radius: 12px;
            font-weight: 700;
            font-size: 13.5px;
            border: none;
            width: 100%;
        }

        .btn-primary {
            background: var(--coral);
            color: #fff;
        }

        .btn-primary:hover {
            background: var(--coral-dark);
        }

        .btn-ghost {
            background: var(--bg);
            color: var(--ink);
            border: 1px solid var(--border);
        }

        .btn-ghost:hover {
            border-color: var(--coral);
            color: var(--coral-dark);
        }

        .btn-row {
            display: flex;
            gap: 10px;
            margin-top: 16px;
        }

        /* PT session card */
        .session-block {
            display: flex;
            align-items: center;
            gap: 14px;
            background: var(--bg);
            border-radius: 14px;
            padding: 14px;
            margin-bottom: 14px;
        }

        .session-date {
            width: 52px;
            height: 52px;
            border-radius: 12px;
            background: var(--coral);
            color: #fff;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        .session-date .d {
            font-size: 17px;
            font-weight: 800;
            line-height: 1;
        }

        .session-date .m {
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
            margin-top: 1px;
        }

        .session-info b {
            font-size: 14.5px;
            display: block;
            margin-bottom: 2px;
        }

        .session-info span {
            font-size: 13px;
            color: var(--ink-soft);
        }

        /* Diet plan card */
        .plan-tag {
            display: inline-block;
            background: var(--coral-tint);
            color: var(--coral-dark);
            font-size: 12px;
            font-weight: 700;
            padding: 4px 10px;
            border-radius: 8px;
            margin-bottom: 10px;
        }

        .meal-list {
            display: flex;
            flex-direction: column;
            gap: 10px;
            margin-top: 6px;
        }

        .meal-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            font-size: 13.5px;
        }

        .meal-row .meal-name {
            color: var(--ink-soft);
        }

        .meal-row .meal-time {
            font-weight: 600;
        }

        /* Progress card */
        .progress-ring-wrap {
            display: flex;
            align-items: center;
            gap: 20px;
        }

        .ring {
            position: relative;
            width: 96px;
            height: 96px;
            flex-shrink: 0;
        }

        .ring svg {
            transform: rotate(-90deg);
        }

        .ring-label {
            position: absolute;
            inset: 0;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
        }

        .ring-label .pct {
            font-size: 19px;
            font-weight: 800;
            font-family: 'Sora', sans-serif;
        }

        .ring-label .txt {
            font-size: 10px;
            color: var(--ink-soft);
        }

        .progress-stats {
            display: flex;
            flex-direction: column;
            gap: 8px;
            flex: 1;
        }

        .progress-stat-row {
            display: flex;
            justify-content: space-between;
            font-size: 13.5px;
        }

        .progress-stat-row span:first-child {
            color: var(--ink-soft);
        }

        .progress-stat-row span:last-child {
            font-weight: 700;
        }

        /* Payment card */
        .amount-due {
            font-size: 26px;
            font-weight: 800;
            font-family: 'Sora', sans-serif;
            color: var(--ink);
        }

        footer.note {
            text-align: center;
            font-size: 12.5px;
            color: var(--ink-faint);
            margin-top: 28px;
        }

        /* ===== Toast ===== */
        .toast-container {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 100;
            display: flex;
            flex-direction: column;
            gap: 10px;
            max-width: 340px;
        }

        .toast {
            background: var(--card);
            border: 1px solid var(--border);
            border-left: 4px solid var(--coral);
            border-radius: 14px;
            padding: 14px 16px;
            box-shadow: 0 10px 30px -8px rgba(20, 20, 30, .25);
            display: flex;
            gap: 12px;
            align-items: flex-start;
            animation: toast-in .28s cubic-bezier(.32, .72, .35, 1) forwards;
        }

        .toast.closing {
            animation: toast-out .22s ease forwards;
        }

        @keyframes toast-in {
            from {
                opacity: 0;
                transform: translateX(40px) scale(.96);
            }

            to {
                opacity: 1;
                transform: translateX(0) scale(1);
            }
        }

        @keyframes toast-out {
            to {
                opacity: 0;
                transform: translateX(40px) scale(.96);
            }
        }

        .toast-icon {
            width: 32px;
            height: 32px;
            border-radius: 9px;
            flex-shrink: 0;
            background: var(--coral-tint);
            color: var(--coral-dark);
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .toast-body b {
            font-size: 13.5px;
            display: block;
            margin-bottom: 2px;
        }

        .toast-body p {
            font-size: 12.5px;
            color: var(--ink-soft);
            line-height: 1.4;
        }

        .toast-close {
            margin-left: auto;
            background: none;
            border: none;
            color: var(--ink-faint);
            width: 20px;
            height: 20px;
            flex-shrink: 0;
        }

        /* ===== Responsive ===== */
        @media (max-width: 1080px) {
            .grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

            .grid .span-2 {
                grid-column: span 2;
            }
        }

        @media (max-width: 860px) {
            .sidebar {
                position: fixed;
                top: 0;
                left: 0;
                bottom: 0;
                transform: translateX(-100%);
                width: 270px;
            }

            .shell.drawer-open .sidebar {
                transform: translateX(0);
            }

            .shell.collapsed .sidebar {
                width: 280px;
            }

            /* the mobile drawer is always fully expanded, even if "collapsed" was left on */
            .shell.collapsed .brand {
                flex-direction: row;
                align-items: center;
                gap: 12px;
                padding: 6px 46px 26px 10px;
            }

            .shell.collapsed .brand-text,
            .shell.collapsed .nav-label {
                display: block;
            }

            .shell.collapsed .nav-item {
                justify-content: flex-start;
                padding: 12px 14px;
            }

            .rail-toggle,
            .shell.collapsed .rail-toggle {
                top: 18px;
                right: 14px;
                transform: none;
            }

            .rail-toggle .ic-collapse {
                display: none;
            }

            .rail-toggle .ic-close {
                display: block;
            }

            .hamburger {
                display: flex;
            }

            .main {
                padding: 16px 16px 50px;
            }

            .welcome-card {
                flex-direction: column;
                align-items: flex-start;
                padding: 20px;
            }

            .welcome-left h1 {
                font-size: 20px;
            }

            .welcome-stats {
                width: 100%;
                justify-content: space-between;
                gap: 10px;
            }

            .grid {
                grid-template-columns: 1fr;
            }

            .card {
                padding: 18px;
            }

            .session-block {
                padding: 12px;
                gap: 12px;
            }

            /* Bump up the smaller text sizes — desktop sizes read too small on a phone screen */
            .card-title {
                font-size: 16px;
            }

            .sub-line,
            .kv-row,
            .meal-row,
            .session-info span {
                font-size: 14.5px;
            }

            .session-info b {
                font-size: 15.5px;
            }

            .status-pill {
                font-size: 12.5px;
                padding: 6px 12px;
            }

            .btn {
                font-size: 14.5px;
                padding: 13px 18px;
            }

            .welcome-left p {
                font-size: 14.5px;
            }

            .welcome-stat .lbl {
                font-size: 12px;
            }

            .plan-tag {
                font-size: 12.5px;
            }

            .grid .span-2 {
                grid-column: span 1;
            }

            .profile-role {
                display: none;
            }

            .toast-container {
                left: 12px;
                right: 12px;
                top: 12px;
                max-width: none;
            }
        }

        @media (max-width: 640px) {
            .topbar-right {
                gap: 8px;
            }

            .icon-btn {
                width: 38px;
                height: 38px;
            }

            .profile-chip {
                padding: 4px 10px 4px 4px;
                gap: 8px;
            }

            .profile-chip .profile-name {
                max-width: 88px;
                overflow: hidden;
                text-overflow: ellipsis;
                white-space: nowrap;
            }
        }

        @media (max-width:420px) {
            .page-title {
                font-size: 17px;
            }

            .welcome-left h1 {
                font-size: 19px;
            }

            .profile-chip > div:last-child {
                display: none;
            }

            .card {
                padding: 16px;
            }

            .welcome-card {
                padding: 18px;
            }

            .welcome-stats {
                gap: 8px;
            }

            .welcome-stat .num {
                font-size: 19px;
            }

            .btn-row {
                flex-direction: column;
            }

            .big-line {
                font-size: 22px;
            }

            .membership-card .big-line {
                font-size: 18px;
            }

            .membership-card .sub-line,
            .membership-card .kv-row {
                font-size: 13px;
            }

            .amount-due {
                font-size: 22px;
            }
        }

        /* focus visibility */
        a:focus-visible,
        button:focus-visible {
            outline: 2px solid var(--coral-dark);
            outline-offset: 2px;
        }

        @media (prefers-reduced-motion: reduce) {
            * {
                animation-duration: .001s !important;
                transition-duration: .001s !important;
            }
        }

        /* Streak keeps its natural height instead of stretching to the goals card */
        #streakCard {
            align-self: start;
        }

        /* ===== Daily goals card ===== */
        .goals-card {
            padding: 28px 28px 30px;
        }
        .goals-head {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 12px;
        }
        .goals-title {
            margin: 0;
            font-size: 17px;
            font-weight: 700;
        }
        .goals-sub {
            margin: 3px 0 0;
            font-size: 13px;
            color: #718096;
        }
        .goals-add-btn {
            flex: 0 0 auto;
            width: 32px;
            height: 32px;
            border-radius: 10px;
            border: 0;
            background: var(--coral);
            color: #fff;
            display: grid;
            place-items: center;
            cursor: pointer;
            transition: background .15s, transform .15s;
        }
        .goals-add-btn svg {
            width: 16px;
            height: 16px;
        }
        .goals-add-btn:hover {
            background: var(--coral-dark);
        }
        .goals-add-btn svg {
            transition: transform .15s;
        }
        .goals-add-btn.open svg {
            transform: rotate(45deg);
        }
        .goals-add-btn[hidden] {
            display: none;
        }

        /* One-line week calendar, newest day on the left */
        .goals-week {
            display: flex;
            align-items: center;
            gap: 6px;
            margin-top: 26px;
        }
        .goals-days {
            flex: 1;
            min-width: 0;
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 10px;
        }
        .goals-day {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 2px;
            min-width: 0;
            padding: 12px 2px;
            border: 1px solid #E2E8F0;
            border-radius: 12px;
            background: #fff;
            color: inherit;
            cursor: pointer;
            font: inherit;
            transition: border-color .15s, background .15s;
        }
        .goals-day:hover {
            border-color: #B7C9F2;
        }
        .goals-day-label {
            max-width: 100%;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            font-size: 10px;
            font-weight: 600;
            color: #718096;
            text-transform: uppercase;
            letter-spacing: 0;
        }
        .goals-day-num {
            white-space: nowrap;
            font-size: 15px;
            font-weight: 700;
        }
        .goals-day-stat {
            font-size: 11px;
            color: #A0AEC0;
            min-height: 14px;
        }
        .goals-day.has-all .goals-day-stat {
            color: #1F8A5B;
            font-weight: 600;
        }
        .goals-day.selected {
            background: var(--coral);
            border-color: var(--coral);
            color: #fff;
        }
        .goals-day.selected .goals-day-label,
        .goals-day.selected .goals-day-stat {
            color: rgba(255, 255, 255, .85);
        }
        .goals-nav {
            flex: 0 0 auto;
            width: 30px;
            height: 30px;
            border-radius: 50%;
            border: 1px solid #E2E8F0;
            background: #fff;
            color: #4A5568;
            display: grid;
            place-items: center;
            cursor: pointer;
        }
        .goals-nav svg {
            width: 14px;
            height: 14px;
        }
        .goals-nav:disabled {
            opacity: .35;
            cursor: default;
        }

        .goals-summary {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            flex-direction: column;
            align-items: stretch;
            margin-top: 28px;
            padding-top: 22px;
            border-top: 1px solid #EDF2F7;
        }
        .goals-date {
            font-size: 14px;
            font-weight: 600;
        }
        .goals-progress {
            display: flex;
            width: 100%;
            align-items: center;
            gap: 10px;
        }
        .goals-bar {
            flex: 1;
            min-width: 0;
            height: 6px;
            border-radius: 99px;
            background: #EDF2F7;
            overflow: hidden;
        }
        .goals-bar span {
            display: block;
            height: 100%;
            width: 0;
            border-radius: 99px;
            background: #22A06B;
            transition: width .3s ease;
        }
        .goals-count {
            font-size: 13px;
            color: #4A5568;
            white-space: nowrap;
        }

        .goals-add-row {
            display: flex;
            gap: 8px;
            margin-top: 22px;
        }
        .goals-add-row[hidden] {
            display: none;
        }
        .goals-add-row {
            flex-direction: column;
            align-items: stretch;
        }
        .goals-add-row textarea {
            width: 100%;
            min-height: 84px;
            resize: vertical;
            padding: 10px 14px;
            line-height: 1.45;
            border: 1px solid #CBD5E0;
            border-radius: 10px;
            font: inherit;
            font-size: 14px;
            background: #fff;
            color: inherit;
        }
        .goals-add-row textarea:focus {
            outline: none;
            border-color: var(--coral);
            box-shadow: 0 0 0 3px rgba(255, 107, 69, .18);
        }
        .goals-submit {
            align-self: flex-end;
            width: auto;
            padding: 7px 18px;
            border: 0;
            border-radius: 9px;
            background: var(--coral);
            color: #fff;
            font: inherit;
            font-size: 13px;
            font-weight: 700;
            cursor: pointer;
        }
        .goals-submit:hover {
            background: var(--coral-dark);
        }
        .goals-submit[disabled] {
            opacity: .6;
            cursor: default;
        }

        .goals-list {
            list-style: none;
            margin: 20px 0 0;
            display: flex;
            flex-direction: column;
            gap: 12px;
            max-height: 300px;
            overflow-y: auto;
            padding: 0 4px 0 0;
        }
        .goal-row {
            display: flex;
            align-items: center;
            gap: 14px;
            padding: 15px 16px;
            border: 1px solid #EDF2F7;
            border-radius: 12px;
            background: #fff;
            font-size: 14px;
        }
        .goal-row:hover .goal-del {
            opacity: 1;
        }
        .goal-check {
            flex: 0 0 22px;
            width: 22px;
            height: 22px;
            border-radius: 50%;
            border: 2px solid #CBD5E0;
            background: #fff;
            color: transparent;
            padding: 0;
            display: grid;
            place-items: center;
            cursor: pointer;
        }
        .goal-check svg {
            width: 12px;
            height: 12px;
        }
        .goal-check[aria-checked="true"] {
            background: #22A06B;
            border-color: #22A06B;
            color: #fff;
        }
        .goal-check:disabled {
            cursor: default;
        }
        .goal-text {
            flex: 1;
            min-width: 0;
            white-space: pre-line;
            overflow-wrap: anywhere;
        }
        .goal-row.done .goal-text {
            color: #718096;
            text-decoration: line-through;
        }
        .goal-del {
            flex: 0 0 auto;
            border: 0;
            background: transparent;
            color: #A0AEC0;
            font-size: 18px;
            line-height: 1;
            padding: 2px 6px;
            cursor: pointer;
            opacity: .45;
        }
        .goal-del:hover {
            color: #E53E3E;
            opacity: 1;
        }
        .goal-del[hidden] {
            display: none;
        }
        .goals-empty {
            margin-top: 14px;
            padding: 22px;
            border: 1px dashed #CBD5E0;
            border-radius: 12px;
            text-align: center;
            font-size: 14px;
            color: #718096;
        }

        @media (max-width: 640px) {
            .goals-card {
                padding: 20px 16px 22px;
            }
            .goals-nav {
                width: 26px;
                height: 26px;
            }
        }

        /* ===== Workout streak card ===== */
        .streak-top {
            display: flex;
            align-items: center;
            gap: 22px;
            flex-wrap: wrap;
            margin-bottom: 18px;
        }

        .streak-flame {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .streak-flame .fl {
            width: 46px;
            height: 46px;
            border-radius: 14px;
            background: linear-gradient(155deg, var(--coral), var(--coral-dark));
            display: flex;
            align-items: center;
            justify-content: center;
            color: #fff;
            flex-shrink: 0;
        }

        .streak-flame .fl svg {
            width: 22px;
            height: 22px;
        }

        .streak-num {
            font-family: 'Sora', sans-serif;
            font-size: 40px;
            font-weight: 800;
            line-height: 1;
            color: var(--coral-dark);
        }

        .streak-cap {
            font-size: 12.5px;
            color: var(--ink-soft);
            margin-top: 3px;
        }

        .streak-log-btn {
            margin-left: auto;
            display: inline-flex;
            align-items: center;
            gap: 9px;
            padding: 12px 20px;
            border: none;
            border-radius: 14px;
            background: var(--coral);
            color: #fff;
            font-weight: 700;
            font-size: 14px;
            cursor: pointer;
            transition: background .18s ease, transform .18s ease;
        }

        .streak-log-btn:hover {
            background: var(--coral-dark);
            transform: translateY(-1px);
        }

        .streak-log-btn:disabled {
            opacity: .6;
            cursor: default;
            transform: none;
        }

        .streak-log-btn.done {
            background: var(--green-tint);
            color: var(--green);
        }

        .streak-log-btn svg {
            width: 16px;
            height: 16px;
        }

        .streak-strip {
            display: flex;
            width: 100%;
            max-width: 100%;
            gap: 5px;
            overflow-x: auto;
            padding-bottom: 4px;
            margin-bottom: 18px;
            -webkit-overflow-scrolling: touch;
        }

        .sd {
            position: relative;
            flex: 1 0 32px;
            min-width: 32px;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 6px;
            padding: 6px 0;
            border: none;
            background: transparent;
            border-radius: 10px;
            cursor: pointer;
            transition: background .15s ease;
        }

        .sd-note-btn {
            position: absolute;
            top: 2px;
            right: 2px;
            width: 15px;
            height: 15px;
            border-radius: 50%;
            background: #fff;
            border: 1.5px solid var(--border);
            font-size: 8.5px;
            line-height: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--ink-faint);
            cursor: pointer;
        }

        .sd.has-note .sd-note-btn {
            border-color: var(--coral);
            color: var(--coral-dark);
            background: var(--coral-tint);
        }

        /* Note editor modal */
        .note-modal-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(20, 20, 30, .45);
            z-index: 4000;
            align-items: center;
            justify-content: center;
            padding: 16px;
        }

        .note-modal-overlay.open {
            display: flex;
        }

        .note-modal {
            background: var(--card);
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            width: 100%;
            max-width: 360px;
            padding: 20px;
        }

        .note-modal h4 {
            font-size: 14.5px;
            font-weight: 700;
            margin-bottom: 10px;
        }

        .note-modal h4 span {
            color: var(--coral-dark);
        }

        .note-modal textarea {
            width: 100%;
            resize: none;
            border: 1.5px solid var(--border);
            border-radius: 10px;
            padding: 10px 12px;
            font: inherit;
            font-size: 13.5px;
            color: var(--ink);
        }

        .note-modal textarea:focus {
            outline: none;
            border-color: var(--coral);
        }

        .note-modal-acts {
            display: flex;
            justify-content: flex-end;
            gap: 8px;
            margin-top: 14px;
        }

        .note-modal-cancel,
        .note-modal-save {
            border: none;
            border-radius: 10px;
            padding: 9px 16px;
            font: inherit;
            font-size: 13px;
            font-weight: 700;
            cursor: pointer;
        }

        .note-modal-cancel {
            background: var(--bg);
            color: var(--ink-soft);
        }

        .note-modal-save {
            background: var(--coral);
            color: #fff;
        }

        .note-modal-save:hover {
            background: var(--coral-dark);
        }

        .sd:hover {
            background: var(--bg);
        }

        .sd-dow {
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
            color: var(--ink-faint);
        }

        .sd-dot {
            width: 22px;
            height: 22px;
            border-radius: 50%;
            border: 2px solid var(--border);
            background: #fff;
            transition: background .15s ease, border-color .15s ease, box-shadow .15s ease;
        }

        .sd.on .sd-dot {
            background: var(--coral);
            border-color: var(--coral);
        }

        .sd.is-today .sd-dot {
            box-shadow: 0 0 0 3px var(--coral-tint);
        }

        .sd.is-today .sd-day {
            color: var(--coral-dark);
            font-weight: 700;
        }

        .sd-day {
            font-size: 11px;
            font-weight: 600;
            color: var(--ink-soft);
        }

        .streak-stats {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 12px;
        }

        .streak-stats>div {
            background: var(--bg);
            border-radius: 12px;
            padding: 12px 10px;
            text-align: center;
        }

        .streak-stats span {
            display: block;
            font-family: 'Sora', sans-serif;
            font-size: 18px;
            font-weight: 800;
            color: var(--ink);
        }

        .streak-stats small {
            font-size: 10.5px;
            color: var(--ink-soft);
        }

        @media (max-width: 560px) {
            .streak-stats {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

            .streak-log-btn {
                margin-left: 0;
            }
        }

        @media (max-width: 860px) {
            .streak-cap {
                font-size: 13.5px;
            }

            .streak-log-btn {
                font-size: 14.5px;
                padding: 13px 20px;
            }

            .streak-stats small {
                font-size: 11.5px;
            }

            .streak-stats span {
                font-size: 19px;
            }

            .sd-day {
                font-size: 12px;
            }

            .sd-dow {
                font-size: 10.5px;
            }

            .sd-dot {
                width: 26px;
                height: 26px;
            }

            .sd {
                flex-basis: 36px;
                min-width: 36px;
            }
        }

        @media (max-width: 420px) {
            .streak-num {
                font-size: 32px;
            }
        }
    </style>
    <link rel="stylesheet" href="_sidebar_theme.css?v=3">
</head>

<body>

    <div class="shell" id="shell">

        <div class="drawer-overlay" id="overlay"></div>

        <!-- SIDEBAR -->
        <aside class="sidebar" id="sidebar">
            <button class="rail-toggle" id="railToggle" type="button" aria-label="Toggle sidebar">
                <svg class="ic-collapse" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"
                    stroke-linecap="round" stroke-linejoin="round">
                    <path d="M15 18l-6-6 6-6" />
                </svg>
                <svg class="ic-close" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"
                    stroke-linecap="round" stroke-linejoin="round">
                    <path d="M6 6l12 12M18 6L6 18" />
                </svg>
            </button>
            <div class="brand">
                <div class="brand-mark"><img src="../../icons/logo-dark(1).png" alt="JOF logo"></div>
                <div class="brand-text">
                    <div class="brand-name">JOF India</div>
                    <div class="brand-sub">Member Portal</div>
                </div>
            </div>

            <nav class="nav-group" data-tour="nav">
                <a class="nav-item active" href="user_dashboard.php">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                        stroke-linejoin="round">
                        <rect x="3" y="3" width="7" height="9" rx="1.5" />
                        <rect x="14" y="3" width="7" height="5" rx="1.5" />
                        <rect x="14" y="12" width="7" height="9" rx="1.5" />
                        <rect x="3" y="16" width="7" height="5" rx="1.5" />
                    </svg>
                    <span class="nav-label">Dashboard</span>
                </a>
                <a class="nav-item" href="user_profile.php">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                        stroke-linejoin="round">
                        <circle cx="12" cy="8" r="4" />
                        <path d="M4 21c0-4.4 3.6-8 8-8s8 3.6 8 8" />
                    </svg>
                    <span class="nav-label">My Profile</span>
                    <?php if ($profile_incomplete): ?><span style="margin-left:auto;width:8px;height:8px;border-radius:50%;background:#FF6B47;flex-shrink:0;"></span><?php endif; ?>
                </a>
                <a class="nav-item" href="user_membership.php">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                        stroke-linejoin="round">
                        <rect x="3" y="5" width="18" height="14" rx="2" />
                        <path d="M3 10h18" />
                    </svg>
                    <span class="nav-label">Membership</span>
                </a>
                <a class="nav-item" href="user_diet_plans.php">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                        stroke-linejoin="round">
                        <path d="M4 3h12l4 4v14H4z" />
                        <path d="M9 8h6M9 12h6M9 16h4" />
                    </svg>
                    <span class="nav-label">Diet Plan</span>
                </a>
                <a class="nav-item" href="user_payments.php">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                        stroke-linejoin="round">
                        <path d="M4 4h16v16H4z" />
                        <path d="M8 9h8M8 13h8M8 17h4" />
                    </svg>
                    <span class="nav-label">Payments &amp; Invoices</span>
                </a>
            </nav>

            <div class="nav-bottom">
                <a class="nav-item" href="#" data-tour-restart>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                        stroke-linejoin="round">
                        <circle cx="12" cy="12" r="9" />
                        <path d="M9.6 9.3a2.5 2.5 0 014.7 1.1c0 1.7-2.4 2-2.4 3.4" />
                        <path d="M12 17h.01" />
                    </svg>
                    <span class="nav-label">Take a tour</span>
                </a>
                <a class="nav-item" href="../../auth/logout.php">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                        stroke-linejoin="round">
                        <path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4" />
                        <path d="M16 17l5-5-5-5" />
                        <path d="M21 12H9" />
                    </svg>
                    <span class="nav-label">Logout</span>
                </a>
            </div>
        </aside>

        <!-- MAIN -->
        <main class="main">
            <div class="topbar">
                <div class="topbar-left">
                    <button class="hamburger" id="hamburgerBtn" aria-label="Open menu" data-tour="menu-btn">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                            stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M4 7h16M4 12h16M4 17h16" />
                        </svg>
                    </button>
                    <div class="page-title">Dashboard</div>
                </div>
                <div class="topbar-right">
                    <div class="shell-dropdown-container">
                        <button class="icon-btn" id="msgBellBtn" aria-label="Messages" title="Messages from your trainer" data-tour="messages">
                            <svg width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z" />
                            </svg>
                            <span class="unread-dot" id="msgUnreadDot" style="display:none;"></span>
                        </button>
                        <div class="shell-dropdown" id="msgDropdown">
                            <div class="shell-dropdown-head">
                                <span>Messages</span>
                                <button type="button" id="msgDropdownClose">✕</button>
                            </div>
                            <div class="shell-dropdown-list" id="msgDropdownList">
                                <div class="shell-dropdown-empty">Loading…</div>
                            </div>
                        </div>
                    </div>
                    <a class="profile-chip" href="user_profile.php" style="text-decoration:none;color:inherit;">
                        <?php if ($pfp_url): ?>
                            <div class="avatar" style="padding:0;overflow:hidden;"><img src="<?= htmlspecialchars($pfp_url) ?>" alt="" style="width:100%;height:100%;object-fit:cover;"></div>
                        <?php else: ?>
                            <div class="avatar"><?= htmlspecialchars($u_initials) ?></div>
                        <?php endif; ?>
                        <div>
                            <div class="profile-name"><?= htmlspecialchars($u_name) ?></div>
                            <div class="profile-role"><?= $profile_incomplete ? 'Profile incomplete' : 'Member' ?></div>
                        </div>
                    </a>
                </div>
            </div>

            <?php if ($profile_incomplete): ?>
                <div class="profile-warning urgent" role="alert">
                    <div class="pw-icon">⚠</div>
                    <div class="pw-text">
                        <b>Your profile is incomplete.</b>
                        Please complete it now so your trainer can set up the right plan for you.
                    </div>
                    <a class="pw-btn" href="user_profile.php">Complete Profile Now</a>
                </div>
            <?php endif; ?>

            <?php if ($unpaid_dues['has_overdue']): ?>
                <div class="profile-warning" style="background:var(--red-tint);border-color:#F3B9BB;" role="alert">
                    <div class="pw-icon" style="color:var(--red);">₹</div>
                    <div class="pw-text" style="color:#8A2A2D;">
                        <b style="color:#6B1618;">
                            You have ₹<?= number_format($unpaid_dues['overdue_total']) ?> overdue<?= $unpaid_dues['count'] > 1 ? ' across ' . (int) $unpaid_dues['count'] . ' plans' : '' ?>.
                        </b>
                        <?= $unpaid_dues['overdue_since'] ? 'Due since ' . htmlspecialchars(date('d M Y', strtotime($unpaid_dues['overdue_since']))) . '. ' : '' ?>Clear it to keep subscribing to new plans.
                    </div>
                    <a class="pw-btn" href="user_payments.php" style="background:var(--red);">View Invoices</a>
                </div>
            <?php endif; ?>

            <?php if ($unpaid_dues['has_upcoming'] ?? false):
                // Shown independently of the overdue notice above, so a member with one plan
                // overdue and another coming due sees both. Shortened when the red one is also up.
                $days_to_due = (int) floor((strtotime($unpaid_dues['upcoming_due']) - strtotime('today')) / 86400);
                $due_phrase  = $days_to_due <= 0 ? 'today' : ('in ' . $days_to_due . ' day' . ($days_to_due === 1 ? '' : 's'));
            ?>
                <div class="profile-warning" style="background:var(--amber-tint);border-color:#F0D9A6;" role="alert">
                    <div class="pw-icon" style="color:#B87814;">₹</div>
                    <div class="pw-text" style="color:#7A5417;">
                        <b style="color:#5C3F10;">
                            Next payment of ₹<?= number_format($unpaid_dues['upcoming_total']) ?> due <?= $due_phrase ?>
                        </b>
                        (<?= htmlspecialchars(date('d M Y', strtotime($unpaid_dues['upcoming_due']))) ?>)<?= $unpaid_dues['has_overdue'] ? '.' : '. Pay now to avoid falling behind.' ?>
                    </div>
                    <a class="pw-btn" href="user_payments.php" style="background:#B87814;">View Invoices</a>
                </div>
            <?php endif; ?>

            <?php if ($membership_pause): ?>
                <div class="profile-warning" style="background:var(--amber-tint);border-color:#F0D9A6;">
                    <div class="pw-icon" style="color:#B87814;">⏸</div>
                    <div class="pw-text" style="color:#7A5A12;">
                        <b style="color:#5C4409;">Your membership is currently paused.</b>
                        It's paused until <?= htmlspecialchars(date('d M Y', strtotime($membership_pause['pause_end']))) ?>. Your plan will automatically resume after that.
                    </div>
                </div>
            <?php elseif ($membership_extension && $membership_status !== 'expired'): ?>
                <div class="profile-warning" style="background:#F0F9FF;border-color:#BAE6FD;">
                    <div class="pw-icon" style="color:#0284C7;">
                        <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="17" rx="2"/><path d="M16 2v4M8 2v4M3 10h18M12 13v5M9.5 15.5h5"/></svg>
                    </div>
                    <div class="pw-text" style="color:#075985;">
                        <b style="color:#0C4A6E;">Your membership has been extended by <?= (int) $membership_extension['days'] ?> day<?= (int) $membership_extension['days'] === 1 ? '' : 's' ?>.</b>
                        It's now valid until <?= htmlspecialchars(date('d M Y', strtotime($membership_info['valid_until'] ?: $membership_extension['end_date_after']))) ?><?= $membership_days_remaining !== null ? ' — ' . (int) $membership_days_remaining . ' day' . ($membership_days_remaining == 1 ? '' : 's') . ' left' : '' ?>.
                    </div>
                    <a class="pw-btn" href="user_membership.php" style="background:#0284C7;">View Plan</a>
                </div>
            <?php elseif ($membership_status === 'expiring' || $membership_status === 'expired'): ?>
                <div class="profile-warning" style="<?= $membership_status === 'expired' ? 'background:var(--red-tint);border-color:#F3B9BB;' : '' ?>">
                    <div class="pw-icon" style="<?= $membership_status === 'expired' ? 'color:var(--red);' : '' ?>">⏰</div>
                    <div class="pw-text" style="<?= $membership_status === 'expired' ? 'color:#8A2A2D;' : '' ?>">
                        <b style="<?= $membership_status === 'expired' ? 'color:#6B1618;' : '' ?>">
                            <?= $membership_status === 'expired'
                                ? 'Your membership has expired.'
                                : 'Your membership is expiring in ' . (int) $membership_days_remaining . ' day' . ($membership_days_remaining == 1 ? '' : 's') . '.' ?>
                        </b>
                        <?= $membership_status === 'expired'
                            ? 'It expired ' . htmlspecialchars(date('d M Y', strtotime($membership_info['valid_until']))) . '. Renew now to keep access to your plans and sessions.'
                            : 'It ends on ' . htmlspecialchars(date('d M Y', strtotime($membership_info['valid_until']))) . '. Renew now to avoid any interruption.' ?>
                    </div>
                    <a class="pw-btn" href="user_membership.php" style="<?= $membership_status === 'expired' ? 'background:var(--red);' : '' ?>">Renew Now</a>
                </div>
            <?php endif; ?>

            <!-- Welcome banner -->
            <?php
            $firstName = trim(explode(' ', html_entity_decode($user['name'], ENT_QUOTES))[0]) ?: 'there';
            $wkMsg = $streak['current_streak'] > 0
                ? "You're on a {$streak['current_streak']}-day streak — keep it going!"
                : ($streak['total_workouts'] > 0
                    ? "Your streak reset. Log today's workout to start a new one."
                    : "Log your first workout to start building a streak.");
            ?>
            <div class="welcome-card">
                <div class="welcome-left">
                    <h1>Welcome back, <?= htmlspecialchars($firstName, ENT_QUOTES, 'UTF-8') ?></h1>
                    <p><?= htmlspecialchars($wkMsg, ENT_QUOTES, 'UTF-8') ?> You've worked out
                        <?= (int) $streak['this_week'] ?> day<?= $streak['this_week'] == 1 ? '' : 's' ?> in the last week.
                    </p>
                </div>
                <div class="welcome-stats" data-tour="welcome-stats">
                    <div class="welcome-stat">
                        <div class="num" id="wsStreak"><?= (int) $streak['current_streak'] ?></div>
                        <div class="lbl">Day streak</div>
                    </div>
                    <div class="welcome-stat">
                        <div class="num" id="wsTotal"><?= (int) $streak['total_workouts'] ?></div>
                        <div class="lbl">Workouts done</div>
                    </div>
                    <div class="welcome-stat">
                        <div class="num"><span id="wsGoalsDone"><?= (int) $goals_stats['achieved'] ?></span><span class="of">/<span id="wsGoalsSet"><?= (int) $goals_stats['total'] ?></span></span></div>
                        <div class="lbl">Goals achieved</div>
                    </div>
                </div>
            </div>

            <!-- Grid -->
            <div class="grid">

                <!-- Membership -->
                <div class="card membership-card" data-tour="membership-card">
                    <div class="card-head">
                        <div class="card-title">
                            <div class="card-icon" style="background:var(--coral-tint); color:var(--coral-dark);">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                    stroke-linecap="round" stroke-linejoin="round">
                                    <rect x="3" y="5" width="18" height="14" rx="2" />
                                    <path d="M3 10h18" />
                                </svg>
                            </div>
                            Membership
                        </div>
                        <div style="display:flex; align-items:center; gap:6px;">
                            <?php if ($pending_plan_request): ?>
                                <span class="status-pill" style="background:#EEF2FF; color:#4338CA;"><span class="dot" style="background:#4338CA;"></span>Pending Setup</span>
                            <?php elseif ($membership_pause): ?>
                                <span class="status-pill amber"><span class="dot"></span>Paused</span>
                            <?php elseif ($membership_extension && ($membership_status === 'active' || $membership_status === 'expiring')): ?>
                                <span class="status-pill" style="background:#E0F2FE; color:#0369A1;"><span class="dot" style="background:#0EA5E9;"></span>Extended</span>
                            <?php elseif ($membership_status === 'active'): ?>
                                <span class="status-pill green"><span class="dot"></span>Active</span>
                            <?php elseif ($membership_status === 'expiring'): ?>
                                <span class="status-pill amber"><span class="dot"></span>Expiring Soon</span>
                            <?php elseif ($membership_status === 'expired'): ?>
                                <span class="status-pill red"><span class="dot"></span>Expired</span>
                            <?php else: ?>
                                <span class="status-pill amber"><span class="dot"></span>Inactive</span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <?php if ($pending_plan_request): ?>
                        <?php /* STATE 1: Pending Setup - new plan requested, awaiting admin confirmation */ ?>
                        <div class="big-line" style="color:#4338CA;">
                            <?= $pending_plan_request['plan_name'] !== '' ? htmlspecialchars($pending_plan_request['plan_name']) : 'New Plan' ?>
                        </div>
                        <div class="sub-line" style="display:flex;align-items:center;gap:6px;">
                            <svg viewBox="0 0 20 20" fill="none" stroke="#4338CA" stroke-width="1.6" width="14" height="14" style="flex-shrink:0;"><circle cx="10" cy="10" r="8"/><path d="M10 6v4l2.5 2.5" stroke-linecap="round"/></svg>
                            Awaiting admin confirmation
                        </div>
                        <div class="divider"></div>
                        <div class="kv-row" style="font-size:12px;color:var(--muted);">
                            <span>Requested on</span>
                            <b><?= htmlspecialchars(date('d M Y', strtotime($latest_payment_any['created_at']))) ?></b>
                        </div>
                        <?php if ($current_plan_name !== ''): ?>
                            <div class="divider" style="margin-top:10px;"></div>
                            <div style="font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.05em;color:var(--muted);margin-bottom:6px;">Previous Plan</div>
                            <div class="kv-row" style="font-size:12px;">
                                <span><?= htmlspecialchars($current_plan_name) ?></span>
                                <?php if ($membership_info['valid_until']): ?>
                                    <b style="color:<?= $membership_status === 'expired' ? 'var(--red)' : 'inherit' ?>;">
                                        <?= $membership_status === 'expired' ? 'Expired ' : 'Until ' ?>
                                        <?= htmlspecialchars(date('d M Y', strtotime($membership_info['valid_until']))) ?>
                                    </b>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                        <div class="btn-row">
                            <button class="btn btn-ghost" onclick="location.href='user_payments.php'">View Invoices</button>
                        </div>

                    <?php elseif ($membership_status === 'expired'): ?>
                        <?php /* STATE 2: Expired, no pending renewal - show "Plan Expired" as hero */ ?>
                        <div class="big-line" style="color:var(--red);">Plan Expired</div>
                        <div class="sub-line" style="display:flex;align-items:center;gap:6px;">
                            <svg viewBox="0 0 20 20" fill="none" stroke="var(--red)" stroke-width="1.6" width="14" height="14" style="flex-shrink:0;"><circle cx="10" cy="10" r="8"/><path d="M10 6v4" stroke-linecap="round"/><circle cx="10" cy="14" r=".5" fill="var(--red)" stroke="none"/></svg>
                            <?php if ($membership_info['valid_until']): ?>
                                Expired on <?= htmlspecialchars(date('d M Y', strtotime($membership_info['valid_until']))) ?>
                                &middot; <?= abs($membership_days_remaining) ?> day<?= abs($membership_days_remaining) !== 1 ? 's' : '' ?> ago
                            <?php else: ?>
                                Your membership has ended.
                            <?php endif; ?>
                        </div>
                        <?php if ($current_plan_name !== ''): ?>
                            <div class="divider"></div>
                            <div style="font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.05em;color:var(--muted);margin-bottom:6px;">Previous Plan</div>
                            <div class="kv-row" style="font-size:13px;">
                                <span><?= htmlspecialchars($current_plan_name) ?></span>
                                <?php if (!empty($latest_payment['start_date'])): ?>
                                    <b style="color:var(--muted);font-weight:500;"><?= htmlspecialchars(date('d M Y', strtotime($latest_payment['start_date']))) ?></b>
                                <?php endif; ?>
                            </div>
                            <?php if ($membership_extension): ?>
                                <div class="kv-row" style="font-size:12px;"><span>Extended on</span><b><?= htmlspecialchars(date('d M Y', strtotime($membership_extension['created_at']))) ?></b></div>
                            <?php endif; ?>
                        <?php endif; ?>
                        <div class="btn-row">
                            <button class="btn btn-primary" onclick="location.href='user_membership.php'">Renew Membership</button>
                        </div>

                    <?php elseif ($current_plan_name !== ''): ?>
                        <?php /* STATE 3: Active / Expiring / Paused / Extended - original behaviour */ ?>
                        <div class="big-line"><?= htmlspecialchars($current_plan_name) ?></div>
                        <div class="sub-line">
                            <?= $membership_info['valid_until']
                                ? 'Valid until ' . htmlspecialchars(date('d M Y', strtotime($membership_info['valid_until'])))
                                    . ($membership_extension ? ' &middot; includes your extension' : '')
                                : 'No expiry on record' ?>
                        </div>
                        <div class="divider"></div>
                        <?php if (!empty($latest_payment['start_date'])): ?>
                            <div class="kv-row"><span>Member since</span><b><?= htmlspecialchars(date('d M Y', strtotime($latest_payment['start_date']))) ?></b></div>
                        <?php endif; ?>
                        <?php if ($membership_extension): ?>
                            <div class="kv-row"><span>Extended on</span><b><?= htmlspecialchars(date('d M Y', strtotime($membership_extension['created_at']))) ?></b></div>
                            <div class="kv-row"><span>Extra days added</span><b style="color:#0369A1;">+<?= (int) $membership_extension['days'] ?> days</b></div>
                        <?php endif; ?>
                        <?php if ($membership_days_remaining !== null): ?>
                            <div class="kv-row">
                                <span><?= $membership_status === 'expired' ? 'Days overdue' : 'Days remaining' ?></span>
                                <b><?= abs($membership_days_remaining) ?> days</b>
                            </div>
                        <?php endif; ?>
                        <div class="btn-row">
                            <button class="btn btn-primary" onclick="location.href='user_membership.php'">View Membership</button>
                        </div>

                    <?php else: ?>
                        <?php /* STATE 4: No plan at all */ ?>
                        <div class="big-line">No plan yet</div>
                        <div class="sub-line">Talk to your trainer to get started.</div>
                        <div class="btn-row">
                            <button class="btn btn-primary" onclick="location.href='user_membership.php'">Explore Plans</button>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- PT Session -->
                <div class="card">
                    <div class="card-head">
                        <div class="card-title">
                            <div class="card-icon" style="background:var(--green-tint); color:var(--green);">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                    stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M6.5 6.5l11 11M4 9l3-3 3 3-3 3zM17 22l-3-3 3-3 3 3z" />
                                </svg>
                            </div>
                            Upcoming PT Session
                        </div>
                        <?php if ($next_pt_session): ?>
                            <span class="status-pill green"><span class="dot"></span><?= htmlspecialchars($next_pt_session['status']) ?></span>
                        <?php endif; ?>
                    </div>
                    <?php if ($next_pt_session):
                        $__pt_ts = strtotime($next_pt_session['session_date']);
                        $__pt_days = (int) floor((strtotime($next_pt_session['session_date']) - strtotime('today')) / 86400);
                        $__pt_when = $__pt_days === 0 ? 'Today' : ($__pt_days === 1 ? 'Tomorrow' : "In {$__pt_days} days");
                    ?>
                        <div class="session-block">
                            <div class="session-date">
                                <div class="d"><?= date('d', $__pt_ts) ?></div>
                                <div class="m"><?= date('M', $__pt_ts) ?></div>
                            </div>
                            <div class="session-info">
                                <b><?= htmlspecialchars($next_pt_session['notes'] ?: 'Personal Training Session') ?></b>
                                <span><?= htmlspecialchars(date('g:i A', strtotime($next_pt_session['session_time']))) ?> · with <?= htmlspecialchars($next_pt_session['trainer_name']) ?></span>
                            </div>
                        </div>
                        <div class="sub-line"><?= $__pt_days === 0 ? 'Your session is today' : 'Next session ' . strtolower($__pt_when) ?></div>
                    <?php else: ?>
                        <div class="sub-line" style="margin-top:6px;">No upcoming PT sessions scheduled.</div>
                        <div class="btn-row">
                            <button class="btn btn-ghost" onclick="location.href='user_membership.php'">Explore PT Plans</button>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Payment status -->
                <div class="card" data-tour="payment-card">
                    <div class="card-head">
                        <div class="card-title">
                            <div class="card-icon" style="background:var(--amber-tint); color:#B87814;">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                    stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M4 4h16v16H4z" />
                                    <path d="M8 9h8M8 13h8M8 17h4" />
                                </svg>
                            </div>
                            Payment Status
                        </div>
                        <?php if (!$latest_payment_any): ?>
                            <span class="status-pill amber"><span class="dot"></span>No Record</span>
                        <?php elseif ($latest_payment_any['membership_type'] === 'Pending Setup'): ?>
                            <span class="status-pill" style="background:#EEF2FF; color:#4338CA;"><span class="dot"></span>Pending Verification</span>
                        <?php elseif ((float) $latest_payment_any['balance_pending'] > 0): ?>
                            <span class="status-pill amber"><span class="dot"></span>Balance Due</span>
                        <?php else: ?>
                            <span class="status-pill green"><span class="dot"></span>Paid in Full</span>
                        <?php endif; ?>
                    </div>
                    <?php if (!$latest_payment_any): ?>
                        <div class="sub-line" style="margin-top:6px;">No payments on record yet.</div>
                        <div class="btn-row">
                            <button class="btn btn-primary" onclick="location.href='user_membership.php'">Explore Plans</button>
                        </div>
                    <?php elseif ($latest_payment_any['membership_type'] === 'Pending Setup'): ?>
                        <div class="big-line">Awaiting Verification</div>
                        <div class="sub-line">Requested on <?= htmlspecialchars(date('d M Y', strtotime($latest_payment_any['created_at']))) ?> · Your trainer will confirm it shortly.</div>
                        <div class="btn-row">
                            <button class="btn btn-primary" onclick="location.href='user_payments.php'">View Invoices</button>
                        </div>
                    <?php else:
                        $__balance = (float) $latest_payment_any['balance_pending'];
                        $__total   = (float) $latest_payment_any['total_amount'];
                        $__received = (float) $latest_payment_any['amount_received'];
                    ?>
                        <div class="amount-due">₹<?= number_format($__balance, 0) ?></div>
                        <div class="sub-line">
                            <?= $__balance > 0 ? 'Balance pending on ' . htmlspecialchars($latest_payment_any['membership_type']) : 'No balance due' ?>
                        </div>
                        <div class="divider"></div>
                        <div class="kv-row"><span>Last payment</span><b>₹<?= number_format($__received, 0) ?> · <?= htmlspecialchars(date('d M Y', strtotime($latest_payment_any['start_date'] ?? $latest_payment_any['created_at']))) ?></b></div>
                        <?php
                        // Installment plans only — a one-shot payment has no "next due date" to show
                        $__is_installment = (int) ($latest_payment_any['installments_count'] ?? 1) > 1;
                        if ($__is_installment && $__balance > 0 && !empty($latest_payment_any['next_due_date'])): ?>
                            <div class="kv-row"><span>Next due date</span><b><?= htmlspecialchars(date('d M Y', strtotime($latest_payment_any['next_due_date']))) ?></b></div>
                        <?php endif; ?>
                        <div class="btn-row">
                            <button class="btn btn-ghost" onclick="location.href='user_payments.php'">View Invoices</button>
                            <?php if ($__balance > 0): ?>
                                <button class="btn btn-primary" onclick="location.href='user_membership.php'">Pay Now</button>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Workout streak -->
                <div class="card span-2" id="streakCard" data-tour="streak-card" data-csrf="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
                    <div class="card-head">
                        <div class="card-title">
                            <div class="card-icon" style="background:var(--coral-tint); color:var(--coral-dark);">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                    stroke-linecap="round" stroke-linejoin="round">
                                    <path
                                        d="M8.5 14.5A2.5 2.5 0 0011 12c0-1.38-.5-2-1-3-1.072-2.143-.224-4.054 2-6 .5 2.5 2 4.9 4 6.5 2 1.6 3 3.5 3 5.5a7 7 0 11-14 0c0-1.153.433-2.294 1-3a2.5 2.5 0 002.5 2.5z" />
                                </svg>
                            </div>
                            Workout Streak
                        </div>
                        <span class="sub-line">Tap any day to log it</span>
                    </div>

                    <div class="streak-top">
                        <div class="streak-flame">
                            <div class="fl">
                                <svg viewBox="0 0 24 24" fill="currentColor">
                                    <path
                                        d="M8.5 14.5A2.5 2.5 0 0011 12c0-1.38-.5-2-1-3-1.072-2.143-.224-4.054 2-6 .5 2.5 2 4.9 4 6.5 2 1.6 3 3.5 3 5.5a7 7 0 11-14 0c0-1.153.433-2.294 1-3a2.5 2.5 0 002.5 2.5z" />
                                </svg>
                            </div>
                            <div>
                                <div class="streak-num" id="streakNum"><?= (int) $streak['current_streak'] ?></div>
                                <div class="streak-cap" id="streakCap">
                                    day<?= $streak['current_streak'] == 1 ? '' : 's' ?> in a row
                                </div>
                            </div>
                        </div>
                        <button type="button" class="streak-log-btn <?= $streak['logged_today'] ? 'done' : '' ?>"
                            id="logTodayBtn">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4"
                                stroke-linecap="round" stroke-linejoin="round">
                                <path d="M20 6L9 17l-5-5" />
                            </svg>
                            <span class="tx"><?= $streak['logged_today'] ? 'Logged today' : "Log today's workout" ?></span>
                        </button>
                    </div>

                    <div class="streak-strip" id="streakStrip">
                        <?php foreach ($streak['strip'] as $d): ?>
                            <button type="button" class="sd <?= $d['done'] ? 'on' : '' ?> <?= $d['today'] ? 'is-today' : '' ?> <?= $d['note'] !== '' ? 'has-note' : '' ?>"
                                data-date="<?= $d['date'] ?>" data-note="<?= htmlspecialchars($d['note'], ENT_QUOTES) ?>"
                                title="<?= $d['date'] . ($d['note'] !== '' ? ' — ' . htmlspecialchars($d['note'], ENT_QUOTES) : '') ?>">
                                <span class="sd-dow"><?= substr($d['label'], 0, 1) ?></span>
                                <span class="sd-dot"></span>
                                <?php if ($d['done']): ?><span class="sd-note-btn" title="Add/edit note">✎</span><?php endif; ?>
                                <span class="sd-day"><?= $d['day'] ?></span>
                            </button>
                        <?php endforeach; ?>
                    </div>

                    <div class="streak-stats">
                        <div><span id="stLongest"><?= (int) $streak['longest_streak'] ?></span><small>Longest
                                streak</small></div>
                        <div><span id="stWeek"><?= (int) $streak['this_week'] ?></span><small>This week</small></div>
                        <div><span id="stGap"><?= (int) $streak['longest_gap'] ?></span><small>Longest gap</small></div>
                        <div><span
                                id="stSince"><?= $streak['days_since_last'] === null ? '—' : (int) $streak['days_since_last'] ?></span><small>Days
                                since last</small></div>
                    </div>
                </div>

                <!-- Daily goals -->
                <section class="card goals-card" id="goalsCard" data-tour="goals-card" data-csrf="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
                    <header class="goals-head">
                        <div>
                            <h3 class="goals-title">Daily Goals</h3>
                            <p class="goals-sub" id="goalsSub">Plan your day and tick off what you get done.</p>
                        </div>
                        <button type="button" class="goals-add-btn" id="goalsAddToggle" aria-label="Add a goal" title="Add a goal">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg>
                        </button>
                    </header>

                    <div class="goals-week">
                        <button type="button" class="goals-nav" id="goalsPrev" aria-label="Earlier days" title="Earlier days">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 6l-6 6 6 6"/></svg>
                        </button>
                        <div class="goals-days" id="goalsDays"></div>
                        <button type="button" class="goals-nav" id="goalsNext" aria-label="Later days" title="Later days">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 6l6 6-6 6"/></svg>
                        </button>
                    </div>

                    <div class="goals-summary">
                        <div class="goals-date" id="goalsDate"></div>
                        <div class="goals-progress">
                            <div class="goals-bar"><span id="goalsBar"></span></div>
                            <span class="goals-count" id="goalsCount"></span>
                        </div>
                    </div>

                    <form class="goals-add-row" id="goalAddForm" hidden autocomplete="off">
                        <textarea id="goalInput" maxlength="255" rows="3" placeholder="Write your goal for today… (Enter to add, Shift+Enter for a new line)" aria-label="New goal"></textarea>
                        <button type="submit" class="goals-submit" id="goalAddBtn">Add</button>
                    </form>

                    <ul class="goals-list" id="goalList"></ul>
                    <div class="goals-empty" id="goalEmpty" hidden></div>
                </section>

            </div>

            <!-- Workout note editor (replaces the native prompt() popup) -->
            <div class="note-modal-overlay" id="noteModalOverlay">
                <div class="note-modal">
                    <h4>Note for <span id="noteModalDate"></span></h4>
                    <textarea id="noteModalInput" maxlength="255" rows="3" placeholder="e.g. Leg day, felt strong"></textarea>
                    <div class="note-modal-acts">
                        <button type="button" class="note-modal-cancel" id="noteModalCancel">Cancel</button>
                        <button type="button" class="note-modal-save" id="noteModalSave">Save</button>
                    </div>
                </div>
            </div>

            <footer class="note">JOF India · Joshuaa's Outdoor Fitness — Member Portal</footer>
        </main>
    </div>

    <!-- Toast container -->
    <div class="toast-container" id="toastContainer"></div>

    <script>
        const shell = document.getElementById('shell');
        const railToggle = document.getElementById('railToggle');
        const hamburgerBtn = document.getElementById('hamburgerBtn');
        const overlay = document.getElementById('overlay');
        const toastContainer = document.getElementById('toastContainer');

        const isMobile = () => window.matchMedia('(max-width: 860px)').matches;

        function openDrawer() {
            shell.classList.add('drawer-open');
            overlay.classList.add('show');
            document.body.style.overflow = 'hidden';
        }
        function closeDrawer() {
            shell.classList.remove('drawer-open');
            overlay.classList.remove('show');
            document.body.style.overflow = '';
        }

        if (hamburgerBtn) hamburgerBtn.addEventListener('click', openDrawer);
        if (overlay) overlay.addEventListener('click', closeDrawer);
        if (railToggle) railToggle.addEventListener('click', () => {
            if (isMobile()) closeDrawer();
            else shell.classList.toggle('collapsed');
        });

        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && shell.classList.contains('drawer-open')) closeDrawer();
        });
        window.addEventListener('resize', () => { if (!isMobile()) closeDrawer(); });

        document.querySelectorAll('.nav-item').forEach(item => {
            item.addEventListener('click', (e) => {
                // Only intercept placeholder # links — allow real hrefs (like logout) to navigate normally
                if (item.getAttribute('href') === '#' || !item.getAttribute('href')) {
                    e.preventDefault();
                }
                if (isMobile()) closeDrawer();
            });
        });

        function showToast(title, body) {
            const toast = document.createElement('div');
            toast.className = 'toast';
            toast.innerHTML = `
      <div class="toast-icon">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8a6 6 0 10-12 0c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.7 21a2 2 0 01-3.4 0"/></svg>
      </div>
      <div class="toast-body">
        <b>${title}</b>
        <p>${body}</p>
      </div>
      <button class="toast-close" aria-label="Dismiss">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 6l12 12M18 6L6 18"/></svg>
      </button>
    `;
            toastContainer.appendChild(toast);

            const remove = () => {
                toast.classList.add('closing');
                setTimeout(() => toast.remove(), 220);
            };
            toast.querySelector('.toast-close').addEventListener('click', remove);
            setTimeout(remove, 5000);
        }

        // ══════════════════════════════════════════════════
        //  MESSAGES BELL (trainer replies on diet plan chats)
        // ══════════════════════════════════════════════════
        (function () {
            let msgOpen = false;
            const msgBtn = document.getElementById('msgBellBtn');
            const msgDrop = document.getElementById('msgDropdown');
            const msgDot = document.getElementById('msgUnreadDot');
            const msgList = document.getElementById('msgDropdownList');
            const msgClose = document.getElementById('msgDropdownClose');
            if (!msgBtn) return;

            function escHtmlM(str) {
                return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
            }
            function timeAgoM(dateStr) {
                const past = new Date(dateStr.replace(' ', 'T'));
                if (isNaN(past.getTime())) return '';
                let diff = Math.floor((Date.now() - past.getTime()) / 1000);
                if (diff < 0) diff = 0;
                if (diff < 60) return diff + 's ago';
                if (diff < 3600) return Math.floor(diff / 60) + 'm ago';
                if (diff < 86400) return Math.floor(diff / 3600) + 'h ago';
                return Math.floor(diff / 86400) + 'd ago';
            }

            msgBtn.addEventListener('click', function (e) {
                e.stopPropagation();
                msgOpen = !msgOpen;
                msgDrop.classList.toggle('active', msgOpen);
                if (msgOpen) openMsgPanel();
            });
            if (msgClose) {
                msgClose.addEventListener('click', function (e) {
                    e.stopPropagation();
                    msgOpen = false;
                    msgDrop.classList.remove('active');
                });
            }
            document.addEventListener('click', function (e) {
                if (msgOpen && msgDrop && !msgDrop.contains(e.target) && e.target !== msgBtn) {
                    msgOpen = false;
                    msgDrop.classList.remove('active');
                }
            });

            function renderMsgList(items) {
                if (!items || !items.length) {
                    msgList.innerHTML = '<div class="shell-dropdown-empty">No messages yet.<br>Ask a question from your Diet Plans page!</div>';
                    return;
                }
                msgList.innerHTML = items.map(n => `
                    <a class="shell-dropdown-item" href="user_diet_plans.php?open_plan=${n.plan_id}">
                        <div class="n">${escHtmlM(n.phase)}</div>
                        <div class="m">${escHtmlM((n.message || '').slice(0, 70))}${n.message.length > 70 ? '…' : ''}</div>
                        <div class="t">${timeAgoM(n.created_at)}</div>
                    </a>
                `).join('');
            }

            function openMsgPanel() {
                msgList.innerHTML = '<div class="shell-dropdown-empty">Loading…</div>';
                fetch('../../handlers/get_user_diet_notifications.php', { cache: 'no-store' })
                    .then(res => res.json())
                    .then(data => {
                        if (data.status === 'success') {
                            renderMsgList(data.notifications);
                            fetch('../../handlers/mark_user_diet_messages_seen.php', { method: 'POST', cache: 'no-store' });
                            updateMsgDot(0);
                        }
                    })
                    .catch(() => { msgList.innerHTML = '<div class="shell-dropdown-empty">Could not load messages.</div>'; });
            }

            function updateMsgDot(count) {
                if (!msgDot) return;
                msgDot.style.display = count > 0 ? 'block' : 'none';
            }

            function pollMsgBadge() {
                fetch('../../handlers/get_user_diet_notifications.php', { cache: 'no-store' })
                    .then(res => res.json())
                    .then(data => { if (data.status === 'success' && !msgOpen) updateMsgDot(data.unread_count); })
                    .catch(() => { });
            }
            pollMsgBadge();
            setInterval(pollMsgBadge, 10000);
        })();


        // ══════════════════════════════════════════════════
        //  DAILY GOALS
        // ══════════════════════════════════════════════════
        (function () {
            const card = document.getElementById('goalsCard');
            if (!card) return;

            // Goals per day, keyed by Y-m-d. Only today's list is editable.
            const GOALS = <?= json_encode($goals_js, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
            const CSRF = card.dataset.csrf;
            const TODAY = GOALS.today;
            const DAYS_BACK = 60;

            const daysEl = document.getElementById('goalsDays');
            const dateEl = document.getElementById('goalsDate');
            const subEl = document.getElementById('goalsSub');
            const barEl = document.getElementById('goalsBar');
            const countEl = document.getElementById('goalsCount');
            const listEl = document.getElementById('goalList');
            const emptyEl = document.getElementById('goalEmpty');
            const addToggle = document.getElementById('goalsAddToggle');
            const addForm = document.getElementById('goalAddForm');
            const input = document.getElementById('goalInput');
            const addBtn = document.getElementById('goalAddBtn');
            const prevBtn = document.getElementById('goalsPrev');
            const nextBtn = document.getElementById('goalsNext');

            let selected = TODAY;
            let center = TODAY; // middle tile of the 3-day strip
            let busy = false;

            const esc = s => String(s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

            function parse(ymd) {
                const [y, m, d] = ymd.split('-').map(Number);
                return new Date(y, m - 1, d);
            }
            function shift(ymd, n) {
                const d = parse(ymd);
                d.setDate(d.getDate() + n);
                return [d.getFullYear(), String(d.getMonth() + 1).padStart(2, '0'), String(d.getDate()).padStart(2, '0')].join('-');
            }
            function longDate(ymd) {
                return parse(ymd).toLocaleDateString('en-IN', { weekday: 'long', day: 'numeric', month: 'long' });
            }
            function goalsOn(ymd) {
                return GOALS.days[ymd] || [];
            }
            function normalise(list) {
                return list.map(g => ({ id: Number(g.id), title: g.title, achieved: g.achieved !== undefined ? !!g.achieved : Number(g.is_achieved) === 1 }));
            }

            function renderWeek() {
                let html = '';
                for (let i = -1; i <= 1; i++) {
                    const ymd = shift(center, i);
                    const offset = Math.round((parse(TODAY) - parse(ymd)) / 86400000);
                    const label = offset === 0 ? 'Today' : offset === 1 ? 'Yesterday' : offset === -1 ? 'Tomorrow'
                        : parse(ymd).toLocaleDateString('en-IN', { weekday: 'short' });
                    const goals = goalsOn(ymd);
                    const done = goals.filter(g => g.achieved).length;
                    const allDone = goals.length > 0 && done === goals.length;
                    const stat = goals.length ? `${done}/${goals.length}` : '–';
                    html += `<button type="button" class="goals-day ${ymd === selected ? 'selected' : ''} ${allDone ? 'has-all' : ''}" data-date="${ymd}">` +
                        `<span class="goals-day-label">${label}</span>` +
                        `<span class="goals-day-num">${parse(ymd).getDate()} ${parse(ymd).toLocaleDateString('en-IN', { month: 'short' })}</span>` +
                        `<span class="goals-day-stat">${stat}</span></button>`;
                }
                daysEl.innerHTML = html;
                prevBtn.disabled = Math.round((parse(TODAY) - parse(center)) / 86400000) >= DAYS_BACK;
                nextBtn.disabled = center === TODAY;
            }

            function renderDay() {
                const isToday = selected === TODAY;
                const goals = goalsOn(selected);
                const done = goals.filter(g => g.achieved).length;
                const pct = goals.length ? Math.round((done / goals.length) * 100) : 0;

                dateEl.textContent = (isToday ? 'Today · ' : '') + longDate(selected);
                subEl.textContent = isToday ? 'Plan your day and tick off what you get done.' : "Only today's goals can be changed.";
                barEl.style.width = pct + '%';
                countEl.textContent = goals.length ? `${done} of ${goals.length} achieved` : 'No goals';

                addToggle.hidden = !isToday;
                if (!isToday) {
                    addForm.hidden = true;
                    addToggle.classList.remove('open');
                }

                listEl.innerHTML = goals.map(g =>
                    `<li class="goal-row ${g.achieved ? 'done' : ''}" data-id="${g.id}">` +
                    `<button type="button" class="goal-check" role="checkbox" aria-checked="${g.achieved}" ${isToday ? '' : 'disabled'}` +
                    ` aria-label="${g.achieved ? 'Mark as not achieved' : 'Mark as achieved'}">` +
                    `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12l5 5 9-10"/></svg></button>` +
                    `<span class="goal-text">${esc(g.title)}</span>` +
                    `<button type="button" class="goal-del" aria-label="Delete goal" title="Delete goal" ${isToday ? '' : 'hidden'}>×</button></li>`
                ).join('');

                emptyEl.hidden = goals.length > 0;
                emptyEl.textContent = isToday
                    ? 'Nothing planned yet. Tap + to add your first goal.'
                    : (selected > TODAY ? 'You can add goals for this day when it arrives.' : 'No goals were set on this day.');
            }

            function renderAll() {
                renderWeek();
                renderDay();
            }

            function applyStats(stats) {
                const set = document.getElementById('wsGoalsSet');
                const achieved = document.getElementById('wsGoalsDone');
                if (set) set.textContent = stats.total;
                if (achieved) achieved.textContent = stats.achieved;
            }

            function send(fields) {
                if (busy) return Promise.resolve(null);
                busy = true;
                const body = new URLSearchParams({ _csrf_token: CSRF, ...fields });
                return fetch('../../handlers/daily_goals.php', { method: 'POST', body })
                    .then(r => r.json())
                    .then(d => {
                        if (d.success) {
                            GOALS.days[TODAY] = normalise(d.today);
                            applyStats(d.stats);
                            renderAll();
                        } else {
                            showToast('Could not save', d.message || 'Please try again.');
                        }
                        return d;
                    })
                    .catch(() => { showToast('Offline', 'Could not reach the server.'); return null; })
                    .finally(() => { busy = false; });
            }

            daysEl.addEventListener('click', e => {
                const btn = e.target.closest('.goals-day');
                if (!btn) return;
                selected = btn.dataset.date;
                renderAll();
            });

            prevBtn.addEventListener('click', () => {
                center = shift(center, -3);
                renderWeek();
            });
            nextBtn.addEventListener('click', () => {
                const next = shift(center, 3);
                center = next > TODAY ? TODAY : next;
                renderWeek();
            });

            addToggle.addEventListener('click', () => {
                addForm.hidden = !addForm.hidden;
                addToggle.classList.toggle('open', !addForm.hidden);
                if (!addForm.hidden) input.focus();
            });

            addForm.addEventListener('submit', e => {
                e.preventDefault();
                const title = input.value.trim();
                if (!title) {
                    input.focus();
                    return;
                }
                addBtn.disabled = true;
                send({ action: 'add', title }).then(d => {
                    addBtn.disabled = false;
                    if (d && d.success) {
                        input.value = '';
                        input.focus();
                    }
                });
            });

            input.addEventListener('keydown', e => {
                if (e.key === 'Enter' && !e.shiftKey) {
                    e.preventDefault();
                    addForm.requestSubmit();
                }
            });

            listEl.addEventListener('click', e => {
                if (selected !== TODAY) return;
                const row = e.target.closest('.goal-row');
                if (!row) return;
                const id = row.dataset.id;
                if (e.target.closest('.goal-check')) {
                    send({ action: 'toggle', id });
                } else if (e.target.closest('.goal-del')) {
                    send({ action: 'delete', id });
                }
            });

            renderAll();
        })();

        // ══════════════════════════════════════════════════
        //  WORKOUT STREAK
        // ══════════════════════════════════════════════════
        (function () {
            const card = document.getElementById('streakCard');
            if (!card) return;

            const CSRF = card.dataset.csrf;
            const logBtn = document.getElementById('logTodayBtn');
            const strip = document.getElementById('streakStrip');
            let busy = false;

            function todayLocal() {
                const n = new Date();
                return n.getFullYear() + '-' +
                    String(n.getMonth() + 1).padStart(2, '0') + '-' +
                    String(n.getDate()).padStart(2, '0');
            }

            function render(s) {
                document.getElementById('streakNum').textContent = s.current_streak;
                document.getElementById('streakCap').textContent =
                    (s.current_streak === 1 ? 'day' : 'days') + ' in a row';
                document.getElementById('stLongest').textContent = s.longest_streak;
                document.getElementById('stWeek').textContent = s.this_week;
                document.getElementById('stGap').textContent = s.longest_gap;
                document.getElementById('stSince').textContent =
                    s.days_since_last === null ? '—' : s.days_since_last;

                logBtn.classList.toggle('done', s.logged_today);
                logBtn.querySelector('.tx').textContent =
                    s.logged_today ? 'Logged today' : "Log today's workout";

                const esc = s => String(s).replace(/[&<>"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
                strip.innerHTML = s.strip.map(d =>
                    `<button type="button" class="sd ${d.done ? 'on' : ''} ${d.today ? 'is-today' : ''} ${d.note ? 'has-note' : ''}"` +
                    ` data-date="${d.date}" data-note="${esc(d.note)}"` +
                    ` title="${d.date}${d.note ? ' — ' + esc(d.note) : ''}">` +
                    `<span class="sd-dow">${d.label[0]}</span><span class="sd-dot"></span>` +
                    (d.done ? '<span class="sd-note-btn" title="Add/edit note">✎</span>' : '') +
                    `<span class="sd-day">${d.day}</span></button>`
                ).join('');

                const ws = document.getElementById('wsStreak');
                const wt = document.getElementById('wsTotal');
                const wb = document.getElementById('wsBest');
                if (ws) ws.textContent = s.current_streak;
                if (wt) wt.textContent = s.total_workouts;
                if (wb) wb.textContent = s.longest_streak;
            }

            function toggleDay(date) {
                if (busy) return;
                busy = true;
                logBtn.disabled = true;

                fetch('../../handlers/workout_log.php', {
                    method: 'POST',
                    body: new URLSearchParams({ _csrf_token: CSRF, date: date })
                })
                    .then(r => r.json())
                    .then(d => {
                        if (d.success) render(d.stats);
                        else showToast('Could not save', d.message || 'Please try again.');
                    })
                    .catch(() => showToast('Offline', 'Could not reach the server.'))
                    .finally(() => { busy = false; logBtn.disabled = false; });
            }

            // ── Note editor (a small modal in place of the native prompt()) ──
            const noteOverlay = document.getElementById('noteModalOverlay');
            const noteDateEl = document.getElementById('noteModalDate');
            const noteInput = document.getElementById('noteModalInput');
            let noteDate = null;

            function editNote(btn) {
                noteDate = btn.dataset.date;
                noteDateEl.textContent = noteDate;
                noteInput.value = btn.dataset.note || '';
                noteOverlay.classList.add('open');
                noteInput.focus();
            }

            function closeNoteModal() {
                noteOverlay.classList.remove('open');
                noteDate = null;
            }

            function saveNote() {
                if (busy || !noteDate) return;
                const note = noteInput.value;
                const date = noteDate;
                closeNoteModal();
                busy = true;
                fetch('../../handlers/workout_log.php', {
                    method: 'POST',
                    body: new URLSearchParams({ _csrf_token: CSRF, date: date, action: 'note', note })
                })
                    .then(r => r.json())
                    .then(d => {
                        if (d.success) render(d.stats);
                        else showToast('Could not save', d.message || 'Please try again.');
                    })
                    .catch(() => showToast('Offline', 'Could not reach the server.'))
                    .finally(() => { busy = false; });
            }

            document.getElementById('noteModalCancel').addEventListener('click', closeNoteModal);
            document.getElementById('noteModalSave').addEventListener('click', saveNote);
            noteOverlay.addEventListener('click', (e) => { if (e.target === noteOverlay) closeNoteModal(); });
            noteInput.addEventListener('keydown', (e) => {
                if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); saveNote(); }
                if (e.key === 'Escape') closeNoteModal();
            });

            logBtn.addEventListener('click', () => toggleDay(todayLocal()));
            strip.addEventListener('click', (e) => {
                const noteBtn = e.target.closest('.sd-note-btn');
                if (noteBtn) { e.stopPropagation(); editNote(noteBtn.closest('.sd')); return; }
                const b = e.target.closest('.sd');
                if (b) toggleDay(b.dataset.date);
            });
        })();
    </script>

    <?php include __DIR__ . '/_tour.php'; ?>
</body>

</html>