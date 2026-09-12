<?php
// Disable error printing to screen so it doesn't break JSON
error_reporting(0);
ini_set('display_errors', 0);

$servername = "localhost";
$username = "root";
// Check if your local DB uses a password or is empty. usually it's "" for XAMPP
$password = ""; // Update this if you are SURE you set a password
$dbname = "fitness_crm";
$port = 3307;

$conn = mysqli_connect($servername, $username, $password, $dbname, $port);

// If connection fails, return a JSON error that your frontend can read
if (!$conn) {
    header('Content-Type: application/json');
    echo json_encode([
        "success" => false,
        "message" => "Database Connection Failed: " . mysqli_connect_error()
    ]);
    exit;
}
?>