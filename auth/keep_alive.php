<?php
session_start();
if (isset($_SESSION['user_id'])) {
    echo json_encode(["status" => "alive"]);
} else {
    echo json_encode(["status" => "dead"]);
}
