<?php
/**
 * auth/google_config.php
 * ─────────────────────────────────────────────────────────────────
 * Loads Google OAuth settings used by:
 *   - "Continue with Google" sign-in (GOOGLE_CLIENT_ID only — Google
 *     Identity Services one-tap flow, no secret needed).
 *   - Google Calendar auto-reminders (GOOGLE_CLIENT_ID + GOOGLE_CLIENT_SECRET
 *     — a separate, traditional OAuth authorization-code flow, since
 *     writing to a user's calendar needs an access/refresh token pair
 *     that one-tap sign-in never produces).
 *
 * Set these in the project-root .env file:
 *     GOOGLE_CLIENT_ID=xxxxxxxx.apps.googleusercontent.com
 *     GOOGLE_CLIENT_SECRET=xxxxxxxxxxxxxxxxxxxxxxxx
 *
 * Both come from the SAME OAuth client in Google Cloud Console →
 * APIs & Services → Credentials → "Web application" client. For the
 * Calendar flow to work, that client also needs, under "Authorized
 * redirect URIs", the exact URL of handlers/google_calendar_callback.php
 * on this site (e.g. http://localhost/JOF-phase2/handlers/google_calendar_callback.php),
 * and the Google Calendar API must be enabled for the project (APIs &
 * Services → Library → Google Calendar API → Enable).
 */

if (!defined('GOOGLE_CLIENT_ID')) {
    $__jof_env = __DIR__ . '/../.env';
    if (is_file($__jof_env)) {
        foreach (file($__jof_env, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $__line) {
            $__line = trim($__line);
            if ($__line === '' || $__line[0] === '#' || !str_contains($__line, '=')) {
                continue;
            }
            [$__k, $__v] = explode('=', $__line, 2);
            $__k = trim($__k);
            if ($__k !== '' && getenv($__k) === false) {
                putenv($__k . '=' . trim($__v, " \t\"'"));
            }
        }
    }

    define('GOOGLE_CLIENT_ID', getenv('GOOGLE_CLIENT_ID') ?: '');
    define('GOOGLE_CLIENT_SECRET', getenv('GOOGLE_CLIENT_SECRET') ?: '');
}
