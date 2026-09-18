<?php
/**
 * auth/ai_schema.php
 * ─────────────────────────────────────────────────────────────────
 * Idempotent schema bootstrap for the AI assistant's own tables.
 * require_once this (after config.php) in any file that touches them —
 * same pattern as auth/diet_plan_schema.php.
 *
 *   ai_conversations  one chat thread; a thread is bound to at most one member
 *   ai_messages       every turn, with token usage for cost/quota tracking
 *   ai_plan_drafts    generated plans awaiting admin review — never diet_plans
 */

if (!function_exists('ai_ensure_schema')) {
    function ai_ensure_schema(mysqli $conn): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;

        $conn->query("CREATE TABLE IF NOT EXISTS ai_conversations (
            id INT NOT NULL AUTO_INCREMENT,
            admin_user_id INT NOT NULL,
            member_id INT DEFAULT NULL,
            title VARCHAR(160) DEFAULT NULL,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_aic_admin (admin_user_id, updated_at),
            KEY idx_aic_member (member_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $conn->query("CREATE TABLE IF NOT EXISTS ai_messages (
            id INT NOT NULL AUTO_INCREMENT,
            conversation_id INT NOT NULL,
            role VARCHAR(20) NOT NULL,
            content MEDIUMTEXT NOT NULL,
            tokens_in INT NOT NULL DEFAULT 0,
            tokens_out INT NOT NULL DEFAULT 0,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_aim_conv (conversation_id, id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $conn->query("CREATE TABLE IF NOT EXISTS ai_plan_drafts (
            id INT NOT NULL AUTO_INCREMENT,
            conversation_id INT NOT NULL,
            member_id INT NOT NULL,
            draft_json MEDIUMTEXT NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'draft',
            saved_plan_id INT DEFAULT NULL,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_aid_conv (conversation_id, id),
            KEY idx_aid_member (member_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }
}
