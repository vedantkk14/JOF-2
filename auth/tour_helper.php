<?php
/**
 * auth/tour_helper.php
 * ─────────────────────────────────────────────────────────────────
 * Remembers how far a member has got through the first-login walkthrough.
 *
 * Progress lives in one `user_data.tour_step` column, added on first use so no
 * manual migration is needed (same approach as members.user_id in profile_helper.php):
 *
 *    0  →  never started; the tour opens by itself on the dashboard
 *   1..N →  the step to show next (the tour spans several pages, so this has to
 *           survive the jump from one page to the next)
 *   -1  →  finished or skipped; the tour stays out of the way until the member
 *          asks for it again from the sidebar
 *
 * Used by: templates/user_side/_tour.php and handlers/user_tour.php
 */

const TOUR_DONE = -1;

/** Add user_data.tour_step if it is not there yet. */
function ensure_tour_column(mysqli $conn): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $res = mysqli_query(
        $conn,
        "SELECT COUNT(*) AS c FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME   = 'user_data'
           AND COLUMN_NAME  = 'tour_step'"
    );
    if ($res && (int) mysqli_fetch_assoc($res)['c'] === 0) {
        mysqli_query($conn, "ALTER TABLE `user_data` ADD COLUMN `tour_step` INT NOT NULL DEFAULT 0");
        // Everyone who already had an account has found their way around without
        // the tour, so only accounts created from here on start at step 0.
        mysqli_query($conn, "UPDATE `user_data` SET `tour_step` = " . TOUR_DONE);
    }
    $done = true;
}

/** How far this member has got: 0 = not started, N = next step, -1 = done. */
function user_tour_step(mysqli $conn, int $user_id): int
{
    ensure_tour_column($conn);
    $stmt = mysqli_prepare($conn, "SELECT tour_step FROM user_data WHERE id = ? LIMIT 1");
    mysqli_stmt_bind_param($stmt, 'i', $user_id);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    return $row ? (int) $row['tour_step'] : TOUR_DONE;
}

/** Save progress. Anything below -1 is treated as "done". */
function set_user_tour_step(mysqli $conn, int $user_id, int $step): void
{
    ensure_tour_column($conn);
    $step = max(TOUR_DONE, $step);
    $stmt = mysqli_prepare($conn, "UPDATE user_data SET tour_step = ? WHERE id = ?");
    mysqli_stmt_bind_param($stmt, 'ii', $step, $user_id);
    mysqli_stmt_execute($stmt);
}
