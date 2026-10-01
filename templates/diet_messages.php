<?php
require_once __DIR__ . '/../auth/auth_check.php';
require_role(['admin', 'trainer']);
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../auth/diet_plan_schema.php';

// One conversation per member, whichever plan a message was about. The phase each
// message belongs to is shown inside the chat (see handlers/diet_chat_member_fetch.php).
$threads_sql = "SELECT m.id AS member_id, m.full_name AS member_name,
                       x.last_id, x.last_at, x.unread, lm.message AS last_message, lm.sender_role AS last_sender
                FROM (SELECT member_id, MAX(id) AS last_id, MAX(created_at) AS last_at,
                             SUM(CASE WHEN sender_role = 'user' AND is_read = 0 THEN 1 ELSE 0 END) AS unread
                      FROM diet_plan_messages
                      GROUP BY member_id) x
                JOIN members m ON m.id = x.member_id
                JOIN diet_plan_messages lm ON lm.id = x.last_id
                ORDER BY x.last_id DESC";
$threads = [];
$res = $conn->query($threads_sql);
while ($res && ($row = $res->fetch_assoc())) {
    $threads[] = $row;
}
$total_unread = array_sum(array_map(static fn(array $t): int => (int) $t['unread'], $threads));

// Avatar colour is derived from the name so a member keeps the same colour everywhere on the page
function dm_avatar_color(string $name): string
{
    $palette = ['#F25C2A', '#0EA5E9', '#8B5CF6', '#10B981', '#EC4899', '#F59E0B', '#6366F1', '#14B8A6'];
    return $palette[crc32(mb_strtolower($name)) % count($palette)];
}

function dm_initials(string $name): string
{
    $words = preg_split('/\s+/', trim($name), -1, PREG_SPLIT_NO_EMPTY) ?: ['?'];
    $out = '';
    foreach (array_slice($words, 0, 2) as $w) {
        $out .= mb_strtoupper(mb_substr($w, 0, 1));
    }
    return $out;
}

function dm_list_time(string $ts): string
{
    $t = strtotime($ts);
    if (!$t) {
        return '';
    }
    if (date('Y-m-d', $t) === date('Y-m-d')) {
        return date('g:i A', $t);
    }
    if (date('Y-m-d', $t) === date('Y-m-d', strtotime('-1 day'))) {
        return 'Yesterday';
    }
    return date('d M', $t);
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" size="16x16" href="../icons/favicon-dark-logo.png" type="image/png">
    <title>Diet Messages | JOF INDIA</title>
    <link rel="stylesheet" href="../static/root.css">
    <style>
        .dm-layout {
            display: grid;
            grid-template-columns: 350px 1fr;
            gap: 20px;
            height: calc(100vh - 182px);
            min-height: 500px;
            margin-top: 10px;
        }

        /* ── Conversation list ── */
        .dm-side {
            background: #fff;
            border: 1px solid #E5E7EB;
            border-radius: 18px;
            display: flex;
            flex-direction: column;
            min-height: 0;
            box-shadow: 0 2px 12px rgba(15, 23, 42, .04);
            overflow: hidden;
        }

        .dm-side-head {
            padding: 16px 16px 12px;
            border-bottom: 1px solid #F1F5F9;
        }

        .dm-side-title {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 12px;
        }

        .dm-side-title h2 {
            font-size: 15px;
            font-weight: 700;
            color: #1E2230;
        }

        .dm-side-title .count {
            font-size: 12px;
            color: #94A3B8;
            font-weight: 600;
        }

        .dm-search {
            position: relative;
        }

        .dm-search svg {
            position: absolute;
            left: 12px;
            top: 50%;
            transform: translateY(-50%);
            width: 16px;
            height: 16px;
            color: #94A3B8;
            pointer-events: none;
        }

        .dm-search input {
            width: 100%;
            height: 40px;
            border: 1.5px solid #E5E7EB;
            border-radius: 11px;
            background: #F8FAFC;
            padding: 0 34px 0 36px;
            font-family: inherit;
            font-size: 13.5px;
            color: #1E2230;
            transition: border-color .15s, background .15s, box-shadow .15s;
        }

        .dm-search input:focus {
            outline: none;
            border-color: #F25C2A;
            background: #fff;
            box-shadow: 0 0 0 3px rgba(242, 92, 42, .12);
        }

        .dm-search-clear {
            position: absolute;
            right: 8px;
            top: 50%;
            transform: translateY(-50%);
            width: 22px;
            height: 22px;
            border: none;
            border-radius: 50%;
            background: #E2E8F0;
            color: #475569;
            font-size: 12px;
            line-height: 1;
            cursor: pointer;
            display: none;
        }

        .dm-search-clear.show {
            display: block;
        }

        .dm-tabs {
            display: flex;
            gap: 6px;
            margin-top: 12px;
        }

        .dm-tab {
            border: 1.5px solid #E5E7EB;
            background: #fff;
            color: #64748B;
            border-radius: 999px;
            padding: 5px 13px;
            font-family: inherit;
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
            transition: .15s;
        }

        .dm-tab:hover {
            border-color: #F25C2A;
            color: #F25C2A;
        }

        .dm-tab.active {
            background: #F25C2A;
            border-color: #F25C2A;
            color: #fff;
        }

        .dm-thread-list {
            flex: 1;
            overflow-y: auto;
            min-height: 0;
        }

        .dm-thread {
            display: flex;
            align-items: center;
            gap: 12px;
            width: 100%;
            text-align: left;
            padding: 13px 16px;
            border: none;
            border-bottom: 1px solid #F8FAFC;
            border-left: 3px solid transparent;
            background: #fff;
            cursor: pointer;
            font-family: inherit;
            transition: background .12s;
        }

        .dm-thread:hover {
            background: #FAFBFC;
        }

        .dm-thread.active {
            background: #FFF3EC;
            border-left-color: #F25C2A;
        }

        .dm-avatar {
            width: 42px;
            height: 42px;
            border-radius: 50%;
            background: var(--av, #F25C2A);
            color: #fff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 14px;
            font-weight: 700;
            flex-shrink: 0;
            letter-spacing: .02em;
        }

        .dm-thread-main {
            flex: 1;
            min-width: 0;
            display: flex;
            flex-direction: column;
            gap: 3px;
        }

        .dm-thread-top,
        .dm-thread-bottom {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 8px;
        }

        .dm-thread-name {
            font-weight: 700;
            font-size: 14px;
            color: #1E2230;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .dm-thread-time {
            font-size: 11px;
            color: #94A3B8;
            flex-shrink: 0;
        }

        .dm-thread-preview {
            font-size: 12.5px;
            color: #64748B;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .dm-thread.has-unread .dm-thread-name,
        .dm-thread.has-unread .dm-thread-preview {
            color: #0F172A;
            font-weight: 700;
        }

        .dm-thread.has-unread .dm-thread-time {
            color: #F25C2A;
            font-weight: 700;
        }

        .dm-unread-dot {
            background: #F25C2A;
            color: #fff;
            font-size: 11px;
            font-weight: 700;
            border-radius: 999px;
            min-width: 20px;
            height: 20px;
            padding: 0 6px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        .dm-empty {
            padding: 46px 24px;
            text-align: center;
            color: #94A3B8;
            font-size: 13.5px;
            line-height: 1.5;
        }

        .dm-empty .big {
            font-size: 30px;
            display: block;
            margin-bottom: 8px;
        }

        /* ── Chat panel ── */
        .dm-chat-panel {
            background: #fff;
            border: 1px solid #E5E7EB;
            border-radius: 18px;
            display: flex;
            flex-direction: column;
            min-height: 0;
            min-width: 0;
            box-shadow: 0 2px 12px rgba(15, 23, 42, .04);
            overflow: hidden;
        }

        .dm-chat-placeholder {
            flex: 1;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 10px;
            color: #94A3B8;
            font-size: 14px;
            padding: 20px;
            text-align: center;
        }

        .dm-chat-placeholder .icon {
            width: 68px;
            height: 68px;
            border-radius: 50%;
            background: #FFF3EC;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 30px;
        }

        .dm-chat-placeholder b {
            color: #475569;
            font-size: 15px;
        }

        .dm-chat {
            flex: 1;
            display: none;
            flex-direction: column;
            min-height: 0;
        }

        .dm-chat.show {
            display: flex;
        }

        .dm-chat-header {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 13px 18px;
            border-bottom: 1px solid #F1F5F9;
            background: #fff;
        }

        .dm-back {
            display: none;
            width: 34px;
            height: 34px;
            border: none;
            border-radius: 10px;
            background: #F1F5F9;
            color: #334155;
            font-size: 18px;
            cursor: pointer;
            flex-shrink: 0;
        }

        .dm-chat-title {
            flex: 1;
            min-width: 0;
        }

        .dm-chat-title .name {
            font-weight: 700;
            font-size: 15px;
            color: #1E2230;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .dm-chat-title .sub {
            font-size: 12px;
            color: #64748B;
            margin-top: 1px;
        }

        .dm-view-plan-btn {
            background: #FFF3EC;
            color: #C2410C;
            border: 1px solid #FDBA8C;
            border-radius: 10px;
            padding: 8px 14px;
            font-size: 12.5px;
            font-weight: 700;
            cursor: pointer;
            font-family: inherit;
            white-space: nowrap;
            transition: background .15s;
        }

        .dm-view-plan-btn:hover {
            background: #FDE5D6;
        }

        .dm-chat-messages {
            flex: 1;
            overflow-y: auto;
            padding: 18px 22px 8px;
            display: flex;
            flex-direction: column;
            gap: 6px;
            background: #F8FAFC;
            min-height: 0;
        }

        /* Marks where each plan's conversation starts */
        .dm-week {
            display: flex;
            align-items: center;
            gap: 12px;
            margin: 16px 0 8px;
        }

        .dm-week:first-child {
            margin-top: 2px;
        }

        .dm-week::before,
        .dm-week::after {
            content: '';
            flex: 1;
            height: 1px;
            background: linear-gradient(90deg, transparent, #D5DCE6);
        }

        .dm-week::after {
            background: linear-gradient(90deg, #D5DCE6, transparent);
        }

        .dm-week-pill {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: #fff;
            border: 1px solid #FDD9C6;
            color: #9A3412;
            font-size: 12px;
            font-weight: 700;
            padding: 5px 6px 5px 13px;
            border-radius: 999px;
            box-shadow: 0 1px 4px rgba(242, 92, 42, .08);
            white-space: nowrap;
        }

        .dm-week-pill button {
            border: none;
            background: #FFF3EC;
            color: #C2410C;
            font-family: inherit;
            font-size: 11px;
            font-weight: 700;
            padding: 3px 10px;
            border-radius: 999px;
            cursor: pointer;
        }

        .dm-week-pill button:hover {
            background: #FDE5D6;
        }

        .dm-msg {
            max-width: 68%;
            padding: 9px 14px 7px;
            border-radius: 16px;
            font-size: 13.5px;
            line-height: 1.5;
            word-wrap: break-word;
            overflow-wrap: anywhere;
        }

        .dm-msg.user {
            align-self: flex-start;
            background: #fff;
            border: 1px solid #E5E7EB;
            color: #1E2230;
            border-bottom-left-radius: 4px;
        }

        .dm-msg.admin {
            align-self: flex-end;
            background: linear-gradient(135deg, #F25C2A 0%, #ff7a4d 100%);
            color: #fff;
            border-bottom-right-radius: 4px;
            box-shadow: 0 2px 8px rgba(242, 92, 42, .22);
        }

        .dm-msg .time {
            display: block;
            font-size: 10.5px;
            opacity: .65;
            margin-top: 3px;
            text-align: right;
        }

        .dm-chat-input {
            padding: 12px 16px 14px;
            border-top: 1px solid #F1F5F9;
            background: #fff;
        }

        .dm-reply-hint {
            font-size: 11.5px;
            color: #94A3B8;
            margin: 0 4px 7px;
        }

        .dm-reply-hint b {
            color: #C2410C;
        }

        .dm-input-row {
            display: flex;
            gap: 10px;
            align-items: flex-end;
        }

        .dm-input-row textarea {
            flex: 1;
            resize: none;
            border: 1.5px solid #E5E7EB;
            border-radius: 13px;
            padding: 11px 14px;
            font-family: inherit;
            font-size: 13.5px;
            min-height: 44px;
            max-height: 110px;
            background: #F8FAFC;
            color: #1E2230;
            transition: border-color .15s, background .15s, box-shadow .15s;
        }

        .dm-input-row textarea:focus {
            outline: none;
            border-color: #F25C2A;
            background: #fff;
            box-shadow: 0 0 0 3px rgba(242, 92, 42, .12);
        }

        .dm-input-row textarea:disabled {
            opacity: .6;
        }

        .dm-send {
            width: 44px;
            height: 44px;
            flex-shrink: 0;
            border: none;
            border-radius: 13px;
            background: #F25C2A;
            color: #fff;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: background .15s, transform .1s;
        }

        .dm-send:hover {
            background: #E5502B;
        }

        .dm-send:active {
            transform: scale(.95);
        }

        .dm-send:disabled {
            opacity: .45;
            cursor: not-allowed;
        }

        .dm-send svg {
            width: 18px;
            height: 18px;
        }

        @media (max-width: 900px) {
            .dm-layout {
                grid-template-columns: 1fr;
                height: calc(100vh - 150px);
            }

            .dm-chat-panel {
                display: none;
            }

            .dm-layout.chat-open .dm-side {
                display: none;
            }

            .dm-layout.chat-open .dm-chat-panel {
                display: flex;
            }

            .dm-back {
                display: block;
            }

            .dm-msg {
                max-width: 85%;
            }

            .dm-view-plan-btn {
                padding: 7px 10px;
            }
        }

        /* ── Plan modal (unchanged) ── */
        .plan-modal-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(15, 20, 32, 0.55);
            backdrop-filter: blur(3px);
            z-index: 2000;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .plan-modal-overlay.active {
            display: flex;
        }

        .plan-modal {
            background: #fff;
            border-radius: 18px;
            width: 100%;
            max-width: 640px;
            max-height: 85vh;
            overflow-y: auto;
            padding: 26px;
        }

        .plan-modal-head {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 16px;
        }

        .plan-modal-head h2 {
            font-size: 18px;
        }

        .plan-modal-close {
            border: none;
            background: #F1F5F9;
            border-radius: 8px;
            width: 30px;
            height: 30px;
            cursor: pointer;
        }

        .plan-modal-chips {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-bottom: 18px;
        }

        .plan-modal-chips span {
            font-size: 12px;
            font-weight: 700;
            padding: 5px 12px;
            border-radius: 999px;
            background: #F1F5F9;
            color: #334155;
        }

        .plan-modal-meal-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
            margin-bottom: 16px;
        }

        @media (max-width: 520px) {
            .plan-modal-meal-grid {
                grid-template-columns: 1fr;
            }
        }

        .plan-modal-meal-card {
            background: #F8FAFC;
            border-radius: 12px;
            padding: 13px;
        }

        .plan-modal-meal-card .t {
            font-weight: 700;
            font-size: 12.5px;
            margin-bottom: 6px;
        }

        .plan-modal-meal-card .b {
            font-size: 12.5px;
            line-height: 1.6;
            color: #475569;
        }

        .plan-modal-resources {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
        }

        .plan-modal-resources a {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 8px 14px;
            border-radius: 9px;
            background: #FFF3EC;
            color: #C2410C;
            font-weight: 600;
            font-size: 12.5px;
            border: 1px solid #FDBA8C;
            text-decoration: none;
        }
    </style>
</head>

<body class="page-diet_messages">

    <button class="mobile-toggle" id="mobileToggle"><img src="../icons/bars-solid-full.svg"
            style="width: 22px; height: 22px; filter: brightness(0) invert(1);"></button>
    <button class="toggle-sidebar-btn" id="toggleBtn"><img src="../icons/chevron-left-solid-full.svg"
            style="width: 14px; height: 14px; filter: brightness(0) invert(1);"></button>

    <div class="dashboard-container">

        <?php include 'sidebar.php'; ?>

        <main class="main-content">

            <header class="page-header">
                <div class="header-text">
                    <h1>Diet Messages</h1>
                    <p>Questions from members about their diet plans. Each member has one conversation, with their messages grouped by phase.</p>
                </div>
            </header>

            <div class="dm-layout" id="dmLayout">

                <aside class="dm-side">
                    <div class="dm-side-head">
                        <div class="dm-side-title">
                            <h2>Conversations</h2>
                            <span class="count" id="dmCount"><?= count($threads) ?> member<?= count($threads) === 1 ? '' : 's' ?></span>
                        </div>
                        <div class="dm-search">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"
                                stroke-linecap="round" stroke-linejoin="round">
                                <circle cx="11" cy="11" r="7" />
                                <path d="M21 21l-4.3-4.3" />
                            </svg>
                            <input type="text" id="dmSearch" placeholder="Search members by name" autocomplete="off"
                                aria-label="Search members by name">
                            <button type="button" class="dm-search-clear" id="dmSearchClear" aria-label="Clear search">✕</button>
                        </div>
                        <div class="dm-tabs">
                            <button type="button" class="dm-tab active" data-filter="all">All</button>
                            <button type="button" class="dm-tab" data-filter="unread">Unread<?= $total_unread > 0 ? ' (' . $total_unread . ')' : '' ?></button>
                        </div>
                    </div>

                    <div class="dm-thread-list" id="threadList">
                        <?php foreach ($threads as $t):
                            $unread = (int) $t['unread'];
                            $preview = preg_replace('/\s+/', ' ', (string) $t['last_message']);
                            $preview = mb_strimwidth($preview, 0, 60, '…');
                            if ($t['last_sender'] === 'admin') {
                                $preview = 'You: ' . $preview;
                            }
                            ?>
                            <button type="button" class="dm-thread<?= $unread > 0 ? ' has-unread' : '' ?>"
                                data-member-id="<?= (int) $t['member_id'] ?>"
                                data-member-name="<?= htmlspecialchars($t['member_name']) ?>"
                                data-search="<?= htmlspecialchars(mb_strtolower($t['member_name'])) ?>"
                                data-unread="<?= $unread ?>" data-last-id="<?= (int) $t['last_id'] ?>">
                                <span class="dm-avatar" style="--av:<?= dm_avatar_color($t['member_name']) ?>"><?= htmlspecialchars(dm_initials($t['member_name'])) ?></span>
                                <span class="dm-thread-main">
                                    <span class="dm-thread-top">
                                        <span class="dm-thread-name"><?= htmlspecialchars($t['member_name']) ?></span>
                                        <span class="dm-thread-time"><?= htmlspecialchars(dm_list_time($t['last_at'])) ?></span>
                                    </span>
                                    <span class="dm-thread-bottom">
                                        <span class="dm-thread-preview"><?= htmlspecialchars($preview) ?></span>
                                        <?php if ($unread > 0): ?>
                                            <span class="dm-unread-dot"><?= $unread ?></span>
                                        <?php endif; ?>
                                    </span>
                                </span>
                            </button>
                        <?php endforeach; ?>

                        <div class="dm-empty" id="dmNoMatch" style="<?= $threads ? 'display:none;' : '' ?>">
                            <?php if (!$threads): ?>
                                <span class="big">💬</span>No diet plan questions yet.<br>Member messages will show up here.
                            <?php endif; ?>
                        </div>
                    </div>
                </aside>

                <section class="dm-chat-panel">
                    <div id="chatPlaceholder" class="dm-chat-placeholder">
                        <div class="icon">💬</div>
                        <b>Select a conversation</b>
                        <span>Pick a member on the left to read and reply to their messages.</span>
                    </div>

                    <div class="dm-chat" id="chatActive">
                        <div class="dm-chat-header">
                            <button type="button" class="dm-back" id="dmBack" aria-label="Back to conversations">‹</button>
                            <span class="dm-avatar" id="chatAvatar"></span>
                            <div class="dm-chat-title">
                                <div class="name" id="chatMemberName"></div>
                                <div class="sub" id="chatSub"></div>
                            </div>
                            <button type="button" class="dm-view-plan-btn" id="viewPlanBtn">📋 View Diet Plan</button>
                        </div>
                        <div class="dm-chat-messages" id="chatMessages"></div>
                        <div class="dm-chat-input">
                            <div class="dm-reply-hint" id="replyHint"></div>
                            <div class="dm-input-row">
                                <textarea id="chatInput" rows="1" placeholder="Type a reply…"></textarea>
                                <button type="button" class="dm-send" id="chatSendBtn" aria-label="Send reply">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"
                                        stroke-linecap="round" stroke-linejoin="round">
                                        <path d="M22 2L11 13M22 2l-7 20-4-9-9-4 20-7z" />
                                    </svg>
                                </button>
                            </div>
                        </div>
                    </div>
                </section>
            </div>

        </main>
    </div>

    <div class="plan-modal-overlay" id="planModalOverlay">
        <div class="plan-modal">
            <div class="plan-modal-head">
                <div>
                    <h2 id="planModalTitle">Diet Plan</h2>
                </div>
                <button type="button" class="plan-modal-close" id="planModalClose">✕</button>
            </div>
            <div class="plan-modal-chips" id="planModalChips"></div>
            <a class="dm-view-plan-btn" id="planModalDownload" href="#" style="display:inline-block; text-decoration:none; margin-top:10px;">⬇ Download Diet Plan (PDF)</a>
            <div class="plan-modal-meal-grid" id="planModalMeals"></div>
            <div id="planModalResourcesWrap" style="display:none;">
                <h3 style="font-size:13px;margin-bottom:10px;">Resources &amp; Recommended Products</h3>
                <div class="plan-modal-resources" id="planModalResources"></div>
            </div>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function () {
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
                        dropdownItems.forEach(other => { if (other !== item) other.classList.remove('active'); });
                        item.classList.toggle('active');
                    });
                }
            });

            const layout = document.getElementById('dmLayout');
            const threadList = document.getElementById('threadList');
            const chatPlaceholder = document.getElementById('chatPlaceholder');
            const chatActive = document.getElementById('chatActive');
            const chatMessages = document.getElementById('chatMessages');
            const chatAvatar = document.getElementById('chatAvatar');
            const chatMemberName = document.getElementById('chatMemberName');
            const chatSub = document.getElementById('chatSub');
            const replyHint = document.getElementById('replyHint');
            const chatInput = document.getElementById('chatInput');
            const chatSendBtn = document.getElementById('chatSendBtn');
            const viewPlanBtn = document.getElementById('viewPlanBtn');

            let activeMemberId = null;
            let replyPlanId = 0;
            let lastSig = '';
            let pollTimer = null;

            function escapeHtml(str) {
                const div = document.createElement('div');
                div.textContent = str;
                return div.innerHTML;
            }

            function fmtTime(s) {
                const d = new Date(String(s).replace(' ', 'T'));
                if (isNaN(d.getTime())) return s;
                const time = d.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' });
                if (d.toDateString() === new Date().toDateString()) return time;
                return d.toLocaleDateString([], { day: 'numeric', month: 'short' }) + ', ' + time;
            }

            // ── Search + Unread filter ──
            const searchInput = document.getElementById('dmSearch');
            const searchClear = document.getElementById('dmSearchClear');
            const noMatch = document.getElementById('dmNoMatch');
            const tabs = document.querySelectorAll('.dm-tab');
            let filter = 'all';

            function applyFilters() {
                const q = searchInput.value.trim().toLowerCase();
                searchClear.classList.toggle('show', q !== '');
                let shown = 0;
                threadList.querySelectorAll('.dm-thread').forEach(t => {
                    const okName = q === '' || t.dataset.search.includes(q);
                    const okTab = filter === 'all' || parseInt(t.dataset.unread, 10) > 0;
                    const show = okName && okTab;
                    t.style.display = show ? '' : 'none';
                    if (show) shown++;
                });
                const total = threadList.querySelectorAll('.dm-thread').length;
                if (total > 0 && shown === 0) {
                    noMatch.style.display = 'block';
                    noMatch.innerHTML = '<span class="big">🔍</span>' + (q !== ''
                        ? 'No members match “' + escapeHtml(searchInput.value.trim()) + '”.'
                        : 'No unread conversations.');
                } else if (total > 0) {
                    noMatch.style.display = 'none';
                }
            }

            searchInput.addEventListener('input', applyFilters);
            searchClear.addEventListener('click', () => { searchInput.value = ''; applyFilters(); searchInput.focus(); });
            tabs.forEach(tab => tab.addEventListener('click', () => {
                tabs.forEach(t => t.classList.remove('active'));
                tab.classList.add('active');
                filter = tab.dataset.filter;
                applyFilters();
            }));

            // ── Conversation ──
            function renderMessages(messages, stickToBottom) {
                const prevTop = chatMessages.scrollTop;
                let html = '';
                let lastPlan = null;
                messages.forEach(m => {
                    // A new phase starts whenever the plan a message belongs to changes
                    if (m.plan_id !== lastPlan) {
                        lastPlan = m.plan_id;
                        html += `<div class="dm-week"><div class="dm-week-pill">🗓 ${escapeHtml(m.phase)}<button type="button" data-plan-id="${m.plan_id}">View plan</button></div></div>`;
                    }
                    html += `<div class="dm-msg ${m.sender_role === 'admin' ? 'admin' : 'user'}">
                        ${escapeHtml(m.message).replace(/\n/g, '<br>')}
                        <span class="time">${escapeHtml(fmtTime(m.created_at))}</span>
                    </div>`;
                });
                chatMessages.innerHTML = html;
                // Don't yank the view to the bottom while the admin is reading older messages
                chatMessages.scrollTop = stickToBottom ? chatMessages.scrollHeight : prevTop;
            }

            function refreshUnreadTab() {
                let n = 0;
                threadList.querySelectorAll('.dm-thread').forEach(t => { n += parseInt(t.dataset.unread, 10) || 0; });
                document.querySelector('.dm-tab[data-filter="unread"]').textContent = 'Unread' + (n > 0 ? ' (' + n + ')' : '');
            }

            // Keeps the list row in step with the open conversation: opening it clears the
            // unread badge, and a new message updates the preview and moves the row to the top.
            function updateThreadRow(memberId, last) {
                const row = threadList.querySelector(`.dm-thread[data-member-id="${memberId}"]`);
                if (!row || !last) return;
                row.dataset.unread = '0';
                row.classList.remove('has-unread');
                const dot = row.querySelector('.dm-unread-dot');
                if (dot) dot.remove();
                if (String(last.id) !== row.dataset.lastId) {
                    row.dataset.lastId = String(last.id);
                    row.querySelector('.dm-thread-preview').textContent =
                        (last.sender_role === 'admin' ? 'You: ' : '') + last.message.replace(/\s+/g, ' ').slice(0, 60);
                    row.querySelector('.dm-thread-time').textContent = fmtTime(last.created_at).replace(/,.*/, '');
                    threadList.prepend(row);
                }
                refreshUnreadTab();
            }

            function fetchMessages(forceScroll) {
                if (!activeMemberId) return;
                const requested = activeMemberId;
                fetch(`../handlers/diet_chat_member_fetch.php?member_id=${requested}`, { cache: 'no-store' })
                    .then(res => res.json())
                    .then(data => {
                        // The admin may have opened someone else while this was in flight
                        if (!data.success || requested !== activeMemberId) return;

                        replyPlanId = data.reply_plan_id || 0;
                        const canReply = replyPlanId > 0;
                        chatInput.disabled = !canReply;
                        chatSendBtn.disabled = !canReply;
                        replyHint.innerHTML = canReply
                            ? 'Your reply is sent under <b>' + escapeHtml(data.reply_phase) + '</b>'
                            : 'This member has no diet plan assigned, so a reply can’t be sent.';
                        chatSub.textContent = data.messages.length + ' message' + (data.messages.length === 1 ? '' : 's')
                            + (canReply ? ' · Current phase: ' + data.reply_phase : '');
                        viewPlanBtn.style.display = canReply ? '' : 'none';

                        const last = data.messages[data.messages.length - 1];
                        const sig = data.messages.length + ':' + (last ? last.id : 0);
                        updateThreadRow(requested, last);
                        if (sig === lastSig && !forceScroll) return;

                        const nearBottom = chatMessages.scrollHeight - chatMessages.scrollTop - chatMessages.clientHeight < 80;
                        renderMessages(data.messages, forceScroll || lastSig === '' || nearBottom);
                        lastSig = sig;
                    })
                    .catch(() => { });
            }

            function openThread(el) {
                threadList.querySelectorAll('.dm-thread').forEach(t => t.classList.remove('active'));
                el.classList.add('active');

                activeMemberId = el.dataset.memberId;
                lastSig = '';
                replyPlanId = 0;

                const name = el.dataset.memberName;
                chatMemberName.textContent = name;
                chatAvatar.textContent = el.querySelector('.dm-avatar').textContent;
                chatAvatar.style.setProperty('--av', el.querySelector('.dm-avatar').style.getPropertyValue('--av'));
                chatSub.textContent = '';
                chatMessages.innerHTML = '';
                replyHint.textContent = '';

                chatPlaceholder.style.display = 'none';
                chatActive.classList.add('show');
                layout.classList.add('chat-open');

                fetchMessages(true);
                if (pollTimer) clearInterval(pollTimer);
                pollTimer = setInterval(() => fetchMessages(false), 5000);
            }

            threadList.querySelectorAll('.dm-thread').forEach(el => {
                el.addEventListener('click', () => openThread(el));
            });

            document.getElementById('dmBack').addEventListener('click', () => layout.classList.remove('chat-open'));

            function sendMessage() {
                const text = chatInput.value.trim();
                if (!text || !activeMemberId || !replyPlanId) return;

                const formData = new FormData();
                formData.append('plan_id', replyPlanId);
                formData.append('member_id', activeMemberId);
                formData.append('message', text);

                chatSendBtn.disabled = true;
                fetch('../handlers/diet_chat_send.php', { method: 'POST', body: formData })
                    .then(res => res.json())
                    .then(data => {
                        if (data.success) {
                            chatInput.value = '';
                            chatInput.style.height = 'auto';
                            fetchMessages(true);
                        }
                    })
                    .finally(() => { chatSendBtn.disabled = !replyPlanId; });
            }

            chatSendBtn.addEventListener('click', sendMessage);
            chatInput.addEventListener('keydown', function (e) {
                if (e.key === 'Enter' && !e.shiftKey) {
                    e.preventDefault();
                    sendMessage();
                }
            });
            chatInput.addEventListener('input', () => {
                chatInput.style.height = 'auto';
                chatInput.style.height = Math.min(chatInput.scrollHeight, 110) + 'px';
            });

            // ── View Diet Plan modal ──
            const planModalOverlay = document.getElementById('planModalOverlay');
            const planModalClose = document.getElementById('planModalClose');
            const planModalTitle = document.getElementById('planModalTitle');
            const planModalChips = document.getElementById('planModalChips');
            const planModalMeals = document.getElementById('planModalMeals');
            const planModalResourcesWrap = document.getElementById('planModalResourcesWrap');
            const planModalResources = document.getElementById('planModalResources');

            function formatMealText(text) {
                if (!text) return '<span style="color:#94A3B8;">Not specified</span>';
                // Strip the leading emoji glyph before each header — redundant next to the section icon.
                const stripped = text.replace(/[\u{1F300}-\u{1FAFF}\u{2600}-\u{27BF}\u{FE0F}]\s*/gu, '');
                let html = escapeHtml(stripped).replace(/\n/g, '<br>');
                html = html.replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>');
                return html;
            }

            function openPlanModal(planId) {
                planModalTitle.textContent = 'Loading…';
                planModalChips.innerHTML = '';
                planModalMeals.innerHTML = '';
                planModalResourcesWrap.style.display = 'none';
                planModalOverlay.classList.add('active');

                fetch(`../handlers/get_diet_plan_detail.php?id=${planId}`)
                    .then(res => res.json())
                    .then(data => {
                        if (!data.status || data.status !== 'success') {
                            planModalTitle.textContent = 'This plan is no longer available';
                            return;
                        }
                        const p = data.plan;
                        planModalTitle.textContent = p.phase;
                        document.getElementById('planModalDownload').href = `../handlers/download_diet_plan_pdf.php?id=${p.id}`;
                        planModalChips.innerHTML = `
                            <span>🎯 ${escapeHtml(p.goal)}</span>
                            <span>🥗 ${escapeHtml(p.diet_type)}</span>
                            <span>🔥 ${escapeHtml(String(p.calories))} kcal</span>
                            <span>⏱ ${escapeHtml(String(p.duration))} weeks</span>
                        `;
                        planModalMeals.innerHTML = `
                            <div class="plan-modal-meal-card"><div class="t">🌅 Morning</div><div class="b">${formatMealText(p.breakfast)}</div></div>
                            <div class="plan-modal-meal-card"><div class="t">🍛 Lunch</div><div class="b">${formatMealText(p.lunch)}</div></div>
                            <div class="plan-modal-meal-card"><div class="t">🍎 Mid Meal</div><div class="b">${formatMealText(p.snack)}</div></div>
                            <div class="plan-modal-meal-card"><div class="t">🌙 Night</div><div class="b">${formatMealText(p.dinner)}</div></div>
                        `;
                        if (p.resources && p.resources.length) {
                            planModalResources.innerHTML = p.resources.map(r =>
                                `<a href="${escapeHtml(r.link || '#')}" target="_blank" rel="noopener">🛒 ${escapeHtml(r.name || 'Resource')}</a>`
                            ).join('');
                            planModalResourcesWrap.style.display = 'block';
                        }
                    })
                    .catch(() => { planModalTitle.textContent = 'Could not load plan'; });
            }

            viewPlanBtn.addEventListener('click', () => { if (replyPlanId) openPlanModal(replyPlanId); });
            // "View plan" on a phase divider opens that phase's plan
            chatMessages.addEventListener('click', e => {
                const b = e.target.closest('.dm-week-pill button');
                if (b) openPlanModal(b.dataset.planId);
            });
            planModalClose.addEventListener('click', () => planModalOverlay.classList.remove('active'));
            planModalOverlay.addEventListener('click', function (e) {
                if (e.target === planModalOverlay) planModalOverlay.classList.remove('active');
            });
            document.addEventListener('keydown', e => {
                if (e.key === 'Escape') planModalOverlay.classList.remove('active');
            });

            // ── Deep-link from the dashboard card: ?member_id=N ──
            const deepMemberId = new URLSearchParams(window.location.search).get('member_id');
            if (deepMemberId) {
                const target = threadList.querySelector(`.dm-thread[data-member-id="${CSS.escape(deepMemberId)}"]`);
                if (target) {
                    openThread(target);
                    target.scrollIntoView({ block: 'nearest' });
                }
            }
        });
    </script>
</body>

</html>
