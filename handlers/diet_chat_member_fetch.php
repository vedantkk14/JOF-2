<?php
/**
 * handlers/diet_chat_member_fetch.php
 * ─────────────────────────────────────────────────────────────────
 * Admin/trainer side of diet messages: ONE conversation per member, holding
 * their messages from every plan, oldest first. Each message carries the phase
 * it was written under so the page can mark where a new phase begins.
 *
 * (The member's own page still reads one plan at a time via diet_chat_fetch.php.)
 *
 *   GET ?member_id=N
 *   → { success, member:{id,name}, messages:[{id,plan_id,phase,sender_role,message,created_at}],
 *       reply_plan_id, reply_phase }
 *
 * reply_plan_id is the plan a reply should be posted under: the one the latest
 * message was about, or failing that (plan since deleted) the member's newest plan.
 */
require_once __DIR__ . '/../auth/auth_check.php';
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../auth/diet_plan_schema.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['user_role'] ?? '', ['admin', 'trainer'], true)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$member_id = (int) ($_GET['member_id'] ?? 0);
if ($member_id <= 0) {
    echo json_encode(['success' => false, 'error' => 'Invalid member']);
    exit;
}

$mq = $conn->prepare("SELECT id, full_name FROM members WHERE id = ? LIMIT 1");
$mq->bind_param('i', $member_id);
$mq->execute();
$member = $mq->get_result()->fetch_assoc();
if (!$member) {
    echo json_encode(['success' => false, 'error' => 'Member not found']);
    exit;
}

// Plan names are "<Client> - <Phase>"; the phase is what follows the first " - "
$phase_of = static function (?string $plan_name): string {
    if ($plan_name === null || $plan_name === '') {
        return 'Earlier plan';   // the plan was deleted after the message was sent
    }
    $parts = explode(' - ', $plan_name, 2);
    return trim($parts[1] ?? $plan_name);
};

$stmt = $conn->prepare("SELECT dpm.id, dpm.plan_id, dpm.sender_role, dpm.message, dpm.created_at, dp.plan_name
                        FROM diet_plan_messages dpm
                        LEFT JOIN diet_plans dp ON dp.id = dpm.plan_id
                        WHERE dpm.member_id = ?
                        ORDER BY dpm.created_at ASC, dpm.id ASC");
$stmt->bind_param('i', $member_id);
$stmt->execute();
$res = $stmt->get_result();

$messages = [];
$plan_names = [];
while ($m = $res->fetch_assoc()) {
    $plan_names[(int) $m['plan_id']] = $m['plan_name'];
    $messages[] = [
        'id'          => (int) $m['id'],
        'plan_id'     => (int) $m['plan_id'],
        'phase'       => $phase_of($m['plan_name']),
        'sender_role' => $m['sender_role'],
        'message'     => $m['message'],
        'created_at'  => $m['created_at'],
    ];
}

// A reply has to go under a plan the member is actually assigned to
$reply_plan_id = 0;
$reply_phase   = '';
$assigned = $conn->prepare("SELECT a.plan_id, dp.plan_name
                            FROM diet_plan_assignments a
                            JOIN diet_plans dp ON dp.id = a.plan_id
                            WHERE a.member_id = ?
                            ORDER BY a.plan_id DESC");
$assigned->bind_param('i', $member_id);
$assigned->execute();
$assigned_plans = [];
$ar = $assigned->get_result();
while ($a = $ar->fetch_assoc()) {
    $assigned_plans[(int) $a['plan_id']] = $a['plan_name'];
}
if ($messages) {
    $latest_plan = end($messages)['plan_id'];
    if (isset($assigned_plans[$latest_plan])) {
        $reply_plan_id = $latest_plan;
    }
}
if ($reply_plan_id === 0 && $assigned_plans) {
    $reply_plan_id = (int) array_key_first($assigned_plans);
}
if ($reply_plan_id > 0) {
    $reply_phase = $phase_of($assigned_plans[$reply_plan_id]);
}

// Opening the conversation counts as reading it
$mark = $conn->prepare("UPDATE diet_plan_messages SET is_read = 1 WHERE member_id = ? AND sender_role = 'user' AND is_read = 0");
$mark->bind_param('i', $member_id);
$mark->execute();

echo json_encode([
    'success'       => true,
    'member'        => ['id' => (int) $member['id'], 'name' => $member['full_name']],
    'messages'      => $messages,
    'reply_plan_id' => $reply_plan_id,
    'reply_phase'   => $reply_phase,
]);
