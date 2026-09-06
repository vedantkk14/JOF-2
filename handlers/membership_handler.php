<?php
// handlers/membership_handler.php
session_start();
require '../config.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: ../index.php");
    exit;
}

// --- HANDLE POST REQUESTS (Create & Update) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    $action = $_POST['action'] ?? 'create'; // Default to create if not specified

    // Common Inputs
    $plan_name = mysqli_real_escape_string($conn, $_POST['plan_name']);
    $description = mysqli_real_escape_string($conn, $_POST['description']);
    $price = floatval($_POST['price']);
    $duration_value = intval($_POST['duration_value']);
    $duration_unit = mysqli_real_escape_string($conn, $_POST['duration_unit']);
    $max_classes = mysqli_real_escape_string($conn, $_POST['max_classes']);
    $features = mysqli_real_escape_string($conn, $_POST['features']);

    if (empty($max_classes)) $max_classes = "Unlimited";

    // 1. UPDATE EXISTING PLAN
    if ($action === 'update') {
        $id = intval($_POST['id']);
        
        $sql = "UPDATE membership_plans SET 
                plan_name='$plan_name', 
                description='$description', 
                price=$price, 
                duration_value=$duration_value, 
                duration_unit='$duration_unit', 
                max_classes='$max_classes', 
                features='$features' 
                WHERE id=$id";

        if (mysqli_query($conn, $sql)) {
            header("Location: ../templates/membership.php?success=updated");
        } else {
            die("Error updating: " . mysqli_error($conn));
        }
    } 
    
    // 2. CREATE NEW PLAN
    else {
        $sql = "INSERT INTO membership_plans 
                (plan_name, description, price, duration_value, duration_unit, max_classes, features) 
                VALUES 
                ('$plan_name', '$description', $price, $duration_value, '$duration_unit', '$max_classes', '$features')";

        if (mysqli_query($conn, $sql)) {
            header("Location: ../templates/membership.php?success=created");
        } else {
            die("Error creating: " . mysqli_error($conn));
        }
    }
    exit;
}

// --- HANDLE DELETE REQUEST ---
if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['id'])) {
    $id = intval($_GET['id']);
    mysqli_query($conn, "DELETE FROM membership_plans WHERE id = $id");
    header("Location: ../templates/membership.php?success=deleted");
    exit;
}
?>