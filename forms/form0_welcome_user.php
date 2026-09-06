<?php
session_start();
// Reset wizard progress when landing on the welcome page
unset($_SESSION['new_member_id']);
$_SESSION['allowed_step'] = 1;
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <link rel="icon" size="16x16" href="../icons/favicon-dark-logo.png" type="image/png">
  <title>JOF INDIA | Welcome Page</title>
  <link
    href="https://fonts.googleapis.com/css2?family=Barlow+Condensed:wght@300;400;600;700;900&family=Barlow:wght@300;400;500;600&display=swap"
    rel="stylesheet">
  <link rel="stylesheet" href="form0.css">
</head>

<body>
  <script>
    // Clear completed flag if starting a fresh onboarding
    localStorage.removeItem('jof_registration_completed');
  </script>

  <!-- LEFT: Hero panel -->
  <div class="panel-hero">
    <img class="hero-bg" src="../icons/images/man-boxing.jpeg" alt="Fitness">
    <div class="hero-content">
      <div class="hero-logo">
        <div class="logo-mark"><img src="../icons/logo-light(1).png" alt="logo"></div>
        <div class="logo-text">
          <strong>JOF INDIA</strong>
          <small>your personalized<br>fitness journey</small>
        </div>
      </div>

      <div class="hero-tagline">
        <div class="eyebrow">Member Portal</div>
        <h1>Your<br>Journey<br><em>Starts</em><br>Here</h1>
        <p>Track your progress, monitor your health, and achieve the results you've been working towards.</p>
        <div class="hero-stats">
          <div class="stat">
            <strong>4</strong>
            <span>Steps</span>
          </div>
          <div class="stat-divider"></div>
          <div class="stat">
            <strong>100%</strong>
            <span>Secure</span>
          </div>
          <div class="stat-divider"></div>
          <div class="stat">
            <strong>Live</strong>
            <span>Tracking</span>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- RIGHT: Form panel -->
  <div class="panel-form">
    <div class="form-inner">

      <!-- Mobile-only: headline + stats (hidden on desktop) -->
      <span class="mobile-hero-headline">Your Journey<br><em>Starts</em> Here</span>
      <span class="mobile-hero-sub">Track your progress, monitor your health, and hit your goals.</span>
      <div class="mobile-stats">
        <div class="stat"><strong>4</strong><span>Steps</span></div>
        <div class="stat"><strong>100%</strong><span>Secure</span></div>
        <div class="stat"><strong>Live</strong><span>Tracking</span></div>
      </div>

      <div class="form-header">
        <div class="form-badge">New Member Onboarding</div>
        <h2>Welcome<br>Aboard</h2>
        <p>Complete your profile in 4 quick steps. We'll collect your health data, measurements, and set up your
          membership.</p>
      </div>

      <div class="form-divider"></div>

      <ul class="feature-list">
        <li>
          <div class="feature-icon">
            <svg viewBox="0 0 24 24">
              <path d="M12 2a5 5 0 110 10A5 5 0 0112 2zm0 12c5.33 0 8 2.67 8 4v2H4v-2c0-1.33 2.67-4 8-4z" />
            </svg>
          </div>
          <div><strong>Health Info</strong> — Goals, medical history & lifestyle</div>
        </li>
        <li>
          <div class="feature-icon">
            <svg viewBox="0 0 24 24">
              <path d="M12 3C7 3 3 7 3 12s4 9 9 9 9-4 9-9-4-9-9-9zm1 13h-2v-5h2v5zm0-7h-2V7h2v2z" />
            </svg>
          </div>
          <div><strong>Daily Metrics</strong> — Mood, sleep, energy & hunger</div>
        </li>
        <li>
          <div class="feature-icon">
            <svg viewBox="0 0 24 24">
              <path
                d="M13 2.05v2.02c3.95.49 7 3.85 7 7.93 0 3.21-1.81 6-4.72 7.72L13 17v5h5l-1.22-1.22C19.91 19.07 22 15.76 22 12c0-5.18-3.95-9.45-9-9.95zM11 2.05C5.95 2.55 2 6.82 2 12c0 3.76 2.09 7.07 5.22 8.78L6 22h5v-5l-2.28 2.72C7.27 18.42 6 15.36 6 12c0-4.08 3.05-7.44 7-7.93V2.05z" />
            </svg>
          </div>
          <div><strong>Measurements</strong> — Body stats & fitness baseline</div>
        </li>
        <li>
          <div class="feature-icon">
            <svg viewBox="0 0 24 24">
              <path
                d="M20 4H4c-1.11 0-2 .89-2 2v12c0 1.11.89 2 2 2h16c1.11 0 2-.89 2-2V6c0-1.11-.89-2-2-2zm0 14H4v-6h16v6zm0-10H4V6h16v2z" />
            </svg>
          </div>
          <div><strong>Payment</strong> — Membership plan & billing</div>
        </li>
      </ul>

      <a href="form1_add_member.php" class="btn-enter">
        <span>Begin Registration</span>
        <span class="btn-arrow">
          Step 1 of 4
          <svg viewBox="0 0 24 24">
            <line x1="5" y1="12" x2="19" y2="12" />
            <polyline points="12 5 19 12 12 19" />
          </svg>
        </span>
      </a>

    </div>

    <div class="step-bar">
      <div class="step-pip active"></div>
      <div class="step-pip future"></div>
      <div class="step-pip future"></div>
      <div class="step-pip future"></div>
      <span class="step-label-bar">Step 1 / 4</span>
    </div>
  </div>

</body>

</html>