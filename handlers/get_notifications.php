<?php
/**
 * get_notifications.php
 * Returns all notifications (newest first) and the unread count.
 * Auto-creates / upgrades the notifications table if needed.
 */

session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}
session_write_close();

require '../config.php';

// Auto-create base table
$conn->query("CREATE TABLE IF NOT EXISTS `notifications` (
  `id`           INT AUTO_INCREMENT PRIMARY KEY,
  `type`         VARCHAR(50)  NOT NULL DEFAULT 'email_reply',
  `title`        VARCHAR(255) NOT NULL,
  `message`      TEXT,
  `member_name`  VARCHAR(150),
  `member_email` VARCHAR(150),
  `is_read`      TINYINT(1)   DEFAULT 0,
  `created_at`   TIMESTAMP    DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

// Check if body_full column exists (MySQL 8.0 compatible — no IF NOT EXISTS in ALTER)
$colCheck = $conn->query(
    "SELECT COUNT(*) as c FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME   = 'notifications'
       AND COLUMN_NAME  = 'body_full'"
);
if ($colCheck) {
    $hasBodyFull = (int)$colCheck->fetch_assoc()['c'] > 0;
    if (!$hasBodyFull) {
        $conn->query("ALTER TABLE `notifications` ADD COLUMN `body_full` TEXT AFTER `message`");
        $hasBodyFull = ($conn->errno === 0);
    }
}
else {
    $hasBodyFull = false;
}

// Build SELECT — only include body_full if column exists
$bodyFullSelect = $hasBodyFull ? ", body_full" : ", NULL AS body_full";

$result = $conn->query(
    "SELECT id, type, title, message{$bodyFullSelect}, member_name, member_email, is_read, created_at
     FROM notifications
     ORDER BY created_at DESC
     LIMIT 20"
);

$notifications = [];
if ($result) {
    while ($row = $result->fetch_assoc()) {
        // Convert created_at to ISO 8601 with server timezone offset so the
        // browser's new Date() parses it correctly and timeAgo() is accurate.
        if (!empty($row['created_at'])) {
            $dt = new DateTime($row['created_at']);
            $row['created_at'] = $dt->format('c'); // e.g. 2026-03-27T10:30:00+05:30
        }
        $notifications[] = $row;
    }
}

// Unread count
$unreadResult = $conn->query("SELECT COUNT(*) as cnt FROM notifications WHERE is_read = 0");
$unread = $unreadResult ? (int)$unreadResult->fetch_assoc()['cnt'] : 0;

echo json_encode([
    'status' => 'success',
    'notifications' => $notifications,
    'unread_count' => $unread,
]);
