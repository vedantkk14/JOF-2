<?php
// handlers/fix_pt_status.php
require '../config.php';

echo "<h2>Fixing PT Sessions Table...</h2>";

// 1. Check current structure (optional, but good for debugging)
$checkSql = "DESCRIBE pt_sessions";
$result = mysqli_query($conn, $checkSql);
echo "<pre>Current Structure:\n";
while ($row = mysqli_fetch_assoc($result)) {
    print_r($row);
}
echo "</pre>";

// 2. Modify the column
$sql = "ALTER TABLE pt_sessions MODIFY COLUMN status VARCHAR(50) DEFAULT 'Pending'";

if (mysqli_query($conn, $sql)) {
    echo "<h3 style='color:green'>Success: 'status' column updated to VARCHAR(50).</h3>";
} else {
    echo "<h3 style='color:red'>Error: " . mysqli_error($conn) . "</h3>";
}

// 3. Verify Change
$result = mysqli_query($conn, $checkSql);
echo "<pre>New Structure:\n";
while ($row = mysqli_fetch_assoc($result)) {
    print_r($row);
}
echo "</pre>";
?>