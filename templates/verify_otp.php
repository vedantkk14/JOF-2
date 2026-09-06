<?php
session_start();

if (!isset($_SESSION['reset_email'])) {
    header("Location: ../templates/forgot_password.php");
    exit;
}

$email = $_SESSION['reset_email'];

// Display status messages if they exist in session
$msg = "";
$msg_type = "";
if (isset($_SESSION['status_msg'])) {
    $msg = $_SESSION['status_msg'];
    $msg_type = $_SESSION['status_type'];
    unset($_SESSION['status_msg']);
    unset($_SESSION['status_type']);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" size="16x16" href="../icons/favicon-dark-logo.png" type="image/png">
    <title>JOF Fitness | Verify OTP</title>
    <link rel="stylesheet" href="../static/root.css">
    <style>
        /* Style for the single OTP input box */
        .otp-input-single {
            width: 100%;
            padding: 12px;
            font-size: 24px;
            text-align: center;
            letter-spacing: 12px; /* Adds space between digits */
            border: 2px solid #e2e8f0;
            border-radius: 10px;
            outline: none;
            transition: all 0.3s;
            font-weight: 600;
            margin-bottom: 20px;
        }

        .otp-input-single:focus {
            border-color: #F25C2A;
            box-shadow: 0 0 0 4px rgba(242, 92, 42, 0.1);
        }

        /* Chrome, Safari, Edge, Opera - Remove arrows */
        .otp-input-single::-webkit-outer-spin-button,
        .otp-input-single::-webkit-inner-spin-button {
            -webkit-appearance: none;
            margin: 0;
        }
    </style>
</head>
<body class="page-login_page">

    <div class="auth-container">
        <div class="visual-side">
            <img src="../icons/logo-light(1).png" alt="JOF Logo" class="brand-logo-img">
            <p class="brand-quote">
                Security first. Secure your account to get the best out of JOF Fitness Center.
            </p>
        </div>

        <div class="form-side">
            <div id="verifyForm">
                <div class="form-header">
                    <h2>Email Verification ✉️</h2>
                    <p>We've sent a 6-digit code to <br><span class="email-display"><?php echo htmlspecialchars($email); ?></span></p>
                </div>

                <?php if (!empty($msg)): ?>
                    <div class="alert alert-<?php echo $msg_type; ?>">
                        <?php echo $msg; ?>
                    </div>
                <?php endif; ?>

                <form action="../handlers/verify_otp_handler.php" method="POST" id="otpForm">
                    <input type="hidden" name="email" value="<?php echo htmlspecialchars($email); ?>">
                    
                    <div class="input-group">
                        <label>Enter 6-Digit OTP</label>
                        <input type="number" 
                               name="otp" 
                               id="otpInput" 
                               class="otp-input-single" 
                               placeholder="000000" 
                               oninput="javascript: if (this.value.length > 6) this.value = this.value.slice(0, 6);" 
                               required 
                               autofocus>
                    </div>

                    <button type="submit" class="btn-primary" id="verifyBtn">Verify & Proceed</button>
                </form>

                <div class="switch-form" style="margin-top: 25px;">
                    Didn't receive the code? 
                    <form action="../handlers/send_reset_link.php" method="POST" style="display:inline;">
                        <input type="hidden" name="email" value="<?php echo htmlspecialchars($email); ?>">
                        <button type="submit" style="background:none; border:none; color:#F25C2A; font-weight:600; cursor:pointer; padding:0; font-size:14px; text-decoration:underline;">Resend</button>
                    </form>
                </div>
                
                <div class="switch-form" style="margin-top: 10px;">
                    <a href="login_page.html">Back to Login</a>
                </div>
            </div>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const otpInput = document.getElementById('otpInput');
            const verifyBtn = document.getElementById('verifyBtn');
            const form = document.getElementById('otpForm');

            otpInput.addEventListener('input', function() {
                // Ensure only 6 digits
                if (this.value.length === 6) {
                    verifyBtn.style.opacity = '1';
                } else {
                    verifyBtn.style.opacity = '0.7';
                }
            });

            form.addEventListener('submit', function(e) {
                if (otpInput.value.length !== 6) {
                    e.preventDefault();
                    alert('Please enter a valid 6-digit OTP.');
                } else {
                    verifyBtn.textContent = 'Verifying...';
                    verifyBtn.style.pointerEvents = 'none';
                }
            });
        });
    </script>
</body>
</html>