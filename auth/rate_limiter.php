<?php
/**
 * auth/rate_limiter.php
 * ─────────────────────────────────────────────────────────────────
 * Database-backed rate limiting for the authentication endpoints:
 * password login (auth/login.php), OTP verification
 * (handlers/verify_otp_handler.php) and OTP sending
 * (handlers/send_reset_link.php).
 *
 * Why the database and not the session: a session counter is reset by
 * simply dropping the session cookie (a fresh login page hands out a new
 * session + CSRF token), so it stops nobody. Attempts are stored in the
 * auth_rate_limits table instead, created on first use.
 *
 * Why REMOTE_ADDR only: X-Forwarded-For is set by the client, so trusting
 * it would let an attacker pick a new "IP" on every request.
 *
 * All time maths runs in MySQL (NOW()), never PHP's time(), so the two
 * clocks can't disagree about when a lockout ends.
 *
 * Usage (login):
 *   $retry = login_retry_after($conn, $email);   // seconds; 0 = allowed
 *   ... verify credentials ...
 *   $ok ? login_record_success($conn, $email) : login_record_failure($conn, $email);
 */

const RL_WINDOW_SECONDS = 900; // 15-minute sliding window for every limit below

// Password login — layered so one attacker IP is stopped fast, a distributed
// attack on one account is still capped, and one IP can't spray many accounts.
// The per-IP limit is deliberately looser: members often share the gym's Wi-Fi.
const RL_LOGIN_PAIR_MAX    = 5;   // failures per account from one IP
const RL_LOGIN_ACCOUNT_MAX = 15;  // failures per account from all IPs
const RL_LOGIN_IP_MAX      = 20;  // failures per IP across all accounts

// Password-reset OTP
const RL_OTP_VERIFY_ACCOUNT_MAX = 5;   // wrong guesses per issued code (the code is then cancelled)
const RL_OTP_VERIFY_IP_MAX      = 20;  // wrong guesses per IP
const RL_OTP_SEND_ACCOUNT_MAX   = 3;   // OTP emails per address
const RL_OTP_SEND_IP_MAX        = 10;  // OTP emails requested per IP

// ── Core ──────────────────────────────────────────────────────────

function rl_ensure_schema(mysqli $conn): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    $conn->query("CREATE TABLE IF NOT EXISTS auth_rate_limits (
        id BIGINT NOT NULL AUTO_INCREMENT,
        scope VARCHAR(32) NOT NULL,
        key_hash CHAR(64) NOT NULL,
        attempted_at DATETIME NOT NULL,
        PRIMARY KEY (id),
        KEY idx_rl_lookup (scope, key_hash, attempted_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

/** The connecting IP. Deliberately ignores client-supplied proxy headers. */
function rl_client_ip(): string
{
    return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
}

/** Identifiers are stored hashed, so the table never holds raw emails or IPs. */
function rl_key(string $identifier): string
{
    return hash('sha256', strtolower(trim($identifier)));
}

/**
 * Seconds until $identifier may try again in $scope; 0 means allowed.
 * Sliding window: once $max attempts fall inside the window, the wait lasts
 * until the oldest attempt that still matters ages out.
 */
function rl_retry_after(mysqli $conn, string $scope, string $identifier, int $max, int $window = RL_WINDOW_SECONDS): int
{
    rl_ensure_schema($conn);
    $key = rl_key($identifier);

    $stmt = $conn->prepare("SELECT COUNT(*) AS c FROM auth_rate_limits
        WHERE scope = ? AND key_hash = ? AND attempted_at > NOW() - INTERVAL ? SECOND");
    $stmt->bind_param('ssi', $scope, $key, $window);
    $stmt->execute();
    $count = (int) $stmt->get_result()->fetch_assoc()['c'];
    if ($count < $max) {
        return 0;
    }

    $offset = $count - $max;
    $stmt = $conn->prepare("SELECT GREATEST(1, TIMESTAMPDIFF(SECOND, NOW(), attempted_at + INTERVAL ? SECOND)) AS wait_s
        FROM auth_rate_limits
        WHERE scope = ? AND key_hash = ? AND attempted_at > NOW() - INTERVAL ? SECOND
        ORDER BY attempted_at ASC, id ASC
        LIMIT 1 OFFSET ?");
    $stmt->bind_param('issii', $window, $scope, $key, $window, $offset);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    return $row ? (int) $row['wait_s'] : $window;
}

/** Record one attempt for $identifier in $scope. */
function rl_hit(mysqli $conn, string $scope, string $identifier): void
{
    rl_ensure_schema($conn);
    $key = rl_key($identifier);
    $stmt = $conn->prepare("INSERT INTO auth_rate_limits (scope, key_hash, attempted_at) VALUES (?, ?, NOW())");
    $stmt->bind_param('ss', $scope, $key);
    $stmt->execute();

    // Occasional housekeeping so the table never grows unbounded
    if (random_int(1, 50) === 1) {
        $conn->query("DELETE FROM auth_rate_limits WHERE attempted_at < NOW() - INTERVAL 1 DAY");
    }
}

/** Forget every attempt for $identifier in $scope. */
function rl_clear(mysqli $conn, string $scope, string $identifier): void
{
    rl_ensure_schema($conn);
    $key = rl_key($identifier);
    $stmt = $conn->prepare("DELETE FROM auth_rate_limits WHERE scope = ? AND key_hash = ?");
    $stmt->bind_param('ss', $scope, $key);
    $stmt->execute();
}

/** Human-friendly wait, e.g. "12 minutes" / "1 minute". */
function rl_wait_text(int $seconds): string
{
    $minutes = max(1, (int) ceil($seconds / 60));
    return $minutes . ' minute' . ($minutes === 1 ? '' : 's');
}

// ── Password login ───────────────────────────────────────────────

function login_retry_after(mysqli $conn, string $email): int
{
    $ip = rl_client_ip();
    return max(
        rl_retry_after($conn, 'login_pair', $email . '|' . $ip, RL_LOGIN_PAIR_MAX),
        rl_retry_after($conn, 'login_account', $email, RL_LOGIN_ACCOUNT_MAX),
        rl_retry_after($conn, 'login_ip', $ip, RL_LOGIN_IP_MAX)
    );
}

function login_record_failure(mysqli $conn, string $email): void
{
    $ip = rl_client_ip();
    rl_hit($conn, 'login_pair', $email . '|' . $ip);
    rl_hit($conn, 'login_account', $email);
    rl_hit($conn, 'login_ip', $ip);
}

/**
 * Clears this account's counters. The per-IP counter is left to expire on its
 * own — otherwise logging into your own account would reset an attacker's
 * budget for guessing everybody else's.
 */
function login_record_success(mysqli $conn, string $email): void
{
    rl_clear($conn, 'login_pair', $email . '|' . rl_client_ip());
    rl_clear($conn, 'login_account', $email);
}

// ── Password-reset OTP ───────────────────────────────────────────

function otp_verify_retry_after(mysqli $conn, string $email): int
{
    return max(
        rl_retry_after($conn, 'otp_verify_account', $email, RL_OTP_VERIFY_ACCOUNT_MAX),
        rl_retry_after($conn, 'otp_verify_ip', rl_client_ip(), RL_OTP_VERIFY_IP_MAX)
    );
}

function otp_verify_record_failure(mysqli $conn, string $email): void
{
    rl_hit($conn, 'otp_verify_account', $email);
    rl_hit($conn, 'otp_verify_ip', rl_client_ip());
}

/** Called on a correct code, and whenever a fresh code is issued (new code = fresh guesses). */
function otp_verify_record_success(mysqli $conn, string $email): void
{
    rl_clear($conn, 'otp_verify_account', $email);
}

function otp_send_retry_after(mysqli $conn, string $email): int
{
    return max(
        rl_retry_after($conn, 'otp_send_account', $email, RL_OTP_SEND_ACCOUNT_MAX),
        rl_retry_after($conn, 'otp_send_ip', rl_client_ip(), RL_OTP_SEND_IP_MAX)
    );
}

/** Counted for every request — whether or not the email has an account — so limits never reveal that. */
function otp_send_record(mysqli $conn, string $email): void
{
    rl_hit($conn, 'otp_send_account', $email);
    rl_hit($conn, 'otp_send_ip', rl_client_ip());
}
