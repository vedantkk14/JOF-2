<?php
require_once __DIR__ . '/../../auth/auth_check.php';
require_role(['user']);
$user = get_session_user();

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../auth/profile_helper.php';

$uid = (int) $user['id'];
$mid = get_user_member_id($conn, $uid);

$member_name  = '';
$member_email = '';
$plans        = [];

if ($mid) {
    $s = mysqli_prepare($conn, "SELECT full_name, email FROM members WHERE id = ?");
    mysqli_stmt_bind_param($s, 'i', $mid);
    mysqli_stmt_execute($s);
    if ($row = mysqli_fetch_assoc(mysqli_stmt_get_result($s))) {
        $member_name  = $row['full_name'] ?? '';
        $member_email = $row['email'] ?? '';
    }

    if ($member_name !== '') {
        // Plans are linked by the "<client name> - <phase>" naming convention
        $s = mysqli_prepare(
            $conn,
            "SELECT * FROM diet_plans
             WHERE TRIM(SUBSTRING_INDEX(plan_name, ' - ', 1)) = ?
             ORDER BY id ASC"
        );
        mysqli_stmt_bind_param($s, 's', $member_name);
        mysqli_stmt_execute($s);
        $res = mysqli_stmt_get_result($s);
        while ($p = mysqli_fetch_assoc($res)) {
            $plans[] = $p;
        }
    }
}

// account email (delivery address shown to the user)
if ($member_email === '') {
    $s = mysqli_prepare($conn, "SELECT email FROM user_data WHERE id = ?");
    mysqli_stmt_bind_param($s, 'i', $uid);
    mysqli_stmt_execute($s);
    $member_email = mysqli_fetch_assoc(mysqli_stmt_get_result($s))['email'] ?? '';
}

$pstatus   = user_profile_status($conn, $uid);
$acct_name = html_entity_decode($user['name'], ENT_QUOTES) ?: 'Member';
$initials  = strtoupper(substr(preg_replace('/[^A-Za-z ]/', '', $acct_name), 0, 1)
    . (strpos(trim($acct_name), ' ') !== false ? substr(strrchr(trim($acct_name), ' '), 1, 1) : ''));
$initials  = $initials ?: 'U';

$plans_desc = array_reverse($plans);            // newest first for display
$latest     = $plans_desc[0] ?? null;

$diet_label = ['veg' => 'Vegetarian', 'nonveg' => 'Non-Veg', 'vegan' => 'Vegan'];

function dp_phase(string $plan_name): string
{
    $parts = explode(' - ', $plan_name, 2);
    return trim($parts[1] ?? $plan_name);
}

/** Render a stored meal-text block (headings + lines) as safe HTML. */
function dp_meal(string $text): string
{
    $text = trim((string) $text);
    if ($text === '') {
        return '<div class="meal-empty">Not specified</div>';
    }
    $text = preg_replace('/[\x{1F000}-\x{1FFFF}\x{2600}-\x{27BF}\x{2B00}-\x{2BFF}]/u', '', $text);
    $text = str_replace('**', '', $text);

    $html = '';
    foreach (preg_split('/\r?\n/', $text) as $line) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }
        $safe = htmlspecialchars($line, ENT_QUOTES, 'UTF-8');
        $isHead = str_ends_with($line, ':') || (mb_strlen($line) > 2 && mb_strtoupper($line) === $line);
        if ($isHead) {
            $html .= '<div class="meal-h">' . rtrim($safe, ': ') . '</div>';
        } else {
            $html .= '<div class="meal-l">' . $safe . '</div>';
        }
    }
    return $html ?: '<div class="meal-empty">Not specified</div>';
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>JOF India — Diet Plans</title>
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
            --blue: #3B6FE0;
            --blue-tint: #EAF2FF;
            --shadow: 0 1px 2px rgba(20, 20, 30, .04), 0 8px 24px -12px rgba(20, 20, 30, .08);
            --radius: 18px;
            --sidebar-w: 264px;
            --sidebar-w-collapsed: 84px;
        }

        * { box-sizing: border-box; margin: 0; padding: 0; min-width: 0; }

        html, body { max-width: 100%; overflow-x: hidden; }
        img { max-width: 100%; }

        body { font-family: 'Inter', sans-serif; background: var(--bg); color: var(--ink); -webkit-font-smoothing: antialiased; }
        h1, h2, h3, .brand-name { font-family: 'Sora', sans-serif; }
        button { font-family: inherit; cursor: pointer; }
        a { text-decoration: none; color: inherit; }
        ul { list-style: none; }

        /* ===== Shell / sidebar / topbar ===== */
        .shell { display: flex; min-height: 100vh; }

        .sidebar {
            width: var(--sidebar-w); flex-shrink: 0; background: var(--card); border-right: 1px solid var(--border);
            display: flex; flex-direction: column; padding: 22px 16px;
            transition: width .28s ease, transform .28s ease; position: relative; z-index: 40;
        }

        .shell.collapsed .sidebar { width: var(--sidebar-w-collapsed); }
        .brand { display: flex; align-items: center; gap: 12px; padding: 6px 46px 26px 10px; }

        .brand-mark {
            width: 40px; height: 40px; flex-shrink: 0; background: #fff; border: 1px solid var(--border);
            border-radius: 12px; display: flex; align-items: center; justify-content: center; overflow: hidden;
        }

        .brand-mark img { width: 26px; height: 26px; object-fit: contain; display: block; }
        .brand-text { overflow: hidden; white-space: nowrap; }
        .brand-name { font-size: 16px; font-weight: 700; line-height: 1.15; }
        .brand-sub { font-size: 11.5px; color: var(--ink-soft); font-weight: 500; }
        .shell.collapsed .brand-text { display: none; }

        .nav-group { flex: 1; display: flex; flex-direction: column; gap: 2px; margin-top: 6px; }

        .nav-item {
            display: flex; align-items: center; gap: 14px; padding: 12px 14px; border-radius: 12px;
            color: var(--ink-soft); font-weight: 600; font-size: 14.5px; white-space: nowrap; overflow: hidden;
            transition: background .15s ease, color .15s ease;
        }

        .nav-item svg { flex-shrink: 0; width: 20px; height: 20px; }
        .nav-item:hover { background: var(--coral-tint); color: var(--coral-dark); }
        .nav-item.active { background: var(--coral); color: #fff; }
        .shell.collapsed .nav-label { display: none; }
        .shell.collapsed .nav-item { justify-content: center; padding: 12px; }
        .nav-bottom { border-top: 1px solid var(--border); padding-top: 10px; margin-top: 10px; }

        .rail-toggle {
            position: absolute; top: 16px; right: 12px; z-index: 3; width: 30px; height: 30px;
            display: flex; align-items: center; justify-content: center; border: 1px solid var(--border);
            background: var(--card); border-radius: 9px; color: var(--ink-soft);
            transition: background .15s ease, color .15s ease, border-color .15s ease;
        }

        .rail-toggle:hover { background: var(--coral-tint); color: var(--coral-dark); border-color: var(--coral); }
        .rail-toggle svg { width: 16px; height: 16px; }
        .rail-toggle .ic-close { display: none; }
        .rail-toggle .ic-collapse { transition: transform .28s ease; }
        .shell.collapsed .rail-toggle .ic-collapse { transform: rotate(180deg); }
        .shell.collapsed .brand { flex-direction: column; gap: 10px; padding: 50px 8px 24px; align-items: center; }
        .shell.collapsed .rail-toggle { top: 14px; right: 50%; transform: translateX(50%); }

        .drawer-overlay { display: none; position: fixed; inset: 0; background: rgba(20, 22, 30, .45); z-index: 35; }
        .drawer-overlay.show { display: block; }

        .hamburger {
            display: none; width: 40px; height: 40px; align-items: center; justify-content: center;
            border-radius: 10px; border: 1px solid var(--border); background: var(--card);
        }

        .main { flex: 1; min-width: 0; padding: 22px 30px 60px; }

        .topbar { display: flex; align-items: center; justify-content: space-between; margin-bottom: 20px; gap: 14px; }
        .topbar-left { display: flex; align-items: center; gap: 14px; }
        .page-title { font-size: 20px; font-weight: 700; }
        .topbar-right { display: flex; align-items: center; gap: 14px; }

        .profile-chip {
            display: flex; align-items: center; gap: 10px; padding: 5px 12px 5px 5px; border-radius: 30px;
            background: var(--card); border: 1px solid var(--border);
        }

        .avatar {
            width: 34px; height: 34px; border-radius: 50%; background: var(--coral-tint); color: var(--coral-dark);
            font-weight: 700; font-size: 13px; display: flex; align-items: center; justify-content: center;
        }

        .profile-name { font-size: 13.5px; font-weight: 600; line-height: 1.1; }
        .profile-role { font-size: 11px; color: var(--ink-soft); }

        /* ===== Hero ===== */
        .dp-hero {
            background: linear-gradient(140deg, #FF7A57, #E5502B); color: #fff; border-radius: var(--radius);
            padding: 24px 26px; box-shadow: var(--shadow); display: flex; align-items: center; gap: 22px;
            flex-wrap: wrap; margin-bottom: 20px;
        }

        .dp-hero .htxt { flex: 1; min-width: 220px; }
        .dp-hero h1 { font-size: 21px; font-weight: 800; margin-bottom: 6px; }
        .dp-hero p { font-size: 13px; opacity: .95; line-height: 1.5; }
        .dp-hero p b { font-weight: 700; }

        .dp-hero .hstats { display: flex; gap: 26px; flex-shrink: 0; }
        .dp-hero .hstat .n { font-family: 'Sora', sans-serif; font-weight: 800; font-size: 22px; }
        .dp-hero .hstat .l { font-size: 11px; opacity: .85; margin-top: 2px; }

        .dp-dl {
            display: inline-flex; align-items: center; gap: 9px; padding: 12px 20px; border-radius: 13px;
            background: #fff; color: var(--coral-dark); font-weight: 800; font-size: 13.5px; flex-shrink: 0;
            border: none; box-shadow: 0 6px 18px -6px rgba(0, 0, 0, .3); transition: transform .16s ease;
        }

        .dp-dl:hover { transform: translateY(-1px); }
        .dp-dl svg { width: 16px; height: 16px; }

        /* ===== Plan cards ===== */
        .dp-list { display: flex; flex-direction: column; gap: 14px; }

        .dp-card {
            background: var(--card); border: 1px solid var(--border); border-radius: var(--radius);
            box-shadow: var(--shadow); overflow: hidden;
        }

        .dp-head {
            display: flex; align-items: center; gap: 14px; padding: 18px 20px; cursor: pointer;
            width: 100%; background: none; border: none; text-align: left;
        }

        .dp-phase {
            font-family: 'Sora', sans-serif; font-weight: 800; font-size: 15px; color: var(--ink);
            display: flex; align-items: center; gap: 10px; flex-wrap: wrap;
        }

        .dp-tag {
            font-size: 10.5px; font-weight: 800; letter-spacing: .03em; text-transform: uppercase;
            padding: 3px 9px; border-radius: 20px; background: var(--green-tint); color: #0f7a53;
        }

        .dp-tag.nonveg { background: var(--red-tint); color: #9b1c1c; }
        .dp-tag.vegan { background: var(--blue-tint); color: var(--blue); }
        .dp-tag.latest { background: var(--coral-tint); color: var(--coral-dark); }

        .dp-sub { font-size: 12px; color: var(--ink-soft); margin-top: 3px; }

        .dp-head .grow { flex: 1; min-width: 0; }

        .dp-chev {
            width: 30px; height: 30px; border-radius: 9px; background: var(--bg); flex-shrink: 0;
            display: flex; align-items: center; justify-content: center; color: var(--ink-soft);
            transition: transform .22s ease;
        }

        .dp-chev svg { width: 15px; height: 15px; }
        .dp-card.open .dp-chev { transform: rotate(180deg); }

        .dp-body { display: none; padding: 0 20px 20px; }
        .dp-card.open .dp-body { display: block; }

        .dp-meta {
            display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 10px; margin-bottom: 18px;
        }

        .dp-meta .box { background: var(--bg); border-radius: 12px; padding: 11px 12px; min-width: 0; }
        .dp-meta .box .k { font-size: 10.5px; text-transform: uppercase; letter-spacing: .04em; color: var(--ink-faint); font-weight: 700; }
        .dp-meta .box .val { font-size: 13.5px; font-weight: 700; margin-top: 3px; overflow-wrap: anywhere; }

        .dp-meals { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px; }

        .meal-content, .meal-l, .meal-h { overflow-wrap: anywhere; }

        .meal-block { border: 1px solid var(--border); border-radius: 13px; overflow: hidden; }

        .meal-title {
            display: flex; align-items: center; gap: 8px; padding: 10px 14px; background: var(--bg);
            font-size: 12px; font-weight: 800; text-transform: uppercase; letter-spacing: .04em; color: var(--ink-soft);
            border-bottom: 1px solid var(--border);
        }

        .meal-title .d { width: 7px; height: 7px; border-radius: 50%; background: var(--coral); }
        .meal-content { padding: 12px 14px; font-size: 13px; line-height: 1.55; }
        .meal-h { font-weight: 800; color: var(--coral-dark); font-size: 11.5px; text-transform: uppercase; letter-spacing: .03em; margin: 10px 0 3px; }
        .meal-h:first-child { margin-top: 0; }
        .meal-l { color: var(--ink); }
        .meal-empty { color: var(--ink-faint); font-style: italic; }

        .dp-foot {
            margin-top: 16px; padding-top: 14px; border-top: 1px dashed var(--border);
            display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap;
        }

        .dp-foot .note { font-size: 11.5px; color: var(--ink-faint); }

        .dp-dl-sm {
            display: inline-flex; align-items: center; gap: 8px; padding: 10px 16px; border-radius: 11px;
            background: var(--coral); color: #fff; font-weight: 700; font-size: 13px; border: none;
            transition: background .16s ease, transform .16s ease;
        }

        .dp-dl-sm:hover { background: var(--coral-dark); transform: translateY(-1px); }
        .dp-dl-sm svg { width: 14px; height: 14px; }

        /* ===== Empty state ===== */
        .dp-empty {
            background: var(--card); border: 1px solid var(--border); border-radius: var(--radius);
            box-shadow: var(--shadow); padding: 46px 30px; text-align: center;
        }

        .dp-empty .ic {
            width: 60px; height: 60px; border-radius: 18px; background: var(--coral-tint); color: var(--coral-dark);
            display: flex; align-items: center; justify-content: center; margin: 0 auto 16px;
        }

        .dp-empty .ic svg { width: 28px; height: 28px; }
        .dp-empty h2 { font-size: 17px; font-weight: 800; margin-bottom: 6px; }
        .dp-empty p { font-size: 13.5px; color: var(--ink-soft); line-height: 1.6; max-width: 440px; margin: 0 auto 18px; }

        .dp-empty a.btn {
            display: inline-flex; align-items: center; gap: 8px; padding: 11px 20px; border-radius: 12px;
            background: var(--coral); color: #fff; font-weight: 700; font-size: 13.5px;
        }

        .dp-empty a.btn:hover { background: var(--coral-dark); }

        footer.note { text-align: center; font-size: 12.5px; color: var(--ink-faint); margin-top: 26px; }

        /* ===== Responsive ===== */
        @media (max-width: 900px) {
            .dp-meta { grid-template-columns: repeat(2, minmax(0, 1fr)); }
            .dp-meals { grid-template-columns: 1fr; }
        }

        @media (max-width: 860px) {
            .sidebar { position: fixed; top: 0; left: 0; bottom: 0; transform: translateX(-100%); width: 270px; }
            .shell.drawer-open .sidebar { transform: translateX(0); }
            .shell.collapsed .sidebar { width: 280px; }
            .shell.collapsed .brand { flex-direction: row; align-items: center; gap: 12px; padding: 6px 46px 26px 10px; }
            .shell.collapsed .brand-text, .shell.collapsed .nav-label { display: block; }
            .shell.collapsed .nav-item { justify-content: flex-start; padding: 12px 14px; }
            .rail-toggle, .shell.collapsed .rail-toggle { top: 18px; right: 14px; transform: none; }
            .rail-toggle .ic-collapse { display: none; }
            .rail-toggle .ic-close { display: block; }
            .hamburger { display: flex; }
            .main { padding: 16px 16px 50px; }
            .dp-hero { flex-direction: column; align-items: flex-start; }
            .dp-hero .hstats { width: 100%; justify-content: space-between; }
            .dp-dl { width: 100%; justify-content: center; }
            .profile-role { display: none; }
        }

        /* ════════ Mobile hardening ════════ */
        @media (max-width: 640px) {
            .main { padding: 14px 13px 44px; }
            .topbar { gap: 10px; }
            .topbar-right { gap: 10px; }
            .profile-chip .profile-name {
                max-width: 92px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
            }
            .dp-hero { padding: 20px; }
            .dp-hero h1 { font-size: 18px; }
            .dp-hero .hstats { gap: 16px; }
            .dp-hero .hstat .n { font-size: 18px; overflow-wrap: anywhere; }
            .dp-head { padding: 15px 16px; gap: 10px; }
            .dp-body { padding: 0 16px 16px; }
            .dp-meta { grid-template-columns: repeat(2, minmax(0, 1fr)); }
            .dp-foot { flex-direction: column; align-items: stretch; }
            .dp-dl-sm { width: 100%; justify-content: center; }
        }

        @media (max-width: 400px) {
            .profile-chip > div:last-child { display: none; }
            .page-title { font-size: 16px; }
            .dp-meta { grid-template-columns: minmax(0, 1fr); }
            .dp-phase { font-size: 14px; }
        }

        a:focus-visible, button:focus-visible { outline: 2px solid var(--coral-dark); outline-offset: 2px; }
        @media (prefers-reduced-motion: reduce) { * { transition-duration: .001s !important; } }
    </style>
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

            <nav class="nav-group">
                <a class="nav-item" href="user_dashboard.php">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                        stroke-linejoin="round">
                        <rect x="3" y="3" width="7" height="9" rx="1.5" />
                        <rect x="14" y="3" width="7" height="5" rx="1.5" />
                        <rect x="14" y="12" width="7" height="9" rx="1.5" />
                        <rect x="3" y="16" width="7" height="5" rx="1.5" />
                    </svg>
                    <span class="nav-label">Dashboard</span>
                </a>
                <a class="nav-item" href="my_profile.php">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                        stroke-linejoin="round">
                        <circle cx="12" cy="8" r="4" />
                        <path d="M4 21c0-4.4 3.6-8 8-8s8 3.6 8 8" />
                    </svg>
                    <span class="nav-label">My Profile</span>
                </a>
                <a class="nav-item active" href="diet_plans.php">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                        stroke-linejoin="round">
                        <path d="M4 3h12l4 4v14H4z" />
                        <path d="M9 8h6M9 12h6M9 16h4" />
                    </svg>
                    <span class="nav-label">Diet Plans</span>
                </a>
                <a class="nav-item" href="#">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                        stroke-linejoin="round">
                        <rect x="3" y="5" width="18" height="14" rx="2" />
                        <path d="M3 10h18" />
                    </svg>
                    <span class="nav-label">Membership</span>
                </a>
                <a class="nav-item" href="#">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                        stroke-linejoin="round">
                        <path d="M4 4h16v16H4z" />
                        <path d="M8 9h8M8 13h8M8 17h4" />
                    </svg>
                    <span class="nav-label">Payments &amp; Invoices</span>
                </a>
                <a class="nav-item" href="#">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                        stroke-linejoin="round">
                        <path d="M18 8a6 6 0 10-12 0c0 7-3 9-3 9h18s-3-2-3-9" />
                        <path d="M13.7 21a2 2 0 01-3.4 0" />
                    </svg>
                    <span class="nav-label">Notifications</span>
                </a>
            </nav>

            <div class="nav-bottom">
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
                    <button class="hamburger" id="hamburgerBtn" aria-label="Open menu">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                            stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M4 7h16M4 12h16M4 17h16" />
                        </svg>
                    </button>
                    <div class="page-title">Diet Plans</div>
                </div>
                <div class="topbar-right">
                    <div class="profile-chip">
                        <div class="avatar"><?= htmlspecialchars($initials, ENT_QUOTES, 'UTF-8') ?></div>
                        <div>
                            <div class="profile-name"><?= htmlspecialchars($acct_name, ENT_QUOTES, 'UTF-8') ?></div>
                            <div class="profile-role">Member</div>
                        </div>
                    </div>
                </div>
            </div>

            <?php if (empty($plans)): ?>
                <div class="dp-empty">
                    <div class="ic">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"
                            stroke-linecap="round" stroke-linejoin="round">
                            <path d="M4 3h12l4 4v14H4z" />
                            <path d="M9 9h6M9 13h6M9 17h4" />
                        </svg>
                    </div>
                    <?php if (!$mid || !$pstatus['complete']): ?>
                        <h2>Finish your profile first</h2>
                        <p>Your trainer builds a personalised diet plan from your profile details. Complete it and your
                            plans will show up here.</p>
                        <a class="btn" href="my_profile.php">
                            Complete profile
                            <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor"
                                stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M5 12h14M13 6l6 6-6 6" />
                            </svg>
                        </a>
                    <?php else: ?>
                        <h2>No diet plans yet</h2>
                        <p>Your trainer hasn't shared a plan yet. When they do, it'll appear here and also arrive at
                            <b><?= htmlspecialchars($member_email, ENT_QUOTES, 'UTF-8') ?></b>.
                        </p>
                    <?php endif; ?>
                </div>
            <?php else: ?>

                <div class="dp-hero">
                    <div class="htxt">
                        <h1>Your Diet Plans</h1>
                        <p>Personalised by your trainer. Every plan is also emailed to
                            <b><?= htmlspecialchars($member_email, ENT_QUOTES, 'UTF-8') ?></b>. The download includes all
                            earlier phases so you have the full history.</p>
                    </div>
                    <div class="hstats">
                        <div class="hstat">
                            <div class="n"><?= count($plans) ?></div>
                            <div class="l">Plan<?= count($plans) === 1 ? '' : 's' ?></div>
                        </div>
                        <div class="hstat">
                            <div class="n"><?= htmlspecialchars(dp_phase($latest['plan_name']), ENT_QUOTES, 'UTF-8') ?>
                            </div>
                            <div class="l">Latest phase</div>
                        </div>
                    </div>
                    <a class="dp-dl" href="../../handlers/user_diet_pdf.php?plan_id=<?= (int) $latest['id'] ?>">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4"
                            stroke-linecap="round" stroke-linejoin="round">
                            <path d="M12 3v12M7 10l5 5 5-5M5 21h14" />
                        </svg>
                        Download full plan
                    </a>
                </div>

                <div class="dp-list">
                    <?php foreach ($plans_desc as $i => $p):
                        $phase = dp_phase($p['plan_name']);
                        $dt = $p['diet_type'] ?? 'veg';
                        $isLatest = ($i === 0);
                        ?>
                        <div class="dp-card <?= $isLatest ? 'open' : '' ?>">
                            <button type="button" class="dp-head" aria-expanded="<?= $isLatest ? 'true' : 'false' ?>">
                                <div class="grow">
                                    <div class="dp-phase">
                                        <?= htmlspecialchars($phase, ENT_QUOTES, 'UTF-8') ?>
                                        <span class="dp-tag <?= htmlspecialchars($dt, ENT_QUOTES) ?>">
                                            <?= htmlspecialchars($diet_label[$dt] ?? $dt, ENT_QUOTES, 'UTF-8') ?></span>
                                        <?php if ($isLatest): ?><span class="dp-tag latest">Latest</span><?php endif; ?>
                                    </div>
                                    <div class="dp-sub">
                                        Shared <?= date('d M Y', strtotime($p['created_at'])) ?>
                                        <?php if (!empty($p['trainer_name'])): ?>
                                            &nbsp;·&nbsp; by <?= htmlspecialchars($p['trainer_name'], ENT_QUOTES, 'UTF-8') ?>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <span class="dp-chev">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4"
                                        stroke-linecap="round" stroke-linejoin="round">
                                        <path d="M6 9l6 6 6-6" />
                                    </svg>
                                </span>
                            </button>

                            <div class="dp-body">
                                <div class="dp-meta">
                                    <div class="box">
                                        <div class="k">Goal</div>
                                        <div class="val"><?= htmlspecialchars($p['goal'] ?: '—', ENT_QUOTES, 'UTF-8') ?>
                                        </div>
                                    </div>
                                    <div class="box">
                                        <div class="k">Duration</div>
                                        <div class="val"><?= (int) $p['duration'] ?> weeks</div>
                                    </div>
                                    <div class="box">
                                        <div class="k">Calories</div>
                                        <div class="val">
                                            <?= (int) $p['calories'] > 0 ? number_format((int) $p['calories']) . ' kcal' : '—' ?>
                                        </div>
                                    </div>
                                    <div class="box">
                                        <div class="k">Trainer</div>
                                        <div class="val">
                                            <?= htmlspecialchars($p['trainer_name'] ?: '—', ENT_QUOTES, 'UTF-8') ?></div>
                                    </div>
                                </div>

                                <div class="dp-meals">
                                    <?php
                                    $blocks = [
                                        'Morning'        => $p['breakfast'] ?? '',
                                        'Lunch'          => $p['lunch'] ?? '',
                                        'Mid Meal'       => $p['snack'] ?? '',
                                        'Evening / Night' => $p['dinner'] ?? '',
                                    ];
                                    foreach ($blocks as $title => $txt): ?>
                                        <div class="meal-block">
                                            <div class="meal-title"><span class="d"></span><?= $title ?></div>
                                            <div class="meal-content"><?= dp_meal($txt) ?></div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>

                                <div class="dp-foot">
                                    <span class="note">PDF includes this phase and every earlier one.</span>
                                    <a class="dp-dl-sm"
                                        href="../../handlers/user_diet_pdf.php?plan_id=<?= (int) $p['id'] ?>">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4"
                                            stroke-linecap="round" stroke-linejoin="round">
                                            <path d="M12 3v12M7 10l5 5 5-5M5 21h14" />
                                        </svg>
                                        Download PDF
                                    </a>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <footer class="note">JOF India · Joshuaa's Outdoor Fitness — Member Portal</footer>
        </main>
    </div>

    <script>
        /* Sidebar collapse / drawer */
        const shell = document.getElementById('shell');
        const overlay = document.getElementById('overlay');
        const railToggle = document.getElementById('railToggle');
        const hamburgerBtn = document.getElementById('hamburgerBtn');
        const isMobile = () => window.matchMedia('(max-width: 860px)').matches;

        function openDrawer() { shell.classList.add('drawer-open'); overlay.classList.add('show'); document.body.style.overflow = 'hidden'; }
        function closeDrawer() { shell.classList.remove('drawer-open'); overlay.classList.remove('show'); document.body.style.overflow = ''; }

        hamburgerBtn?.addEventListener('click', openDrawer);
        overlay?.addEventListener('click', closeDrawer);
        railToggle?.addEventListener('click', () => { isMobile() ? closeDrawer() : shell.classList.toggle('collapsed'); });
        document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && shell.classList.contains('drawer-open')) closeDrawer(); });
        window.addEventListener('resize', () => { if (!isMobile()) closeDrawer(); });
        document.querySelectorAll('.sidebar .nav-item').forEach(item => {
            item.addEventListener('click', (e) => {
                const href = item.getAttribute('href');
                if (!href || href === '#') e.preventDefault();
                if (isMobile()) closeDrawer();
            });
        });

        /* Accordion */
        document.querySelectorAll('.dp-head').forEach(btn => {
            btn.addEventListener('click', () => {
                const card = btn.closest('.dp-card');
                const open = card.classList.toggle('open');
                btn.setAttribute('aria-expanded', open ? 'true' : 'false');
            });
        });
    </script>
</body>

</html>
