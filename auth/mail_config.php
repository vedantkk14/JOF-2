<?php
/**
 * auth/mail_config.php
 * ─────────────────────────────────────────────────────────────────
 * Single source of truth for outgoing SMTP credentials.
 *
 * Every send_*.php in the app pulls its mailbox / App Password from
 * here, so a credential change is a ONE-LINE edit.
 *
 * ── To change the credentials ────────────────────────────────────
 *   Preferred : create a file called ".env" in the project root
 *               (c:\xampp\htdocs\jof-phase_2\.env) containing:
 *
 *                 SMTP_USERNAME=youraddress@gmail.com
 *                 SMTP_PASSWORD=your16charAppPassword
 *                 SMTP_FROM_EMAIL=youraddress@gmail.com
 *
 *               (.env is git-ignored, so the secret stays out of the repo)
 *
 *   Fallback  : edit the default values in the define() calls below.
 *
 * ── Usage in a sender ───────────────────────────────────────────
 *   require_once __DIR__ . '/mail_config.php';        // adjust ../ from handlers/templates
 *   $mail = new PHPMailer\PHPMailer\PHPMailer(true);
 *   jof_configure_mailer($mail);                      // host + auth + from
 *   // caller still sets ->addAddress(), ->Subject, ->Body, ->isHTML(), ->send()
 */

if (!defined('JOF_MAIL_CONFIG_LOADED')) {
    define('JOF_MAIL_CONFIG_LOADED', true);

    // Pull overrides from the project-root .env file, if it exists.
    $__jof_env = __DIR__ . '/../.env';
    if (is_file($__jof_env)) {
        foreach (file($__jof_env, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $__line) {
            $__line = trim($__line);
            if ($__line === '' || $__line[0] === '#' || !str_contains($__line, '=')) {
                continue;
            }
            [$__k, $__v] = explode('=', $__line, 2);
            $__k = trim($__k);
            $__v = trim($__v, " \t\"'");
            if ($__k !== '' && getenv($__k) === false) {
                putenv($__k . '=' . $__v);
            }
        }
    }

    // ── DEFAULTS — override via .env (see header) ─────────────────
    define('SMTP_HOST',       getenv('SMTP_HOST')        ?: 'smtp.gmail.com');
    define('SMTP_PORT',       (int) (getenv('SMTP_PORT') ?: 587));
    define('SMTP_SECURE',     getenv('SMTP_SECURE')      ?: 'tls');   // 'tls' = STARTTLS/587, 'ssl' = SMTPS/465
    define('SMTP_USERNAME',   getenv('SMTP_USERNAME')    ?: 'iglmembershipid@gmail.com');
    define('SMTP_PASSWORD',   getenv('SMTP_PASSWORD')    ?: 'hclvlxtfmfxnywwm'); // Gmail App Password — REPLACE ME
    define('SMTP_FROM_EMAIL', getenv('SMTP_FROM_EMAIL')  ?: 'iglmembershipid@gmail.com');
    define('SMTP_FROM_NAME',  getenv('SMTP_FROM_NAME')   ?: 'JOF INDIA');
}

/**
 * Apply the shared SMTP settings to a PHPMailer instance.
 * Namespace-agnostic (uses the raw 'tls'/'ssl' strings, so it works
 * whether the caller imported PHPMailer with `use` or the FQCN).
 *
 * @param object $mail  A PHPMailer instance.
 * @param array  $opts  Optional per-message overrides:
 *                       'secret'|'secure' => 'tls'|'ssl', 'port' => int,
 *                       'from_name' => string, 'from_email' => string
 */
function jof_configure_mailer($mail, array $opts = []): void
{
    $secure = $opts['secure'] ?? SMTP_SECURE;
    $port   = $opts['port']   ?? SMTP_PORT;

    $mail->isSMTP();
    $mail->Host       = SMTP_HOST;
    $mail->SMTPAuth   = true;
    $mail->Username   = SMTP_USERNAME;
    $mail->Password   = SMTP_PASSWORD;
    $mail->SMTPSecure = ($secure === 'ssl') ? 'ssl' : 'tls';
    $mail->Port       = (int) $port;
    $mail->CharSet    = 'UTF-8';

    $mail->setFrom(
        $opts['from_email'] ?? SMTP_FROM_EMAIL,
        $opts['from_name']  ?? SMTP_FROM_NAME
    );
}
