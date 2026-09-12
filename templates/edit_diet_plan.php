<?php
require_once __DIR__ . '/../auth/auth_check.php';
require_role(['admin', 'trainer']);
require_once __DIR__ . '/../config.php';

$error = "";
$success = "";

// 2. Fetch Plan
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

// Parse Name to separate Client Name from Phase
$name_parts = explode(" - ", $plan['plan_name']);
$client_name = trim($name_parts[0]);
$current_phase = isset($name_parts[1]) ? trim($name_parts[1]) : '';

// Fetch ALL Phases for this Client (for Phase Selection dropdown)
$history_sql = "SELECT id, plan_name FROM diet_plans WHERE plan_name LIKE ? ORDER BY id ASC";
$search_name = $client_name . " - %";
$stmt_hist = $conn->prepare($history_sql);
$stmt_hist->bind_param("s", $search_name);
$stmt_hist->execute();
$all_phases = $stmt_hist->get_result();

// Copy out id+label pairs for the reference-panel dropdown, then rewind for the
// existing "Week Phase" dropdown below, which reads $all_phases itself.
$all_client_phases = [];
while ($ph = $all_phases->fetch_assoc()) {
    $ph_parts = explode(" - ", $ph['plan_name']);
    $all_client_phases[] = [
        'id'    => $ph['id'],
        'label' => isset($ph_parts[1]) ? trim($ph_parts[1]) : $ph['plan_name'],
    ];
}
$all_phases->data_seek(0);

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

// --- PARSING LOGIC: Extract separate fields from combined DB columns ---
// Uses flexible regex to handle variations: "**WAKE UP:**", "**WAKE UP (6 AM):**", "**WAKE UP :**" etc.
// The key pattern is: optional emoji, **, LABEL, optional (time), optional spaces, :, **

// 1. Breakfast Column (Contains: Wake Up, Post Workout, Breakfast)
$db_breakfast = $plan['breakfast'];
$val_wakeup = "";
$val_postworkout = "";
$val_breakfast = "";

// Extract Wake Up - handles "WAKE UP:", "WAKE UP (6 AM):", "WAKE UP :" etc.
if (preg_match('/\*\*WAKE\s*UP\s*(?:\([^)]*\))?\s*:?\s*\*\*\s*(.*?)(?=\n\n|💪|🍳|$)/su', $db_breakfast, $m)) {
    $val_wakeup = trim($m[1]);
}
// Extract Post Workout - handles "POST WORKOUT:", "POST WORKOUT (time):" etc.
if (preg_match('/\*\*POST\s*WORKOUT\s*(?:\([^)]*\))?\s*:?\s*\*\*\s*(.*?)(?=\n\n|🍳|$)/su', $db_breakfast, $m)) {
    $val_postworkout = trim($m[1]);
}
// Extract Breakfast - handles "BREAKFAST:", "BREAKFAST (time):" etc.
if (preg_match('/\*\*BREAKFAST\s*(?:\([^)]*\))?\s*:?\s*\*\*\s*(.*)/su', $db_breakfast, $m)) {
    $val_breakfast = trim($m[1]);
} else if (empty($val_wakeup) && empty($val_postworkout)) {
    // If no markers found, assume it's all breakfast (legacy data)
    $val_breakfast = trim($db_breakfast);
}

// 2. Lunch Column - strip any emoji + markdown prefix like "🍛 **LUNCH (11 AM):**"
$val_lunch = $plan['lunch'];
$val_lunch = preg_replace('/^.*?\*\*LUNCH\s*(?:\([^)]*\))?\s*:?\s*\*\*\s*\n?/su', '', $val_lunch);
$val_lunch = trim($val_lunch);

// 3. Snack Column - strip any emoji + markdown prefix like "🍎 **MID MEAL (4 PM):**"
$val_snack = $plan['snack'];
$val_snack = preg_replace('/^.*?\*\*MID\s*MEAL\s*(?:\([^)]*\))?\s*:?\s*\*\*\s*\n?/su', '', $val_snack);
$val_snack = trim($val_snack);

// 4. Dinner Column (Contains: Dinner, Pre-Sleep, Guidelines)
$db_dinner = $plan['dinner'];
$val_dinner = "";
$val_presleep = "";
$val_guidelines = "";

// Extract Dinner - handles "DINNER:", "DINNER (8 PM):", "DINNER :" etc.
if (preg_match('/\*\*DINNER\s*(?:\([^)]*\))?\s*:?\s*\*\*\s*(.*?)(?=\n\n|🌙|📝|$)/su', $db_dinner, $m)) {
    $val_dinner = trim($m[1]);
} else if (strpos($db_dinner, '**DINNER') === false && strpos($db_dinner, '**PRE-SLEEP') === false && strpos($db_dinner, '**GUIDELINES') === false) {
    // If no markers at all, it's raw text
    $val_dinner = trim($db_dinner);
}

// Extract Pre-Sleep - handles "PRE-SLEEP:", "PRE-SLEEP (time):" etc.
if (preg_match('/\*\*PRE-SLEEP\s*(?:\([^)]*\))?\s*:?\s*\*\*\s*(.*?)(?=\n\n|📝|$)/su', $db_dinner, $m)) {
    $val_presleep = trim($m[1]);
}

// Extract Guidelines
if (preg_match('/\*\*GUIDELINES\s*(?:\([^)]*\))?\s*:?\s*\*\*\s*(.*)/su', $db_dinner, $m)) {
    $val_guidelines = trim($m[1]);
}


// 3. Handle Submission (Update Only)
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
        $final_breakfast .= "🌅 **WAKE UP :**\n" . $in_wakeup . "\n\n";
    if ($in_post)
        $final_breakfast .= "💪 **POST WORKOUT:**\n" . $in_post . "\n\n";
    if ($in_break)
        $final_breakfast .= "🍳 **BREAKFAST:**\n" . $in_break;

    // Lunch Column
    $final_lunch = trim($_POST['lunch']);
    if ($final_lunch)
        $final_lunch = "🍛 **LUNCH :**\n" . $final_lunch;

    // Snack Column
    $final_snack = trim($_POST['snack']);
    if ($final_snack)
        $final_snack = "🍎 **MID MEAL :**\n" . $final_snack;

    // Dinner Column
    $in_dinner = trim($_POST['dinner']);
    $in_pre = trim($_POST['pre_sleep']);
    $in_guide = trim($_POST['guidelines']);

    $final_dinner = "";
    if ($in_dinner)
        $final_dinner .= "🍲 **DINNER :**\n" . $in_dinner . "\n\n";
    if ($in_pre)
        $final_dinner .= "🌙 **PRE-SLEEP:**\n" . $in_pre . "\n\n";
    if ($in_guide)
        $final_dinner .= "📝 **GUIDELINES:**\n" . $in_guide;

    try {
        // UPDATE existing plan
        $sql = "UPDATE diet_plans SET plan_name=?, diet_type=?, goal=?, duration=?, calories=?, trainer_name=?, breakfast=?, lunch=?, snack=?, dinner=? WHERE id=?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("sssiisssssi", $new_plan_name, $diet_type, $goal, $duration, $calories, $trainer_name, $final_breakfast, $final_lunch, $final_snack, $final_dinner, $plan_id);
        if ($stmt->execute()) {
            $success = "updated";
            // Update local vars to show latest data
            $current_phase = $selected_phase;
            $val_wakeup = $in_wakeup;
            $val_postworkout = $in_post;
            $val_breakfast = $in_break;
            $val_lunch = trim($_POST['lunch']); // raw
            $val_snack = trim($_POST['snack']); // raw
            $val_dinner = $in_dinner;
            $val_presleep = $in_pre;
            $val_guidelines = $in_guide;
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
    <title>Edit Diet Plan | JOF Fitness</title>
    <link rel="stylesheet" href="../static/root.css?v=<?= @filemtime(__DIR__ . '/../static/root.css') ?: time() ?>">
    <!-- <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"> -->
    <style>
        /* Split-screen: previous-phase reference on the left, the form on the right — equal
           width columns, and both stretch to the same height (the form card already caps its
           body at 70vh with its own scrollbar; the reference pane matches that automatically). */
        body.page-create_diet_plan { align-items: stretch; }
        .phase-split {
            display: flex;
            gap: 24px;
            width: 100%;
            max-width: 1800px;
            margin: 0 auto;
            align-items: stretch;
        }
        .phase-reference-pane {
            flex: 1 1 0;
            min-width: 0;
            background: rgba(255, 255, 255, 0.97);
            backdrop-filter: blur(10px);
            border-radius: 24px;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5);
            overflow: hidden;
            display: flex;
            flex-direction: column;
        }
        .phase-reference-pane .ref-header {
            padding: 18px 20px;
            border-bottom: 1px solid #EEE;
            display: flex;
            flex-direction: column;
            gap: 8px;
            flex-shrink: 0;
        }
        .phase-reference-pane .ref-header label {
            font-weight: 700;
            font-size: 13px;
            color: #374151;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .phase-reference-pane select {
            width: 100%;
            padding: 9px 10px;
            border: 1px solid #E5E7EB;
            border-radius: 8px;
            font-size: 13px;
            background: #fff;
        }
        .phase-reference-pane iframe {
            flex: 1 1 auto;
            width: 100%;
            min-height: 400px;
            border: none;
            background: #F3F4F6;
        }
        .phase-form-col {
            flex: 1 1 0;
            min-width: 0;
            display: flex;
            justify-content: center;
        }
        .phase-form-col .container { margin: 0; width: 100%; }
        @media (max-width: 1100px) {
            .phase-split { flex-direction: column; align-items: stretch; }
            .phase-reference-pane { flex: none; height: 55vh; min-height: 340px; }
        }
        /* Daily Schedule: always one meal field per row inside the split-screen form,
           and guarantee the textareas are user-resizable, regardless of viewport width. */
        .meal-grid {
            display: block !important;
            grid-template-columns: none !important;
            columns: auto !important;
            column-count: auto !important;
            width: 100% !important;
        }
        .meal-grid .meal-row {
            display: block !important;
            width: 100% !important;
            max-width: 100% !important;
            float: none !important;
            clear: both !important;
            grid-column: auto !important;
            margin: 0 0 20px 0 !important;
        }
        .meal-grid .meal-label {
            display: flex !important;
            align-items: center !important;
            gap: 10px !important;
            width: 100% !important;
            padding-top: 0 !important;
            margin-bottom: 8px !important;
        }
        .meal-grid .meal-input-area {
            display: block !important;
            width: 100% !important;
        }
        .meal-grid textarea.form-input {
            resize: vertical !important;
            min-height: 100px !important;
            width: 100% !important;
        }
    </style>

</head>

<body class="page-create_diet_plan">

    <div class="phase-split">

        <div class="phase-reference-pane">
            <div class="ref-header">
                <label>
                    <img src="../icons/layer-group-solid-full.svg" width="14" alt="">
                    Previous phases &mdash; <?= htmlspecialchars($client_name) ?>
                </label>
                <select id="refPhaseSelect">
                    <?php foreach ($all_client_phases as $ph): ?>
                        <option value="<?= (int) $ph['id'] ?>" <?= $ph['id'] == $plan_id ? 'selected' : '' ?>>
                            <?= htmlspecialchars($ph['label']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <iframe id="refPhaseFrame" src="print_combined_plan.php?id=<?= (int) $plan_id ?>&single=1"
                title="Selected phase reference"></iframe>
        </div>

    <div class="phase-form-col">
    <div class="container">
        <div class="card-header">
            <div class="brand-area">
                <img src="../icons/logo-dark(1).png" class="brand-logo" alt="JOF">
                <div class="brand-text">
                    <h2>Edit Diet Plan</h2>
                    <p>Modify <b><?= htmlspecialchars($client_name) ?></b>'s plan.</p>
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
                                    readonly required>
                                <img src="../icons/user-solid-full.svg" class="input-icon" alt="user">
                            </div>
                        </div>
                        <div>
                            <label>Week Phase *</label>
                            <div class="input-wrapper">
                                <select class="form-input" onchange="location = this.value;">
                                    <?php
                                    // Reset pointer in case it's used elsewhere (though it shouldn't be)
                                    $all_phases->data_seek(0);
                                    while ($phase = $all_phases->fetch_assoc()):
                                        $p_parts = explode(" - ", $phase['plan_name']);
                                        $p_name = isset($p_parts[1]) ? $p_parts[1] : $phase['plan_name'];
                                        $selected = ($phase['id'] == $plan_id) ? 'selected' : '';
                                        ?>
                                        <option value="edit_diet_plan.php?id=<?= $phase['id'] ?>" <?= $selected ?>>
                                            <?= htmlspecialchars($p_name) ?>
                                        </option>
                                    <?php endwhile; ?>
                                </select>
                                <img src="../icons/calendar-days-solid-full.svg" class="input-icon" alt="phase">
                                <input type="hidden" name="week_phase" value="<?= htmlspecialchars($current_phase) ?>">
                            </div>
                        </div>
                    </div>

                    <div class="input-group">
                        <label>Goal *</label>
                        <div class="input-wrapper">
                            <input type="text" name="goal" class="form-input"
                                value="<?= htmlspecialchars($plan['goal']) ?>" required>
                            <img src="../icons/bullseye-solid-full.svg" class="input-icon">
                        </div>
                    </div>
                    <div class="input-group">
                        <label>Diet Type *</label>
                        <div class="input-wrapper">
                            <input type="text" name="diet_type" class="form-input"
                                value="<?= htmlspecialchars($plan['diet_type']) ?>" required>
                            <img src="../icons/leaf-solid-full.svg" class="input-icon">
                        </div>
                    </div>
                    <div class="input-group">
                        <label>Calories *</label>
                        <div class="input-wrapper">
                            <input type="number" name="calories" class="form-input"
                                value="<?= htmlspecialchars($plan['calories']) ?>" required>
                            <img src="../icons/fire-solid-full.svg" class="input-icon">
                        </div>
                    </div>
                    <div class="input-group">
                        <label>Duration (in weeks) *</label>
                        <div class="input-wrapper">
                            <input type="number" name="duration" class="form-input"
                                value="<?= htmlspecialchars($plan['duration']) ?>" required>
                            <img src="../icons/calendar-day-solid-full.svg" class="input-icon">
                        </div>
                    </div>
                    <div class="input-group">
                        <label>Trainer *</label>
                        <div class="input-wrapper">
                            <input type="text" name="trainer_name" class="form-input"
                                value="<?= htmlspecialchars($plan['trainer_name']) ?>">
                            <img src="../icons/user-tie-solid-full.svg" class="input-icon">
                        </div>
                    </div>
                </div>

                <div class="section-title"><img src="../icons/utensils-solid-full.svg"><span>Daily Schedule</span></div>

                <div class="meal-grid" style="display:block; width:100%;">

                    <div class="meal-row" style="display:block; width:100%; margin-bottom:20px; float:none; clear:both;">
                        <div class="meal-label" style="display:flex; align-items:center; gap:10px; width:100%; padding-top:0; margin-bottom:8px;"><img src="../icons/sun-solid-full.svg" alt="sun" width="20"
                                style="filter: brightness(0) saturate(100%) invert(67%) sepia(87%) saturate(2159%) hue-rotate(352deg) brightness(105%) contrast(93%);">
                            Wake Up</div>
                        <div class="meal-input-area" style="display:block; width:100%;">
                            <textarea name="wake_up" class="form-input" style="resize:vertical; min-height:100px; width:100%;"><?= htmlspecialchars($val_wakeup) ?></textarea>
                        </div>
                    </div>

                    <div class="meal-row" style="display:block; width:100%; margin-bottom:20px; float:none; clear:both;">
                        <div class="meal-label" style="display:flex; align-items:center; gap:10px; width:100%; padding-top:0; margin-bottom:8px;"><img src="../icons/dumbbell-solid-full.svg" alt="dumbbell" width="20"
                                style="filter: brightness(0) saturate(100%) invert(43%) sepia(61%) saturate(3025%) hue-rotate(205deg) brightness(101%) contrast(94%);">
                            Post Workout
                        </div>
                        <div class="meal-input-area" style="display:block; width:100%;">
                            <textarea name="post_workout"
                                class="form-input" style="resize:vertical; min-height:100px; width:100%;"><?= htmlspecialchars($val_postworkout) ?></textarea>
                        </div>
                    </div>

                    <div class="meal-row" style="display:block; width:100%; margin-bottom:20px; float:none; clear:both;">
                        <div class="meal-label" style="display:flex; align-items:center; gap:10px; width:100%; padding-top:0; margin-bottom:8px;"><img src="../icons/mug-hot-solid-full.svg" alt="mug-hot" width="20"
                                style="filter: brightness(0) saturate(100%) invert(42%) sepia(88%) saturate(1636%) hue-rotate(1deg) brightness(101%) contrast(92%);">
                            Breakfast
                        </div>
                        <div class="meal-input-area" style="display:block; width:100%;">
                            <textarea name="breakfast"
                                class="form-input" style="resize:vertical; min-height:100px; width:100%;"><?= htmlspecialchars($val_breakfast) ?></textarea>
                        </div>
                    </div>

                    <div class="meal-row" style="display:block; width:100%; margin-bottom:20px; float:none; clear:both;">
                        <div class="meal-label" style="display:flex; align-items:center; gap:10px; width:100%; padding-top:0; margin-bottom:8px;"><img src="../icons/bowl-rice-solid-full.svg" alt="bowl-rice" width="20"
                                style="filter: brightness(0) saturate(100%) invert(58%) sepia(62%) saturate(497%) hue-rotate(113deg) brightness(98%) contrast(85%);">
                            Lunch</div>
                        <div class="meal-input-area" style="display:block; width:100%;">
                            <textarea name="lunch" class="form-input" style="resize:vertical; min-height:100px; width:100%;"><?= htmlspecialchars($val_lunch) ?></textarea>
                        </div>
                    </div>

                    <div class="meal-row" style="display:block; width:100%; margin-bottom:20px; float:none; clear:both;">
                        <div class="meal-label" style="display:flex; align-items:center; gap:10px; width:100%; padding-top:0; margin-bottom:8px;"><img src="../icons/apple-whole-solid-full.svg" alt="apple-whole"
                                width="20"
                                style="filter: brightness(0) saturate(100%) invert(43%) sepia(50%) saturate(1476%) hue-rotate(319deg) brightness(92%) contrast(100%);">
                            Mid Meal
                        </div>
                        <div class="meal-input-area" style="display:block; width:100%;">
                            <textarea name="snack" class="form-input" style="resize:vertical; min-height:100px; width:100%;"><?= htmlspecialchars($val_snack) ?></textarea>
                        </div>
                    </div>

                    <div class="meal-row" style="display:block; width:100%; margin-bottom:20px; float:none; clear:both;">
                        <div class="meal-label" style="display:flex; align-items:center; gap:10px; width:100%; padding-top:0; margin-bottom:8px;"><img src="../icons/moon-solid-full.svg" alt="moon" width="20"
                                style="filter: brightness(0) saturate(100%) invert(41%) sepia(88%) saturate(1661%) hue-rotate(224deg) brightness(99%) contrast(91%);">
                            Dinner</div>
                        <div class="meal-input-area" style="display:block; width:100%;">
                            <textarea name="dinner" class="form-input" style="resize:vertical; min-height:100px; width:100%;"><?= htmlspecialchars($val_dinner) ?></textarea>
                        </div>
                    </div>

                    <div class="meal-row" style="display:block; width:100%; margin-bottom:20px; float:none; clear:both;">
                        <div class="meal-label" style="display:flex; align-items:center; gap:10px; width:100%; padding-top:0; margin-bottom:8px;"><img src="../icons/bed-solid-full.svg" alt="bed" width="20"
                                style="filter: brightness(0) saturate(100%) invert(43%) sepia(35%) saturate(3015%) hue-rotate(231deg) brightness(96%) contrast(99%);">
                            Pre-Sleep</div>
                        <div class="meal-input-area" style="display:block; width:100%;">
                            <textarea name="pre_sleep"
                                class="form-input" style="resize:vertical; min-height:100px; width:100%;"><?= htmlspecialchars($val_presleep) ?></textarea>
                        </div>
                    </div>

                    <div class="meal-row full-width" style="display:block; width:100%; margin-bottom:20px; float:none; clear:both;">
                        <div class="meal-label" style="display:flex; align-items:center; gap:10px; width:100%; padding-top:0; margin-bottom:8px;"><img src="../icons/list-check-solid-full.svg" alt="list-check"
                                width="20"
                                style="filter: brightness(0) saturate(100%) invert(47%) sepia(13%) saturate(382%) hue-rotate(178deg) brightness(94%) contrast(90%);">
                            Guidelines
                        </div>
                        <div class="meal-input-area" style="display:block; width:100%;">
                            <textarea name="guidelines"
                                class="form-input" style="resize:vertical; min-height:100px; width:100%;"><?= htmlspecialchars($val_guidelines) ?></textarea>
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
                        Save Changes
                    </button>
                </div>

            </form>
        </div>
    </div>
    </div><!-- /.phase-form-col -->

    </div><!-- /.phase-split -->

    <div id="successToast" class="success-toast" aria-hidden="true">
        <div class="success-card">
            <i class="fa-solid fa-circle-check" style="font-size:24px; color:#10B981;"></i>
            <div>
                <h3>Plan Updated!</h3>
                <p>Changes saved successfully.</p>
            </div>
        </div>
    </div>

    <script>
        // ---- Reference pane: swap the preview iframe to whichever phase is picked ----
        (function () {
            const sel = document.getElementById('refPhaseSelect');
            const frame = document.getElementById('refPhaseFrame');
            if (sel && frame) {
                sel.addEventListener('change', function () {
                    frame.src = 'print_combined_plan.php?id=' + encodeURIComponent(this.value) + '&single=1';
                });
            }
        })();

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
            if (!confirm('Replace the current meal details with the "' + t.template_name + '" template? You can still edit before saving.')) return;
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
            if (msg) { msg.textContent = '✓ Filled ' + n + ' field(s). Edit anything, then Save Changes.'; msg.style.color = '#059669'; }
        }

        document.addEventListener('DOMContentLoaded', function () {
            const isSuccess = "<?= $success ?>";
            if (isSuccess === "updated") {
                document.getElementById('successToast').classList.add('visible');
                setTimeout(() => { window.location.href = 'diet_plan_details.php?id=<?= $plan_id ?>'; }, 1500);
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

        // Force the Daily Schedule meal fields to stack one below the other.
        // Runs last so nothing in the stylesheet can override it.
        (function stackMealFields() {
            var grid = document.querySelector('.meal-grid');
            if (!grid) return;
            grid.style.setProperty('display', 'block', 'important');
            grid.style.setProperty('grid-template-columns', 'none', 'important');
            grid.style.setProperty('column-count', 'auto', 'important');
            grid.querySelectorAll('.meal-row').forEach(function (row) {
                row.style.setProperty('display', 'block', 'important');
                row.style.setProperty('width', '100%', 'important');
                row.style.setProperty('float', 'none', 'important');
                row.style.setProperty('margin', '0 0 20px 0', 'important');
            });
            grid.querySelectorAll('.meal-input-area').forEach(function (area) {
                area.style.setProperty('display', 'block', 'important');
                area.style.setProperty('width', '100%', 'important');
            });
            grid.querySelectorAll('textarea').forEach(function (ta) {
                ta.style.setProperty('width', '100%', 'important');
                ta.style.setProperty('resize', 'vertical', 'important');
                ta.style.setProperty('min-height', '100px', 'important');
            });
        })();
    </script>
</body>

</html>