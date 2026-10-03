<?php
/**
 * auth/diet_templates_schema.php
 * ─────────────────────────────────────────────────────────────────
 * Idempotent bootstrap for the diet_plan_templates table used by the
 * reusable diet template pages (create/edit/delete_diet_template.php and
 * the diet-plans.php template list). Same pattern as diet_plan_schema.php:
 * require_once this in any file that touches the table.
 */

if (!function_exists('ensure_diet_templates_schema')) {
    function ensure_diet_templates_schema(mysqli $conn): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;

        $conn->query("CREATE TABLE IF NOT EXISTS diet_plan_templates (
            id INT AUTO_INCREMENT PRIMARY KEY,
            template_name VARCHAR(255) NOT NULL,
            diet_type VARCHAR(20) NOT NULL DEFAULT 'veg',
            goal VARCHAR(255) NOT NULL,
            duration INT NOT NULL DEFAULT 2,
            calories INT NOT NULL DEFAULT 0,
            trainer_name VARCHAR(255) DEFAULT '',
            breakfast TEXT,
            lunch TEXT,
            snack TEXT,
            dinner TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
}
