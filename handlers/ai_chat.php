<?php
/**
 * handlers/ai_chat.php
 * ─────────────────────────────────────────────────────────────────
 * The assistant's single chat endpoint (POST → JSON).
 *
 * Two modes:
 *   mode = "chat"  → a normal reply, as text (streamed line by line when stream=1)
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

// Nothing below touches the session. Releasing it now matters: PHP locks the
// session file for the life of a request, so a 10-second AI call would otherwise
// freeze every other page the admin opens in the meantime.
session_write_close();

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
$plan_id         = (int) ($_POST['plan_id'] ?? 0);   // saved plan open in "Working on"
$existing_draft  = null;   // set below when revising a draft or editing a saved plan
$target_plan     = null;   // the saved diet_plans row being edited, if any

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

    // The saved plan the admin has open, if any. Checked against this member, so
    // a stray or crafted id can never reach someone else's plan.
    if ($plan_id > 0) {
        $target_plan = ai_member_owns_plan($conn, $member_id, $plan_id);
        if (!$target_plan) {
            ai_fail('That plan was not found for this member.');
        }
    }

    // A revision carries the goal of the draft being revised. Without this, a
    // message like "make it vegan" reads as no goal at all and the targets
    // silently fall back to maintenance calories, undoing a fat-loss deficit.
    if ($draft_id > 0) {
        $dq = $conn->prepare("SELECT draft_json, target_plan_id FROM ai_plan_drafts WHERE id = ? AND member_id = ? LIMIT 1");
        $dq->bind_param('ii', $draft_id, $member_id);
        $dq->execute();
        if ($drow = $dq->get_result()->fetch_assoc()) {
            $existing_draft = json_decode($drow['draft_json'], true);
            if (!is_array($existing_draft)) {
                $existing_draft = null;
            }
            // A draft made while editing a saved plan stays tied to that plan
            if ($target_plan === null && !empty($drow['target_plan_id'])) {
                $target_plan = ai_member_owns_plan($conn, $member_id, (int) $drow['target_plan_id']);
            }
        }
    }

    // Editing a saved plan with no draft yet: the plan itself is the starting point
    if ($mode === 'plan' && $existing_draft === null && $target_plan !== null) {
        $existing_draft = ai_columns_to_draft($target_plan);
        $existing_draft['phase'] = ai_phase_of($target_plan['plan_name']);
    }

    // What the admin currently sees in the panel wins over any stored version:
    // it may hold hand edits made since the last reply, and revising from the
    // stored copy would silently throw those away.
    $posted = json_decode((string) ($_POST['current_draft'] ?? ''), true);
    if ($mode === 'plan' && is_array($posted)) {
        $base = $existing_draft ?? [];
        foreach (array_merge(array_keys(ai_plan_sections()), ['goal', 'diet_type', 'phase']) as $k) {
            if (isset($posted[$k]) && is_string($posted[$k])) {
                $base[$k] = $posted[$k];
            }
        }
        if (isset($posted['calories'])) {
            $base['calories'] = (int) $posted['calories'];
        }
        if (isset($posted['times']) && is_array($posted['times'])) {
            $base['times'] = array_filter(array_intersect_key($posted['times'], ai_plan_sections()), 'is_string');
        }
        $existing_draft = $base;
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
$is_edit = $mode === 'plan' && $existing_draft !== null;
$context_purpose = $mode !== 'plan' ? 'chat' : ($is_edit ? 'edit_plan' : 'new_plan');

// Some older plans never recorded a calorie figure (stored as 0). Editing one of
// those must not impose the formula's target, or the model would rebalance meals
// the admin never asked it to touch. An explicit calorie request still applies.
$enforce_calories = !($is_edit && (int) ($existing_draft['calories'] ?? 0) === 0 && empty($targets['overridden']));

$messages = [];
$messages[] = [
    'role'    => 'system',
    'content' => $ctx
        ? ai_system_prompt_member(ai_context_to_text($ctx, $targets, $context_purpose, $target_plan), $member_name)
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

    // Editing: hand the plan as it stands back to the model. Meal times are left
    // out — they aren't the model's to change, and they're restored afterwards.
    if ($is_edit) {
        $for_model = $existing_draft;
        unset($for_model['times']);
        $messages[] = [
            'role'    => 'system',
            'content' => "The plan as it currently stands is below. Apply the admin's requested change and return the COMPLETE plan in the same JSON shape — every section, including the ones you did not change. Keep the calorie and macro targets unchanged unless the admin explicitly asked to change them.\n\n"
                . json_encode($for_model, JSON_UNESCAPED_UNICODE),
        ];
    }

    // The phase name the plan should carry: what's in the panel or draft, else the
    // saved plan's own, else the next number in the member's sequence.
    $phase_hint = trim((string) ($existing_draft['phase'] ?? ''));
    if ($phase_hint === '') {
        $phase_hint = $target_plan
            ? ai_phase_of($target_plan['plan_name'])
            : ai_next_phase_name($conn, ai_client_name_for_member($conn, $member_id));
    }

    $messages[] = [
        'role'    => 'system',
        'content' => ai_plan_instructions(
            $targets,
            $diet_type,
            $goal,
            (string) ($targets['override_note'] ?? ''),
            $is_edit ? 'edit' : 'new',
            $enforce_calories,
            $phase_hint
        ),
    ];
    $messages[] = ['role' => 'user', 'content' => $message !== '' ? $message : 'Create the next phase for this member.'];
} else {
    $messages[] = ['role' => 'user', 'content' => $message];
}

// ── Chat, streamed ────────────────────────────────────────────────
// The reply is sent to the browser as it's written, one JSON object per line:
//   {"t":"start"} → {"t":"delta","text":"..."} × n → {"t":"done"} or {"t":"error"}
// Plans don't stream — Groq only releases a JSON reply once it's complete.
if ($mode === 'chat' && ($_POST['stream'] ?? '') === '1') {
    @set_time_limit(GROQ_TIMEOUT + 30);
    // Keep running if the admin presses Stop, so the partial reply is still saved
    ignore_user_abort(true);

    // Every layer that could hold output back is switched off
    @ini_set('zlib.output_compression', '0');
    @ini_set('output_buffering', '0');
    if (function_exists('apache_setenv')) {
        @apache_setenv('no-gzip', '1');
    }
    header('Content-Type: application/x-ndjson; charset=utf-8');
    header('Cache-Control: no-cache');
    header('X-Accel-Buffering: no');
    while (ob_get_level() > 0) {
        ob_end_flush();
    }
    ob_implicit_flush(true);

    $emit = function (array $event): void {
        echo json_encode($event, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE), "\n";
        flush();
    };

    $emit(['t' => 'start', 'conversation_id' => $conversation_id]);
    $result = groq_chat_stream($messages, function (string $text) use ($emit): void {
        $emit(['t' => 'delta', 'text' => $text]);
    });

    if (AI_DAILY_REQUEST_CAP > 0) {
        rl_hit($conn, 'ai_request', (string) $admin_id);
    }

    $reply = $result['content'];
    if ($reply === '') {
        $emit(['t' => 'error', 'error' => $result['error'] ?: 'Stopped.', 'retry_after' => $result['retry_after']]);
        exit;
    }

    // A stopped or cut-off reply is saved as far as it got, so the conversation
    // history matches what the admin actually saw.
    $um = $conn->prepare("INSERT INTO ai_messages (conversation_id, role, content) VALUES (?, 'user', ?)");
    $um->bind_param('is', $conversation_id, $message);
    $um->execute();
    $am = $conn->prepare("INSERT INTO ai_messages (conversation_id, role, content, tokens_in, tokens_out) VALUES (?, 'assistant', ?, ?, ?)");
    $am->bind_param('isii', $conversation_id, $reply, $result['usage']['in'], $result['usage']['out']);
    $am->execute();

    $emit([
        't'               => 'done',
        'reply'           => $reply,
        'conversation_id' => $conversation_id,
        'mode'            => 'chat',
        'stopped'         => $result['aborted'],
        'error'           => $result['ok'] ? '' : $result['error'],
    ]);
    exit;
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
    // The targets are authoritative — overwrite whatever the model put here.
    // (A plan with no recorded calories keeps none, unless one was requested.)
    if (!$enforce_calories) {
        $draft['calories'] = 0;
    } elseif ($targets['usable']) {
        $draft['calories'] = $targets['target_calories'];
    }
    $draft['diet_type'] = in_array($draft['diet_type'] ?? '', ['veg', 'nonveg', 'vegan'], true) ? $draft['diet_type'] : 'veg';

    // Meal times aren't the model's to change — carry them over from what was edited
    if ($is_edit && !empty($existing_draft['times'])) {
        $draft['times'] = $existing_draft['times'];
    }

    // The model may rename the phase when asked, but only to a valid name.
    // Anything it gets wrong falls back to the name it was given.
    $phase_check = ai_validate_phase((string) ($draft['phase'] ?? ''));
    $draft['phase'] = $phase_check['ok'] ? $phase_check['phase'] : $phase_hint;

    $json = json_encode($draft, JSON_UNESCAPED_UNICODE);
    $target_id = $target_plan ? (int) $target_plan['id'] : null;
    $di = $conn->prepare("INSERT INTO ai_plan_drafts (conversation_id, member_id, draft_json, target_plan_id) VALUES (?, ?, ?, ?)");
    $di->bind_param('iisi', $conversation_id, $member_id, $json, $target_id);
    $di->execute();
    $new_draft_id = (int) $conn->insert_id;

    $summary = trim((string) ($draft['summary'] ?? 'Draft plan ready for review.'));
    $am = $conn->prepare("INSERT INTO ai_messages (conversation_id, role, content, tokens_in, tokens_out) VALUES (?, 'assistant', ?, ?, ?)");
    $am->bind_param('isii', $conversation_id, $summary, $result['usage']['in'], $result['usage']['out']);
    $am->execute();

    $response['reply']    = $summary;
    $response['draft']    = $draft;
    $response['draft_id'] = $new_draft_id;
    $response['phase']            = $draft['phase'];
    $response['target_plan_id']   = $target_plan ? (int) $target_plan['id'] : 0;
    $response['target_plan_name'] = $target_plan['plan_name'] ?? '';
    $response['targets']          = $targets;
} else {
    $reply = $result['content'];
    $am = $conn->prepare("INSERT INTO ai_messages (conversation_id, role, content, tokens_in, tokens_out) VALUES (?, 'assistant', ?, ?, ?)");
    $am->bind_param('isii', $conversation_id, $reply, $result['usage']['in'], $result['usage']['out']);
    $am->execute();
    $response['reply'] = $reply;
}

echo json_encode($response);
