<?php
/**
 * auth/workout_helper.php
 * ─────────────────────────────────────────────────────────────────
 * Workout-streak tracking for the member portal (user_dashboard.php).
 *
 * One row per user per day in `workout_logs`. The table is created on
 * first use, so no manual migration is needed.
 *
 * Shared by:
 *   - templates/user_side/user_dashboard.php  (initial render)
 *   - handlers/workout_log.php                (AJAX toggle + refresh)
 */

/** Create the workout_logs table once per request if it does not exist. */
function ensure_workout_table(mysqli $conn): void
{
    static $done = false;
    if ($done) {
        return;
    }
    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS `workout_logs` (
        `id`           INT AUTO_INCREMENT PRIMARY KEY,
        `user_id`      INT  NOT NULL,
        `workout_date` DATE NOT NULL,
        `note`         VARCHAR(255) DEFAULT NULL,
        `created_at`   TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY `uniq_user_date` (`user_id`, `workout_date`),
        KEY `idx_workout_user` (`user_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $done = true;
}

/**
 * Toggle a single day for a user.
 * @return bool  true  = day is now logged (was added)
 *               false = day was removed (already existed)
 */
function workout_toggle_day(mysqli $conn, int $user_id, string $date): bool
{
    ensure_workout_table($conn);

    $del = mysqli_prepare($conn, "DELETE FROM workout_logs WHERE user_id = ? AND workout_date = ?");
    mysqli_stmt_bind_param($del, 'is', $user_id, $date);
    mysqli_stmt_execute($del);
    if (mysqli_stmt_affected_rows($del) > 0) {
        return false;
    }

    $ins = mysqli_prepare($conn, "INSERT INTO workout_logs (user_id, workout_date) VALUES (?, ?)");
    mysqli_stmt_bind_param($ins, 'is', $user_id, $date);
    @mysqli_stmt_execute($ins); // ignore duplicate-key race
    return true;
}

/**
 * Compute every streak figure the dashboard card needs.
 *
 * @return array{
 *   current_streak:int, longest_streak:int, longest_gap:int,
 *   days_since_last:?int, this_week:int, total_workouts:int,
 *   logged_today:bool, strip:array<int,array{date:string,label:string,day:string,done:bool,today:bool}>
 * }
 */
function workout_streak_stats(mysqli $conn, int $user_id): array
{
    ensure_workout_table($conn);

    $dates = [];
    $stmt = mysqli_prepare($conn, "SELECT workout_date FROM workout_logs WHERE user_id = ? ORDER BY workout_date ASC");
    mysqli_stmt_bind_param($stmt, 'i', $user_id);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    while ($row = mysqli_fetch_assoc($res)) {
        $dates[] = $row['workout_date'];
    }

    $today   = new DateTimeImmutable('today');
    $todayStr = $today->format('Y-m-d');
    $set     = array_flip($dates);
    $total   = count($dates);

    // ── Current streak: walk back from today (or yesterday if not logged today) ──
    $current = 0;
    if ($total) {
        $cursor = isset($set[$todayStr]) ? $today : $today->modify('-1 day');
        while (isset($set[$cursor->format('Y-m-d')])) {
            $current++;
            $cursor = $cursor->modify('-1 day');
        }
    }

    // ── Longest streak + longest gap between two workouts ──
    $longest = 0;
    $run     = 0;
    $longestGap = 0;
    $prev = null;
    foreach ($dates as $d) {
        $dt = new DateTimeImmutable($d);
        if ($prev === null) {
            $run = 1;
        } else {
            $diff = (int) $prev->diff($dt)->days;
            if ($diff === 1) {
                $run++;
            } else {
                $run = 1;
                if ($diff - 1 > $longestGap) {
                    $longestGap = $diff - 1;
                }
            }
        }
        if ($run > $longest) {
            $longest = $run;
        }
        $prev = $dt;
    }

    // ── Days since the last workout ──
    $daysSince = null;
    if ($total) {
        $last = new DateTimeImmutable($dates[$total - 1]);
        $daysSince = (int) $last->diff($today)->days;
    }

    // ── Workouts in the last 7 days (incl. today) ──
    $weekStart = $today->modify('-6 days')->format('Y-m-d');
    $thisWeek  = 0;
    foreach ($dates as $d) {
        if ($d >= $weekStart && $d <= $todayStr) {
            $thisWeek++;
        }
    }

    // ── 21-day strip for the tappable calendar ──
    $strip = [];
    for ($i = 20; $i >= 0; $i--) {
        $day = $today->modify("-{$i} days");
        $key = $day->format('Y-m-d');
        $strip[] = [
            'date'  => $key,
            'label' => $day->format('D'),
            'day'   => $day->format('j'),
            'done'  => isset($set[$key]),
            'today' => $i === 0,
        ];
    }

    return [
        'current_streak'  => $current,
        'longest_streak'  => $longest,
        'longest_gap'     => $longestGap,
        'days_since_last' => $daysSince,
        'this_week'       => $thisWeek,
        'total_workouts'  => $total,
        'logged_today'    => isset($set[$todayStr]),
        'strip'           => $strip,
    ];
}
