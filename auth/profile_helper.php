<?php
/**
 * auth/profile_helper.php
 * ─────────────────────────────────────────────────────────────────
 * Links a portal `user_data` account to a `members` row and reports
 * how complete that member's profile is.
 *
 * A nullable `members.user_id` column is added on first use, so no
 * manual migration is needed. Admin-created members keep user_id = NULL.
 *
 * Used by:
 *   - templates/user_side/my_profile.php      (view + edit)
 *   - templates/user_side/user_dashboard.php  ("complete your profile" nudge)
 */

/** Add members.user_id if it is not there yet. */
function ensure_member_user_link(mysqli $conn): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $res = mysqli_query(
        $conn,
        "SELECT COUNT(*) AS c FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME   = 'members'
           AND COLUMN_NAME  = 'user_id'"
    );
    if ($res && (int) mysqli_fetch_assoc($res)['c'] === 0) {
        mysqli_query($conn, "ALTER TABLE `members` ADD COLUMN `user_id` INT DEFAULT NULL AFTER `id`");
        mysqli_query($conn, "ALTER TABLE `members` ADD KEY `idx_members_user` (`user_id`)");
    }
    $done = true;
}

/** members.id linked to this portal user, or null. */
function get_user_member_id(mysqli $conn, int $user_id): ?int
{
    ensure_member_user_link($conn);
    $stmt = mysqli_prepare($conn, "SELECT id FROM members WHERE user_id = ? ORDER BY id LIMIT 1");
    mysqli_stmt_bind_param($stmt, 'i', $user_id);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    return $row ? (int) $row['id'] : null;
}

/**
 * Everything the profile form needs to render.
 *
 * @return array{member_id:?int, member:?array, metrics:?array, measure:?array}
 */
function get_user_profile(mysqli $conn, int $user_id): array
{
    $mid = get_user_member_id($conn, $user_id);
    $out = ['member_id' => $mid, 'member' => null, 'metrics' => null, 'measure' => null];
    if (!$mid) {
        return $out;
    }

    $s = mysqli_prepare($conn, "SELECT * FROM members WHERE id = ?");
    mysqli_stmt_bind_param($s, 'i', $mid);
    mysqli_stmt_execute($s);
    $out['member'] = mysqli_fetch_assoc(mysqli_stmt_get_result($s)) ?: null;

    $s = mysqli_prepare(
        $conn,
        "SELECT mood, sleep_quality, energy_level, hunger_craving, recorded_at
         FROM metrics WHERE member_id = ? ORDER BY recorded_at DESC, id DESC LIMIT 1"
    );
    mysqli_stmt_bind_param($s, 'i', $mid);
    mysqli_stmt_execute($s);
    $out['metrics'] = mysqli_fetch_assoc(mysqli_stmt_get_result($s)) ?: null;

    $s = mysqli_prepare(
        $conn,
        "SELECT * FROM member_measurements WHERE member_id = ? ORDER BY recorded_at DESC, id DESC LIMIT 1"
    );
    mysqli_stmt_bind_param($s, 'i', $mid);
    mysqli_stmt_execute($s);
    $out['measure'] = mysqli_fetch_assoc(mysqli_stmt_get_result($s)) ?: null;

    return $out;
}

/**
 * How complete is the profile?  Progress photos are NOT required.
 *
 * @return array{complete:bool, missing:string[], percent:int, member_id:?int}
 */
function user_profile_status(mysqli $conn, int $user_id): array
{
    $p = get_user_profile($conn, $user_id);
    $m = $p['member'] ?? [];
    $missing = [];

    $text_fields = [
        'full_name'    => 'Full name',
        'phone_number' => 'Phone number',
        'email'        => 'Email address',
        'gender'       => 'Gender',
        'diet_type'    => 'Diet preference',
    ];
    foreach ($text_fields as $key => $label) {
        if (trim((string) ($m[$key] ?? '')) === '') {
            $missing[] = $label;
        }
    }
    foreach (['age' => 'Age', 'height' => 'Height', 'weight' => 'Weight'] as $key => $label) {
        if ((float) ($m[$key] ?? 0) <= 0) {
            $missing[] = $label;
        }
    }
    if (!$p['metrics']) {
        $missing[] = 'Self-assessment';
    }
    if ((float) ($p['measure']['chest_nipple_line'] ?? 0) <= 0) {
        $missing[] = 'Body measurements';
    }

    $total = 10; // 5 text + 3 numeric + metrics + measurements
    $done  = max(0, $total - count($missing));

    return [
        'complete'  => count($missing) === 0,
        'missing'   => $missing,
        'percent'   => (int) round($done / $total * 100),
        'member_id' => $p['member_id'],
    ];
}
