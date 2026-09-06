<?php
session_start();
require '../config.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: ../index.php");
    exit;
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Get form data
    $member_id = intval($_POST['member_id'] ?? 0);
    $service_type = $_POST['service_type'] ?? '';
    $scheduled_date = $_POST['scheduled_date'] ?? '';
    $scheduled_time = $_POST['scheduled_time'] ?? '';
    $price = floatval($_POST['price'] ?? 0);
    $notes = $_POST['notes'] ?? '';

    // Get member details
    $member_sql = "SELECT full_name, email FROM members WHERE id = ?";
    $member_stmt = $conn->prepare($member_sql);
    $member_stmt->bind_param("i", $member_id);
    $member_stmt->execute();
    $member_result = $member_stmt->get_result();
    $member = $member_result->fetch_assoc();

    if (!$member) {
        die("Member not found");
    }

    $member_name = $member['full_name'];
    $member_email = $member['email'];

    // Insert booking
    $sql = "INSERT INTO addon_services_bookings 
            (member_id, member_name, member_email, service_type, scheduled_date, 
             scheduled_time, price, notes, status) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'scheduled')";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param(
        "isssssds",
        $member_id,
        $member_name,
        $member_email,
        $service_type,
        $scheduled_date,
        $scheduled_time,
        $price,
        $notes
    );

    if ($stmt->execute()) {
        $booking_id = $stmt->insert_id;

        // Send confirmation email
        if (!empty($member_email)) {
            require __DIR__ . '/../auth/send_addon_confirmation.php';

            sendAddonConfirmation(
                $member_name,
                $member_email,
                $service_type,
                $scheduled_date,
                $scheduled_time,
                $price
            );
        }

        // Redirect with success message
        header("Location: ../templates/membership.php?booking=success");
        exit;
    } else {
        die("Error creating booking: " . $stmt->error);
    }

    $stmt->close();
    $conn->close();
}
?>