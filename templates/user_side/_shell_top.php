<?php
/**
 * templates/user_side/_shell_top.php
 * Shared sidebar/topbar shell for member-portal pages.
 * The including page must set $ACTIVE_NAV ('dashboard'|'profile'|'membership'|'diet'|'payments')
 * and $PAGE_TITLE before requiring this file, then require _shell_bottom.php after its own content.
 */

require_once __DIR__ . '/../../auth/auth_check.php';
require_role(['user']);
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../auth/diet_plan_schema.php';
require_once __DIR__ . '/../../auth/profile_helper.php';

$user = get_session_user();
$uid  = (int) $user['id'];

$pc = $conn->query("SELECT full_name, email, profile_completed, profile_pic FROM user_data WHERE id = $uid")->fetch_assoc();
$profile_incomplete = !$pc || !user_profile_status($conn, $uid)['complete'];
$pfp_url = (!empty($pc['profile_pic']) && is_file(__DIR__ . '/../../uploads/profile_pics/' . $pc['profile_pic']))
    ? '../../uploads/profile_pics/' . rawurlencode($pc['profile_pic'])
    : null;
$u_name = $user['name'] ?: 'Member';
$u_initials = strtoupper(mb_substr($u_name, 0, 1) . (str_contains($u_name, ' ') ? mb_substr(strrchr($u_name, ' '), 1, 1) : ''));

$ACTIVE_NAV = $ACTIVE_NAV ?? '';
$PAGE_TITLE = $PAGE_TITLE ?? 'Member Portal';

function shell_nav_active($key, $active) { return $key === $active ? ' active' : ''; }
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($PAGE_TITLE) ?> — JOF India</title>
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
        }

        body {
            font-family: 'Inter', sans-serif;
            background: var(--bg);
            color: var(--ink);
            -webkit-font-smoothing: antialiased;
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

            .profile-role {
                display: none;
            }
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

        /* ===== Mobile hardening ===== */
        @media (max-width: 640px) {
            .main { padding: 14px 13px 50px; }
            .topbar { gap: 10px; }
            .topbar-right { gap: 8px; }
            .icon-btn { width: 38px; height: 38px; }
            .profile-chip { padding: 4px 10px 4px 4px; gap: 8px; }
            .profile-chip .profile-name {
                max-width: 88px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
            }
            .page-title { font-size: 17px; }
        }

        @media (max-width: 400px) {
            .profile-chip > div:last-child { display: none; }
            .page-title { font-size: 16px; }
        }
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
                <a class="nav-item<?= shell_nav_active('dashboard', $ACTIVE_NAV) ?>" href="user_dashboard.php">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                        stroke-linejoin="round">
                        <rect x="3" y="3" width="7" height="9" rx="1.5" />
                        <rect x="14" y="3" width="7" height="5" rx="1.5" />
                        <rect x="14" y="12" width="7" height="9" rx="1.5" />
                        <rect x="3" y="16" width="7" height="5" rx="1.5" />
                    </svg>
                    <span class="nav-label">Dashboard</span>
                </a>
                <a class="nav-item<?= shell_nav_active('profile', $ACTIVE_NAV) ?>" href="user_profile.php">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                        stroke-linejoin="round">
                        <circle cx="12" cy="8" r="4" />
                        <path d="M4 21c0-4.4 3.6-8 8-8s8 3.6 8 8" />
                    </svg>
                    <span class="nav-label">My Profile</span>
                    <?php if ($profile_incomplete): ?><span style="margin-left:auto;width:8px;height:8px;border-radius:50%;background:#FF6B47;flex-shrink:0;"></span><?php endif; ?>
                </a>
                <a class="nav-item<?= shell_nav_active('membership', $ACTIVE_NAV) ?>" href="user_membership.php">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                        stroke-linejoin="round">
                        <rect x="3" y="5" width="18" height="14" rx="2" />
                        <path d="M3 10h18" />
                    </svg>
                    <span class="nav-label">Membership</span>
                </a>
                <a class="nav-item<?= shell_nav_active('diet', $ACTIVE_NAV) ?>" href="user_diet_plans.php">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                        stroke-linejoin="round">
                        <path d="M4 3h12l4 4v14H4z" />
                        <path d="M9 8h6M9 12h6M9 16h4" />
                    </svg>
                    <span class="nav-label">Diet Plan</span>
                </a>
                <a class="nav-item<?= shell_nav_active('payments', $ACTIVE_NAV) ?>" href="user_payments.php">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                        stroke-linejoin="round">
                        <path d="M4 4h16v16H4z" />
                        <path d="M8 9h8M8 13h8M8 17h4" />
                    </svg>
                    <span class="nav-label">Payments &amp; Invoices</span>
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
                    <div class="page-title"><?= htmlspecialchars($PAGE_TITLE) ?></div>
                </div>
                <div class="topbar-right">
                    <div class="shell-dropdown-container">
                        <button class="icon-btn" id="msgBellBtn" aria-label="Messages" title="Messages from your trainer">
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
