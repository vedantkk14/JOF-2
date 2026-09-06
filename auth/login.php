<?php
session_start();
require '../config.php';

header('Content-Type: application/json');

// If already logged in, just tell the JavaScript it's a success
if (isset($_SESSION['user_id'])) {
    echo json_encode(["success" => true]);
    exit;

}

$email = $_POST['email'] ?? '';
$password = $_POST['password'] ?? '';

if ($email === '' || $password === '') {
    echo json_encode([
        "success" => false,
        "message" => "Email and password required"
    ]);
    exit;
}

$sql = "SELECT id, full_name, password FROM user_data WHERE email = ?";
$stmt = mysqli_prepare($conn, $sql);
mysqli_stmt_bind_param($stmt, "s", $email);
mysqli_stmt_execute($stmt);

$result = mysqli_stmt_get_result($stmt);
$user = mysqli_fetch_assoc($result);

if ($user && password_verify($password, $user['password'])) {

    $_SESSION['user_id'] = $user['id'];
    $_SESSION['full_name'] = $user['full_name'];

    echo json_encode([
        "success" => true
    ]);
    exit;

}
else {
    echo json_encode([
        "success" => false,
        "message" => "Invalid email or password"
    ]);
    exit;
}