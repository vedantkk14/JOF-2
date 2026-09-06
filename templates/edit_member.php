<?php
require_once __DIR__ . '/../auth/auth_check.php';
require_role(['admin', 'trainer']);
require_once __DIR__ . '/../config.php';

$member_id = $_GET['id'] ?? null;

if (!$member_id) {
    die("Invalid member ID");
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Update member information
    $full_name = trim($_POST['full_name']);
    $age = $_POST['age'];
    $height = $_POST['height'];
    $weight = $_POST['weight'];
    $email = trim($_POST['email']);
    $medical_issues = trim($_POST['medical_issues']);

    $sql = "UPDATE members SET 
            full_name = ?, 
            age = ?, 
            height = ?, 
            weight = ?, 
            email = ?, 
            medical_issues = ?
            WHERE id = ?";

    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "siiissi", $full_name, $age, $height, $weight, $email, $medical_issues, $member_id);

    if (mysqli_stmt_execute($stmt)) {
        // Update or insert measurements
        $chest = $_POST['chest_nipple_line'] ?? null;
        $waist = $_POST['waist_navel_line'] ?? null;
        $hips = $_POST['hip_widest_part'] ?? null;
        $thighs = $_POST['thigh_mid'] ?? null;

        // Check if measurements exist for this member
        $check_sql = "SELECT id FROM member_measurements WHERE member_id = ? ORDER BY recorded_at DESC LIMIT 1";
        $check_stmt = mysqli_prepare($conn, $check_sql);
        mysqli_stmt_bind_param($check_stmt, "i", $member_id);
        mysqli_stmt_execute($check_stmt);
        $existing = mysqli_fetch_assoc(mysqli_stmt_get_result($check_stmt));

        if ($existing) {
            // Update existing measurements
            $update_sql = "UPDATE member_measurements SET 
                        chest_nipple_line = ?, 
                        waist_navel_line = ?, 
                        hip_widest_part = ?, 
                        thigh_mid = ?
                        WHERE id = ?";
            $update_stmt = mysqli_prepare($conn, $update_sql);
            mysqli_stmt_bind_param($update_stmt, "ddddi", $chest, $waist, $hips, $thighs, $existing['id']);
            mysqli_stmt_execute($update_stmt);
        } else {
            // Insert new measurements
            $insert_sql = "INSERT INTO member_measurements 
                        (member_id, chest_nipple_line, waist_navel_line, hip_widest_part, thigh_mid) 
                        VALUES (?, ?, ?, ?, ?)";
            $insert_stmt = mysqli_prepare($conn, $insert_sql);
            mysqli_stmt_bind_param($insert_stmt, "idddd", $member_id, $chest, $waist, $hips, $thighs);
            mysqli_stmt_execute($insert_stmt);
        }

        header("Location: person_info.php?id=" . $member_id);
        exit;
    } else {
        $error = "Failed to update member information.";
    }
}

// Fetch current member data
$sql = "SELECT full_name, age, height, weight, email, medical_issues 
        FROM members WHERE id = ?";
$stmt = mysqli_prepare($conn, $sql);
mysqli_stmt_bind_param($stmt, "i", $member_id);
mysqli_stmt_execute($stmt);
$member = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

if (!$member) {
    die("Member not found");
}

// Fetch latest measurements
$measure_sql = "SELECT chest_nipple_line, waist_navel_line, hip_widest_part, thigh_mid
                FROM member_measurements
                WHERE member_id = ?
                ORDER BY recorded_at DESC
                LIMIT 1";
$measure_stmt = mysqli_prepare($conn, $measure_sql);
mysqli_stmt_bind_param($measure_stmt, "i", $member_id);
mysqli_stmt_execute($measure_stmt);
$measurements = mysqli_fetch_assoc(mysqli_stmt_get_result($measure_stmt));
?>

<style>
    /* Exact Match to User-Provided Reference Design */
    .premium-edit-wrapper {
        background: #ffffff;
        border-radius: 20px;
        overflow-y: auto;
        max-height: 90vh;
        font-family: 'Plus Jakarta Sans', 'Poppins', sans-serif;
        box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
    }

    /* Custom Scrollbar for the Wrapper */
    .premium-edit-wrapper::-webkit-scrollbar {
        width: 6px;
    }

    .premium-edit-wrapper::-webkit-scrollbar-track {
        background: transparent;
        margin: 10px 0;
    }

    .premium-edit-wrapper::-webkit-scrollbar-thumb {
        background: rgba(0, 0, 0, 0.1);
        border-radius: 10px;
    }

    .premium-edit-wrapper::-webkit-scrollbar-thumb:hover {
        background: rgba(242, 92, 42, 0.3);
    }

    .premium-edit-header {
        padding: 30px 40px;
        display: flex;
        justify-content: space-between;
        align-items: center;
        border-bottom: 1px solid #f1f5f9;
        background: #fafafa;
    }

    .premium-edit-header-left {
        display: flex;
        align-items: center;
        gap: 15px;
    }

    .edit-logo {
        width: 45px;
        height: auto;
    }

    .edit-title-group h2 {
        margin: 0;
        font-size: 22px;
        font-weight: 800;
        color: #111827;
        line-height: 1.2;
    }

    .edit-title-group p {
        margin: 4px 0 0 0;
        font-size: 14px;
        color: #6b7280;
        font-weight: 500;
    }

    .close-edit-btn {
        width: 40px;
        height: 40px;
        display: flex;
        align-items: center;
        justify-content: center;
        border-radius: 12px;
        cursor: pointer;
        transition: all 0.2s;
        border: none;
        background: transparent;
    }

    .close-edit-btn:hover {
        background: #f1f5f9;
        transform: rotate(90deg);
    }

    .close-edit-btn img {
        width: 18px;
        height: 18px;
        opacity: 0.5;
    }

    .premium-edit-body {
        padding: 40px;
        background: #ffffff;
    }

    .input-section-label {
        display: block;
        font-size: 14px;
        font-weight: 700;
        color: #111827;
        margin-bottom: 10px;
        margin-top: 25px;
    }

    .input-section-label:first-child {
        margin-top: 0;
    }

    .premium-grid-row {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 25px;
        margin-bottom: 25px;
    }

    .premium-input-box {
        position: relative;
    }

    .premium-input-box label {
        display: block;
        font-size: 13px;
        font-weight: 700;
        color: #111827;
        margin-bottom: 10px;
    }

    .input-inner-wrapper {
        position: relative;
    }

    .input-inner-icon {
        position: absolute;
        left: 20px;
        top: 50%;
        transform: translateY(-50%);
        width: 18px;
        height: 18px;
        opacity: 0.4;
        transition: opacity 0.3s;
    }

    .matched-input {
        width: 100%;
        padding: 16px 20px 16px 52px;
        background: #f9fafb;
        border: 1px solid transparent;
        border-radius: 16px;
        font-size: 15px;
        font-weight: 500;
        color: #111827;
        transition: all 0.3s ease;
    }
    
    .matched-textarea {
        width: 100%;
        padding: 16px 20px;
        background: #f9fafb;
        border: 1px solid transparent;
        border-radius: 16px;
        font-size: 15px;
        font-weight: 500;
        color: #111827;
        min-height: 100px;
        resize: vertical;
        transition: all 0.3s ease;
    }

    .matched-input:focus, .matched-textarea:focus {
        background: #ffffff;
        border-color: #f1f5f9;
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05);
        outline: none;
    }

    .premium-edit-footer {
        padding: 30px 40px;
        display: flex;
        justify-content: flex-end;
        align-items: center;
        gap: 20px;
        background: #ffffff;
        border-top: 1px solid #f1f5f9;
    }

    .matched-btn-cancel {
        color: #6b7280;
        background: none;
        border: none;
        font-size: 15px;
        font-weight: 600;
        cursor: pointer;
        padding: 10px 20px;
        transition: color 0.2s;
    }

    .matched-btn-cancel:hover {
        color: #111827;
    }

    .matched-btn-save {
        padding: 14px 35px;
        background: #F25C2A;
        color: #ffffff;
        border: none;
        border-radius: 16px;
        font-size: 15px;
        font-weight: 700;
        cursor: pointer;
        transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        box-shadow: 0 4px 15px rgba(242, 92, 42, 0.3);
    }

    .matched-btn-save:hover {
        background: #e54d1c;
        transform: translateY(-2px);
        box-shadow: 0 8px 20px rgba(242, 92, 42, 0.4);
    }

    .matched-btn-save:active {
        transform: translateY(0);
    }

    @media (max-width: 640px) {
        .premium-edit-header { padding: 25px; }
        .premium-grid-row { grid-template-columns: 1fr; gap: 20px; }
        .premium-edit-body { padding: 25px; }
    }
</style>

<div class="premium-edit-wrapper">
    <div class="premium-edit-header">
        <div class="premium-edit-header-left">
            <img src="../icons/logo-dark(1).png" alt="JOF" class="edit-logo">
            <div class="edit-title-group">
                <h2>Edit Profile</h2>
                <p>Update member personal details and measurements.</p>
            </div>
        </div>
        <button type="button" class="close-edit-btn" onclick="closeEditModal()">
            <img src="../icons/xmark-solid-full.svg" class="fa-solid fa-xmark">
        </button>
    </div>

    <form action="edit_member.php?id=<?= $member_id ?>" method="POST" id="editMemberForm">
        <div class="premium-edit-body">
            <?php if (isset($error)): ?>
                <div style="background: #fef2f2; border: 1px solid #fee2e2; color: #b91c1c; padding: 15px; border-radius: 12px; margin-bottom: 25px; font-size: 14px; display: flex; align-items: center; gap: 10px;">
                    <img src="../icons/circle-exclamation-solid-full.svg" style="width: 18px; height: 18px; filter: invert(18%) sepia(85%) saturate(4565%) hue-rotate(352deg) brightness(88%) contrast(96%);">
                    <?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>

            <label class="input-section-label">PERSONAL INFORMATION</label>
            
            <div class="premium-grid-row">
                <div class="premium-input-box">
                    <label for="full_name">Full Name</label>
                    <div class="input-inner-wrapper">
                        <img src="../icons/user-solid-full.svg" class="input-inner-icon">
                        <input type="text" id="full_name" name="full_name" class="matched-input" 
                               value="<?= htmlspecialchars($member['full_name']) ?>" required placeholder="Full Name">
                    </div>
                </div>
                <div class="premium-input-box">
                    <label for="age">Age</label>
                    <div class="input-inner-wrapper">
                        <img src="../icons/calendar-regular-full.svg" class="input-inner-icon">
                        <input type="number" id="age" name="age" class="matched-input" 
                               value="<?= $member['age'] ?>" required placeholder="Age">
                    </div>
                </div>
            </div>

            <div class="premium-grid-row">
                <div class="premium-input-box">
                    <label for="height">Height (cm)</label>
                    <div class="input-inner-wrapper">
                        <img src="../icons/ruler-vertical-solid-full.svg" class="input-inner-icon">
                        <input type="number" id="height" name="height" class="matched-input" 
                               value="<?= $member['height'] ?>" required placeholder="Height">
                    </div>
                </div>
                <div class="premium-input-box">
                    <label for="weight">Weight (kg)</label>
                    <div class="input-inner-wrapper">
                        <img src="../icons/weight-scale-solid-full.svg" class="input-inner-icon">
                        <input type="number" id="weight" name="weight" class="matched-input" 
                               value="<?= $member['weight'] ?>" required step="0.1" placeholder="Weight">
                    </div>
                </div>
            </div>

            <div style="margin-bottom: 25px;">
                <div class="premium-input-box">
                    <label for="email">Email Address</label>
                    <div class="input-inner-wrapper">
                        <img src="../icons/envelope-solid-full.svg" class="input-inner-icon">
                        <input type="email" id="email" name="email" class="matched-input" 
                               value="<?= htmlspecialchars($member['email']) ?>" required placeholder="Email Address">
                    </div>
                </div>
            </div>

            <div style="margin-bottom: 25px;">
                <div class="premium-input-box">
                    <label for="medical_issues">Medical Issues / Injuries</label>
                    <textarea id="medical_issues" name="medical_issues" class="matched-textarea" 
                              placeholder="Enter any medical conditions or injuries..."><?= htmlspecialchars($member['medical_issues']) ?></textarea>
                </div>
            </div>

            <label class="input-section-label">BODY MEASUREMENTS</label>

            <div class="premium-grid-row">
                <div class="premium-input-box">
                    <label for="chest_nipple_line">Chest (cm)</label>
                    <div class="input-inner-wrapper">
                        <img src="../icons/ruler-solid-full.svg" class="input-inner-icon">
                        <input type="number" id="chest_nipple_line" name="chest_nipple_line" class="matched-input" 
                               value="<?= $measurements['chest_nipple_line'] ?? '' ?>" step="0.1" placeholder="e.g. 100.0">
                    </div>
                </div>
                <div class="premium-input-box">
                    <label for="waist_navel_line">Waist (cm)</label>
                    <div class="input-inner-wrapper">
                        <img src="../icons/ruler-solid-full.svg" class="input-inner-icon">
                        <input type="number" id="waist_navel_line" name="waist_navel_line" class="matched-input" 
                               value="<?= $measurements['waist_navel_line'] ?? '' ?>" step="0.1" placeholder="e.g. 85.0">
                    </div>
                </div>
            </div>

            <div class="premium-grid-row">
                <div class="premium-input-box">
                    <label for="hip_widest_part">Hips (cm)</label>
                    <div class="input-inner-wrapper">
                        <img src="../icons/ruler-solid-full.svg" class="input-inner-icon">
                        <input type="number" id="hip_widest_part" name="hip_widest_part" class="matched-input" 
                               value="<?= $measurements['hip_widest_part'] ?? '' ?>" step="0.1" placeholder="e.g. 95.0">
                    </div>
                </div>
                <div class="premium-input-box">
                    <label for="thigh_mid">Thighs (cm)</label>
                    <div class="input-inner-wrapper">
                        <img src="../icons/ruler-solid-full.svg" class="input-inner-icon">
                        <input type="number" id="thigh_mid" name="thigh_mid" class="matched-input" 
                               value="<?= $measurements['thigh_mid'] ?? '' ?>" step="0.1" placeholder="e.g. 60.0">
                    </div>
                </div>
            </div>
        </div>

        <div class="premium-edit-footer">
            <button type="button" class="matched-btn-cancel" onclick="closeEditModal()" style="margin-right: auto; background-color: #f3f4f6; border: 1px solid #d1d5db; border-radius: 10px;">
                Cancel
            </button>
            <button type="submit" class="matched-btn-save">
                Save Changes
            </button>
        </div>
    </form>
</div>