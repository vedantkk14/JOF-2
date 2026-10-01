<?php
/**
 * auth/google_calendar_helper.php
 * ─────────────────────────────────────────────────────────────────
 * Everything needed to turn an assigned diet plan into automatic
 * Google Calendar reminders for a member who has connected their
 * Google account for this feature (separate from, and in addition
 * to, "Continue with Google" sign-in — see google_config.php).
 *
 * Public entry points used elsewhere in the app:
 *   - google_calendar_auth_url(string $state): string
 *   - google_calendar_exchange_code(string $code): ?array
 *   - google_calendar_is_connected(mysqli $conn, int $user_id): bool
 *   - google_calendar_disconnect(mysqli $conn, int $user_id): void
 *   - sync_member_diet_calendar(mysqli $conn, int $plan_id, int $member_id): array
 */

require_once __DIR__ . '/google_config.php';
require_once __DIR__ . '/calendar_schema.php';

const GOOGLE_CALENDAR_SCOPE = 'https://www.googleapis.com/auth/calendar.events';
const GOOGLE_CALENDAR_TIMEZONE = 'Asia/Kolkata';
const GOOGLE_CALENDAR_REMINDER_MINUTES = 30;

/* ───────────────────────── OAuth: connect flow ───────────────────────── */

/**
 * Absolute URL of handlers/google_calendar_callback.php on this install,
 * derived the same way auth/google_auth.php derives redirect paths —
 * works whether the site lives at the domain root or a subfolder.
 */
function google_calendar_redirect_uri(): string
{
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $base = str_replace('\\', '/', dirname(dirname($_SERVER['SCRIPT_NAME'] ?? '')));
    $base = ($base === '/' || $base === '.' || $base === '') ? '' : rtrim($base, '/');
    return "{$scheme}://{$host}{$base}/handlers/google_calendar_callback.php";
}

/**
 * The URL to send the member to for the "Enable Meal Reminders" consent
 * screen. $state should be an unguessable, session-bound value that
 * google_calendar_callback.php verifies before trusting the callback.
 */
function google_calendar_auth_url(string $state): string
{
    $params = [
        'client_id'              => GOOGLE_CLIENT_ID,
        'redirect_uri'           => google_calendar_redirect_uri(),
        'response_type'          => 'code',
        'scope'                  => GOOGLE_CALENDAR_SCOPE,
        'access_type'            => 'offline', // required to get a refresh_token
        'prompt'                 => 'consent', // force the consent screen so we always get a refresh_token
        'include_granted_scopes' => 'true',
        'state'                  => $state,
    ];
    return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query($params);
}

/**
 * Exchanges an authorization code (from the callback's ?code=...) for an
 * access_token + refresh_token pair. Returns null on failure.
 */
function google_calendar_exchange_code(string $code): ?array
{
    return _google_calendar_http_post('https://oauth2.googleapis.com/token', [
        'code'          => $code,
        'client_id'     => GOOGLE_CLIENT_ID,
        'client_secret' => GOOGLE_CLIENT_SECRET,
        'redirect_uri'  => google_calendar_redirect_uri(),
        'grant_type'    => 'authorization_code',
    ]);
}

function _google_calendar_refresh_access_token(string $refresh_token): ?array
{
    return _google_calendar_http_post('https://oauth2.googleapis.com/token', [
        'refresh_token' => $refresh_token,
        'client_id'     => GOOGLE_CLIENT_ID,
        'client_secret' => GOOGLE_CLIENT_SECRET,
        'grant_type'    => 'refresh_token',
    ]);
}

/* ───────────────────────── Token storage ───────────────────────── */

function google_calendar_is_connected(mysqli $conn, int $user_id): bool
{
    $stmt = $conn->prepare("SELECT user_id FROM user_calendar_tokens WHERE user_id = ?");
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    return (bool) $stmt->get_result()->fetch_assoc();
}

function google_calendar_store_tokens(mysqli $conn, int $user_id, array $tokens): void
{
    $access_token = $tokens['access_token'] ?? '';
    $refresh_token = $tokens['refresh_token'] ?? '';
    $expires_in = (int) ($tokens['expires_in'] ?? 3600);
    $scope = $tokens['scope'] ?? GOOGLE_CALENDAR_SCOPE;
    $expires_at = date('Y-m-d H:i:s', time() + $expires_in - 60); // 60s safety margin

    if ($refresh_token === '') {
        // Google only sends a refresh_token on the FIRST consent for this
        // client+user pair. If we already have one stored, keep it and just
        // update the access token; otherwise the caller must ask the user
        // to reconnect (re-prompt handled by prompt=consent on our auth URL).
        $stmt = $conn->prepare(
            "UPDATE user_calendar_tokens SET access_token=?, token_expires_at=?, scope=? WHERE user_id=?"
        );
        $stmt->bind_param('sssi', $access_token, $expires_at, $scope, $user_id);
        $stmt->execute();
        return;
    }

    $stmt = $conn->prepare(
        "INSERT INTO user_calendar_tokens (user_id, access_token, refresh_token, token_expires_at, scope)
         VALUES (?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE access_token=VALUES(access_token), refresh_token=VALUES(refresh_token),
             token_expires_at=VALUES(token_expires_at), scope=VALUES(scope)"
    );
    $stmt->bind_param('issss', $user_id, $access_token, $refresh_token, $expires_at, $scope);
    $stmt->execute();
}

function google_calendar_disconnect(mysqli $conn, int $user_id): void
{
    $stmt = $conn->prepare("DELETE FROM user_calendar_tokens WHERE user_id = ?");
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
}

/**
 * Returns a currently-valid access token for this user, refreshing it via
 * the stored refresh_token if it has expired. Returns null if the user
 * isn't connected, or the refresh itself fails (e.g. access was revoked).
 */
function google_calendar_get_valid_token(mysqli $conn, int $user_id): ?string
{
    $stmt = $conn->prepare("SELECT access_token, refresh_token, token_expires_at FROM user_calendar_tokens WHERE user_id = ?");
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    if (!$row) {
        return null;
    }

    if (strtotime($row['token_expires_at']) > time()) {
        return $row['access_token'];
    }

    $fresh = _google_calendar_refresh_access_token($row['refresh_token']);
    if (!$fresh || empty($fresh['access_token'])) {
        // Refresh failed — most likely the member revoked access from their
        // Google account. Drop the dead row so the UI shows "not connected".
        google_calendar_disconnect($conn, $user_id);
        return null;
    }

    $expires_at = date('Y-m-d H:i:s', time() + (int) ($fresh['expires_in'] ?? 3600) - 60);
    $upd = $conn->prepare("UPDATE user_calendar_tokens SET access_token=?, token_expires_at=? WHERE user_id=?");
    $upd->bind_param('ssi', $fresh['access_token'], $expires_at, $user_id);
    $upd->execute();

    return $fresh['access_token'];
}

/* ───────────────────────── Meal-time extraction ───────────────────────── */

/**
 * Splits a diet_plans row's 4 packed columns into labeled sub-sections
 * (same "**LABEL:**" markers used everywhere else in this app) and, for
 * each one, finds the first clock time mentioned in its body text using
 * the exact same detector that bolds times orange in the emailed PDF.
 * A field with no time typed by the admin is simply omitted — never
 * guessed. GUIDELINES is skipped; it isn't a meal.
 *
 * Returns: ['wake_up' => ['label'=>'Wake Up','time'=>'06:30:00'], ...]
 */
function extract_diet_plan_meal_times(array $plan): array
{
    $sections = [
        'wake_up'      => ['label' => 'Wake Up',      'marker' => 'WAKE UP',      'col' => 'breakfast'],
        'post_workout' => ['label' => 'Post Workout', 'marker' => 'POST WORKOUT', 'col' => 'breakfast'],
        'breakfast'    => ['label' => 'Breakfast',     'marker' => 'BREAKFAST',    'col' => 'breakfast'],
        'lunch'        => ['label' => 'Lunch',         'marker' => 'LUNCH',        'col' => 'lunch'],
        'snack'        => ['label' => 'Mid Meal',      'marker' => 'MID MEAL',     'col' => 'snack'],
        'dinner'       => ['label' => 'Dinner',        'marker' => 'DINNER',       'col' => 'dinner'],
        'pre_sleep'    => ['label' => 'Pre-Sleep',     'marker' => 'PRE-SLEEP',    'col' => 'dinner'],
    ];

    $out = [];
    foreach ($sections as $field => $s) {
        $col = $plan[$s['col']] ?? '';
        if ($col === '') {
            continue;
        }

        // Grab the text between this section's "**MARKER...:**" heading and the
        // next "**...**" heading (or end of the column) — same shape used to
        // unpack these fields for editing in add_new_phase.php/edit_diet_plan.php.
        $pattern = '/\*\*\s*' . preg_quote($s['marker'], '/') . '\s*(?:\([^)]*\))?\s*:?\s*\*\*\s*(.*?)(?=\*\*|$)/su';
        if (!preg_match($pattern, $col, $m)) {
            continue;
        }
        $body = trim($m[1]);
        if ($body === '') {
            continue;
        }

        $time = _extract_first_time($body);
        if ($time !== null) {
            $out[$field] = ['label' => $s['label'], 'time' => $time];
        }
    }

    return $out;
}

/**
 * Scans text line-by-line for the first clock time or time-range (matching
 * the same $timeRe pattern used in auth/send_diet_plan.php's PDF formatter),
 * and returns it as "HH:MM:SS" (24-hour), or null if no time is present.
 * For a range ("5pm-6pm"), the START time is used as the reminder anchor.
 */
function _extract_first_time(string $text): ?string
{
    $timeRe = '/('
        . '(?:\d{3,4}|\d{1,2}(?:[:.]\d{2})?)\s*(?:[AP]\.?M\.?)?\s*[-\x{2013}\x{2014}~]\s*(?:\d{3,4}|\d{1,2}(?:[:.]\d{2})?)\s*[AP]\.?M\.?'
        . '|(?:\d{3,4}|\d{1,2}(?:[:.]\d{2})?)\s*[AP]\.?M\.?'
        . ')/iu';

    foreach (explode("\n", $text) as $line) {
        if (!preg_match($timeRe, $line, $m)) {
            continue;
        }
        // Only the START of a range/single time matters for scheduling. A range
        // often states AM/PM only once, on the END ("6:30–7:00 AM") — if the
        // start half has no meridiem of its own, borrow the end's.
        $parts = preg_split('/\s*[-\x{2013}\x{2014}~]\s*/u', $m[1]);
        $first = $parts[0];
        if (count($parts) > 1 && !preg_match('/[AP]\.?M\.?$/i', $first) && preg_match('/([AP]\.?M\.?)$/i', $parts[1], $mm)) {
            $first .= ' ' . $mm[1];
        }
        $h24 = _time_string_to_24h($first);
        if ($h24 !== null) {
            return $h24;
        }
    }
    return null;
}

/**
 * "6 AM" / "1130 AM" / "6:30pm" / "630 PM" -> "HH:MM:SS" (24h), or null.
 */
function _time_string_to_24h(string $raw): ?string
{
    $raw = trim($raw);
    if (!preg_match('/^(\d{1,4})(?:[:.](\d{2}))?\s*([AP])\.?M\.?$/i', $raw, $m)) {
        return null;
    }
    $digits = $m[1];
    // $m[2] is '' (not absent) when the optional colon-group didn't match, so
    // check for empty string too — plain `?? null` would never catch it and
    // "1130 AM" / "630 PM" (no colon) would wrongly fall into the else branch.
    $minutePart = (($m[2] ?? '') !== '') ? $m[2] : null;
    $meridiem = strtoupper($m[3]);

    if ($minutePart === null && strlen($digits) >= 3) {
        // "1130" -> hour=11, min=30 ; "630" -> hour=6, min=30
        $minutePart = substr($digits, -2);
        $hour = (int) substr($digits, 0, strlen($digits) - 2);
    } else {
        $hour = (int) $digits;
    }
    $minute = $minutePart !== null ? (int) $minutePart : 0;

    if ($hour < 1 || $hour > 12 || $minute > 59) {
        return null;
    }
    $hour = $hour % 12;
    if ($meridiem === 'P') {
        $hour += 12;
    }
    return sprintf('%02d:%02d:00', $hour, $minute);
}

/* ───────────────────────── Calendar API ───────────────────────── */

function _google_calendar_api_request(string $method, string $url, string $access_token, ?array $body = null): ?array
{
    $ch = curl_init($url);
    $headers = ['Authorization: Bearer ' . $access_token, 'Content-Type: application/json'];
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_HTTPHEADER     => $headers,
    ];
    if ($body !== null) {
        $opts[CURLOPT_POSTFIELDS] = json_encode($body);
    }
    curl_setopt_array($ch, $opts);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($resp === false) {
        return null;
    }
    if ($code === 204 || $resp === '') {
        return ['success' => true];
    }
    $data = json_decode($resp, true);
    if ($code >= 400) {
        error_log('[GOOGLE-CALENDAR] API error ' . $code . ' on ' . $url . ': ' . $resp);
        return null;
    }
    return is_array($data) ? $data : null;
}

function _google_calendar_http_post(string $url, array $fields): ?array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query($fields),
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($resp === false || $code >= 400) {
        error_log('[GOOGLE-CALENDAR] token endpoint error ' . $code . ': ' . ($resp ?: 'no response'));
        return null;
    }
    $data = json_decode($resp, true);
    return is_array($data) ? $data : null;
}

function google_calendar_create_event(
    string $access_token,
    string $summary,
    string $startDate,   // "Y-m-d"
    string $startTime,   // "H:i:s" (24h)
    int $durationMinutes,
    int $repeatDays
): ?string {
    $endTime = date('H:i:s', strtotime($startTime) + $durationMinutes * 60);

    $event = [
        'summary'     => $summary,
        'start'       => ['dateTime' => "{$startDate}T{$startTime}", 'timeZone' => GOOGLE_CALENDAR_TIMEZONE],
        'end'         => ['dateTime' => "{$startDate}T{$endTime}", 'timeZone' => GOOGLE_CALENDAR_TIMEZONE],
        'recurrence'  => ["RRULE:FREQ=DAILY;COUNT={$repeatDays}"],
        'reminders'   => [
            'useDefault' => false,
            'overrides'  => [
                ['method' => 'popup', 'minutes' => GOOGLE_CALENDAR_REMINDER_MINUTES],
            ],
        ],
    ];

    $res = _google_calendar_api_request(
        'POST',
        'https://www.googleapis.com/calendar/v3/calendars/primary/events',
        $access_token,
        $event
    );

    return $res['id'] ?? null;
}

function google_calendar_delete_event(string $access_token, string $event_id): void
{
    _google_calendar_api_request(
        'DELETE',
        'https://www.googleapis.com/calendar/v3/calendars/primary/events/' . urlencode($event_id),
        $access_token
    );
}

/* ───────────────────────── Orchestration ───────────────────────── */

/**
 * Called after a diet plan is (re-)assigned to a member. If that member's
 * account has a connected Google Calendar, deletes any reminders left over
 * from a previous plan/phase and creates fresh ones for every meal that has
 * a time in it. Silently does nothing (returns connected=false) for members
 * who haven't connected calendar reminders — assigning/emailing the plan
 * still succeeds either way.
 */
function sync_member_diet_calendar(mysqli $conn, int $plan_id, int $member_id): array
{
    $stmt = $conn->prepare("SELECT user_id FROM members WHERE id = ?");
    $stmt->bind_param('i', $member_id);
    $stmt->execute();
    $member = $stmt->get_result()->fetch_assoc();
    $user_id = (int) ($member['user_id'] ?? 0);

    if ($user_id <= 0) {
        return ['connected' => false, 'reason' => 'no_linked_account'];
    }

    $access_token = google_calendar_get_valid_token($conn, $user_id);
    if (!$access_token) {
        return ['connected' => false, 'reason' => 'not_connected'];
    }

    // Remove every previously-created event for this member (any past plan),
    // so switching plans/phases never leaves stale reminders behind.
    $old = $conn->prepare("SELECT id, google_event_id FROM diet_plan_calendar_events WHERE member_id = ?");
    $old->bind_param('i', $member_id);
    $old->execute();
    $oldRows = $old->get_result()->fetch_all(MYSQLI_ASSOC);
    foreach ($oldRows as $row) {
        google_calendar_delete_event($access_token, $row['google_event_id']);
    }
    if ($oldRows) {
        $del = $conn->prepare("DELETE FROM diet_plan_calendar_events WHERE member_id = ?");
        $del->bind_param('i', $member_id);
        $del->execute();
    }

    $pstmt = $conn->prepare("SELECT * FROM diet_plans WHERE id = ?");
    $pstmt->bind_param('i', $plan_id);
    $pstmt->execute();
    $plan = $pstmt->get_result()->fetch_assoc();
    if (!$plan) {
        return ['connected' => true, 'created' => 0, 'reason' => 'plan_not_found'];
    }

    $meals = extract_diet_plan_meal_times($plan);
    if (!$meals) {
        return ['connected' => true, 'created' => 0, 'reason' => 'no_times_in_plan'];
    }

    $durationWeeks = max(1, (int) ($plan['duration'] ?: 2));
    $repeatDays = $durationWeeks * 7;
    // Use India's calendar date explicitly — never PHP's ambient default
    // timezone (which may be set to something else server-wide, e.g. the
    // hosting box's own locale). Using date('Y-m-d') here would silently
    // date the very first occurrence a day off whenever it's already past
    // midnight in India but not yet midnight in the server's own timezone.
    $startDate = (new DateTime('now', new DateTimeZone(GOOGLE_CALENDAR_TIMEZONE)))->format('Y-m-d');
    $ins = $conn->prepare(
        "INSERT INTO diet_plan_calendar_events (plan_id, member_id, meal_field, google_event_id) VALUES (?, ?, ?, ?)"
    );

    $created = 0;
    foreach ($meals as $field => $meal) {
        $eventId = google_calendar_create_event(
            $access_token,
            $meal['label'] . ' — Diet Plan Reminder',
            $startDate,
            $meal['time'],
            15,   // event length in minutes — just a calendar block, not attended time
            $repeatDays
        );
        if ($eventId) {
            $ins->bind_param('iiss', $plan_id, $member_id, $field, $eventId);
            $ins->execute();
            $created++;
        }
    }

    return ['connected' => true, 'created' => $created, 'total_meals_with_time' => count($meals)];
}
