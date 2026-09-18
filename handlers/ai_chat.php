<?php
/**
 * handlers/ai_chat.php
 * ─────────────────────────────────────────────────────────────────
 * The assistant's single chat endpoint (POST → JSON).
 *
 * Two modes:
 *   mode = "chat"  → a normal reply, as text
 *   mode = "plan"  → a full diet plan as JSON, stored as a draft for review
 *
 * The member is chosen by the admin in the widget's dropdown, so this handler
 * fetches their data itself — the model is never given tools and never
 * decides whose record to open.
 */

require_once __DIR__ . '/../auth/auth_check.php';
require_role(['admin', 'trainer']);
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../auth/ai_config.php';
require_once __DIR__ . '/../auth/ai_schema.php';
require_once __DIR__ . '/../auth/ai_client.php';
require_once __DIR__ . '/../auth/ai_context.php';
require_once __DIR__ . '/../auth/ai_prompts.php';
require_once __DIR__ . '/../auth/ai_plan.php';
require_once __DIR__ . '/../auth/rate_limiter.php';

header('Content-Type: application/json');

function ai_fail(string $message, int $retry_after = 0): void
{
    echo json_encode(['ok' => false, 'error' => $message, 'retry_after' => $retry_after]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ai_fail('Invalid request.');
}
if (!ai_assistant_enabled()) {
    ai_fail(ai_config_problem() ?: 'The assistant is unavailable.');
}

// CSRF — validate only (the dashboard is a long-lived page, same as the other widgets)
$submitted = $_POST['_csrf_token'] ?? '';
$stored    = $_SESSION['_csrf_token'] ?? '';
if (!$stored || !hash_equals($stored, $submitted)) {
    ai_fail('Security token expired. Please refresh the page.');
}

ai_ensure_schema($conn);

$admin      = get_session_user();
$admin_id   = (int) $admin['id'];
$admin_name = html_entity_decode($admin['name'] ?? '', ENT_QUOTES);

// Daily quota per admin, so one person can't exhaust the Groq free tier
if (AI_DAILY_REQUEST_CAP > 0) {
    $wait = rl_retry_after($conn, 'ai_request', (string) $admin_id, AI_DAILY_REQUEST_CAP, 86400);
    if ($wait > 0) {
        ai_fail('Daily AI usage limit reached. It resets in about ' . max(1, (int) round($wait / 3600)) . ' hour(s).');
    }
}

$mode            = ($_POST['mode'] ?? 'chat') === 'plan' ? 'plan' : 'chat';
$member_id       = (int) ($_POST['member_id'] ?? 0);
$conversation_id = (int) ($_POST['conversation_id'] ?? 0);
$draft_id        = (int) ($_POST['draft_id'] ?? 0);
$message         = trim($_POST['message'] ?? '');
$existing_draft  = null;   // set below when revising an existing draft

if ($message === '' && $mode !== 'plan') {
    ai_fail('Type a message first.');
}
if (mb_strlen($message) > 4000) {
    ai_fail('That message is too long.');
}

// ── Member context ────────────────────────────────────────────────
$ctx = null;
$targets = ['usable' => false];
$member_name = '';
if ($member_id > 0) {
    $ctx = ai_member_context($conn, $member_id);
    if (!$ctx) {
        ai_fail('That member no longer exists.');
    }
    $member_name = $ctx['member']['full_name'];

    // A revision carries the goal of the draft being revised. Without this, a
    // message like "make it vegan" reads as no goal at all and the targets
    // silently fall back to maintenance calories, undoing a fat-loss deficit.
    if ($draft_id > 0) {
        $dq = $conn->prepare("SELECT draft_json FROM ai_plan_drafts WHERE id = ? AND member_id = ? LIMIT 1");
        $dq->bind_param('ii', $draft_id, $member_id);
        $dq->execute();
        if ($drow = $dq->get_result()->fetch_assoc()) {
            $existing_draft = json_decode($drow['draft_json'], true);
            if (!is_array($existing_draft)) {
                $existing_draft = null;
            }
        }
    }

    // Goal for the targets: the draft being revised, else what the admin asked
    // for, else their last plan's goal.
    $goal_hint = trim((string) ($existing_draft['goal'] ?? ''));
    if ($goal_hint === '') {
        $goal_hint = $message;
        if (!empty($ctx['plans'])) {
            $goal_hint .= ' ' . end($ctx['plans'])['goal'];
        }
    }
    $targets = ai_nutrition_targets($ctx, $goal_hint);

    // A revision adjusts the draft's own numbers rather than recalculating from
    // scratch, so repeated tweaks don't drift back to the formula's figure.
    if ($existing_draft !== null && $targets['usable'] && !empty($existing_draft['calories'])) {
        $targets['target_calories'] = (int) $existing_draft['calories'];
    }

    // The calculator exists to stop the model inventing numbers — not to stop the
    // trainer. An explicit instruction in the message overrules it.
    $targets = ai_apply_target_overrides($targets, $message);
}

if ($mode === 'plan' && $member_id <= 0) {
    ai_fail('Choose a member from the dropdown before generating a plan.');
}

// Refuse to build a plan on a profile whose numbers can't be trusted — cheaper
// than an API call, and clearer than a plan quietly based on bad figures.
if ($mode === 'plan' && !$targets['usable']) {
    ai_fail('Can\'t build a plan for ' . $member_name . '. ' . ($targets['reason'] ?? '')
        . ' Update their profile, then try again.');
}

// ── Conversation record ───────────────────────────────────────────
if ($conversation_id > 0) {
    $own = $conn->prepare("SELECT id, member_id FROM ai_conversations WHERE id = ? AND admin_user_id = ? LIMIT 1");
    $own->bind_param('ii', $conversation_id, $admin_id);
    $own->execute();
    $row = $own->get_result()->fetch_assoc();
    // Switching member starts a new thread, so one member's data never bleeds into another's
    if (!$row || (int) $row['member_id'] !== $member_id) {
        $conversation_id = 0;
    }
}
if ($conversation_id === 0) {
    $title = $member_name !== '' ? $member_name : 'General nutrition';
    $ins = $conn->prepare("INSERT INTO ai_conversations (admin_user_id, member_id, title) VALUES (?, ?, ?)");
    $mid_param = $member_id > 0 ? $member_id : null;
    $ins->bind_param('iis', $admin_id, $mid_param, $title);
    $ins->execute();
    $conversation_id = (int) $conn->insert_id;
}

// ── Build the prompt ──────────────────────────────────────────────
$messages = [];
$messages[] = [
    'role'    => 'system',
    'content' => $ctx
        ? ai_system_prompt_member(ai_context_to_text($ctx, $targets, $mode === 'plan'), $member_name)
        : ai_system_prompt_general(),
];

// Recent history, oldest first (trimmed — the context block is the expensive part).
// Plan generation deliberately skips it: the history holds the previous plan's
// summary, and feeding that back makes the model reproduce it.
if ($mode !== 'plan') {
    $hist = $conn->prepare("SELECT role, content FROM ai_messages
                            WHERE conversation_id = ? AND role IN ('user','assistant')
                            ORDER BY id DESC LIMIT 8");
    $hist->bind_param('i', $conversation_id);
    $hist->execute();
    $rows = [];
    $hres = $hist->get_result();
    while ($h = $hres->fetch_assoc()) {
        $rows[] = $h;
    }
    foreach (array_reverse($rows) as $h) {
        $messages[] = ['role' => $h['role'], 'content' => $h['content']];
    }
}

if ($mode === 'plan') {
    // A revision keeps the draft's own diet type and goal as the baseline, so the
    // instructions never contradict a change the admin just asked for.
    $diet_type = strtolower((string) ($existing_draft['diet_type'] ?? $ctx['member']['diet_type'] ?? 'veg'));
    if (!in_array($diet_type, ['veg', 'nonveg', 'vegan'], true)) {
        $diet_type = 'veg';
    }
    $goal = trim((string) ($existing_draft['goal'] ?? ''));
    if ($goal === '') {
        $goal = $message !== '' ? $message : (!empty($ctx['plans']) ? end($ctx['plans'])['goal'] : 'General fitness');
    }

    // Revising an existing draft: hand the current version back for editing
    if ($existing_draft !== null) {
        $messages[] = [
            'role'    => 'system',
            'content' => "The current draft plan is below. Apply the admin's requested change and return the COMPLETE plan again in the same JSON shape. Keep the calorie and macro targets unchanged unless the admin explicitly asked to change them.\n\n"
                . json_encode($existing_draft, JSON_UNESCAPED_UNICODE),
        ];
    }

    $messages[] = [
        'role'    => 'system',
        'content' => ai_plan_instructions($targets, $diet_type, $goal, (string) ($targets['override_note'] ?? '')),
    ];
    $messages[] = ['role' => 'user', 'content' => $message !== '' ? $message : 'Create the next phase for this member.'];
} else {
    $messages[] = ['role' => 'user', 'content' => $message];
}

// ── Call Groq ─────────────────────────────────────────────────────
$result = groq_chat($messages, $mode === 'plan'
    ? ['json_mode' => true, 'max_tokens' => GROQ_PLAN_MAX_TOKENS, 'temperature' => GROQ_PLAN_TEMPERATURE]
    : []);

if (AI_DAILY_REQUEST_CAP > 0) {
    rl_hit($conn, 'ai_request', (string) $admin_id);
}

if (!$result['ok']) {
    ai_fail($result['error'], $result['retry_after']);
}

// ── Persist ───────────────────────────────────────────────────────
$store_user = $message !== '' ? $message : '(generate plan)';
$um = $conn->prepare("INSERT INTO ai_messages (conversation_id, role, content) VALUES (?, 'user', ?)");
$um->bind_param('is', $conversation_id, $store_user);
$um->execute();

$response = [
    'ok'              => true,
    'conversation_id' => $conversation_id,
    'mode'            => $mode,
    'draft'           => null,
    'draft_id'        => 0,
    'phase'           => '',
];

if ($mode === 'plan') {
    $draft = $result['json'];
    // The targets are authoritative — overwrite whatever the model put here
    if ($targets['usable']) {
        $draft['calories'] = $targets['target_calories'];
    }
    $draft['diet_type'] = in_array($draft['diet_type'] ?? '', ['veg', 'nonveg', 'vegan'], true) ? $draft['diet_type'] : 'veg';

    $json = json_encode($draft, JSON_UNESCAPED_UNICODE);
    $di = $conn->prepare("INSERT INTO ai_plan_drafts (conversation_id, member_id, draft_json) VALUES (?, ?, ?)");
    $di->bind_param('iis', $conversation_id, $member_id, $json);
    $di->execute();
    $new_draft_id = (int) $conn->insert_id;

    $summary = trim((string) ($draft['summary'] ?? 'Draft plan ready for review.'));
    $am = $conn->prepare("INSERT INTO ai_messages (conversation_id, role, content, tokens_in, tokens_out) VALUES (?, 'assistant', ?, ?, ?)");
    $am->bind_param('isii', $conversation_id, $summary, $result['usage']['in'], $result['usage']['out']);
    $am->execute();

    $response['reply']    = $summary;
    $response['draft']    = $draft;
    $response['draft_id'] = $new_draft_id;
    $response['phase']    = ai_next_phase_name($conn, ai_client_name_for_member($conn, $member_id));
    $response['targets']  = $targets;
} else {
    $reply = $result['content'];
    $am = $conn->prepare("INSERT INTO ai_messages (conversation_id, role, content, tokens_in, tokens_out) VALUES (?, 'assistant', ?, ?, ?)");
    $am->bind_param('isii', $conversation_id, $reply, $result['usage']['in'], $result['usage']['out']);
    $am->execute();
    $response['reply'] = $reply;
}

echo json_encode($response);
