<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);
header('Content-Type: application/json');

session_start();
require_once "../config.php";

if (!isset($_SESSION['user_id'])) {
    echo json_encode([]);
    exit;
}

if (!$conn) {
    echo json_encode([]);
    exit;
}

$query = "SELECT * FROM events ORDER BY event_date ASC, event_time ASC";
$result = mysqli_query($conn, $query);

$events = [];
if ($result) {
    while ($row = mysqli_fetch_assoc($result)) {
        $events[] = [
            "id"    => $row["id"], // ADDED THIS LINE
            "title" => $row["title"],
            "date"  => $row["event_date"],
            "time"  => substr($row["event_time"], 0, 5),
            "type"  => $row["event_type"] ?? 'other'
        ];
    }
}

echo json_encode($events);
?>