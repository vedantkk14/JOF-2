<?php
require_once __DIR__ . '/../auth/auth_check.php';
require_role(['admin', 'trainer']);
require_once __DIR__ . '/../config.php';

$error = "";
$success = "";

if (!isset($_GET['id'])) {
    header("Location: diet-plans.php?tab=templates");
    exit;
}
$template_id = intval($_GET['id']);

$stmt = $conn->prepare("SELECT * FROM diet_plan_templates WHERE id = ?");
$stmt->bind_param("i", $template_id);
$stmt->execute();
$tpl = $stmt->get_result()->fetch_assoc();

if (!$tpl)
    die("Template not found.");

// --- Unpack the combined DB columns back into the 8 form fields (same regex as edit_diet_plan.php) ---
$db_breakfast = $tpl['breakfast'];
$val_wakeup = "";
$val_postworkout = "";
$val_breakfast = "";

if (preg_match('/\*\*WAKE\s*UP\s*(?:\([^)]*\))?\s*:?\s*\*\*\s*(.*?)(?=\n\n|💪|🍳|$)/su', $db_breakfast, $m)) {
    $val_wakeup = trim($m[1]);
}
if (preg_match('/\*\*POST\s*WORKOUT\s*(?:\([^)]*\))?\s*:?\s*\*\*\s*(.*?)(?=\n\n|🍳|$)/su', $db_breakfast, $m)) {
    $val_postworkout = trim($m[1]);
}
if (preg_match('/\*\*BREAKFAST\s*(?:\([^)]*\))?\s*:?\s*\*\*\s*(.*)/su', $db_breakfast, $m)) {
    $val_breakfast = trim($m[1]);
} else if (empty($val_wakeup) && empty($val_postworkout)) {
    $val_breakfast = trim($db_breakfast);
}

$val_lunch = preg_replace('/^.*?\*\*LUNCH\s*(?:\([^)]*\))?\s*:?\s*\*\*\s*\n?/su', '', $tpl['lunch']);
$val_lunch = trim($val_lunch);

$val_snack = preg_replace('/^.*?\*\*MID\s*MEAL\s*(?:\([^)]*\))?\s*:?\s*\*\*\s*\n?/su', '', $tpl['snack']);
$val_snack = trim($val_snack);

$db_dinner = $tpl['dinner'];
$val_dinner = "";
$val_presleep = "";
$val_guidelines = "";

if (preg_match('/\*\*DINNER\s*(?:\([^)]*\))?\s*:?\s*\*\*\s*(.*?)(?=\n\n|🌙|📝|$)/su', $db_dinner, $m)) {
    $val_dinner = trim($m[1]);
} else if (strpos($db_dinner, '**DINNER') === false && strpos($db_dinner, '**PRE-SLEEP') === false && strpos($db_dinner, '**GUIDELINES') === false) {
    $val_dinner = trim($db_dinner);
}
if (preg_match('/\*\*PRE-SLEEP\s*(?:\([^)]*\))?\s*:?\s*\*\*\s*(.*?)(?=\n\n|📝|$)/su', $db_dinner, $m)) {
    $val_presleep = trim($m[1]);
}
if (preg_match('/\*\*GUIDELINES\s*(?:\([^)]*\))?\s*:?\s*\*\*\s*(.*)/su', $db_dinner, $m)) {
    $val_guidelines = trim($m[1]);
}

// Handle Update
if ($_SERVER["REQUEST_METHOD"] == "POST") {

    $template_name = trim($_POST['template_name'] ?? '');
    $diet_type     = $_POST['diet_type'] ?? 'veg';
    $goal          = $_POST['goal'] ?? '';
    $duration      = $_POST['duration'] ?? 2;
    $calories      = $_POST['calories'] ?? 0;
    $trainer_name  = $_POST['trainer_name'] ?? '';

    $in_wakeup = trim($_POST['wake_up'] ?? '');
    $in_post   = trim($_POST['post_workout'] ?? '');
    $in_break  = trim($_POST['breakfast'] ?? '');

    $final_breakfast = "";
    if ($in_wakeup)
        $final_breakfast .= "🌅 **WAKE UP :**\n" . $in_wakeup . "\n\n";
    if ($in_post)
        $final_breakfast .= "💪 **POST WORKOUT:**\n" . $in_post . "\n\n";
    if ($in_break)
        $final_breakfast .= "🍳 **BREAKFAST:**\n" . $in_break;

    $final_lunch = trim($_POST['lunch'] ?? '');
    if ($final_lunch)
        $final_lunch = "🍛 **LUNCH :**\n" . $final_lunch;

    $final_snack = trim($_POST['snack'] ?? '');
    if ($final_snack)
        $final_snack = "🍎 **MID MEAL :**\n" . $final_snack;

    $in_dinner = trim($_POST['dinner'] ?? '');
    $in_pre    = trim($_POST['pre_sleep'] ?? '');
    $in_guide  = trim($_POST['guidelines'] ?? '');

    $final_dinner = "";
    if ($in_dinner)
        $final_dinner .= "🍲 **DINNER :**\n" . $in_dinner . "\n\n";
    if ($in_pre)
        $final_dinner .= "🌙 **PRE-SLEEP:**\n" . $in_pre . "\n\n";
    if ($in_guide)
        $final_dinner .= "📝 **GUIDELINES:**\n" . $in_guide;

    if (empty($template_name) || empty($goal)) {
        $error = "Please fill in the Template Name and Goal.";
    } else {
        $sql = "UPDATE diet_plan_templates SET template_name=?, diet_type=?, goal=?, duration=?, calories=?, trainer_name=?, breakfast=?, lunch=?, snack=?, dinner=? WHERE id=?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("sssiisssssi", $template_name, $diet_type, $goal, $duration, $calories, $trainer_name, $final_breakfast, $final_lunch, $final_snack, $final_dinner, $template_id);
        if ($stmt->execute()) {
            $success = "updated";
            // Reflect the just-saved values
            $tpl['template_name'] = $template_name;
            $tpl['diet_type'] = $diet_type;
            $tpl['goal'] = $goal;
            $tpl['duration'] = $duration;
            $tpl['calories'] = $calories;
            $tpl['trainer_name'] = $trainer_name;
            $val_wakeup = $in_wakeup;
            $val_postworkout = $in_post;
            $val_breakfast = $in_break;
            $val_lunch = trim($_POST['lunch'] ?? '');
            $val_snack = trim($_POST['snack'] ?? '');
            $val_dinner = $in_dinner;
            $val_presleep = $in_pre;
            $val_guidelines = $in_guide;
        } else {
            $error = "Could not update template: " . $stmt->error;
        }
    }
}

$trainers_result = $conn->query("SELECT id, full_name FROM trainers WHERE is_active = 1 ORDER BY full_name ASC");
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" size="16x16" href="../icons/favicon-dark-logo.png" type="image/png">
    <title>Edit Diet Template | JOF INDIA</title>
    <link rel="stylesheet" href="../static/root.css">
</head>

<body class="page-create_diet_plan">

    <div class="container">
        <div class="card-header">
            <div class="brand-area">
                <img src="../icons/logo-dark(1).png" class="brand-logo" alt="JOF">
                <div class="brand-text">
                    <h2>Edit Diet Template</h2>
                    <p>Modify <b><?= htmlspecialchars($tpl['template_name']) ?></b>.</p>
                </div>
            </div>
            <a href="diet-plans.php?tab=templates" class="close-btn"><img src="../icons/xmark-solid-full.svg" alt="close"></a>
        </div>

        <?php if ($error): ?>
            <div
                style="background:#fee2e2; color:#b91c1c; padding:12px; margin:0 24px 10px; border-radius:8px; text-align:center;">
                <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <div class="card-body">
            <form method="POST" action="">

                <div class="section-title"><img src="../icons/clipboard-user-solid-full.svg"><span>Template
                        Details</span></div>

                <div class="form-grid">
                    <div class="input-group full-width">
                        <label>Template Name *</label>
                        <div class="input-wrapper">
                            <input type="text" name="template_name" class="form-input"
                                value="<?= htmlspecialchars($tpl['template_name']) ?>" required>
                            <img src="../icons/clipboard-list-solid-full.svg" class="input-icon" alt="template">
                        </div>
                    </div>

                    <div class="input-group">
                        <label>Goal *</label>
                        <div class="input-wrapper">
                            <input type="text" name="goal" class="form-input"
                                value="<?= htmlspecialchars($tpl['goal']) ?>" required>
                            <img src="../icons/bullseye-solid-full.svg" class="input-icon">
                        </div>
                    </div>
                    <div class="input-group">
                        <label>Diet Type *</label>
                        <div class="input-wrapper">
                            <select name="diet_type" class="form-input" required>
                                <?php foreach (['veg' => 'Vegetarian', 'nonveg' => 'Non-Veg', 'vegan' => 'Vegan'] as $v => $label): ?>
                                    <option value="<?= $v ?>" <?= $tpl['diet_type'] === $v ? 'selected' : '' ?>><?= $label ?></option>
                                <?php endforeach; ?>
                            </select>
                            <img src="../icons/leaf-solid-full.svg" class="input-icon">
                        </div>
                    </div>
                    <div class="input-group">
                        <label>Calories *</label>
                        <div class="input-wrapper">
                            <input type="number" name="calories" class="form-input"
                                value="<?= htmlspecialchars($tpl['calories']) ?>" required>
                            <img src="../icons/fire-solid-full.svg" class="input-icon">
                        </div>
                    </div>
                    <div class="input-group">
                        <label>Duration (in weeks) *</label>
                        <div class="input-wrapper">
                            <input type="number" name="duration" class="form-input"
                                value="<?= htmlspecialchars($tpl['duration']) ?>" required>
                            <img src="../icons/calendar-day-solid-full.svg" class="input-icon">
                        </div>
                    </div>
                    <div class="input-group">
                        <label>Trainer</label>
                        <div class="input-wrapper">
                            <input type="text" name="trainer_name" class="form-input"
                                value="<?= htmlspecialchars($tpl['trainer_name']) ?>">
                            <img src="../icons/user-tie-solid-full.svg" class="input-icon">
                        </div>
                    </div>
                </div>

                <div class="section-title"><img src="../icons/utensils-solid-full.svg"><span>Daily Schedule</span></div>

                <div class="meal-grid">
                    <div class="meal-row">
                        <div class="meal-label"><img src="../icons/sun-solid-full.svg" alt="sun" width="20"> Wake Up</div>
                        <div class="meal-input-area">
                            <textarea name="wake_up" class="form-input"><?= htmlspecialchars($val_wakeup) ?></textarea>
                        </div>
                    </div>
                    <div class="meal-row">
                        <div class="meal-label"><img src="../icons/dumbbell-solid-full.svg" alt="dumbbell" width="20">
                            Post Workout</div>
                        <div class="meal-input-area">
                            <textarea name="post_workout" class="form-input"><?= htmlspecialchars($val_postworkout) ?></textarea>
                        </div>
                    </div>
                    <div class="meal-row">
                        <div class="meal-label"><img src="../icons/mug-hot-solid-full.svg" alt="mug-hot" width="20">
                            Breakfast</div>
                        <div class="meal-input-area">
                            <textarea name="breakfast" class="form-input"><?= htmlspecialchars($val_breakfast) ?></textarea>
                        </div>
                    </div>
                    <div class="meal-row">
                        <div class="meal-label"><img src="../icons/bowl-rice-solid-full.svg" alt="bowl-rice" width="20">
                            Lunch</div>
                        <div class="meal-input-area">
                            <textarea name="lunch" class="form-input"><?= htmlspecialchars($val_lunch) ?></textarea>
                        </div>
                    </div>
                    <div class="meal-row">
                        <div class="meal-label"><img src="../icons/apple-whole-solid-full.svg" alt="apple-whole"
                                width="20"> Mid Meal</div>
                        <div class="meal-input-area">
                            <textarea name="snack" class="form-input"><?= htmlspecialchars($val_snack) ?></textarea>
                        </div>
                    </div>
                    <div class="meal-row">
                        <div class="meal-label"><img src="../icons/moon-solid-full.svg" alt="moon" width="20"> Dinner</div>
                        <div class="meal-input-area">
                            <textarea name="dinner" class="form-input"><?= htmlspecialchars($val_dinner) ?></textarea>
                        </div>
                    </div>
                    <div class="meal-row">
                        <div class="meal-label"><img src="../icons/bed-solid-full.svg" alt="bed" width="20"> Pre-Sleep</div>
                        <div class="meal-input-area">
                            <textarea name="pre_sleep" class="form-input"><?= htmlspecialchars($val_presleep) ?></textarea>
                        </div>
                    </div>
                    <div class="meal-row full-width">
                        <div class="meal-label"><img src="../icons/list-check-solid-full.svg" alt="list-check"
                                width="20"> Guidelines</div>
                        <div class="meal-input-area">
                            <textarea name="guidelines" class="form-input"><?= htmlspecialchars($val_guidelines) ?></textarea>
                        </div>
                    </div>
                </div>

                <div
                    style="display:flex; justify-content: space-between; align-items:center; padding: 20px 0 5px 0; gap:15px;">
                    <a href="diet-plans.php?tab=templates" class="btn btn-secondary"
                        style="border: 1px solid #D1D5DB; background: transparent; text-decoration:none; display:inline-flex; align-items:center; gap:8px;">
                        Cancel
                    </a>
                    <button type="submit" class="btn-new-phase">Save Changes</button>
                </div>

            </form>
        </div>
    </div>

    <div id="successToast" class="success-toast" aria-hidden="true">
        <div class="success-card">
            <i class="fa-solid fa-circle-check" style="font-size:24px; color:#10B981;"></i>
            <div>
                <h3>Template Updated!</h3>
                <p>Changes saved successfully.</p>
            </div>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            if ("<?= $success ?>" === "updated") {
                document.getElementById('successToast').classList.add('visible');
                setTimeout(() => { window.location.href = 'diet-plans.php?tab=templates'; }, 1500);
            }
        });

        setInterval(function () {
            fetch('../auth/keep_alive.php')
                .then(res => res.json())
                .then(data => { if (data.status === 'dead') window.location.href = '../index.php'; })
                .catch(() => { });
        }, 600000);
    </script>
</body>

</html>
