<?php
session_start();

// Auth Check
if (!isset($_SESSION['user_id'])) {
    header("Location: ../index.php");
    exit;
}

require '../config.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['template_id'])) {
    $template_id = intval($_POST['template_id']);

    $stmt = $conn->prepare("DELETE FROM diet_plan_templates WHERE id = ?");
    $stmt->bind_param("i", $template_id);

    if ($stmt->execute()) {
        $stmt->close();
        header("Location: ../templates/diet-plans.php?tab=templates&deleted=1");
        exit;
    } else {
        $stmt->close();
        header("Location: ../templates/diet-plans.php?tab=templates&error=1");
        exit;
    }
} else {
    header("Location: ../templates/diet-plans.php?tab=templates");
    exit;
}
