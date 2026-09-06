<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}

require '../config.php';

// Fetch recent enquiries (latest 20), along with unread count
// We track "seen" enquiries via a dashboard_enquiry_seen table or a simple last_seen_enquiry_id in session.
// For simplicity, we use a `is_seen` column on member_enquiries. If it doesn't exist, we add it.

// Check if is_seen column exists
$col_check = mysqli_query($conn, "SHOW COLUMNS FROM member_enquiries LIKE 'is_seen'");
if (mysqli_num_rows($col_check) === 0) {
    // Add the column - default all existing to 1 (already seen)
    mysqli_query($conn, "ALTER TABLE member_enquiries ADD COLUMN is_seen TINYINT(1) NOT NULL DEFAULT 0");
    // Mark all existing enquiries as seen so only new ones trigger notifications
    mysqli_query($conn, "UPDATE member_enquiries SET is_seen = 1");
}

// Count unseen enquiries
$unread_count = 0;
$unread_res = mysqli_query($conn, "SELECT COUNT(*) as cnt FROM member_enquiries WHERE is_seen = 0");
if ($unread_res) {
    $unread_count = (int) mysqli_fetch_assoc($unread_res)['cnt'];
}

// Fetch recent enquiries (latest 20)
$enquiries = [];
$sql = "SELECT id, full_name, email, phone_number, is_seen, 
        COALESCE(created_at, NOW()) as created_at 
        FROM member_enquiries 
        ORDER BY id DESC 
        LIMIT 20";
$result = mysqli_query($conn, $sql);
if ($result) {
    while ($row = mysqli_fetch_assoc($result)) {
        // Convert created_at to ISO 8601 with server timezone offset so the
        // browser's new Date() parses it correctly and timeAgo() is accurate.
        if (!empty($row['created_at'])) {
            $dt = new DateTime($row['created_at']);
            $row['created_at'] = $dt->format('c'); // e.g. 2026-04-05T10:30:00+05:30
        }
        $enquiries[] = $row;
    }
}

echo json_encode([
    'status' => 'success',
    'unread_count' => $unread_count,
    'enquiries' => $enquiries
]);
?>