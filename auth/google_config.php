<?php
/**
 * auth/google_config.php
 * ─────────────────────────────────────────────────────────────────
 * Loads the Google OAuth Web Client ID used by "Continue with Google"
 * on the registration page.
 *
 * Set it in the project-root .env file:
 *     GOOGLE_CLIENT_ID=xxxxxxxx.apps.googleusercontent.com
 *
 * Get the value from Google Cloud Console → APIs & Services →
 * Credentials → Create OAuth client ID → "Web application", with
 * "Authorized JavaScript origins" set to your site origin
 * (e.g. http://localhost).
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
}
