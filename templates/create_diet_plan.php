<?php
// 1. Start Session & Connect
require_once __DIR__ . '/../auth/auth_check.php';
require_role(['admin', 'trainer']);
require_once __DIR__ . '/../config.php';

$error = "";
$success = "";

// Fetch Active Trainers
$trainers_result = $conn->query("SELECT id, full_name FROM trainers WHERE is_active = 1 ORDER BY full_name ASC");

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

// 2. Handle Form Submission
if ($_SERVER["REQUEST_METHOD"] == "POST") {

    $client_name = trim($_POST['client_name'] ?? '');
    $week_phase = $_POST['week_phase'] ?? 'Week 1 & 2';
    $plan_name = $client_name . " - " . $week_phase;

    $diet_type = $_POST['diet_type'] ?? 'veg';
    $goal = $_POST['goal'] ?? '';
    $duration = $_POST['duration'] ?? 2;
    $calories = $_POST['calories'] ?? 0;
    $trainer_name = $_POST['trainer_name'] ?? '';

    $wake_up_text = trim($_POST['wake_up'] ?? '');
    $breakfast_text = trim($_POST['breakfast'] ?? '');
    $post_workout_text = trim($_POST['post_workout'] ?? '');

    $db_breakfast = "";
    if ($wake_up_text)
        $db_breakfast .= "🌅 **WAKE UP :**\n" . $wake_up_text . "\n\n";
    if ($post_workout_text)
        $db_breakfast .= "💪 **POST WORKOUT:**\n" . $post_workout_text . "\n\n";
    if ($breakfast_text)
        $db_breakfast .= "🍳 **BREAKFAST:**\n" . $breakfast_text;

    $db_lunch = trim($_POST['lunch'] ?? '');
    if ($db_lunch)
        $db_lunch = "🍛 **LUNCH :**\n" . $db_lunch;

    $db_snack = trim($_POST['snack'] ?? '');
    if ($db_snack)
        $db_snack = "🍎 **MID MEAL :**\n" . $db_snack;

    $dinner_text = trim($_POST['dinner'] ?? '');
    $pre_sleep_text = trim($_POST['pre_sleep'] ?? '');
    $guidelines_text = trim($_POST['guidelines'] ?? '');

    $db_dinner = "";
    if ($dinner_text)
        $db_dinner .= "🍲 **DINNER :**\n" . $dinner_text . "\n\n";
    if ($pre_sleep_text)
        $db_dinner .= "🌙 **PRE-SLEEP:**\n" . $pre_sleep_text . "\n\n";
    if ($guidelines_text)
        $db_dinner .= "📝 **GUIDELINES:**\n" . $guidelines_text;

    if (empty($client_name) || empty($goal)) {
        $error = "Please fill in the Client Name and Goal.";
    } else {
        try {
            $sql = "INSERT INTO diet_plans (plan_name, diet_type, goal, duration, calories, trainer_name, breakfast, lunch, snack, dinner) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

            $stmt = $conn->prepare($sql);
            $stmt->bind_param(
                "sssiisssss",
                $plan_name,
                $diet_type,
                $goal,
                $duration,
                $calories,
                $trainer_name,
                $db_breakfast,
                $db_lunch,
                $db_snack,
                $db_dinner
            );

            if ($stmt->execute()) {
                $success = "success";
            }
        } catch (Exception $e) {
            $error = "Database Error: " . $e->getMessage();
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" size="16x16" href="../icons/favicon-dark-logo.png" type="image/png">
    <title>Create Lifestyle Chart | JOF INDIA</title>
    <link rel="stylesheet" href="../static/root.css">
    <style>
        /* Premium Success Modal for Diet Plan */
        .success-overlay {
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.4);
            backdrop-filter: blur(8px);
            z-index: 10000;
            display: none;
            align-items: center;
            justify-content: center;
            opacity: 0;
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            padding: 20px;
        }

        .success-overlay.active {
            display: flex;
            opacity: 1;
        }

        .success-card {
            background: #fff;
            border-radius: 20px;
            width: 100%;
            max-width: 380px;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
            padding: 40px 32px;
            text-align: center;
            transform: scale(0.9) translateY(30px);
            transition: all 0.4s cubic-bezier(0.34, 1.56, 0.64, 1);
        }

        .success-overlay.active .success-card {
            transform: scale(1) translateY(0);
        }

        .success-icon-box {
            width: 72px;
            height: 72px;
            border-radius: 50%;
            background: linear-gradient(135deg, #10B981, #34D399);
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 20px;
            box-shadow: 0 10px 20px rgba(16, 185, 129, 0.2);
        }

        .success-card h3 {
            font-size: 21px;
            font-weight: 800;
            color: #111827;
            margin: 0 0 10px;
            letter-spacing: -0.5px;
        }

        .success-card p {
            font-size: 14px;
            color: #6B7280;
            line-height: 1.6;
            margin-bottom: 24px;
        }

        .timer-progress {
            height: 4px;
            border-radius: 4px;
            background: #F3F4F6;
            overflow: hidden;
            width: 100%;
        }

        .timer-bar {
            height: 100%;
            width: 100%;
            background: linear-gradient(90deg, #10B981, #34D399);
            transition: width 1.5s linear;
        }
    </style>
</head>

<body class="page-create_diet_plan">

    <div class="container">
        <div class="card-header">
            <div class="brand-area">
                <img src="../icons/logo-dark(1).png" class="brand-logo" alt="JOF">
                <div class="brand-text">
                    <h2>Create Lifestyle Chart</h2>
                    <p>Design multi-phase diet plans.</p>
                </div>
            </div>
            <a href="diet-plans.php" class="close-btn">
                <img src="../icons/xmark-solid-full.svg" alt="close">
            </a>
        </div>

        <?php if ($error): ?>
            <div
                style="background:#fee2e2; color:#b91c1c; padding:12px; margin:0 24px 10px; border-radius:8px; font-size:14px; text-align:center;">
                <img src="../icons/circle-exclamation-solid-full.svg" alt="circle-exclamation" width="20"> <?= $error ?>
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

            <form method="POST" action="" id="dietPlanForm">

                <div class="section-title">
                    <img src="../icons/clipboard-user-solid-full.svg" alt="icon">
                    <span>Client & Phase Details</span>
                </div>

                <div class="form-grid">
                    <div class="input-group full-width phase-container">
                        <div>
                            <label>Client Name</label>
                            <div class="input-wrapper">
                                <input type="text" name="client_name" class="form-input"
                                    placeholder="e.g. Shubham Samriya" required>
                                <img src="../icons/user-solid-full.svg" class="input-icon" alt="user">
                            </div>
                        </div>
                        <div>
                            <label>Week Phase</label>
                            <div class="input-wrapper">
                                <select name="week_phase" class="form-input" required>
                                    <option value="Week 1 & 2">Week 1 & 2</option>
                                    <option value="Week 3 & 4">Week 3 & 4</option>
                                    <option value="Week 5 & 6">Week 5 & 6</option>
                                    <option value="Week 7 & 8">Week 7 & 8</option>
                                    <option value="Week 9 & 10">Week 9 & 10</option>
                                    <option value="Week 11 & 12">Week 11 & 12</option>
                                    <option value="Maintenance">Maintenance</option>
                                </select>
                                <img src="../icons/calendar-days-solid-full.svg" class="input-icon" alt="phase">
                            </div>
                        </div>
                    </div>

                    <div class="input-group">
                        <label>Goal</label>
                        <div class="input-wrapper">
                            <select name="goal" class="form-input" required>
                                <option value="Fat Loss">Fat Loss</option>
                                <option value="Muscle Building">Muscle Building</option>
                                <option value="General Health">General Health</option>
                            </select>
                            <img src="../icons/bullseye-solid-full.svg" class="input-icon" alt="goal">
                        </div>
                    </div>

                    <div class="input-group">
                        <label>Diet Type</label>
                        <div class="input-wrapper">
                            <select name="diet_type" class="form-input">
                                <option value="veg">Vegetarian</option>
                                <option value="nonveg">Non-Veg</option>
                                <option value="vegan">Vegan</option>
                            </select>
                            <img src="../icons/leaf-solid-full.svg" class="input-icon" alt="diet">
                        </div>
                    </div>

                    <div class="input-group">
                        <label>Trainer</label>
                        <div class="input-wrapper">
                            <select name="trainer_name" class="form-input">
                                <?php
                                if ($trainers_result && $trainers_result->num_rows > 0) {
                                    while ($row = $trainers_result->fetch_assoc()) {
                                        echo '<option value="' . htmlspecialchars($row['full_name']) . '">' . htmlspecialchars($row['full_name']) . '</option>';
                                    }
                                } else {
                                    echo '<option value="Admin">Admin</option>';
                                }
                                ?>
                            </select>
                            <img src="../icons/user-tie-solid-full.svg" class="input-icon" alt="trainer">
                        </div>
                    </div>
                </div>

                <div class="section-title">
                    <img src="../icons/utensils-solid-full.svg" alt="icon">
                    <span>Daily Schedule</span>
                </div>

                <div class="meal-grid">
                    <div class="meal-row">
                        <div class="meal-label"><img src="../icons/sun-solid-full.svg" alt="sun" width="20"> Wake Up
                        </div>
                        <div class="meal-input-area"><textarea name="wake_up" class="form-input"></textarea></div>
                    </div>
                    <div class="meal-row">
                        <div class="meal-label"><img src="../icons/dumbbell-solid-full.svg" alt="dumbbell" width="20">
                            Post Workout</div>
                        <div class="meal-input-area"><textarea name="post_workout" class="form-input"></textarea></div>
                    </div>
                    <div class="meal-row">
                        <div class="meal-label"><img src="../icons/mug-hot-solid-full.svg" alt="mug-hot" width="20">
                            Breakfast</div>
                        <div class="meal-input-area"><textarea name="breakfast" class="form-input"></textarea></div>
                    </div>
                    <div class="meal-row">
                        <div class="meal-label"><img src="../icons/bowl-rice-solid-full.svg" alt="bowl-rice" width="20">
                            Lunch</div>
                        <div class="meal-input-area"><textarea name="lunch" class="form-input"></textarea></div>
                    </div>
                    <div class="meal-row">
                        <div class="meal-label"><img src="../icons/apple-whole-solid-full.svg" alt="apple-whole"
                                width="20"> Mid Meal</div>
                        <div class="meal-input-area"><textarea name="snack" class="form-input"></textarea></div>
                    </div>
                    <div class="meal-row">
                        <div class="meal-label"><img src="../icons/moon-solid-full.svg" alt="moon" width="20"> Dinner
                        </div>
                        <div class="meal-input-area"><textarea name="dinner" class="form-input"></textarea></div>
                    </div>
                    <div class="meal-row">
                        <div class="meal-label"><img src="../icons/bed-solid-full.svg" alt="bed" width="20"> Pre-Sleep
                        </div>
                        <div class="meal-input-area"><textarea name="pre_sleep" class="form-input"></textarea></div>
                    </div>
                    <div class="meal-row full-width">
                        <div class="meal-label"><img src="../icons/list-check-solid-full.svg" alt="list-check"
                                width="20"> Guidelines</div>
                        <div class="meal-input-area"><textarea name="guidelines" class="form-input"></textarea></div>
                    </div>
                </div>

                <div class="action-buttons">
                    <a href="diet-plans.php" class="btn btn-secondary" style="text-decoration:none;">Cancel</a>
                    <button type="submit" class="btn btn-primary">Save Phase & Publish</button>
                </div>

            </form>
        </div>
    </div>

    <!-- ===== SUCCESS MODAL ===== -->
    <div id="successToast" class="success-overlay">
        <div class="success-card">
            <div class="success-icon-box">
                <img src="../icons/check-solid-full.svg"
                    style="width:30px; height:30px; filter:brightness(0) invert(1);" alt="Success">
            </div>
            <h3>Phase Saved Successfully!</h3>
            <p>Your lifestyle chart has been updated. Redirecting you to the diet plans list...</p>
            <div class="timer-progress">
                <div id="redirectTimer" class="timer-bar"></div>
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
            const form = document.getElementById('dietPlanForm');
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
            if (msg) { msg.textContent = '✓ Filled ' + n + ' field(s). Edit anything, then Save.'; msg.style.color = '#059669'; }
        }

        document.addEventListener('DOMContentLoaded', function () {
            if ("<?= $success ?>" === "success") {
                const toast = document.getElementById('successToast');
                const timerBar = document.getElementById('redirectTimer');

                toast.classList.add('active');

                // Animate the timer bar
                setTimeout(() => {
                    timerBar.style.width = '0%';
                }, 100);

                setTimeout(() => window.location.href = 'diet-plans.php', 1600);
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