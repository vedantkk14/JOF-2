<?php
require_once __DIR__ . '/../../auth/auth_check.php';
require_role(['user']);
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../auth/diet_plan_schema.php';

$user = get_session_user();
$uid  = (int) $user['id'];
$open_plan = (int) ($_GET['open_plan'] ?? 0);

$mstmt = $conn->prepare("SELECT id, full_name FROM members WHERE user_id = ? ORDER BY id DESC LIMIT 1");
$mstmt->bind_param('i', $uid);
$mstmt->execute();
$member = $mstmt->get_result()->fetch_assoc();
$member_id = $member ? (int) $member['id'] : 0;

$plans = [];
if ($member_id) {
    $pstmt = $conn->prepare("SELECT dp.* FROM diet_plans dp
                              JOIN diet_plan_assignments dpa ON dpa.plan_id = dp.id
                              WHERE dpa.member_id = ?
                              ORDER BY dp.created_at DESC");
    $pstmt->bind_param('i', $member_id);
    $pstmt->execute();
    $res = $pstmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $plans[] = $row;
    }
}

$current = $plans[0] ?? null;

function e($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }

function formatDietBlock($text) {
    if (empty($text)) return '<span class="muted">Not specified</span>';
    // Strip the leading emoji glyph the admin form prepends to each header (e.g. "🌅 **WAKE UP:**") —
    // the meal card already shows its own icon, so keeping this too just repeats it.
    $text = preg_replace('/[\x{1F300}-\x{1FAFF}\x{2600}-\x{27BF}\x{FE0F}]\s*/u', '', $text);
    $text = e($text);
    $text = preg_replace('/\*\*(.*?)\*\*/', '<span class="meal-subhead">$1</span>', $text);
    return nl2br($text);
}

function planPhaseLabel($plan) {
    $parts = explode(' - ', $plan['plan_name']);
    return trim($parts[1] ?? $plan['plan_name']);
}

function planTitleLabel($plan) {
    $parts = explode(' - ', $plan['plan_name']);
    return trim($parts[0] ?? '');
}

function planResources($plan) {
    if (empty($plan['resources'])) return [];
    $decoded = json_decode($plan['resources'], true);
    return is_array($decoded) ? $decoded : [];
}

$member_full_name = trim($member['full_name'] ?? '');

// Build a JS-ready payload for every assigned plan so the modal can render instantly.
$plans_js = [];
foreach ($plans as $i => $p) {
    $title = planTitleLabel($p);
    // Only surface the "title" segment when it isn't just the member's own name
    // (admin usually types the client's name there, which would be redundant to show back to them) —
    // e.g. "Summer Shred" should show, but "Vedant Kolhapure" shouldn't repeat.
    $custom_title = ($title !== '' && strcasecmp($title, $member_full_name) !== 0) ? $title : null;

    $plans_js[] = [
        'id'            => (int) $p['id'],
        'phase'         => planPhaseLabel($p),
        'custom_title'  => $custom_title,
        'goal'          => $p['goal'],
        'trainer_name'  => $p['trainer_name'],
        'diet_type'     => ucfirst($p['diet_type']),
        'calories'      => (int) $p['calories'],
        'duration'      => (int) $p['duration'],
        'created_at'    => !empty($p['created_at']) ? date('d M Y', strtotime($p['created_at'])) : '',
        'is_current'    => $i === 0,
        'meals'         => [
            'breakfast' => formatDietBlock($p['breakfast']),
            'lunch'     => formatDietBlock($p['lunch']),
            'snack'     => formatDietBlock($p['snack']),
            'dinner'    => formatDietBlock($p['dinner']),
        ],
        'resources'     => planResources($p),
        'download_url'  => '../../handlers/download_diet_plan_pdf.php?id=' . (int) $p['id'],
        'view_url'      => '../../handlers/download_diet_plan_pdf.php?id=' . (int) $p['id'] . '&inline=1',
    ];
}
$ACTIVE_NAV = 'diet';
$PAGE_TITLE = 'Diet Plans';
require __DIR__ . '/_shell_top.php';
?>
<style>
        :root {
            --soft: #6B7280; --faint: #9CA3AF; --coral-dark: #E5502B; --coral-tint: #FFEDE7;
            --green: #1FA971; --green-tint: #E7F8F0; --red: #E5484D; --amber: #F0A93A;
            --shadow: 0 6px 20px -14px rgba(20,20,30,.18);
        }
        .wrap { max-width: 1180px; margin: 0 auto; }

        /* ===== Two-column layout: plan details left, chat sticky on the right ===== */
        .plan-layout { display: grid; grid-template-columns: 1fr 380px; gap: 20px; align-items: start; }
        .plan-side { position: sticky; top: 20px; }
        .chat-panel { display: flex; flex-direction: column; height: calc(100vh - 100px); max-height: 720px; }
        .chat-panel .chat-box { flex: 1; height: auto; }
        @media (max-width: 980px) {
            .plan-layout { grid-template-columns: 1fr; }
            .plan-side { position: static; }
            .chat-panel { height: auto; max-height: none; }
            .chat-panel .chat-box { height: 380px; }
        }

        .page-head { margin-bottom: 20px; display: flex; flex-wrap: wrap; align-items: flex-end; justify-content: space-between; gap: 14px; }
        .page-head h1 { font-size: 24px; }
        .page-head p { color: var(--soft); font-size: 13.5px; margin-top: 4px; }
        .page-head p b { color: var(--ink); }

        .empty-card { text-align: center; padding: 60px 20px; background: var(--card); border-radius: var(--radius); box-shadow: var(--shadow); border: 1px solid var(--border); color: var(--faint); }
        .empty-card .big { font-size: 36px; margin-bottom: 12px; }

        /* ===== Header actions: download + week switcher ===== */
        .head-actions { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
        .head-btn {
            display: inline-flex; align-items: center; gap: 8px; padding: 10px 16px; border-radius: 10px;
            font-size: 13px; font-weight: 700; border: 1px solid var(--border); background: var(--card); color: var(--ink);
        }
        .head-btn:hover { border-color: var(--coral); color: var(--coral-dark); }
        .head-btn img { width: 14px; height: 14px; }
        .week-switcher {
            display: flex; align-items: center; gap: 8px; padding: 9px 14px 9px 12px; border-radius: 10px;
            border: 1px solid #FDBA8C; background: var(--coral-tint);
        }
        .week-switcher img { width: 14px; height: 14px; }
        #weekSelect {
            font-family: inherit; font-size: 13px; font-weight: 700; color: var(--coral-dark);
            border: none; background: transparent; cursor: pointer; appearance: none; -webkit-appearance: none;
        }
        #weekSelect:focus { outline: none; }

        /* ===== Stat cards (Goal / Trainer / Calories / Duration) ===== */
        .stats-row { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 14px; margin-bottom: 18px; }
        .stat-card { display: flex; align-items: center; gap: 12px; background: var(--card); border: 1px solid var(--border); border-radius: 14px; padding: 16px; box-shadow: var(--shadow); }
        .stat-icon-box { width: 42px; height: 42px; flex-shrink: 0; border-radius: 11px; background: var(--coral-tint); display: flex; align-items: center; justify-content: center; }
        .stat-icon-box img { width: 19px; height: 19px; filter: brightness(0) saturate(100%) invert(41%) sepia(96%) saturate(1636%) hue-rotate(346deg) brightness(97%) contrast(93%); }
        .stat-card .lbl { display: block; font-size: 11.5px; color: var(--faint); font-weight: 600; }
        .stat-card .val { display: block; font-size: 14.5px; font-weight: 700; color: var(--ink); margin-top: 2px; }

        /* ===== Content panels ===== */
        .content-panel { background: var(--card); border-radius: 18px; box-shadow: var(--shadow); border: 1px solid var(--border); padding: 22px 24px; margin-bottom: 16px; }
        .panel-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 16px; }
        .panel-header h3 { font-size: 15px; }
        .panel-header .tag { font-size: 11.5px; font-weight: 700; background: var(--coral-tint); color: var(--coral-dark); padding: 5px 12px; border-radius: 999px; }

        .meal-list { display: flex; flex-direction: column; gap: 4px; }
        .meal-row { display: flex; gap: 16px; padding: 16px 0; border-top: 1px solid var(--border); }
        .meal-row:first-child { border-top: none; padding-top: 0; }
        .meal-time { display: flex; align-items: center; gap: 10px; width: 130px; flex-shrink: 0; }
        .time-icon { width: 34px; height: 34px; border-radius: 10px; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
        .time-icon.sun { background: #FEF3C7; }
        .time-icon.cloud { background: #E0F2FE; }
        .time-icon.coffee { background: #F3E8D8; }
        .time-icon.moon { background: #E5E7FF; }
        .time-icon img { width: 16px; height: 16px; }
        .meal-name { font-weight: 700; font-size: 13.5px; }
        .meal-content { flex: 1; font-size: 13px; line-height: 1.65; color: var(--soft); }
        .meal-subhead { display: block; font-weight: 700; color: var(--ink); margin-top: 8px; }
        .meal-subhead:first-child { margin-top: 0; }
        .muted { color: var(--faint); }
        @media (max-width: 560px) { .meal-row { flex-direction: column; gap: 8px; } .meal-time { width: auto; } }

        .resource-list { display: flex; flex-wrap: wrap; gap: 10px; }
        .resource-btn { display: inline-flex; align-items: center; gap: 8px; padding: 10px 16px; border-radius: 10px; background: #FFF3EC; color: #C2410C; font-weight: 600; font-size: 13px; border: 1px solid #FDBA8C; }
        .resource-btn:hover { background: #FDE5D6; }

        /* Chat */
        .chat-box { display: flex; flex-direction: column; height: 320px; border: 1px solid var(--border); border-radius: 13px; overflow: hidden; }
        .chat-messages { flex: 1; overflow-y: auto; padding: 16px; display: flex; flex-direction: column; gap: 10px; background: var(--bg); }
        .msg { max-width: 75%; padding: 10px 14px; border-radius: 14px; font-size: 13.5px; line-height: 1.4; }
        .msg.user { align-self: flex-end; background: var(--coral); color: #fff; border-bottom-right-radius: 4px; }
        .msg.admin { align-self: flex-start; background: #fff; color: var(--ink); border: 1px solid var(--border); border-bottom-left-radius: 4px; }
        .msg .time { display: block; font-size: 10px; opacity: .75; margin-top: 4px; }
        .chat-input-row { display: flex; gap: 10px; padding: 12px; border-top: 1px solid var(--border); background: #fff; }
        .chat-input-row textarea { flex: 1; resize: none; border: 1px solid var(--border); border-radius: 10px; padding: 10px 12px; font-family: inherit; font-size: 13.5px; min-height: 44px; }
        .chat-input-row textarea:focus { border-color: var(--coral); outline: none; box-shadow: 0 0 0 3px var(--coral-tint); }
        .chat-input-row button { background: var(--coral); color: #fff; border: none; border-radius: 10px; padding: 0 20px; font-weight: 700; cursor: pointer; }
        .chat-input-row button:hover { background: var(--coral-dark); }
        .chat-placeholder { flex: 1; display: flex; align-items: center; justify-content: center; color: var(--faint); font-size: 13px; }

        /* ===== Mobile hardening ===== */
        @media (max-width: 640px) {
            .page-head { flex-direction: column; align-items: stretch; }
            .head-actions { justify-content: space-between; }
            .content-panel { padding: 18px 16px; }
            .chat-box { height: 280px; }
            .chat-input-row { flex-wrap: wrap; }
            .chat-input-row textarea { flex: 1 1 100%; }
            .chat-input-row button { flex: 1 1 100%; padding: 10px; }
            .msg { max-width: 88%; }
        }
    </style>

    <div class="wrap">
        <div class="page-head">
            <div>
                <h1>My Diet Plan</h1>
                <p>Current Phase: <b id="planPhaseSub">—</b></p>
            </div>
            <?php if ($current): ?>
                <div class="head-actions">
                    <a class="head-btn" id="planViewPdf" href="#" target="_blank">
                        <img src="../../icons/eye-solid-full.svg" alt="">
                        View PDF
                    </a>
                    <a class="head-btn" id="planDownload" href="#" target="_blank">
                        <img src="../../icons/download-solid-full.svg" alt="">
                        Download Plan
                    </a>
                    <?php if (count($plans_js) > 1): ?>
                        <div class="week-switcher">
                            <img src="../../icons/layer-group-solid-full.svg" alt="">
                            <select id="weekSelect"></select>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>

        <?php if (!$current): ?>
            <div class="empty-card">
                <div class="big">🥗</div>
                <h3>No diet plan assigned yet</h3>
                <p style="margin-top:8px;">Your trainer hasn't assigned a diet plan yet — check back soon!</p>
            </div>
        <?php else: ?>

            <div class="plan-layout">
                <div class="plan-main">
                    <div class="stats-row">
                        <div class="stat-card">
                            <div class="stat-icon-box"><img src="../../icons/fire-solid-full.svg" alt=""></div>
                            <div><span class="lbl">Goal</span><span class="val" id="statGoal">—</span></div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-icon-box"><img src="../../icons/user-tie-solid-full.svg" alt=""></div>
                            <div><span class="lbl">Trainer</span><span class="val" id="statTrainer">—</span></div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-icon-box"><img src="../../icons/utensils-solid-full.svg" alt=""></div>
                            <div><span class="lbl">Calories</span><span class="val" id="statCalories">—</span></div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-icon-box"><img src="../../icons/clock-solid-full.svg" alt=""></div>
                            <div><span class="lbl">Duration</span><span class="val" id="statDuration">—</span></div>
                        </div>
                    </div>

                    <div class="content-panel">
                        <div class="panel-header">
                            <h3>Daily Schedule</h3>
                            <span class="tag" id="planScheduleTag">Phase</span>
                        </div>
                        <div class="meal-list">
                            <div class="meal-row">
                                <div class="meal-time"><div class="time-icon sun"><img src="../../icons/sun-solid-full.svg" alt=""></div><span class="meal-name">Morning</span></div>
                                <div class="meal-content" id="mealBreakfast"></div>
                            </div>
                            <div class="meal-row">
                                <div class="meal-time"><div class="time-icon cloud"><img src="../../icons/cloud-sun-solid-full.svg" alt=""></div><span class="meal-name">Lunch</span></div>
                                <div class="meal-content" id="mealLunch"></div>
                            </div>
                            <div class="meal-row">
                                <div class="meal-time"><div class="time-icon coffee"><img src="../../icons/mug-hot-solid-full.svg" alt=""></div><span class="meal-name">Snack</span></div>
                                <div class="meal-content" id="mealSnack"></div>
                            </div>
                            <div class="meal-row">
                                <div class="meal-time"><div class="time-icon moon"><img src="../../icons/moon-solid-full.svg" alt=""></div><span class="meal-name">Night</span></div>
                                <div class="meal-content" id="mealDinner"></div>
                            </div>
                        </div>
                    </div>

                    <div class="content-panel" id="planResourcesWrap" style="display:none;">
                        <div class="panel-header"><h3>Resources &amp; Recommended Products</h3></div>
                        <div class="resource-list" id="planResources"></div>
                    </div>
                </div>

                <div class="plan-side">
                    <div class="content-panel chat-panel">
                        <div class="panel-header"><h3>💬 Ask About This Plan</h3></div>
                        <div class="chat-box">
                            <div class="chat-messages" id="chatMessages">
                                <div class="chat-placeholder">Loading conversation…</div>
                            </div>
                            <div class="chat-input-row">
                                <textarea id="chatInput" placeholder="Ask your trainer a question about this plan..."></textarea>
                                <button type="button" id="chatSendBtn">Send</button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

        <?php endif; ?>
    </div>

    <script>
        const MEMBER_ID = <?= (int) $member_id ?>;
        const PLANS = <?= json_encode($plans_js) ?>;

        document.addEventListener('DOMContentLoaded', function () {
            const weekSelect = document.getElementById('weekSelect');
            const phaseSubEl = document.getElementById('planPhaseSub');
            const scheduleTagEl = document.getElementById('planScheduleTag');
            const downloadEl = document.getElementById('planDownload');
            const viewPdfEl = document.getElementById('planViewPdf');
            const statGoal = document.getElementById('statGoal');
            const statTrainer = document.getElementById('statTrainer');
            const statCalories = document.getElementById('statCalories');
            const statDuration = document.getElementById('statDuration');
            const mealBreakfast = document.getElementById('mealBreakfast');
            const mealLunch = document.getElementById('mealLunch');
            const mealSnack = document.getElementById('mealSnack');
            const mealDinner = document.getElementById('mealDinner');
            const resourcesWrap = document.getElementById('planResourcesWrap');
            const resourcesEl = document.getElementById('planResources');
            const chatMessages = document.getElementById('chatMessages');
            const chatInput = document.getElementById('chatInput');
            const chatSendBtn = document.getElementById('chatSendBtn');

            if (!PLANS.length) return;

            let activePlanId = null;
            let pollTimer = null;

            function escapeHtml(str) {
                const div = document.createElement('div');
                div.textContent = str;
                return div.innerHTML;
            }

            function findPlan(id) {
                return PLANS.find(p => p.id === Number(id));
            }

            if (weekSelect) {
                weekSelect.innerHTML = PLANS.map(p =>
                    `<option value="${p.id}">${escapeHtml(p.phase)}${p.is_current ? ' (Current)' : ''}</option>`
                ).join('');
                weekSelect.addEventListener('change', () => renderPlan(weekSelect.value));
            }

            function renderPlan(id) {
                const plan = findPlan(id);
                if (!plan) return;

                activePlanId = plan.id;
                if (weekSelect) weekSelect.value = String(plan.id);
                phaseSubEl.textContent = plan.phase + (plan.custom_title ? ' · ' + plan.custom_title : '');
                scheduleTagEl.textContent = 'Phase: ' + plan.phase;
                statGoal.textContent = plan.goal;
                statTrainer.textContent = plan.trainer_name || '—';
                statCalories.textContent = plan.calories + ' kcal';
                statDuration.textContent = plan.duration + ' Weeks';
                downloadEl.href = plan.download_url;
                viewPdfEl.href = plan.view_url;
                mealBreakfast.innerHTML = plan.meals.breakfast;
                mealLunch.innerHTML = plan.meals.lunch;
                mealSnack.innerHTML = plan.meals.snack;
                mealDinner.innerHTML = plan.meals.dinner;
                if (plan.resources && plan.resources.length) {
                    resourcesEl.innerHTML = plan.resources.map(r =>
                        `<a class="resource-btn" href="${escapeHtml(r.link || '#')}" target="_blank" rel="noopener">🛒 ${escapeHtml(r.name || 'Resource')}</a>`
                    ).join('');
                    resourcesWrap.style.display = 'block';
                } else {
                    resourcesWrap.style.display = 'none';
                }

                startChatPolling();
            }

            function renderMessages(messages) {
                if (!messages.length) {
                    chatMessages.innerHTML = '<div class="chat-placeholder">No messages yet — ask your first question!</div>';
                    return;
                }
                chatMessages.innerHTML = messages.map(m => `
                    <div class="msg ${m.sender_role === 'user' ? 'user' : 'admin'}">
                        ${escapeHtml(m.message).replace(/\n/g, '<br>')}
                        <span class="time">${m.created_at}</span>
                    </div>
                `).join('');
                chatMessages.scrollTop = chatMessages.scrollHeight;
            }

            function fetchMessages() {
                if (!activePlanId) return;
                fetch(`../../handlers/diet_chat_fetch.php?plan_id=${activePlanId}&member_id=${MEMBER_ID}`)
                    .then(res => res.json())
                    .then(data => { if (data.success) renderMessages(data.messages); })
                    .catch(() => { });
            }

            function startChatPolling() {
                chatMessages.innerHTML = '<div class="chat-placeholder">Loading conversation…</div>';
                fetchMessages();
                if (pollTimer) clearInterval(pollTimer);
                pollTimer = setInterval(fetchMessages, 6000);
            }

            function sendMessage() {
                const text = chatInput.value.trim();
                if (!text || !activePlanId) return;

                const formData = new FormData();
                formData.append('plan_id', activePlanId);
                formData.append('message', text);

                chatSendBtn.disabled = true;
                fetch('../../handlers/diet_chat_send.php', { method: 'POST', body: formData })
                    .then(res => res.json())
                    .then(data => { if (data.success) { chatInput.value = ''; fetchMessages(); } })
                    .finally(() => { chatSendBtn.disabled = false; });
            }

            chatSendBtn.addEventListener('click', sendMessage);
            chatInput.addEventListener('keydown', function (e) {
                if (e.key === 'Enter' && !e.shiftKey) {
                    e.preventDefault();
                    sendMessage();
                }
            });

            // Deep-link to a specific week (e.g. from the dashboard's "View Full Plan" or the Messages bell);
            // otherwise default to the current week's plan.
            const deepOpenId = <?= (int) $open_plan ?>;
            renderPlan((deepOpenId && findPlan(deepOpenId)) ? deepOpenId : PLANS[0].id);
        });
    </script>

<?php require __DIR__ . '/_shell_bottom.php'; ?>
