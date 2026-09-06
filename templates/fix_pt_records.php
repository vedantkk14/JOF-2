<?php
// templates/fix_pt_records.php
require '../config.php';

echo "<h2> 🛠️ Fixing Missing PT Records...</h2>";

// 1. Find members who opted for PT but are missing from personal_training table
$sql = "SELECT m.id, m.full_name, m.created_at 
        FROM members m 
        LEFT JOIN personal_training pt ON m.id = pt.member_id
        WHERE m.personal_training = 1 AND pt.id IS NULL";

$result = $conn->query($sql);
$fixed_count = 0;

if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $member_id = $row['id'];
        $name = $conn->real_escape_string($row['full_name']); 
        
        // Defaults
        $start_date = date('Y-m-d');
        $end_date = date('Y-m-d', strtotime('+30 days'));
        $sessions = 12;
        
        // CRITICAL FIX: Use the member's OWN ID as a placeholder for trainer_id
        // This satisfies the Foreign Key constraint because we know this ID exists in the members table.
        $trainer_id = $member_id; 
        
        $pt_fees = 0.00; 

        // 2. Insert Missing Record
        $insert_sql = "INSERT INTO personal_training 
                       (member_id, full_name, trainer_id, total_sessions, sessions_used, start_date, end_date, status, pt_fees) 
                       VALUES 
                       ('$member_id', '$name', '$trainer_id', '$sessions', 0, '$start_date', '$end_date', 'active', '$pt_fees')";

        if ($conn->query($insert_sql)) {
            echo "<p style='color:green'>✅ Fixed: <b>$name</b> (Added default PT pack)</p>";
            $fixed_count++;
        } else {
            echo "<p style='color:red'>❌ Failed to fix $name: " . $conn->error . "</p>";
        }
    }
} else {
    echo "<p>👍 No missing records found.</p>";
}

echo "<hr><h3>Done! Fixed $fixed_count members.</h3>";
echo "<a href='pt_session.php'>Go to PT Sessions Page</a>";
?>