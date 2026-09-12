<?php
require_once __DIR__ . '/../auth/auth_check.php';
require_role(['admin', 'trainer']);
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../auth/diet_plan_schema.php';

$threads_sql = "SELECT dpm.plan_id, dpm.member_id, m.full_name AS member_name, dp.plan_name,
                        MAX(dpm.created_at) AS last_at,
                        SUM(CASE WHEN dpm.sender_role = 'user' AND dpm.is_read = 0 THEN 1 ELSE 0 END) AS unread
                 FROM diet_plan_messages dpm
                 JOIN members m ON m.id = dpm.member_id
                 JOIN diet_plans dp ON dp.id = dpm.plan_id
                 GROUP BY dpm.plan_id, dpm.member_id
                 ORDER BY last_at DESC";
$threads = $conn->query($threads_sql);
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
            grid-template-columns: 320px 1fr;
            gap: 20px;
            height: calc(100vh - 160px);
            min-height: 480px;
        }

        .dm-thread-list {
            background: #fff;
            border: 1px solid #E5E7EB;
            border-radius: 14px;
            overflow-y: auto;
        }

        .dm-thread {
            display: block;
            width: 100%;
            text-align: left;
            padding: 14px 16px;
            border: none;
            border-bottom: 1px solid #F1F5F9;
            background: #fff;
            cursor: pointer;
        }

        .dm-thread:hover,
        .dm-thread.active {
            background: #FFF3EC;
        }

        .dm-thread .name {
            font-weight: 700;
            font-size: 14px;
            color: #1E2230;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 8px;
        }

        .dm-thread .plan {
            font-size: 12px;
            color: #6B7280;
            margin-top: 2px;
        }

        .dm-unread-dot {
            background: #F25C2A;
            color: #fff;
            font-size: 11px;
            font-weight: 700;
            border-radius: 999px;
            padding: 1px 7px;
        }

        .dm-empty {
            padding: 40px 20px;
            text-align: center;
            color: #94A3B8;
        }

        .dm-chat-panel {
            background: #fff;
            border: 1px solid #E5E7EB;
            border-radius: 14px;
            display: flex;
            flex-direction: column;
        }

        .dm-chat-header {
            padding: 14px 18px;
            border-bottom: 1px solid #F1F5F9;
            font-weight: 700;
        }

        .dm-chat-header .sub {
            font-weight: 500;
            font-size: 12px;
            color: #6B7280;
        }

        .dm-chat-messages {
            flex: 1;
            overflow-y: auto;
            padding: 18px;
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .dm-msg {
            max-width: 70%;
            padding: 10px 14px;
            border-radius: 14px;
            font-size: 13.5px;
            line-height: 1.4;
        }

        .dm-msg.user {
            align-self: flex-start;
            background: #F1F5F9;
            color: #1E2230;
            border-bottom-left-radius: 4px;
        }

        .dm-msg.admin {
            align-self: flex-end;
            background: #F25C2A;
            color: #fff;
            border-bottom-right-radius: 4px;
        }

        .dm-msg .time {
            display: block;
            font-size: 10px;
            opacity: .7;
            margin-top: 4px;
        }

        .dm-chat-input {
            display: flex;
            gap: 10px;
            padding: 14px;
            border-top: 1px solid #F1F5F9;
        }

        .dm-chat-input textarea {
            flex: 1;
            resize: none;
            border: 1px solid #E5E7EB;
            border-radius: 10px;
            padding: 10px 12px;
            font-family: inherit;
            font-size: 13.5px;
            min-height: 44px;
        }

        .dm-chat-input button {
            background: #F25C2A;
            color: #fff;
            border: none;
            border-radius: 10px;
            padding: 0 20px;
            font-weight: 600;
            cursor: pointer;
        }

        .dm-chat-placeholder {
            flex: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #94A3B8;
        }

        .dm-view-plan-btn {
            background: #FFF3EC;
            color: #C2410C;
            border: 1px solid #FDBA8C;
            border-radius: 8px;
            padding: 6px 14px;
            font-size: 12px;
            font-weight: 700;
            cursor: pointer;
            margin-top: 6px;
        }

        .dm-view-plan-btn:hover {
            background: #FDE5D6;
        }

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
                    <p>Member questions about their diet plans, grouped by plan.</p>
                </div>
            </header>

            <div class="dm-layout">
                <div class="dm-thread-list" id="threadList">
                    <?php if ($threads && $threads->num_rows > 0): ?>
                        <?php while ($t = $threads->fetch_assoc()):
                            $phase = explode(' - ', $t['plan_name']);
                            $phase_label = $phase[1] ?? $t['plan_name'];
                            ?>
                            <button type="button" class="dm-thread" data-plan-id="<?= (int) $t['plan_id'] ?>"
                                data-member-id="<?= (int) $t['member_id'] ?>"
                                data-member-name="<?= htmlspecialchars($t['member_name']) ?>"
                                data-plan-name="<?= htmlspecialchars($phase_label) ?>">
                                <div class="name">
                                    <span><?= htmlspecialchars($t['member_name']) ?></span>
                                    <?php if ((int) $t['unread'] > 0): ?>
                                        <span class="dm-unread-dot"><?= (int) $t['unread'] ?></span>
                                    <?php endif; ?>
                                </div>
                                <div class="plan"><?= htmlspecialchars($phase_label) ?></div>
                            </button>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <div class="dm-empty">No diet plan questions yet.</div>
                    <?php endif; ?>
                </div>

                <div class="dm-chat-panel">
                    <div id="chatPlaceholder" class="dm-chat-placeholder">Select a conversation to view messages</div>
                    <div id="chatActive" style="display:none; flex-direction:column; height:100%;">
                        <div class="dm-chat-header">
                            <div id="chatMemberName"></div>
                            <div class="sub" id="chatPlanName"></div>
                            <button type="button" class="dm-view-plan-btn" id="viewPlanBtn">📋 View Diet Plan</button>
                        </div>
                        <div class="dm-chat-messages" id="chatMessages"></div>
                        <div class="dm-chat-input">
                            <textarea id="chatInput" placeholder="Type a reply..."></textarea>
                            <button type="button" id="chatSendBtn">Send</button>
                        </div>
                    </div>
                </div>
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

            let activePlanId = null;
            let activeMemberId = null;
            let pollTimer = null;

            const chatPlaceholder = document.getElementById('chatPlaceholder');
            const chatActive = document.getElementById('chatActive');
            const chatMessages = document.getElementById('chatMessages');
            const chatMemberName = document.getElementById('chatMemberName');
            const chatPlanName = document.getElementById('chatPlanName');
            const chatInput = document.getElementById('chatInput');
            const chatSendBtn = document.getElementById('chatSendBtn');

            function escapeHtml(str) {
                const div = document.createElement('div');
                div.textContent = str;
                return div.innerHTML;
            }

            function renderMessages(messages) {
                chatMessages.innerHTML = messages.map(m => `
                    <div class="dm-msg ${m.sender_role === 'admin' ? 'admin' : 'user'}">
                        ${escapeHtml(m.message).replace(/\n/g, '<br>')}
                        <span class="time">${m.created_at}</span>
                    </div>
                `).join('');
                chatMessages.scrollTop = chatMessages.scrollHeight;
            }

            function fetchMessages() {
                if (!activePlanId || !activeMemberId) return;
                fetch(`../handlers/diet_chat_fetch.php?plan_id=${activePlanId}&member_id=${activeMemberId}`)
                    .then(res => res.json())
                    .then(data => {
                        if (data.success) renderMessages(data.messages);
                    })
                    .catch(() => { });
            }

            function openThread(el) {
                document.querySelectorAll('.dm-thread').forEach(t => t.classList.remove('active'));
                el.classList.add('active');

                activePlanId = el.dataset.planId;
                activeMemberId = el.dataset.memberId;

                chatMemberName.textContent = el.dataset.memberName;
                chatPlanName.textContent = el.dataset.planName;

                chatPlaceholder.style.display = 'none';
                chatActive.style.display = 'flex';

                fetchMessages();
                if (pollTimer) clearInterval(pollTimer);
                pollTimer = setInterval(fetchMessages, 5000);
            }

            document.querySelectorAll('.dm-thread').forEach(el => {
                el.addEventListener('click', () => openThread(el));
            });

            function sendMessage() {
                const text = chatInput.value.trim();
                if (!text || !activePlanId || !activeMemberId) return;

                const formData = new FormData();
                formData.append('plan_id', activePlanId);
                formData.append('member_id', activeMemberId);
                formData.append('message', text);

                chatSendBtn.disabled = true;
                fetch('../handlers/diet_chat_send.php', { method: 'POST', body: formData })
                    .then(res => res.json())
                    .then(data => {
                        if (data.success) {
                            chatInput.value = '';
                            fetchMessages();
                        }
                    })
                    .finally(() => { chatSendBtn.disabled = false; });
            }

            chatSendBtn.addEventListener('click', sendMessage);
            chatInput.addEventListener('keydown', function (e) {
                if (e.key === 'Enter' && !e.shiftKey) {
                    e.preventDefault();
                    sendMessage();
                }
            });

            // ── View Diet Plan modal ──
            const viewPlanBtn = document.getElementById('viewPlanBtn');
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
                        if (!data.status || data.status !== 'success') return;
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

            if (viewPlanBtn) {
                viewPlanBtn.addEventListener('click', function () {
                    if (activePlanId) openPlanModal(activePlanId);
                });
            }
            if (planModalClose) {
                planModalClose.addEventListener('click', () => planModalOverlay.classList.remove('active'));
            }
            planModalOverlay.addEventListener('click', function (e) {
                if (e.target === planModalOverlay) planModalOverlay.classList.remove('active');
            });

            // ── Deep-link auto-open from the dashboard bell ──
            const urlParams = new URLSearchParams(window.location.search);
            const deepPlanId = urlParams.get('plan_id');
            const deepMemberId = urlParams.get('member_id');
            if (deepPlanId && deepMemberId) {
                const target = document.querySelector(`.dm-thread[data-plan-id="${deepPlanId}"][data-member-id="${deepMemberId}"]`);
                if (target) openThread(target);
            }
        });
    </script>
</body>

</html>
