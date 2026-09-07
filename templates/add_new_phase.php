<?php
require_once __DIR__ . '/../auth/auth_check.php';
require_role(['admin', 'trainer']);
require_once __DIR__ . '/../config.php';

$error = "";
$success = "";
$new_id = 0;

// 1. Fetch the existing plan to identify the client
if (!isset($_GET['id'])) {
    header("Location: diet-plans.php");
    exit;
}
$plan_id = intval($_GET['id']);

$sql = "SELECT * FROM diet_plans WHERE id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $plan_id);
$stmt->execute();
$plan = $stmt->get_result()->fetch_assoc();

if (!$plan)
    die("Plan not found.");

// 2. Extract client name from the plan
$name_parts = explode(" - ", $plan['plan_name']);
$client_name = trim($name_parts[0]);

// 3. Find all existing phases for this client
$existing_phases = [];

$history_sql = "SELECT plan_name FROM diet_plans WHERE plan_name LIKE ?";
$search_name = $client_name . " - %";
$stmt_hist = $conn->prepare($history_sql);
$stmt_hist->bind_param("s", $search_name);
$stmt_hist->execute();
$result_hist = $stmt_hist->get_result();

while ($row = $result_hist->fetch_assoc()) {
    $parts = explode(" - ", $row['plan_name']);
    if (isset($parts[1])) {
        $existing_phases[] = trim($parts[1]);
    }
}

// 4. Determine the next phase — always the next consecutive 2-week block.
//    Keeps going indefinitely (Week 9 & 10, Week 11 & 12, ...) with no "Maintenance" cap.
$highest_week = 0;
foreach ($existing_phases as $phase) {
    if (preg_match('/Week\s*(\d+)\s*&\s*(\d+)/i', $phase, $m)) {
        $highest_week = max($highest_week, (int) $m[1], (int) $m[2]);
    }
}
$start_week = $highest_week + 1;
$next_phase = "Week " . $start_week . " & " . ($start_week + 1);

// Fetch reusable diet templates (guard: table may not exist yet on this server)
$dp_templates = [];
$dp_tpl_check = $conn->query("SHOW TABLES LIKE 'diet_plan_templates'");
if ($dp_tpl_check && $dp_tpl_check->num_rows > 0) {
    $dp_tpl_res = $conn->query("SELECT id, template_name, goal, diet_type, calories, duration, breakfast, lunch, snack, dinner FROM diet_plan_templates ORDER BY template_name ASC");
    if ($dp_tpl_res) {
        while ($row = $dp_tpl_res->fetch_assoc()) {
            $dp_templates[] = $row;
        }
    }
}

// 5. Handle Submission — INSERT only
if ($_SERVER["REQUEST_METHOD"] == "POST") {

    $selected_phase = $_POST['week_phase'];
    $new_plan_name = $client_name . " - " . $selected_phase;

    $diet_type = $_POST['diet_type'];
    $goal = $_POST['goal'];
    $duration = $_POST['duration'];
    $calories = $_POST['calories'];
    $trainer_name = $_POST['trainer_name'];

    // --- COMBINE LOGIC (Same as Create Page) ---
    // Breakfast Column
    $in_wakeup = trim($_POST['wake_up']);
    $in_post = trim($_POST['post_workout']);
    $in_break = trim($_POST['breakfast']);

    $final_breakfast = "";
    if ($in_wakeup)
        $final_breakfast .= "🌅 **WAKE UP:**\n" . $in_wakeup . "\n\n";
    if ($in_post)
        $final_breakfast .= "💪 **POST WORKOUT:**\n" . $in_post . "\n\n";
    if ($in_break)
        $final_breakfast .= "🍳 **BREAKFAST:**\n" . $in_break;

    // Lunch Column
    $final_lunch = trim($_POST['lunch']);
    if ($final_lunch)
        $final_lunch = "🍛 **LUNCH:**\n" . $final_lunch;

    // Snack Column
    $final_snack = trim($_POST['snack']);
    if ($final_snack)
        $final_snack = "🍎 **MID MEAL:**\n" . $final_snack;

    // Dinner Column
    $in_dinner = trim($_POST['dinner']);
    $in_pre = trim($_POST['pre_sleep']);
    $in_guide = trim($_POST['guidelines']);

    $final_dinner = "";
    if ($in_dinner)
        $final_dinner .= "🍲 **DINNER:**\n" . $in_dinner . "\n\n";
    if ($in_pre)
        $final_dinner .= "🌙 **PRE-SLEEP:**\n" . $in_pre . "\n\n";
    if ($in_guide)
        $final_dinner .= "📝 **GUIDELINES:**\n" . $in_guide;

    try {
        $sql = "INSERT INTO diet_plans (plan_name, diet_type, goal, duration, calories, trainer_name, breakfast, lunch, snack, dinner) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("sssiisssss", $new_plan_name, $diet_type, $goal, $duration, $calories, $trainer_name, $final_breakfast, $final_lunch, $final_snack, $final_dinner);
        if ($stmt->execute()) {
            $new_id = $conn->insert_id;
            $success = "success";
        } else {
            $error = "Could not save phase: " . $stmt->error;
        }
    } catch (Exception $e) {
        $error = "Error: " . $e->getMessage();
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" size="16x16" href="../icons/favicon-dark-logo.png" type="image/png">
    <title>Add New Phase | JOF Fitness</title>
    <link rel="stylesheet" href="../static/root.css">
</head>

<body class="page-create_diet_plan">

    <div class="container">
        <div class="card-header">
            <div class="brand-area">
                <img src="../icons/logo-dark(1).png" class="brand-logo" alt="JOF">
                <div class="brand-text">
                    <h2>Add New Phase</h2>
                    <p>Create a new phase for <b><?= htmlspecialchars($client_name) ?></b>.</p>
                </div>
            </div>
            <a href="diet_plan_details.php?id=<?= $plan_id ?>" class="close-btn"><img
                    src="../icons/xmark-solid-full.svg" alt="close"></a>
        </div>

        <?php if ($error): ?>
            <div
                style="background:#fee2e2; color:#b91c1c; padding:12px; margin:0 24px 10px; border-radius:8px; text-align:center;">
                <i class="fa-solid fa-circle-exclamation"></i> <?= $error ?>
            </div>
        <?php endif; ?>

        <div class="card-body">

            <?php if (!empty($dp_templates)): ?>
            <div style="margin:0 0 18px; padding:12px 14px; border:1px dashed #10B981; border-radius:12px; background:#ECFDF5; display:flex; flex-wrap:wrap; gap:10px; align-items:center;">
                <label style="font-weight:700; font-size:13px; color:#065F46; margin:0;">
                    <img src="../icons/clipboard-list-solid-full.svg" width="14" style="vertical-align:-2px; margin-right:6px;">Use a template
                </label>
                <select id="tplPicker" style="flex:1; min-width:200px; padding:8px 10px; border:1px solid #A7F3D0; border-radius:8px; font-size:13px; background:#fff;">
                    <option value="">&mdash; Select a template to auto-fill &mdash;</option>
                    <?php foreach ($dp_templates as $t): ?>
                        <option value="<?= (int) $t['id'] ?>"><?= htmlspecialchars($t['template_name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="button" class="btn btn-primary" style="padding:8px 16px;" onclick="applyTemplate()">Fill form</button>
                <span id="tplMsg" style="font-size:12px; color:#059669;"></span>
            </div>
            <script>window.__DP_TEMPLATES = <?= json_encode($dp_templates, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;</script>
            <?php endif; ?>

            <form method="POST" action="">

                <div class="section-title"><img src="../icons/clipboard-user-solid-full.svg"><span>Phase
                        Management</span></div>

                <div class="form-grid">
                    <div class="input-group full-width phase-container">
                        <div>
                            <label>Client Name *</label>
                            <div class="input-wrapper">
                                <input type="text" class="form-input" value="<?= htmlspecialchars($client_name) ?>"
                                    readonly>
                                <img src="../icons/user-solid-full.svg" class="input-icon" alt="user">
                            </div>
                        </div>
                        <div>
                            <label>Week Phase *</label>
                            <div class="input-wrapper">
                                <select name="week_phase" id="phaseSelect" class="form-input">
                                    <option value="<?= $next_phase ?>" selected><?= $next_phase ?></option>
                                </select>
                                <img src="../icons/calendar-days-solid-full.svg" class="input-icon" alt="phase">
                            </div>
                        </div>
                    </div>

                    <div class="input-group">
                        <label>Goal *</label>
                        <div class="input-wrapper">
                            <input type="text" name="goal" class="form-input" value="" placeholder="e.g. Weight Loss"
                                required>
                            <img src="../icons/bullseye-solid-full.svg" class="input-icon">
                        </div>
                    </div>
                    <div class="input-group">
                        <label>Diet Type *</label>
                        <div class="input-wrapper">
                            <select name="diet_type" class="form-input" required>
                                <option value="veg">Vegetarian</option>
                                <option value="nonveg">Non-Veg</option>
                                <option value="vegan">Vegan</option>
                            </select>
                            <img src="../icons/leaf-solid-full.svg" class="input-icon">
                        </div>
                    </div>
                    <div class="input-group">
                        <label>Calories *</label>
                        <div class="input-wrapper">
                            <input type="number" name="calories" class="form-input" value="" placeholder="e.g. 2000"
                                required>
                            <img src="../icons/fire-solid-full.svg" class="input-icon">
                        </div>
                    </div>
                    <div class="input-group">
                        <label>Duration (in weeks) *</label>
                        <div class="input-wrapper">
                            <input type="number" name="duration" class="form-input" value="" placeholder="e.g. 2"
                                required>
                            <img src="../icons/calendar-day-solid-full.svg" class="input-icon">
                        </div>
                    </div>
                    <div class="input-group">
                        <label>Trainer</label>
                        <div class="input-wrapper">
                            <input type="text" name="trainer_name" class="form-input" value="" placeholder="e.g. John">
                            <img src="../icons/user-tie-solid-full.svg" class="input-icon">
                        </div>
                    </div>
                </div>

                <div class="section-title"><img src="../icons/utensils-solid-full.svg"><span>Daily Schedule</span></div>

                <div class="meal-grid">

                    <div class="meal-row">
                        <div class="meal-label"><img src="../icons/sun-solid-full.svg" alt="sun" width="20"
                                style="filter: brightness(0) saturate(100%) invert(67%) sepia(87%) saturate(2159%) hue-rotate(352deg) brightness(105%) contrast(93%);">
                            Wake Up</div>
                        <div class="meal-input-area">
                            <textarea name="wake_up" class="form-input" placeholder="Enter wake up meal..."></textarea>
                        </div>
                    </div>

                    <div class="meal-row">
                        <div class="meal-label"><img src="../icons/dumbbell-solid-full.svg" alt="dumbbell" width="20"
                                style="filter: brightness(0) saturate(100%) invert(43%) sepia(61%) saturate(3025%) hue-rotate(205deg) brightness(101%) contrast(94%);">
                            Post Workout</div>
                        <div class="meal-input-area">
                            <textarea name="post_workout" class="form-input"
                                placeholder="Enter post workout meal..."></textarea>
                        </div>
                    </div>

                    <div class="meal-row">
                        <div class="meal-label"><img src="../icons/mug-hot-solid-full.svg" alt="mug-hot" width="20"
                                style="filter: brightness(0) saturate(100%) invert(43%) sepia(61%) saturate(3025%) hue-rotate(205deg) brightness(101%) contrast(94%);">
                            Breakfast</div>
                        <div class="meal-input-area">
                            <textarea name="breakfast" class="form-input" placeholder="Enter breakfast..."></textarea>
                        </div>
                    </div>

                    <div class="meal-row">
                        <div class="meal-label"><img src="../icons/bowl-rice-solid-full.svg" alt="bowl-rice" width="20"
                                style="filter: brightness(0) saturate(100%) invert(43%) sepia(61%) saturate(3025%) hue-rotate(205deg) brightness(101%) contrast(94%);">
                            Lunch</div>
                        <div class="meal-input-area">
                            <textarea name="lunch" class="form-input" placeholder="Enter lunch..."></textarea>
                        </div>
                    </div>

                    <div class="meal-row">
                        <div class="meal-label"><img src="../icons/apple-whole-solid-full.svg" alt="apple-whole"
                                width="20"
                                style="filter: brightness(0) saturate(100%) invert(43%) sepia(61%) saturate(3025%) hue-rotate(205deg) brightness(101%) contrast(94%);">
                            Mid Meal</div>
                        <div class="meal-input-area">
                            <textarea name="snack" class="form-input"
                                placeholder="Enter mid meal / snack..."></textarea>
                        </div>
                    </div>

                    <div class="meal-row">
                        <div class="meal-label"><img src="../icons/moon-solid-full.svg" alt="moon" width="20"
                                style="filter: brightness(0) saturate(100%) invert(43%) sepia(61%) saturate(3025%) hue-rotate(205deg) brightness(101%) contrast(94%);">
                            Dinner</div>
                        <div class="meal-input-area">
                            <textarea name="dinner" class="form-input" placeholder="Enter dinner..."></textarea>
                        </div>
                    </div>

                    <div class="meal-row">
                        <div class="meal-label"><img src="../icons/bed-solid-full.svg" alt="bed" width="20"
                                style="filter: brightness(0) saturate(100%) invert(43%) sepia(61%) saturate(3025%) hue-rotate(205deg) brightness(101%) contrast(94%);">
                            Pre-Sleep</div>
                        <div class="meal-input-area">
                            <textarea name="pre_sleep" class="form-input"
                                placeholder="Enter pre-sleep meal..."></textarea>
                        </div>
                    </div>

                    <div class="meal-row full-width">
                        <div class="meal-label"><img src="../icons/list-check-solid-full.svg" alt="list-check"
                                width="20"
                                style="filter: brightness(0) saturate(100%) invert(43%) sepia(61%) saturate(3025%) hue-rotate(205deg) brightness(101%) contrast(94%);">
                            Guidelines</div>
                        <div class="meal-input-area">
                            <textarea name="guidelines" class="form-input" placeholder="Enter guidelines..."></textarea>
                        </div>
                    </div>
                </div>

                <div
                    style="display:flex; justify-content: space-between; align-items:center; padding: 20px 0 5px 0; gap:15px;">
                    <a href="diet_plan_details.php?id=<?= $plan_id ?>" class="btn btn-secondary"
                        style="border: 1px solid #D1D5DB; background: transparent; text-decoration:none; display:inline-flex; align-items:center; gap:8px;">
                        Cancel
                    </a>
                    <button type="submit" class="btn-new-phase">
                        Save New Phase
                    </button>
                </div>
            </form>
        </div>
    </div>

    <div id="successToast" class="success-toast" aria-hidden="true">
        <div class="success-card">
            <i class="fa-solid fa-circle-check" style="font-size:24px; color:#10B981;"></i>
            <div>
                <h3>Phase Added!</h3>
                <p>New phase saved successfully.</p>
            </div>
        </div>
    </div>

    <script>
        // ---- Use a template: split the packed meal columns back into the 8 fields ----
        function unpackDiet(b, l, s, d) {
            b = b || ''; l = l || ''; s = s || ''; d = d || '';
            const o = { wake_up: '', post_workout: '', breakfast: '', lunch: '', snack: '', dinner: '', pre_sleep: '', guidelines: '' };
            let m;
            if ((m = b.match(/\*\*WAKE\s*UP\s*(?:\([^)]*\))?\s*:?\s*\*\*\s*([\s\S]*?)(?=\n\n|💪|🍳|$)/u))) o.wake_up = m[1].trim();
            if ((m = b.match(/\*\*POST\s*WORKOUT\s*(?:\([^)]*\))?\s*:?\s*\*\*\s*([\s\S]*?)(?=\n\n|🍳|$)/u))) o.post_workout = m[1].trim();
            if ((m = b.match(/\*\*BREAKFAST\s*(?:\([^)]*\))?\s*:?\s*\*\*\s*([\s\S]*)/u))) o.breakfast = m[1].trim();
            else if (!o.wake_up && !o.post_workout) o.breakfast = b.trim();

            o.lunch = l.replace(/^[\s\S]*?\*\*LUNCH\s*(?:\([^)]*\))?\s*:?\s*\*\*\s*\n?/u, '').trim();
            o.snack = s.replace(/^[\s\S]*?\*\*MID\s*MEAL\s*(?:\([^)]*\))?\s*:?\s*\*\*\s*\n?/u, '').trim();

            if ((m = d.match(/\*\*DINNER\s*(?:\([^)]*\))?\s*:?\s*\*\*\s*([\s\S]*?)(?=\n\n|🌙|📝|$)/u))) o.dinner = m[1].trim();
            else if (d.indexOf('**DINNER') === -1 && d.indexOf('**PRE-SLEEP') === -1 && d.indexOf('**GUIDELINES') === -1) o.dinner = d.trim();
            if ((m = d.match(/\*\*PRE-SLEEP\s*(?:\([^)]*\))?\s*:?\s*\*\*\s*([\s\S]*?)(?=\n\n|📝|$)/u))) o.pre_sleep = m[1].trim();
            if ((m = d.match(/\*\*GUIDELINES\s*(?:\([^)]*\))?\s*:?\s*\*\*\s*([\s\S]*)/u))) o.guidelines = m[1].trim();
            return o;
        }

        function applyTemplate() {
            const sel = document.getElementById('tplPicker');
            const msg = document.getElementById('tplMsg');
            const list = window.__DP_TEMPLATES || [];
            const t = list.find(x => String(x.id) === String(sel && sel.value));
            if (!t) { if (msg) { msg.textContent = 'Pick a template first.'; msg.style.color = '#b45309'; } return; }
            if (!confirm('Fill this phase with the "' + t.template_name + '" template? You can still edit before saving.')) return;
            const form = document.querySelector('.card-body form');
            const meals = unpackDiet(t.breakfast, t.lunch, t.snack, t.dinner);
            let n = 0;
            const flash = el => { el.style.transition = 'background .4s'; el.style.background = '#ecfdf5'; setTimeout(() => el.style.background = '', 1000); };
            const setF = (name, val) => {
                if (val === undefined || val === null || val === '') return;
                const el = form.querySelector('[name="' + name + '"]');
                if (!el) return;
                el.value = val; n++; flash(el);
            };
            ['wake_up', 'post_workout', 'breakfast', 'lunch', 'snack', 'dinner', 'pre_sleep', 'guidelines'].forEach(k => setF(k, meals[k]));
            setF('calories', (t.calories && String(t.calories) !== '0') ? t.calories : '');
            setF('duration', t.duration);
            [['goal', t.goal], ['diet_type', t.diet_type]].forEach(function (pair) {
                const name = pair[0], val = pair[1];
                if (!val) return;
                const el = form.querySelector('[name="' + name + '"]');
                if (!el) return;
                if (el.tagName === 'SELECT') {
                    const want = String(val).toLowerCase();
                    const opt = [...el.options].find(o => o.value.toLowerCase() === want || o.text.toLowerCase() === want);
                    if (opt) { el.value = opt.value; n++; flash(el); }
                } else { el.value = val; n++; flash(el); }
            });
            if (msg) { msg.textContent = '✓ Filled ' + n + ' field(s). Edit anything, then Save New Phase.'; msg.style.color = '#059669'; }
        }

        document.addEventListener('DOMContentLoaded', function () {
            if ("<?= $success ?>" === "success") {
                document.getElementById('successToast').classList.add('visible');
                setTimeout(() => { window.location.href = 'diet_plan_details.php?id=<?= $new_id ?>'; }, 1500);
            }
        });

        // Ping the server every 10 minutes to keep the session alive
        setInterval(function () {
            fetch('../auth/keep_alive.php')
                .then(res => res.json())
                .then(data => {
                    if (data.status === 'dead') {
                        window.location.href = '../index.php';
                    }
                })
                .catch(() => { });
        }, 600000); // 10 minutes
    </script>
</body>

</html>