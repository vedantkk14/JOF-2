<?php
$servername = "localhost";
$username = "root";
$password = "vrishabh#2807";
$dbname = "fitness_crm";

$conn = mysqli_connect($servername, $username, $password, $dbname);

if (!$conn) {
    die("Connection failed: " . mysqli_connect_error());
}
?>