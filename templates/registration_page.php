<?php
session_start();
require_once __DIR__ . '/../auth/google_config.php';

// CSRF token used by the "Continue with Google" flow
if (empty($_SESSION['_csrf_token'])) {
    $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['_csrf_token'];

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
    <link rel="stylesheet" href="../static/root.css?v=<?= @filemtime(__DIR__ . '/../static/root.css') ?>">
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
                    <button type="submit" class="btn-primary" id="registerBtn">Register using Email</button>
                </form>

                <?php if (GOOGLE_CLIENT_ID !== ''): ?>
                    <div class="oauth-divider"><span>or</span></div>
                    <div class="gbtn" id="gbtnCustom" role="button" tabindex="0" aria-label="Continue with Google">
                        <span class="gbtn-icon">
                            <svg width="18" height="18" viewBox="0 0 48 48" aria-hidden="true">
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
                        <span class="gbtn-label">Continue with Google<small>Auto-fills your details &amp; signs you
                                in</small></span>
                        <svg class="gbtn-arrow" width="16" height="16" viewBox="0 0 24 24" fill="none"
                            stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M5 12h14M13 6l6 6-6 6" />
                        </svg>
                        <span class="gbtn-gis" id="gbtnGis"></span>
                    </div>
                <?php endif; ?>

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

    <?php if (GOOGLE_CLIENT_ID !== ''): ?>
        <script>
            // Called by Google Identity Services once the user picks an account
            window.handleGoogleCredential = function (response) {
                showMessage('Signing you in with Google…', 'success');

                const body = new URLSearchParams();
                body.set('credential', response.credential);
                body.set('_csrf_token', <?= json_encode($csrf_token) ?>);

                fetch('../auth/google_auth.php', { method: 'POST', body: body })
                    .then(res => res.json())
                    .then(data => {
                        if (data.success) {
                            showMessage('Success! Redirecting…', 'success');
                            setTimeout(() => { window.location.href = data.redirect; }, 800);
                        } else {
                            showMessage(data.message || 'Google sign-in failed. Please try again.', 'error');
                        }
                    })
                    .catch(() => showMessage('Could not reach the server. Please try again.', 'error'));
            };

            // Drive our custom-designed button with an invisible real Google button on top
            window.onGoogleLibraryLoad = function () {
                const host = document.getElementById('gbtnGis');
                const btn = document.getElementById('gbtnCustom');
                if (!host || !btn || typeof google === 'undefined') return;

                google.accounts.id.initialize({
                    client_id: <?= json_encode(GOOGLE_CLIENT_ID) ?>,
                    callback: window.handleGoogleCredential,
                    auto_select: false,
                    cancel_on_tap_outside: true
                });

                const render = () => {
                    host.innerHTML = '';
                    const w = Math.max(200, Math.min(400, Math.round(btn.clientWidth || 360)));
                    google.accounts.id.renderButton(host, {
                        type: 'standard', theme: 'outline', size: 'large',
                        text: 'continue_with', shape: 'rectangular', width: w
                    });
                };
                render();

                let t;
                window.addEventListener('resize', () => { clearTimeout(t); t = setTimeout(render, 200); });

                // Forward keyboard + any stray clicks to the real Google button
                const fire = () => {
                    const real = host.querySelector('div[role="button"], button, iframe');
                    if (real && real.click) real.click();
                };
                btn.addEventListener('keydown', (e) => {
                    if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); fire(); }
                });
                btn.addEventListener('click', (e) => { if (!host.contains(e.target)) fire(); });
                btn.addEventListener('focus', () => btn.classList.add('is-focus'));
                btn.addEventListener('blur', () => btn.classList.remove('is-focus'));
            };
        </script>
        <script src="https://accounts.google.com/gsi/client" async defer></script>
    <?php endif; ?>

</body>

</html>