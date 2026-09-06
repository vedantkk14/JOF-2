<?php
session_start();
require '../config.php';

// 1. Check if admin is logged in (public users can also access this page)
$is_admin = isset($_SESSION['user_id']);

// Enforce sequential access
if (!isset($_SESSION['allowed_step']) || $_SESSION['allowed_step'] < 1) {
    header("Location: form0_welcome_user.php");
    exit;
}

$member_id = null;
$is_edit = false;

// Default values — overwritten below by DB fetch or lead data if applicable
$full_name = '';
$phone_number = '';
$email = '';
$gender = '';
$diet_type = '';
$personal_training = 1;
$age = '';
$height = '';
$weight = '';
$medical_issues = '';
$error_msg = '';
$lead_id = null;


if ($_SERVER['REQUEST_METHOD'] !== 'POST' && !isset($_GET['back'])) {
    unset($_SESSION['new_member_id']);
}

// If new_member_id is in session (user went Back from metrics page), pre-fill
if (isset($_SESSION['new_member_id'])) {
    $member_id = $_SESSION['new_member_id'];
    $is_edit = true;

    // Pre-fill from existing DB row
    $fetch = $conn->prepare("SELECT full_name, phone_number, email, gender, diet_type, personal_training, age, height, weight, medical_issues FROM members WHERE id = ?");
    $fetch->bind_param("i", $member_id);
    $fetch->execute();
    $row = $fetch->get_result()->fetch_assoc();
    $fetch->close();
    if ($row) {
        $full_name = $row['full_name'];
        $phone_number = $row['phone_number'];
        $email = $row['email'];
        $gender = $row['gender'] ?? '';
        $diet_type = $row['diet_type'] ?? '';
        $personal_training = $row['personal_training'];
        $age = $row['age'];
        $height = $row['height'];
        $weight = $row['weight'];
        $medical_issues = $row['medical_issues'];
    }
}

// Check if converting from a lead (only when not already mid-wizard)
if (!$is_edit && isset($_GET['lead_id'])) {
    $lead_id = intval($_GET['lead_id']);
    $lead_sql = "SELECT * FROM sales_leads WHERE id = ?";
    if ($lead_stmt = $conn->prepare($lead_sql)) {
        $lead_stmt->bind_param("i", $lead_id);
        $lead_stmt->execute();
        $lead_result = $lead_stmt->get_result();
        if ($lead_data = $lead_result->fetch_assoc()) {
            $full_name = $lead_data['full_name'];
            $phone_number = $lead_data['phone_number'];
            $email = $lead_data['email'] ?? '';
        }
        $lead_stmt->close();
    }
}

// 2. Handle Form Submission
if ($_SERVER["REQUEST_METHOD"] == "POST") {

    // Retrieve Inputs
    $full_name = trim($_POST['full_name'] ?? '');
    $phone_number = trim($_POST['phone_number'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $gender = $_POST['gender'] ?? '';
    $diet_type = $_POST['diet_type'] ?? '';
    $personal_training = $_POST['personal_training'] ?? 1;
    $age = $_POST['age'] ?? null;
    $height = $_POST['height'] ?? null;
    $weight = $_POST['weight'] ?? null;
    $medical_issues = trim($_POST['medical_issues'] ?? '');
    $lead_id = isset($_POST['lead_id']) ? intval($_POST['lead_id']) : null;

    // Detailed server-side validation
    $errors = [];
    if (empty($full_name)) {
        $errors[] = "Full name is required.";
    } elseif (!preg_match('/^[a-zA-Z]{2,}(\s[a-zA-Z]{1,})+$/', $full_name)) {
        $errors[] = "Full name must include first and last name (letters only, no numbers or symbols).";
    }
    if (empty($phone_number)) {
        $errors[] = "Phone number is required.";
    } elseif (!preg_match('/^\d{10}$/', $phone_number)) {
        $errors[] = "Phone number must be exactly 10 digits (numbers only).";
    }
    if ($age === null || $age === '') {
        $errors[] = "Age is required.";
    } elseif (intval($age) <= 18) {
        $errors[] = "Member must be older than 18 years.";
    }
    if ($height === null || $height === '') {
        $errors[] = "Height is required.";
    } elseif (floatval($height) <= 50) {
        $errors[] = "Height must be greater than 50 cm.";
    }
    if ($weight === null || $weight === '') {
        $errors[] = "Weight is required.";
    } elseif (floatval($weight) <= 10) {
        $errors[] = "Weight must be greater than 10 kg.";
    }

    if (empty($email)) {
        $errors[] = "Email is required.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = "Invalid email format.";
    } else {
        // Check for unique email
        if ($is_edit && $member_id) {
            $stmt_check = $conn->prepare("SELECT id FROM members WHERE email = ? AND id != ?");
            $stmt_check->bind_param("si", $email, $member_id);
        } else {
            $stmt_check = $conn->prepare("SELECT id FROM members WHERE email = ?");
            $stmt_check->bind_param("s", $email);
        }
        $stmt_check->execute();
        $stmt_check->store_result();
        if ($stmt_check->num_rows > 0) {
            $errors[] = "This email address is already registered.";
        }
        $stmt_check->close();
    }

    if (!empty($errors)) {
        $error_msg = implode('<br>', $errors);
    } else {
        if ($is_edit && $member_id) {
            // UPDATE existing member
            $sql = "UPDATE members SET full_name=?, phone_number=?, email=?, gender=?, diet_type=?, personal_training=?, age=?, height=?, weight=?, medical_issues=? WHERE id=?";
            if ($stmt = $conn->prepare($sql)) {
                $stmt->bind_param(
                    "sssssiiidsi",
                    $full_name,
                    $phone_number,
                    $email,
                    $gender,
                    $diet_type,
                    $personal_training,
                    $age,
                    $height,
                    $weight,
                    $medical_issues,
                    $member_id
                );
                if ($stmt->execute()) {
                    $_SESSION['allowed_step'] = max($_SESSION['allowed_step'] ?? 1, 2);
                    header("Location: form2_member_metrics.php");
                    exit;
                } else {
                    $error_msg = "Update Error: " . $stmt->error;
                }
                $stmt->close();
            }
        } else {
            // INSERT new member
            $membership_default = "Pending";
            $status_default = "inactive";
            $sql = "INSERT INTO members (full_name, phone_number, email, gender, diet_type, membership, status, personal_training, age, height, weight, medical_issues, lead_source_id, inactive_date) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";
            if ($stmt = $conn->prepare($sql)) {
                $stmt->bind_param("sssssssiiidsi", $full_name, $phone_number, $email, $gender, $diet_type, $membership_default, $status_default, $personal_training, $age, $height, $weight, $medical_issues, $lead_id);
                if ($stmt->execute()) {
                    $_SESSION['new_member_id'] = $stmt->insert_id;
                    $_SESSION['allowed_step'] = max($_SESSION['allowed_step'] ?? 1, 2);
                    if ($lead_id) {
                        $upd = $conn->prepare("UPDATE sales_leads SET lead_status = 'Converted' WHERE id = ?");
                        $upd->bind_param("i", $lead_id);
                        $upd->execute();
                        $upd->close();
                    }

                    // Registration email is sent from member_payments.php (final wizard step)

                    header("Location: form2_member_metrics.php");
                    exit;
                } else {
                    $error_msg = "Database Error: " . $stmt->error;
                }
                $stmt->close();
            } else {
                $error_msg = "Query Error: " . $conn->error;
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
    <link rel="icon" size="16x16" href="../icons/favicon-dark-logo.png" type="image/png">
    <title>JOF India | Add Member</title>
    <link rel="stylesheet" href="../static/root.css">
</head>

<body class="page-add_member">

    <div class="container">

        <div class="card-header">
            <div class="brand-area">
                <img src="../icons/logo-dark(1).png" class="brand-logo" alt="JOF">
                <div class="brand-text">
                    <h2>Add New Member</h2>
                    <p>Enter health details to get started.</p>
                </div>
            </div>
        </div>

        <div class="stepper-container">
            <div class="stepper stepper-narrow">
                <div class="step-item active">
                    <div class="step-circle">1</div>
                    <span class="step-label">Health</span>
                </div>
                <div class="step-item">
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


                <!-- Hidden field to preserve lead_id through form submission -->
                <?php if ($lead_id): ?>
                    <input type="hidden" name="lead_id" value="<?php echo $lead_id; ?>">
                    <?php
                endif; ?>

                <div class="input-group full-width mt-8">
                    <h3 class="section-header-compact">
                        <img src="../icons/user-regular-full.svg" class="fa-regular fa-user header-icon-orange">
                        Personal Info
                    </h3>
                </div>

                <br>
                <div class="form-grid">
                    <div class="input-group full-width">
                        <label>Full Name *</label>
                        <div class="input-wrapper">
                            <input type="text" name="full_name" id="full_name" class="form-input"
                                placeholder="First Last" value="<?php echo htmlspecialchars($full_name); ?>" required>
                            <img src="../icons/user-solid-full.svg" class="input-icon" alt="user">
                        </div>
                        <div id="err_name" class="err-msg"></div>
                    </div>

                    <div class="input-group full-width">
                        <label>Phone Number *</label>
                        <div class="input-wrapper">
                            <input type="tel" name="phone_number" id="phone_number" class="form-input"
                                placeholder="10-digit number" value="<?php echo htmlspecialchars($phone_number); ?>"
                                required maxlength="10">
                            <img src="../icons/user-solid-full.svg" class="input-icon" alt="phone">
                        </div>
                        <div id="err_phone" class="err-msg"></div>
                    </div>

                    <div class="input-group full-width">
                        <label>Email Address *</label>
                        <div class="input-wrapper">
                            <input type="email" name="email" class="form-input" placeholder="Email Address"
                                value="<?php echo htmlspecialchars($email); ?>" required>
                            <img src="../icons/envelope-solid-full.svg" class="input-icon" alt="email">
                        </div>
                    </div>

                    <div class="input-group">
                        <label>Gender *</label>
                        <div class="input-wrapper">
                            <select name="gender" class="form-input" required>
                                <option value="" disabled <?php echo $gender === '' ? 'selected' : ''; ?>>Select Gender
                                </option>
                                <option value="male" <?php echo $gender === 'male' ? 'selected' : ''; ?>>Male</option>
                                <option value="female" <?php echo $gender === 'female' ? 'selected' : ''; ?>>Female
                                </option>
                                <option value="other" <?php echo $gender === 'other' ? 'selected' : ''; ?>>Other</option>
                            </select>
                            <img src="../icons/user-solid-full.svg" class="input-icon" alt="gender">
                        </div>
                    </div>

                    <div class="input-group">
                        <label>Diet Preference *</label>
                        <div class="input-wrapper">
                            <select name="diet_type" class="form-input" required>
                                <option value="" disabled <?php echo $diet_type === '' ? 'selected' : ''; ?>>Select Diet
                                </option>
                                <option value="non-veg" <?php echo $diet_type === 'non-veg' ? 'selected' : ''; ?>>
                                    Non-Vegetarian</option>
                                <option value="veg" <?php echo $diet_type === 'veg' ? 'selected' : ''; ?>>Vegetarian
                                </option>
                                <option value="vegan" <?php echo $diet_type === 'vegan' ? 'selected' : ''; ?>>Vegan
                                </option>
                                <option value="eggetarian" <?php echo $diet_type === 'eggetarian' ? 'selected' : ''; ?>>
                                    Eggetarian</option>
                                <option value="keto" <?php echo $diet_type === 'keto' ? 'selected' : ''; ?>>Keto / Low
                                    Carb</option>
                            </select>
                            <img src="../icons/leaf-solid-full.svg" class="fa-solid fa-leaf input-icon leaf-icon-green">
                        </div>
                    </div>

                    <!-- Membership Dropdown Removed -->

                    <div class="input-group full-width">
                        <label>Personal Training *</label>
                        <div class="radio-group">
                            <label class="radio-option">
                                <input type="radio" name="personal_training" value="1" <?php echo $personal_training == 1 ? 'checked' : ''; ?> required> Yes
                            </label>
                            <label class="radio-option">
                                <input type="radio" name="personal_training" value="0" <?php echo $personal_training == 0 ? 'checked' : ''; ?>> No
                            </label>
                        </div>
                    </div>

                    <div class="full-width">
                        <div class="three-cols">
                            <div class="input-group">
                                <label>Age *</label>
                                <div class="input-wrapper">
                                    <input type="number" name="age" id="age" class="form-input" placeholder="24"
                                        min="19" value="<?php echo htmlspecialchars($age); ?>" required>
                                    <img src="../icons/cake-candles-solid-full.svg" class="input-icon" alt="age">
                                </div>
                                <div id="err_age" class="err-msg">
                                </div>
                            </div>
                            <div class="input-group">
                                <label>Height (cm) *</label>
                                <div class="input-wrapper">
                                    <input type="number" name="height" id="height" class="form-input" placeholder="175"
                                        min="51" value="<?php echo htmlspecialchars($height); ?>" required>
                                    <img src="../icons/ruler-vertical-solid-full.svg" class="input-icon" alt="height">
                                </div>
                                <div id="err_height" class="err-msg">
                                </div>
                            </div>
                            <div class="input-group">
                                <label>Weight (kg) *</label>
                                <div class="input-wrapper">
                                    <input type="number" name="weight" id="weight" class="form-input" placeholder="70"
                                        min="11" value="<?php echo htmlspecialchars($weight); ?>" required>
                                    <img src="../icons/weight-scale-solid-full.svg" class="input-icon" alt="weight">
                                </div>
                                <div id="err_weight" class="err-msg">
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="input-group full-width">
                        <label>Medical Issues / Injuries (Optional)</label>
                        <textarea name="medical_issues" class="form-input"
                            placeholder="List any injuries, surgeries, or medical conditions..."><?php echo htmlspecialchars($medical_issues); ?></textarea>
                    </div>
                </div>


            </div>

            <div class="card-footer footer-spaced">
                <?php if ($is_admin): ?>
                    <a href="form0_welcome_user.php" class="btn btn-secondary">Back</a>
                    <?php
                else: ?>
                    <div></div>
                    <?php
                endif; ?>
                <button type="submit" class="btn btn-primary">
                    Continue to Metrics →
                </button>
            </div>
        </form>


    </div>

    <script>
        // ── Client-side validation 
        function showErr(id, msg) {
            const el = document.getElementById(id);
            if (!el) return;
            el.textContent = '⚠ ' + msg;
            el.style.display = 'block';
        }
        function clearErr(id) {
            const el = document.getElementById(id);
            if (el) el.style.display = 'none';
        }

        // Live phone: allow only digits
        document.getElementById('phone_number').addEventListener('input', function () {
            this.value = this.value.replace(/\D/g, '').slice(0, 10);
        });

        document.querySelector('form').addEventListener('submit', function (e) {
            let valid = true;

            // 1. Full name — letters & spaces, at least two words
            const name = document.getElementById('full_name').value.trim();
            const nameRegex = /^[a-zA-Z]{2,}(\s[a-zA-Z]{1,})+$/;
            if (!nameRegex.test(name)) {
                showErr('err_name', 'Enter first and last name (letters only, no numbers or symbols).');
                valid = false;
            } else { clearErr('err_name'); }

            // 2. Phone — exactly 10 digits
            const phone = document.getElementById('phone_number').value.trim();
            if (!/^\d{10}$/.test(phone)) {
                showErr('err_phone', 'Phone must be exactly 10 digits (numbers only).');
                valid = false;
            } else { clearErr('err_phone'); }

            // 3. Email - basic format check
            const email = document.getElementById('email').value.trim();
            const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            if (!emailRegex.test(email)) {
                showErr('err_email', 'Please enter a valid email address.');
                valid = false;
            } else { clearErr('err_email'); }

            // 4. Personal Training - check if selected
            const personalTrainingYes = document.querySelector('input[name="personal_training"][value="1"]');
            const personalTrainingNo = document.querySelector('input[name="personal_training"][value="0"]');
            if (!personalTrainingYes.checked && !personalTrainingNo.checked) {
                showErr('err_personal_training', 'Please select an option for Personal Training.');
                valid = false;
            } else { clearErr('err_personal_training'); }


            // 5. Age > 18
            const age = parseFloat(document.getElementById('age').value);
            if (isNaN(age) || age <= 18) {
                showErr('err_age', 'Age must be greater than 18.');
                valid = false;
            } else { clearErr('err_age'); }

            // 6. Height > 50
            const height = parseFloat(document.getElementById('height').value);
            if (isNaN(height) || height <= 50) {
                showErr('err_height', 'Height must be greater than 50 cm.');
                valid = false;
            } else { clearErr('err_height'); }

            // 7. Weight > 10
            const weight = parseFloat(document.getElementById('weight').value);
            if (isNaN(weight) || weight <= 10) {
                showErr('err_weight', 'Weight must be greater than 10 kg.');
                valid = false;
            } else { clearErr('err_weight'); }

            if (!valid) e.preventDefault();
        });
        });
    </script>

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