<?php
/**
 * templates/_pause_modal.php  —  shared "Pause Membership" modal + mini calendar
 * ─────────────────────────────────────────────────────────────────
 * Include this once, just before </body>, on any page that needs it:
 *
 *   <?php $pause_redirect = 'members.php'; include '_pause_modal.php'; ?>
 *
 * Then wire a button:  onclick="openPauseModal(<id>, '<name>', '<plan_end_YYYY-MM-DD>')"
 *
 * Optional before include:
 *   $pause_redirect      page to return to  (default: current page basename)
 *   $pause_redirect_id   int appended as ?id=  (person_info.php needs this)
 */
if (!isset($csrf)) {
    $csrf = function_exists('generate_csrf_token')
        ? generate_csrf_token()
        : ($_SESSION['_csrf_token'] ?? '');
}
$pause_redirect    = $pause_redirect    ?? basename($_SERVER['PHP_SELF']);
$pause_redirect_id = $pause_redirect_id ?? 0;
?>
<div id="pauseModalOverlay" class="pause-modal-overlay">
    <div class="pause-modal-card">
        <div class="pause-modal-head">
            <div>
                <h2 class="pause-modal-title">
                    <img src="../icons/pause-solid-full.svg" class="fa-solid fa-pause"> Pause Membership
                </h2>
                <p class="pause-modal-sub">Freeze <strong id="pauseMemberName">this member's</strong> plan while they're away — the days are added back after their plan ends.</p>
            </div>
            <button type="button" class="pause-x" onclick="closePauseModal()">&times;</button>
        </div>

        <div class="pause-cal">
            <div class="pause-cal-nav">
                <button type="button" id="pauseCalPrev" class="pause-cal-arrow">&lsaquo;</button>
                <span id="pauseCalLabel">Month YYYY</span>
                <button type="button" id="pauseCalNext" class="pause-cal-arrow">&rsaquo;</button>
            </div>
            <div class="pause-cal-grid pause-cal-dow">
                <span>Su</span><span>Mo</span><span>Tu</span><span>We</span><span>Th</span><span>Fr</span><span>Sa</span>
            </div>
            <div class="pause-cal-grid" id="pauseCalDays"></div>
            <p class="pause-cal-hint" id="pauseCalHint">Click a start day, then an end day.</p>
        </div>

        <div class="pause-summary">
            <div class="pause-summary-box">
                <span class="pause-summary-lbl">From</span>
                <span class="pause-summary-val" id="pauseFromLbl">—</span>
            </div>
            <div class="pause-summary-box">
                <span class="pause-summary-lbl">To</span>
                <span class="pause-summary-val" id="pauseToLbl">—</span>
            </div>
            <div class="pause-summary-box accent">
                <span class="pause-summary-lbl">Days added</span>
                <span class="pause-summary-val" id="pauseDaysLbl">0</span>
            </div>
        </div>

        <div id="pausePreview" class="pause-preview" hidden>
            New plan end date: <strong id="pausePreviewDate">—</strong>
        </div>

        <label class="pause-reason-lbl" for="pauseReason">Reason <span>(optional)</span></label>
        <input type="text" id="pauseReason" class="pause-reason" maxlength="255" placeholder="e.g. Vacation, medical rest, travel…">

        <div id="pauseError" class="pause-error" hidden></div>

        <div class="pause-actions">
            <button type="button" class="pause-btn pause-btn-ghost" onclick="closePauseModal()">Cancel</button>
            <button type="button" id="pauseConfirmBtn" class="pause-btn pause-btn-primary" disabled>Confirm Pause</button>
        </div>

        <form id="pauseForm" method="POST" action="../handlers/pause_membership.php" style="display:none;">
            <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="redirect" value="<?= htmlspecialchars($pause_redirect, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="redirect_id" value="<?= (int) $pause_redirect_id ?>">
            <input type="hidden" name="member_id" id="pauseMemberId" value="">
            <input type="hidden" name="pause_start" id="pauseStartInput" value="">
            <input type="hidden" name="pause_end" id="pauseEndInput" value="">
            <input type="hidden" name="reason" id="pauseReasonInput" value="">
        </form>
    </div>
</div>

<style>
    .pause-modal-overlay {
        position: fixed; inset: 0; background: rgba(17, 24, 39, .6);
        backdrop-filter: blur(5px); z-index: 100001;
        display: none; align-items: center; justify-content: center; padding: 20px;
        opacity: 0; transition: opacity .25s ease;
    }
    .pause-modal-overlay.active { display: flex; opacity: 1; }
    .pause-modal-card {
        background: #fff; border-radius: 22px; width: 100%; max-width: 390px;
        padding: 24px; box-shadow: 0 24px 60px rgba(0, 0, 0, .25);
        transform: translateY(18px); transition: transform .25s ease;
        max-height: 92vh; overflow-y: auto;
    }
    .pause-modal-overlay.active .pause-modal-card { transform: translateY(0); }
    .pause-modal-head { display: flex; justify-content: space-between; align-items: flex-start; gap: 10px; }
    .pause-modal-title {
        font-size: 18px; font-weight: 800; color: #1F2937; margin: 0;
        display: flex; align-items: center; gap: 8px;
    }
    .pause-modal-title img { width: 16px; height: 16px; color: #F25C2A; }
    .pause-modal-sub { font-size: 12.5px; color: #6B7280; margin: 6px 0 0; line-height: 1.5; }
    .pause-x {
        border: none; background: #F3F4F6; color: #6B7280; width: 30px; height: 30px;
        border-radius: 9px; font-size: 20px; line-height: 1; cursor: pointer; flex-shrink: 0;
    }
    .pause-x:hover { background: #E5E7EB; }

    .pause-cal { margin-top: 18px; border: 1px solid #EEF0F3; border-radius: 16px; padding: 14px; }
    .pause-cal-nav {
        display: flex; align-items: center; justify-content: space-between;
        font-weight: 700; color: #1F2937; font-size: 14px; margin-bottom: 10px;
    }
    .pause-cal-arrow {
        border: none; background: #F3F4F6; color: #374151; width: 30px; height: 30px;
        border-radius: 9px; cursor: pointer; font-size: 18px; line-height: 1;
    }
    .pause-cal-arrow:hover { background: #FFE9E1; color: #F25C2A; }
    .pause-cal-grid { display: grid; grid-template-columns: repeat(7, 1fr); gap: 3px; }
    .pause-cal-dow span {
        text-align: center; font-size: 11px; font-weight: 700; color: #9CA3AF;
        padding: 4px 0; text-transform: uppercase;
    }
    .pause-day {
        border: none; background: transparent; aspect-ratio: 1 / 1;
        border-radius: 9px; font-size: 13px; color: #374151; cursor: pointer;
        display: flex; align-items: center; justify-content: center;
        transition: background .12s ease, color .12s ease;
    }
    .pause-day:hover:not(.empty) { background: #FFE9E1; color: #F25C2A; }
    .pause-day.empty { cursor: default; }
    .pause-day.today { box-shadow: 0 0 0 1px #F25C2A inset; font-weight: 700; }
    .pause-day.in-range { background: #FFE9E1; color: #B7370F; border-radius: 0; }
    .pause-day.range-end, .pause-day.range-start {
        background: #F25C2A; color: #fff; font-weight: 700;
    }
    .pause-day.range-start { border-radius: 9px 0 0 9px; }
    .pause-day.range-end { border-radius: 0 9px 9px 0; }
    .pause-day.range-start.range-end { border-radius: 9px; }
    .pause-cal-hint { font-size: 11.5px; color: #9CA3AF; margin: 10px 0 0; text-align: center; }

    .pause-summary { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 8px; margin-top: 16px; }
    .pause-summary-box {
        border: 1px solid #EEF0F3; border-radius: 12px; padding: 8px 10px; text-align: center;
    }
    .pause-summary-box.accent { background: #FFF1EC; border-color: #FFD9CB; }
    .pause-summary-lbl {
        display: block; font-size: 10px; font-weight: 700; letter-spacing: .05em;
        text-transform: uppercase; color: #9CA3AF;
    }
    .pause-summary-val { display: block; font-size: 13px; font-weight: 700; color: #1F2937; margin-top: 3px; }
    .pause-summary-box.accent .pause-summary-val { color: #F25C2A; font-size: 16px; }

    .pause-preview {
        margin-top: 12px; font-size: 12.5px; color: #15803D; background: #F0FDF4;
        border: 1px solid #BBF7D0; border-radius: 10px; padding: 9px 12px; text-align: center;
    }

    .pause-reason-lbl { display: block; margin-top: 16px; font-size: 12.5px; font-weight: 600; color: #374151; }
    .pause-reason-lbl span { color: #9CA3AF; font-weight: 400; }
    .pause-reason {
        width: 100%; margin-top: 6px; padding: 10px 12px; font-size: 13px;
        border: 1px solid #E5E7EB; border-radius: 10px; outline: none;
    }
    .pause-reason:focus { border-color: #F25C2A; }

    .pause-error {
        margin-top: 12px; font-size: 12.5px; color: #B91C1C; background: #FEF2F2;
        border: 1px solid #FECACA; border-radius: 10px; padding: 9px 12px;
    }

    .pause-actions { display: flex; gap: 10px; margin-top: 18px; }
    .pause-btn {
        flex: 1; padding: 12px; border-radius: 12px; font-size: 14px; font-weight: 700;
        cursor: pointer; border: none; transition: all .2s;
    }
    .pause-btn-ghost { background: #F3F4F6; color: #4B5563; }
    .pause-btn-ghost:hover { background: #E5E7EB; }
    .pause-btn-primary { background: #F25C2A; color: #fff; box-shadow: 0 4px 14px rgba(242, 92, 42, .3); }
    .pause-btn-primary:hover:not(:disabled) { background: #e04e1e; transform: translateY(-1px); }
    .pause-btn-primary:disabled { opacity: .5; cursor: not-allowed; box-shadow: none; }
</style>

<script>
(function () {
    const overlay   = document.getElementById('pauseModalOverlay');
    const daysGrid  = document.getElementById('pauseCalDays');
    const label     = document.getElementById('pauseCalLabel');
    const hint      = document.getElementById('pauseCalHint');
    const fromLbl   = document.getElementById('pauseFromLbl');
    const toLbl     = document.getElementById('pauseToLbl');
    const daysLbl   = document.getElementById('pauseDaysLbl');
    const preview   = document.getElementById('pausePreview');
    const previewDt = document.getElementById('pausePreviewDate');
    const errBox    = document.getElementById('pauseError');
    const confirmBtn= document.getElementById('pauseConfirmBtn');
    const form      = document.getElementById('pauseForm');

    const MONTHS = ['January','February','March','April','May','June','July','August','September','October','November','December'];
    let viewYear, viewMonth;      // currently displayed month
    let selStart = null, selEnd = null;   // Date objects (midnight)
    let planEnd = null;           // Date object of current plan end

    const iso   = d => d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
    const pretty= d => d.toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' });
    const midnight = d => { const x = new Date(d); x.setHours(0,0,0,0); return x; };

    window.openPauseModal = function (memberId, memberName, planEndStr) {
        document.getElementById('pauseMemberId').value = memberId;
        document.getElementById('pauseMemberName').textContent = memberName || "this member's";
        document.getElementById('pauseReason').value = '';
        errBox.hidden = true;
        selStart = selEnd = null;
        planEnd = planEndStr ? midnight(new Date(planEndStr + 'T00:00:00')) : null;

        const now = new Date();
        viewYear = now.getFullYear();
        viewMonth = now.getMonth();
        render();
        syncSummary();
        overlay.classList.add('active');
    };
    window.closePauseModal = function () { overlay.classList.remove('active'); };

    overlay.addEventListener('click', e => { if (e.target === overlay) window.closePauseModal(); });
    document.getElementById('pauseCalPrev').addEventListener('click', () => { shift(-1); });
    document.getElementById('pauseCalNext').addEventListener('click', () => { shift(1); });

    function shift(dir) {
        viewMonth += dir;
        if (viewMonth < 0) { viewMonth = 11; viewYear--; }
        if (viewMonth > 11) { viewMonth = 0; viewYear++; }
        render();
    }

    function render() {
        label.textContent = MONTHS[viewMonth] + ' ' + viewYear;
        daysGrid.innerHTML = '';
        const first = new Date(viewYear, viewMonth, 1);
        const startDow = first.getDay();
        const daysInMonth = new Date(viewYear, viewMonth + 1, 0).getDate();
        const today = midnight(new Date());

        for (let i = 0; i < startDow; i++) {
            const b = document.createElement('div');
            b.className = 'pause-day empty';
            daysGrid.appendChild(b);
        }
        for (let d = 1; d <= daysInMonth; d++) {
            const cur = midnight(new Date(viewYear, viewMonth, d));
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'pause-day';
            btn.textContent = d;
            if (cur.getTime() === today.getTime()) btn.classList.add('today');
            if (selStart && selEnd) {
                if (cur > selStart && cur < selEnd) btn.classList.add('in-range');
                if (cur.getTime() === selStart.getTime()) btn.classList.add('range-start');
                if (cur.getTime() === selEnd.getTime()) btn.classList.add('range-end');
            } else if (selStart && cur.getTime() === selStart.getTime()) {
                btn.classList.add('range-start', 'range-end');
            }
            btn.addEventListener('click', () => pick(cur));
            daysGrid.appendChild(btn);
        }
    }

    function pick(d) {
        if (!selStart || (selStart && selEnd)) {
            selStart = d; selEnd = null;
            hint.textContent = 'Now pick the end day.';
        } else {
            if (d < selStart) { selEnd = selStart; selStart = d; }
            else { selEnd = d; }
            hint.textContent = 'Range selected — adjust by clicking a new start day.';
        }
        render();
        syncSummary();
    }

    function syncSummary() {
        const has = selStart && selEnd;
        const single = selStart && !selEnd;
        let days = 0;
        if (has) days = Math.round((selEnd - selStart) / 86400000) + 1;
        else if (single) days = 1;

        fromLbl.textContent = selStart ? pretty(selStart) : '—';
        toLbl.textContent   = selEnd ? pretty(selEnd) : (single ? pretty(selStart) : '—');
        daysLbl.textContent = days;

        if (days > 0 && planEnd) {
            const np = new Date(planEnd);
            np.setDate(np.getDate() + days);
            previewDt.textContent = pretty(np) + '  (was ' + pretty(planEnd) + ')';
            preview.hidden = false;
        } else {
            preview.hidden = true;
        }
        confirmBtn.disabled = !(selStart);
    }

    confirmBtn.addEventListener('click', () => {
        if (!selStart) return;
        const s = selStart;
        const e = selEnd || selStart;
        document.getElementById('pauseStartInput').value = iso(s);
        document.getElementById('pauseEndInput').value = iso(e);
        document.getElementById('pauseReasonInput').value = document.getElementById('pauseReason').value.trim();
        confirmBtn.disabled = true;
        confirmBtn.textContent = 'Saving…';
        form.submit();
    });
})();
</script>
