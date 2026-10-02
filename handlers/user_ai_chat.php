<?php
/**
 * handlers/user_ai_chat.php
 * ─────────────────────────────────────────────────────────────────
 * "FitJo" — the member-facing diet/fitness chatbot on user_diet_plans.php.
 *
 *   GET                                    → { success, history, limit, used, limit_reached }
 *   POST message=...&plan_id=N (0=all)     → { success, reply, used, limit_reached }
 *                                             (CSRF required)
 *
 * Member accounts only, and always scoped to that member's OWN diet plan data —
 * there's no member_id parameter to trust here, it's derived from the session.
 */

require_once __DIR__ . '/../auth/auth_check.php';
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../auth/ai_config.php';
require_once __DIR__ . '/../auth/ai_client.php';
require_once __DIR__ . '/../auth/member_ai_schema.php';
require_once __DIR__ . '/../auth/member_ai_prompts.php';

header('Content-Type: application/json');

function uai_fail(string $message): void
{
    echo json_encode(['success' => false, 'error' => $message]);
    exit;
}

$user = get_session_user();
if (!$user || $user['role'] !== 'user') {
    http_response_code(403);
    uai_fail('Unauthorized');
}
if (!ai_assistant_enabled()) {
    uai_fail(ai_config_problem() ?: 'The assistant is unavailable right now.');
}

$uid = (int) $user['id'];
$mstmt = $conn->prepare("SELECT id, full_name FROM members WHERE user_id = ? ORDER BY id DESC LIMIT 1");
$mstmt->bind_param('i', $uid);
$mstmt->execute();
$member = $mstmt->get_result()->fetch_assoc();
$member_id = $member ? (int) $member['id'] : 0;
if (!$member_id) {
    uai_fail('Please complete your profile first.');
}
$member_name = trim(explode(' ', html_entity_decode($member['full_name'] ?? '', ENT_QUOTES))[0]) ?: 'there';

ensure_member_ai_schema($conn);
$used = member_ai_message_count($conn, $member_id);
$limit_reached = $used >= MEMBER_AI_MESSAGE_LIMIT;

// ── Read: history + current allowance ───────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    echo json_encode([
        'success'       => true,
        'history'       => member_ai_history($conn, $member_id),
        'used'          => $used,
        'limit'         => MEMBER_AI_MESSAGE_LIMIT,
        'limit_reached' => $limit_reached,
        'member_name'   => $member_name,
    ]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    uai_fail('Invalid request.');
}

$submitted = $_POST['_csrf_token'] ?? '';
$stored    = $_SESSION['_csrf_token'] ?? '';
if (!$stored || !hash_equals($stored, $submitted)) {
    uai_fail('Security token expired. Please refresh the page.');
}

if ($limit_reached) {
    // No special "limit reached" wording — the UI disables itself before this
    // can even be hit; this is just the server-side backstop.
    uai_fail('This chat is no longer available.');
}

$message = trim($_POST['message'] ?? '');
if ($message === '') {
    uai_fail('Type a message first.');
}
if (mb_strlen($message) > 1000) {
    uai_fail('That message is too long.');
}

$plan_id = (int) ($_POST['plan_id'] ?? 0);

// ── Build the plan context: one phase, or every phase this member has ──
$params = [$member_id];
$sql = "SELECT dp.id, dp.plan_name, dp.goal, dp.diet_type, dp.calories, dp.duration,
               dp.breakfast, dp.lunch, dp.snack, dp.dinner
        FROM diet_plans dp
        JOIN diet_plan_assignments a ON a.plan_id = dp.id
        WHERE a.member_id = ?";
if ($plan_id > 0) {
    $sql .= " AND dp.id = ?";
    $params[] = $plan_id;
}
$sql .= " ORDER BY dp.created_at ASC";
$pstmt = $conn->prepare($sql);
$pstmt->bind_param(str_repeat('i', count($params)), ...$params);
$pstmt->execute();
$res = $pstmt->get_result();
$plans = [];
while ($row = $res->fetch_assoc()) {
    $plans[] = $row;
}
if ($plan_id > 0 && !$plans) {
    uai_fail('That plan was not found on your account.');
}
if (!$plans) {
    uai_fail("You don't have a diet plan assigned yet, so there's nothing for me to help with yet — check back once your trainer sets one up!");
}

function uai_clean(string $text): string
{
    // Strip the admin form's leading emoji + markdown bold markers so the model
    // (and its answer) reads plain, like the plan page itself already shows.
    $text = preg_replace('/[\x{1F300}-\x{1FAFF}\x{2600}-\x{27BF}\x{FE0F}]\s*/u', '', $text);
    return preg_replace('/\*\*(.*?)\*\*/', '$1', trim((string) $text));
}

function uai_phase_label(string $plan_name): string
{
    $parts = explode(' - ', $plan_name, 2);
    return trim($parts[1] ?? $plan_name);
}

$plan_blocks = [];
foreach ($plans as $p) {
    $plan_blocks[] = sprintf(
        "Phase: %s\nGoal: %s | Diet: %s | Calories: %s kcal | Duration: %s weeks\nBreakfast: %s\nLunch: %s\nSnack: %s\nDinner: %s",
        uai_phase_label($p['plan_name']),
        $p['goal'] ?: '—',
        ucfirst($p['diet_type'] ?: '—'),
        $p['calories'] ?: '—',
        $p['duration'] ?: '—',
        uai_clean($p['breakfast']),
        uai_clean($p['lunch']),
        uai_clean($p['snack']),
        uai_clean($p['dinner'])
    );
}
$context_text = "Member's name: {$member['full_name']}\n\n" . implode("\n\n---\n\n", $plan_blocks);

$messages = [[
    'role'    => 'system',
    'content' => member_ai_system_prompt($member_name, $context_text, $plan_id > 0),
]];

// Recent history for conversational continuity (trimmed, same convention as the admin widget)
$hist = $conn->prepare("SELECT role, content FROM member_ai_messages WHERE member_id = ? ORDER BY id DESC LIMIT 8");
$hist->bind_param('i', $member_id);
$hist->execute();
$hres = $hist->get_result();
$rows = [];
while ($h = $hres->fetch_assoc()) {
    $rows[] = $h;
}
foreach (array_reverse($rows) as $h) {
    $messages[] = ['role' => $h['role'], 'content' => $h['content']];
}
$messages[] = ['role' => 'user', 'content' => $message];

$result = groq_chat($messages, ['temperature' => 0.5, 'max_tokens' => 600]);
if (!$result['ok']) {
    uai_fail($result['error']);
}

$um = $conn->prepare("INSERT INTO member_ai_messages (member_id, role, content) VALUES (?, 'user', ?)");
$um->bind_param('is', $member_id, $message);
$um->execute();

$reply = $result['content'];
$am = $conn->prepare("INSERT INTO member_ai_messages (member_id, role, content) VALUES (?, 'assistant', ?)");
$am->bind_param('is', $member_id, $reply);
$am->execute();

$used++;
echo json_encode([
    'success'       => true,
    'reply'         => $reply,
    'used'          => $used,
    'limit'         => MEMBER_AI_MESSAGE_LIMIT,
    'limit_reached' => $used >= MEMBER_AI_MESSAGE_LIMIT,
]);
