<?php
session_start();

// Destroy stale sessions that are missing user_role (pre-role-auth legacy sessions)
// Without this, old browser sessions would bypass the login form entirely
if (isset($_SESSION['user_id']) && empty($_SESSION['user_role'])) {
    session_unset();
    session_destroy();
    session_start();
}

// Already logged in with a valid role? Send to correct dashboard
if (isset($_SESSION['user_id'], $_SESSION['user_role'])) {
    $dest = match ($_SESSION['user_role']) {
        'user' => 'templates/user_side/user_dashboard.php',
        'counsellor' => 'templates/counsellor_side/counsellor_dashboard.php',
        default => 'templates/dashboard.php',
    };
    header('Location: ' . $dest);
    exit;
}

// Generate CSRF token for login form
if (empty($_SESSION['_csrf_token'])) {
    $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['_csrf_token'];
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" size="16x16" href="icons/favicon-dark-logo.png" type="image/png">
    <title>JOF INDIA | Login</title>
    <meta name="description"
        content="Sign in to the JOF India CRM admin panel to manage members, payments, and fitness plans.">
    <link rel="stylesheet" href="static/root.css?v=<?= time(); ?>">
</head>

<body class="page-login_page">

    <div class="auth-container">

        <div class="visual-side">
            <img src="icons/logo-light(1).png" alt="JOF Logo" class="brand-logo-img">
            <p class="brand-quote">
                Get the best workouts &amp; exercise plans tailored for you at JOF Fitness Center!
            </p>
        </div>

        <div class="form-side">

            <div id="loginForm">
                <div class="form-header">
                    <h2>Welcome Back! 👋</h2>
                    <p>Enter your details to access the panel.</p>
                </div>

                <div id="formMessage" class="form-message" style="display:none;"></div>

                <form id="formLogin" action="auth/login.php" method="POST">
                    <input type="hidden" name="_csrf_token"
                        value="<?= htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8') ?>">
                    <div class="input-group">
                        <label for="loginEmail">Email Address</label>
                        <div class="input-wrapper">
                            <input type="email" id="loginEmail" name="email" class="form-input"
                                placeholder="Email Address" required autocomplete="email">
                            <img src="icons/envelope-solid-full.svg" class="input-icon" alt="email">
                        </div>
                    </div>

                    <div class="input-group">
                        <label for="loginPassword">Password</label>
                        <div class="input-wrapper">
                            <input type="password" id="loginPassword" name="password" class="form-input"
                                placeholder="••••••••" required autocomplete="current-password">
                            <img src="icons/lock-solid-full.svg" class="input-icon" alt="lock">
                        </div>
                    </div>

                    <div class="actions">
                        <label class="remember-me">
                            <input type="checkbox" name="remember"> Remember me
                        </label>
                        <a href="templates/forgot_password.php" class="forgot-password">Forgot Password?</a>
                    </div>

                    <button type="submit" class="btn-primary" id="loginBtn">Sign In</button>
                </form>

                <div class="switch-form">
                    Don't have an account? <a href="templates/registration_page.php">Sign Up</a>
                </div>
            </div>

        </div>
    </div>
    <script>
        const form = document.getElementById('formLogin');
        const msgBox = document.getElementById('formMessage');
        const loginBtn = document.getElementById('loginBtn');
        const csrfInput = form.querySelector('input[name="_csrf_token"]');

        function showMessage(text, type) {
            msgBox.textContent = text;
            msgBox.className = 'form-message ' + type;
            msgBox.style.display = 'block';
        }

        function resetButton() {
            loginBtn.disabled = false;
            loginBtn.textContent = 'Sign In';
        }

        // Silently swap in the fresh CSRF token returned by the server
        function updateCsrf(data) {
            if (data.csrf_token && csrfInput) {
                csrfInput.value = data.csrf_token;
            }
        }

        // If browser restores from bfcache, reset button state and reload for fresh CSRF token
        window.addEventListener('pageshow', function (event) {
            if (event.persisted) {
                window.location.reload();
            }
        });

        form.addEventListener('submit', function (e) {
            e.preventDefault();

            // Disable button to prevent double-submit
            loginBtn.disabled = true;
            loginBtn.textContent = 'Signing in…';
            msgBox.style.display = 'none';

            const formData = new FormData(form);

            fetch('auth/login.php', {
                method: 'POST',
                body: formData
            })
                .then(res => res.json())
                .then(data => {
                    if (data.success) {
                        showMessage('Login successful! Redirecting...', 'success');
                        setTimeout(() => {
                            window.location.href = data.redirect || 'templates/dashboard.php';
                        }, 1500);
                    } else {
                        updateCsrf(data);
                        showMessage(data.message || 'Invalid credentials.', 'error');
                        resetButton();
                    }
                })
                .catch(() => {
                    showMessage('Could not reach the server. Check your connection.', 'error');
                    resetButton();
                });
        });
    </script>

</body>

</html>