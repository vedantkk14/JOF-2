<?php
// templates/fix_database.php
require '../config.php';

echo "<h2>🔧 Database Repair Tool</h2>";

// 1. Check if 'reschedule_type' column exists in 'pt_sessions'
$check_col = $conn->query("SHOW COLUMNS FROM pt_sessions LIKE 'reschedule_type'");

if ($check_col->num_rows == 0) {
    // Column missing -> Add it
    $sql = "ALTER TABLE pt_sessions ADD COLUMN reschedule_type ENUM('none', 'member', 'trainer') DEFAULT 'none'";
    
    if ($conn->query($sql)) {
        echo "<p style='color:green'>✅ <b>Success:</b> Added 'reschedule_type' column to pt_sessions table.</p>";
    } else {
        echo "<p style='color:red'>❌ <b>Error:</b> Could not add column. " . $conn->error . "</p>";
    }
} else {
    echo "<p style='color:blue'>ℹ️ <b>Info:</b> The column 'reschedule_type' already exists. You are good to go!</p>";
}

echo "<hr><a href='pt_session.php'>Go back to PT Sessions</a>";
?>