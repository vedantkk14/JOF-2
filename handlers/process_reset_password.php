<?php
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params(['lifetime'=>0,'path'=>'/','secure'=>isset($_SERVER['HTTPS'])&&$_SERVER['HTTPS']==='on','httponly'=>true,'samesite'=>'Strict']);
    session_start();
}
require '../config.php';

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

    // Get the POST data
    $email            = trim($_POST['email'] ?? '');
    $password         = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    // Verify session
    if (!isset($_SESSION['otp_verified_for']) || $_SESSION['otp_verified_for'] !== $email) {
        $_SESSION['status_msg'] = "Unauthorized access or session expired.";
        $_SESSION['status_type'] = "error";
        header("Location: ../templates/forgot_password.php");
        exit;
    }

    if (strlen($password) < 8) {
         $_SESSION['status_msg']  = 'Password must be at least 8 characters.';
         $_SESSION['status_type'] = 'error';
         header('Location: ../templates/reset_password.php');
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
                    window.location.href = '../index.php';
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
    header("Location: ../index.php");
    exit;
}
?>