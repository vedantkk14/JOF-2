<?php
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params(['lifetime'=>0,'path'=>'/','secure'=>isset($_SERVER['HTTPS'])&&$_SERVER['HTTPS']==='on','httponly'=>true,'samesite'=>'Strict']);
    session_start();
}
require_once "../config.php";
require_once __DIR__ . '/../auth/rate_limiter.php';

if ($_SERVER["REQUEST_METHOD"] == "POST") {

    // ── CSRF Validation ──────────────────────────────────────────
    $submitted_csrf = $_POST['_csrf_token'] ?? '';
    $stored_csrf    = $_SESSION['_csrf_token'] ?? '';
    if (!$stored_csrf || !hash_equals($stored_csrf, $submitted_csrf)) {
        $_SESSION['status_msg']  = 'Invalid security token. Please try again.';
        $_SESSION['status_type'] = 'error';
        header('Location: ../templates/forgot_password.php');
        exit;
    }
    unset($_SESSION['_csrf_token']);

    $email = trim(filter_var($_POST['email'] ?? '', FILTER_SANITIZE_EMAIL));
    $submitted_otp = trim($_POST['otp'] ?? '');
    
    // Server-side validation
    if (empty($email) || empty($submitted_otp) || strlen($submitted_otp) !== 6) {
        $_SESSION['status_msg'] = "Please enter a valid 6-digit OTP.";
        $_SESSION['status_type'] = "error";
        header("Location: ../templates/verify_otp.php");
        exit;
    }

    // ── Rate limiting: a 6-digit code must not be guessable by brute force ──
    $retry = otp_verify_retry_after($conn, $email);
    if ($retry > 0) {
        $_SESSION['status_msg']  = 'Too many incorrect codes. Please try again in ' . rl_wait_text($retry) . ', or request a new OTP.';
        $_SESSION['status_type'] = 'error';
        header("Location: ../templates/verify_otp.php");
        exit;
    }

    $token_hash = hash('sha256', $submitted_otp);

    // Verify OTP and check expiration in one query
    $sql = "SELECT id FROM user_data WHERE email = ? AND reset_token_hash = ? AND reset_token_expires_at > NOW()";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ss", $email, $token_hash);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 1) {
        // OTP is valid — consume it so the same code can never be used twice
        $consume = $conn->prepare("UPDATE user_data SET reset_token_hash = NULL, reset_token_expires_at = NULL WHERE email = ?");
        $consume->bind_param("s", $email);
        $consume->execute();
        otp_verify_record_success($conn, $email);

        // Set a secure session flag indicating this user is permitted to reset their password
        // This prevents direct URL access to the reset_password.php page
        $_SESSION['otp_verified_for'] = $email;
        $_SESSION['otp_verified_time'] = time();

        // Remove the email from the reset intent session so they can't go back
        unset($_SESSION['reset_email']);

        header("Location: ../templates/reset_password.php");
        exit;
    } else {
        otp_verify_record_failure($conn, $email);

        // Out of guesses for this code: cancel it outright, so brute force can't continue
        if (otp_verify_retry_after($conn, $email) > 0) {
            $cancel = $conn->prepare("UPDATE user_data SET reset_token_hash = NULL, reset_token_expires_at = NULL WHERE email = ?");
            $cancel->bind_param("s", $email);
            $cancel->execute();
            $_SESSION['status_msg']  = 'Too many incorrect codes. For your security this OTP has been cancelled — please request a new one.';
            $_SESSION['status_type'] = 'error';
            header("Location: ../templates/forgot_password.php");
            exit;
        }

        // Validation Failed (Either wrong code, or it expired)
        // Check if it's just expired to give a better error message (optional, but good UX)
        $expire_check = $conn->prepare("SELECT reset_token_expires_at FROM user_data WHERE email = ? AND reset_token_hash = ?");
        $expire_check->bind_param("ss", $email, $token_hash);
        $expire_check->execute();
        $exp_result = $expire_check->get_result();
        
        if ($exp_result->num_rows === 1) {
             $_SESSION['status_msg'] = "This OTP has expired. Please request a new one.";
        } else {
             $_SESSION['status_msg'] = "Invalid verification code. Please try again.";
        }
        
        $_SESSION['status_type'] = "error";
        header("Location: ../templates/verify_otp.php");
        exit;
    }
} else {
    // If not a POST request, send back to appropriate place
    header("Location: ../templates/forgot_password.php");
    exit;
}
?>
