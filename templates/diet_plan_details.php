<?php
require_once __DIR__ . '/../auth/auth_check.php';
require_role(['admin', 'trainer']);
require_once __DIR__ . '/../config.php';

if (!isset($_GET['id'])) {
    die("Error: No plan selected.");
}

$plan_id = intval($_GET['id']);

// 1. Fetch Current Plan Details
$sql = "SELECT * FROM diet_plans WHERE id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $plan_id);
$stmt->execute();
$plan = $stmt->get_result()->fetch_assoc();

if (!$plan) {
    die("Plan not found.");
}

// 2. Extract Client Name (Logic: "Client Name - Phase")
$name_parts = explode(" - ", $plan['plan_name']);
$client_name = trim($name_parts[0]);
$current_phase = isset($name_parts[1]) ? trim($name_parts[1]) : 'Unknown Phase';

// 3. Fetch ALL Phases for this Client (For the Dropdown)
$history_sql = "SELECT id, plan_name FROM diet_plans WHERE plan_name LIKE ? ORDER BY id ASC";
$search_name = $client_name . " - %";
$stmt_hist = $conn->prepare($history_sql);
$stmt_hist->bind_param("s", $search_name);
$stmt_hist->execute();
$all_phases = $stmt_hist->get_result();

// Helper to format text — orange sub-headings + orange time-of-day badges
function formatDietText($text)
{
    if (empty($text) || trim($text) === '')
        return '<span class="text-muted">Not specified</span>';
    $text = str_replace(["\r\n", "\r"], "\n", $text);
    // single time (6 AM, 1130 AM, 8:30 PM) OR range (5pm-6pm, 6:30–7:00 AM, 6-7 PM)
    $timeRe = '/^('
        . '(?:\d{3,4}|\d{1,2}(?:[:.]\d{2})?)\s*(?:[AP]\.?M\.?)?\s*[-\x{2013}\x{2014}~]\s*(?:\d{3,4}|\d{1,2}(?:[:.]\d{2})?)\s*[AP]\.?M\.?'
        . '|(?:\d{3,4}|\d{1,2}(?:[:.]\d{2})?)\s*[AP]\.?M\.?'
        . ')\b[\s\-\x{2013}\x{2014}:]*(.*)$/iu';
    $niceTime = function ($raw) {
        $t = preg_replace('/\s*([ap])\.?\s*m\.?/iu', ' $1m', $raw);
        $t = preg_replace('/\s*[-\x{2013}\x{2014}~]\s*/u', ' - ', $t);
        return strtoupper(preg_replace('/\s+/', ' ', trim($t)));
    };

    $out = [];
    $lastWasHeading = false;
    $inNumberedList = false;
    foreach (explode("\n", $text) as $line) {
        $line = trim($line);
        // A stray blank line (accidental extra Enter) has no effect at all — never rendered,
        // never treated as "end of point". Only a new "N. " line closes the previous point.
        if ($line === '') { continue; }

        if (preg_match('/\*\*\s*(.+?)\s*\*\*/', $line, $sm)) {
            $out[] = '<div class="meal-subhead">' . htmlspecialchars(rtrim(trim($sm[1]), ' :') . ' :') . '</div>';
            $lastWasHeading = true;
            $inNumberedList = false;
            continue;
        }
        $line = str_replace('**', '', $line);

        if (preg_match($timeRe, $line, $tm)) {
            $rest  = trim($tm[2]);
            $badge = '<span class="time-badge">' . htmlspecialchars($niceTime(trim($tm[1]))) . '</span>'
                   . ($rest !== '' ? ' ' . htmlspecialchars($rest) : '');
            if ($lastWasHeading) {
                $i = count($out) - 1;
                $out[$i] = preg_replace('#</div>$#', ' ' . $badge . '</div>', $out[$i]);
            } else {
                $out[] = $badge;
            }
            $lastWasHeading = false;
            $inNumberedList = false;
            continue;
        }

        // Numbered point ("1. ...", "2) ..."). The blank line goes ONLY before a new numbered
        // point — never after the point just written — so any line that follows (numbered or
        // not) that isn't a new "N. " continues that point with no gap, even past a stray blank.
        if (preg_match('/^\d+[.)]\s+/', $line)) {
            $out[] = ($inNumberedList ? '<br>' : '') . htmlspecialchars($line);
            $inNumberedList = true;
            $lastWasHeading = false;
            continue;
        }

        // Plain content line — if a numbered point is open, this continues it (no gap)
        $out[] = htmlspecialchars($line);
        $lastWasHeading = false;
    }
    return implode("<br>\n", $out);
}

// Visual Theme Logic
$goal = strtolower($plan['goal']);
$theme = "green";
$icon = "leaf-solid-full.svg";
if (strpos($goal, 'weight') !== false || strpos($goal, 'fat') !== false) {
    $theme = "orange";
    $icon = "fire-solid-full.svg";
} elseif (strpos($goal, 'muscle') !== false) {
    $theme = "purple";
    $icon = "dumbbell-solid-full.svg";
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <link rel="icon" size="16x16" href="../icons/favicon-dark-logo.png" type="image/png">
    <title><?= htmlspecialchars($plan['plan_name']) ?> | JOF Fitness</title>
    <!-- <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"> -->
    <link rel="stylesheet" href="../static/root.css">

</head>

<body class="page-diet_plan_details">
    <button class="mobile-toggle" id="mobileToggle">
        <img src="../icons/bars-solid-full.svg" class="fa-solid fa-bars" style="filter: brightness(0) invert(1);">
    </button>

    <button class="toggle-sidebar-btn" id="toggleBtn">
        <img src="../icons/chevron-left-solid-full.svg" class="fa-solid fa-chevron-left"
            style="filter: brightness(0) invert(1);">
    </button>

    <div class="dashboard-container">

        <?php include 'sidebar.php'; ?>

        <main class="main-content">

            <div class="page-header">
                <div class="header-left">
                    <h1><?= htmlspecialchars($client_name) ?></h1>
                    <p class="plan-desc">Current Phase: <b><?= htmlspecialchars($current_phase) ?></b></p>
                </div>

                <div class="header-actions"
                    style="display:flex; flex-direction:column; align-items:flex-end; gap:10px;">
                    <div style="display:flex; align-items:center; gap:10px;">
                        <a href="print_combined_plan.php?id=<?= $plan['id'] ?>" target="_blank" class="btn-secondary">
                            <img src="../icons/print-solid-full.svg" alt="print" width="16"
                                style="filter: brightness(0) saturate(100%) invert(16%) sepia(10%) saturate(500%) hue-rotate(180deg);">
                            Print History
                        </a>
                        <a href="edit_diet_plan.php?id=<?= $plan['id'] ?>" class="btn-primary"
                            style="text-decoration:none; display:inline-flex; align-items:center; gap:8px;">
                            <img src="../icons/pen-solid-full.svg" alt="pen" width="16"
                                style="filter: brightness(0) invert(1);"> Edit Phase
                        </a>
                        <a href="add_new_phase.php?id=<?= $plan['id'] ?>" class="btn-primary"
                            style="text-decoration:none; display:inline-flex; align-items:center; gap:8px;">
                            <img src="../icons/plus-solid-full.svg" alt="pen" width="16"
                                style="filter: brightness(0) invert(1);"> Add New Phase
                        </a>
                    </div>
                    <div style="display:flex; align-items:center; gap:10px;">
                        <div class="phase-switcher" style="margin-bottom:0;">
                            <img src="../icons/layer-group-solid-full.svg" alt="layer-group" width="16"
                                style="filter: brightness(0) saturate(100%) invert(41%) sepia(96%) saturate(1636%) hue-rotate(346deg) brightness(97%) contrast(93%);">
                            <select class="phase-select" onchange="location = this.value;">
                                <?php while ($phase = $all_phases->fetch_assoc()):
                                    $p_parts = explode(" - ", $phase['plan_name']);
                                    $p_name = isset($p_parts[1]) ? $p_parts[1] : $phase['plan_name'];
                                    $selected = ($phase['id'] == $plan_id) ? 'selected' : '';
                                    ?>
                                    <option value="diet_plan_details.php?id=<?= $phase['id'] ?>" <?= $selected ?>>
                                        <?= htmlspecialchars($p_name) ?>
                                    </option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                        <a href="diet-plans.php" class="btn-secondary"
                            style="text-decoration:none; display:inline-flex; align-items:center; gap:8px; font-size:13px;">
                            <img src="../icons/arrow-left-solid-full.svg" alt="arrow-left" width="14"
                                style="filter: brightness(0) saturate(100%) invert(16%) sepia(10%) saturate(500%) hue-rotate(180deg);">
                            Back to Plans
                        </a>
                    </div>
                </div>
            </div>

            <div class="stats-container">
                <div class="stat-item">
                    <div class="icon-box <?= $theme ?>"><img src="../icons/<?= $icon ?>" alt="goal" width="20"
                            class="stat-icon"></div>
                    <div><span class="label">Goal</span><span class="value"><?= $plan['goal'] ?></span></div>
                </div>
                <div class="stat-item">
                    <div class="icon-box blue"><img src="../icons/user-tie-solid-full.svg" alt="user-tie" width="20"
                            class="stat-icon">
                    </div>
                    <div><span class="label">Trainer</span><span
                            class="value"><?= htmlspecialchars($plan['trainer_name']) ?></span></div>
                </div>
                <div class="stat-item">
                    <div class="icon-box <?= $theme ?>"><img src="../icons/utensils-solid-full.svg" alt="utensils"
                            width="20" class="stat-icon"></div>
                    <div><span class="label">Calories</span><span class="value"><?= $plan['calories'] ?> kcal</span>
                    </div>
                </div>
                <div class="stat-item">
                    <div class="icon-box <?= $theme ?>"><img src="../icons/clock-solid-full.svg" alt="clock" width="20"
                            class="stat-icon">
                    </div>
                    <div><span class="label">Duration</span><span class="value"><?= $plan['duration'] ?> Weeks</span>
                    </div>
                </div>
            </div>

            <div class="content-panel">
                <div class="panel-header">
                    <h2>Daily Schedule</h2><span class="tag">Phase: <?= $current_phase ?></span>
                </div>
                <div class="meal-list">
                    <div class="meal-row">
                        <div class="meal-time">
                            <div class="time-icon sun"><img src="../icons/sun-solid-full.svg" alt="sun" width="20">
                            </div><span class="meal-name">Morning</span>
                        </div>
                        <div class="meal-content"><?= formatDietText($plan['breakfast']) ?></div>
                    </div>
                    <div class="meal-row">
                        <div class="meal-time">
                            <div class="time-icon cloud"><img src="../icons/cloud-sun-solid-full.svg" alt="cloud-sun"
                                    width="20"></div><span class="meal-name">Lunch</span>
                        </div>
                        <div class="meal-content"><?= formatDietText($plan['lunch']) ?></div>
                    </div>
                    <div class="meal-row">
                        <div class="meal-time">
                            <div class="time-icon coffee"><img src="../icons/mug-hot-solid-full.svg" alt="mug-hot"
                                    width="20"></div><span class="meal-name">Snack</span>
                        </div>
                        <div class="meal-content"><?= formatDietText($plan['snack']) ?></div>
                    </div>
                    <div class="meal-row">
                        <div class="meal-time">
                            <div class="time-icon moon"><img src="../icons/moon-solid-full.svg" alt="moon" width="20">
                            </div><span class="meal-name">Night</span>
                        </div>
                        <div class="meal-content"><?= formatDietText($plan['dinner']) ?></div>
                    </div>
                </div>
            </div>

        </main>
    </div>

    <script>
        const toggleBtn = document.getElementById('toggleBtn');
        const mobileToggle = document.getElementById('mobileToggle');
        const body = document.body;

        if (toggleBtn) toggleBtn.addEventListener('click', () => body.classList.toggle('collapsed'));
        if (mobileToggle) mobileToggle.addEventListener('click', () => body.classList.toggle('sidebar-open'));

        // Dropdown Logic
        const dropdownItems = document.querySelectorAll('.nav-item-dropdown');
        dropdownItems.forEach(item => {
            const link = item.querySelector('.nav-link');
            const arrow = link ? link.querySelector('.nav-arrow') : null;
            if (arrow) {
                arrow.addEventListener('click', function (e) {
                    e.preventDefault();
                    e.stopPropagation();
                    dropdownItems.forEach(otherItem => {
                        if (otherItem !== item) otherItem.classList.remove('active');
                    });
                    item.classList.toggle('active');
                });
            }
        });
    </script>
</body>

</html>