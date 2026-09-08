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

// "Continue with Google" client ID (empty string = feature disabled)
require_once __DIR__ . '/auth/google_config.php';
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

                <?php if (GOOGLE_CLIENT_ID !== ''): ?>
                    <div class="oauth-divider"><span>or</span></div>
                    <div class="gbtn" id="gbtnCustom">
                        <div class="gbtn-gis" id="gbtnGis"></div>
                        <span class="gbtn-visual" aria-hidden="true">
                            <span class="gbtn-icon">
                                <svg width="18" height="18" viewBox="0 0 48 48">
                                    <path fill="#EA4335"
                                        d="M24 9.5c3.54 0 6.71 1.22 9.21 3.6l6.85-6.85C35.9 2.38 30.47 0 24 0 14.62 0 6.51 5.38 2.56 13.22l7.98 6.19C12.43 13.72 17.74 9.5 24 9.5z" />
                                    <path fill="#4285F4"
                                        d="M46.98 24.55c0-1.57-.15-3.09-.38-4.55H24v9.02h12.94c-.58 2.96-2.26 5.48-4.78 7.18l7.73 6c4.51-4.18 7.09-10.36 7.09-17.65z" />
                                    <path fill="#FBBC05"
                                        d="M10.53 28.59c-.48-1.45-.76-2.99-.76-4.59s.27-3.14.76-4.59l-7.98-6.19C.92 16.46 0 20.12 0 24c0 3.88.92 7.54 2.56 10.78l7.97-6.19z" />
                                    <path fill="#34A853"
                                        d="M24 48c6.48 0 11.93-2.13 15.89-5.81l-7.73-6c-2.15 1.45-4.92 2.3-8.16 2.3-6.26 0-11.57-4.22-13.47-9.91l-7.98 6.19C6.51 42.62 14.62 48 24 48z" />
                                </svg>
                            </span>
                            <span class="gbtn-label">Continue with Google<small>Sign in with your Google
                                    account</small></span>
                            <svg class="gbtn-arrow" width="16" height="16" viewBox="0 0 24 24" fill="none"
                                stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M5 12h14M13 6l6 6-6 6" />
                            </svg>
                        </span>
                    </div>
                <?php endif; ?>

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

    <?php if (GOOGLE_CLIENT_ID !== ''): ?>
        <script>
            // Google sign-in: send the ID token to the server, then go to the returned dashboard
            window.handleGoogleCredential = function (response) {
                showMessage('Signing you in with Google…', 'success');

                const body = new URLSearchParams();
                body.set('credential', response.credential);
                body.set('_csrf_token', <?= json_encode($csrf_token) ?>);

                fetch('auth/google_auth.php', { method: 'POST', body: body })
                    .then(res => res.json())
                    .then(data => {
                        if (data.success) {
                            showMessage('Login successful! Redirecting...', 'success');
                            setTimeout(() => { window.location.href = data.redirect; }, 800);
                        } else {
                            showMessage(data.message || 'Google sign-in failed. Please try again.', 'error');
                        }
                    })
                    .catch(() => showMessage('Could not reach the server. Check your connection.', 'error'));
            };

            // Render the real Google button ONCE, underneath the custom skin.
            // It stays fully visible to the browser (so GIS keeps it working);
            // the skin on top hides it visually and passes clicks through.
            window.onGoogleLibraryLoad = function () {
                const host = document.getElementById('gbtnGis');
                if (!host || typeof google === 'undefined') return;

                google.accounts.id.initialize({
                    client_id: <?= json_encode(GOOGLE_CLIENT_ID) ?>,
                    callback: window.handleGoogleCredential,
                    auto_select: false,
                    cancel_on_tap_outside: true
                });

                google.accounts.id.renderButton(host, {
                    type: 'standard', theme: 'outline', size: 'large',
                    text: 'continue_with', shape: 'rectangular', width: 400
                });
            };
        </script>
        <script src="https://accounts.google.com/gsi/client" async defer></script>
    <?php endif; ?>

</body>

</html>