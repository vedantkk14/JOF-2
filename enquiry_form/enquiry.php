<?php
session_start();
require '../config.php';
require '../auth/send_enquiry_mail.php';

$show_success = false;
$error_msg = '';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $full_name = trim($_POST['full_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone_number = trim($_POST['phone_number'] ?? '');

    if (empty($full_name) || empty($phone_number)) {
        $error_msg = "Please fill in all required fields.";
    }
    else {
        // Validation: Check if an enquiry has already been submitted with this email
        $check_sql = "SELECT id FROM member_enquiries WHERE email = ?";
        $check_stmt = $conn->prepare($check_sql);
        $check_stmt->bind_param("s", $email);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();

        if ($check_result->num_rows > 0) {
            $error_msg = "An enquiry has already been submitted with this email address.";
            $check_stmt->close();
        }
        else {
            $check_stmt->close();
            $sql = "INSERT INTO member_enquiries (full_name, email, phone_number) VALUES (?, ?, ?)";
            if ($stmt = $conn->prepare($sql)) {
                $stmt->bind_param("sss", $full_name, $email, $phone_number);
                if ($stmt->execute()) {
                    $show_success = true;

                    // Send "Thank You" Email to the User
                    sendEnquiryEmail($full_name, $email);

                    // Clear the form fields after successful submission
                    $_POST = array();
                }
                else {
                    $error_msg = "Something went wrong. Please try again later.";
                }
                $stmt->close();
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
    <title>Enquiry Form | JOF INDIA</title>
    <link rel="stylesheet" href="../static/root.css">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #F25C2A;
            --primary-hover: #e04b19;
            --primary-gradient: linear-gradient(135deg, #F25C2A 0%, #ff7a4a 100%);
            --text-main: #111827;
            --text-muted: #6B7280;
            --bg-input: #F9FAFB;
            --border-input: #E5E7EB;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: 'Plus Jakarta Sans', sans-serif;
            background: linear-gradient(rgba(0, 0, 0, 0.7), rgba(0, 0, 0, 0.8)), url('../icons/images/young-man.jpeg');
            background-size: cover;
            background-position: center;
            background-attachment: fixed;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .enquiry-card {
            background: rgba(255, 255, 255, 0.98);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            width: 100%;
            max-width: 440px;
            border-radius: 28px;
            padding: 48px 40px;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5), 0 0 0 1px rgba(255,255,255,0.1);
            animation: slideUp 0.6s cubic-bezier(0.16, 1, 0.3, 1) forwards;
            opacity: 0;
            transform: translateY(30px);
        }

        @keyframes slideUp {
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .logo-area {
            text-align: center;
            margin-bottom: 32px;
        }

        .logo-area img {
            height: 48px;
            object-fit: contain;
        }

        .header-text {
            text-align: center;
            margin-bottom: 40px;
        }

        .header-text h1 {
            font-size: 28px;
            font-weight: 800;
            color: var(--text-main);
            margin-bottom: 10px;
            letter-spacing: -0.5px;
        }

        .header-text p {
            font-size: 15px;
            color: var(--text-muted);
            line-height: 1.5;
        }

        /* Small Success Toast Popup */
        .toast-popup {
            position: fixed;
            top: 32px; /* Dropped it slightly lower from the top edge */
            left: 50%;
            transform: translateX(-50%) translateY(-120px);
            background: #ffffff;
            border-left: 6px solid #10B981; /* Made the green accent line slightly thicker */
            padding: 20px 32px; /* Increased padding */
            border-radius: 14px; /* Slightly rounder edges */
            box-shadow: 0 15px 30px -5px rgba(0, 0, 0, 0.25);
            display: flex;
            align-items: center;
            gap: 16px; /* Increased space between icon and text */
            min-width: 360px; /* Forces the box to be wider */
            z-index: 9999;
            animation: slideDownToast 0.5s cubic-bezier(0.16, 1, 0.3, 1) forwards, fadeOutToast 0.5s ease 4s forwards;
        }

        .toast-popup svg {
            color: #10B981;
            width: 28px; /* Increased icon size from 24px */
            height: 28px; 
        }

        .toast-popup p {
            color: var(--text-main);
            font-weight: 700; 
            font-size: 17px; 
            margin: 0;
        }

        @keyframes slideDownToast {
            to { transform: translateX(-50%) translateY(0); }
        }

        @keyframes fadeOutToast {
            to { opacity: 0; visibility: hidden; }
        }

        .alert-error {
            background-color: #FEF2F2;
            border-left: 4px solid #EF4444;
            color: #991B1B;
            padding: 12px 16px;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 500;
            margin-bottom: 24px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .form-group {
            margin-bottom: 24px;
            position: relative;
        }

        .input-wrapper {
            position: relative;
            display: flex;
            align-items: center;
        }

        .input-wrapper svg {
            position: absolute;
            left: 16px;
            width: 20px;
            height: 20px;
            color: #9CA3AF;
            transition: color 0.3s ease;
        }

        .input-wrapper input {
            width: 100%;
            padding: 16px 16px 16px 48px;
            background: var(--bg-input);
            border: 2px solid var(--border-input);
            border-radius: 16px;
            font-size: 15px;
            font-weight: 600;
            color: var(--text-main);
            transition: all 0.3s ease;
            outline: none;
            font-family: inherit;
        }

        .input-wrapper input::placeholder {
            color: transparent;
        }

        .form-group label {
            position: absolute;
            left: 48px;
            top: 50%;
            transform: translateY(-50%);
            font-size: 15px;
            font-weight: 500;
            color: #9CA3AF;
            transition: all 0.3s ease;
            pointer-events: none;
            z-index: 10;
        }

        .input-wrapper input:focus + label,
        .input-wrapper input:not(:placeholder-shown) + label {
            top: 0;
            left: 16px;
            transform: translateY(-50%) scale(0.85);
            background: #ffffff;
            padding: 0 8px;
            color: var(--primary);
            font-weight: 700;
            border-radius: 4px;
        }

        .input-wrapper input:focus {
            border-color: var(--primary);
            background: #fff;
            box-shadow: 0 0 0 4px rgba(242, 92, 42, 0.1);
        }

        .input-wrapper input:focus ~ svg {
            color: var(--primary);
        }

        .btn-submit {
            width: 100%;
            padding: 16px;
            background: var(--primary-gradient);
            color: #fff;
            border: none;
            border-radius: 16px;
            font-size: 16px;
            font-weight: 700;
            letter-spacing: 0.5px;
            cursor: pointer;
            transition: all 0.3s ease;
            margin-top: 8px;
            box-shadow: 0 8px 20px rgba(242, 92, 42, 0.25);
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 10px;
        }

        .btn-submit:hover {
            transform: translateY(-2px);
            box-shadow: 0 12px 24px rgba(242, 92, 42, 0.35);
        }

        .btn-submit:active {
            transform: translateY(0);
            box-shadow: 0 4px 12px rgba(242, 92, 42, 0.2);
        }

        .footer-note {
            text-align: center;
            margin-top: 32px;
            font-size: 13px;
            color: #9CA3AF;
            line-height: 1.6;
            font-weight: 500;
        }
    </style>
</head>

<body>

    <?php if ($show_success): ?>
        <div class="toast-popup">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7"></path></svg>
            <p>Thank you for submitting your enquiry.</p>
        </div>
    <?php
endif; ?>

    <div class="enquiry-card">
        <div class="logo-area">
            <img src="https://www.jofindia.com/assets/img/f-logo.png" alt="JOF INDIA">
        </div>

        <div class="header-text">
            <h1>Start Your Journey</h1>
            <p>Tell us a bit about yourself and we'll handle the rest.</p>
        </div>

        <?php if (!empty($error_msg)): ?>
            <div class="alert-error">
                <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path></svg>
                <?php echo htmlspecialchars($error_msg); ?>
            </div>
        <?php
endif; ?>

        <form method="POST">
            <div class="form-group">
                <div class="input-wrapper">
                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path></svg>
                    <input type="text" name="full_name" id="full_name" placeholder=" " value="<?php echo htmlspecialchars($_POST['full_name'] ?? ''); ?>" required>
                    <label for="full_name">Full Name</label>
                </div>
            </div>

            <div class="form-group">
                <div class="input-wrapper">
                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"></path></svg>
                    <input type="email" name="email" id="email" placeholder=" " value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>" required>
                    <label for="email">Email Address</label>
                </div>
            </div>

            <div class="form-group">
                <div class="input-wrapper">
                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"></path></svg>
                    <input type="tel" name="phone_number" id="phone_number" placeholder=" " value="<?php echo htmlspecialchars($_POST['phone_number'] ?? ''); ?>" required pattern="[0-9]{10}">
                    <label for="phone_number">Phone Number (10 digits)</label>
                </div>
            </div>

            <button type="submit" class="btn-submit">
                SEND ENQUIRY
                <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3"></path></svg>
            </button>
        </form>

        <div class="footer-note">
            By continuing, you allow us to contact and assist in your fitness journey.
        </div>
    </div>

</body>
</html>