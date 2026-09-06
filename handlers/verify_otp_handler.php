<?php
session_start();
require_once "../config.php";

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    
    $email = filter_var($_POST['email'], FILTER_SANITIZE_EMAIL);
    $submitted_otp = $_POST['otp'];
    
    // Server-side validation
    if (empty($email) || empty($submitted_otp) || strlen($submitted_otp) !== 6) {
        $_SESSION['status_msg'] = "Please enter a valid 6-digit OTP.";
        $_SESSION['status_type'] = "error";
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
        // OTP is valid!
        // Set a secure session flag indicating this user is permitted to reset their password
        // This prevents direct URL access to the reset_password.php page
        $_SESSION['otp_verified_for'] = $email;
        $_SESSION['otp_verified_time'] = time();
        
        // Remove the email from the reset intent session so they can't go back
        unset($_SESSION['reset_email']);

        header("Location: ../templates/reset_password.php");
        exit;
    } else {
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
