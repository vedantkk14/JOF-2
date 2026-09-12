<?php
require_once __DIR__ . '/../auth/auth_check.php';
require_role(['admin', 'trainer']);
require_once __DIR__ . '/../config.php';

// Get member ID
$member_id = $_GET['id'] ?? null;

if (!$member_id) {
    die("Invalid member");
}

// Handle Adding New Progress (Photos + Measurements)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_progress') {

    $chest = $_POST['chest'] ?? 0;
    $waist = $_POST['waist'] ?? 0;
    $hips = $_POST['hips'] ?? 0;
    $thigh = $_POST['thigh'] ?? 0;

    // Helper for simple upload 
    function processProgressPhoto($fileInputName, $memberId)
    {
        if (!isset($_FILES[$fileInputName]) || $_FILES[$fileInputName]['error'] !== 0)
            return null;
        if ($_FILES[$fileInputName]['size'] > 10 * 1024 * 1024)
            return null; // 10MB Limit

        $ext = pathinfo($_FILES[$fileInputName]['name'], PATHINFO_EXTENSION);
        $baseFileName = $fileInputName . "_" . $memberId . "_" . time() . "." . $ext;
        $finalUploadPath = "../uploads/progress_photos/" . $baseFileName;

        if (move_uploaded_file($_FILES[$fileInputName]['tmp_name'], $finalUploadPath)) {
            return $baseFileName;
        }
        return null;
    }

    $front = processProgressPhoto('front_view', $member_id);
    $side = processProgressPhoto('side_view', $member_id);
    $back = processProgressPhoto('back_view', $member_id);

    // If no photos uploaded and no measurements, we just save what we have


    $sql = "INSERT INTO member_measurements (member_id, chest_nipple_line, waist_navel_line, hip_widest_part, thigh_mid, front_view_image, side_view_image, back_view_image, recorded_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "iddddsss", $member_id, $chest, $waist, $hips, $thigh, $front, $side, $back);

    if (mysqli_stmt_execute($stmt)) {
        header("Location: person_info.php?id=" . $member_id . "&msg=progress_updated");
        exit;
    } else {
        $error = "Failed to update progress.";
    }
}

// Handle Adding Trainer Note
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_note') {
    $note = trim($_POST['note']);
    if (!empty($note)) {
        $sql = "INSERT INTO trainer_notes (member_id, note, created_at) VALUES (?, ?, NOW())";
        $stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_bind_param($stmt, "is", $member_id, $note);
        if (mysqli_stmt_execute($stmt)) {
            header("Location: person_info.php?id=" . $member_id . "&msg=note_added");
            exit;
        } else {
            $error = "Failed to add note.";
        }
    }
}

// Handle Deleting Progress Photo
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_photo') {
    $viewToDelete = $_POST['view_to_delete'] ?? '';
    $recordIdToDelete = $_POST['record_id'] ?? null;
    $allowedViews = ['front_view_image', 'side_view_image', 'back_view_image'];

    if (in_array($viewToDelete, $allowedViews) && $recordIdToDelete) {
        // Find measurement record by ID to ensure it belongs to the member
        $sql = "SELECT id, $viewToDelete FROM member_measurements WHERE id = ? AND member_id = ?";
        $stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_bind_param($stmt, "ii", $recordIdToDelete, $member_id);
        mysqli_stmt_execute($stmt);
        $res = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

        if ($res && !empty($res[$viewToDelete])) {
            $imagePath = "../uploads/progress_photos/" . $res[$viewToDelete];

            // Delete file if it exists
            if (file_exists($imagePath)) {
                unlink($imagePath);
            }

            // Nullify the record for that specific view
            $updateSql = "UPDATE member_measurements SET $viewToDelete = NULL WHERE id = ?";
            $updateStmt = mysqli_prepare($conn, $updateSql);
            mysqli_stmt_bind_param($updateStmt, "i", $recordIdToDelete);
            mysqli_stmt_execute($updateStmt);

            header("Location: person_info.php?id=" . $member_id . "&msg=photo_deleted");
            exit;
        }
    }
}

// Fetch member details
$sql = "SELECT full_name, age, height, weight, medical_issues, email, gender, membership
        FROM members WHERE id = ?";
$stmt = mysqli_prepare($conn, $sql);
mysqli_stmt_bind_param($stmt, "i", $member_id);
mysqli_stmt_execute($stmt);
$member = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

if (!$member) {
    die("Member not found");
}

// Calculate BMI
$bmi = null;
if ($member['height'] && $member['weight']) {
    $h = $member['height'] / 100;
    $bmi = round($member['weight'] / ($h * $h), 1);
}

// Fetch LATEST measurements
$sql = "SELECT * FROM member_measurements WHERE member_id = ? ORDER BY recorded_at DESC LIMIT 1";
$stmt = mysqli_prepare($conn, $sql);
mysqli_stmt_bind_param($stmt, "i", $member_id);
mysqli_stmt_execute($stmt);
$latest_measure = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

// Fetch latest metrics
$sql = "SELECT mood, sleep_quality, energy_level, hunger_craving
        FROM metrics
        WHERE member_id = ?
        ORDER BY recorded_at DESC
        LIMIT 1";

$stmt = mysqli_prepare($conn, $sql);
mysqli_stmt_bind_param($stmt, "i", $member_id);
mysqli_stmt_execute($stmt);
$metrics = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

// Fetch ALL valid images for each view chronologically to weave a CSS "Single Collage"
$all_view_images = [];
$views = ['front_view_image', 'side_view_image', 'back_view_image'];
foreach ($views as $viewCol) {
    $sql_all = "SELECT id, $viewCol, recorded_at FROM member_measurements WHERE member_id = ? AND $viewCol IS NOT NULL AND $viewCol != '' ORDER BY recorded_at ASC";
    $stmt_all = mysqli_prepare($conn, $sql_all);
    mysqli_stmt_bind_param($stmt_all, "i", $member_id);
    mysqli_stmt_execute($stmt_all);
    $res_all = mysqli_stmt_get_result($stmt_all);
    $all_view_images[$viewCol] = [];
    while ($row = mysqli_fetch_assoc($res_all)) {
        $all_view_images[$viewCol][] = $row;
    }
}

// Fetch trainer notes
$sql = "SELECT note, created_at
        FROM trainer_notes
        WHERE member_id = ?
        ORDER BY created_at DESC";

$stmt = mysqli_prepare($conn, $sql);
mysqli_stmt_bind_param($stmt, "i", $member_id);
mysqli_stmt_execute($stmt);
$notes_result = mysqli_stmt_get_result($stmt);

// Fetch membership history
$sql_mem_hist = "SELECT membership_type, diet_type, duration_months, start_date, end_date, total_amount, amount_received, payment_mode, transaction_id, created_at 
                 FROM member_payments 
                 WHERE member_id = ? 
                 ORDER BY created_at DESC";
$stmt_mem = mysqli_prepare($conn, $sql_mem_hist);
mysqli_stmt_bind_param($stmt_mem, "i", $member_id);
mysqli_stmt_execute($stmt_mem);
$mem_history_result = mysqli_stmt_get_result($stmt_mem);

// Latest plan + pause info for the "Pause Membership" action
$pauseStmt = mysqli_prepare($conn, "SELECT payment_id, end_date FROM member_payments WHERE member_id = ? ORDER BY created_at DESC LIMIT 1");
mysqli_stmt_bind_param($pauseStmt, "i", $member_id);
mysqli_stmt_execute($pauseStmt);
$latest_plan = mysqli_fetch_assoc(mysqli_stmt_get_result($pauseStmt));
$can_pause_member = $latest_plan && !empty($latest_plan['end_date']);

require_once __DIR__ . '/../auth/membership_helper.php';
membership_pauses_ensure_schema($conn);

// Pauses only (extensions are shown separately below)
$pauseAgg = mysqli_query($conn, "
    SELECT COALESCE(SUM(days),0) AS total_days,
           MAX(CASE WHEN CURDATE() BETWEEN pause_start AND pause_end THEN pause_end END) AS active_pause_end
    FROM membership_pauses WHERE kind = 'pause' AND member_id = " . (int) $member_id);
$pause_info = $pauseAgg ? mysqli_fetch_assoc($pauseAgg) : ['total_days' => 0, 'active_pause_end' => null];

// Most recent plan extension (running or finished) for the extension notice
$extStmt = mysqli_prepare($conn, "SELECT days, reason, end_date_before, end_date_after, created_at
    FROM membership_pauses WHERE member_id = ? AND kind = 'extension' ORDER BY id DESC LIMIT 1");
mysqli_stmt_bind_param($extStmt, "i", $member_id);
mysqli_stmt_execute($extStmt);
$extension_info = mysqli_fetch_assoc(mysqli_stmt_get_result($extStmt)) ?: null;
$extension_running = $extension_info && $extension_info['end_date_after'] >= date('Y-m-d');

// Fetch ALL measurements for Gallery
$sql = "SELECT id, front_view_image, side_view_image, back_view_image, recorded_at 
        FROM member_measurements 
        WHERE member_id = ? 
        ORDER BY recorded_at DESC"; // Newest first
$stmt = mysqli_prepare($conn, $sql);
mysqli_stmt_bind_param($stmt, "i", $member_id);
mysqli_stmt_execute($stmt);
$history_result = mysqli_stmt_get_result($stmt);

$progress_history = [];
while ($row = mysqli_fetch_assoc($history_result)) {
    // Only add if at least one image exists
    if ($row['front_view_image'] || $row['side_view_image'] || $row['back_view_image']) {
        $progress_history[] = $row;
    }
}
$progress_history_json = json_encode($progress_history);

// Define image path for physique photos
$img_path = '../uploads/progress_photos/';
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" size="16x16" href="../icons/favicon-dark-logo.png" type="image/png">
    <title>Member Profile | JOF INDIA</title>
    <link rel="stylesheet" href="../static/root.css?v=<?= @filemtime(__DIR__ . '/../static/root.css') ?>">

    <style>
        .premium-edit-footer {
            padding: 45px 5px;
            display: flex;
            justify-content: flex-end;
            align-items: center;
            gap: 25px;
            border-top: 3px solid #f1f5f9;
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
</head>

<body class="page-person_info page-modal-styles">

    <button class="mobile-toggle" id="mobileToggle">
        <img src="../icons/bars-solid-full.svg" class="fa-solid fa-bars">
    </button>
    <button class="toggle-sidebar-btn" id="toggleBtn">
        <img src="../icons/chevron-left-solid-full.svg" class="fa-solid fa-chevron-left">
    </button>

    <div class="dashboard-container">

        <?php include 'sidebar.php'; ?>

        <main class="main-content">

            <div class="profile-header">
                <div class="user-identity">
                    <img src="https://ui-avatars.com/api/?name=<?= urlencode($member['full_name']) ?>&background=random&color=fff"
                        class="profile-avatar" alt="Profile">
                    <div class="user-text">
                        <h1><?= htmlspecialchars($member['full_name']) ?></h1>
                        <p class="member-id">Member ID: #<?= str_pad($member_id, 4, '0', STR_PAD_LEFT) ?></p>
                        <span class="status-badge">Active Member</span>
                    </div>
                </div>

                <div class="header-actions">
                    <a href="members.php" class="back-btn">
                        <img src="../icons/arrow-left-solid-full.svg" class="fa-solid fa-arrow-left"> Back to Members
                    </a>
                    <button class="action-btn" onclick="openEditModal(<?= $member_id ?>)">
                        <img src="../icons/pen-to-square-solid-full.svg" class="fa-solid fa-pen-to-square"> Edit Info
                    </button>

                </div>
            </div>
            <div id="editMemberModal" class="modal">
                <div class="modal-content" style="background: transparent; box-shadow: none; overflow: visible; max-height: none;">
                    <div id="editMemberFormContainer"></div>
                </div>
            </div>


            <div class="profile-grid">

                <div class="column-left">

                    <div class="info-card">
                        <h3><img src="../icons/file-medical-solid-full.svg" class="fa-solid fa-file-medical card-icon"> Health Details</h3>

                        <div class="details-grid">
                            <div class="detail-box">
                                <label>Age</label>
                                <span><?= $member['age'] ?? '-' ?></span>
                            </div>
                            <div class="detail-box">
                                <label>Height</label>
                                <span><?= $member['height'] ? $member['height'] . ' cm' : '-' ?></span>
                            </div>
                            <div class="detail-box">
                                <label>Weight</label>
                                <span><?= $member['weight'] ? $member['weight'] . ' kg' : '-' ?></span>
                            </div>
                            <div class="detail-box">
                                <label>BMI</label>
                                <span><?= $bmi ?? '-' ?></span>
                            </div>
                            <div class="detail-box">
                                <label>Gender</label>
                                <span class="text-capitalize"><?= !empty($member['gender']) ? htmlspecialchars($member['gender']) : '-' ?></span>
                            </div>
                        </div>

                        <div class="detail-full">
                            <label>Medical Issues / Injuries</label>
                            <p>
                                <?= !empty($member['medical_issues']) ? nl2br(htmlspecialchars($member['medical_issues'])) : 'None. No issues recorded.' ?>
                            </p>
                        </div>

                        <div class="detail-full">
                            <label>Email Address</label>
                            <p><?= htmlspecialchars($member['email']) ?></p>
                        </div>
                    </div>

                    <div class="info-card">
                        <h3><img src="../icons/sliders-solid-full.svg" class="fa-solid fa-sliders card-icon"> Self Assessment</h3>
                        <?php if ($metrics): ?>

                            <div class="scale-item">
                                <div class="scale-label">
                                    <span>Mood</span>
                                    <strong><?= $metrics['mood'] ?>/10</strong>
                                </div>
                                <div class="progress-track">
                                    <div class="progress-fill <?= $metrics['mood'] > 7 ? 'green' : ($metrics['mood'] > 4 ? 'orange' : 'red') ?>"
                                        style="width: <?= $metrics['mood'] * 10 ?>%;"></div>
                                </div>
                            </div>

                            <div class="scale-item">
                                <div class="scale-label">
                                    <span>Sleep Quality</span>
                                    <strong><?= $metrics['sleep_quality'] ?>/10</strong>
                                </div>
                                <div class="progress-track">
                                    <div class="progress-fill <?= $metrics['sleep_quality'] > 7 ? 'green' : ($metrics['sleep_quality'] > 4 ? 'orange' : 'red') ?>"
                                        style="width: <?= $metrics['sleep_quality'] * 10 ?>%;"></div>
                                </div>
                            </div>

                            <div class="scale-item">
                                <div class="scale-label">
                                    <span>Energy Level</span>
                                    <strong><?= $metrics['energy_level'] ?>/10</strong>
                                </div>
                                <div class="progress-track">
                                    <div class="progress-fill <?= $metrics['energy_level'] > 7 ? 'green' : ($metrics['energy_level'] > 4 ? 'orange' : 'red') ?>"
                                        style="width: <?= $metrics['energy_level'] * 10 ?>%;"></div>
                                </div>
                            </div>

                            <div class="scale-item">
                                <div class="scale-label">
                                    <span>Hunger Control</span>
                                    <strong><?= $metrics['hunger_craving'] ?>/10</strong>
                                </div>
                                <div class="progress-track">
                                    <div class="progress-fill <?= $metrics['hunger_craving'] > 7 ? 'green' : ($metrics['hunger_craving'] > 4 ? 'orange' : 'red') ?>"
                                        style="width: <?= $metrics['hunger_craving'] * 10 ?>%;"></div>
                                </div>
                            </div>

                        <?php else: ?>
                            <p style="color:var(--text-muted);">No self-assessment recorded.</p>
                        <?php endif; ?>
                    </div>



                </div>

                <div class="column-right">

                    <div class="info-card">
                        <h3><img src="../icons/ruler-combined-solid-full.svg" class="fa-solid fa-ruler-combined card-icon"> Measurements</h3>
                        <?php if ($latest_measure): ?>
                            <div class="measure-grid">
                                <div class="measure-item">
                                    <span class="m-label">Chest</span>
                                    <span class="m-val"><?= $latest_measure['chest_nipple_line'] ?> inches</span>
                                </div>
                                <div class="measure-item">
                                    <span class="m-label">Waist</span>
                                    <span class="m-val"><?= $latest_measure['waist_navel_line'] ?> inches</span>
                                </div>
                                <div class="measure-item">
                                    <span class="m-label">Hips</span>
                                    <span class="m-val"><?= $latest_measure['hip_widest_part'] ?> inches</span>
                                </div>
                                <div class="measure-item">
                                    <span class="m-label">Thighs</span>
                                    <span class="m-val"><?= $latest_measure['thigh_mid'] ?> inches</span>
                                </div>
                            </div>
                        <?php else: ?>
                            <p style="color:var(--text-muted); font-size:14px;">No measurements recorded yet.</p>
                        <?php endif; ?>
                    </div>



                    <div class="info-card">
                        <h3><img src="../icons/clipboard-solid-full.svg" class="fa-solid fa-clipboard card-icon"> Trainer Notes</h3>

                        <div class="notes-history">
                            <?php if (mysqli_num_rows($notes_result) > 0): ?>
                                <?php while ($note = mysqli_fetch_assoc($notes_result)): ?>
                                    <div class="note-item">
                                        <div class="note-date"><?= date("d M Y", strtotime($note['created_at'])) ?></div>
                                        <p><?= htmlspecialchars($note['note']) ?></p>
                                    </div>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <div class="note-item">
                                    <p>No notes yet.</p>
                                </div>
                            <?php endif; ?>
                        </div>

                        <form method="POST" class="add-note-area">
                            <input type="hidden" name="action" value="add_note">
                            <label>Add New Note</label>
                            <textarea name="note" placeholder="Write observation here..." required></textarea>
                            <button type="submit" class="save-note-btn">Save Note</button>
                        </form>
                    </div>

                    <div class="info-card mt-20">
                        <div class="card-header-flex flex-between">
                            <h3><img src="../icons/id-card-solid-full.svg" class="fa-solid fa-id-card card-icon"> Membership & Plan History</h3>
                            <?php if ($can_pause_member): ?>
                                <button type="button" class="action-btn btn-small"
                                    onclick="openPauseModal(<?= (int) $member_id ?>, '<?= htmlspecialchars(addslashes($member['full_name']), ENT_QUOTES) ?>', '<?= htmlspecialchars($latest_plan['end_date']) ?>')">
                                    <img src="../icons/pause-solid-full.svg" class="fa-solid fa-pause"> Pause
                                </button>
                            <?php endif; ?>
                        </div>
                        <?php if (!empty($pause_info['active_pause_end'])): ?>
                            <p style="margin:8px 0 0;font-size:13px;color:#4338CA;font-weight:600;">
                                Plan currently paused &mdash; resumes <?= date('d M Y', strtotime($pause_info['active_pause_end'])) ?>.
                            </p>
                        <?php elseif ((int) $pause_info['total_days'] > 0): ?>
                            <p style="margin:8px 0 0;font-size:13px;color:#6366F1;font-weight:600;">
                                +<?= (int) $pause_info['total_days'] ?> paused day<?= (int) $pause_info['total_days'] === 1 ? '' : 's' ?> already added to this plan.
                            </p>
                        <?php endif; ?>

                        <?php if ($extension_info):
                            $ext_days = (int) $extension_info['days']; ?>
                            <style>
                                .ext-callout { display: flex; gap: 14px; margin-top: 14px; padding: 14px 16px; border-radius: 14px; background: #F0F9FF; border: 1px solid #BAE6FD; }
                                .ext-callout.past { background: #F9FAFB; border-color: #E5E7EB; }
                                .ext-callout-ic { width: 38px; height: 38px; flex-shrink: 0; border-radius: 11px; background: #0EA5E9; color: #fff; display: flex; align-items: center; justify-content: center; }
                                .ext-callout.past .ext-callout-ic { background: #9CA3AF; }
                                .ext-callout-ic svg { width: 18px; height: 18px; }
                                .ext-callout-title { font-size: 14px; font-weight: 700; color: #0C4A6E; display: flex; align-items: center; flex-wrap: wrap; gap: 8px; }
                                .ext-callout.past .ext-callout-title { color: #374151; }
                                .ext-chip { font-size: 10.5px; font-weight: 800; letter-spacing: .03em; text-transform: uppercase; padding: 2px 8px; border-radius: 999px; background: #0EA5E9; color: #fff; }
                                .ext-callout.past .ext-chip { background: #E5E7EB; color: #6B7280; }
                                .ext-callout-meta { display: flex; flex-wrap: wrap; gap: 6px 18px; margin-top: 6px; font-size: 12.5px; color: #475569; }
                                .ext-callout-meta b { color: #0F172A; font-weight: 700; }
                                .ext-callout-reason { margin-top: 8px; font-size: 12.5px; color: #475569; font-style: italic; }
                            </style>
                            <div class="ext-callout<?= $extension_running ? '' : ' past' ?>">
                                <div class="ext-callout-ic">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="17" rx="2"/><path d="M16 2v4M8 2v4M3 10h18M12 13v5M9.5 15.5h5"/></svg>
                                </div>
                                <div>
                                    <div class="ext-callout-title">
                                        Plan extended by <?= $ext_days ?> day<?= $ext_days === 1 ? '' : 's' ?>
                                        <span class="ext-chip"><?= $extension_running ? 'Active' : 'Ended' ?></span>
                                    </div>
                                    <div class="ext-callout-meta">
                                        <span>Extended on <b><?= date('d M Y', strtotime($extension_info['created_at'])) ?></b></span>
                                        <span>Previously ended <b><?= date('d M Y', strtotime($extension_info['end_date_before'])) ?></b></span>
                                        <span><?= $extension_running ? 'Now valid until' : 'Ran until' ?> <b><?= date('d M Y', strtotime($extension_info['end_date_after'])) ?></b></span>
                                    </div>
                                    <?php if (trim((string) $extension_info['reason']) !== ''): ?>
                                        <div class="ext-callout-reason">Reason: <?= htmlspecialchars($extension_info['reason']) ?></div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endif; ?>
                        <div class="plan-history-container mt-15">
                            <?php if (mysqli_num_rows($mem_history_result) > 0): ?>
                                <?php while ($plan = mysqli_fetch_assoc($mem_history_result)): ?>
                                    <div class="plan-history-item">
                                        <div class="plan-history-header">
                                            <div>
                                                <strong class="plan-type-title"><?= htmlspecialchars($plan['membership_type']) ?></strong>
                                                <span class="plan-purchase-date">Purchased: <?= date("d M Y", strtotime($plan['created_at'])) ?></span>
                                            </div>
                                            <span class="plan-duration-badge"><?= $plan['duration_months'] ?> Weeks</span>
                                        </div>

                                        <div class="plan-details-grid">
                                            <div class="plan-detail-box">
                                                <span class="plan-detail-label">Diet Plan</span>
                                                <strong class="plan-detail-value text-capitalize"><?= htmlspecialchars($plan['diet_type']) ?></strong>
                                            </div>
                                            <div class="plan-detail-box">
                                                <span class="plan-detail-label">Validity</span>
                                                <strong class="plan-detail-value"><?= date("d M Y", strtotime($plan['start_date'])) ?> - <?= date("d M Y", strtotime($plan['end_date'])) ?></strong>
                                            </div>
                                        </div>

                                        <div class="plan-payment-info">
                                            <div>
                                                <span class="plan-detail-label">Total Amount</span>
                                                <strong class="payment-amount">₹<?= number_format($plan['amount_received']) ?>
                                                    <span class="payment-total">/ ₹<?= number_format($plan['total_amount']) ?></span></strong>
                                            </div>
                                            <div class="text-right">
                                                <span class="payment-mode-badge"><?= htmlspecialchars($plan['payment_mode']) ?></span>
                                                <?php if (strtolower($plan['payment_mode']) === 'upi' && !empty($plan['transaction_id'])): ?>
                                                    <div class="txn-id-label">TXN: <?= htmlspecialchars($plan['transaction_id']) ?></div>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <div class="empty-state">
                                    <p>No membership history found.</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                </div>

            </div>

            <!-- Physique Progress Section (Full Width) -->
            <div class="info-card mt-30">
                <div class="card-header-flex flex-between">
                    <h3><img src="../icons/camera-solid-full.svg" class="fa-solid fa-camera card-icon"> Physique Progress</h3>
                    <button class="action-btn btn-small" onclick="openProgressModal()">
                        <img src="../icons/plus-solid-full.svg" class="fa-solid fa-plus"> Update
                    </button>
                </div>

                

                <div class="physique-views-grid">
                    <!-- Front View -->
                    <div class="view-column">
                        <div class="comparison-card">
                            <div class="photo-label">Front View</div>
                            <?php if (!empty($all_view_images['front_view_image'])): ?>
                                <div class="collage-container">
                                    <?php foreach ($all_view_images['front_view_image'] as $img): ?>
                                        <div class="collage-item">
                                            <form method="POST" class="btn-delete-photo"
                                                onsubmit="return confirm('Delete this progress photo?');">
                                                <input type="hidden" name="action" value="delete_photo">
                                                <input type="hidden" name="view_to_delete" value="front_view_image">
                                                <input type="hidden" name="record_id" value="<?= $img['id'] ?>">
                                                <button type="submit" class="btn-reset">
                                                    <img src="../icons/trash-solid-full.svg" class="fa-solid fa-trash btn-delete-photo-icon">
                                                </button>
                                            </form>
                                            <img class="compare-img"
                                                src="<?= $img_path . htmlspecialchars($img['front_view_image']) ?>"
                                                onclick="openGallery('front_view_image')" alt="Front View">
                                            <div class="collage-date-label">
                                                <?= date('d M Y', strtotime($img['recorded_at'])) ?>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <div class="empty-photo-box">
                                    <img src="../icons/camera-solid-full.svg" class="fa-solid fa-camera">
                                    <span>No Front View</span>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Side View -->
                    <div class="view-column">
                        <div class="comparison-card">
                            <div class="photo-label">Side View</div>
                            <?php if (!empty($all_view_images['side_view_image'])): ?>
                                <div class="collage-container">
                                    <?php foreach ($all_view_images['side_view_image'] as $img): ?>
                                        <div class="collage-item">
                                            <form method="POST" class="btn-delete-photo"
                                                onsubmit="return confirm('Delete this progress photo?');">
                                                <input type="hidden" name="action" value="delete_photo">
                                                <input type="hidden" name="view_to_delete" value="side_view_image">
                                                <input type="hidden" name="record_id" value="<?= $img['id'] ?>">
                                                <button type="submit" class="btn-reset">
                                                    <img src="../icons/trash-solid-full.svg" class="fa-solid fa-trash btn-delete-photo-icon">
                                                </button>
                                            </form>
                                            <img class="compare-img"
                                                src="<?= $img_path . htmlspecialchars($img['side_view_image']) ?>"
                                                onclick="openGallery('side_view_image')" alt="Side View">
                                            <div class="collage-date-label">
                                                <?= date('d M Y', strtotime($img['recorded_at'])) ?>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <div class="empty-photo-box">
                                    <img src="../icons/camera-solid-full.svg" class="fa-solid fa-camera">
                                    <span>No Side View</span>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Back View -->
                    <div class="view-column">
                        <div class="comparison-card">
                            <div class="photo-label">Back View</div>
                            <?php if (!empty($all_view_images['back_view_image'])): ?>
                                <div class="collage-container">
                                    <?php foreach ($all_view_images['back_view_image'] as $img): ?>
                                        <div class="collage-item">
                                            <form method="POST" class="btn-delete-photo"
                                                onsubmit="return confirm('Delete this progress photo?');">
                                                <input type="hidden" name="action" value="delete_photo">
                                                <input type="hidden" name="view_to_delete" value="back_view_image">
                                                <input type="hidden" name="record_id" value="<?= $img['id'] ?>">
                                                <button type="submit" class="btn-reset">
                                                    <img src="../icons/trash-solid-full.svg" class="fa-solid fa-trash btn-delete-photo-icon">
                                                </button>
                                            </form>
                                            <img class="compare-img"
                                                src="<?= $img_path . htmlspecialchars($img['back_view_image']) ?>"
                                                onclick="openGallery('back_view_image')" alt="Back View">
                                            <div class="collage-date-label">
                                                <?= date('d M Y', strtotime($img['recorded_at'])) ?>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <div class="empty-photo-box">
                                    <img src="../icons/camera-solid-full.svg" class="fa-solid fa-camera">
                                    <span>No Back View</span>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Progress Update Modal -->
            <div id="progressModal" class="modal">
                <div class="modal-content modal-wrapper">
                    <div class="modal-header-simple">
                        <span class="modal-title-simple"><img src="../icons/camera-solid-full.svg" class="fa-solid fa-camera" style="color:#F25C2A; font-size:16px;"> Update Progress</span>
                        <span onclick="closeProgressModal()" class="modal-close-icon">&times;</span>
                    </div>
                    <div class="modal-body-simple">
                    <form action="" method="POST" enctype="multipart/form-data">
                        <input type="hidden" name="action" value="update_progress">

                        <div class="upload-section plan-detail-box mb-20">
                            <h4 class="plan-detail-label mb-15">Physique Photos (Max 10MB)</h4>

                            <div class="file-input-group">
                                <label class="custom-file-upload">
                                    <input type="file" name="front_view" accept="image/*"
                                        onchange="updateFileName(this)">
                                    <img src="../icons/camera-solid-full.svg" class="fa-solid fa-camera"> Front View
                                </label>
                                <span class="file-name">No file chosen</span>
                            </div>

                            <div class="file-input-group">
                                <label class="custom-file-upload">
                                    <input type="file" name="side_view" accept="image/*"
                                        onchange="updateFileName(this)">
                                    <img src="../icons/camera-solid-full.svg" class="fa-solid fa-camera"> Side View
                                </label>
                                <span class="file-name">No file chosen</span>
                            </div>

                            <div class="file-input-group">
                                <label class="custom-file-upload">
                                    <input type="file" name="back_view" accept="image/*"
                                        onchange="updateFileName(this)">
                                    <img src="../icons/camera-solid-full.svg" class="fa-solid fa-camera"> Back View
                                </label>
                                <span class="file-name">No file chosen</span>
                            </div>
                        </div>

                        <div class="measure-section">
                            <h4 class="plan-detail-label mb-15">Current Measurements (cm)</h4>

                            <div style="display:grid; grid-template-columns: 1fr 1fr; gap:15px;">
                                <div class="form-group">
                                    <label style="font-size:13px; font-weight:600; color:#374151;">Chest</label>
                                    <input type="number" name="chest" step="0.1" class="form-input styled-input"
                                        value="<?= $latest_measure['chest_nipple_line'] ?? '' ?>" placeholder="0.0">
                                </div>
                                <div class="form-group">
                                    <label style="font-size:13px; font-weight:600; color:#374151;">Waist</label>
                                    <input type="number" name="waist" step="0.1" class="form-input styled-input"
                                        value="<?= $latest_measure['waist_navel_line'] ?? '' ?>" placeholder="0.0">
                                </div>
                                <div class="form-group">
                                    <label style="font-size:13px; font-weight:600; color:#374151;">Hips</label>
                                    <input type="number" name="hips" step="0.1" class="form-input styled-input"
                                        value="<?= $latest_measure['hip_widest_part'] ?? '' ?>" placeholder="0.0">
                                </div>
                                <div class="form-group">
                                    <label style="font-size:13px; font-weight:600; color:#374151;">Thighs</label>
                                    <input type="number" name="thigh" step="0.1" class="form-input styled-input"
                                        value="<?= $latest_measure['thigh_mid'] ?? '' ?>" placeholder="0.0">
                                </div>
                            </div>
                        </div>
                        <div class="premium-edit-footer">
                            <button type="button" class="matched-btn-cancel" onclick="closeProgressModal()" style="margin-right: auto; background-color: #f3f4f6; border: 1px solid #d1d5db; border-radius: 10px;">
                                Cancel
                            </button>
                            <button type="submit" class="matched-btn-save">
                                Save Changes
                            </button>
                        </div>
                    </form>
                    </div>
                </div>
            </div>
            <script>
                // Progress Modal Logic
                const progressModal = document.getElementById('progressModal');
                const editMemberModal = document.getElementById('editMemberModal');
                const imageModal = document.getElementById('imageModal'); // For gallery

                function openProgressModal() {
                    if(progressModal) progressModal.style.display = "flex";
                }
                function closeProgressModal() {
                    if(progressModal) progressModal.style.display = "none";
                }

                // Unified click handler to close modals when clicking on overlay
                window.addEventListener('click', function(event) {
                    if (event.target === progressModal) closeProgressModal();
                    if (event.target === editMemberModal) {
                        if(typeof closeEditModal === 'function') closeEditModal();
                        else editMemberModal.style.display = "none";
                    }
                    if (event.target === imageModal) {
                         if(imageModal) imageModal.style.display = "none";
                    }
                });
                function updateFileName(input) {
                    const fileNameSpan = input.parentElement.nextElementSibling;
                    if (input.files && input.files.length > 0) {
                        fileNameSpan.textContent = input.files[0].name;
                        fileNameSpan.style.color = '#111827';
                    } else {
                        fileNameSpan.textContent = 'No file chosen';
                        fileNameSpan.style.color = '#6b7280';
                    }
                }
            </script>

        </main>
    </div>

    <!-- Gallery Modal -->
    <div id="imageModal" class="image-modal">
        <span class="close-image-modal">&times;</span>

        <!-- We'll populate this with a flex layout in JS -->
        <div id="modalGalleryContainer" class="gallery-modal-container">
        </div>
    </div>

    <script>
        // Pass PHP data to JS
        const progressHistory = <?= $progress_history_json ?>; /* Array of {front_view_image, side_view_image, back_view_image, recorded_at} */
        let currentViewType = ''; // 'front_view_image', 'side_view_image', 'back_view_image'
        let galleryImages = []; // Will hold the filtered list based on view type

        const modal = document.getElementById("imageModal");
        const modalGalleryContainer = document.getElementById("modalGalleryContainer");
        const imgPath = '<?= $img_path ?>';

        // Function to open gallery
        window.openGallery = function (viewType) {
            currentViewType = viewType;

            galleryImages = progressHistory
                .filter(item => item[viewType])
                .map(item => ({
                    id: item.id,
                    src: imgPath + item[viewType],
                    date: new Date(item.recorded_at).toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' })
                }));

            if (galleryImages.length === 0) return;

            // Reverse so oldest shows first initially left-to-right (if PHP was DESC)
            galleryImages.reverse();

            modalGalleryContainer.innerHTML = ''; // clear

            galleryImages.forEach(img => {
                const itemDiv = document.createElement('div');
                itemDiv.style.flex = "0 0 auto";
                itemDiv.style.display = "flex";
                itemDiv.style.flexDirection = "column";
                itemDiv.style.alignItems = "center";

                const imgEl = document.createElement('img');
                imgEl.src = img.src;
                imgEl.style.width = "800px";
                imgEl.style.height = "800px";
                imgEl.style.objectFit = "contain";
                imgEl.style.background = "#e5e7eb";
                imgEl.style.borderRadius = "6px";
                imgEl.classList.add("modal-content");
                imgEl.style.padding = "0";

                const dateDiv = document.createElement('div');
                dateDiv.style.marginTop = "15px";
                dateDiv.style.textAlign = "center";
                dateDiv.style.fontSize = "16px";
                dateDiv.style.color = "white";
                dateDiv.style.fontWeight = "500";
                dateDiv.textContent = img.date;

                const formEl = document.createElement('form');
                formEl.method = "POST";
                formEl.style.marginTop = "20px";
                formEl.onsubmit = function () {
                    return confirm('Are you sure you want to delete this specific photo upload?');
                };

                formEl.innerHTML = `
                    <input type="hidden" name="action" value="delete_photo">
                    <input type="hidden" name="view_to_delete" value="${currentViewType}">
                    <input type="hidden" name="record_id" value="${img.id}">
                    <button type="submit" style="background: rgba(239, 68, 68, 0.9); color: white; border: none; border-radius: 6px; padding: 10px 20px; font-size: 14px; cursor: pointer; display: inline-flex; align-items: center; justify-content: center; box-shadow: 0 2px 4px rgba(0,0,0,0.2);" title="Delete This Photo">
                        <img src="../icons/trash-solid-full.svg" class="fa-solid fa-trash" style="margin-right: 8px;"> Delete This History Image
                    </button>
                `;

                itemDiv.appendChild(imgEl);
                itemDiv.appendChild(dateDiv);
                itemDiv.appendChild(formEl);

                modalGalleryContainer.appendChild(itemDiv);
            });

            modal.style.display = "flex";
            modal.style.alignItems = "center";
            modal.style.justifyContent = "center";
        }

        // Close logic
        document.querySelector(".close-image-modal").onclick = function () { modal.style.display = "none"; }


        // Previous openEditModal Logic
        window.openEditModal = function (memberId) { // Make global
            console.log("Edit clicked:", memberId);
            fetch(`edit_member.php?id=${memberId}`)
                .then(res => res.text())
                .then(html => {
                    document.getElementById("editMemberFormContainer").innerHTML = html;
                    document.getElementById("editMemberModal").style.display = "flex";
                })
                .catch(err => {
                    console.error("Error loading edit form:", err);
                    alert("Failed to load edit form. Please try again.");
                });
        }
        window.closeEditModal = function () { // Make global
            document.getElementById("editMemberModal").style.display = "none";
        }

    </script>

    <script>
        // Rest of Sidebar Logic
        document.addEventListener('DOMContentLoaded', function () {
            // Sidebar Toggles (Existing Code)
            const toggleBtn = document.getElementById('toggleBtn');
            const mobileToggle = document.getElementById('mobileToggle');
            const body = document.body;
            const sidebar = document.querySelector('.sidebar');

            if (toggleBtn) {
                toggleBtn.addEventListener('click', function () { body.classList.toggle('collapsed'); });
            }
            if (mobileToggle) {
                mobileToggle.addEventListener('click', function () { body.classList.toggle('sidebar-open'); });
            }

            document.addEventListener('click', function (event) {
                const isClickInsideSidebar = sidebar.contains(event.target);
                const isClickOnToggle = mobileToggle.contains(event.target);
                if (!isClickInsideSidebar && !isClickOnToggle && body.classList.contains('sidebar-open') && window.innerWidth <= 992) {
                    body.classList.remove('sidebar-open');
                }
            });

            // Dropdown Logic (Existing Code)
            const dropdownItems = document.querySelectorAll('.nav-item-dropdown');
            dropdownItems.forEach(item => {
                const link = item.querySelector('.nav-link');
                const arrow = link ? link.querySelector('.nav-arrow') : null;
                if (arrow) {
                    arrow.addEventListener('click', function (e) {
                        e.preventDefault(); e.stopPropagation();
                        dropdownItems.forEach(otherItem => { if (otherItem !== item) otherItem.classList.remove('active'); });
                        item.classList.toggle('active');
                    });
                }
            });
        });

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

    <?php
    $pause_redirect = 'person_info.php';
    $pause_redirect_id = (int) $member_id;
    include '_pause_modal.php';
    ?>

    <?php if (isset($_GET['pause_ok']) || isset($_GET['pause_err'])): ?>
    <script>
        window.addEventListener('DOMContentLoaded', function () {
            <?php if (isset($_GET['pause_ok'])): ?>
            alert('Membership paused. <?= (int) $_GET['pause_ok'] ?> day(s) added to the plan end date.');
            <?php else: ?>
            alert(<?= json_encode($_GET['pause_err']) ?>);
            <?php endif; ?>
            history.replaceState(null, '', 'person_info.php?id=<?= (int) $member_id ?>');
        });
    </script>
    <?php endif; ?>

</body>


</html>