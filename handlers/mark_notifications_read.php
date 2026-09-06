<?php
/**
 * mark_notifications_read.php
 * Marks all notifications as read (called when user opens the bell panel).
 */

session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}
session_write_close();

require '../config.php';

$conn->query("UPDATE notifications SET is_read = 1 WHERE is_read = 0");

echo json_encode(['status' => 'success']);
