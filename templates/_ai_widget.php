<?php
/**
 * templates/_ai_widget.php
 * ─────────────────────────────────────────────────────────────────
 * Floating "Nutrition Assistant" button + chat card for admin pages.
 *
 * Drop it in with a single include before </body>:
 *     <?php include '_ai_widget.php'; ?>
 *
 * Optional: set $ai_widget_member_id before the include to pre-select a member
 * (person_info.php can pass the member whose page is open).
 *
 * Everything is namespaced under .jof-ai so it can't collide with page styles.
 */

require_once __DIR__ . '/../auth/ai_config.php';

$ai_csrf      = generate_csrf_token();
$ai_problem   = ai_config_problem();
$ai_preselect = isset($ai_widget_member_id) ? (int) $ai_widget_member_id : 0;

$ai_members = [];
$ai_res = $conn->query("SELECT id, full_name, diet_type FROM members WHERE status = 'active' ORDER BY full_name ASC");
if ($ai_res) {
    while ($ai_row = $ai_res->fetch_assoc()) {
        $ai_members[] = $ai_row;
    }
}
?>

<style>
    .jof-ai-fab {
        position: fixed; right: 26px; bottom: 26px; z-index: 9800;
        width: 58px; height: 58px; border-radius: 50%; border: none; cursor: pointer;
        background: linear-gradient(135deg, #F25C2A, #E5502B); color: #fff;
        display: flex; align-items: center; justify-content: center;
        box-shadow: 0 8px 24px rgba(242, 92, 42, .42);
        transition: transform .18s ease, box-shadow .18s ease;
    }
    .jof-ai-fab:hover { transform: translateY(-2px) scale(1.04); box-shadow: 0 12px 30px rgba(242, 92, 42, .52); }
    .jof-ai-fab svg { width: 26px; height: 26px; }
    .jof-ai-fab.hidden { display: none; }

    .jof-ai-card {
        position: fixed; right: 26px; bottom: 26px; z-index: 9801;
        width: 420px; max-width: calc(100vw - 32px);
        height: min(640px, calc(100vh - 60px));
        background: #fff; border-radius: 20px; overflow: hidden;
        box-shadow: 0 24px 60px rgba(15, 23, 42, .26);
        display: none; flex-direction: column;
        animation: jofAiPop .26s cubic-bezier(.34, 1.56, .64, 1) both;
    }
    .jof-ai-card.open { display: flex; }
    @keyframes jofAiPop { from { opacity: 0; transform: translateY(18px) scale(.97); } to { opacity: 1; transform: none; } }

    .jof-ai-head {
        display: flex; align-items: center; gap: 12px; padding: 16px 18px;
        background: linear-gradient(135deg, #F25C2A, #E5502B); color: #fff; flex-shrink: 0;
    }
    .jof-ai-head .ic {
        width: 38px; height: 38px; border-radius: 11px; flex-shrink: 0;
        background: rgba(255, 255, 255, .2); display: flex; align-items: center; justify-content: center;
    }
    .jof-ai-head .ic svg { width: 19px; height: 19px; }
    .jof-ai-head h3 { margin: 0; font-size: 15px; font-weight: 700; }
    .jof-ai-head p { margin: 1px 0 0; font-size: 11.5px; opacity: .9; }
    .jof-ai-x {
        margin-left: auto; width: 30px; height: 30px; border: none; border-radius: 8px; cursor: pointer;
        background: rgba(255, 255, 255, .18); color: #fff; font-size: 17px; line-height: 1;
        display: flex; align-items: center; justify-content: center;
    }
    .jof-ai-x:hover { background: rgba(255, 255, 255, .3); }

    .jof-ai-picker { padding: 11px 16px; border-bottom: 1px solid #F1F5F9; background: #FAFBFC; flex-shrink: 0; }
    .jof-ai-picker label { display: block; font-size: 10.5px; font-weight: 700; text-transform: uppercase; letter-spacing: .05em; color: #94A3B8; margin-bottom: 5px; }
    .jof-ai-picker select {
        width: 100%; padding: 9px 11px; border: 1.5px solid #E2E8F0; border-radius: 9px;
        font-size: 13.5px; font-family: inherit; color: #1E293B; background: #fff; cursor: pointer;
    }
    .jof-ai-picker select:focus { outline: none; border-color: #F25C2A; }

    .jof-ai-body { flex: 1; overflow-y: auto; padding: 16px; background: #F8FAFC; }
    .jof-ai-msg { margin-bottom: 12px; display: flex; }
    .jof-ai-msg .bubble {
        max-width: 85%; padding: 10px 13px; border-radius: 14px; font-size: 13.5px; line-height: 1.55;
        white-space: pre-wrap; word-wrap: break-word;
    }
    .jof-ai-msg.bot .bubble { background: #fff; color: #1E293B; border: 1px solid #E8EDF2; border-bottom-left-radius: 4px; }
    .jof-ai-msg.user { justify-content: flex-end; }
    .jof-ai-msg.user .bubble { background: #F25C2A; color: #fff; border-bottom-right-radius: 4px; }
    .jof-ai-msg.err .bubble { background: #FEF2F2; color: #991B1B; border: 1px solid #FECACA; }
    .jof-ai-msg .bubble strong { font-weight: 700; }

    .jof-ai-chips { display: flex; flex-wrap: wrap; gap: 7px; margin-bottom: 12px; }
    .jof-ai-chip {
        padding: 7px 12px; border-radius: 999px; border: 1.5px solid #E2E8F0; background: #fff;
        font-size: 12px; font-weight: 600; color: #475569; cursor: pointer; transition: .15s; font-family: inherit;
    }
    .jof-ai-chip:hover { border-color: #F25C2A; color: #F25C2A; background: #FFF7F4; }

    .jof-ai-typing { display: flex; gap: 4px; padding: 11px 14px; background: #fff; border: 1px solid #E8EDF2; border-radius: 14px; width: fit-content; }
    .jof-ai-typing span { width: 7px; height: 7px; border-radius: 50%; background: #CBD5E1; animation: jofAiBounce 1.3s infinite; }
    .jof-ai-typing span:nth-child(2) { animation-delay: .18s; }
    .jof-ai-typing span:nth-child(3) { animation-delay: .36s; }
    @keyframes jofAiBounce { 0%, 60%, 100% { transform: translateY(0); opacity: .45; } 30% { transform: translateY(-5px); opacity: 1; } }

    .jof-ai-compose { display: flex; gap: 9px; padding: 12px 14px; border-top: 1px solid #F1F5F9; background: #fff; flex-shrink: 0; }
    .jof-ai-compose textarea {
        flex: 1; resize: none; border: 1.5px solid #E2E8F0; border-radius: 11px; padding: 10px 12px;
        font-size: 13.5px; font-family: inherit; max-height: 96px; min-height: 40px; color: #1E293B;
    }
    .jof-ai-compose textarea:focus { outline: none; border-color: #F25C2A; }
    .jof-ai-send {
        width: 40px; height: 40px; flex-shrink: 0; align-self: flex-end; border: none; border-radius: 11px; cursor: pointer;
        background: #F25C2A; color: #fff; display: flex; align-items: center; justify-content: center;
    }
    .jof-ai-send:hover { background: #E5502B; }
    .jof-ai-send:disabled { opacity: .45; cursor: not-allowed; }
    .jof-ai-send svg { width: 17px; height: 17px; }

    /* Draft review panel */
    .jof-ai-draft { border-top: 2px solid #F25C2A; background: #fff; flex-shrink: 0; max-height: 58%; overflow-y: auto; display: none; }
    .jof-ai-draft.open { display: block; }
    .jof-ai-draft-head { position: sticky; top: 0; background: #FFF7F4; padding: 11px 15px; border-bottom: 1px solid #FFE4D6; display: flex; align-items: center; gap: 8px; }
    .jof-ai-draft-head b { font-size: 13px; color: #9A3412; }
    .jof-ai-draft-head span { font-size: 11px; color: #C2410C; margin-left: auto; }
    .jof-ai-draft-body { padding: 13px 15px; }
    .jof-ai-row { display: grid; grid-template-columns: 1fr 1fr; gap: 9px; margin-bottom: 10px; }
    .jof-ai-f { margin-bottom: 10px; }
    .jof-ai-f label { display: block; font-size: 10.5px; font-weight: 700; text-transform: uppercase; letter-spacing: .04em; color: #94A3B8; margin-bottom: 4px; }
    .jof-ai-f input, .jof-ai-f select, .jof-ai-f textarea {
        width: 100%; padding: 8px 10px; border: 1.5px solid #E2E8F0; border-radius: 8px;
        font-size: 12.5px; font-family: inherit; color: #1E293B; box-sizing: border-box;
    }
    .jof-ai-f textarea { resize: vertical; min-height: 62px; line-height: 1.5; }
    .jof-ai-f input:focus, .jof-ai-f select:focus, .jof-ai-f textarea:focus { outline: none; border-color: #F25C2A; }
    .jof-ai-draft-actions { display: flex; gap: 9px; padding: 12px 15px; border-top: 1px solid #F1F5F9; position: sticky; bottom: 0; background: #fff; }
    .jof-ai-btn { flex: 1; padding: 10px; border-radius: 10px; font-size: 13px; font-weight: 700; cursor: pointer; font-family: inherit; border: none; }
    .jof-ai-btn.save { background: #16A34A; color: #fff; }
    .jof-ai-btn.save:hover { background: #15803D; }
    .jof-ai-btn.save:disabled { opacity: .5; cursor: not-allowed; }
    .jof-ai-btn.ghost { background: #F1F5F9; color: #475569; }
    .jof-ai-btn.ghost:hover { background: #E2E8F0; }

    .jof-ai-setup { padding: 20px; font-size: 13px; color: #92400E; background: #FFFBEB; border: 1px solid #FDE68A; border-radius: 12px; margin: 16px; line-height: 1.6; }
    .jof-ai-setup code { background: #FEF3C7; padding: 1px 5px; border-radius: 4px; font-size: 12px; }

    @media (max-width: 560px) {
        .jof-ai-card { right: 0; bottom: 0; width: 100vw; max-width: 100vw; height: 92vh; border-radius: 18px 18px 0 0; }
        .jof-ai-fab { right: 18px; bottom: 18px; }
        .jof-ai-row { grid-template-columns: 1fr; }
    }
</style>

<button class="jof-ai-fab" id="jofAiFab" title="Nutrition Assistant" aria-label="Open Nutrition Assistant">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
        <path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"/>
    </svg>
</button>

<div class="jof-ai-card" id="jofAiCard" role="dialog" aria-label="Nutrition Assistant">
    <div class="jof-ai-head">
        <span class="ic">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M11 20A7 7 0 0 1 9.8 6.1C15.5 5 17 4.48 19 2c1 2 2 4.18 2 8 0 5.5-4.78 10-10 10z"/><path d="M2 21c0-3 1.85-5.36 5.08-6C9.5 14.52 12 13 13 12"/>
            </svg>
        </span>
        <div>
            <h3>Nutrition Assistant</h3>
            <p id="jofAiSubtitle">General nutrition advice</p>
        </div>
        <button class="jof-ai-x" id="jofAiClose" aria-label="Close">&times;</button>
    </div>

    <?php if ($ai_problem !== ''): ?>
        <div class="jof-ai-setup">
            <b>Setup needed.</b><br><?= htmlspecialchars($ai_problem) ?><br><br>
            Copy <code>.env.example</code> to <code>.env</code>, add your key from
            <code>console.groq.com</code>, then reload this page.
        </div>
    <?php else: ?>
        <div class="jof-ai-picker">
            <label for="jofAiMember">Advising about</label>
            <select id="jofAiMember">
                <option value="0">General &mdash; no specific member</option>
                <?php foreach ($ai_members as $m): ?>
                    <option value="<?= (int) $m['id'] ?>" <?= $ai_preselect === (int) $m['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($m['full_name']) ?><?= $m['diet_type'] ? ' (' . htmlspecialchars($m['diet_type']) . ')' : '' ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="jof-ai-body" id="jofAiBody"></div>

        <div class="jof-ai-draft" id="jofAiDraft">
            <div class="jof-ai-draft-head">
                <b>Draft plan &mdash; review before saving</b>
                <span id="jofAiDraftCal"></span>
            </div>
            <div class="jof-ai-draft-body">
                <div class="jof-ai-row">
                    <div class="jof-ai-f"><label>Phase</label><input type="text" id="jofAiPhase"></div>
                    <div class="jof-ai-f"><label>Calories</label><input type="number" id="jofAiCalories"></div>
                </div>
                <div class="jof-ai-row">
                    <div class="jof-ai-f"><label>Goal</label><input type="text" id="jofAiGoal"></div>
                    <div class="jof-ai-f"><label>Diet type</label>
                        <select id="jofAiDietType">
                            <option value="veg">Veg</option>
                            <option value="nonveg">Non-veg</option>
                            <option value="vegan">Vegan</option>
                        </select>
                    </div>
                </div>
                <div class="jof-ai-f"><label>Wake up</label><textarea id="jofAiWakeUp"></textarea></div>
                <div class="jof-ai-f"><label>Breakfast</label><textarea id="jofAiBreakfast"></textarea></div>
                <div class="jof-ai-f"><label>Post workout</label><textarea id="jofAiPostWorkout"></textarea></div>
                <div class="jof-ai-f"><label>Lunch</label><textarea id="jofAiLunch"></textarea></div>
                <div class="jof-ai-f"><label>Mid meal / snack</label><textarea id="jofAiSnack"></textarea></div>
                <div class="jof-ai-f"><label>Dinner</label><textarea id="jofAiDinner"></textarea></div>
                <div class="jof-ai-f"><label>Pre-sleep</label><textarea id="jofAiPreSleep"></textarea></div>
                <div class="jof-ai-f"><label>Guidelines</label><textarea id="jofAiGuidelines"></textarea></div>
            </div>
            <div class="jof-ai-draft-actions">
                <button class="jof-ai-btn ghost" id="jofAiDiscard">Discard</button>
                <button class="jof-ai-btn save" id="jofAiSave">Save as phase</button>
            </div>
        </div>

        <div class="jof-ai-compose">
            <textarea id="jofAiInput" rows="1" placeholder="Ask anything about nutrition&hellip;"></textarea>
            <button class="jof-ai-send" id="jofAiSend" aria-label="Send">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M22 2L11 13M22 2l-7 20-4-9-9-4 20-7z"/>
                </svg>
            </button>
        </div>
    <?php endif; ?>
</div>

<?php if ($ai_problem === ''): ?>
<script>
(function () {
    const CSRF = <?= json_encode($ai_csrf) ?>;
    const fab = document.getElementById('jofAiFab');
    const card = document.getElementById('jofAiCard');
    const body = document.getElementById('jofAiBody');
    const input = document.getElementById('jofAiInput');
    const sendBtn = document.getElementById('jofAiSend');
    const memberSel = document.getElementById('jofAiMember');
    const subtitle = document.getElementById('jofAiSubtitle');
    const draftBox = document.getElementById('jofAiDraft');

    const F = {
        phase: 'jofAiPhase', calories: 'jofAiCalories', goal: 'jofAiGoal', diet_type: 'jofAiDietType',
        wake_up: 'jofAiWakeUp', breakfast: 'jofAiBreakfast', post_workout: 'jofAiPostWorkout',
        lunch: 'jofAiLunch', snack: 'jofAiSnack', dinner: 'jofAiDinner',
        pre_sleep: 'jofAiPreSleep', guidelines: 'jofAiGuidelines'
    };
    const el = k => document.getElementById(F[k]);

    let conversationId = 0, draftId = 0, busy = false;

    function openCard(open) {
        card.classList.toggle('open', open);
        fab.classList.toggle('hidden', open);
        if (open) { input.focus(); if (!body.children.length) greet(); }
    }
    fab.addEventListener('click', () => openCard(true));
    document.getElementById('jofAiClose').addEventListener('click', () => openCard(false));
    document.addEventListener('keydown', e => { if (e.key === 'Escape' && card.classList.contains('open')) openCard(false); });

    // The card shows plain text, but models still emit markdown tables, ###
    // headings and <br> tags now and then. Flatten all of that into readable
    // lines here, then escape, then apply the one bit of markup we allow.
    function fmt(text) {
        const out = [];
        // Table rows are flattened first: a <br> inside a cell would otherwise
        // split the row and leave its pipes stranded on the second half.
        for (const raw of String(text).replace(/```+/g, '').split('\n')) {
            let l = raw.trim();

            if (/^\|?[\s:|-]*-{3,}[\s:|-]*\|?$/.test(l)) continue;   // |---|---| separator

            if ((l.match(/\|/g) || []).length >= 2) {                 // a table row
                const cells = l.split('|')
                    .map(c => c.replace(/\s*<br\s*\/?>\s*/gi, ', ').replace(/<[^>]+>/g, '').trim())
                    .filter(c => c !== '');
                if (cells.length) out.push(cells.join(' — '));
                continue;
            }

            l = l.replace(/<br\s*\/?>/gi, '\n').replace(/<[^>]+>/g, '');
            l = l.replace(/^#{1,6}\s*(.+)$/, '**$1**');               // heading → bold line
            l = l.replace(/^[*+-]\s+/, '• ');                          // bullet → real bullet
            out.push(l);
        }

        const t = out.join('\n').replace(/\n{3,}/g, '\n\n').trim();
        const esc = t.replace(/[&<>"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
        return esc.replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>');
    }
    function bubble(role, text) {
        const wrap = document.createElement('div');
        wrap.className = 'jof-ai-msg ' + role;
        wrap.innerHTML = '<div class="bubble">' + fmt(text) + '</div>';
        body.appendChild(wrap);
        body.scrollTop = body.scrollHeight;
        return wrap;
    }
    function typing(on) {
        const old = document.getElementById('jofAiTyping');
        if (old) old.remove();
        if (!on) return;
        const t = document.createElement('div');
        t.id = 'jofAiTyping';
        t.className = 'jof-ai-msg bot';
        t.innerHTML = '<div class="jof-ai-typing"><span></span><span></span><span></span></div>';
        body.appendChild(t);
        body.scrollTop = body.scrollHeight;
    }

    function greet() {
        const name = memberSel.value !== '0' ? memberSel.options[memberSel.selectedIndex].text.trim() : '';
        body.innerHTML = '';
        if (name) {
            bubble('bot', "I've got **" + name + "**'s file open — measurements, metrics and past phases. Ask me anything, or generate their next plan.");
            chips(['Create their next phase', 'How is their progress?', 'Any concerns in their data?']);
        } else {
            bubble('bot', "I'm your nutrition assistant. Ask me about calories, macros, condition-specific diets, or pick a member above to work on their plan.");
            chips(['High-protein veg meals at 500 kcal', 'Diet basics for a diabetic client', 'How much protein for muscle gain?']);
        }
    }
    function chips(list) {
        const box = document.createElement('div');
        box.className = 'jof-ai-chips';
        list.forEach(t => {
            const b = document.createElement('button');
            b.type = 'button';
            b.className = 'jof-ai-chip';
            b.textContent = t;
            b.addEventListener('click', () => { input.value = t; send(); });
            box.appendChild(b);
        });
        body.appendChild(box);
    }

    memberSel.addEventListener('change', () => {
        // Switching member starts a clean thread so one member's data never
        // carries over into advice about another.
        conversationId = 0; draftId = 0;
        draftBox.classList.remove('open');
        subtitle.textContent = memberSel.value !== '0'
            ? 'Advising: ' + memberSel.options[memberSel.selectedIndex].text.trim()
            : 'General nutrition advice';
        greet();
    });

    input.addEventListener('input', () => {
        input.style.height = 'auto';
        input.style.height = Math.min(input.scrollHeight, 96) + 'px';
    });
    input.addEventListener('keydown', e => {
        if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); send(); }
    });
    sendBtn.addEventListener('click', () => send());

    // "plan" mode is requested when the admin clearly asks for a plan
    function wantsPlan(text) {
        return /\b(create|generate|make|build|draft|new)\b.{0,24}\b(plan|phase|diet)\b/i.test(text)
            || /\bnext phase\b/i.test(text);
    }

    async function send(forceMode) {
        const text = input.value.trim();
        if (!text || busy) return;
        const memberId = memberSel.value;
        const mode = forceMode || ((wantsPlan(text) && memberId !== '0') ? 'plan' : 'chat');

        bubble('user', text);
        input.value = ''; input.style.height = 'auto';
        busy = true; sendBtn.disabled = true; typing(true);

        const fd = new FormData();
        fd.append('_csrf_token', CSRF);
        fd.append('message', text);
        fd.append('mode', mode);
        fd.append('member_id', memberId);
        fd.append('conversation_id', conversationId);
        if (draftId && mode === 'plan') fd.append('draft_id', draftId);

        try {
            const res = await fetch('../handlers/ai_chat.php', { method: 'POST', body: fd });
            const data = await res.json();
            typing(false);
            if (!data.ok) { bubble('err', data.error || 'Something went wrong.'); return; }

            conversationId = data.conversation_id;
            bubble('bot', data.reply || '');
            if (data.draft) showDraft(data.draft, data.phase, data.draft_id);
        } catch (err) {
            typing(false);
            bubble('err', 'Could not reach the server. Check your connection and try again.');
        } finally {
            busy = false; sendBtn.disabled = false; input.focus();
        }
    }

    function showDraft(d, phase, id) {
        draftId = id || 0;
        el('phase').value = phase || '';
        el('calories').value = d.calories || 0;
        el('goal').value = d.goal || '';
        el('diet_type').value = ['veg', 'nonveg', 'vegan'].includes(d.diet_type) ? d.diet_type : 'veg';
        ['wake_up', 'breakfast', 'post_workout', 'lunch', 'snack', 'dinner', 'pre_sleep', 'guidelines']
            .forEach(k => { el(k).value = d[k] || ''; });
        document.getElementById('jofAiDraftCal').textContent = (d.calories || 0) + ' kcal';
        draftBox.classList.add('open');
        draftBox.scrollTop = 0;
    }

    document.getElementById('jofAiDiscard').addEventListener('click', () => {
        draftBox.classList.remove('open');
        draftId = 0;
        bubble('bot', 'Draft discarded. Tell me what to change and I can build another.');
    });

    document.getElementById('jofAiSave').addEventListener('click', async () => {
        const btn = document.getElementById('jofAiSave');
        if (btn.disabled) return;
        btn.disabled = true; btn.textContent = 'Saving…';

        const fd = new FormData();
        fd.append('_csrf_token', CSRF);
        fd.append('member_id', memberSel.value);
        fd.append('draft_id', draftId);
        fd.append('phase', el('phase').value);
        fd.append('goal', el('goal').value);
        fd.append('diet_type', el('diet_type').value);
        fd.append('calories', el('calories').value);
        fd.append('duration', 2);
        ['wake_up', 'breakfast', 'post_workout', 'lunch', 'snack', 'dinner', 'pre_sleep', 'guidelines']
            .forEach(k => fd.append(k, el(k).value));

        try {
            const res = await fetch('../handlers/ai_plan_save.php', { method: 'POST', body: fd });
            const data = await res.json();
            if (!data.ok) { bubble('err', data.error || 'Could not save.'); return; }
            draftBox.classList.remove('open');
            draftId = 0;
            const w = bubble('bot', 'Saved as **' + data.plan_name + '** and assigned to the member.');
            const a = document.createElement('a');
            a.href = data.view_url; a.target = '_blank';
            a.style.cssText = 'display:inline-block;margin-top:7px;font-size:12.5px;font-weight:700;color:#F25C2A;';
            a.textContent = 'Open the plan →';
            w.querySelector('.bubble').appendChild(a);
        } catch (err) {
            bubble('err', 'Could not reach the server while saving.');
        } finally {
            btn.disabled = false; btn.textContent = 'Save as phase';
        }
    });

    if (memberSel.value !== '0') {
        subtitle.textContent = 'Advising: ' + memberSel.options[memberSel.selectedIndex].text.trim();
    }
})();
</script>
<?php endif; ?>
