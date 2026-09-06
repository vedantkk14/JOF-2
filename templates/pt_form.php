<?php
session_start();
require '../config.php';

if(!isset($_SESSION['user_id'])) {
    header("Location: login_page.html");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {

    $member_id = $_POST['member_id'] ?? '';
    $trainer_id = $_POST['trainer_id'] ?? '';
    $total_sessions = $_POST['total_sessions'] ?? '';
    $pt_fees = $_POST['pt_fees'] ?? '';
    $start_date = $_POST['start_date'] ?? '';
    $end_date = $_POST['end_date'] ?? '';

    if(
        $member_id === '' || $trainer_id === '' ||
        $total_sessions === '' || $pt_fees === '' ||
        $start_date === '' || $end_date === ''
    ) {
        die("All fields are required.");
    }

    // 1. Fetch member name
    // (We keep this because your table uses it, based on previous context)
    $nameQuery = mysqli_prepare($conn, "SELECT full_name FROM members WHERE id = ?");
    mysqli_stmt_bind_param($nameQuery, "i", $member_id);
    mysqli_stmt_execute($nameQuery);
    $result = mysqli_stmt_get_result($nameQuery);
    $memberData = mysqli_fetch_assoc($result);

    if (!$memberData) {
        die("Member not found.");
    }

    $full_name = $memberData['full_name'];
    
    // --- THE FIX IS HERE ---
    // 2. Set default sessions_used to 0
    $sessions_used = 0; 

    // 3. Update Query to include 'sessions_used'
    $sql = "INSERT INTO personal_training
            (member_id, full_name, trainer_id, total_sessions, pt_fees, start_date, end_date, sessions_used)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
    
    $stmt = mysqli_prepare($conn, $sql);
    
    // 4. Update Bind Param (Added 'i' at the end for the integer 0)
    mysqli_stmt_bind_param(
        $stmt,
        "isiidssi", 
        $member_id,
        $full_name,
        $trainer_id,
        $total_sessions,
        $pt_fees,
        $start_date,
        $end_date,
        $sessions_used // Passing 0 here
    );
    // -----------------------

    if(mysqli_stmt_execute($stmt)) {
        header("Location: personal_training.php");
        exit;
    } else {
        die("Failed to save PT config --> <br> " . mysqli_error($conn));
    }
}

// Fetch lists for dropdowns
$members = mysqli_query($conn, "SELECT id, full_name FROM members WHERE personal_training = 1");
$trainers = mysqli_query($conn, "SELECT id, full_name FROM trainers");
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" size="16x16" href="../icons/favicon-dark-logo.png" type="image/png">
    <title>JOF INDIA | PT Configuration</title>
    <link rel="stylesheet" href="../static/root.css">
</head>
<body class="page-pt_form">

    <div class="container">
        
        <div class="card-header">
            <div class="brand-area">
                <img src="../icons/logo-dark(1).png" class="brand-logo" alt="JOF">
                <div class="brand-text">
                    <h2>PT Configuration</h2>
                    <p>Setup training package for member.</p>
                </div>
            </div>
            <a href="personal_training.php" class="close-btn">
                <img src="../icons/xmark-solid-full.svg" alt="close">
            </a>
        </div>

        <div class="card-body">
            <form action="pt_form.php" method="POST">

                <div class="input-group">
                    <label>Select Member</label>
                    <div class="input-wrapper">
                        <select name="member_id" class="form-input" required>
                            <option value="" disabled selected>Choose a member...</option>
                            <?php while($m = mysqli_fetch_assoc($members)) { ?>
                                <option value="<?= $m['id'] ?> ">
                                    <?= htmlspecialchars(($m['full_name'])) ?>
                                </option>
                            <?php } ?>
                        </select>
                        <img src="../icons/users-solid-full.svg" class="input-icon" alt="user">
                    </div>
                </div>

                <div class="input-group">
                    <label>Assign Trainer</label>
                    <div class="input-wrapper">
                        <select name="trainer_id" class="form-input" required>
                            <option value="" disabled selected>Select Trainer</option>
                            <?php while ($t = mysqli_fetch_assoc($trainers)) { ?>
                                <option value="<?= $t['id'] ?>">
                                    <?= htmlspecialchars($t['full_name']) ?>
                                </option>
                            <?php } ?>
                        </select>
                    </div>
                </div>

                <div class="grid-2">
                    <div class="input-group">
                        <label>Total Sessions</label>
                        <div class="input-wrapper">
                            <input type="number" name="total_sessions" class="form-input" placeholder="e.g. 24" required>
                            <img src="../icons/dumbbell-solid-full.svg" class="input-icon" alt="sessions">
                        </div>
                    </div>
                    <div class="input-group">
                        <label>PT Fees (₹)</label>
                        <div class="input-wrapper">
                            <input type="number" name="pt_fees" class="form-input" placeholder="12000" required>
                            <img src="../icons/indian-rupee-sign-solid-full.svg" class="input-icon" alt="fee">
                        </div>
                    </div>
                </div>

                <div class="grid-2">
                    <div class="input-group">
                        <label>Start Date</label>
                        <div class="input-wrapper">
                            <input type="date" name="start_date" class="form-input" required>
                            <img src="../icons/calendar-plus-solid-full.svg" class="input-icon" alt="date">
                        </div>
                    </div>
                    <div class="input-group">
                        <label>End Date</label>
                        <div class="input-wrapper">
                            <input type="date" name="end_date" class="form-input" required>
                            <img src="../icons/calendar-plus-solid-full.svg" class="input-icon" alt="date">
                        </div>
                    </div>
                </div>

                <div class="card-footer">
                    <a href="personal_training.php" class="btn btn-secondary">Cancel</a>
                    <button id="saveConfigBtn" type="submit" class="btn btn-primary">Save Package</button>
                </div>

            </form>
        </div>
    </div>
</body>
</html>