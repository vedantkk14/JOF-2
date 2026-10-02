<?php
/**
 * auth/member_ai_schema.php
 * ─────────────────────────────────────────────────────────────────
 * Schema for the member-facing diet/fitness chatbot ("FitJo") on
 * user_diet_plans.php — separate from the admin-side AI assistant's
 * ai_conversations/ai_messages tables on purpose: this is the member
 * talking to a bot about themselves, not a trainer managing a plan, and
 * keeping it independent means it can never leak into the admin AI widget's
 * member picker or conversation history.
 *
 * One continuous thread per member (no multi-conversation concept here),
 * so history always just loads in full — it's meant to persist indefinitely,
 * the same way the "Ask Trainer" diet_plan_messages thread already does.
 */

if (!defined('MEMBER_AI_MESSAGE_LIMIT')) {
    // Lifetime cap, not daily — once a member hits this many questions sent,
    // the chat box disables itself. Deliberately generous; this is a cost
    // control against runaway/abusive use, not a feature a normal member
    // should ever bump into.
    define('MEMBER_AI_MESSAGE_LIMIT', 60);
}

if (!function_exists('ensure_member_ai_schema')) {
    function ensure_member_ai_schema(mysqli $conn): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;

        $conn->query("CREATE TABLE IF NOT EXISTS member_ai_messages (
            id INT AUTO_INCREMENT PRIMARY KEY,
            member_id INT NOT NULL,
            role VARCHAR(20) NOT NULL,
            content TEXT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_mam_member (member_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
}

if (!function_exists('member_ai_message_count')) {
    /** How many questions this member has ever sent the bot. */
    function member_ai_message_count(mysqli $conn, int $member_id): int
    {
        ensure_member_ai_schema($conn);
        $stmt = $conn->prepare("SELECT COUNT(*) c FROM member_ai_messages WHERE member_id = ? AND role = 'user'");
        $stmt->bind_param('i', $member_id);
        $stmt->execute();
        return (int) ($stmt->get_result()->fetch_assoc()['c'] ?? 0);
    }
}

if (!function_exists('member_ai_history')) {
    /** Full chat history for this member, oldest first. */
    function member_ai_history(mysqli $conn, int $member_id, int $limit = 200): array
    {
        ensure_member_ai_schema($conn);
        $stmt = $conn->prepare("SELECT role, content, created_at FROM member_ai_messages
                                 WHERE member_id = ? ORDER BY id ASC LIMIT ?");
        $stmt->bind_param('ii', $member_id, $limit);
        $stmt->execute();
        $res = $stmt->get_result();
        $out = [];
        while ($row = $res->fetch_assoc()) {
            $out[] = $row;
        }
        return $out;
    }
}
