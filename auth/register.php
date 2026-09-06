<?php
require '../config.php';

header('Content-Type: application/json');

$full_name = $_POST['full_name'] ?? '';
$email     = $_POST['email'] ?? '';
$password  = $_POST['password'] ?? '';

if ($full_name === '' || $email === '' || $password === '') {
    echo json_encode([
        "success" => false,
        "message" => "All fields are required"
    ]);
    exit;
}

// Check if email exists
$check_sql = "SELECT id FROM user_data WHERE email = ?";
$check_stmt = mysqli_prepare($conn, $check_sql);
mysqli_stmt_bind_param($check_stmt, "s", $email);
mysqli_stmt_execute($check_stmt);
$check_result = mysqli_stmt_get_result($check_stmt);

if (mysqli_fetch_assoc($check_result)) {
    echo json_encode([
        "success" => false,
        "message" => "Email already registered"
    ]);
    exit;
}

// Hash password
$hashed_password = password_hash($password, PASSWORD_DEFAULT);

// Insert user
$insert_sql = "INSERT INTO user_data (full_name, email, password)
                VALUES (?, ?, ?)";

$insert_stmt = mysqli_prepare($conn, $insert_sql);
mysqli_stmt_bind_param($insert_stmt, "sss", $full_name, $email, $hashed_password);

if (mysqli_stmt_execute($insert_stmt)) {
    echo json_encode([
        "success" => true
    ]);
    exit;
}

echo json_encode([
    "success" => false,
    "message" => "Registration failed"
]);
exit;
