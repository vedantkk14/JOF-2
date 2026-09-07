<?php
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require '../config.php';

    header('Content-Type: application/json');

    $full_name = $_POST['full_name'] ?? '';
    $email = $_POST['email'] ?? '';
    $password = $_POST['password'] ?? '';

    if ($full_name === '' || $email === '' || $password === '') {
        echo json_encode([
            "success" => false,
            "message" => "All fields are required"
        ]);
        exit;
    }

    // Check if email exists
    $check_sql = "SELECT id FROM user_data WHERE email = ?";
    $check_stmt = mysqli_prepare($conn, $check_sql);
    mysqli_stmt_bind_param($check_stmt, "s", $email);
    mysqli_stmt_execute($check_stmt);
    $check_result = mysqli_stmt_get_result($check_stmt);

    if (mysqli_fetch_assoc($check_result)) {
        echo json_encode([
            "success" => false,
            "message" => "Email already registered. Try LogIn Instead."
        ]);
        exit;
    }

    // Hash password
    $hashed_password = password_hash($password, PASSWORD_DEFAULT);

    // Insert user
    $insert_sql = "INSERT INTO user_data (full_name, email, password, role)
                    VALUES (?, ?, ?, 'user')";

    $insert_stmt = mysqli_prepare($conn, $insert_sql);
    mysqli_stmt_bind_param($insert_stmt, "sss", $full_name, $email, $hashed_password);

    if (mysqli_stmt_execute($insert_stmt)) {
        // Send registration confirmation email
        require_once __DIR__ . '/../auth/send_registration_email.php';
        $email_result = sendRegistrationEmail($full_name, $email);
        if (!$email_result['success']) {
            error_log('[REGISTER] Welcome email failed for ' . $email . ': ' . ($email_result['error'] ?? 'unknown'));
        }

        echo json_encode([
            "success" => true,
            "message" => "Registration successful! Check your email for confirmation."
        ]);
        exit;
    }

    echo json_encode([
        "success" => false,
        "message" => "Registration failed"
    ]);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" size="16x16" href="../icons/favicon-dark-logo.png" type="image/png">
    <title>JOF INDIA | Register</title>
    <link rel="stylesheet" href="../static/root.css">
</head>

<body class="page-login_page">
    <div class="auth-container">

        <div class="visual-side">
            <img src="../icons/logo-light(1).png" alt="JOF Logo" class="brand-logo-img">
            <p class="brand-quote">
                Join the team! Create an admin account to manage the JOF Fitness Center.
            </p>
        </div>

        <div class="form-side">

            <div id="registerForm">
                <div class="form-header">
                    <h2>Create Account 🚀</h2>
                    <p>Register a new admin for your fitness center.</p>
                </div>

                <div id="formMessage" class="form-message" style="display:none;"></div>

                <form id="formRegister" action="" method="POST">
                    <div class="input-group">
                        <label>Full Name</label>
                        <div class="input-wrapper">
                            <input type="text" name="full_name" class="form-input" placeholder="Full Name" required>
                            <img src="../icons/user-solid-full.svg" class="input-icon" alt="user">
                        </div>
                    </div>

                    <div class="input-group">
                        <label>Email Address</label>
                        <div class="input-wrapper">
                            <input type="email" name="email" class="form-input" placeholder="Email Address" required>
                            <img src="../icons/envelope-solid-full.svg" class="input-icon" alt="email">
                        </div>
                    </div>

                    <div class="input-group">
                        <label>Password</label>
                        <div class="input-wrapper">
                            <input type="password" name="password" class="form-input" placeholder="Create a password"
                                required>
                            <img src="../icons/lock-solid-full.svg" class="input-icon" alt="lock">
                        </div>
                    </div>
                    <button type="submit" class="btn-primary" id="registerBtn">Register</button>
                </form>

                <div class="switch-form">
                    Already have an account? <a href="../index.php">Sign In</a>
                </div>
            </div>

        </div>
    </div>
    <script>
        const form = document.getElementById('formRegister');
        const msgBox = document.getElementById('formMessage');
        const registerBtn = document.getElementById('registerBtn');

        function showMessage(text, type) {
            msgBox.textContent = text;
            msgBox.className = 'form-message ' + type;
            msgBox.style.display = 'block';
        }

        function resetButton() {
            registerBtn.disabled = false;
            registerBtn.textContent = 'Register';
        }

        form.addEventListener('submit', function (e) {
            e.preventDefault();

            // Prevent double-submit
            registerBtn.disabled = true;
            registerBtn.textContent = 'Registering…';
            msgBox.style.display = 'none';

            const formData = new FormData(form);

            fetch(window.location.href, {
                method: 'POST',
                body: formData
            })
                .then(res => res.json())
                .then(data => {
                    if (data.success) {
                        showMessage('Registration successful! Redirecting to login page...', 'success');
                        setTimeout(() => {
                            window.location.href = '../index.php';
                        }, 1500);
                    } else {
                        showMessage(data.message, 'error');
                        resetButton();
                    }
                })
                .catch(() => {
                    showMessage('Server not reachable. Please try again.', 'error');
                    resetButton();
                });
        });
    </script>

</body>

</html>