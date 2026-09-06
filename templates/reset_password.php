<?php
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params(['lifetime'=>0,'path'=>'/','secure'=>isset($_SERVER['HTTPS'])&&$_SERVER['HTTPS']==='on','httponly'=>true,'samesite'=>'Strict']);
    session_start();
}

// 1. Check if user successfully verified OTP
if (!isset($_SESSION['otp_verified_for']) || !isset($_SESSION['otp_verified_time'])) {
    // Basic security: if they haven't verified an OTP, send them away.
    header("Location: ../templates/forgot_password.php");
    exit;
}

// 2. Add an extra layer: The verification session should expire quickly (e.g. 15 mins)
$verification_age = time() - $_SESSION['otp_verified_time'];
if ($verification_age > 900) { // 900 seconds = 15 minutes
    unset($_SESSION['otp_verified_for']);
    unset($_SESSION['otp_verified_time']);
    $_SESSION['status_msg'] = "Your session expired. Please verify OTP again.";
    $_SESSION['status_type'] = "error";
    header("Location: ../templates/forgot_password.php");
    exit;
}

$email = $_SESSION['otp_verified_for'];

// CSRF token for reset form
if (empty($_SESSION['_csrf_token'])) {
    $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['_csrf_token'];

$token_valid = true;

$msg = ""; $msg_type = "";
if (isset($_SESSION['status_msg'])) {
    $msg = $_SESSION['status_msg']; $msg_type = $_SESSION['status_type'];
    unset($_SESSION['status_msg']); unset($_SESSION['status_type']);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <link rel="icon" size="16x16" href="../icons/favicon-dark-logo.png" type="image/png">
    <title>JOF Fitness | Reset Password</title>
    <link rel="stylesheet" href="../static/root.css">
    
</head>
<body class="page-login_page">

    <div class="auth-container">
        <div class="visual-side">
            <div>
                <img src="../icons/logo-light(1).png" alt="JOF Logo" style="width: 150px; margin-bottom: 20px;">
                <p style="font-size: 16px; opacity: 0.9;">Reset your password to regain access.</p>
            </div>
        </div>

        <div class="form-side">
            <div id="loginForm">
                <div class="form-header">
                    <h2 style="margin-bottom: 10px; color: #2D3748;">Reset Password</h2>
                    <p style="color: #A0AEC0; font-size: 14px; margin-bottom: 30px;">Enter your new password below.</p>
                </div>

                <?php if (!empty($msg)): ?>
                    <div class="alert alert-<?php echo $msg_type; ?>"><?php echo $msg; ?></div>
                <?php endif; ?>

                <?php if ($token_valid): ?>
                    <form action="../handlers/process_reset_password.php" method="POST">
                        <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" name="email" value="<?php echo htmlspecialchars($email); ?>">

                        <div class="input-group">
                            <label style="display:block; margin-bottom:8px; font-weight:600; font-size:12px;">New Password</label>
                            <input type="password" name="password" class="form-input" placeholder="New Password" required minlength="8">
                        </div>

                        <div class="input-group">
                            <label style="display:block; margin-bottom:8px; font-weight:600; font-size:12px;">Confirm Password</label>
                            <input type="password" name="confirm_password" class="form-input" placeholder="Confirm Password" required minlength="8">
                        </div>

                        <button type="submit" class="btn-primary">Update Password</button>
                    </form>
                <?php else: ?>
                    <div class="alert alert-error">
                        This link is invalid or has expired.
                    </div>
                    <a href="forgot_password.php" class="btn-primary" style="text-align:center; text-decoration:none; display:block;">Try Again</a>
                <?php endif; ?>
            </div>
        </div>
    </div>

</body>
</html>