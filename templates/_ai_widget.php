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
    /* With a plan open there's a long form as well as the chat — give it more room */
    .jof-ai-card:has(.jof-ai-draft.open) { height: min(780px, calc(100vh - 40px)); }
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

    /* The chat and the draft panel share the card's height. The chat keeps a floor,
       so a long plan can never squeeze it to nothing or push the input box off the card. */
    .jof-ai-body { flex: 1 1 0; min-height: 90px; overflow-y: auto; padding: 16px; background: #F8FAFC; }
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
    .jof-ai-typing em { font-style: normal; font-size: 12px; color: #64748B; margin-left: 6px; align-self: center; }

    /* Blinking cursor at the end of a reply that's still being written */
    .jof-ai-caret { display: inline-block; width: 7px; height: 1.05em; margin-left: 2px; vertical-align: text-bottom; background: #F25C2A; border-radius: 1px; animation: jofAiBlink 1s steps(2, start) infinite; }
    @keyframes jofAiBlink { to { visibility: hidden; } }
    .jof-ai-stopped { margin-top: 6px; font-size: 11px; font-style: italic; color: #94A3B8; }

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
    .jof-ai-send.stop { background: #334155; }
    .jof-ai-send.stop:hover { background: #1E293B; }
    .jof-ai-send svg { width: 17px; height: 17px; }

    /* Draft review panel */
    .jof-ai-draft { border-top: 2px solid #F25C2A; background: #fff; flex: 1.8 1 0; min-height: 0; overflow-y: auto; display: none; }
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
        /* Pinned to both edges rather than width:100vw — 100vw includes the scrollbar,
           which made the card 15px wider than the visible area on narrow windows. */
        .jof-ai-card { left: 0; right: 0; bottom: 0; width: auto; max-width: none; height: 92vh; border-radius: 18px 18px 0 0; }
        .jof-ai-fab { right: 18px; bottom: 18px; }
        .jof-ai-row { grid-template-columns: 1fr; }
    }

    /* "Working on" plan picker + edit-mode bits.
       Once a member is chosen the two pickers sit side by side — stacked, they
       cost ~60px of height, which is what squeezed the chat out of the card. */
    .jof-ai-picker select { text-overflow: ellipsis; }
    .jof-ai-picker.has-plans .jof-ai-pickgrid { display: grid; grid-template-columns: 1fr 1fr; gap: 9px; }
    .jof-ai-picker.has-plans select { padding: 8px 9px; font-size: 12.5px; }
    .jof-ai-row3 { grid-template-columns: 1.25fr .9fr 1fr; }
    .jof-ai-f input[readonly] { background: #F8FAFC; color: #64748B; cursor: default; }
    .jof-ai-undo {
        margin-left: 10px; padding: 3px 11px; border-radius: 7px; cursor: pointer;
        border: 1.5px solid #CBD5E1; background: #fff; color: #475569;
        font-size: 12px; font-weight: 700; font-family: inherit;
    }
    .jof-ai-undo:hover { border-color: #F25C2A; color: #F25C2A; }
    .jof-ai-undo:disabled { opacity: .5; cursor: not-allowed; }

    @media (max-width: 560px) {
        .jof-ai-picker.has-plans .jof-ai-pickgrid { grid-template-columns: 1fr; }
        .jof-ai-row3 { grid-template-columns: 1fr 1fr; }
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
        <div class="jof-ai-picker" id="jofAiPicker">
            <div class="jof-ai-pickgrid">
                <div>
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
                <div id="jofAiPlanRow" style="display:none;">
                    <label for="jofAiPlan">Working on</label>
                    <select id="jofAiPlan"><option value="0">+ A new phase</option></select>
                </div>
            </div>
        </div>

        <div class="jof-ai-body" id="jofAiBody"></div>

        <div class="jof-ai-draft" id="jofAiDraft">
            <div class="jof-ai-draft-head">
                <b id="jofAiDraftTitle">Draft plan &mdash; review before saving</b>
                <span id="jofAiDraftCal"></span>
            </div>
            <div class="jof-ai-draft-body">
                <div class="jof-ai-f"><label>Goal</label><input type="text" id="jofAiGoal"></div>
                <div class="jof-ai-row jof-ai-row3">
                    <div class="jof-ai-f"><label>Phase</label><input type="text" id="jofAiPhase" maxlength="60"></div>
                    <div class="jof-ai-f"><label>Calories</label><input type="number" id="jofAiCalories"></div>
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

<?php if ($ai_problem !== ''): ?>
<script>
// No Groq key configured yet — the full chat script below never loads, so the
// button would otherwise look clickable but silently do nothing. Wire up just
// enough to open/close the card and show the "Setup needed" message it already
// contains, instead of a dead button.
(function () {
    const fab = document.getElementById('jofAiFab');
    const card = document.getElementById('jofAiCard');
    function openCard(open) {
        card.classList.toggle('open', open);
        fab.classList.toggle('hidden', open);
    }
    fab.addEventListener('click', () => openCard(true));
    document.getElementById('jofAiClose').addEventListener('click', () => openCard(false));
    document.addEventListener('keydown', e => { if (e.key === 'Escape' && card.classList.contains('open')) openCard(false); });
})();
</script>
<?php endif; ?>

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

    const picker = document.getElementById('jofAiPicker');
    const planRow = document.getElementById('jofAiPlanRow');
    const planSel = document.getElementById('jofAiPlan');
    const draftTitle = document.getElementById('jofAiDraftTitle');
    const saveBtn = document.getElementById('jofAiSave');
    const SECTIONS = ['wake_up', 'breakfast', 'post_workout', 'lunch', 'snack', 'dinner', 'pre_sleep', 'guidelines'];

    let conversationId = 0, draftId = 0, busy = false;
    let streamCtl = null;   // AbortController of the reply being streamed, for Stop
    // Set while a saved plan is open from "Working on". Meal times aren't
    // editable in the panel but must survive the round trip, so they ride here.
    let editPlanId = 0, editPlanName = '', draftTimes = {};

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
    function typing(on, label) {
        const old = document.getElementById('jofAiTyping');
        if (old) old.remove();
        if (!on) return;
        const t = document.createElement('div');
        t.id = 'jofAiTyping';
        t.className = 'jof-ai-msg bot';
        t.innerHTML = '<div class="jof-ai-typing"><span></span><span></span><span></span>'
            + (label ? '<em>' + label + '</em>' : '') + '</div>';
        body.appendChild(t);
        body.scrollTop = body.scrollHeight;
    }

    function greet() {
        const name = memberSel.value !== '0' ? memberSel.options[memberSel.selectedIndex].text.trim() : '';
        body.innerHTML = '';
        if (name && editPlanId) {
            bubble('bot', '**' + editPlanName + "** is open below. Edit the fields directly, or tell me what to change — I'll only touch what you ask about.");
            chips(['Increase calories by 200', 'Make dinner lighter', 'Swap in more vegetarian protein']);
        } else if (name) {
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

    function setSubtitle() {
        if (memberSel.value === '0') {
            subtitle.textContent = 'General nutrition advice';
        } else if (editPlanId) {
            subtitle.textContent = 'Editing: ' + editPlanName;
        } else {
            subtitle.textContent = 'Advising: ' + memberSel.options[memberSel.selectedIndex].text.trim();
        }
    }

    function clearEdit() {
        editPlanId = 0; editPlanName = ''; draftTimes = {}; draftId = 0;
        draftBox.classList.remove('open');
    }

    // Fills "Working on" with the member's saved plans, newest first. The date is
    // shown because some members have two plans with the same phase name.
    async function loadPlans(keepId) {
        planSel.innerHTML = '<option value="0">+ A new phase</option>';
        const hasMember = memberSel.value !== '0';
        picker.classList.toggle('has-plans', hasMember);
        planRow.style.display = hasMember ? '' : 'none';
        if (!hasMember) return;
        try {
            const res = await fetch('../handlers/ai_plans.php?action=list&member_id=' + encodeURIComponent(memberSel.value));
            const data = await res.json();
            if (!data.ok) return;
            data.plans.slice().reverse().forEach(p => {
                const o = document.createElement('option');
                o.value = p.id;
                o.textContent = p.phase + ' · ' + (p.calories ? p.calories + ' kcal · ' : '') + p.created;
                planSel.appendChild(o);
            });
            // After a rename the list is rebuilt — keep the open plan selected
            if (keepId) planSel.value = String(keepId);
        } catch (err) {
            // The list is a convenience — chat keeps working without it
        }
    }

    memberSel.addEventListener('change', () => {
        // Switching member starts a clean thread so one member's data never
        // carries over into advice about another.
        if (streamCtl) streamCtl.abort();
        conversationId = 0;
        clearEdit();
        setSubtitle();
        greet();
        loadPlans();
    });

    planSel.addEventListener('change', async () => {
        const id = parseInt(planSel.value, 10) || 0;
        conversationId = 0;   // a fresh thread per plan, so edits to two plans never mix
        clearEdit();
        if (!id) { setSubtitle(); greet(); return; }
        try {
            const res = await fetch('../handlers/ai_plans.php?action=load&member_id='
                + encodeURIComponent(memberSel.value) + '&plan_id=' + id);
            const data = await res.json();
            if (!data.ok) { bubble('err', data.error || 'Could not open that plan.'); planSel.value = '0'; return; }
            editPlanId = data.plan_id;
            editPlanName = data.plan_name;
            setSubtitle();
            greet();
            showDraft(data.draft, data.phase, 0);
        } catch (err) {
            bubble('err', 'Could not load that plan.');
            planSel.value = '0';
        }
    });

    input.addEventListener('input', () => {
        input.style.height = 'auto';
        input.style.height = Math.min(input.scrollHeight, 96) + 'px';
    });
    input.addEventListener('keydown', e => {
        if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); send(); }
    });
    // While a reply is streaming the send button becomes a Stop button
    const SEND_ICON = sendBtn.innerHTML;
    const STOP_ICON = '<svg viewBox="0 0 24 24"><rect x="6" y="6" width="12" height="12" rx="2" fill="currentColor"/></svg>';
    function stopMode(on) {
        sendBtn.classList.toggle('stop', on);
        sendBtn.innerHTML = on ? STOP_ICON : SEND_ICON;
        sendBtn.setAttribute('aria-label', on ? 'Stop' : 'Send');
    }
    sendBtn.addEventListener('click', () => {
        if (streamCtl) { streamCtl.abort(); return; }
        send();
    });

    // "plan" mode is requested when the admin clearly asks for a plan
    function wantsPlan(text) {
        return /\b(create|generate|make|build|draft|new)\b.{0,24}\b(plan|phase|diet)\b/i.test(text)
            || /\bnext phase\b/i.test(text);
    }

    // With a plan or draft open, any request to change it is an edit. Without
    // this, "make it vegan" (no word "plan") went to chat and nothing changed.
    function wantsEdit(text) {
        return /\b(change|replace|swap|switch|substitute|reduce|increase|decrease|lower|raise|cut|bump|remove|drop|add|make|update|edit|adjust|more|less|fewer|lighter|heavier|instead|without|rename|renumber|call it|name it|phase)\b/i.test(text);
    }

    // The panel exactly as it stands — including any hand edits — so the AI
    // revises what the admin is looking at, not an older stored copy.
    function panelDraft() {
        const d = {
            phase: el('phase').value,
            goal: el('goal').value,
            diet_type: el('diet_type').value,
            calories: parseInt(el('calories').value, 10) || 0,
            times: draftTimes
        };
        SECTIONS.forEach(k => { d[k] = el(k).value; });
        return d;
    }

    async function send(forceMode) {
        const text = input.value.trim();
        if (!text || busy) return;
        const memberId = memberSel.value;
        const draftOpen = draftBox.classList.contains('open');
        const editing = editPlanId || draftOpen;
        const mode = forceMode || ((memberId !== '0' && (editing ? (wantsEdit(text) || wantsPlan(text)) : wantsPlan(text)))
            ? 'plan' : 'chat');

        bubble('user', text);
        input.value = ''; input.style.height = 'auto';
        busy = true;
        if (mode === 'chat') {
            streamCtl = new AbortController();
            stopMode(true);
            typing(true);
        } else {
            // Plans arrive whole (see ai_chat.php), so say what's happening meanwhile
            sendBtn.disabled = true;
            typing(true, editing ? 'Updating the plan&hellip;' : 'Building the plan&hellip;');
        }

        const fd = new FormData();
        fd.append('_csrf_token', CSRF);
        fd.append('message', text);
        fd.append('mode', mode);
        fd.append('member_id', memberId);
        fd.append('conversation_id', conversationId);
        if (editPlanId) fd.append('plan_id', editPlanId);
        if (draftId && mode === 'plan') fd.append('draft_id', draftId);
        if (draftOpen && mode === 'plan') fd.append('current_draft', JSON.stringify(panelDraft()));
        if (mode === 'chat') fd.append('stream', '1');

        const ctl = streamCtl;
        try {
            const res = await fetch('../handlers/ai_chat.php', { method: 'POST', body: fd, signal: ctl ? ctl.signal : undefined });
            // A streamed reply; anything refused before the AI call is plain JSON
            if ((res.headers.get('Content-Type') || '').includes('ndjson') && res.body) {
                await readStream(res, ctl);
                return;
            }
            const data = await res.json();
            typing(false);
            if (!data.ok) { bubble('err', data.error || 'Something went wrong.'); return; }

            conversationId = data.conversation_id;
            bubble('bot', data.reply || '');
            if (data.draft) showDraft(data.draft, data.phase, data.draft_id);
        } catch (err) {
            typing(false);
            if (err.name !== 'AbortError') {
                bubble('err', 'Could not reach the server. Check your connection and try again.');
            }
        } finally {
            if (streamCtl === ctl) { streamCtl = null; stopMode(false); }
            busy = false; sendBtn.disabled = false; input.focus();
        }
    }

    // Mid-stream the text can end inside **bold**; closing it for the preview
    // stops the rest of the line flashing as literal asterisks.
    function closeOpenBold(t) {
        t = t.replace(/(^|[^*])\*$/, '$1');
        return (t.match(/\*\*/g) || []).length % 2 ? t + '**' : t;
    }

    // Reads the reply as the server sends it, one event per line:
    // start, delta (a few words), done, or error.
    //
    // Groq writes far faster than anyone reads, so a whole answer can land in a
    // fraction of a second. Received text is queued and revealed at a steady
    // pace instead, speeding up when a lot is waiting so long replies never lag.
    async function readStream(res, ctl) {
        const reader = res.body.getReader();
        const decoder = new TextDecoder();
        let buf = '', received = '', shown = 0, box = null, timer = 0;
        let netDone = false, finished = false, stopped = false, note = '';

        // Stop (or a hidden tab) skips the animation and shows everything received
        let skip = false;
        ctl.signal.addEventListener('abort', () => { skip = true; });

        let revealEnd;
        const revealed = new Promise(r => { revealEnd = r; });

        const tick = () => {
            timer = 0;
            if (skip || document.hidden) shown = received.length;
            const backlog = received.length - shown;
            if (backlog > 0) {
                shown = Math.min(received.length, shown + Math.max(2, Math.min(12, Math.ceil(backlog / 30))));
                // Follow the text down only if the admin hasn't scrolled up to read
                const atBottom = body.scrollHeight - body.scrollTop - body.clientHeight < 60;
                box.innerHTML = fmt(closeOpenBold(received.slice(0, shown))) + '<span class="jof-ai-caret"></span>';
                if (atBottom) body.scrollTop = body.scrollHeight;
            }
            if (netDone && shown >= received.length) { revealEnd(); return; }
            timer = setTimeout(tick, 16);
        };

        const handle = ev => {
            if (ev.t === 'start') {
                conversationId = ev.conversation_id;
            } else if (ev.t === 'delta') {
                if (!box) {
                    typing(false);
                    box = bubble('bot', '').querySelector('.bubble');
                    box.innerHTML = '<span class="jof-ai-caret"></span>';
                    tick();
                }
                received += ev.text;
            } else if (ev.t === 'done') {
                finished = true;
                conversationId = ev.conversation_id;
                if (ev.error) note = ev.error;
            } else if (ev.t === 'error') {
                finished = true;
                typing(false);
                bubble('err', ev.error || 'Something went wrong.');
            }
        };

        try {
            for (;;) {
                const { value, done } = await reader.read();
                if (done) break;
                buf += decoder.decode(value, { stream: true });
                let nl;
                while ((nl = buf.indexOf('\n')) >= 0) {
                    const line = buf.slice(0, nl).trim();
                    buf = buf.slice(nl + 1);
                    if (!line) continue;
                    let ev = null;
                    try { ev = JSON.parse(line); } catch (e) { continue; }
                    handle(ev);
                }
            }
        } catch (err) {
            if (err.name !== 'AbortError') { skip = true; netDone = true; throw err; }
            stopped = true;
        }

        netDone = true;
        typing(false);
        if (box) {
            await revealed;
            // Final render, without the cursor
            box.innerHTML = fmt(received);
            const tail = stopped ? 'Stopped' : note;
            if (tail) {
                const n = document.createElement('div');
                n.className = 'jof-ai-stopped';
                n.textContent = tail;
                box.appendChild(n);
            }
        } else if (!finished && !stopped) {
            bubble('err', 'The connection dropped before a reply arrived. Try again.');
        }
    }

    function showDraft(d, phase, id) {
        draftId = id || 0;
        if (d.times && typeof d.times === 'object' && !Array.isArray(d.times)) draftTimes = d.times;
        el('phase').value = d.phase || phase || '';
        el('calories').value = d.calories || 0;
        el('goal').value = d.goal || '';
        el('diet_type').value = ['veg', 'nonveg', 'vegan'].includes(d.diet_type) ? d.diet_type : 'veg';
        SECTIONS.forEach(k => { el(k).value = d[k] || ''; });
        document.getElementById('jofAiDraftCal').textContent = d.calories ? d.calories + ' kcal' : 'no calorie target';
        draftTitle.textContent = editPlanId
            ? 'Editing ' + (phase || editPlanName) + ' — review before updating'
            : 'Draft plan — review before saving';
        saveBtn.textContent = editPlanId ? 'Update ' + (el('phase').value || 'plan') : 'Save as new phase';
        draftBox.classList.add('open');
        draftBox.scrollTop = 0;
        body.scrollTop = body.scrollHeight;   // keep the latest reply in view above the panel
    }

    // Typing a new phase name renames the plan on update — say so on the button
    el('phase').addEventListener('input', () => {
        if (!editPlanId) return;
        const v = el('phase').value.trim();
        const current = editPlanName.split(' - ').slice(1).join(' - ');
        saveBtn.textContent = v && v !== current ? 'Update & rename to ' + v : 'Update ' + (v || 'plan');
    });

    function addLink(wrap, href, label) {
        const a = document.createElement('a');
        a.href = href; a.target = '_blank';
        a.style.cssText = 'display:inline-block;margin-top:7px;font-size:12.5px;font-weight:700;color:#F25C2A;';
        a.textContent = label;
        wrap.querySelector('.bubble').appendChild(a);
    }

    // Updates overwrite a plan the member may already be following, so every
    // update comes with a one-click way back.
    function addUndo(wrap, undoId, planName) {
        const b = document.createElement('button');
        b.type = 'button';
        b.className = 'jof-ai-undo';
        b.textContent = 'Undo';
        b.addEventListener('click', async () => {
            b.disabled = true;
            const fd = new FormData();
            fd.append('_csrf_token', CSRF);
            fd.append('action', 'undo');
            fd.append('draft_id', undoId);
            try {
                const res = await fetch('../handlers/ai_plan_save.php', { method: 'POST', body: fd });
                const data = await res.json();
                if (!data.ok) { bubble('err', data.error || 'Could not undo.'); b.disabled = false; return; }
                b.remove();
                bubble('bot', 'Restored **' + (data.plan_name || planName) + '** to how it was before that update.');
                // Undo also reverts a rename — bring the labels back in line
                if (editPlanId && data.plan_id === editPlanId && data.plan_name) {
                    editPlanName = data.plan_name;
                    setSubtitle();
                }
                loadPlans(editPlanId || undefined);
                draftBox.classList.remove('open');
                draftId = 0;
            } catch (err) {
                bubble('err', 'Could not reach the server.');
                b.disabled = false;
            }
        });
        wrap.querySelector('.bubble').appendChild(b);
    }

    document.getElementById('jofAiDiscard').addEventListener('click', () => {
        draftBox.classList.remove('open');
        draftId = 0;
        if (editPlanId) {
            bubble('bot', 'Changes discarded — **' + editPlanName + '** is exactly as it was.');
            planSel.value = '0';
            clearEdit();
            setSubtitle();
        } else {
            bubble('bot', 'Draft discarded. Tell me what to change and I can build another.');
        }
    });

    saveBtn.addEventListener('click', async () => {
        if (saveBtn.disabled) return;
        const label = saveBtn.textContent;
        saveBtn.disabled = true; saveBtn.textContent = 'Saving…';

        const fd = new FormData();
        fd.append('_csrf_token', CSRF);
        fd.append('member_id', memberSel.value);
        fd.append('draft_id', draftId);
        if (editPlanId) fd.append('plan_id', editPlanId);
        fd.append('phase', el('phase').value);
        fd.append('goal', el('goal').value);
        fd.append('diet_type', el('diet_type').value);
        fd.append('calories', el('calories').value);
        fd.append('duration', 2);
        fd.append('times', JSON.stringify(draftTimes));
        SECTIONS.forEach(k => fd.append(k, el(k).value));

        try {
            const res = await fetch('../handlers/ai_plan_save.php', { method: 'POST', body: fd });
            const data = await res.json();
            if (!data.ok) { bubble('err', data.error || 'Could not save.'); return; }
            draftBox.classList.remove('open');
            draftId = 0;

            if (data.updated) {
                // The plan stays open, so further requests edit the saved version
                const w = bubble('bot', data.renamed
                    ? 'Updated and renamed to **' + data.plan_name + '**. The member sees the new version straight away.'
                    : 'Updated **' + data.plan_name + '**. The member sees the new version straight away.');
                if (data.renamed) {
                    editPlanName = data.plan_name;
                    setSubtitle();
                    loadPlans(editPlanId);   // relabel the dropdown, keep this plan selected
                }
                addLink(w, data.view_url, 'Open the plan →');
                addUndo(w, data.undo_draft_id, data.plan_name);
            } else {
                const w = bubble('bot', 'Saved as **' + data.plan_name + '** and assigned to the member.');
                addLink(w, data.view_url, 'Open the plan →');
                loadPlans();   // so the new phase can be opened and edited straight away
            }
        } catch (err) {
            bubble('err', 'Could not reach the server while saving.');
        } finally {
            saveBtn.disabled = false; saveBtn.textContent = label;
        }
    });

    setSubtitle();
    if (memberSel.value !== '0') loadPlans();
})();
</script>
<?php endif; ?>
