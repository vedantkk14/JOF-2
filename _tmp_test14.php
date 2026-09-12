<?php
require __DIR__ . '/config.php';

$m = $conn->query("SELECT id, user_id FROM members WHERE user_id = 4 LIMIT 1")->fetch_assoc();
$member_id = (int) $m['id'];
file_put_contents(__DIR__ . '/_tmp_member_id.txt', $member_id);

$conn->query("DELETE FROM member_payments WHERE member_id=$member_id");

// Real, currently active plan: 20 days left
$stmt = $conn->prepare("INSERT INTO member_payments (member_id, membership_type, duration_months, start_date, end_date, total_amount, discount, installments_count, amount_received, payment_mode, transaction_id, payer_name, balance_pending, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())");
$mtype = "Gold Monthly"; $dur = 1; $start = date('Y-m-d', strtotime('-10 days')); $end = date('Y-m-d', strtotime('+20 days'));
$total = 3000.0; $disc = 0.0; $inst = 1; $recv = 3000.0; $pm = "cash"; $tid = "OLDPLAN1"; $payer = "Real Payer"; $bal = 0.0;
$stmt->bind_param('isissddidsssd', $member_id, $mtype, $dur, $start, $end, $total, $disc, $inst, $recv, $pm, $tid, $payer, $bal);
$stmt->execute();

sleep(1); // ensure created_at differs

// New subscribe request (submitted via handler, still unverified)
$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST['plan_id'] = 6; // "Lifestyle Consultation(12 WEEKS)"
$_POST['transaction_id'] = 'NEWREQ1';
$_POST['payer_name'] = '';
$_POST['installments'] = 1;
session_start();
$_SESSION['user_id'] = 4;
$_SESSION['user_name'] = 'Test User';
$_SESSION['user_role'] = 'user';
$_SESSION['_last_activity'] = time();
ob_start();
include __DIR__ . '/handlers/subscribe_payment.php';
$handlerOut = ob_get_clean();
echo "Handler: $handlerOut\n";
