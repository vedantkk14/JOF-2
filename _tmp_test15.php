<?php
require __DIR__ . '/config.php';
$member_id = (int) trim(file_get_contents(__DIR__ . '/_tmp_member_id.txt'));

$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['SCRIPT_FILENAME'] = realpath('templates/user_side/user_dashboard.php');
session_start();
$_SESSION['user_id'] = 4;
$_SESSION['user_name'] = 'Test User';
$_SESSION['user_role'] = 'user';
$_SESSION['_last_activity'] = time();
chdir('templates/user_side');
ob_start();
include 'user_dashboard.php';
$dashOut = ob_get_clean();
chdir(__DIR__);

echo "=== DASHBOARD ===\n";
echo "rendered: " . strlen($dashOut) . " bytes\n";
echo (strpos($dashOut, 'Gold Monthly') !== false ? "PASS" : "FAIL") . ": shows real active plan 'Gold Monthly'\n";
echo (strpos($dashOut, '+ New Plan Pending') !== false ? "PASS" : "FAIL") . ": shows '+ New Plan Pending' tag\n";
if (preg_match('/Days remaining<\/span>\s*<b>(\d+) days<\/b>/', $dashOut, $mm)) {
    echo "Days remaining shown: {$mm[1]} (expect ~20)\n";
} else {
    echo "Days remaining not found in output\n";
}
file_put_contents(__DIR__ . '/_tmp_dash_out.html', $dashOut);
