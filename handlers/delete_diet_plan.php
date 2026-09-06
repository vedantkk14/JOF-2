<?php
session_start();

// Auth Check
if (!isset($_SESSION['user_id'])) {
    header("Location: ../index.php");
    exit;
}

require '../config.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['plan_id'])) {
    $plan_id = intval($_POST['plan_id']);

    // Delete the diet plan
    $stmt = $conn->prepare("DELETE FROM diet_plans WHERE id = ?");
    $stmt->bind_param("i", $plan_id);

    if ($stmt->execute()) {
        $stmt->close();
        header("Location: ../templates/diet-plans.php?deleted=1");
        exit;
    } else {
        $stmt->close();
        header("Location: ../templates/diet-plans.php?error=1");
        exit;
    }
} else {
    header("Location: ../templates/diet-plans.php");
    exit;
}
