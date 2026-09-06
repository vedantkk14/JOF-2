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
$search_name = $client_name . "%";
$stmt_hist = $conn->prepare($history_sql);
$stmt_hist->bind_param("s", $search_name);
$stmt_hist->execute();
$all_phases = $stmt_hist->get_result();

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
    <link rel="stylesheet" href="../static/root.css">
    <!-- <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"> -->

</head>

<body class="page-create_diet_plan">

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

                <div class="meal-grid">

                    <div class="meal-row">
                        <div class="meal-label"><img src="../icons/sun-solid-full.svg" alt="sun" width="20"
                                style="filter: brightness(0) saturate(100%) invert(67%) sepia(87%) saturate(2159%) hue-rotate(352deg) brightness(105%) contrast(93%);">
                            Wake Up</div>
                        <div class="meal-input-area">
                            <textarea name="wake_up" class="form-input"><?= htmlspecialchars($val_wakeup) ?></textarea>
                        </div>
                    </div>

                    <div class="meal-row">
                        <div class="meal-label"><img src="../icons/dumbbell-solid-full.svg" alt="dumbbell" width="20"
                                style="filter: brightness(0) saturate(100%) invert(43%) sepia(61%) saturate(3025%) hue-rotate(205deg) brightness(101%) contrast(94%);">
                            Post Workout
                        </div>
                        <div class="meal-input-area">
                            <textarea name="post_workout"
                                class="form-input"><?= htmlspecialchars($val_postworkout) ?></textarea>
                        </div>
                    </div>

                    <div class="meal-row">
                        <div class="meal-label"><img src="../icons/mug-hot-solid-full.svg" alt="mug-hot" width="20"
                                style="filter: brightness(0) saturate(100%) invert(42%) sepia(88%) saturate(1636%) hue-rotate(1deg) brightness(101%) contrast(92%);">
                            Breakfast
                        </div>
                        <div class="meal-input-area">
                            <textarea name="breakfast"
                                class="form-input"><?= htmlspecialchars($val_breakfast) ?></textarea>
                        </div>
                    </div>

                    <div class="meal-row">
                        <div class="meal-label"><img src="../icons/bowl-rice-solid-full.svg" alt="bowl-rice" width="20"
                                style="filter: brightness(0) saturate(100%) invert(58%) sepia(62%) saturate(497%) hue-rotate(113deg) brightness(98%) contrast(85%);">
                            Lunch</div>
                        <div class="meal-input-area">
                            <textarea name="lunch" class="form-input"><?= htmlspecialchars($val_lunch) ?></textarea>
                        </div>
                    </div>

                    <div class="meal-row">
                        <div class="meal-label"><img src="../icons/apple-whole-solid-full.svg" alt="apple-whole"
                                width="20"
                                style="filter: brightness(0) saturate(100%) invert(43%) sepia(50%) saturate(1476%) hue-rotate(319deg) brightness(92%) contrast(100%);">
                            Mid Meal
                        </div>
                        <div class="meal-input-area">
                            <textarea name="snack" class="form-input"><?= htmlspecialchars($val_snack) ?></textarea>
                        </div>
                    </div>

                    <div class="meal-row">
                        <div class="meal-label"><img src="../icons/moon-solid-full.svg" alt="moon" width="20"
                                style="filter: brightness(0) saturate(100%) invert(41%) sepia(88%) saturate(1661%) hue-rotate(224deg) brightness(99%) contrast(91%);">
                            Dinner</div>
                        <div class="meal-input-area">
                            <textarea name="dinner" class="form-input"><?= htmlspecialchars($val_dinner) ?></textarea>
                        </div>
                    </div>

                    <div class="meal-row">
                        <div class="meal-label"><img src="../icons/bed-solid-full.svg" alt="bed" width="20"
                                style="filter: brightness(0) saturate(100%) invert(43%) sepia(35%) saturate(3015%) hue-rotate(231deg) brightness(96%) contrast(99%);">
                            Pre-Sleep</div>
                        <div class="meal-input-area">
                            <textarea name="pre_sleep"
                                class="form-input"><?= htmlspecialchars($val_presleep) ?></textarea>
                        </div>
                    </div>

                    <div class="meal-row full-width">
                        <div class="meal-label"><img src="../icons/list-check-solid-full.svg" alt="list-check"
                                width="20"
                                style="filter: brightness(0) saturate(100%) invert(47%) sepia(13%) saturate(382%) hue-rotate(178deg) brightness(94%) contrast(90%);">
                            Guidelines
                        </div>
                        <div class="meal-input-area">
                            <textarea name="guidelines"
                                class="form-input"><?= htmlspecialchars($val_guidelines) ?></textarea>
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
    </script>
</body>

</html>