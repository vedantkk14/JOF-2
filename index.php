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
    $dest = match($_SESSION['user_role']) {
        'user'       => 'templates/user_side/user_dashboard.php',
        'counsellor' => 'templates/counsellor_side/counsellor_dashboard.php',
        default      => 'templates/dashboard.php',
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
    <meta name="description" content="Sign in to the JOF India CRM admin panel to manage members, payments, and fitness plans.">
    <link rel="stylesheet" href="static/root.css?v=<?= time(); ?>">
    <style>
        .success-overlay {
            z-index: 10000 !important;
        }
        
        .success-modal {
            background: #ffffff !important;
            padding: 40px !important;
            border-radius: 20px !important;
            text-align: center !important;
            box-shadow: 0 20px 50px rgba(0,0,0,0.2) !important;
            width: 90% !important;
            max-width: 380px !important;
            display: flex !important;
            flex-direction: column !important;
            align-items: center !important;
        }

        .login-success-icon-container {
            width: 80px !important;
            height: 80px !important;
            background-color: #10B981 !important;
            border-radius: 50% !important;
            display: flex !important;
            align-items: center !important;
            justify-content: center !important;
            margin: 0 auto 20px auto !important;
            box-shadow: 0 10px 20px rgba(16, 185, 129, 0.3) !important;
        }

        .login-success-icon {
            width: 35px !important;
            height: 35px !important;
            filter: brightness(0) invert(1) !important;
            display: block !important;
        }

        .success-modal h3 {
            font-size: 24px !important;
            color: #1A202C !important;
            margin-bottom: 8px !important;
            font-weight: 700 !important;
        }

        .success-modal p {
            color: #718096 !important;
            font-size: 15px !important;
            margin-bottom: 20px !important;
        }

        .spinner {
            width: 24px !important;
            height: 24px !important;
            border: 3px solid #EDF2F7 !important;
            border-top: 3px solid #10B981 !important;
            border-radius: 50% !important;
            animation: spin 0.8s linear infinite !important;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
    </style>
</head>
<body class="page-login_page">

<div class="success-overlay" id="successPopup">
        <div class="success-modal">
            <div class="login-success-icon-container">
                <img src="icons/check-solid-full.svg" class="login-success-icon" alt="Success">
            </div>
            <h3 id="popupTitle">Success!</h3>
            <p id="popupMessage">Logging you in...</p>
            <div class="spinner"></div>
        </div>
    </div>

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

                <form id="formLogin" action="auth/login.php" method="POST">
                    <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8') ?>">
                    <div class="input-group">
                        <label for="loginEmail">Email Address</label>
                        <div class="input-wrapper">
                            <input type="email" id="loginEmail" name="email" class="form-input" placeholder="Email Address" required autocomplete="email">
                            <img src="icons/envelope-solid-full.svg" class="input-icon" alt="email">
                        </div>
                    </div>

                    <div class="input-group">
                        <label for="loginPassword">Password</label>
                        <div class="input-wrapper">
                            <input type="password" id="loginPassword" name="password" class="form-input" placeholder="••••••••" required autocomplete="current-password">
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
        const form        = document.getElementById('formLogin');
        const popup       = document.getElementById('successPopup');
        const popupTitle  = document.getElementById('popupTitle');
        const popupMsg    = document.getElementById('popupMessage');
        const loginBtn    = document.getElementById('loginBtn');

        // If browser restores from bfcache, hide any stale spinner and reload
        window.addEventListener('pageshow', function (event) {
            if (event.persisted) {
                popup.classList.remove('active');
                window.location.reload();
            }
        });

        form.addEventListener('submit', function (e) {
            e.preventDefault();

            // Disable button to prevent double-submit
            loginBtn.disabled = true;
            loginBtn.textContent = 'Signing in…';

            const formData = new FormData(form);

            fetch('auth/login.php', {
                method: 'POST',
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    popupTitle.innerText = "Login Successful!";
                    popupMsg.innerText = "Redirecting to dashboard...";
                    popup.classList.add('active');

                    setTimeout(() => {
                        window.location.href = data.redirect || 'templates/dashboard.php';
                    }, 1500);

                } else {
                    // Show error in popup briefly, then hide
                    popupTitle.innerText = 'Login Failed';
                    popupMsg.innerText   = data.message || 'Invalid credentials.';
                    // Switch icon to error style
                    popup.classList.add('active');
                    document.querySelector('.login-success-icon-container').style.backgroundColor = '#EF4444';
                    document.querySelector('.spinner').style.display = 'none';

                    setTimeout(() => {
                        popup.classList.remove('active');
                        // Restore button
                        loginBtn.disabled = false;
                        loginBtn.textContent = 'Sign In';
                        // Reset icon for next attempt
                        document.querySelector('.login-success-icon-container').style.backgroundColor = '';
                        document.querySelector('.spinner').style.display = '';
                    }, 2800);
                }
            })
            .catch(() => {
                popupTitle.innerText = 'Connection Error';
                popupMsg.innerText   = 'Could not reach the server. Check your connection.';
                popup.classList.add('active');
                document.querySelector('.login-success-icon-container').style.backgroundColor = '#F59E0B';
                document.querySelector('.spinner').style.display = 'none';

                setTimeout(() => {
                    popup.classList.remove('active');
                    loginBtn.disabled = false;
                    loginBtn.textContent = 'Sign In';
                    document.querySelector('.login-success-icon-container').style.backgroundColor = '';
                    document.querySelector('.spinner').style.display = '';
                }, 3000);
            });
        });
    </script>

</body>
</html>