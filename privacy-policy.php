<?php
/**
 * privacy-policy.php
 * Public privacy policy for JOF CRM and JOF Diet Reminders (Google Calendar sync).
 * Linked from the Google OAuth consent screen.
 */
$lastUpdated = '5 October 2026';
$contactEmail = 'info@jofindia.com';
$dashboardUrl = 'index.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Privacy policy for JOF CRM and JOF Diet Reminders, including how we access Google Calendar data.">
    <link rel="icon" sizes="96x96" href="icons/favicon-dark-logo.png" type="image/png">
    <title>Privacy Policy | JOF India</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" crossorigin="anonymous">
    <style>
        body { background: #f5f6f8; color: #1f2937; line-height: 1.7; }
        .policy-wrap { max-width: 860px; margin: 0 auto; padding: 32px 16px 64px; }
        .policy-card { background: #fff; border-radius: 14px; padding: 36px 40px; box-shadow: 0 4px 18px rgba(0, 0, 0, 0.06); }
        .policy-card h1 { font-size: 1.9rem; font-weight: 700; color: #111827; margin-bottom: 4px; }
        .policy-card h2 { font-size: 1.2rem; font-weight: 700; color: #111827; margin-top: 32px; margin-bottom: 10px; }
        .policy-card h3 { font-size: 1rem; font-weight: 600; margin-top: 18px; }
        .policy-card ul { padding-left: 22px; }
        .policy-card li { margin-bottom: 6px; }
        .policy-meta { color: #6b7280; font-size: 0.92rem; margin-bottom: 20px; }
        .policy-card a { color: #e05a1e; }
        .policy-note { background: #fff7ed; border-left: 4px solid #e05a1e; padding: 14px 18px; border-radius: 8px; }
        .policy-header { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 12px; margin-bottom: 24px; padding-bottom: 16px; border-bottom: 1px solid #ececf1; }
        .policy-brand { font-size: 1.05rem; font-weight: 800; letter-spacing: 0.04em; color: #111827; text-transform: uppercase; }
        .policy-back { display: inline-block; padding: 8px 16px; border-radius: 999px; border: 1px solid #e05a1e; color: #e05a1e; font-weight: 600; font-size: 0.9rem; text-decoration: none; }
        .policy-back:hover { background: #fff7ed; color: #c2410c; }
        @media (max-width: 576px) {
            .policy-card { padding: 24px 20px; }
            .policy-card h1 { font-size: 1.5rem; }
        }
    </style>
</head>
<body>
<main class="policy-wrap">
    <article class="policy-card">

        <header class="policy-header">
            <span class="policy-brand">JOF India</span>
            <a class="policy-back" href="<?= htmlspecialchars($dashboardUrl) ?>">&larr; Back to Dashboard</a>
        </header>

        <h1>Privacy Policy</h1>
        <p class="policy-meta">
            JOF India &middot; JOF CRM (crm.jofindia.com) &middot; JOF Diet Reminders<br>
            Last updated: <?= htmlspecialchars($lastUpdated) ?>
        </p>

        <p>
            This policy explains what information JOF India (&ldquo;we&rdquo;, &ldquo;us&rdquo;) collects through JOF CRM at
            <a href="https://crm.jofindia.com">crm.jofindia.com</a>, how we use it, and the choices you have.
            JOF Diet Reminders is a feature within JOF CRM that syncs your assigned meal schedule to your Google Calendar.
        </p>

        <h2>1. Who We Are</h2>
        <h3>JOF India</h3>
        <p>Aurelia, Pancard Road, Baner, Pune - 411045, Maharashtra, India.</p>
        <p>Contact for privacy questions: <a href="mailto:<?= htmlspecialchars($contactEmail) ?>"><?= htmlspecialchars($contactEmail) ?></a></p>

        <h2>2. Information We Collect</h2>
        <ul>
            <li><strong>Sign-in details:</strong> If you sign in using Google, we receive your name and email address to create or match your member account.</li>
            <li><strong>Diet plan details:</strong> The diet plan assigned to you by JOF India, including meal names and scheduled times, so reminder events can be generated.</li>
            <li><strong>Google Calendar connection details:</strong> When you connect your Google account, we store access and refresh tokens issued by Google, the granted permission scopes, and the unique IDs of the calendar events created by our app.</li>
        </ul>
        <div class="policy-note">
            <strong>Note:</strong> We do not collect or store your Google Account password, and we do not access, read, or track any other non-app calendar events.
        </div>

        <h2>3. Google User Data We Access</h2>
        <p>When you choose <strong>Enable Meal Reminders</strong>, we request access to your Google Calendar via the following scope:</p>
        <p><code>https://www.googleapis.com/auth/calendar.events</code></p>
        <p>This scope allows our system to create, update, and delete calendar events strictly associated with your meal schedule.</p>

        <h2>4. Why We Access It</h2>
        <p>We access Google Calendar strictly to:</p>
        <ol>
            <li>Create meal reminder events on your primary calendar based on your assigned diet plan.</li>
            <li>Update or delete those specific reminder events when your diet plan changes or when you disconnect the service.</li>
        </ol>
        <p>We do not use this access for any other purpose.</p>

        <h2>5. Data Sharing, Selling, and AI/ML Disclosure</h2>
        <ul>
            <li><strong>No Selling:</strong> We do not sell Google user data.</li>
            <li><strong>No Advertising:</strong> We do not share, transfer, or use Google user data for serving ads, retargeting, or personalized marketing.</li>
            <li><strong>No AI/ML Training:</strong> We do not use Google user data to train, tune, or improve generalized machine learning (ML) or artificial intelligence (AI) models.</li>
            <li><strong>Service Providers:</strong> We share information only with standard infrastructure service providers necessary to run JOF CRM (such as our web hosting provider), and only to the minimal extent needed. We may also disclose data if required by applicable law.</li>
        </ul>

        <h2>6. Data Storage, Security &amp; Human Access</h2>
        <ul>
            <li><strong>Token Security:</strong> Your Google Calendar tokens are stored securely in our encrypted database and are accessible solely by our web backend application.</li>
            <li><strong>Encryption:</strong> All data transmitted between your browser, our servers, and Google is encrypted using HTTPS (TLS).</li>
            <li><strong>Human Access Prohibition:</strong> No human employee or administrator reads your Google user data unless you provide explicit consent for technical support, or it is strictly required for security investigations or legal compliance.</li>
        </ul>

        <h2>7. Retention and Deletion</h2>
        <p>We maintain your Google Calendar connection details while the feature remains active.</p>
        <ul>
            <li>When you click <strong>Disconnect Google Calendar</strong>, stored tokens for your account are deleted immediately from our database.</li>
            <li>Events already synced to your Google Calendar will remain on your calendar until you remove them manually or request account deletion.</li>
            <li>To request full deletion of your JOF CRM account data, email <a href="mailto:<?= htmlspecialchars($contactEmail) ?>"><?= htmlspecialchars($contactEmail) ?></a>.</li>
        </ul>

        <h2>8. How You Can Revoke Access</h2>
        <p>You can revoke calendar permissions at any time:</p>
        <ol>
            <li><strong>From JOF CRM:</strong> Navigate to your Diet Plans page in the member portal and select <strong>Disconnect Google Calendar</strong>.</li>
            <li><strong>From your Google Account:</strong> Go to <a href="https://myaccount.google.com/permissions" target="_blank" rel="noopener noreferrer">myaccount.google.com/permissions</a>, select <strong>JOF Diet Reminders</strong>, and click <strong>Remove access</strong>.</li>
        </ol>

        <h2>9. Google API Limited Use Disclosure</h2>
        <p>
            JOF Diet Reminders's use and transfer to any other app of information received from Google APIs will adhere to the
            <a href="https://developers.google.com/terms/api-services-user-data-policy" target="_blank" rel="noopener noreferrer">Google API Services User Data Policy</a>,
            including the Limited Use requirements.
        </p>

        <h2>10. Changes to This Policy</h2>
        <p>
            We may update this policy periodically. Any revisions will be reflected by updating the &ldquo;Last updated&rdquo; date at the top of this page.
        </p>

        <h2>11. Contact Us</h2>
        <p>
            For questions regarding this policy or your personal data, contact:
            <a href="mailto:<?= htmlspecialchars($contactEmail) ?>"><?= htmlspecialchars($contactEmail) ?></a>
        </p>

    </article>
</main>
</body>
</html>
