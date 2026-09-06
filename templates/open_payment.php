<?php
session_start();
require '../config.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: ../index.php");
    exit;
}

$member_id = intval($_GET['member_id'] ?? 0);
if ($member_id <= 0) {
    header("Location: members.php");
    exit;
}

// Verify member exists
$chk = $conn->prepare("SELECT id FROM members WHERE id = ? LIMIT 1");
$chk->bind_param("i", $member_id);
$chk->execute();
$chk->store_result();
if ($chk->num_rows === 0) {
    $chk->close();
    header("Location: members.php");
    exit;
}
$chk->close();

// Set the session so payment_details.php can pick it up
$_SESSION['new_member_id'] = $member_id;
$_SESSION['activate_member_id'] = $member_id;

header("Location: payment_details.php");
exit;
