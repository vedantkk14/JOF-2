<?php
require __DIR__ . '/config.php';

$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['SCRIPT_FILENAME'] = realpath('templates/user_side/user_membership.php');
session_start();
$_SESSION['user_id'] = 4;
$_SESSION['user_name'] = 'Test User';
$_SESSION['user_role'] = 'user';
$_SESSION['_last_activity'] = time();
chdir('templates/user_side');
ob_start();
include 'user_membership.php';
$out = ob_get_clean();
chdir(__DIR__);

echo "rendered: " . strlen($out) . " bytes\n";
echo (strpos($out, 'Gold Monthly') !== false ? "PASS" : "FAIL") . ": shows real active plan 'Gold Monthly'\n";
echo (strpos($out, '● Active membership') !== false ? "PASS" : "FAIL") . ": hero tag is Active membership\n";
echo (strpos($out, '+ New Plan Pending') !== false ? "PASS" : "FAIL") . ": shows '+ New Plan Pending' tag\n";
