<?php
session_start();
require '../config.php';

// This page is PUBLIC — no login check needed.
// It's a 3-step wizard: Step 1 (Health), Step 2 (Metrics), Step 3 (Measurements)

$step = isset($_GET['step']) ? intval($_GET['step']) : 1;
if ($step < 1 || $step > 3)
    $step = 1;

$error_msg = '';

// ===== STEP 1: PERSONAL INFO =====
if ($step === 1 && $_SERVER['REQUEST_METHOD'] === 'POST') {

    $full_name = trim($_POST['full_name'] ?? '');
    $phone_number = trim($_POST['phone_number'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $gender = $_POST['gender'] ?? '';
    $personal_training = $_POST['personal_training'] ?? 1;
    $age = $_POST['age'] ?? null;
    $height = $_POST['height'] ?? null;
    $weight = $_POST['weight'] ?? null;
    $medical_issues = trim($_POST['medical_issues'] ?? '');

    // Validation
    $errors = [];
    if (empty($full_name))
        $errors[] = "Full name is required.";
    elseif (!preg_match('/^[a-zA-Z]{2,}(\s[a-zA-Z]{1,})+$/', $full_name))
        $errors[] = "Full name must include first and last name.";
    if (empty($phone_number))
        $errors[] = "Phone number is required.";
    elseif (!preg_match('/^\d{10}$/', $phone_number))
        $errors[] = "Phone number must be exactly 10 digits.";
    if ($age === null || $age === '')
        $errors[] = "Age is required.";
    elseif (intval($age) <= 18)
        $errors[] = "Must be older than 18 years.";
    if ($height === null || $height === '')
        $errors[] = "Height is required.";
    elseif (floatval($height) <= 50)
        $errors[] = "Height must be greater than 50 cm.";
    if ($weight === null || $weight === '')
        $errors[] = "Weight is required.";
    elseif (floatval($weight) <= 10)
        $errors[] = "Weight must be greater than 10 kg.";

    if (!empty($errors)) {
        $error_msg = implode('<br>', $errors);
    } else {
        if (isset($_SESSION['public_register_id'])) {
            // UPDATE existing record
            $member_id = $_SESSION['public_register_id'];
            $sql = "UPDATE members SET full_name=?, phone_number=?, email=?, gender=?, personal_training=?, age=?, height=?, weight=?, medical_issues=? WHERE id=?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("ssssiiidsi", $full_name, $phone_number, $email, $gender, $personal_training, $age, $height, $weight, $medical_issues, $member_id);
            $stmt->execute();
            $stmt->close();
        } else {
            // INSERT new inactive member
            $membership_default = "Pending";
            $status_inactive = "inactive";
            $sql = "INSERT INTO members (full_name, phone_number, email, gender, membership, personal_training, age, height, weight, medical_issues, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("sssssiiidss", $full_name, $phone_number, $email, $gender, $membership_default, $personal_training, $age, $height, $weight, $medical_issues, $status_inactive);
            $stmt->execute();
            $_SESSION['public_register_id'] = $stmt->insert_id;
            $stmt->close();
        }
        header("Location: public_register.php?step=2");
        exit;
    }
}

// ===== STEP 2: METRICS =====
if ($step === 2) {
    if (!isset($_SESSION['public_register_id'])) {
        header("Location: public_register.php?step=1");
        exit;
    }
    $member_id = $_SESSION['public_register_id'];

    // Pre-fill
    $mood = $sleep = $hunger = $energy = '';
    $ex = $conn->prepare("SELECT mood, sleep_quality, hunger_craving, energy_level FROM metrics WHERE member_id = ? ORDER BY id DESC LIMIT 1");
    $ex->bind_param("i", $member_id);
    $ex->execute();
    $ex_row = $ex->get_result()->fetch_assoc();
    $ex->close();
    if ($ex_row) {
        $mood = $ex_row['mood'];
        $sleep = $ex_row['sleep_quality'];
        $hunger = $ex_row['hunger_craving'];
        $energy = $ex_row['energy_level'];
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $mood = $_POST['mood'] ?? '';
        $sleep = $_POST['sleep_quality'] ?? '';
        $hunger = $_POST['hunger_cravings'] ?? '';
        $energy = $_POST['energy_levels'] ?? '';

        if ($mood === '' || $sleep === '' || $hunger === '' || $energy === '') {
            $error_msg = "All fields are required.";
        } else {
            if ($ex_row) {
                $sql = "UPDATE metrics SET mood=?, sleep_quality=?, hunger_craving=?, energy_level=? WHERE member_id=?";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("iiiii", $mood, $sleep, $hunger, $energy, $member_id);
            } else {
                $sql = "INSERT INTO metrics (member_id, mood, sleep_quality, hunger_craving, energy_level) VALUES (?, ?, ?, ?, ?)";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("iiiii", $member_id, $mood, $sleep, $hunger, $energy);
            }
            $stmt->execute();
            $stmt->close();
            header("Location: public_register.php?step=3");
            exit;
        }
    }
}

// ===== STEP 3: MEASUREMENTS =====
$show_success = false;
if ($step === 3) {
    if (!isset($_SESSION['public_register_id'])) {
        header("Location: public_register.php?step=1");
        exit;
    }
    $member_id = $_SESSION['public_register_id'];

    $chest = $waist = $hip = $thigh = '';
    $ex_meas = $conn->prepare("SELECT chest_nipple_line, waist_navel_line, thigh_mid, hip_widest_part, front_view_image, back_view_image, side_view_image FROM member_measurements WHERE member_id = ? ORDER BY id DESC LIMIT 1");
    $ex_meas->bind_param("i", $member_id);
    $ex_meas->execute();
    $ex_meas_row = $ex_meas->get_result()->fetch_assoc();
    $ex_meas->close();
    if ($ex_meas_row) {
        $chest = $ex_meas_row['chest_nipple_line'];
        $waist = $ex_meas_row['waist_navel_line'];
        $thigh = $ex_meas_row['thigh_mid'];
        $hip = $ex_meas_row['hip_widest_part'];
    }

    function uploadImagePublic($fileInputName, $member_id)
    {
        if (!isset($_FILES[$fileInputName]) || $_FILES[$fileInputName]['error'] !== 0)
            return null;
        if ($_FILES[$fileInputName]['size'] > 2 * 1024 * 1024)
            return null;
        $allowedExts = ['jpg', 'jpeg', 'png'];
        $fileExt = strtolower(pathinfo($_FILES[$fileInputName]['name'], PATHINFO_EXTENSION));
        if (!in_array($fileExt, $allowedExts))
            return null;
        $uploadDir = "../uploads/progress_photos/";
        if (!is_dir($uploadDir))
            mkdir($uploadDir, 0777, true);
        $fileName = $fileInputName . "_" . $member_id . "_" . time() . "." . $fileExt;
        if (move_uploaded_file($_FILES[$fileInputName]['tmp_name'], $uploadDir . $fileName))
            return $fileName;
        return null;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $chest = $_POST['chest_nipple_line'] ?? '';
        $waist = $_POST['waist_navel_line'] ?? '';
        $thigh = $_POST['thigh_mid'] ?? '';
        $hip = $_POST['hip_widest_part'] ?? '';

        if ($chest === '' || $waist === '' || $thigh === '' || $hip === '') {
            $error_msg = "All measurement fields are required.";
        } else {
            $front_image = uploadImagePublic('front_view', $member_id);
            $back_image = uploadImagePublic('back_view', $member_id);
            $side_image = uploadImagePublic('side_view', $member_id);

            if ($ex_meas_row) {
                $final_front = $front_image ?? $ex_meas_row['front_view_image'];
                $final_back = $back_image ?? $ex_meas_row['back_view_image'];
                $final_side = $side_image ?? $ex_meas_row['side_view_image'];
                $sql = "UPDATE member_measurements SET chest_nipple_line=?, waist_navel_line=?, thigh_mid=?, hip_widest_part=?, front_view_image=?, back_view_image=?, side_view_image=? WHERE member_id=?";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("ddddsssi", $chest, $waist, $thigh, $hip, $final_front, $final_back, $final_side, $member_id);
            } else {
                $sql = "INSERT INTO member_measurements (member_id, chest_nipple_line, waist_navel_line, thigh_mid, hip_widest_part, front_view_image, back_view_image, side_view_image) VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("idddssss", $member_id, $chest, $waist, $thigh, $hip, $front_image, $back_image, $side_image);
            }
            $stmt->execute();
            $stmt->close();
            unset($_SESSION['public_register_id']);
            $show_success = true;
        }
    }
}

// Pre-fill step 1 data
$full_name = $phone_number = $email = $gender = $medical_issues = '';
$personal_training = 1;
$age = $height = $weight = '';
if ($step === 1 && isset($_SESSION['public_register_id'])) {
    $fetch = $conn->prepare("SELECT full_name, phone_number, email, gender, personal_training, age, height, weight, medical_issues FROM members WHERE id = ?");
    $fetch->bind_param("i", $_SESSION['public_register_id']);
    $fetch->execute();
    $row = $fetch->get_result()->fetch_assoc();
    $fetch->close();
    if ($row) {
        $full_name = $row['full_name'];
        $phone_number = $row['phone_number'];
        $email = $row['email'];
        $gender = $row['gender'] ?? '';
        $personal_training = $row['personal_training'];
        $age = $row['age'];
        $height = $row['height'];
        $weight = $row['weight'];
        $medical_issues = $row['medical_issues'];
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" size="16x16" href="../icons/favicon-dark-logo.png" type="image/png">
    <title>JOF INDIA | Member Registration</title>
    <link rel="stylesheet" href="../static/root.css">
    <?php if ($step === 2): ?>
    <?php endif; ?>
    <?php if ($step === 3): ?>
    <?php endif; ?>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    
</head>

<body class="page-add_member page-metrics page-measurements">

    <?php if ($show_success): ?>
        <div class="modal-overlay active">
            <div class="modal-card">
                <div class="success-icon-container"><img src="../icons/check-solid-full.svg" class="fa-solid fa-check icon-white" width="35"></div>
                <h2 class="color-dark mb-10">Registration Successful! 🎉</h2>
                <p class="color-muted mb-30">Thank you for registering with <strong>Joshuaa's Outdoor Fitness</strong>. Our team will reach out to you soon to complete your membership activation.</p>
                <p class="color-muted text-sm">You may now close this page.</p>
            </div>
        </div>
    <?php endif; ?>

    <div class="container">

        <div class="card-header">
            <div class="brand-area">
                <img src="../icons/logo-dark(1).png" class="brand-logo" alt="JOF">
                <div class="brand-text">
                    <h2>
                        <?php
                        if ($step === 1)
                            echo "Member Registration";
                        elseif ($step === 2)
                            echo "Health Metrics";
                        else
                            echo "Body Measurements";
                        ?>
                    </h2>
                    <p>
                        <?php
                        if ($step === 1)
                            echo "Enter your health details to get started.";
                        elseif ($step === 2)
                            echo "Track your daily health indicators.";
                        else
                            echo "Record your current body measurements.";
                        ?>
                    </p>
                </div>
            </div>
        </div>

        <div class="stepper-container">
            <div class="stepper" style="max-width: 500px;">
                <div class="step-item <?= $step === 1 ? 'active' : '' ?>">
                    <div class="step-circle">1</div>
                    <span class="step-label">Health</span>
                </div>
                <div class="step-item <?= $step === 2 ? 'active' : '' ?>">
                    <div class="step-circle">2</div>
                    <span class="step-label">Metrics</span>
                </div>
                <div class="step-item <?= $step === 3 ? 'active' : '' ?>">
                    <div class="step-circle">3</div>
                    <span class="step-label">Measurements</span>
                </div>
            </div>
        </div>

        <!-- ===== STEP 1: PERSONAL INFO ===== -->
        <?php if ($step === 1): ?>
            <form action="public_register.php?step=1" method="POST">
                <div class="card-body">
                    <?php if (!empty($error_msg)): ?>
                        <div class="alert-error">
                            <?= $error_msg ?>
                        </div>
                    <?php endif; ?>

                    <div class="form-grid">
                        <div class="input-group full-width" style="margin-top:8px;">
                            <h3
                                style="font-size:15px;font-weight:700;color:#111827;margin-bottom:4px;display:flex;align-items:center;gap:8px;">
                                <i class="fa-regular fa-user" style="color:#F25C2A;"></i> Personal Info
                            </h3>
                        </div>

                        <br>
                        <div class="input-group full-width">
                            <label>Full Name *</label>
                            <div class="input-wrapper">
                                <input type="text" name="full_name" id="full_name" class="form-input"
                                    placeholder="First Last" value="<?= htmlspecialchars($full_name) ?>" required>
                                <img src="../icons/user-solid-full.svg" class="input-icon" alt="user">
                            </div>
                            <div id="err_name" style="display:none;color:#DC2626;font-size:12px;margin-top:4px;"></div>
                        </div>

                        <div class="input-group full-width">
                            <label>Phone Number *</label>
                            <div class="input-wrapper">
                                <input type="tel" name="phone_number" id="phone_number" class="form-input"
                                    placeholder="10-digit number" value="<?= htmlspecialchars($phone_number) ?>" required
                                    maxlength="10">
                                <img src="../icons/user-solid-full.svg" class="input-icon" alt="phone">
                            </div>
                            <div id="err_phone" style="display:none;color:#DC2626;font-size:12px;margin-top:4px;"></div>
                        </div>

                        <div class="input-group full-width">
                            <label>Email Address *</label>
                            <div class="input-wrapper">
                                <input type="email" name="email" class="form-input" placeholder="Email Address"
                                    value="<?= htmlspecialchars($email) ?>" required>
                                <img src="../icons/envelope-solid-full.svg" class="input-icon" alt="email">
                            </div>
                        </div>

                        <div class="input-group full-width">
                            <label>Gender *</label>
                            <div class="input-wrapper">
                                <select name="gender" class="form-input" required>
                                    <option value="" disabled <?= $gender === '' ? 'selected' : '' ?>>Select Gender</option>
                                    <option value="male" <?= $gender === 'male' ? 'selected' : '' ?>>Male</option>
                                    <option value="female" <?= $gender === 'female' ? 'selected' : '' ?>>Female</option>
                                    <option value="other" <?= $gender === 'other' ? 'selected' : '' ?>>Other</option>
                                </select>
                                <img src="../icons/user-solid-full.svg" class="input-icon" alt="gender">
                            </div>
                        </div>

                        <div class="input-group full-width">
                            <label>Personal Training *</label>
                            <div class="radio-group">
                                <label class="radio-option">
                                    <input type="radio" name="personal_training" value="1" <?= $personal_training == 1 ? 'checked' : '' ?> required> Yes
                                </label>
                                <label class="radio-option">
                                    <input type="radio" name="personal_training" value="0" <?= $personal_training == 0 ? 'checked' : '' ?>> No
                                </label>
                            </div>
                        </div>

                        <div class="full-width">
                            <div class="three-cols">
                                <div class="input-group">
                                    <label>Age *</label>
                                    <div class="input-wrapper">
                                        <input type="number" name="age" id="age" class="form-input" placeholder="24"
                                            min="19" value="<?= htmlspecialchars($age) ?>" required>
                                        <img src="../icons/cake-candles-solid-full.svg" class="input-icon" alt="age">
                                    </div>
                                    <div id="err_age" style="display:none;color:#DC2626;font-size:12px;margin-top:4px;">
                                    </div>
                                </div>
                                <div class="input-group">
                                    <label>Height (cm) *</label>
                                    <div class="input-wrapper">
                                        <input type="number" name="height" id="height" class="form-input" placeholder="175"
                                            min="51" value="<?= htmlspecialchars($height) ?>" required>
                                        <img src="../icons/ruler-vertical-solid-full.svg" class="input-icon" alt="height">
                                    </div>
                                    <div id="err_height" style="display:none;color:#DC2626;font-size:12px;margin-top:4px;">
                                    </div>
                                </div>
                                <div class="input-group">
                                    <label>Weight (kg) *</label>
                                    <div class="input-wrapper">
                                        <input type="number" name="weight" id="weight" class="form-input" placeholder="70"
                                            min="11" value="<?= htmlspecialchars($weight) ?>" required>
                                        <img src="../icons/weight-scale-solid-full.svg" class="input-icon" alt="weight">
                                    </div>
                                    <div id="err_weight" style="display:none;color:#DC2626;font-size:12px;margin-top:4px;">
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="input-group full-width">
                            <label>Medical Issues / Injuries (Optional)</label>
                            <textarea name="medical_issues" class="form-input"
                                placeholder="List any injuries, surgeries, or medical conditions..."><?= htmlspecialchars($medical_issues) ?></textarea>
                        </div>
                    </div>
                </div>

                <div class="card-footer" style="padding: 30px 40px 40px 40px;">
                    <div></div>
                    <button type="submit" class="btn btn-primary">
                        Continue to Metrics →
                    </button>
                </div>
            </form>
        <?php endif; ?>

        <!-- ===== STEP 2: METRICS ===== -->
        <?php if ($step === 2): ?>
            <form action="public_register.php?step=2" method="POST">
                <div class="card-body">
                    <?php if (!empty($error_msg)): ?>
                        <div class="alert-error">
                            <?= $error_msg ?>
                        </div>
                    <?php endif; ?>

                    <div class="form-grid">
                        <div class="input-group full-width">
                            <h3 class="section-title">
                                <i class="fas fa-chart-line"></i>
                                Daily Health Metrics (Rate 1-10)
                            </h3>
                        </div>

                        <div class="input-group">
                            <label>Mood *</label>
                            <div class="input-wrapper">
                                <input type="number" name="mood" class="form-input" placeholder="1-10" min="1" max="10"
                                    value="<?= htmlspecialchars($mood) ?>" required>
                                <i class="fas fa-smile input-icon" style="color: #fbbf24;"></i>
                            </div>
                        </div>

                        <div class="input-group">
                            <label>Sleep Quality *</label>
                            <div class="input-wrapper">
                                <input type="number" name="sleep_quality" class="form-input" placeholder="1-10" min="1"
                                    max="10" value="<?= htmlspecialchars($sleep) ?>" required>
                                <i class="fas fa-moon input-icon" style="color: #8b5cf6;"></i>
                            </div>
                        </div>

                        <div class="input-group">
                            <label>Hunger & Cravings *</label>
                            <div class="input-wrapper">
                                <input type="number" name="hunger_cravings" class="form-input" placeholder="1-10" min="1"
                                    max="10" value="<?= htmlspecialchars($hunger) ?>" required>
                                <i class="fas fa-utensils input-icon" style="color: #ef4444;"></i>
                            </div>
                        </div>

                        <div class="input-group">
                            <label>Energy Levels *</label>
                            <div class="input-wrapper">
                                <input type="number" name="energy_levels" class="form-input" placeholder="1-10" min="1"
                                    max="10" value="<?= htmlspecialchars($energy) ?>" required>
                                <i class="fas fa-bolt input-icon" style="color: #10b981;"></i>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card-footer"
                    style="padding: 30px 40px 40px 40px; display:flex; justify-content:space-between; align-items:center;">
                    <a href="public_register.php?step=1" class="btn btn-secondary" style="border:1px solid #d1d5db;">Back to
                        Health Info</a>
                    <button type="submit" class="btn btn-primary" style="border:none; margin-left:auto;">
                        Continue to Measurements →
                    </button>
                </div>
            </form>
        <?php endif; ?>

        <!-- ===== STEP 3: MEASUREMENTS ===== -->
        <?php if ($step === 3): ?>
            <form action="public_register.php?step=3" method="POST" enctype="multipart/form-data">
                <div class="card-body">
                    <?php if (!empty($error_msg)): ?>
                        <div class="alert-error">
                            <?= $error_msg ?>
                        </div>
                    <?php endif; ?>

                    <div class="form-grid">
                        <div class="input-group full-width">
                            <h3 style="margin-bottom: 20px; color: var(--text-dark); font-size: 18px; font-weight: 600;">
                                <i class="fa-solid fa-tape" style="margin-right: 10px; color: var(--primary);"></i>
                                Enter your Body Measurements
                            </h3>
                        </div>

                        <div class="input-group">
                            <label>CHEST (NIPPLE LINE) *</label>
                            <div class="input-wrapper">
                                <input type="number" name="chest_nipple_line" class="form-input"
                                    placeholder="in cm or inches" step="0.1" value="<?= htmlspecialchars($chest) ?>"
                                    required>
                            </div>
                        </div>

                        <div class="input-group">
                            <label>WAIST (NAVEL LINE) *</label>
                            <div class="input-wrapper">
                                <input type="number" name="waist_navel_line" class="form-input"
                                    placeholder="in cm or inches" step="0.1" value="<?= htmlspecialchars($waist) ?>"
                                    required>
                            </div>
                        </div>

                        <div class="input-group">
                            <label>HIP (WIDEST PART) *</label>
                            <div class="input-wrapper">
                                <input type="number" name="hip_widest_part" class="form-input" placeholder="in cm or inches"
                                    step="0.1" value="<?= htmlspecialchars($hip) ?>" required>
                            </div>
                        </div>

                        <div class="input-group">
                            <label>THIGHS (MID THIGHS) *</label>
                            <div class="input-wrapper">
                                <input type="number" name="thigh_mid" class="form-input" placeholder="in cm or inches"
                                    step="0.1" value="<?= htmlspecialchars($thigh) ?>" required>
                            </div>
                        </div>

                        <div class="input-group full-width">
                            <h3 style="margin: 30px 0 10px 0; color: var(--text-dark); font-size: 18px; font-weight: 600;">
                                <i class="fas fa-camera" style="margin-right: 10px; color: var(--primary);"></i>
                                Progress Photos
                            </h3>
                            <p style="color: var(--text-muted); font-size: 14px; margin-bottom: 20px;">
                                Upload photos from different angles to track your transformation journey.
                                <span style="color: #EF4444; font-weight: bold;">(Max 2MB per photo in jpg/png format
                                    only!)</span>
                            </p>
                        </div>
                    </div>

                    <div class="photo-upload-grid">
                        <div class="photo-card">
                            <div class="photo-upload-area">
                                <i class="fas fa-user photo-icon"></i>
                                <div class="photo-text">
                                    <h4>Front View</h4>
                                    <p>Upload front-facing photo</p>
                                </div>
                                <input type="file" id="front-photo" name="front_view" accept="image/jpeg,image/png"
                                    class="photo-input">
                                <label for="front-photo" class="photo-upload-btn"><i class="fas fa-plus"></i> Choose
                                    Photo</label>
                            </div>
                            <div class="photo-preview" id="front-preview"></div>
                        </div>

                        <div class="photo-card">
                            <div class="photo-upload-area">
                                <i class="fas fa-user photo-icon"></i>
                                <div class="photo-text">
                                    <h4>Back View</h4>
                                    <p>Upload back-facing photo</p>
                                </div>
                                <input type="file" id="back-photo" name="back_view" accept="image/jpeg,image/png"
                                    class="photo-input">
                                <label for="back-photo" class="photo-upload-btn"><i class="fas fa-plus"></i> Choose
                                    Photo</label>
                            </div>
                            <div class="photo-preview" id="back-preview"></div>
                        </div>

                        <div class="photo-card">
                            <div class="photo-upload-area">
                                <i class="fas fa-user photo-icon"></i>
                                <div class="photo-text">
                                    <h4>Side View</h4>
                                    <p>Upload side-facing photo</p>
                                </div>
                                <input type="file" id="side-photo" name="side_view" accept="image/jpeg,image/png"
                                    class="photo-input">
                                <label for="side-photo" class="photo-upload-btn"><i class="fas fa-plus"></i> Choose
                                    Photo</label>
                            </div>
                            <div class="photo-preview" id="side-preview"></div>
                        </div>
                    </div>
                </div>

                <div class="card-footer" style="padding: 30px 40px 40px 40px;">
                    <a href="public_register.php?step=2" class="btn btn-secondary">Back to Metrics</a>
                    <button type="submit" class="btn btn-primary" style="border:none;">Finish Registration →</button>
                </div>
            </form>
        <?php endif; ?>

    </div>

    <script>
        // Client-side validation for step 1
        <?php if ($step === 1): ?>
                function showErr(id, msg) { const el = document.getElementById(id); if (!el) return; el.textContent = '⚠ ' + msg; el.style.display = 'block'; }
            function clearErr(id) { const el = document.getElementById(id); if (el) el.style.display = 'none'; }

            document.getElementById('phone_number').addEventListener('input', function () {
                this.value = this.value.replace(/\D/g, '').slice(0, 10);
            });

            document.querySelector('form').addEventListener('submit', function (e) {
                let valid = true;
                const name = document.getElementById('full_name').value.trim();
                if (!/^[a-zA-Z]{2,}(\s[a-zA-Z]{1,})+$/.test(name)) { showErr('err_name', 'Enter first and last name (letters only).'); valid = false; } else { clearErr('err_name'); }
                const phone = document.getElementById('phone_number').value.trim();
                if (!/^\d{10}$/.test(phone)) { showErr('err_phone', 'Phone must be exactly 10 digits.'); valid = false; } else { clearErr('err_phone'); }
                const age = parseFloat(document.getElementById('age').value);
                if (isNaN(age) || age <= 18) { showErr('err_age', 'Age must be greater than 18.'); valid = false; } else { clearErr('err_age'); }
                const height = parseFloat(document.getElementById('height').value);
                if (isNaN(height) || height <= 50) { showErr('err_height', 'Height must be greater than 50 cm.'); valid = false; } else { clearErr('err_height'); }
                const weight = parseFloat(document.getElementById('weight').value);
                if (isNaN(weight) || weight <= 10) { showErr('err_weight', 'Weight must be greater than 10 kg.'); valid = false; } else { clearErr('err_weight'); }
                if (!valid) e.preventDefault();
            });
        <?php endif; ?>

        // Photo preview for step 3
        <?php if ($step === 3): ?>
                function setupPhotoPreview(inputId, previewId) {
                    const input = document.getElementById(inputId);
                    const preview = document.getElementById(previewId);
                    input.addEventListener('change', function (e) {
                        const file = e.target.files[0];
                        if (file) {
                            if (!['image/jpeg', 'image/png'].includes(file.type)) { alert('Only JPG and PNG files are allowed.'); input.value = ''; preview.innerHTML = ''; return; }
                            if (file.size > 2 * 1024 * 1024) { alert('File too large. Max 2MB.'); input.value = ''; preview.innerHTML = ''; return; }
                            const reader = new FileReader();
                            reader.onload = function (e) { preview.innerHTML = `<img src="${e.target.result}" alt="Preview" style="max-width:100%;max-height:200px;border-radius:8px;margin-top:10px;">`; };
                            reader.readAsDataURL(file);
                        } else { preview.innerHTML = ''; }
                    });
                }
            setupPhotoPreview('front-photo', 'front-preview');
            setupPhotoPreview('back-photo', 'back-preview');
            setupPhotoPreview('side-photo', 'side-preview');
        <?php endif; ?>
    </script>

</body>

</html>