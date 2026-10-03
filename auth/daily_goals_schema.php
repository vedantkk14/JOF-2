<?php
/**
 * auth/daily_goals_schema.php
 * ─────────────────────────────────────────────────────────────────
 * Member to-do list ("Today's goals") on the user dashboard. Each row is one
 * goal the member set for a given day; is_achieved is the done/not-done flag.
 * Keyed by the portal user id (same as workout_log), so goals show up
 * regardless of which members row the user is linked to.
 */

if (!function_exists('ensure_daily_goals_schema')) {
    function ensure_daily_goals_schema(mysqli $conn): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;

        $conn->query("CREATE TABLE IF NOT EXISTS daily_goals (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            goal_date DATE NOT NULL,
            title VARCHAR(255) NOT NULL,
            is_achieved TINYINT(1) NOT NULL DEFAULT 0,
            achieved_at DATETIME NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_dg_user_date (user_id, goal_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
}

if (!function_exists('daily_goals_stats')) {
    /** Lifetime totals for the welcome banner: goals set and goals achieved. */
    function daily_goals_stats(mysqli $conn, int $user_id): array
    {
        ensure_daily_goals_schema($conn);
        $stmt = $conn->prepare("SELECT COUNT(*) AS total, COALESCE(SUM(is_achieved), 0) AS achieved
                                FROM daily_goals WHERE user_id = ?");
        $stmt->bind_param('i', $user_id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        return [
            'total'    => (int) ($row['total'] ?? 0),
            'achieved' => (int) ($row['achieved'] ?? 0),
        ];
    }
}

if (!function_exists('daily_goals_for_date')) {
    /** Goals for one day, oldest first (the order they were added). */
    function daily_goals_for_date(mysqli $conn, int $user_id, string $date): array
    {
        ensure_daily_goals_schema($conn);
        $stmt = $conn->prepare("SELECT id, title, is_achieved FROM daily_goals
                                WHERE user_id = ? AND goal_date = ? ORDER BY id ASC");
        $stmt->bind_param('is', $user_id, $date);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }
}

if (!function_exists('daily_goals_history')) {
    /** Every day that has goals, newest day first, with its goals grouped in. */
    function daily_goals_history(mysqli $conn, int $user_id, int $limit_days = 60): array
    {
        ensure_daily_goals_schema($conn);
        $stmt = $conn->prepare("SELECT id, goal_date, title, is_achieved FROM daily_goals
                                WHERE user_id = ?
                                ORDER BY goal_date DESC, id ASC");
        $stmt->bind_param('i', $user_id);
        $stmt->execute();

        $days = [];
        foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
            $d = $row['goal_date'];
            if (!isset($days[$d])) {
                if (count($days) >= $limit_days) {
                    break;
                }
                $days[$d] = ['date' => $d, 'goals' => []];
            }
            $days[$d]['goals'][] = [
                'id'       => (int) $row['id'],
                'title'    => $row['title'],
                'achieved' => (bool) $row['is_achieved'],
            ];
        }
        return array_values($days);
    }
}
