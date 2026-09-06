<?php
require_once __DIR__ . '/../auth/auth_check.php';
require_role(['admin', 'trainer']);
require_once __DIR__ . '/../config.php';

$is_admin = true; // enforced by require_role above

// 2. Flow Check
if (!isset($_SESSION['new_member_id'])) {
    header("Location: add_member.php");
    exit;
}

$member_id = $_SESSION['new_member_id'];
$error_msg = '';
$show_success_modal = false;

// Initialize variables for stickiness
$chest = '';
$waist = '';
$hip = '';
$thigh = '';

// Pre-fill from existing measurements row if the user is going back
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

// 3. Helper Function for Image Uploads
function uploadImage($fileInputName)
{
    global $error_msg;
    if (!isset($_FILES[$fileInputName]) || $_FILES[$fileInputName]['error'] !== 0)
        return null;
    if ($_FILES[$fileInputName]['size'] > 2 * 1024 * 1024) {
        $error_msg = "File " . $_FILES[$fileInputName]['name'] . " is too large (Max 2MB).";
        return null;
    }
    $allowedExts = ['jpg', 'jpeg', 'png'];
    $fileExt = strtolower(pathinfo($_FILES[$fileInputName]['name'], PATHINFO_EXTENSION));
    if (!in_array($fileExt, $allowedExts)) {
        $error_msg = "Only JPG and PNG files are allowed. '" . $_FILES[$fileInputName]['name'] . "' is not permitted.";
        return null;
    }
    $uploadDir = "../uploads/progress_photos/";
    if (!is_dir($uploadDir))
        mkdir($uploadDir, 0777, true);
    $ext = pathinfo($_FILES[$fileInputName]['name'], PATHINFO_EXTENSION);
    $fileName = $fileInputName . "_" . $_SESSION['new_member_id'] . "_" . time() . "." . $ext;
    if (move_uploaded_file($_FILES[$fileInputName]['tmp_name'], $uploadDir . $fileName))
        return $fileName;
    return null;
}

// 4. Handle Form Submission
if ($_SERVER["REQUEST_METHOD"] == "POST") {

    $chest = $_POST['chest_nipple_line'] ?? '';
    $waist = $_POST['waist_navel_line'] ?? '';
    $thigh = $_POST['thigh_mid'] ?? '';
    $hip = $_POST['hip_widest_part'] ?? '';

    if ($chest === '' || $waist === '' || $thigh === '' || $hip === '') {
        $error_msg = "All measurement fields are required.";
    }
    else {
        $front_image = uploadImage('front_view');
        $back_image = uploadImage('back_view');
        $side_image = uploadImage('side_view');

        if (empty($error_msg)) {
            if ($ex_meas_row) {
                // UPDATE existing row; keep old photos if no new ones uploaded
                $final_front = $front_image ?? $ex_meas_row['front_view_image'];
                $final_back = $back_image ?? $ex_meas_row['back_view_image'];
                $final_side = $side_image ?? $ex_meas_row['side_view_image'];

                $sql = "UPDATE member_measurements SET chest_nipple_line=?, waist_navel_line=?, thigh_mid=?, hip_widest_part=?, front_view_image=?, back_view_image=?, side_view_image=? WHERE member_id=?";
                if ($stmt = $conn->prepare($sql)) {
                    $stmt->bind_param("ddddsssi", $chest, $waist, $thigh, $hip, $final_front, $final_back, $final_side, $member_id);
                    if ($stmt->execute()) {
                        header("Location: member_payments.php");
                        exit;
                    }
                    else {
                        $error_msg = "Failed to update measurements: " . $stmt->error;
                    }
                    $stmt->close();
                }
            }
            else {
                // INSERT new row
                $sql = "INSERT INTO member_measurements (member_id, chest_nipple_line, waist_navel_line, thigh_mid, hip_widest_part, front_view_image, back_view_image, side_view_image) VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
                if ($stmt = $conn->prepare($sql)) {
                    $stmt->bind_param("idddssss", $member_id, $chest, $waist, $thigh, $hip, $front_image, $back_image, $side_image);
                    if ($stmt->execute()) {
                        header("Location: member_payments.php");
                        exit;
                    }
                    else {
                        $error_msg = "Failed to save measurements: " . $stmt->error;
                    }
                    $stmt->close();
                }
                else {
                    $error_msg = "Database Error: " . $conn->error;
                }
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
    <title>JOF India | Body Measurements</title>
    <link rel="stylesheet" href="../static/root.css">
    <!-- <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"> -->
    
</head>

<body class="page-measurements">

    <div class="container">

        <div class="card-header">
            <div class="brand-area">
                <img src="../icons/logo-dark(1).png" class="brand-logo" alt="JOF">
                <div class="brand-text">
                    <h2>Body Measurements</h2>
                    <p>Record your current body measurements for tracking progress.</p>
                </div>
            </div>
            <?php if ($is_admin): ?>
                <a href="members.php" class="close-btn">
                    <img src="../icons/xmark-solid-full.svg" alt="close">
                </a>
            <?php
endif; ?>
        </div>

        <div class="stepper-container">
            <div class="stepper stepper-narrow">
                <div class="step-item">
                    <div class="step-circle">1</div>
                    <span class="step-label">Health</span>
                </div>
                <div class="step-item">
                    <div class="step-circle">2</div>
                    <span class="step-label">Metrics</span>
                </div>
                <div class="step-item active">
                    <div class="step-circle">3</div>
                    <span class="step-label">Measurements</span>
                </div>
                <div class="step-item">
                    <div class="step-circle">4</div>
                    <span class="step-label">Payment</span>
                </div>
            </div>
        </div>


        <form action="" method="POST" enctype="multipart/form-data">
            <div class="card-body">

                <?php if (!empty($error_msg)): ?>
                    <div class="alert-error"><?php echo $error_msg; ?></div>
                <?php
endif; ?>


                <div class="form-grid">

                    <div class="input-group full-width">
                        <h3 class="section-title">
                            <img src="../icons/tape-solid-full.svg" class="fa-solid fa-tape mr-10 header-icon-orange">
                            Enter your Body Measurements
                        </h3>
                    </div>

                    <div class="input-group">
                        <label>CHEST (NIPPLE LINE) *</label>
                        <div class="input-wrapper">
                            <input type="number" name="chest_nipple_line" class="form-input"
                                placeholder="in inches" step="0.1" value="<?php echo htmlspecialchars($chest); ?>"
                                required>
                        </div>
                    </div>

                    <div class="input-group">
                        <label>WAIST (NAVEL LINE) *</label>
                        <div class="input-wrapper">
                            <input type="number" name="waist_navel_line" class="form-input"
                                placeholder="in inches" step="0.1" value="<?php echo htmlspecialchars($waist); ?>"
                                required>
                        </div>
                    </div>

                    <div class="input-group">
                        <label>HIP (WIDEST PART) *</label>
                        <div class="input-wrapper">
                            <input type="number" name="hip_widest_part" class="form-input" placeholder="in inches"
                                step="0.1" value="<?php echo htmlspecialchars($hip); ?>" required>
                        </div>
                    </div>

                    <div class="input-group">
                        <label>THIGHS (MID THIGHS) *</label>
                        <div class="input-wrapper">
                            <input type="number" name="thigh_mid" class="form-input" placeholder="in inches"
                                step="0.1" value="<?php echo htmlspecialchars($thigh); ?>" required>
                        </div>
                    </div>

                    <div class="input-group full-width">
                        <h3 class="section-title mt-30 mb-4" style="margin-bottom: 4px;">
                            <img src="../icons/camera-solid-full.svg" class="fa-solid fa-camera mr-10 header-icon-orange">
                            Progress Photos
                        </h3>
                        <p style="font-size: 13px; color: #6B7280; margin-bottom: 25px; line-height: 1.5;">
                            Upload photos from different angles to track your transformation journey.
                            <span style="color: #F25C2A; font-weight: 600;">(Max 2MB per photo in jpg/png format only!)</span>
                        </p>
                    </div>

                </div>

                <div class="photo-upload-grid">
                    <div class="photo-card">
                        <div class="photo-upload-area">
                            <img src="../icons/user-solid-full.svg" class="fa-solid fa-user photo-icon">
                            <div class="photo-text">
                                <h4>Front View</h4>
                                <p>Upload front-facing photo</p>
                            </div>
                            <input type="file" id="front-photo" name="front_view" accept="image/jpeg,image/png"
                                class="photo-input">
                            <label for="front-photo" class="photo-upload-btn">
                                <img src="../icons/plus-solid-full.svg" class="fa-solid fa-plus mr-8"> Choose Photo
                            </label>
                        </div>
                        <div class="photo-preview" id="front-preview"></div>
                    </div>

                    <div class="photo-card">
                        <div class="photo-upload-area">
                            <img src="../icons/user-solid-full.svg" class="fa-solid fa-user photo-icon">
                            <div class="photo-text">
                                <h4>Back View</h4>
                                <p>Upload back-facing photo</p>
                            </div>
                            <input type="file" id="back-photo" name="back_view" accept="image/jpeg,image/png"
                                class="photo-input">
                            <label for="back-photo" class="photo-upload-btn">
                                <img src="../icons/plus-solid-full.svg" class="fa-solid fa-plus mr-8"> Choose Photo
                            </label>
                        </div>
                        <div class="photo-preview" id="back-preview"></div>
                    </div>

                    <div class="photo-card">
                        <div class="photo-upload-area">
                            <img src="../icons/user-solid-full.svg" class="fa-solid fa-user photo-icon">
                            <div class="photo-text">
                                <h4>Side View</h4>
                                <p>Upload side-facing photo</p>
                            </div>
                            <input type="file" id="side-photo" name="side_view" accept="image/jpeg,image/png"
                                class="photo-input">
                            <label for="side-photo" class="photo-upload-btn">
                                <img src="../icons/plus-solid-full.svg" class="fa-solid fa-plus mr-8"> Choose Photo
                            </label>
                        </div>
                        <div class="photo-preview" id="side-preview"></div>
                    </div>
                </div>


            </div>

            <div class="card-footer footer-spaced">
                <a href="metrics.php" class="btn btn-secondary">Back to metrics</a>
                <button type="submit" class="btn btn-primary no-border">Continue to Payment →</button>
            </div>

        </form>


    </div>

    <script>
        // Photo preview functionality
        function setupPhotoPreview(inputId, previewId) {
            const input = document.getElementById(inputId);
            const preview = document.getElementById(previewId);

            input.addEventListener('change', function (e) {
                const file = e.target.files[0];
                if (file) {
                    // Check file type (JPG/PNG only)
                    const allowedTypes = ['image/jpeg', 'image/png'];
                    if (!allowedTypes.includes(file.type)) {
                        alert('Only JPG and PNG files are allowed. Please choose a valid photo.');
                        input.value = '';
                        preview.innerHTML = '';
                        return;
                    }
                    // Check file size (2MB = 2 * 1024 * 1024 bytes)
                    if (file.size > 2 * 1024 * 1024) {
                        alert("File " + file.name + " is too large. Maximum size is 2MB.");
                        input.value = ""; // Clear input
                        preview.innerHTML = "";
                        return;
                    }

                    const reader = new FileReader();
                    reader.onload = function (e) {
                        preview.innerHTML = `<img src="${e.target.result}" alt="Preview" style="max-width: 100%; max-height: 200px; border-radius: 8px; margin-top: 10px;">`;
                    };
                    reader.readAsDataURL(file);
                } else {
                    preview.innerHTML = '';
                }
            });
        }

        // Setup all photo previews
        setupPhotoPreview('front-photo', 'front-preview');
        setupPhotoPreview('back-photo', 'back-preview');
        setupPhotoPreview('side-photo', 'side-preview');
    </script>

    <script>
        function replaceSVG() {
            var images = document.querySelectorAll('img.fa-solid, img.fa-regular, img[class*="fa-"]');
            images.forEach(function(img) {
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
                        paths.forEach(function(path) {
                            path.setAttribute('fill', 'currentColor');
                        });

                        img.parentNode.replaceChild(svg, img);
                    })
                    .catch(err => console.error('Error fetching SVG:', err));
            });
        }
        
        replaceSVG();

        var observer = new MutationObserver(function(mutations) {
            var shouldRun = false;
            mutations.forEach(function(mutation) {
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