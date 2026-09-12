<?php
/**
 * auth/diet_plan_schema.php
 * Idempotent schema bootstrap for diet-plan resources, assignments, and chat.
 * require_once this (after config.php) in any file that touches these tables.
 */

if (!function_exists('_diet_plan_schema_ensure')) {
    function _diet_plan_schema_ensure(mysqli $conn): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;

        $col = $conn->query("SHOW COLUMNS FROM diet_plans LIKE 'resources'");
        if ($col && $col->num_rows === 0) {
            $conn->query("ALTER TABLE diet_plans ADD COLUMN resources TEXT NULL");
        }

        $conn->query("CREATE TABLE IF NOT EXISTS diet_plan_assignments (
            id INT AUTO_INCREMENT PRIMARY KEY,
            plan_id INT NOT NULL,
            member_id INT NOT NULL,
            assigned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_plan_member (plan_id, member_id)
        )");

        $conn->query("CREATE TABLE IF NOT EXISTS diet_plan_messages (
            id INT AUTO_INCREMENT PRIMARY KEY,
            plan_id INT NOT NULL,
            member_id INT NOT NULL,
            sender_role VARCHAR(20) NOT NULL,
            message TEXT NOT NULL,
            is_read TINYINT(1) NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_plan_member (plan_id, member_id)
        )");
    }
}

_diet_plan_schema_ensure($conn);

if (!function_exists('diet_plan_meal_summary')) {
    /**
     * Scans a diet_plans row's breakfast/lunch/snack/dinner text blobs for their
     * "**LABEL (optional time):**" headers and returns up to 4 {label, time} pairs
     * (skipping GUIDELINES, which isn't a meal). A time is only included if the
     * admin actually typed one in parentheses — never fabricated.
     */
    function diet_plan_meal_summary(array $plan, int $limit = 4): array
    {
        $blob = implode("\n", [
            $plan['breakfast'] ?? '',
            $plan['lunch'] ?? '',
            $plan['snack'] ?? '',
            $plan['dinner'] ?? '',
        ]);

        $summary = [];
        if (preg_match_all('/\*\*([A-Za-z][A-Za-z \-]*?)\s*(?:\(([^)]*)\))?\s*:?\s*\*\*/u', $blob, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $label = trim($m[1]);
                if (strcasecmp($label, 'GUIDELINES') === 0) {
                    continue;
                }
                $summary[] = [
                    'label' => ucwords(strtolower($label)),
                    'time'  => isset($m[2]) ? trim($m[2]) : '',
                ];
                if (count($summary) >= $limit) {
                    break;
                }
            }
        }
        return $summary;
    }
}
