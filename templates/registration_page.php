<?php
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require '../config.php'; 

    header('Content-Type: application/json');

    $full_name = $_POST['full_name'] ?? '';
    $email     = $_POST['email'] ?? '';
    $password  = $_POST['password'] ?? '';

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
            "message" => "Email already registered"
        ]);
        exit;
    }

    // Hash password
    $hashed_password = password_hash($password, PASSWORD_DEFAULT);

    // Insert user
    $insert_sql = "INSERT INTO user_data (full_name, email, password)
                    VALUES (?, ?, ?)";

    $insert_stmt = mysqli_prepare($conn, $insert_sql);
    mysqli_stmt_bind_param($insert_stmt, "sss", $full_name, $email, $hashed_password);

    if (mysqli_stmt_execute($insert_stmt)) {
        echo json_encode([
            "success" => true
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
    <div class="success-overlay" id="successPopup">
        <div class="success-modal">
            <h3 id="popupTitle"></h3>
            <p id="popupMessage"></p>
            <div class="spinner"></div>
        </div>
    </div>
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
                            <input type="password" name="password" class="form-input" placeholder="Create a password" required>
                            <img src="../icons/lock-solid-full.svg" class="input-icon" alt="lock">
                        </div>
                    </div>
<button type="submit" class="btn-primary">Register Admin</button>
                </form>

                <div class="switch-form">
                    Already have an account? <a href="login_page.html">Sign In</a>
                </div>
            </div>

        </div>
    </div>
<script>
const form = document.getElementById('formRegister');
const popup = document.getElementById('successPopup');
const popupTitle = document.getElementById('popupTitle');
const popupMessage = document.getElementById('popupMessage');

form.addEventListener('submit', function (e) {
    e.preventDefault(); // stop normal submit

    const formData = new FormData(form);

    // Updated fetch URL to submit to itself
    fetch(window.location.href, {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            popupTitle.innerText = "Registration Successful 🎉";
            popupMessage.innerText = "Redirecting to login page...";
            popup.classList.add('active');

            setTimeout(() => {
                window.location.href = 'login_page.html';
            }, 1500);

        } else {
            popupTitle.innerText = "Registration Failed";
            popupMessage.innerText = data.message;
            popup.classList.add('active');

            setTimeout(() => {
                popup.classList.remove('active');
            }, 2500);
        }
    })
    .catch(() => {
        popupTitle.innerText = "Error";
        popupMessage.innerText = "Server not reachable";
        popup.classList.add('active');
    });
});
</script>

</body>
</html>