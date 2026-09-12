<?php
/**
 * auth/profile_helper.php
 * Shared helpers for the member "My Profile" page.
 */

if (!function_exists('get_user_member_id')) {
    function get_user_member_id(mysqli $conn, int $uid): ?int
    {
        $s = mysqli_prepare($conn, "SELECT id FROM members WHERE user_id = ? ORDER BY id DESC LIMIT 1");
        mysqli_stmt_bind_param($s, 'i', $uid);
        mysqli_stmt_execute($s);
        $row = mysqli_fetch_assoc(mysqli_stmt_get_result($s));
        return $row ? (int) $row['id'] : null;
    }
}

if (!function_exists('get_user_profile')) {
    /**
     * @return array{member: array, metrics: array, measure: array}
     */
    function get_user_profile(mysqli $conn, int $uid): array
    {
        $member = [];
        $metrics = [];
        $measure = [];

        $mid = get_user_member_id($conn, $uid);
        if ($mid) {
            $s = mysqli_prepare($conn, "SELECT * FROM members WHERE id = ?");
            mysqli_stmt_bind_param($s, 'i', $mid);
            mysqli_stmt_execute($s);
            $member = mysqli_fetch_assoc(mysqli_stmt_get_result($s)) ?: [];

            $s = mysqli_prepare($conn, "SELECT * FROM metrics WHERE member_id = ? ORDER BY recorded_at DESC, id DESC LIMIT 1");
            mysqli_stmt_bind_param($s, 'i', $mid);
            mysqli_stmt_execute($s);
            $metrics = mysqli_fetch_assoc(mysqli_stmt_get_result($s)) ?: [];

            $s = mysqli_prepare($conn, "SELECT * FROM member_measurements WHERE member_id = ? ORDER BY recorded_at DESC, id DESC LIMIT 1");
            mysqli_stmt_bind_param($s, 'i', $mid);
            mysqli_stmt_execute($s);
            $measure = mysqli_fetch_assoc(mysqli_stmt_get_result($s)) ?: [];
        }

        return ['member' => $member, 'metrics' => $metrics, 'measure' => $measure];
    }
}

if (!function_exists('user_profile_status')) {
    /**
     * @return array{missing: string[], percent: int, complete: bool}
     */
    function user_profile_status(mysqli $conn, int $uid): array
    {
        $profile = get_user_profile($conn, $uid);
        $m  = $profile['member'];
        $mt = $profile['metrics'];
        $ms = $profile['measure'];

        $checks = [
            'Full name'         => trim($m['full_name'] ?? '') !== '',
            'Phone number'      => trim($m['phone_number'] ?? '') !== '',
            'Email address'     => trim($m['email'] ?? '') !== '',
            'Gender'            => trim($m['gender'] ?? '') !== '',
            'Diet preference'   => trim($m['diet_type'] ?? '') !== '',
            'Age'               => (int) ($m['age'] ?? 0) > 0,
            'Height'            => (float) ($m['height'] ?? 0) > 0,
            'Weight'            => (float) ($m['weight'] ?? 0) > 0,
            'Self-assessment'   => !empty($mt),
            'Body measurements' => !empty($ms)
                && (float) ($ms['chest_nipple_line'] ?? 0) > 0
                && (float) ($ms['waist_navel_line'] ?? 0) > 0
                && (float) ($ms['hip_widest_part'] ?? 0) > 0
                && (float) ($ms['thigh_mid'] ?? 0) > 0,
        ];

        $missing = [];
        foreach ($checks as $label => $ok) {
            if (!$ok) {
                $missing[] = $label;
            }
        }

        $total   = count($checks);
        $done    = $total - count($missing);
        $percent = $total > 0 ? (int) round($done / $total * 100) : 0;

        return [
            'missing'  => $missing,
            'percent'  => $percent,
            'complete' => empty($missing),
        ];
    }
}
