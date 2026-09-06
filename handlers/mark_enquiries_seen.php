<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}

require '../config.php';

// Mark all enquiries as seen
$result = mysqli_query($conn, "UPDATE member_enquiries SET is_seen = 1 WHERE is_seen = 0");

if ($result) {
    echo json_encode(['status' => 'success', 'message' => 'All enquiries marked as seen']);
} else {
    echo json_encode(['status' => 'error', 'message' => mysqli_error($conn)]);
}
?>
