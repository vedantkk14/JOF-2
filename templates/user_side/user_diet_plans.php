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
        .wrap { max-width: 1080px; margin: 0 auto; }

        .page-head { margin-bottom: 20px; }
        .page-head h1 { font-size: 24px; }
        .page-head p { color: var(--soft); font-size: 13.5px; margin-top: 4px; }

        .empty-card { text-align: center; padding: 60px 20px; background: var(--card); border-radius: var(--radius); box-shadow: var(--shadow); border: 1px solid var(--border); color: var(--faint); }
        .empty-card .big { font-size: 36px; margin-bottom: 12px; }

        /* ===== Card grid ===== */
        .plan-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(240px, 1fr)); gap: 16px; }
        .plan-card {
            background: var(--card); border-radius: 16px; padding: 20px; box-shadow: var(--shadow); border: 1px solid var(--border);
            cursor: pointer; transition: transform .15s ease, box-shadow .15s ease; position: relative; overflow: hidden;
        }
        .plan-card:hover { transform: translateY(-3px); box-shadow: 0 14px 30px -16px rgba(20,20,30,.28); }
        .plan-card.current { background: linear-gradient(135deg, #FF6B47 0%, #FF8A5B 55%, #FFA94D 100%); color: #fff; border: none; }
        .plan-card .ribbon {
            position: absolute; top: 14px; right: -30px; transform: rotate(35deg);
            background: #fff; color: var(--coral-dark); font-size: 10px; font-weight: 800; letter-spacing: .04em;
            padding: 4px 34px; text-transform: uppercase;
        }
        .plan-card .icon-box { width: 40px; height: 40px; border-radius: 12px; background: var(--coral-tint); display: flex; align-items: center; justify-content: center; font-size: 18px; margin-bottom: 14px; }
        .plan-card.current .icon-box { background: rgba(255,255,255,.22); }
        .plan-title-badge { font-size: 11px; font-weight: 800; text-transform: uppercase; letter-spacing: .04em; color: var(--coral-dark); margin-bottom: 4px; }
        .plan-card.current .plan-title-badge { color: #fff; opacity: .95; }
        .plan-card h3 { font-size: 16px; margin-bottom: 6px; }
        .plan-card .meta { font-size: 12px; opacity: .85; margin-bottom: 12px; }
        .plan-card .chips { display: flex; flex-wrap: wrap; gap: 6px; }
        .plan-card .chips span { font-size: 11px; font-weight: 700; padding: 4px 10px; border-radius: 999px; background: var(--bg); color: var(--soft); }
        .plan-card.current .chips span { background: rgba(255,255,255,.22); color: #fff; }
        .plan-card .view-hint { margin-top: 14px; font-size: 12px; font-weight: 700; display: flex; align-items: center; gap: 6px; }
        .download-btn {
            display: block; margin-top: 12px; text-align: center; padding: 8px 10px; border-radius: 9px;
            background: rgba(255,255,255,.9); color: var(--coral-dark); font-size: 12px; font-weight: 700;
        }
        .plan-card:not(.current) .download-btn { background: var(--bg); }
        .download-btn:hover { background: #fff; }

        /* ===== Modal ===== */
        .plan-modal-overlay {
            display: none; position: fixed; inset: 0; background: rgba(15,20,32,.55); backdrop-filter: blur(3px);
            z-index: 2000; align-items: center; justify-content: center; padding: 20px;
        }
        .plan-modal-overlay.active { display: flex; }
        .plan-modal { background: var(--card); border-radius: 22px; width: 100%; max-width: 720px; max-height: 88vh; overflow-y: auto; }
        .plan-modal-banner { background: linear-gradient(135deg, #FF6B47 0%, #FF8A5B 55%, #FFA94D 100%); color: #fff; padding: 26px 28px; border-radius: 22px 22px 0 0; position: relative; }
        .plan-modal-banner .tag { display: inline-flex; align-items: center; gap: 6px; font-size: 11px; font-weight: 800; text-transform: uppercase; letter-spacing: .04em; background: rgba(255,255,255,.22); padding: 4px 11px; border-radius: 999px; margin-bottom: 10px; }
        .plan-modal-banner h2 { font-size: 21px; }
        .plan-modal-banner .sub { font-size: 13px; opacity: .92; margin-top: 4px; }
        .plan-modal-close { position: absolute; top: 18px; right: 18px; border: none; background: rgba(255,255,255,.25); color: #fff; border-radius: 9px; width: 32px; height: 32px; cursor: pointer; font-size: 15px; }
        .plan-modal-close:hover { background: rgba(255,255,255,.4); }
        .plan-modal-chips { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 14px; }
        .plan-modal-chips span { font-size: 12px; font-weight: 700; background: rgba(255,255,255,.22); padding: 5px 12px; border-radius: 999px; }
        .plan-modal-body { padding: 24px 28px 28px; }
        .plan-modal-body h3 { font-size: 14px; display: flex; align-items: center; gap: 8px; margin-bottom: 14px; }

        .meal-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; margin-bottom: 22px; }
        @media (max-width: 560px) { .meal-grid { grid-template-columns: 1fr; } }
        .meal-card { background: var(--bg); border-radius: 13px; padding: 15px; }
        .meal-card .meal-title { display: flex; align-items: center; gap: 8px; font-weight: 700; font-size: 13px; margin-bottom: 8px; }
        .meal-card .meal-body { font-size: 13px; line-height: 1.6; color: var(--soft); }
        .meal-subhead { display: block; font-weight: 700; color: var(--ink); margin-top: 8px; }
        .meal-subhead:first-child { margin-top: 0; }
        .muted { color: var(--faint); }

        .resource-list { display: flex; flex-wrap: wrap; gap: 10px; margin-bottom: 22px; }
        .resource-btn { display: inline-flex; align-items: center; gap: 8px; padding: 10px 16px; border-radius: 10px; background: #FFF3EC; color: #C2410C; font-weight: 600; font-size: 13px; border: 1px solid #FDBA8C; }
        .resource-btn:hover { background: #FDE5D6; }

        /* Chat (inside modal) */
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
            .plan-modal-overlay { padding: 10px; }
            .plan-modal-banner { padding: 20px 18px; }
            .plan-modal-banner h2 { font-size: 18px; }
            .plan-modal-body { padding: 18px 18px 22px; }
            .chat-box { height: 280px; }
            .chat-input-row { flex-wrap: wrap; }
            .chat-input-row textarea { flex: 1 1 100%; }
            .chat-input-row button { flex: 1 1 100%; padding: 10px; }
            .msg { max-width: 88%; }
        }
    </style>

    <div class="wrap">
        <div class="page-head">
            <h1>My Diet Plan</h1>
            <p>Your assigned nutrition schedules, week by week.</p>
        </div>

        <?php if (!$current): ?>
            <div class="empty-card">
                <div class="big">🥗</div>
                <h3>No diet plan assigned yet</h3>
                <p style="margin-top:8px;">Your trainer hasn't assigned a diet plan yet — check back soon!</p>
            </div>
        <?php else: ?>

            <div class="plan-grid" id="planGrid">
                <?php foreach ($plans_js as $p): ?>
                    <div class="plan-card <?= $p['is_current'] ? 'current' : '' ?>" data-plan-id="<?= $p['id'] ?>">
                        <?php if ($p['is_current']): ?><span class="ribbon">Current</span><?php endif; ?>
                        <div class="icon-box">🍽</div>
                        <?php if ($p['custom_title']): ?>
                            <div class="plan-title-badge"><?= e($p['custom_title']) ?></div>
                        <?php endif; ?>
                        <h3><?= e($p['phase']) ?></h3>
                        <div class="meta"><?= e($p['goal']) ?> · <?= e($p['created_at']) ?></div>
                        <div class="chips">
                            <span>🥗 <?= e($p['diet_type']) ?></span>
                            <span>🔥 <?= e($p['calories']) ?> kcal</span>
                            <span>⏱ <?= e($p['duration']) ?>w</span>
                        </div>
                        <div class="view-hint">View plan →</div>
                        <a class="download-btn" href="<?= e($p['download_url']) ?>" onclick="event.stopPropagation();">⬇ Download Diet Plan</a>
                    </div>
                <?php endforeach; ?>
            </div>

        <?php endif; ?>
    </div>

    <!-- Plan detail modal -->
    <div class="plan-modal-overlay" id="planModalOverlay">
        <div class="plan-modal">
            <div class="plan-modal-banner">
                <button type="button" class="plan-modal-close" id="planModalClose">✕</button>
                <div class="tag" id="planModalTag">This week's plan</div>
                <div class="plan-title-badge" id="planModalCustomTitle" style="display:none; color:#fff; opacity:.95;"></div>
                <h2 id="planModalTitle">Diet Plan</h2>
                <div class="sub" id="planModalSub"></div>
                <div class="plan-modal-chips" id="planModalChips"></div>
                <a class="download-btn" id="planModalDownload" href="#" style="display:inline-block; margin-top:14px; width:auto; padding:9px 18px;">⬇ Download Diet Plan (PDF)</a>
            </div>
            <div class="plan-modal-body">
                <h3>🍽 Daily Schedule</h3>
                <div class="meal-grid" id="planModalMeals"></div>

                <div id="planModalResourcesWrap" style="display:none;">
                    <h3>🛒 Resources &amp; Recommended Products</h3>
                    <div class="resource-list" id="planModalResources"></div>
                </div>

                <h3>💬 Ask About This Plan</h3>
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

    <script>
        const MEMBER_ID = <?= (int) $member_id ?>;
        const PLANS = <?= json_encode($plans_js) ?>;

        document.addEventListener('DOMContentLoaded', function () {
            const overlay = document.getElementById('planModalOverlay');
            const closeBtn = document.getElementById('planModalClose');
            const tagEl = document.getElementById('planModalTag');
            const titleEl = document.getElementById('planModalTitle');
            const subEl = document.getElementById('planModalSub');
            const chipsEl = document.getElementById('planModalChips');
            const customTitleEl = document.getElementById('planModalCustomTitle');
            const downloadEl = document.getElementById('planModalDownload');
            const mealsEl = document.getElementById('planModalMeals');
            const resourcesWrap = document.getElementById('planModalResourcesWrap');
            const resourcesEl = document.getElementById('planModalResources');
            const chatMessages = document.getElementById('chatMessages');
            const chatInput = document.getElementById('chatInput');
            const chatSendBtn = document.getElementById('chatSendBtn');

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

            function openPlanModal(id) {
                const plan = findPlan(id);
                if (!plan) return;

                activePlanId = plan.id;
                tagEl.textContent = plan.is_current ? "This is your diet plan for this week" : plan.phase;
                if (plan.custom_title) {
                    customTitleEl.textContent = plan.custom_title;
                    customTitleEl.style.display = 'block';
                } else {
                    customTitleEl.style.display = 'none';
                }
                titleEl.textContent = plan.phase;
                subEl.textContent = plan.goal;
                chipsEl.innerHTML = `
                    <span>🥗 ${escapeHtml(plan.diet_type)}</span>
                    <span>🔥 ${escapeHtml(String(plan.calories))} kcal</span>
                    <span>⏱ ${escapeHtml(String(plan.duration))} weeks</span>
                `;
                downloadEl.href = plan.download_url;
                mealsEl.innerHTML = `
                    <div class="meal-card"><div class="meal-title">🌅 Morning</div><div class="meal-body">${plan.meals.breakfast}</div></div>
                    <div class="meal-card"><div class="meal-title">🍛 Lunch</div><div class="meal-body">${plan.meals.lunch}</div></div>
                    <div class="meal-card"><div class="meal-title">🍎 Mid Meal</div><div class="meal-body">${plan.meals.snack}</div></div>
                    <div class="meal-card"><div class="meal-title">🌙 Night</div><div class="meal-body">${plan.meals.dinner}</div></div>
                `;
                if (plan.resources && plan.resources.length) {
                    resourcesEl.innerHTML = plan.resources.map(r =>
                        `<a class="resource-btn" href="${escapeHtml(r.link || '#')}" target="_blank" rel="noopener">🛒 ${escapeHtml(r.name || 'Resource')}</a>`
                    ).join('');
                    resourcesWrap.style.display = 'block';
                } else {
                    resourcesWrap.style.display = 'none';
                }

                overlay.classList.add('active');
                startChatPolling();
            }

            function closePlanModal() {
                overlay.classList.remove('active');
                activePlanId = null;
                if (pollTimer) clearInterval(pollTimer);
            }

            document.querySelectorAll('.plan-card').forEach(card => {
                card.addEventListener('click', () => openPlanModal(card.dataset.planId));
            });
            closeBtn.addEventListener('click', closePlanModal);
            overlay.addEventListener('click', function (e) {
                if (e.target === overlay) closePlanModal();
            });

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

            // Open a specific plan's modal only when linked to directly (e.g. from the Messages bell)
            const deepOpenId = <?= (int) $open_plan ?>;
            if (deepOpenId && findPlan(deepOpenId)) {
                openPlanModal(deepOpenId);
            }
        });
    </script>

<?php require __DIR__ . '/_shell_bottom.php'; ?>
