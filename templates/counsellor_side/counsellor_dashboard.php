<?php
require_once __DIR__ . '/../../auth/auth_check.php';
require_role(['counsellor']);
$user = get_session_user();
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>JOF India — Counsellor Dashboard</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link
        href="https://fonts.googleapis.com/css2?family=Sora:wght@600;700;800&family=Inter:wght@400;500;600;700&display=swap"
        rel="stylesheet">
    <style>
        :root {
            --bg: #F4F5F7;
            --card: #FFFFFF;
            --border: #ECEEF1;
            --navy: #1E2E36;
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
            --blue: #3B6FE0;
            --blue-tint: #EAF2FF;
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

        input {
            font-family: inherit;
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

        .role-badge {
            margin: 0 8px 18px 8px;
            padding: 8px 12px;
            background: var(--coral-tint);
            color: var(--coral-dark);
            border-radius: 10px;
            font-size: 11.5px;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 7px;
        }

        .shell.collapsed .role-badge {
            display: none;
        }

        .nav-group {
            flex: 1;
            display: flex;
            flex-direction: column;
            gap: 2px;
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
            flex-shrink: 0;
        }

        /* ===== Main ===== */
        .main {
            flex: 1;
            min-width: 0;
            padding: 20px 28px 60px;
        }

        .topbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 20px;
            gap: 14px;
            flex-wrap: wrap;
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

        .page-sub {
            font-size: 12.5px;
            color: var(--ink-soft);
            margin-top: 2px;
        }

        .topbar-right {
            display: flex;
            align-items: center;
            gap: 12px;
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
            flex-shrink: 0;
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
            flex-shrink: 0;
        }

        .avatar.lg {
            width: 56px;
            height: 56px;
            font-size: 18px;
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

        /* ===== Stat cards ===== */
        .stat-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 16px;
            margin-bottom: 20px;
        }

        .stat-card {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 18px 20px;
            box-shadow: var(--shadow);
            display: flex;
            align-items: center;
            gap: 14px;
        }

        .stat-icon {
            width: 44px;
            height: 44px;
            border-radius: 12px;
            flex-shrink: 0;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .stat-icon svg {
            width: 20px;
            height: 20px;
        }

        .stat-num {
            font-size: 22px;
            font-weight: 800;
            font-family: 'Sora', sans-serif;
            line-height: 1.1;
        }

        .stat-label {
            font-size: 12.5px;
            color: var(--ink-soft);
            margin-top: 2px;
        }

        /* ===== General card ===== */
        .card {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 20px;
            box-shadow: var(--shadow);
        }

        .card-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 14px;
            gap: 10px;
        }

        .card-title {
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 15px;
            font-weight: 700;
        }

        .card-icon {
            width: 32px;
            height: 32px;
            border-radius: 9px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        .card-icon svg {
            width: 16px;
            height: 16px;
        }

        .link-btn {
            font-size: 12.5px;
            font-weight: 700;
            color: var(--coral-dark);
            flex-shrink: 0;
        }

        .two-col {
            display: grid;
            grid-template-columns: 1.3fr 1fr;
            gap: 18px;
            margin-bottom: 20px;
            align-items: start;
        }

        /* Schedule list */
        .sched-row {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 11px 0;
            border-bottom: 1px solid var(--border);
        }

        .sched-row:last-child {
            border-bottom: none;
        }

        .sched-time {
            width: 64px;
            flex-shrink: 0;
            text-align: center;
            font-size: 13px;
            font-weight: 700;
            color: var(--ink);
            background: var(--bg);
            border-radius: 9px;
            padding: 7px 4px;
        }

        .sched-info b {
            font-size: 13.5px;
            display: block;
        }

        .sched-info span {
            font-size: 12.5px;
            color: var(--ink-soft);
        }

        .sched-type {
            margin-left: auto;
            font-size: 11px;
            font-weight: 700;
            padding: 4px 9px;
            border-radius: 20px;
            flex-shrink: 0;
        }

        .sched-type.pt {
            background: var(--green-tint);
            color: var(--green);
        }

        .sched-type.consult {
            background: var(--blue-tint);
            color: var(--blue);
        }

        /* Alert list */
        .alert-row {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 10px 0;
            border-bottom: 1px solid var(--border);
        }

        .alert-row:last-child {
            border-bottom: none;
        }

        .alert-row .txt b {
            font-size: 13.5px;
            display: block;
        }

        .alert-row .txt span {
            font-size: 12px;
            color: var(--ink-soft);
        }

        .pill {
            font-size: 11px;
            font-weight: 700;
            padding: 4px 9px;
            border-radius: 20px;
            margin-left: auto;
            flex-shrink: 0;
        }

        .pill.red {
            background: var(--red-tint);
            color: var(--red);
        }

        .pill.amber {
            background: var(--amber-tint);
            color: #B87814;
        }

        .pill.green {
            background: var(--green-tint);
            color: var(--green);
        }

        .tabs-mini {
            display: flex;
            gap: 6px;
            margin-bottom: 12px;
        }

        .tab-mini {
            font-size: 12px;
            font-weight: 700;
            padding: 7px 12px;
            border-radius: 9px;
            background: var(--bg);
            color: var(--ink-soft);
            border: 1px solid transparent;
        }

        .tab-mini.active {
            background: var(--coral-tint);
            color: var(--coral-dark);
        }

        /* ===== Members section ===== */
        .members-toolbar {
            display: flex;
            gap: 10px;
            align-items: center;
            margin-bottom: 16px;
            flex-wrap: wrap;
        }

        .search-box {
            flex: 1;
            min-width: 220px;
            display: flex;
            align-items: center;
            gap: 9px;
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 11px 14px;
        }

        .search-box svg {
            width: 17px;
            height: 17px;
            color: var(--ink-faint);
            flex-shrink: 0;
        }

        .search-box input {
            border: none;
            outline: none;
            font-size: 13.5px;
            width: 100%;
            background: transparent;
        }

        .filter-chip {
            font-size: 12.5px;
            font-weight: 700;
            padding: 9px 14px;
            border-radius: 20px;
            background: var(--card);
            border: 1px solid var(--border);
            color: var(--ink-soft);
            flex-shrink: 0;
        }

        .filter-chip.active {
            background: var(--coral);
            color: #fff;
            border-color: var(--coral);
        }

        .member-table {
            width: 100%;
            border-collapse: collapse;
        }

        .member-table th {
            text-align: left;
            font-size: 11.5px;
            text-transform: uppercase;
            letter-spacing: .03em;
            color: var(--ink-faint);
            font-weight: 700;
            padding: 0 14px 10px;
        }

        .member-table td {
            padding: 12px 14px;
            font-size: 13.5px;
            border-top: 1px solid var(--border);
        }

        .member-table tr:hover td {
            background: var(--bg);
        }

        .member-name-cell {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .member-name-cell b {
            font-size: 13.5px;
        }

        .member-name-cell span {
            font-size: 12px;
            color: var(--ink-soft);
            display: block;
        }

        .view-btn {
            background: var(--coral-tint);
            color: var(--coral-dark);
            font-weight: 700;
            font-size: 12.5px;
            padding: 8px 14px;
            border-radius: 9px;
            border: none;
        }

        .view-btn:hover {
            background: var(--coral);
            color: #fff;
        }

        .member-cards {
            display: none;
            flex-direction: column;
            gap: 12px;
        }

        .member-card {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 14px;
            padding: 14px;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .member-card .grow {
            flex: 1;
            min-width: 0;
        }

        .member-card b {
            font-size: 14px;
            display: block;
        }

        .member-card .sub {
            font-size: 12px;
            color: var(--ink-soft);
            margin-top: 1px;
        }

        .member-card .badges {
            display: flex;
            gap: 6px;
            margin-top: 6px;
            flex-wrap: wrap;
        }

        /* ===== Slide-over profile panel ===== */
        .panel-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(20, 22, 30, .45);
            z-index: 60;
        }

        .panel-overlay.show {
            display: block;
        }

        .profile-panel {
            position: fixed;
            top: 0;
            right: 0;
            bottom: 0;
            width: 460px;
            max-width: 100vw;
            background: var(--bg);
            z-index: 65;
            transform: translateX(100%);
            transition: transform .3s cubic-bezier(.32, .72, .35, 1);
            display: flex;
            flex-direction: column;
            box-shadow: -12px 0 40px -12px rgba(20, 20, 30, .3);
        }

        .profile-panel.open {
            transform: translateX(0);
        }

        .panel-header {
            background: var(--card);
            border-bottom: 1px solid var(--border);
            padding: 20px;
            display: flex;
            align-items: flex-start;
            gap: 14px;
            flex-shrink: 0;
        }

        .panel-header .grow {
            flex: 1;
            min-width: 0;
        }

        .panel-header h2 {
            font-size: 17px;
            font-weight: 700;
        }

        .panel-header .sub {
            font-size: 12.5px;
            color: var(--ink-soft);
            margin-top: 2px;
        }

        .panel-close {
            width: 34px;
            height: 34px;
            border-radius: 9px;
            border: 1px solid var(--border);
            background: var(--bg);
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            color: var(--ink-soft);
        }

        .panel-badges {
            display: flex;
            gap: 6px;
            margin-top: 8px;
            flex-wrap: wrap;
        }

        .panel-tabs {
            display: flex;
            gap: 4px;
            background: var(--card);
            border-bottom: 1px solid var(--border);
            padding: 0 16px;
            overflow-x: auto;
            flex-shrink: 0;
        }

        .panel-tab {
            padding: 13px 12px;
            font-size: 13px;
            font-weight: 700;
            color: var(--ink-soft);
            border-bottom: 2px solid transparent;
            white-space: nowrap;
        }

        .panel-tab.active {
            color: var(--coral-dark);
            border-color: var(--coral);
        }

        .panel-body {
            flex: 1;
            overflow-y: auto;
            padding: 20px;
        }

        .panel-tabpage {
            display: none;
            flex-direction: column;
            gap: 14px;
        }

        .panel-tabpage.active {
            display: flex;
        }

        .info-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
        }

        .info-item {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 12px 14px;
        }

        .info-item .lbl {
            font-size: 11px;
            color: var(--ink-faint);
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: .03em;
        }

        .info-item .val {
            font-size: 14px;
            font-weight: 700;
            margin-top: 4px;
        }

        .measure-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 10px;
        }

        .measure-item {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 12px;
            text-align: center;
        }

        .measure-item .num {
            font-size: 17px;
            font-weight: 800;
            font-family: 'Sora', sans-serif;
        }

        .measure-item .lbl {
            font-size: 11px;
            color: var(--ink-soft);
            margin-top: 2px;
        }

        .photo-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 8px;
        }

        .photo-ph {
            aspect-ratio: 3/4;
            border-radius: 10px;
            background: linear-gradient(150deg, #EFE4DC, #E4D6CC);
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--ink-faint);
        }

        .photo-ph svg {
            width: 24px;
            height: 24px;
        }

        .plan-tag {
            display: inline-block;
            background: var(--coral-tint);
            color: var(--coral-dark);
            font-size: 12px;
            font-weight: 700;
            padding: 4px 10px;
            border-radius: 8px;
        }

        .meal-row {
            display: flex;
            justify-content: space-between;
            font-size: 13.5px;
            padding: 8px 0;
            border-bottom: 1px solid var(--border);
        }

        .meal-row:last-child {
            border-bottom: none;
        }

        .meal-row span:first-child {
            color: var(--ink-soft);
        }

        .meal-row span:last-child {
            font-weight: 600;
        }

        .history-row {
            display: flex;
            gap: 12px;
            padding: 10px 0;
            border-bottom: 1px solid var(--border);
        }

        .history-row:last-child {
            border-bottom: none;
        }

        .history-dot {
            width: 9px;
            height: 9px;
            border-radius: 50%;
            background: var(--coral);
            margin-top: 5px;
            flex-shrink: 0;
        }

        .history-row b {
            font-size: 13.5px;
            display: block;
        }

        .history-row span {
            font-size: 12px;
            color: var(--ink-soft);
        }

        .note-item {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 12px 14px;
        }

        .note-item .note-meta {
            font-size: 11.5px;
            color: var(--ink-faint);
            margin-bottom: 5px;
        }

        .note-item p {
            font-size: 13.5px;
            line-height: 1.5;
        }

        .note-input-wrap {
            display: flex;
            gap: 8px;
            margin-top: 4px;
        }

        .note-input-wrap textarea {
            flex: 1;
            resize: none;
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 11px 13px;
            font-family: inherit;
            font-size: 13.5px;
            outline: none;
            min-height: 44px;
        }

        .note-input-wrap textarea:focus {
            border-color: var(--coral);
        }

        .note-send {
            background: var(--coral);
            color: #fff;
            border: none;
            border-radius: 12px;
            padding: 0 16px;
            font-weight: 700;
            font-size: 13px;
            flex-shrink: 0;
        }

        .note-send:hover {
            background: var(--coral-dark);
        }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 11px 16px;
            border-radius: 12px;
            font-weight: 700;
            font-size: 13.5px;
            border: none;
        }

        .btn-primary {
            background: var(--coral);
            color: #fff;
        }

        .btn-primary:hover {
            background: var(--coral-dark);
        }

        .btn-ghost {
            background: var(--card);
            color: var(--ink);
            border: 1px solid var(--border);
        }

        .btn-ghost:hover {
            border-color: var(--coral);
            color: var(--coral-dark);
        }

        footer.note {
            text-align: center;
            font-size: 12.5px;
            color: var(--ink-faint);
            margin-top: 24px;
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
        @media (max-width: 1180px) {
            .stat-grid {
                grid-template-columns: repeat(2, 1fr);
            }

            .two-col {
                grid-template-columns: 1fr;
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

            .shell.collapsed .role-badge {
                display: flex;
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
                padding: 16px 14px 50px;
            }

            .stat-grid {
                grid-template-columns: 1fr 1fr;
                gap: 12px;
            }

            .member-table {
                display: none;
            }

            .member-cards {
                display: flex;
            }

            .profile-panel {
                width: 100%;
            }

            .toast-container {
                left: 12px;
                right: 12px;
                top: 12px;
                max-width: none;
            }
        }

        @media (max-width:480px) {
            .stat-grid {
                grid-template-columns: 1fr 1fr;
            }

            .page-title {
                font-size: 17px;
            }

            .info-grid {
                grid-template-columns: 1fr;
            }

            .measure-grid {
                grid-template-columns: repeat(3, 1fr);
            }
        }

        a:focus-visible,
        button:focus-visible,
        input:focus-visible,
        textarea:focus-visible {
            outline: 2px solid var(--coral-dark);
            outline-offset: 2px;
        }

        @media (prefers-reduced-motion: reduce) {
            * {
                animation-duration: .001s !important;
                transition-duration: .001s !important;
            }
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
                    <div class="brand-sub">Counsellor Portal</div>
                </div>
            </div>

            <div class="role-badge">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                    stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="12" cy="8" r="4" />
                    <path d="M4 21c0-4.4 3.6-8 8-8s8 3.6 8 8" />
                </svg>
                Counsellor · Limited Access
            </div>

            <nav class="nav-group">
                <a class="nav-item active" data-page="dashboard" href="#">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                        stroke-linejoin="round">
                        <rect x="3" y="3" width="7" height="9" rx="1.5" />
                        <rect x="14" y="3" width="7" height="5" rx="1.5" />
                        <rect x="14" y="12" width="7" height="9" rx="1.5" />
                        <rect x="3" y="16" width="7" height="5" rx="1.5" />
                    </svg>
                    <span class="nav-label">Dashboard</span>
                </a>
                <a class="nav-item" data-page="members" href="#">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                        stroke-linejoin="round">
                        <circle cx="9" cy="8" r="3.5" />
                        <path d="M2.5 20c0-3.6 2.9-6.5 6.5-6.5s6.5 2.9 6.5 6.5" />
                        <circle cx="17.5" cy="8.5" r="2.8" />
                        <path d="M15.7 13.6c2.9.4 5.1 2.9 5.1 6" />
                    </svg>
                    <span class="nav-label">Members</span>
                </a>
                <a class="nav-item" href="#">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                        stroke-linejoin="round">
                        <path d="M6.5 6.5l11 11M4 9l3-3 3 3-3 3zM17 22l-3-3 3-3 3 3zM2 2l2.5 2.5M22 22l-2.5-2.5" />
                    </svg>
                    <span class="nav-label">PT Sessions</span>
                </a>
                <a class="nav-item" href="#">
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
                        <path
                            d="M21 11.5a8.38 8.38 0 01-.9 3.8 8.5 8.5 0 01-7.6 4.7 8.38 8.38 0 01-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 01-.9-3.8 8.5 8.5 0 014.7-7.6 8.38 8.38 0 013.8-.9h.5a8.48 8.48 0 018 8v.5z" />
                    </svg>
                    <span class="nav-label">Consultations</span>
                </a>
                <a class="nav-item" id="notifNavItem" href="#">
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
                    <div>
                        <div class="page-title">Good morning, Priya</div>
                        <div class="page-sub">Here's what's on your plate today</div>
                    </div>
                </div>
                <div class="topbar-right">
                    <button class="icon-btn" id="notifBtn" aria-label="Notifications">
                        <svg width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                            stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M18 8a6 6 0 10-12 0c0 7-3 9-3 9h18s-3-2-3-9" />
                            <path d="M13.7 21a2 2 0 01-3.4 0" />
                        </svg>
                        <span class="unread-dot" id="unreadDot"></span>
                    </button>
                    <div class="profile-chip">
                        <div class="avatar">PK</div>
                        <div>
                            <div class="profile-name">Priya Kulkarni</div>
                            <div class="profile-role">Fitness Counsellor</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Stat cards -->
            <div class="stat-grid">
                <div class="stat-card">
                    <div class="stat-icon" style="background:var(--coral-tint); color:var(--coral-dark);">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                            stroke-linecap="round" stroke-linejoin="round">
                            <circle cx="9" cy="8" r="3.5" />
                            <path d="M2.5 20c0-3.6 2.9-6.5 6.5-6.5s6.5 2.9 6.5 6.5" />
                            <circle cx="17.5" cy="8.5" r="2.8" />
                        </svg>
                    </div>
                    <div>
                        <div class="stat-num">36</div>
                        <div class="stat-label">Assigned members</div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon" style="background:var(--blue-tint); color:var(--blue);">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                            stroke-linecap="round" stroke-linejoin="round">
                            <rect x="3" y="4" width="18" height="17" rx="2" />
                            <path d="M8 2v4M16 2v4M3 10h18" />
                        </svg>
                    </div>
                    <div>
                        <div class="stat-num">7</div>
                        <div class="stat-label">Sessions today</div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon" style="background:var(--amber-tint); color:var(--amber);">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                            stroke-linecap="round" stroke-linejoin="round">
                            <circle cx="12" cy="12" r="9" />
                            <path d="M12 8v4l3 2" />
                        </svg>
                    </div>
                    <div>
                        <div class="stat-num">5</div>
                        <div class="stat-label">Need follow-up</div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon" style="background:var(--red-tint); color:var(--red);">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                            stroke-linecap="round" stroke-linejoin="round">
                            <path d="M12 9v4M12 17h.01" />
                            <path d="M10.3 3.9L1.8 18a2 2 0 001.7 3h17a2 2 0 001.7-3L13.7 3.9a2 2 0 00-3.4 0z" />
                        </svg>
                    </div>
                    <div>
                        <div class="stat-num">4</div>
                        <div class="stat-label">Memberships expiring</div>
                    </div>
                </div>
            </div>

            <!-- Two column: schedule + alerts -->
            <div class="two-col">
                <div class="card">
                    <div class="card-head">
                        <div class="card-title">
                            <div class="card-icon" style="background:var(--blue-tint); color:var(--blue);">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                    stroke-linecap="round" stroke-linejoin="round">
                                    <rect x="3" y="4" width="18" height="17" rx="2" />
                                    <path d="M3 10h18" />
                                </svg>
                            </div>
                            Today's Schedule
                        </div>
                        <span class="link-btn">View calendar</span>
                    </div>

                    <div class="sched-row">
                        <div class="sched-time">9:00 AM</div>
                        <div class="sched-info"><b>Ananya Verma</b><span>Initial consultation · Goal setting</span>
                        </div>
                        <span class="sched-type consult">Consult</span>
                    </div>
                    <div class="sched-row">
                        <div class="sched-time">10:30 AM</div>
                        <div class="sched-info"><b>Rohan Sharma</b><span>Strength training · Upper body</span></div>
                        <span class="sched-type pt">PT</span>
                    </div>
                    <div class="sched-row">
                        <div class="sched-time">12:00 PM</div>
                        <div class="sched-info"><b>Karan Mehta</b><span>Monthly progress review</span></div>
                        <span class="sched-type consult">Consult</span>
                    </div>
                    <div class="sched-row">
                        <div class="sched-time">4:30 PM</div>
                        <div class="sched-info"><b>Sana Iyer</b><span>Mobility &amp; recovery session</span></div>
                        <span class="sched-type pt">PT</span>
                    </div>
                    <div class="sched-row">
                        <div class="sched-time">6:00 PM</div>
                        <div class="sched-info"><b>Vikram Nair</b><span>Diet plan check-in</span></div>
                        <span class="sched-type consult">Consult</span>
                    </div>
                </div>

                <div class="card">
                    <div class="tabs-mini">
                        <button class="tab-mini active" data-tab="alerts">Expiry Alerts</button>
                        <button class="tab-mini" data-tab="new">Recently Added</button>
                    </div>

                    <div id="alertsList">
                        <div class="alert-row">
                            <div class="txt"><b>Vikram Nair</b><span>Expires in 2 days</span></div>
                            <span class="pill red">Urgent</span>
                        </div>
                        <div class="alert-row">
                            <div class="txt"><b>Meera Joshi</b><span>Expires in 5 days</span></div>
                            <span class="pill amber">Soon</span>
                        </div>
                        <div class="alert-row">
                            <div class="txt"><b>Arjun Rao</b><span>Expires in 6 days</span></div>
                            <span class="pill amber">Soon</span>
                        </div>
                        <div class="alert-row">
                            <div class="txt"><b>Divya Pillai</b><span>Expires in 9 days</span></div>
                            <span class="pill amber">Soon</span>
                        </div>
                    </div>

                    <div id="newList" style="display:none;">
                        <div class="alert-row">
                            <div class="txt"><b>Sana Iyer</b><span>Joined 2 days ago</span></div>
                            <span class="pill green">New</span>
                        </div>
                        <div class="alert-row">
                            <div class="txt"><b>Farhan Khan</b><span>Joined 4 days ago</span></div>
                            <span class="pill green">New</span>
                        </div>
                        <div class="alert-row">
                            <div class="txt"><b>Ritika Desai</b><span>Joined 6 days ago</span></div>
                            <span class="pill green">New</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Members section -->
            <div class="card" id="membersSection">
                <div class="card-head">
                    <div class="card-title">
                        <div class="card-icon" style="background:var(--coral-tint); color:var(--coral-dark);">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                stroke-linecap="round" stroke-linejoin="round">
                                <circle cx="9" cy="8" r="3.5" />
                                <path d="M2.5 20c0-3.6 2.9-6.5 6.5-6.5s6.5 2.9 6.5 6.5" />
                            </svg>
                        </div>
                        My Members
                    </div>
                </div>

                <div class="members-toolbar">
                    <div class="search-box">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                            stroke-linecap="round" stroke-linejoin="round">
                            <circle cx="11" cy="11" r="7" />
                            <path d="M21 21l-4.3-4.3" />
                        </svg>
                        <input type="text" id="searchInput" placeholder="Search members by name or goal...">
                    </div>
                    <button class="filter-chip active" data-filter="all">All</button>
                    <button class="filter-chip" data-filter="followup">Follow-up</button>
                    <button class="filter-chip" data-filter="expiring">Expiring</button>
                </div>

                <table class="member-table">
                    <thead>
                        <tr>
                            <th>Member</th>
                            <th>Goal</th>
                            <th>Membership</th>
                            <th>Last Session</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody id="memberTableBody"></tbody>
                </table>

                <div class="member-cards" id="memberCards"></div>
            </div>

            <footer class="note">JOF India · Joshuaa's Outdoor Fitness — Counsellor Portal</footer>
        </main>
    </div>

    <!-- Slide-over member profile panel -->
    <div class="panel-overlay" id="panelOverlay"></div>
    <div class="profile-panel" id="profilePanel">
        <div class="panel-header">
            <div class="avatar lg" id="panelAvatar">RS</div>
            <div class="grow">
                <h2 id="panelName">Member name</h2>
                <div class="sub" id="panelGoal">Goal</div>
                <div class="panel-badges" id="panelBadges"></div>
            </div>
            <button class="panel-close" id="panelClose" aria-label="Close">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                    stroke-linecap="round" stroke-linejoin="round">
                    <path d="M6 6l12 12M18 6L6 18" />
                </svg>
            </button>
        </div>

        <div class="panel-tabs">
            <button class="panel-tab active" data-tabpage="overview">Overview</button>
            <button class="panel-tab" data-tabpage="diet">Diet Plan</button>
            <button class="panel-tab" data-tabpage="sessions">Sessions</button>
            <button class="panel-tab" data-tabpage="photos">Photos</button>
            <button class="panel-tab" data-tabpage="notes">Notes</button>
        </div>

        <div class="panel-body">
            <!-- Overview -->
            <div class="panel-tabpage active" id="tab-overview">
                <div class="info-grid">
                    <div class="info-item">
                        <div class="lbl">Age</div>
                        <div class="val" id="ov-age">—</div>
                    </div>
                    <div class="info-item">
                        <div class="lbl">Phone</div>
                        <div class="val" id="ov-phone">—</div>
                    </div>
                    <div class="info-item">
                        <div class="lbl">Member since</div>
                        <div class="val" id="ov-since">—</div>
                    </div>
                    <div class="info-item">
                        <div class="lbl">Membership</div>
                        <div class="val" id="ov-plan">—</div>
                    </div>
                </div>
                <div class="card" style="box-shadow:none; padding:14px;">
                    <div class="card-title" style="font-size:13.5px; margin-bottom:8px;">Primary goal</div>
                    <p style="font-size:13.5px; color:var(--ink-soft); line-height:1.5;" id="ov-goal-full">—</p>
                </div>
                <div>
                    <div class="card-title" style="font-size:13.5px; margin-bottom:10px;">Body measurements</div>
                    <div class="measure-grid" id="ov-measurements"></div>
                </div>
            </div>

            <!-- Diet -->
            <div class="panel-tabpage" id="tab-diet">
                <span class="plan-tag" id="diet-week">Week —</span>
                <div id="diet-meals"></div>
            </div>

            <!-- Sessions -->
            <div class="panel-tabpage" id="tab-sessions">
                <div id="sessions-history"></div>
            </div>

            <!-- Photos -->
            <div class="panel-tabpage" id="tab-photos">
                <div class="photo-grid">
                    <div class="photo-ph"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <rect x="3" y="3" width="18" height="18" rx="2" />
                            <circle cx="9" cy="9" r="2" />
                            <path d="M21 15l-5-5L5 21" />
                        </svg></div>
                    <div class="photo-ph"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <rect x="3" y="3" width="18" height="18" rx="2" />
                            <circle cx="9" cy="9" r="2" />
                            <path d="M21 15l-5-5L5 21" />
                        </svg></div>
                    <div class="photo-ph"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <rect x="3" y="3" width="18" height="18" rx="2" />
                            <circle cx="9" cy="9" r="2" />
                            <path d="M21 15l-5-5L5 21" />
                        </svg></div>
                </div>
                <p style="font-size:12.5px; color:var(--ink-faint); text-align:center;">Latest progress photos uploaded
                    by the member</p>
            </div>

            <!-- Notes -->
            <div class="panel-tabpage" id="tab-notes">
                <div id="notes-list" style="display:flex; flex-direction:column; gap:10px;"></div>
                <div class="note-input-wrap">
                    <textarea id="noteInput" placeholder="Add a consultation note..."></textarea>
                    <button class="note-send" id="noteSendBtn">Add</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Toast container -->
    <div class="toast-container" id="toastContainer"></div>

    <script>
        /* ===== Sample member data ===== */
        const members = [
            {
                id: 1, initials: 'RS', name: 'Rohan Sharma', age: 29, phone: '+91 98200 11223',
                since: '12 Mar 2026', plan: 'Premium · Active', status: 'active',
                goal: 'Build lean muscle and improve upper body strength over the next 6 months.',
                measurements: [['Weight', '76 kg'], ['Height', '178 cm'], ['Body fat', '17%'], ['Chest', '101 cm'], ['Waist', '84 cm'], ['Arms', '35 cm']],
                dietWeek: 'Week 6 · Lean Muscle Phase',
                meals: [['Breakfast', '7:30 AM'], ['Lunch', '1:00 PM'], ['Post-workout', '6:00 PM'], ['Dinner', '8:30 PM']],
                sessions: [['10 Sep', 'Strength Training — Upper Body', 'Completed'], ['7 Sep', 'Strength Training — Legs', 'Completed'], ['4 Sep', 'Consultation — Progress Review', 'Completed']],
                notes: [['3 Sep', 'Priya K.', 'Member reported shoulder soreness, adjusted press volume for next 2 weeks.'], ['20 Aug', 'Priya K.', 'Great progress on squat form, increasing load gradually.']],
                followup: false, expiring: false
            },
            {
                id: 2, initials: 'VN', name: 'Vikram Nair', age: 34, phone: '+91 99870 44556',
                since: '02 Feb 2025', plan: 'Standard · Expiring in 2 days', status: 'expiring',
                goal: 'Weight management and better cardiovascular endurance for daily activity.',
                measurements: [['Weight', '88 kg'], ['Height', '172 cm'], ['Body fat', '24%'], ['Chest', '106 cm'], ['Waist', '96 cm'], ['Arms', '33 cm']],
                dietWeek: 'Week 3 · Fat Loss Phase',
                meals: [['Breakfast', '7:00 AM'], ['Lunch', '12:30 PM'], ['Snack', '4:30 PM'], ['Dinner', '8:00 PM']],
                sessions: [['5 Sep', 'Diet Plan Check-in', 'Completed'], ['29 Aug', 'Cardio &amp; Core Session', 'Completed']],
                notes: [['5 Sep', 'Priya K.', 'Discussed renewal — member is interested in the annual plan, follow up before expiry.']],
                followup: true, expiring: true
            },
            {
                id: 3, initials: 'MJ', name: 'Meera Joshi', age: 41, phone: '+91 97650 22114',
                since: '18 Nov 2024', plan: 'Premium · Expiring in 5 days', status: 'expiring',
                goal: 'Post-injury mobility recovery and general fitness maintenance.',
                measurements: [['Weight', '64 kg'], ['Height', '160 cm'], ['Body fat', '26%'], ['Chest', '92 cm'], ['Waist', '78 cm'], ['Arms', '27 cm']],
                dietWeek: 'Week 10 · Maintenance Phase',
                meals: [['Breakfast', '8:00 AM'], ['Lunch', '1:30 PM'], ['Dinner', '7:30 PM']],
                sessions: [['1 Sep', 'Mobility Session', 'Completed'], ['25 Aug', 'Consultation', 'Completed']],
                notes: [['1 Sep', 'Priya K.', 'Knee mobility improving steadily, continue low-impact routine.']],
                followup: true, expiring: true
            },
            {
                id: 4, initials: 'KM', name: 'Karan Mehta', age: 26, phone: '+91 90123 78901',
                since: '05 Jun 2026', plan: 'Standard · Active', status: 'active',
                goal: 'Improve overall strength and prepare for a local amateur powerlifting meet.',
                measurements: [['Weight', '82 kg'], ['Height', '180 cm'], ['Body fat', '15%'], ['Chest', '104 cm'], ['Waist', '82 cm'], ['Arms', '37 cm']],
                dietWeek: 'Week 2 · Strength Phase',
                meals: [['Breakfast', '6:30 AM'], ['Lunch', '12:00 PM'], ['Post-workout', '5:30 PM'], ['Dinner', '9:00 PM']],
                sessions: [['6 Sep', 'Monthly Progress Review', 'Completed'], ['30 Aug', 'Strength Training', 'Completed']],
                notes: [['6 Sep', 'Priya K.', 'Squat max increased by 8kg this month, on track for meet.']],
                followup: false, expiring: false
            },
            {
                id: 5, initials: 'SI', name: 'Sana Iyer', age: 31, phone: '+91 98456 33221',
                since: '02 Sep 2026', plan: 'Premium · Active', status: 'active',
                goal: 'Recover from lower back tightness while easing back into regular training.',
                measurements: [['Weight', '58 kg'], ['Height', '163 cm'], ['Body fat', '22%'], ['Chest', '88 cm'], ['Waist', '70 cm'], ['Arms', '25 cm']],
                dietWeek: 'Week 1 · Introductory Phase',
                meals: [['Breakfast', '7:45 AM'], ['Lunch', '1:15 PM'], ['Dinner', '8:00 PM']],
                sessions: [['4 Sep', 'Mobility & Recovery Session', 'Completed']],
                notes: [['4 Sep', 'Priya K.', 'New member — flexible onboarding plan, focus on form and recovery first.']],
                followup: false, expiring: false
            },
            {
                id: 6, initials: 'AR', name: 'Arjun Rao', age: 37, phone: '+91 91234 55667',
                since: '14 Jan 2025', plan: 'Standard · Expiring in 6 days', status: 'expiring',
                goal: 'Maintain fitness level and manage stress through regular training.',
                measurements: [['Weight', '79 kg'], ['Height', '175 cm'], ['Body fat', '20%'], ['Chest', '99 cm'], ['Waist', '86 cm'], ['Arms', '32 cm']],
                dietWeek: 'Week 8 · Maintenance Phase',
                meals: [['Breakfast', '7:00 AM'], ['Lunch', '1:00 PM'], ['Dinner', '8:30 PM']],
                sessions: [['30 Aug', 'Consultation', 'Completed'], ['23 Aug', 'Strength Training', 'Completed']],
                notes: [['30 Aug', 'Priya K.', 'Reminded about renewal, member wants to check budget before deciding.']],
                followup: true, expiring: true
            },
            {
                id: 7, initials: 'DP', name: 'Divya Pillai', age: 24, phone: '+91 99001 22334',
                since: '28 Jul 2026', plan: 'Premium · Expiring in 9 days', status: 'expiring',
                goal: 'Improve flexibility and build a consistent training habit.',
                measurements: [['Weight', '55 kg'], ['Height', '158 cm'], ['Body fat', '23%'], ['Chest', '84 cm'], ['Waist', '68 cm'], ['Arms', '24 cm']],
                dietWeek: 'Week 4 · Fat Loss Phase',
                meals: [['Breakfast', '8:00 AM'], ['Lunch', '1:00 PM'], ['Snack', '5:00 PM'], ['Dinner', '8:00 PM']],
                sessions: [['2 Sep', 'Yoga & Flexibility Session', 'Completed']],
                notes: [],
                followup: false, expiring: true
            }
        ];

        /* ===== Render member table + cards ===== */
        const tbody = document.getElementById('memberTableBody');
        const cardsWrap = document.getElementById('memberCards');

        function statusPill(m) {
            if (m.status === 'expiring') return '<span class="pill amber" style="margin-left:0;">Expiring</span>';
            return '<span class="pill green" style="margin-left:0;">Active</span>';
        }

        function renderMembers(list) {
            tbody.innerHTML = '';
            cardsWrap.innerHTML = '';
            list.forEach(m => {
                const lastSession = m.sessions.length ? m.sessions[0][0] : '—';
                const row = document.createElement('tr');
                row.innerHTML = `
        <td>
          <div class="member-name-cell">
            <div class="avatar">${m.initials}</div>
            <div><b>${m.name}</b><span>${m.age} yrs</span></div>
          </div>
        </td>
        <td>${m.goal.split('.')[0]}</td>
        <td>${statusPill(m)}</td>
        <td>${lastSession}</td>
        <td><button class="view-btn" data-id="${m.id}">View</button></td>
      `;
                tbody.appendChild(row);

                const card = document.createElement('div');
                card.className = 'member-card';
                card.innerHTML = `
        <div class="avatar">${m.initials}</div>
        <div class="grow">
          <b>${m.name}</b>
          <div class="sub">${m.goal.split('.')[0]}</div>
          <div class="badges">${statusPill(m)}${m.followup ? '<span class="pill red" style="margin-left:0;">Follow-up</span>' : ''}</div>
        </div>
        <button class="view-btn" data-id="${m.id}">View</button>
      `;
                cardsWrap.appendChild(card);
            });

            document.querySelectorAll('.view-btn').forEach(btn => {
                btn.addEventListener('click', () => openProfile(Number(btn.dataset.id)));
            });
        }
        renderMembers(members);

        /* ===== Search + filter ===== */
        const searchInput = document.getElementById('searchInput');
        const filterChips = document.querySelectorAll('.filter-chip');
        let activeFilter = 'all';

        function applyFilters() {
            const q = searchInput.value.trim().toLowerCase();
            let list = members.filter(m =>
                m.name.toLowerCase().includes(q) || m.goal.toLowerCase().includes(q)
            );
            if (activeFilter === 'followup') list = list.filter(m => m.followup);
            if (activeFilter === 'expiring') list = list.filter(m => m.expiring);
            renderMembers(list);
        }
        searchInput.addEventListener('input', applyFilters);
        filterChips.forEach(chip => {
            chip.addEventListener('click', () => {
                filterChips.forEach(c => c.classList.remove('active'));
                chip.classList.add('active');
                activeFilter = chip.dataset.filter;
                applyFilters();
            });
        });

        /* ===== Mini tabs (alerts / new members) ===== */
        document.querySelectorAll('.tab-mini').forEach(tab => {
            tab.addEventListener('click', () => {
                document.querySelectorAll('.tab-mini').forEach(t => t.classList.remove('active'));
                tab.classList.add('active');
                document.getElementById('alertsList').style.display = tab.dataset.tab === 'alerts' ? 'block' : 'none';
                document.getElementById('newList').style.display = tab.dataset.tab === 'new' ? 'block' : 'none';
            });
        });

        /* ===== Profile panel ===== */
        const panel = document.getElementById('profilePanel');
        const panelOverlay = document.getElementById('panelOverlay');

        function openProfile(id) {
            const m = members.find(x => x.id === id);
            if (!m) return;

            document.getElementById('panelAvatar').textContent = m.initials;
            document.getElementById('panelName').textContent = m.name;
            document.getElementById('panelGoal').textContent = m.goal.split('.')[0];
            document.getElementById('panelBadges').innerHTML =
                statusPill(m) + (m.followup ? '<span class="pill red" style="margin-left:0;">Follow-up</span>' : '');

            document.getElementById('ov-age').textContent = m.age + ' years';
            document.getElementById('ov-phone').textContent = m.phone;
            document.getElementById('ov-since').textContent = m.since;
            document.getElementById('ov-plan').textContent = m.plan;
            document.getElementById('ov-goal-full').textContent = m.goal;

            document.getElementById('ov-measurements').innerHTML = m.measurements.map(([lbl, val]) =>
                `<div class="measure-item"><div class="num">${val}</div><div class="lbl">${lbl}</div></div>`
            ).join('');

            document.getElementById('diet-week').textContent = m.dietWeek;
            document.getElementById('diet-meals').innerHTML = m.meals.map(([name, time]) =>
                `<div class="meal-row"><span>${name}</span><span>${time}</span></div>`
            ).join('');

            document.getElementById('sessions-history').innerHTML = m.sessions.length ? m.sessions.map(([date, title, status]) =>
                `<div class="history-row"><div class="history-dot"></div><div><b>${title}</b><span>${date} · ${status}</span></div></div>`
            ).join('') : '<p style="font-size:13px; color:var(--ink-faint);">No sessions logged yet.</p>';

            renderNotes(m);

            document.querySelectorAll('.panel-tab').forEach(t => t.classList.remove('active'));
            document.querySelector('.panel-tab[data-tabpage="overview"]').classList.add('active');
            document.querySelectorAll('.panel-tabpage').forEach(p => p.classList.remove('active'));
            document.getElementById('tab-overview').classList.add('active');

            panel.dataset.currentId = id;
            panel.classList.add('open');
            panelOverlay.classList.add('show');
        }

        function renderNotes(m) {
            const list = document.getElementById('notes-list');
            list.innerHTML = m.notes.length ? m.notes.map(([date, author, text]) =>
                `<div class="note-item"><div class="note-meta">${date} · ${author}</div><p>${text}</p></div>`
            ).join('') : '<p style="font-size:13px; color:var(--ink-faint);">No notes yet. Add one below.</p>';
        }

        function closeProfile() {
            panel.classList.remove('open');
            panelOverlay.classList.remove('show');
        }
        document.getElementById('panelClose').addEventListener('click', closeProfile);
        panelOverlay.addEventListener('click', closeProfile);

        document.querySelectorAll('.panel-tab').forEach(tab => {
            tab.addEventListener('click', () => {
                document.querySelectorAll('.panel-tab').forEach(t => t.classList.remove('active'));
                tab.classList.add('active');
                document.querySelectorAll('.panel-tabpage').forEach(p => p.classList.remove('active'));
                document.getElementById('tab-' + tab.dataset.tabpage).classList.add('active');
            });
        });

        document.getElementById('noteSendBtn').addEventListener('click', () => {
            const input = document.getElementById('noteInput');
            const text = input.value.trim();
            if (!text) return;
            const id = Number(panel.dataset.currentId);
            const m = members.find(x => x.id === id);
            const today = new Date().toLocaleDateString('en-GB', { day: 'numeric', month: 'short' });
            m.notes.unshift([today, 'Priya K.', text]);
            input.value = '';
            renderNotes(m);
            showToast('Note added', `Consultation note saved for ${m.name}.`);
        });

        /* ===== Sidebar: collapse (desktop) + drawer (mobile) ===== */
        const shell = document.getElementById('shell');
        const overlay = document.getElementById('overlay');
        const railToggle = document.getElementById('railToggle');
        const hamburgerBtn = document.getElementById('hamburgerBtn');
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

        document.querySelectorAll('.sidebar .nav-item').forEach(item => {
            item.addEventListener('click', (e) => {
                const href = item.getAttribute('href');
                // Let real links (e.g. Logout) navigate normally
                if (href && href !== '#') return;
                e.preventDefault();
                document.querySelectorAll('.sidebar .nav-item').forEach(n => n.classList.remove('active'));
                item.classList.add('active');
                if (isMobile()) closeDrawer();
            });
        });

        /* ===== Toasts ===== */
        const toastContainer = document.getElementById('toastContainer');
        const unreadDot = document.getElementById('unreadDot');

        function showToast(title, body) {
            const toast = document.createElement('div');
            toast.className = 'toast';
            toast.innerHTML = `
      <div class="toast-icon">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8a6 6 0 10-12 0c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.7 21a2 2 0 01-3.4 0"/></svg>
      </div>
      <div class="toast-body"><b>${title}</b><p>${body}</p></div>
      <button class="toast-close" aria-label="Dismiss">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 6l12 12M18 6L6 18"/></svg>
      </button>
    `;
            toastContainer.appendChild(toast);
            const remove = () => { toast.classList.add('closing'); setTimeout(() => toast.remove(), 220); };
            toast.querySelector('.toast-close').addEventListener('click', remove);
            setTimeout(remove, 5000);
        }

        document.getElementById('notifBtn').addEventListener('click', () => {
            unreadDot.style.display = 'none';
            showToast('Membership expiring', "Vikram Nair's membership expires in 2 days.");
        });
    </script>

</body>

</html>