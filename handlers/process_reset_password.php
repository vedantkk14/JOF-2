<?php
session_start();
require '../config.php';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    
    // Get the POST data
    $email = $_POST['email'] ?? '';
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    // Verify session
    if (!isset($_SESSION['otp_verified_for']) || $_SESSION['otp_verified_for'] !== $email) {
        $_SESSION['status_msg'] = "Unauthorized access or session expired.";
        $_SESSION['status_type'] = "error";
        header("Location: ../templates/forgot_password.php");
        exit;
    }

    if (strlen($password) < 6) {
         $_SESSION['status_msg'] = "Password must be at least 6 characters.";
         $_SESSION['status_type'] = "error";
         header("Location: ../templates/reset_password.php");
         exit;
    }

    if ($password !== $confirm_password) {
        $_SESSION['status_msg'] = "Passwords do not match.";
        $_SESSION['status_type'] = "error";
        header("Location: ../templates/reset_password.php");
        exit;
    }

    // Hash the NEW password
    $new_password_hash = password_hash($password, PASSWORD_DEFAULT);

    // UPDATED TABLE NAME: user_data
    // Note: We don't check reset_token_hash here anymore because verify_otp_handler.php already did.
    // We just clear it out.
    $sql = "UPDATE user_data SET password = ?, reset_token_hash = NULL, reset_token_expires_at = NULL WHERE email = ?";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ss", $new_password_hash, $email);
    if ($stmt->execute() && $stmt->errno === 0) {
        // Clear the verification session now that password is changed
        unset($_SESSION['otp_verified_for']);
        unset($_SESSION['otp_verified_time']);
        
        // Output JavaScript to show a SweetAlert popup and then redirect
        echo "<!DOCTYPE html><html><head>";
        echo "<script src='https://cdn.jsdelivr.net/npm/sweetalert2@11'></script>";
        echo "<style>body { font-family: sans-serif; }</style>";
        echo "</head><body>";
        echo "<script>
            Swal.fire({
                icon: 'success',
                title: 'Password Updated!',
                text: 'Your password has been changed successfully.',
                confirmButtonColor: '#F25C2A',
                confirmButtonText: 'Go to Login',
                allowOutsideClick: false
            }).then((result) => {
                if (result.isConfirmed) {
                    window.location.href = '../templates/login_page.html';
                }
            });
        </script>";
        echo "</body></html>";
        exit;
    } else {
        $_SESSION['status_msg'] = "Could not update password. Please try again.";
        $_SESSION['status_type'] = "error";
        header("Location: ../templates/forgot_password.php");
        exit;
    }

} else {
    header("Location: ../templates/login_page.html");
    exit;
}
?>