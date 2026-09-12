<?php
/**
 * _profile_wizard.php — the 4-step "complete your profile" form.
 * Included by user_dashboard.php (as a blurred modal) and by
 * user_profile.php (edit mode). Submits via fetch() to user_profile.php
 * which returns JSON {success, errors, step, redirect}.
 *
 * Expects in scope: $v, $errors, $DIETS, $GENDERS, $email, $avatar,
 *                   $has_pic, $has_payment_step, $first_bad_step,
 *                   $measure, $img_base, $done
 */
if (!function_exists('e')) {
    function e($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
}
$measure = $measure ?? null;
$img_base = $img_base ?? '../../uploads/progress_photos/';
?>
<style>
    #pfw {
        --card: #fff; --border: #E4E6EA; --ink: #1E2230; --soft: #6B7280; --faint: #9CA3AF;
        --coral: #FF6B47; --coral-dark: #E5502B; --coral-tint: #FFEDE7;
        --green: #1FA971; --green-tint: #E7F8F0; --red: #E5484D;
        font-family: 'Inter', system-ui, sans-serif; color: var(--ink);
    }
    #pfw * { box-sizing: border-box; }
    #pfw h1, #pfw h3 { font-family: 'Sora', 'Inter', sans-serif; margin: 0; }

    #pfw .wizard { background: var(--card); border-radius: 20px; overflow: hidden; width: 100%; max-width: 720px; }
    #pfw .wz-head { padding: 24px 28px 0; }
    #pfw .wz-head h1 { font-size: 20px; }
    #pfw .wz-head p { color: var(--soft); font-size: 13px; margin: 5px 0 0; }

    #pfw .stepper { display: flex; align-items: flex-start; padding: 20px 28px 4px; }
    #pfw .stepper .node { display: flex; flex-direction: column; align-items: center; gap: 6px; flex-shrink: 0; width: 78px; text-align: center; }
    #pfw .stepper .dot { width: 32px; height: 32px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: 800; font-size: 13px; background: #fff; border: 2px solid var(--border); color: var(--faint); transition: .2s; }
    #pfw .stepper .node.active .dot { background: var(--coral); border-color: var(--coral); color: #fff; }
    #pfw .stepper .node.done .dot { background: #fff; border-color: var(--coral); color: var(--coral); }
    #pfw .stepper .lbl { font-size: 10.5px; font-weight: 700; color: var(--faint); }
    #pfw .stepper .node.active .lbl, #pfw .stepper .node.done .lbl { color: var(--ink); }
    #pfw .stepper .bar { flex: 1; height: 2px; background: var(--border); margin: 16px 2px 0; }
    #pfw .stepper .bar.filled { background: var(--coral); }

    #pfw .wz-body { padding: 18px 28px 4px; max-height: 62vh; overflow-y: auto; }
    #pfw .step { display: none; }
    #pfw .step.on { display: block; animation: pfwFade .2s ease; }
    @keyframes pfwFade { from { opacity: 0; transform: translateY(6px); } to { opacity: 1; transform: none; } }
    #pfw .step h3 { font-size: 14.5px; display: flex; align-items: center; gap: 9px; margin-bottom: 15px; }
    #pfw .step h3 .ic { width: 28px; height: 28px; border-radius: 8px; background: var(--coral-tint); display: flex; align-items: center; justify-content: center; font-size: 14px; }

    #pfw .g2 { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
    #pfw .g3 { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 14px; }
    @media (max-width: 600px) { #pfw .g2, #pfw .g3 { grid-template-columns: 1fr; } }
    #pfw .fld { display: flex; flex-direction: column; gap: 5px; }
    #pfw .fld.full { grid-column: 1 / -1; }
    #pfw .fld label { font-size: 12px; font-weight: 600; }
    #pfw .fld label .opt { color: var(--faint); font-weight: 400; }
    #pfw .fld input, #pfw .fld select, #pfw .fld textarea { font: inherit; font-size: 13.5px; padding: 10px 12px; border: 1px solid var(--border); border-radius: 10px; background: #fff; outline: none; width: 100%; color: var(--ink); }
    #pfw .fld textarea { min-height: 64px; resize: vertical; }
    #pfw .fld input:focus, #pfw .fld select:focus, #pfw .fld textarea:focus { border-color: var(--coral); box-shadow: 0 0 0 3px var(--coral-tint); }
    #pfw .fld.bad input, #pfw .fld.bad select, #pfw .fld.bad textarea { border-color: var(--red); }
    #pfw .erx { font-size: 11px; color: var(--red); font-weight: 600; display: none; }
    #pfw .fld.bad .erx { display: block; }
    #pfw .tip { font-size: 11px; color: var(--faint); }
    #pfw .radio-row { display: flex; gap: 16px; padding-top: 3px; }
    #pfw .radio-row label { display: flex; align-items: center; gap: 6px; font-size: 13.5px; cursor: pointer; }
    #pfw .radio-row input { accent-color: var(--coral); width: 15px; height: 15px; }

    #pfw .pfp-upload { display: flex; align-items: center; gap: 15px; padding-bottom: 16px; margin-bottom: 15px; border-bottom: 1px solid var(--border); }
    #pfw .pfp-upload.bad .pfp-ring img { border-color: var(--red); }
    #pfw .pfp-ring { position: relative; width: 72px; height: 72px; flex-shrink: 0; cursor: pointer; display: block; }
    #pfw .pfp-ring img { width: 72px; height: 72px; border-radius: 50%; object-fit: cover; border: 3px solid var(--coral-tint); background: #f1f2f4; }
    #pfw .pfp-cam { position: absolute; right: -2px; bottom: -2px; width: 24px; height: 24px; border-radius: 50%; background: var(--coral); color: #fff; font-size: 11px; display: flex; align-items: center; justify-content: center; border: 2px solid #fff; }
    #pfw .pfp-ring input[type=file] { position: absolute; inset: 0; opacity: 0; cursor: pointer; }
    #pfw .pfp-t { font-size: 12.5px; font-weight: 700; }
    #pfw .pfp-s { font-size: 11px; color: var(--faint); margin-top: 2px; }
    #pfw .pfp-actions { display: flex; gap: 8px; margin-top: 7px; align-items: center; }
    #pfw .pfp-btn { font: inherit; font-size: 11.5px; font-weight: 700; padding: 5px 12px; border-radius: 8px; border: none; cursor: pointer; background: var(--coral-tint); color: var(--coral-dark); }
    #pfw .pfp-btn.ghost { background: #f1f2f4; color: var(--soft); display: inline-flex; align-items: center; }

    #pfw .slider { margin-bottom: 15px; }
    #pfw .slider .lab { display: flex; justify-content: space-between; font-size: 12.5px; font-weight: 600; margin-bottom: 7px; }
    #pfw .slider .val { font-family: 'Sora', sans-serif; font-weight: 800; color: var(--coral); }
    #pfw .slider input[type=range] { width: 100%; accent-color: var(--coral); height: 6px; }
    #pfw .slider .scale { display: flex; justify-content: space-between; font-size: 10px; color: var(--faint); margin-top: 3px; }

    #pfw #bmiChip { display: none; align-items: center; gap: 6px; font-size: 11.5px; font-weight: 800; padding: 5px 10px; border-radius: 999px; margin-bottom: 12px; }

    #pfw .photos { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 11px; }
    @media (max-width: 600px) { #pfw .photos { grid-template-columns: 1fr; } }
    #pfw .drop { border: 2px dashed var(--border); border-radius: 12px; padding: 13px 8px; text-align: center; cursor: pointer; position: relative; overflow: hidden; transition: .15s; }
    #pfw .drop:hover { border-color: var(--coral); background: var(--coral-tint); }
    #pfw .drop .cam { font-size: 18px; }
    #pfw .drop .t { font-weight: 700; font-size: 11.5px; margin-top: 4px; }
    #pfw .drop .s { font-size: 10px; color: var(--faint); }
    #pfw .drop input[type=file] { position: absolute; inset: 0; opacity: 0; cursor: pointer; }
    #pfw .drop .prev { width: 100%; height: 92px; object-fit: cover; border-radius: 7px; margin-top: 7px; display: none; }
    #pfw .drop.has .prev { display: block; }
    #pfw .drop.has .cam, #pfw .drop.has .s { display: none; }

    #pfw .pay-row { display: grid; grid-template-columns: 1.2fr 1fr; gap: 16px; align-items: start; }
    @media (max-width: 640px) { #pfw .pay-row { grid-template-columns: 1fr; } }
    #pfw .qr-box { background: #0F1420; border-radius: 14px; padding: 16px; text-align: center; color: #fff; }
    #pfw .qr-box h4 { font-size: 13px; margin: 0 0 9px; }
    #pfw .qr-box img { width: 100%; max-width: 180px; border-radius: 9px; background: #fff; padding: 5px; }
    #pfw .qr-box .upi { font-size: 11px; margin-top: 9px; color: #cbd5e1; }
    #pfw .qr-box .upi b { color: #fff; }
    #pfw .upi-badge { display: inline-flex; align-items: center; gap: 7px; background: var(--green-tint); color: var(--green); font-weight: 700; font-size: 12.5px; padding: 7px 13px; border-radius: 9px; margin-bottom: 12px; }

    #pfw .wz-foot { display: flex; justify-content: space-between; gap: 10px; padding: 16px 28px 22px; border-top: 1px solid var(--border); margin-top: 6px; }
    #pfw .wb { font: inherit; font-weight: 700; font-size: 13.5px; padding: 11px 22px; border-radius: 11px; border: none; cursor: pointer; text-decoration: none; display: inline-flex; align-items: center; }
    #pfw .wb.ghost { background: #EDEFF1; color: var(--soft); }
    #pfw .wb.primary { background: var(--coral); color: #fff; box-shadow: 0 8px 18px -8px rgba(255,107,71,.55); }
    #pfw .wb.primary:hover { background: var(--coral-dark); }
    #pfw .wb[disabled] { opacity: .55; cursor: not-allowed; }
    #pfw .wz-alert { background: var(--red); color: #fff; padding: 10px 14px; border-radius: 10px; font-size: 12.5px; font-weight: 600; margin: 0 28px 12px; }
</style>

<div id="pfw">
    <form class="wizard" id="pf" method="POST" action="user_profile.php" enctype="multipart/form-data" novalidate>
        <input type="hidden" name="ajax" value="1">
        <div class="wz-head">
            <h1><?= $done ? 'Edit Your Profile' : 'Complete Your Profile' ?></h1>
            <p><?= $done ? 'Update anything and save.' : 'A one-time setup so your trainer can build the right plan for you.' ?></p>
        </div>

        <div class="stepper" id="stepper">
            <div class="node" data-node="1"><div class="dot">1</div><div class="lbl">Health</div></div>
            <div class="bar"></div>
            <div class="node" data-node="2"><div class="dot">2</div><div class="lbl">Metrics</div></div>
            <div class="bar"></div>
            <div class="node" data-node="3"><div class="dot">3</div><div class="lbl">Measure</div></div>
            <?php if ($has_payment_step): ?>
            <div class="bar"></div>
            <div class="node" data-node="4"><div class="dot">4</div><div class="lbl">Payment</div></div>
            <?php endif; ?>
        </div>

        <?php if (isset($errors['_form'])): ?><div class="wz-alert"><?= e($errors['_form']) ?></div><?php endif; ?>

        <div class="wz-body">
            <!-- STEP 1 -->
            <section class="step" data-step="1">
                <h3><span class="ic">👤</span> Personal &amp; Health Info</h3>
                <div class="pfp-upload <?= isset($errors['profile_pic']) ? 'bad' : '' ?>">
                    <label class="pfp-ring">
                        <img id="pfpImg" src="<?= e($avatar) ?>" alt="Profile picture">
                        <span class="pfp-cam">📷</span>
                        <input type="file" name="profile_pic" id="pfpInput" accept="image/jpeg,image/png">
                    </label>
                    <div>
                        <div class="pfp-t">Profile Picture <span class="opt">(optional)</span></div>
                        <div class="pfp-s">JPG or PNG · max 2 MB</div>
                        <div class="pfp-actions">
                            <button type="button" class="pfp-btn" onclick="document.getElementById('pfpInput').click()"><?= $has_pic ? 'Change' : 'Upload' ?></button>
                            <?php if ($has_pic): ?><label class="pfp-btn ghost"><input type="checkbox" name="remove_profile_pic" id="pfpRemove" style="margin-right:5px;"> Remove</label><?php endif; ?>
                        </div>
                        <span class="erx" data-for="profile_pic" style="<?= isset($errors['profile_pic']) ? 'display:block' : '' ?>"><?= e($errors['profile_pic'] ?? '') ?></span>
                    </div>
                </div>
                <div class="g2">
                    <div class="fld full <?= isset($errors['full_name']) ? 'bad' : '' ?>">
                        <label>Full Name *</label>
                        <input name="full_name" value="<?= e($v['full_name']) ?>" maxlength="100" data-req>
                        <span class="erx" data-for="full_name"><?= e($errors['full_name'] ?? 'Enter your full name.') ?></span>
                    </div>
                    <div class="fld <?= isset($errors['phone_number']) ? 'bad' : '' ?>">
                        <label>Phone Number *</label>
                        <input name="phone_number" value="<?= e($v['phone_number']) ?>" maxlength="15" inputmode="tel" data-req>
                        <span class="erx" data-for="phone_number"><?= e($errors['phone_number'] ?? 'Enter a valid phone number.') ?></span>
                    </div>
                    <div class="fld">
                        <label>Email <span class="opt">(login)</span></label>
                        <input value="<?= e($email) ?>" disabled>
                    </div>
                    <div class="fld <?= isset($errors['gender']) ? 'bad' : '' ?>">
                        <label>Gender *</label>
                        <select name="gender" data-req>
                            <option value="">Select…</option>
                            <?php foreach ($GENDERS as $g): ?><option value="<?= $g ?>" <?= $v['gender'] === $g ? 'selected' : '' ?>><?= $g ?></option><?php endforeach; ?>
                        </select>
                        <span class="erx" data-for="gender"><?= e($errors['gender'] ?? 'Select your gender.') ?></span>
                    </div>
                    <div class="fld <?= isset($errors['diet_type']) ? 'bad' : '' ?>">
                        <label>Diet Preference *</label>
                        <select name="diet_type" data-req>
                            <option value="">Select…</option>
                            <?php foreach ($DIETS as $key => $lbl): ?><option value="<?= $key ?>" <?= $v['diet_type'] === $key ? 'selected' : '' ?>><?= $lbl ?></option><?php endforeach; ?>
                        </select>
                        <span class="erx" data-for="diet_type"><?= e($errors['diet_type'] ?? 'Select a diet preference.') ?></span>
                    </div>
                    <div class="fld <?= isset($errors['age']) ? 'bad' : '' ?>">
                        <label>Age *</label>
                        <input name="age" type="number" min="5" max="100" value="<?= e($v['age']) ?>" data-req>
                        <span class="erx" data-for="age"><?= e($errors['age'] ?? 'Age 5–100.') ?></span>
                    </div>
                    <div class="fld">
                        <label>Personal Training *</label>
                        <div class="radio-row">
                            <label><input type="radio" name="personal_training" value="1" <?= (int) $v['personal_training'] === 1 ? 'checked' : '' ?>> Yes</label>
                            <label><input type="radio" name="personal_training" value="0" <?= (int) $v['personal_training'] === 0 ? 'checked' : '' ?>> No</label>
                        </div>
                    </div>
                    <div class="fld full <?= isset($errors['medical_issues']) ? 'bad' : '' ?>">
                        <label>Medical Issues / Injuries <span class="opt">(optional)</span></label>
                        <textarea name="medical_issues" maxlength="1000" placeholder="Injuries, surgeries, conditions…"><?= e($v['medical_issues']) ?></textarea>
                        <span class="erx" data-for="medical_issues"><?= e($errors['medical_issues'] ?? '') ?></span>
                    </div>
                </div>
            </section>

            <!-- STEP 2 -->
            <section class="step" data-step="2">
                <h3><span class="ic">💭</span> How You Feel Today <span class="tip" style="font-weight:400;">drag the sliders (1–10)</span></h3>
                <?php foreach (['mood' => ['Mood', '😞', '😄'], 'sleep_quality' => ['Sleep Quality', '😴', '🌟'], 'hunger_craving' => ['Hunger &amp; Cravings', '🍽️', '✅'], 'energy_level' => ['Energy Levels', '🪫', '⚡']] as $mk => $mm): ?>
                    <div class="slider">
                        <div class="lab"><span><?= $mm[0] ?></span><span class="val" id="val_<?= $mk ?>"><?= (int) $v[$mk] ?></span></div>
                        <input type="range" name="<?= $mk ?>" min="1" max="10" value="<?= (int) $v[$mk] ?>" oninput="document.getElementById('val_<?= $mk ?>').textContent=this.value" data-req>
                        <div class="scale"><span><?= $mm[1] ?> Low</span><span>High <?= $mm[2] ?></span></div>
                    </div>
                <?php endforeach; ?>
            </section>

            <!-- STEP 3 -->
            <section class="step" data-step="3">
                <h3><span class="ic">📏</span> Body Measurements</h3>
                <span id="bmiChip"></span>
                <div class="g3">
                    <div class="fld <?= isset($errors['height']) ? 'bad' : '' ?>">
                        <label>Height (cm) *</label><input name="height" type="number" step="0.01" min="50" max="260" value="<?= e($v['height']) ?>" data-req>
                        <span class="erx" data-for="height"><?= e($errors['height'] ?? '50–260 cm.') ?></span>
                    </div>
                    <div class="fld <?= isset($errors['weight']) ? 'bad' : '' ?>">
                        <label>Weight (kg) *</label><input name="weight" type="number" step="0.01" min="20" max="300" value="<?= e($v['weight']) ?>" data-req>
                        <span class="erx" data-for="weight"><?= e($errors['weight'] ?? '20–300 kg.') ?></span>
                    </div>
                    <?php foreach (['chest' => 'Chest (in)', 'waist' => 'Waist (in)', 'hip' => 'Hip (in)', 'thigh' => 'Thighs (in)'] as $bk => $bl): ?>
                        <div class="fld <?= isset($errors[$bk]) ? 'bad' : '' ?>">
                            <label><?= $bl ?> *</label><input name="<?= $bk ?>" type="number" step="0.01" min="5" max="100" value="<?= e($v[$bk]) ?>" data-req>
                            <span class="erx" data-for="<?= $bk ?>"><?= e($errors[$bk] ?? '5–100.') ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
                <h3 style="margin-top:20px;"><span class="ic">📷</span> Progress Photos <span class="tip" style="font-weight:400;">(optional)</span></h3>
                <p class="tip" style="margin:0 0 9px;">Add now or later from your profile · Max 2 MB · JPG / PNG</p>
                <div class="photos">
                    <?php foreach (['front_view' => ['Front View', 'front_view_image'], 'side_view' => ['Side View', 'side_view_image'], 'back_view' => ['Back View', 'back_view_image']] as $pf => $pp): ?>
                        <label class="drop <?= (!empty($measure[$pp[1]])) ? 'has' : '' ?>" data-drop>
                            <div class="cam">📸</div>
                            <div class="t"><?= $pp[0] ?></div>
                            <div class="s">Tap to choose</div>
                            <img class="prev" src="<?= !empty($measure[$pp[1]]) ? e($img_base . $measure[$pp[1]]) : '' ?>" alt="">
                            <input type="file" name="<?= $pf ?>" accept="image/jpeg,image/png">
                        </label>
                    <?php endforeach; ?>
                </div>
            </section>

            <?php if ($has_payment_step): ?>
            <!-- STEP 4 -->
            <section class="step" data-step="4">
                <h3><span class="ic">💳</span> Payment</h3>
                <div class="upi-badge">🟢 Paying via UPI</div>
                <p class="tip" style="margin:0 0 13px;">Pay the joining amount and record the reference. Your trainer sets your exact plan &amp; pricing after this.</p>
                <div class="pay-row">
                    <div style="display:flex;flex-direction:column;gap:13px;">
                        <div class="fld <?= isset($errors['transaction_id']) ? 'bad' : '' ?>">
                            <label>UPI Reference / Transaction ID *</label>
                            <input name="transaction_id" value="<?= e($v['transaction_id']) ?>" maxlength="30" placeholder="Last 4–6 digits of the UPI ref" data-req>
                            <span class="erx" data-for="transaction_id"><?= e($errors['transaction_id'] ?? 'Enter your UPI reference.') ?></span>
                        </div>
                        <div class="fld">
                            <label>Paid By <span class="opt">(if different)</span></label>
                            <input name="payer_name" value="<?= e($v['payer_name']) ?>" maxlength="100" placeholder="e.g. Father / Friend name">
                        </div>
                        <div class="fld">
                            <label>Payment Screenshot <span class="opt">(optional)</span></label>
                            <input type="file" name="payment_screenshot" accept="image/jpeg,image/png,application/pdf">
                        </div>
                    </div>
                    <div class="qr-box">
                        <h4>Scan &amp; Pay</h4>
                        <img src="../../icons/images/qr_code.jpeg" alt="Payment QR">
                        <div class="upi">UPI ID: <b>7798487212@hdfc</b><br>JOSHUA SUNIL SADANANDAN</div>
                    </div>
                </div>
            </section>
            <?php endif; ?>
        </div>

        <div class="wz-foot">
            <button type="button" class="wb ghost" id="btnBack" style="visibility:hidden;">← Back</button>
            <div style="display:flex;gap:9px;">
                <?php if ($done): ?><a class="wb ghost" href="user_profile.php">Cancel</a><?php endif; ?>
                <button type="button" class="wb primary" id="btnNext">Continue →</button>
                <button type="submit" class="wb primary" id="btnFinish" style="display:none;"><?= $done ? 'Save Changes' : 'Finish Registration →' ?></button>
            </div>
        </div>
    </form>
</div>

<script>
    (function () {
        const root = document.getElementById('pfw');
        const form = document.getElementById('pf');
        if (!form) return;
        const steps = [...form.querySelectorAll('.step')];
        const nodes = [...root.querySelectorAll('#stepper .node')];
        const bars  = [...root.querySelectorAll('#stepper .bar')];
        const btnBack = document.getElementById('btnBack');
        const btnNext = document.getElementById('btnNext');
        const btnFin  = document.getElementById('btnFinish');
        const FIN_LABEL = <?= json_encode($done ? 'Save Changes' : 'Finish Registration →') ?>;
        const TOTAL = steps.length;
        let cur = <?= (int) $first_bad_step ?>;
        if (cur > TOTAL) cur = TOTAL;

        function render() {
            steps.forEach(s => s.classList.toggle('on', +s.dataset.step === cur));
            nodes.forEach(n => {
                const i = +n.dataset.node;
                n.classList.toggle('active', i === cur);
                n.classList.toggle('done', i < cur);
                n.querySelector('.dot').textContent = i < cur ? '✓' : i;
            });
            bars.forEach((b, i) => b.classList.toggle('filled', i < cur - 1));
            btnBack.style.visibility = cur === 1 ? 'hidden' : 'visible';
            btnNext.style.display = cur === TOTAL ? 'none' : 'inline-flex';
            btnFin.style.display  = cur === TOTAL ? 'inline-flex' : 'none';
            const body = root.querySelector('.wz-body');
            if (body) body.scrollTop = 0;
        }
        function fieldOk(el) {
            if (el.type === 'range') return true;
            if (!el.value || String(el.value).trim() === '') return false;
            if (el.type === 'number' && (+el.value < +el.min || +el.value > +el.max)) return false;
            return true;
        }
        function validateStep(n) {
            const step = steps.find(s => +s.dataset.step === n);
            let ok = true;
            step.querySelectorAll('[data-req]').forEach(el => {
                const fld = el.closest('.fld');
                const bad = !fieldOk(el);
                if (fld) fld.classList.toggle('bad', bad);
                if (bad) ok = false;
            });
            return ok;
        }

        btnNext.addEventListener('click', () => { if (validateStep(cur) && cur < TOTAL) { cur++; render(); } });
        btnBack.addEventListener('click', () => { if (cur > 1) { cur--; render(); } });
        nodes.forEach(n => n.addEventListener('click', () => { const t = +n.dataset.node; if (t < cur) { cur = t; render(); } }));

        form.addEventListener('submit', async ev => {
            ev.preventDefault();
            for (let s = 1; s <= TOTAL; s++) { if (!validateStep(s)) { cur = s; render(); return; } }
            btnFin.disabled = true; btnFin.textContent = 'Saving…';
            try {
                const res = await fetch(form.getAttribute('action'), { method: 'POST', body: new FormData(form), credentials: 'same-origin' });
                const data = await res.json();
                if (data.success) { window.location.href = data.redirect || 'user_dashboard.php'; return; }
                root.querySelectorAll('.fld.bad, .pfp-upload.bad').forEach(x => x.classList.remove('bad'));
                Object.entries(data.errors || {}).forEach(([k, msg]) => {
                    const el = form.querySelector('[name="' + k + '"]');
                    const box = form.querySelector('.erx[data-for="' + k + '"]');
                    const wrap = el ? (el.closest('.fld') || el.closest('.pfp-upload')) : (box ? box.closest('.pfp-upload') : null);
                    if (wrap) wrap.classList.add('bad');
                    if (box) { box.textContent = msg; box.style.display = 'block'; }
                });
                if (data.step) { cur = data.step; render(); }
                btnFin.disabled = false; btnFin.textContent = FIN_LABEL;
            } catch (e) {
                alert('Could not save right now. Please try again.');
                btnFin.disabled = false; btnFin.textContent = FIN_LABEL;
            }
        });

        // profile picture preview
        const pfpInput = document.getElementById('pfpInput');
        const pfpImg = document.getElementById('pfpImg');
        const pfpRemove = document.getElementById('pfpRemove');
        const pfpOrig = pfpImg ? pfpImg.src : '';
        if (pfpInput) pfpInput.addEventListener('change', () => {
            const f = pfpInput.files[0];
            if (!f) return;
            if (f.size > 2 * 1024 * 1024) { alert('That image is over 2 MB.'); pfpInput.value = ''; return; }
            const r = new FileReader();
            r.onload = e => { pfpImg.src = e.target.result; pfpImg.style.opacity = '1'; if (pfpRemove) pfpRemove.checked = false; };
            r.readAsDataURL(f);
        });
        if (pfpRemove) pfpRemove.addEventListener('change', () => {
            if (pfpRemove.checked) { pfpInput.value = ''; pfpImg.style.opacity = '.35'; }
            else { pfpImg.src = pfpOrig; pfpImg.style.opacity = '1'; }
        });

        // photo previews
        form.querySelectorAll('[data-drop] input[type=file]').forEach(inp => {
            inp.addEventListener('change', () => {
                const drop = inp.closest('.drop');
                const f = inp.files[0];
                if (!f) return;
                if (f.size > 2 * 1024 * 1024) { alert('That image is over 2 MB.'); inp.value = ''; return; }
                const r = new FileReader();
                r.onload = e => { drop.querySelector('.prev').src = e.target.result; drop.classList.add('has'); };
                r.readAsDataURL(f);
            });
        });

        // live BMI
        function updateBmi() {
            const h = +(form.querySelector('[name=height]') || {}).value;
            const w = +(form.querySelector('[name=weight]') || {}).value;
            const box = document.getElementById('bmiChip');
            if (!box) return;
            if (h > 0 && w > 0) {
                const b = w / Math.pow(h / 100, 2);
                let cat = 'Normal', col = '#1FA971';
                if (b < 18.5) { cat = 'Underweight'; col = '#F0A93A'; }
                else if (b < 25) { cat = 'Normal'; col = '#1FA971'; }
                else if (b < 30) { cat = 'Overweight'; col = '#F0A93A'; }
                else { cat = 'Obese'; col = '#E5484D'; }
                box.style.display = 'inline-flex'; box.style.color = col; box.style.background = col + '1a';
                box.textContent = 'BMI ' + b.toFixed(1) + ' · ' + cat;
            } else { box.style.display = 'none'; }
        }
        form.addEventListener('input', updateBmi);
        const phone = form.querySelector('[name=phone_number]');
        if (phone) phone.addEventListener('input', () => { phone.value = phone.value.replace(/[^\d+\-\s]/g, '').slice(0, 15); });

        render(); updateBmi();
    })();
</script>
