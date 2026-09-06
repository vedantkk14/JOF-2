<?php
// Migration Script Runner: Add phone_number and lead_source_id to members table
require '../config.php';

echo "<!DOCTYPE html>\n<html>\n<head>\n<link rel=\"icon\" size=\"16x16\" href=\"../icons/favicon-dark-logo.png\" type=\"image/png\"><title>Database Migration</title>\n<style>\nbody { font-family: Arial, sans-serif; padding: 20px; background: #f5f5f5; }\n.success { background: #d4edda; border: 1px solid #c3e6cb; color: #155724; padding: 15px; border-radius: 5px; margin: 10px 0; }\n.error { background: #f8d7da; border: 1px solid #f5c6cb; color: #721c24; padding: 15px; border-radius: 5px; margin: 10px 0; }\n.info { background: #d1ecf1; border: 1px solid #bee5eb; color: #0c5460; padding: 15px; border-radius: 5px; margin: 10px 0; }\n</style>\n</head>\n<body>\n<h1>Lead Conversion Migration</h1>\n";

// Check if phone_number column exists
$check_phone = $conn->query("SHOW COLUMNS FROM members LIKE 'phone_number'");

if ($check_phone->num_rows == 0) {
    // Add phone_number column
    $sql1 = "ALTER TABLE members ADD COLUMN phone_number VARCHAR(15) AFTER email";
    if ($conn->query($sql1)) {
        echo "<div class='success'>✓ Added 'phone_number' column to members table</div>";
    } else {
        echo "<div class='error'>✗ Error adding phone_number: " . $conn->error . "</div>";
    }
} else {
    echo "<div class='info' class='warning'>ℹ 'phone_number' column already exists</div>";
}

// Check if lead_source_id column exists
$check_lead = $conn->query("SHOW COLUMNS FROM members LIKE 'lead_source_id'");

if ($check_lead->num_rows == 0) {
    // Add lead_source_id column
    $sql2 = "ALTER TABLE members ADD COLUMN lead_source_id INT(11) DEFAULT NULL AFTER phone_number";
    if ($conn->query($sql2)) {
        echo "<div class='success'>✓ Added 'lead_source_id' column to members table</div>";
    } else {
        echo "<div class='error'>✗ Error adding lead_source_id: " . $conn->error . "</div>";
    }
} else {
    echo "<div class='info'>ℹ 'lead_source_id' column already exists</div>";
}

echo "\n<h2>Migration Complete!</h2>\n";
echo "<p>The members table now supports lead conversion tracking.</p>\n";
echo "<p><a href='sales_leads.php'>Go to Sales Leads</a> | <a href='add_member.php'>Go to Add Member</a></p>\n";
echo "</body>\n</html>";

$conn->close();
?>