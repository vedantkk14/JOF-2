<?php 
session_start(); 
// Display status messages if they exist in session
$msg = "";
$msg_type = "";
if (isset($_SESSION['status_msg'])) {
    $msg = $_SESSION['status_msg'];
    $msg_type = $_SESSION['status_type']; // 'success' or 'error'
    unset($_SESSION['status_msg']);
    unset($_SESSION['status_type']);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>JOF Fitness | Forgot Password</title>
    <link rel="stylesheet" href="../static/root.css">
    
</head>
<body class="page-login_page">

    <div class="auth-container">
        
        <div class="visual-side">
            <img src="../icons/logo-light(1).png" alt="JOF Logo" class="brand-logo-img">
            <p class="brand-quote">
                Get the best workouts & exercise plans tailored for you at JOF Fitness Center!
            </p>
        </div>

        <div class="form-side">
            
            <div id="forgotForm">
                <div class="form-header">
                    <h2>Forgot Password? 🔒</h2>
                    <p>Enter your email to receive a reset link.</p>
                </div>

                <?php if (!empty($msg)): ?>
                    <div class="alert alert-<?php echo $msg_type; ?>">
                        <?php echo $msg; ?>
                    </div>
                <?php endif; ?>

                <form action="../handlers/send_reset_link.php" method="POST">
                    
                    <div class="input-group">
                        <label for="email">Email Address</label>
                        <div class="input-wrapper">
                            <input type="email" id="email" name="email" class="form-input" placeholder="Enter your registered email" required>
                            <img src="../icons/envelope-solid-full.svg" class="input-icon" alt="email">
                        </div>
                    </div>

                    <button type="submit" class="btn-primary">Send Reset Link</button>
                </form>

                <div class="switch-form">
                    Remember your password? <a href="login_page.html">Back to Login</a>
                </div>
            </div>

        </div>
    </div>

</body>
</html>