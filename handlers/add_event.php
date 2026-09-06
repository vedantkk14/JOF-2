<?php
// Disable error printing to avoid breaking JSON
error_reporting(E_ALL);
ini_set('display_errors', 0);
header('Content-Type: application/json');

session_start();
require_once "../config.php";

// Check Auth
if (!isset($_SESSION['user_id'])) {
    echo json_encode(["status" => "error", "message" => "Unauthorized"]);
    exit;
}

// Check DB Connection
if (!$conn) {
    echo json_encode(["status" => "error", "message" => "Database connection failed"]);
    exit;
}

// Get Data
$title = $_POST['title'] ?? '';
$date  = $_POST['date'] ?? '';
$time  = $_POST['time'] ?? '';
$type  = $_POST['type'] ?? 'other';

// Validate
if (empty($title) || empty($date) || empty($time)) {
    echo json_encode(["status" => "error", "message" => "All fields are required"]);
    exit;
}

// FIXED SQL: Used 'event_type' instead of 'type'
$sql = "INSERT INTO events (title, event_date, event_time, event_type) VALUES (?, ?, ?, ?)";
$stmt = mysqli_prepare($conn, $sql);

if ($stmt) {
    mysqli_stmt_bind_param($stmt, "ssss", $title, $date, $time, $type);
    
    if (mysqli_stmt_execute($stmt)) {
        echo json_encode(["status" => "success"]);
    } else {
        // Return actual SQL error for debugging
        echo json_encode(["status" => "error", "message" => "SQL Error: " . mysqli_error($conn)]);
    }
    mysqli_stmt_close($stmt);
} else {
    echo json_encode(["status" => "error", "message" => "Query Preparation Error: " . mysqli_error($conn)]);
}
?>