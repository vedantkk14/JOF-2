<?php
/**
 * auth/calendar_schema.php
 * Idempotent schema bootstrap for Google Calendar auto-reminders.
 * require_once this (after config.php) in any file that touches these tables.
 */

if (!function_exists('_calendar_schema_ensure')) {
    function _calendar_schema_ensure(mysqli $conn): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;

        // One row per user_data account that has connected their Google Calendar.
        // access_token is short-lived (~1hr) and refreshed on demand using
        // refresh_token, which Google only issues on first consent.
        $conn->query("CREATE TABLE IF NOT EXISTS user_calendar_tokens (
            user_id INT NOT NULL PRIMARY KEY,
            access_token TEXT NOT NULL,
            refresh_token TEXT NOT NULL,
            token_expires_at DATETIME NOT NULL,
            scope VARCHAR(255) NOT NULL DEFAULT '',
            connected_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )");

        // Tracks every Google Calendar event we've created for a member's diet
        // plan, so a later phase/re-assignment can delete the old ones before
        // creating new ones (no stale/duplicate reminders left behind).
        $conn->query("CREATE TABLE IF NOT EXISTS diet_plan_calendar_events (
            id INT AUTO_INCREMENT PRIMARY KEY,
            plan_id INT NOT NULL,
            member_id INT NOT NULL,
            meal_field VARCHAR(30) NOT NULL,
            google_event_id VARCHAR(255) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_member (member_id),
            INDEX idx_plan_member (plan_id, member_id)
        )");
    }
}

_calendar_schema_ensure($conn);
