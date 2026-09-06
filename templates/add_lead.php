<?php
require_once __DIR__ . '/../auth/auth_check.php';
require_role(['admin', 'trainer']);
require_once __DIR__ . '/../config.php';

$show_success_modal = false;
$error_msg = '';

// Initialize variables for form stickiness
$full_name = '';
$phone_number = '';
$email = '';
$source = 'Walk-in';
$fitness_goal = '';
$lead_status = 'New';
$preferred_contact = 'Phone Call';
$follow_up_date = '';
$remarks = '';

// 2. Handle Form Submission
if ($_SERVER["REQUEST_METHOD"] == "POST") {

    // Retrieve & Sanitize Inputs
    $full_name = trim($_POST['full_name'] ?? '');
    $phone_number = trim($_POST['phone_number'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $source = $_POST['source'] ?? 'Walk-in';
    $fitness_goal = trim($_POST['fitness_goal'] ?? '');
    $lead_status = $_POST['lead_status'] ?? 'New';
    $preferred_contact = $_POST['preferred_contact_method'] ?? 'Phone Call';
    $follow_up_date = !empty($_POST['follow_up_date']) ? $_POST['follow_up_date'] : NULL;
    $remarks = trim($_POST['remarks'] ?? '');

    // Basic Validation
    if (empty($full_name) || empty($phone_number)) {
        $error_msg = "Full Name and Phone Number are required.";
    }
    else {
        // 3. Insert into Database (handled_by removed as requested)
        $sql = "INSERT INTO sales_leads 
                (full_name, phone_number, email, source, fitness_goal, lead_status, preferred_contact_method, follow_up_date, remarks) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";

        if ($stmt = $conn->prepare($sql)) {
            // Bind params: all strings (s)
            $stmt->bind_param("sssssssss",
                $full_name, $phone_number, $email, $source, $fitness_goal,
                $lead_status, $preferred_contact, $follow_up_date, $remarks
            );

            if ($stmt->execute()) {
                // SUCCESS: Show modal and clear form
                $show_success_modal = true;
                $full_name = $phone_number = $email = $fitness_goal = $follow_up_date = $remarks = '';
                $source = 'Walk-in';
                $lead_status = 'New';
                $preferred_contact = 'Phone Call';
            }
            else {
                $error_msg = "Database Error: " . $stmt->error;
            }
            $stmt->close();
        }
        else {
            $error_msg = "Query Preparation Error: " . $conn->error;
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
    <title>JOF India | Add Sales Lead</title>
    
    <link rel="stylesheet" href="../static/root.css">    
</head>
<body class="page-metrics">

    <?php if ($show_success_modal): ?>
    <div class="modal-overlay active" id="successModal">
        <div class="modal-card">
            <div class="success-icon-container">
                <img src="../icons/check-solid-full.svg" alt="check" width="24" class="icon-white">
            </div>
            <h2>Lead Captured!</h2>
            <p>The new sales lead has been successfully added to the system.</p>
            <div class="modal-actions">
                <button onclick="document.getElementById('successModal').style.display='none'" class="btn-success">
                    <img src="../icons/plus-solid-full.svg" alt="plus" width="16" class="icon-white" style="margin-right: 8px;"> Add Another Lead
                </button>
                <button onclick="window.location.href='sales_leads.php'" class="btn-outline">
                    <img src="../icons/list-solid-full.svg" alt="list" width="16" class="icon-muted" style="margin-right: 8px;"> View All Leads
                </button>
            </div>
        </div>
    </div>
    <?php
endif; ?>

    <div class="container" style="max-width: 800px; margin: 40px auto;">

        <div class="card-header">
            <div class="brand-area">
                <img src="../icons/logo-dark(1).png" class="brand-logo" alt="JOF">
                <div class="brand-text">
                    <h2>Add New Lead</h2>
                    <p>Capture contact details and fitness goals.</p>
                </div>
            </div>
            <a href="sales_leads.php" class="close-btn">
                <img src="../icons/xmark-solid-full.svg" alt="close">
            </a>
        </div>

        <form action="" method="POST" id="leadForm">

            <div class="card-body">
                <?php if (!empty($error_msg)): ?>
                    <div class="alert-error"><?php echo $error_msg; ?></div>
                <?php
endif; ?>

                <div class="form-grid">

                    <div class="input-group full-width">
                        <h3 class="section-title" style="margin-bottom: 10px; border-bottom: 1px solid #E5E7EB; padding-bottom: 10px;">
                            <img src="../icons/address-card-solid-full.svg" alt="address-card" width="18" class="icon-primary"> Contact Information
                        </h3>
                    </div>

                    <div class="input-group">
                        <label>Full Name *</label>
                        <div class="input-wrapper">
                            <input type="text" name="full_name" class="form-input" placeholder="e.g. Rahul Sharma" value="<?php echo htmlspecialchars($full_name); ?>" required>
                            <img src="../icons/user-solid-full.svg" alt="user" class="input-icon icon-muted">
                        </div>
                    </div>

                    <div class="input-group">
                        <label>Phone Number *</label>
                        <div class="input-wrapper">
                            <input type="tel" name="phone_number" class="form-input" placeholder="10-digit number" value="<?php echo htmlspecialchars($phone_number); ?>" required>
                            <img src="../icons/phone-solid-full.svg" alt="phone" class="input-icon icon-success">
                        </div>
                    </div>

                    <div class="input-group">
                        <label>Email Address</label>
                        <div class="input-wrapper">
                            <input type="email" name="email" class="form-input" placeholder="optional@email.com" value="<?php echo htmlspecialchars($email); ?>">
                            <img src="../icons/envelope-solid-full.svg" alt="envelope" class="input-icon icon-blue">
                        </div>
                    </div>

                    <div class="input-group">
                        <label>Preferred Contact</label>
                        <div class="input-wrapper">
                            <select name="preferred_contact_method" class="form-input">
                                <option value="Phone Call" <?php if ($preferred_contact == 'Phone Call')
    echo 'selected'; ?>>Phone Call</option>
                                <option value="WhatsApp" <?php if ($preferred_contact == 'WhatsApp')
    echo 'selected'; ?>>WhatsApp</option>
                                <option value="Email" <?php if ($preferred_contact == 'Email')
    echo 'selected'; ?>>Email</option>
                            </select>
                            <img src="../icons/comments-solid-full.svg" alt="comments" class="input-icon icon-purple">
                        </div>
                    </div>

                    <div class="input-group full-width" style="margin-top: 15px;">
                        <h3 class="section-title" style="margin-bottom: 10px; border-bottom: 1px solid #E5E7EB; padding-bottom: 10px;">
                            <img src="../icons/bullseye-solid-full.svg" alt="bullseye" width="18" class="icon-primary"> Lead Details
                        </h3>
                    </div>

                    <div class="input-group">
                        <label>Source (How did they hear about us?)</label>
                        <div class="input-wrapper">
                            <select name="source" class="form-input" required>
                                <option value="Walk-in" <?php if ($source == 'Walk-in')
    echo 'selected'; ?>>Walk-in</option>
                                <option value="Word of Mouth" <?php if ($source == 'Word of Mouth')
    echo 'selected'; ?>>Word of Mouth / Referral</option>
                                <option value="Instagram" <?php if ($source == 'Instagram')
    echo 'selected'; ?>>Instagram</option>
                                <option value="Facebook" <?php if ($source == 'Facebook')
    echo 'selected'; ?>>Facebook</option>
                                <option value="Google Ads" <?php if ($source == 'Google Ads')
    echo 'selected'; ?>>Google Search / Ads</option>
                                <option value="Flyer/Poster" <?php if ($source == 'Flyer/Poster')
    echo 'selected'; ?>>Flyer / Poster</option>
                                <option value="Other" <?php if ($source == 'Other')
    echo 'selected'; ?>>Other</option>
                            </select>
                            <img src="../icons/bullhorn-solid-full.svg" alt="bullhorn" class="input-icon icon-orange">
                        </div>
                    </div>

                    <div class="input-group">
                        <label>Primary Fitness Goal</label>
                        <div class="input-wrapper">
                            <input type="text" name="fitness_goal" class="form-input" list="goals" placeholder="e.g. Weight Loss, Muscle Gain" value="<?php echo htmlspecialchars($fitness_goal); ?>">
                            <datalist id="goals">
                                <option value="Weight Loss">
                                <option value="Muscle Gain / Bodybuilding">
                                <option value="General Fitness">
                                <option value="Rehab / Injury Recovery">
                            </datalist>
                            <img src="../icons/dumbbell-solid-full.svg" alt="dumbbell" class="input-icon icon-muted">
                        </div>
                    </div>

                    <div class="input-group full-width" style="margin-top: 15px;">
                        <h3 class="section-title" style="margin-bottom: 10px; border-bottom: 1px solid #E5E7EB; padding-bottom: 10px;">
                            <img src="../icons/calendar-check-solid-full.svg" alt="calendar-check" width="18" class="icon-primary"> Action Plan
                        </h3>
                    </div>

                    <div class="input-group">
                        <label>Current Lead Status</label>
                        <div class="input-wrapper">
                            <select name="lead_status" class="form-input" required>
                                <option value="New" <?php if ($lead_status == 'New')
    echo 'selected'; ?>>New Inquiry</option>
                                <option value="Contacted" <?php if ($lead_status == 'Contacted')
    echo 'selected'; ?>>Contacted (Follow-up needed)</option>
                                <option value="Trial Booked" <?php if ($lead_status == 'Trial Booked')
    echo 'selected'; ?>>Trial Booked</option>
                                <option value="Converted" <?php if ($lead_status == 'Converted')
    echo 'selected'; ?>>Converted (Joined)</option>
                                <option value="Lost" <?php if ($lead_status == 'Lost')
    echo 'selected'; ?>>Lost (Not interested)</option>
                            </select>
                            <img src="../icons/bars-progress-solid-full.svg" alt="bars-progress" class="input-icon icon-blue">
                        </div>
                    </div>

                    <div class="input-group">
                        <label>Next Follow-Up Date</label>
                        <div class="input-wrapper">
                            <input type="date" name="follow_up_date" class="form-input" value="<?php echo htmlspecialchars($follow_up_date); ?>">
                        </div>
                    </div>

                    <div class="input-group full-width">
                        <label>Remarks / Notes</label>
                        <div class="input-wrapper">
                            <textarea name="remarks" class="form-input" rows="3" placeholder="e.g. Looking for a couple's discount, works night shifts..." style="padding-left: 16px; height: auto;"><?php echo htmlspecialchars($remarks); ?></textarea>
                        </div>
                    </div>

                </div>
            </div>

            <div class="card-footer">
                <a href="sales_leads.php" class="btn btn-secondary">
                    Cancel
                </a>
                
                <button type="submit" class="btn btn-primary" style="border:none;">
                    <img src="../icons/save-solid-full.svg" alt="save" width="18" class="icon-white" style="margin-right: 8px;"> Save Lead
                </button>
            </div>

        </form>
    </div>

</body>
</html>