<?php
// 1. Start Session & Connect
require_once __DIR__ . '/../auth/auth_check.php';
require_role(['admin', 'trainer']);
require_once __DIR__ . '/../config.php';

$error = "";
$success = "";

// Fetch Active Trainers
$trainers_result = $conn->query("SELECT id, full_name FROM trainers WHERE is_active = 1 ORDER BY full_name ASC");

// 2. Handle Form Submission
if ($_SERVER["REQUEST_METHOD"] == "POST") {

    $template_name = trim($_POST['template_name'] ?? '');

    $diet_type    = $_POST['diet_type'] ?? 'veg';
    $goal         = $_POST['goal'] ?? '';
    $duration     = $_POST['duration'] ?? 2;
    $calories     = $_POST['calories'] ?? 0;
    $trainer_name = $_POST['trainer_name'] ?? '';

    // --- Pack the 8 meal fields into the 4 DB columns (same format as diet plans) ---
    $wake_up_text      = trim($_POST['wake_up'] ?? '');
    $breakfast_text    = trim($_POST['breakfast'] ?? '');
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

    $dinner_text     = trim($_POST['dinner'] ?? '');
    $pre_sleep_text  = trim($_POST['pre_sleep'] ?? '');
    $guidelines_text = trim($_POST['guidelines'] ?? '');

    $db_dinner = "";
    if ($dinner_text)
        $db_dinner .= "🍲 **DINNER :**\n" . $dinner_text . "\n\n";
    if ($pre_sleep_text)
        $db_dinner .= "🌙 **PRE-SLEEP:**\n" . $pre_sleep_text . "\n\n";
    if ($guidelines_text)
        $db_dinner .= "📝 **GUIDELINES:**\n" . $guidelines_text;

    if (empty($template_name) || empty($goal)) {
        $error = "Please fill in the Template Name and Goal.";
    } else {
        $sql = "INSERT INTO diet_plan_templates
                (template_name, diet_type, goal, duration, calories, trainer_name, breakfast, lunch, snack, dinner)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param(
                "sssiisssss",
                $template_name,
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
            } else {
                $error = "Could not save template: " . $stmt->error;
            }
        } else {
            $error = "Database error: " . $conn->error
                . " — make sure the 'diet_plan_templates' table exists.";
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
    <title>Create Diet Template | JOF INDIA</title>
    <link rel="stylesheet" href="../static/root.css">
    <style>
        /* Premium Success Modal (matches create_diet_plan.php) */
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
                    <h2>Create Diet Template</h2>
                    <p>Build a reusable meal-plan skeleton.</p>
                </div>
            </div>
            <a href="diet-plans.php?tab=templates" class="close-btn">
                <img src="../icons/xmark-solid-full.svg" alt="close">
            </a>
        </div>

        <?php if ($error): ?>
            <div
                style="background:#fee2e2; color:#b91c1c; padding:12px; margin:0 24px 10px; border-radius:8px; font-size:14px; text-align:center;">
                <img src="../icons/circle-exclamation-solid-full.svg" alt="circle-exclamation" width="20"> <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <div class="card-body">

            <!-- Import from ChatGPT -->
            <div style="margin: 0 0 18px; border: 1px dashed #F25C2A; border-radius: 12px; background: #FFF7ED;">
                <button type="button" onclick="toggleGptImport()"
                    style="width:100%; text-align:left; background:none; border:none; padding:14px 16px; cursor:pointer; font-size:14px; font-weight:700; color:#B45309; display:flex; justify-content:space-between; align-items:center;">
                    <span><img src="../icons/clipboard-list-solid-full.svg" width="15" style="vertical-align:-2px; margin-right:7px;">Import from ChatGPT</span>
                    <span id="gptChevron">&#9656;</span>
                </button>
                <div id="gptImportBody" style="display:none; padding:0 16px 16px;">
                    <p style="font-size:12.5px; color:#92400E; margin:0 0 8px;">Paste the diet plan text from ChatGPT, then click <b>Fill the form</b>. Fields are only filled in &mdash; nothing is saved until you press Save Template.</p>
                    <textarea id="gptRaw" rows="8"
                        placeholder="Paste ChatGPT output here&#10;&#10;e.g.&#10;Breakfast: oats + fruit&#10;Lunch: dal, rice, salad&#10;Dinner: paneer + veg&#10;Guidelines: 3L water/day"
                        style="width:100%; padding:10px; border:1px solid #FCD9B6; border-radius:8px; font-family:inherit; font-size:13px; resize:vertical; box-sizing:border-box;"></textarea>
                    <div style="display:flex; gap:10px; align-items:center; margin-top:8px; flex-wrap:wrap;">
                        <button type="button" class="btn btn-primary" onclick="gptFillForm()" style="padding:8px 18px;">Fill the form</button>
                        <span id="gptResult" style="font-size:12.5px; color:#059669;"></span>
                    </div>
                </div>
            </div>

            <form method="POST" action="" id="dietTemplateForm">

                <div class="section-title">
                    <img src="../icons/clipboard-user-solid-full.svg" alt="icon">
                    <span>Template Details</span>
                </div>

                <div class="form-grid">
                    <div class="input-group full-width">
                        <label>Template Name</label>
                        <div class="input-wrapper">
                            <input type="text" name="template_name" class="form-input"
                                placeholder="e.g. Fat Loss - Veg - 1800 kcal" required
                                value="<?= isset($_POST['template_name']) ? htmlspecialchars($_POST['template_name']) : '' ?>">
                            <img src="../icons/clipboard-list-solid-full.svg" class="input-icon" alt="template">
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
                        <label>Calories</label>
                        <div class="input-wrapper">
                            <input type="number" name="calories" class="form-input" placeholder="e.g. 1800"
                                value="<?= isset($_POST['calories']) ? htmlspecialchars($_POST['calories']) : '' ?>">
                            <img src="../icons/fire-solid-full.svg" class="input-icon" alt="calories">
                        </div>
                    </div>

                    <div class="input-group">
                        <label>Duration (weeks)</label>
                        <div class="input-wrapper">
                            <input type="number" name="duration" class="form-input" placeholder="e.g. 2"
                                value="<?= isset($_POST['duration']) ? htmlspecialchars($_POST['duration']) : '2' ?>">
                            <img src="../icons/calendar-day-solid-full.svg" class="input-icon" alt="duration">
                        </div>
                    </div>

                    <div class="input-group">
                        <label>Trainer</label>
                        <div class="input-wrapper">
                            <select name="trainer_name" class="form-input">
                                <option value="">— None —</option>
                                <?php
                                if ($trainers_result && $trainers_result->num_rows > 0) {
                                    while ($row = $trainers_result->fetch_assoc()) {
                                        echo '<option value="' . htmlspecialchars($row['full_name']) . '">' . htmlspecialchars($row['full_name']) . '</option>';
                                    }
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
                    <a href="diet-plans.php?tab=templates" class="btn btn-secondary" style="text-decoration:none;">Cancel</a>
                    <button type="submit" class="btn btn-primary">Save Template</button>
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
            <h3>Template Saved!</h3>
            <p>Your diet template has been created. Redirecting you back to the templates list...</p>
            <div class="timer-progress">
                <div id="redirectTimer" class="timer-bar"></div>
            </div>
        </div>
    </div>

    <script>
        // ---------- Import from ChatGPT ----------
        function toggleGptImport() {
            const b = document.getElementById('gptImportBody');
            const c = document.getElementById('gptChevron');
            const open = b.style.display === 'none';
            b.style.display = open ? 'block' : 'none';
            c.innerHTML = open ? '&#9662;' : '&#9656;';
        }

        function setGptResult(msg, warn) {
            const el = document.getElementById('gptResult');
            el.textContent = msg;
            el.style.color = warn ? '#b45309' : '#059669';
        }

        // Parse a free-text diet plan (ChatGPT output, any layout) into named sections + metadata.
        function parseDietText(raw) {
            const clean = s => s
                .replace(/\*\*/g, '')
                .replace(/`/g, '')
                .replace(/^[^\p{L}\p{N}]+/u, '')
                .replace(/^\d+[.)]\s+/, '')
                .replace(/\s*\|\s*/g, ' ')
                .trim();

            // strip clock times / numeric ranges: "7:00 AM", "6:00–7:15 PM", "11 AM", "60–90 min", "1–2"
            const stripTimes = s => s
                .replace(/\b\d{1,2}:\d{2}\s*(?:[–—-]\s*\d{1,2}:\d{2})?\s*(?:[ap]\.?\s*m\.?)?/gi, ' ')
                .replace(/\b\d{1,2}\s*(?:[ap]\.?\s*m\.?)\b/gi, ' ')
                .replace(/\b\d{1,3}\s*[–—-]\s*\d{1,3}\b/g, ' ');

            // first clock time in a line -> hour as float (24h); null if none
            const clockHour = (line) => {
                const m = line.match(/\b(\d{1,2})(?::(\d{2}))?\s*([ap])\.?\s*m\b/i);
                if (!m) return null;
                let h = parseInt(m[1], 10) % 12;
                if (/p/i.test(m[3])) h += 12;
                return h + (m[2] ? parseInt(m[2], 10) / 60 : 0);
            };
            // the visible time / time-range text in a line ("7:00 AM", "1130 AM", "6:30–7:00 PM") or null
            const grabTime = (line) => {
                const m = line.match(/\b(?:\d{3,4}|\d{1,2}(?:[:.]\d{2})?)\s*(?:[–—-]\s*\d{1,2}(?:[:.]\d{2})?)?\s*[ap]\.?\s*m\.?/i);
                return m ? m[0].replace(/\s*\.\s*/g, '').replace(/\s+/g, ' ').trim().toUpperCase() : null;
            };
            const slotFromHour = (h) => {
                if (h == null) return null;
                if (h >= 4 && h < 7.5) return 'wake_up';
                if (h >= 7.5 && h < 10.5) return 'breakfast';
                if (h >= 10.5 && h < 12.5) return 'snack';
                if (h >= 12.5 && h < 15.5) return 'lunch';
                if (h >= 15.5 && h < 18.75) return 'snack';
                if (h >= 18.75 && h < 22) return 'dinner';
                return 'pre_sleep';
            };

            const SKIP = /^(choose(?: any)?(?: one| 1)?|pick one|for example|for eg|e\.?g\.?|use this(?: simple)? plate structure|plate structure|option\s*\w+|non-?vegetarian option|non-?veg option|vegetarian option|veg option|about|approx|approximately|or|and|plus|time|meal|food|item|items|when|what)$/i;
            const AVOID_HEAD = /^(avoid|avoid these|avoid the following|things to avoid|foods to avoid|don'?t eat|do not eat|strictly avoid)$/i;
            const ADVICE = /^(try to |prefer |avoid |keep (?:dinner|lunch|breakfast|it|the)|don'?t |do not |no |note[:\s]|tip[:\s]|aim to |aim for |make sure|drink at least|stay hydrated|limit |reduce |for (?:someone|people|those|a person)|about \d|you (?:don'?t|should|can|may|need|must))/i;
            const MACRO = /^(protein|carb|carbs|carbohydrate|carbohydrates|fat|fats|calorie|calories|kcal|fiber|fibre|macros?|total)\s*[:~=]/i;

            const SECTIONS = [
                { field: '__ignore', keys: ['workout', 'work out', 'training', 'training session', 'exercise', 'exercises', 'gym', 'gym session', 'resistance training', 'weight training', 'cardio', 'strength training'] },
                { field: 'wake_up', keys: ['wake up', 'wake-up', 'wakeup', 'on waking', 'on rising', 'early morning', 'empty stomach', 'first thing', 'morning ritual', 'morning drink', 'after waking', 'am ritual'] },
                { field: 'post_workout', keys: ['post workout', 'post-workout', 'after workout', 'intra workout', 'intra-workout', 'pre workout', 'pre-workout', 'workout meal', 'during workout', 'post training', 'pre training'] },
                { field: 'breakfast', keys: ['breakfast', 'morning meal', 'first meal', 'meal 1', 'meal one', 'b/fast', 'brk', 'am meal'] },
                { field: 'lunch', keys: ['lunch', 'afternoon meal', 'midday meal', 'mid day meal', 'noon', 'afternoon', 'meal 3', 'meal three'] },
                { field: 'snack', keys: ['mid meal', 'mid-meal', 'midmeal', 'mid morning', 'mid-morning', 'midmorning', 'mid day snack', 'mid-day snack', 'evening snack', 'evening', 'snack', 'snacks', 'brunch', 'tea time', 'tea-time', 'teatime', 'meal 2', 'meal two', 'meal 4', 'meal four', 'between meals'] },
                { field: 'dinner', keys: ['dinner', 'supper', 'evening meal', 'night meal', 'last meal', 'meal 5', 'meal five', 'meal 6', 'pm meal'] },
                { field: 'pre_sleep', keys: ['pre sleep', 'pre-sleep', 'presleep', 'bed time', 'bedtime', 'before bed', 'before sleep', 'night cap', 'nightcap', 'post dinner', 'post-dinner', 'optional', 'optional meal', 'bedtime snack', 'night snack', 'late night', 'night', 'before sleeping'] },
                { field: 'guidelines', keys: ['guideline', 'guidelines', 'note', 'notes', 'instruction', 'instructions', 'tips', 'general tips', 'important', 'rules', 'do and don', 'dos and don', 'general guidelines', 'if hungry', 'if still hungry', 'if you feel hungry', 'general advice', 'other tips', 'hydration', 'water intake'] }
            ];

            const norm = (line) => stripTimes(clean(line).toLowerCase())
                .replace(/\([^)]*\)/g, ' ')
                .replace(/[—–\-:•·|]+/g, ' ')
                .replace(/\s+/g, ' ')
                .trim();

            const headerField = (line) => {
                let l = norm(line);
                if (l.length <= 42) {
                    for (const s of SECTIONS) {
                        for (const k of s.keys) {
                            const kk = k.replace(/-/g, ' ');
                            if (l === kk || l.startsWith(kk + ' ') || (l.startsWith(kk) && l.length <= kk.length + 6)) return s.field;
                        }
                    }
                }
                // name-less time header: "7:00 AM", "🍳 8:00 AM", "Meal 1 (8 AM)", "8:00–9:00 AM"
                const leftover = l.replace(/^(meal|session|slot)\s*\d*/i, '').trim();
                if (leftover.length <= 3 && clockHour(line) != null) return slotFromHour(clockHour(line));
                return null;
            };

            const buckets = {};
            let current = null;
            let avoidMode = false;

            const pushLine = (bucket, txt) => {
                const c = clean(txt);
                if (!c) return;
                const bare = c.replace(/:\s*$/, '');
                if (MACRO.test(c)) return;
                if (AVOID_HEAD.test(bare)) { avoidMode = true; return; }
                if (SKIP.test(bare)) { avoidMode = false; return; }
                if (bucket === '__ignore') { if (ADVICE.test(c)) (buckets.guidelines = buckets.guidelines || []).push(c); return; }
                if (avoidMode) { (buckets.guidelines = buckets.guidelines || []).push('Avoid: ' + c); return; }
                if (ADVICE.test(c)) { (buckets.guidelines = buckets.guidelines || []).push(c); return; }
                (buckets[bucket] = buckets[bucket] || []).push(c);
            };

            raw.split(/\r?\n/).forEach(rawLine => {
                const line = rawLine.replace(/\t/g, ' ');
                if (!line.trim()) return;

                // ---- markdown / pipe table row ----
                if ((line.match(/\|/g) || []).length >= 2) {
                    const cells = line.split('|').map(c => c.trim()).filter(Boolean);
                    if (!cells.length || cells.every(c => /^[-:\s]+$/.test(c))) return;
                    let field = null;
                    for (const cell of cells) { const ff = headerField(cell); if (ff && ff !== '__ignore') { field = ff; break; } }
                    if (!field) for (const cell of cells) { const s = slotFromHour(clockHour(cell)); if (s) { field = s; break; } }
                    if (field) {
                        const tCell = cells.map(c => grabTime(c)).find(Boolean);
                        if (tCell) (buckets[field] = buckets[field] || []).push(tCell);
                        const food = cells
                            .filter(c => headerField(c) === null && clockHour(c) == null && !/^(time|meal|food|slot|when)$/i.test(c))
                            .sort((a, b) => b.length - a.length)[0];
                        if (food) food.split(/[;,]\s*|\s*\/\s*(?=[A-Z])/).forEach(part => pushLine(field, part));
                        current = field;
                    }
                    return;
                }

                const f = headerField(line);
                if (f) {
                    current = f;
                    avoidMode = false;
                    if (!buckets[f]) buckets[f] = [];
                    // keep the time that was on the section/header line
                    if (f !== '__ignore') { const t = grabTime(line); if (t) buckets[f].push(t); }
                    const noParen = stripTimes(line.replace(/\([^)]*\)/g, ''));
                    const ci = noParen.indexOf(':');
                    if (ci !== -1) {
                        const after = clean(noParen.slice(ci + 1));
                        if (after.length >= 3 && !/^[\d\s–—-]*(?:am|pm)?$/i.test(after) && !SKIP.test(after.replace(/:\s*$/, ''))) {
                            after.split(/,\s*(?=[A-Za-z0-9])/).forEach(part => pushLine(f, part));
                        }
                    }
                    return;
                }
                if (current) pushLine(current, line);
            });

            const out = { fields: {}, meta: {} };
            Object.keys(buckets).forEach(f => { if (f !== '__ignore' && buckets[f].length) out.fields[f] = buckets[f].join('\n'); });

            const low = raw.toLowerCase();
            if (/\bvegan\b/.test(low)) out.meta.diet_type = 'vegan';
            else if (/non[\s-]?veg|nonveg|eggetarian|chicken|fish|mutton|prawn|\begg\b|\beggs\b/.test(low)) out.meta.diet_type = 'nonveg';
            else if (/\bveg\b|vegetarian/.test(low)) out.meta.diet_type = 'veg';

            const calM = low.match(/(\d{3,5})\s*(?:k?cal|calories|kcals)/);
            if (calM) out.meta.calories = calM[1];

            const wkM = low.match(/(\d{1,2})\s*(?:week|wk)s?\b/);
            if (wkM) out.meta.duration = wkM[1];
            else { const mM = low.match(/(\d{1,2})\s*months?\b/); if (mM) out.meta.duration = String(parseInt(mM[1], 10) * 4); }

            const gm = raw.match(/goal\s*[:\-]\s*(.+)/i);
            if (gm) out.meta.goal = clean(gm[1]).split(/[.\n]/)[0].trim();
            else if (/fat loss|weight loss|cutting|lose weight|reduce weight|slim/i.test(raw)) out.meta.goal = 'Fat Loss';
            else if (/muscle (gain|build)|bulking|lean mass|hypertrophy|mass gain|muscle building/i.test(raw)) out.meta.goal = 'Muscle Building';
            else if (/maintenance|maintain weight|general health|wellness|diabet|blood sugar|healthy eating/i.test(raw)) out.meta.goal = 'General Health';

            return out;
        }

        function gptFillForm() {
            const raw = document.getElementById('gptRaw').value || '';
            if (!raw.trim()) { setGptResult('Paste the ChatGPT text first.', true); return; }
            const p = parseDietText(raw);
            let filled = 0;
            const flash = el => {
                el.style.transition = 'background .4s';
                el.style.background = '#ecfdf5';
                setTimeout(() => { el.style.background = ''; }, 1000);
            };
            const setField = (name, val) => {
                if (val === undefined || val === '') return;
                const el = document.querySelector('#dietTemplateForm [name="' + name + '"]');
                if (!el) return;
                el.value = val;
                filled++;
                flash(el);
            };
            ['wake_up', 'post_workout', 'breakfast', 'lunch', 'snack', 'dinner', 'pre_sleep', 'guidelines']
                .forEach(f => setField(f, p.fields[f]));
            setField('calories', p.meta.calories);
            setField('duration', p.meta.duration);

            const dt = document.querySelector('#dietTemplateForm [name="diet_type"]');
            if (dt && p.meta.diet_type) { dt.value = p.meta.diet_type; filled++; flash(dt); }

            const go = document.querySelector('#dietTemplateForm [name="goal"]');
            if (go && p.meta.goal) {
                const want = p.meta.goal.toLowerCase();
                const first = want.split(/[\s/]/)[0];
                const opt = [...go.options].find(o =>
                    o.value.toLowerCase() === want ||
                    o.text.toLowerCase() === want ||
                    o.text.toLowerCase().startsWith(first));
                if (opt) { go.value = opt.value; filled++; flash(go); }
            }

            setGptResult(filled
                ? ('✓ Filled ' + filled + ' field(s). Review everything, then Save Template.')
                : 'Could not detect the sections — check the pasted format or fill manually.', filled === 0);
        }

        document.addEventListener('DOMContentLoaded', function () {
            if ("<?= $success ?>" === "success") {
                const toast = document.getElementById('successToast');
                const timerBar = document.getElementById('redirectTimer');

                toast.classList.add('active');
                setTimeout(() => { timerBar.style.width = '0%'; }, 100);
                setTimeout(() => window.location.href = 'diet-plans.php?tab=templates', 1600);
            }
        });

        // Keep the session alive
        setInterval(function () {
            fetch('../auth/keep_alive.php')
                .then(res => res.json())
                .then(data => { if (data.status === 'dead') window.location.href = '../index.php'; })
                .catch(() => { });
        }, 600000);
    </script>
</body>

</html>
