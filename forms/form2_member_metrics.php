<?php
session_start();
require '../config.php';

// 1. Check if admin is logged in (public users can also access this page)
$is_admin = isset($_SESSION['user_id']);

// Enforce sequential access
if (!isset($_SESSION['allowed_step']) || $_SESSION['allowed_step'] < 2) {
    header("Location: form1_add_member.php");
    exit;
}

// 2. Flow Check (Ensure they came from Step 1)
if (!isset($_SESSION['new_member_id'])) {
    header("Location: form1_add_member.php");
    exit;
}

$member_id = $_SESSION['new_member_id'];
$error_msg = '';

// Guard: verify this member actually exists (session could be stale from a previous flow)
$chk = $conn->prepare("SELECT id FROM members WHERE id = ? LIMIT 1");
$chk->bind_param("i", $member_id);
$chk->execute();
$chk->store_result();
if ($chk->num_rows === 0) {
    // Stale session — clear it and restart
    unset($_SESSION['new_member_id']);
    $chk->close();
    header("Location: form1_add_member.php");
    exit;
}
$chk->close();

// Pre-fill from existing metrics row if the user is going back
$mood = '';
$sleep = '';
$hunger = '';
$energy = '';

$existing = $conn->prepare("SELECT mood, sleep_quality, hunger_craving, energy_level FROM metrics WHERE member_id = ? ORDER BY id DESC LIMIT 1");
$existing->bind_param("i", $member_id);
$existing->execute();
$ex_row = $existing->get_result()->fetch_assoc();
$existing->close();
if ($ex_row) {
    $mood = $ex_row['mood'];
    $sleep = $ex_row['sleep_quality'];
    $hunger = $ex_row['hunger_craving'];
    $energy = $ex_row['energy_level'];
}

// 3. Handle Form Submission
if ($_SERVER["REQUEST_METHOD"] == "POST") {

    $mood = $_POST['mood'] ?? '';
    $sleep = $_POST['sleep_quality'] ?? '';
    $hunger = $_POST['hunger_cravings'] ?? '';
    $energy = $_POST['energy_levels'] ?? '';

    if ($mood === '' || $sleep === '' || $hunger === '' || $energy === '') {
        $error_msg = "All fields are required.";
    } else {
        if ($ex_row) {
            // UPDATE existing row
            $sql = "UPDATE metrics SET mood=?, sleep_quality=?, hunger_craving=?, energy_level=? WHERE member_id=?";
            if ($stmt = $conn->prepare($sql)) {
                $stmt->bind_param("iiiii", $mood, $sleep, $hunger, $energy, $member_id);
                if ($stmt->execute()) {
                    $_SESSION['allowed_step'] = max($_SESSION['allowed_step'] ?? 2, 3);
                    header("Location: form3_member_measurements.php");
                    exit;
                } else {
                    $error_msg = "Failed to update metrics: " . $stmt->error;
                }
                $stmt->close();
            }
        } else {
            // INSERT new row
            $sql = "INSERT INTO metrics (member_id, mood, sleep_quality, hunger_craving, energy_level) VALUES (?, ?, ?, ?, ?)";
            if ($stmt = $conn->prepare($sql)) {
                $stmt->bind_param("iiiii", $member_id, $mood, $sleep, $hunger, $energy);
                if ($stmt->execute()) {
                    $_SESSION['allowed_step'] = max($_SESSION['allowed_step'] ?? 2, 3);
                    header("Location: form3_member_measurements.php");
                    exit;
                } else {
                    $error_msg = "Failed to save metrics: " . $stmt->error;
                }
                $stmt->close();
            } else {
                $error_msg = "Database Error: " . $conn->error;
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>JOF India | Health Metrics</title>
    <link rel="stylesheet" href="../static/root.css">
    <!-- <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"> -->

</head>

<body class="page-metrics">

    <div class="container">

        <div class="card-header">
            <div class="brand-area">
                <img src="../icons/logo-dark(1).png" class="brand-logo" alt="JOF">
                <div class="brand-text">
                    <h2>Health Metrics</h2>
                    <p>Track your daily health indicators.</p>
                </div>
            </div>
        </div>

        <div class="stepper-container">
            <div class="stepper stepper-narrow">
                <div class="step-item">
                    <div class="step-circle">1</div>
                    <span class="step-label">Health</span>
                </div>
                <div class="step-item active">
                    <div class="step-circle">2</div>
                    <span class="step-label">Metrics</span>
                </div>
                <div class="step-item">
                    <div class="step-circle">3</div>
                    <span class="step-label">Measurements</span>
                </div>
                <div class="step-item">
                    <div class="step-circle">4</div>
                    <span class="step-label">Payment</span>
                </div>

            </div>
        </div>


        <form action="" method="POST">
            <div class="card-body">

                <?php if (!empty($error_msg)): ?>
                    <div class="alert-error"><?php echo $error_msg; ?></div>
                    <?php
                endif; ?>


                <div class="form-grid">

                    <div class="input-group full-width">
                        <h3 class="section-title">
                            <img src="../icons/chart-line-solid-full.svg" class="fa-solid fa-chart-line mr-10">
                            Daily Health Metrics (Rate 1-10)
                        </h3>
                    </div>

                    <div class="input-group">
                        <label>Mood *</label>
                        <div class="input-wrapper">
                            <input type="number" name="mood" class="form-input" placeholder="1-10" min="1" max="10"
                                value="<?php echo htmlspecialchars($mood); ?>" required>
                            <img src="../icons/smile-solid-full.svg" class="fa-solid fa-smile input-icon icon-yellow">
                        </div>
                    </div>

                    <div class="input-group">
                        <label>Sleep Quality *</label>
                        <div class="input-wrapper">
                            <input type="number" name="sleep_quality" class="form-input" placeholder="1-10" min="1"
                                max="10" value="<?php echo htmlspecialchars($sleep); ?>" required>
                            <img src="../icons/moon-solid-full.svg" class="fa-solid fa-moon input-icon icon-purple">
                        </div>
                    </div>

                    <div class="input-group">
                        <label>Hunger & Cravings *</label>
                        <div class="input-wrapper">
                            <input type="number" name="hunger_cravings" class="form-input" placeholder="1-10" min="1"
                                max="10" value="<?php echo htmlspecialchars($hunger); ?>" required>
                            <img src="../icons/utensils-solid-full.svg"
                                class="fa-solid fa-utensils input-icon icon-red">
                        </div>
                    </div>

                    <div class="input-group">
                        <label>Energy Levels *</label>
                        <div class="input-wrapper">
                            <input type="number" name="energy_levels" class="form-input" placeholder="1-10" min="1"
                                max="10" value="<?php echo htmlspecialchars($energy); ?>" required>
                            <img src="../icons/bolt-solid-full.svg" class="fa-solid fa-bolt input-icon icon-green">
                        </div>
                    </div>

                </div>

            </div>

            <div class="card-footer footer-spaced flex-between">
                <?php if ($is_admin): ?>
                    <a href="form1_add_member.php?back=1" class="btn btn-secondary btn-border">Back to
                        member info</a>
                    <?php
                else: ?>
                    <a href="form1_add_member.php?back=1" class="btn btn-secondary btn-border">Back to
                        Health Info</a>
                    <?php
                endif; ?>

                <button type="submit" class="btn btn-primary no-border ml-auto">
                    Continue to Measurements →
                </button>
            </div>
        </form>


    </div>

    <script>
        function replaceSVG() {
            var images = document.querySelectorAll('img.fa-solid, img.fa-regular, img[class*="fa-"]');
            images.forEach(function (img) {
                if (img.classList.contains('svg-replaced')) return;
                img.classList.add('svg-replaced');

                var imgID = img.id;
                var imgClass = img.className;
                var imgURL = img.src;

                if (!imgURL.endsWith('.svg')) return;

                fetch(imgURL)
                    .then(response => response.text())
                    .then(text => {
                        var parser = new DOMParser();
                        var xmlDoc = parser.parseFromString(text, "text/xml");
                        var svg = xmlDoc.getElementsByTagName('svg')[0];

                        if (!svg) return;

                        if (typeof imgID !== 'undefined' && imgID !== '') {
                            svg.setAttribute('id', imgID);
                        }
                        if (typeof imgClass !== 'undefined' && imgClass !== '') {
                            svg.setAttribute('class', imgClass + ' replaced-svg');
                        }

                        svg.removeAttribute('xmlns:a');
                        svg.removeAttribute('width');
                        svg.removeAttribute('height');

                        var paths = svg.querySelectorAll('path');
                        paths.forEach(function (path) {
                            path.setAttribute('fill', 'currentColor');
                        });

                        img.parentNode.replaceChild(svg, img);
                    })
                    .catch(err => console.error('Error fetching SVG:', err));
            });
        }

        replaceSVG();

        var observer = new MutationObserver(function (mutations) {
            var shouldRun = false;
            mutations.forEach(function (mutation) {
                if (mutation.addedNodes.length) {
                    shouldRun = true;
                }
            });
            if (shouldRun) replaceSVG();
        });

        observer.observe(document.body, { childList: true, subtree: true });
    </script>
</body>

</html>