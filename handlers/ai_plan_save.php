<?php
/**
 * handlers/ai_plan_save.php
 * ─────────────────────────────────────────────────────────────────
 * Writes an admin-reviewed plan (POST → JSON). Three actions:
 *
 *   save (default), plan_id = 0   insert the draft as the member's next phase
 *   save,           plan_id = N   update that existing plan in place
 *   undo,           draft_id = D  restore a plan to how it was before update D
 *
 * The fields posted here are whatever the admin left in the draft panel after
 * editing — the model's output is only ever a starting point, and nothing
 * reaches diet_plans without passing through this handler.
 */

require_once __DIR__ . '/../auth/auth_check.php';
require_role(['admin', 'trainer']);
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../auth/ai_schema.php';
require_once __DIR__ . '/../auth/ai_context.php';
require_once __DIR__ . '/../auth/ai_plan.php';

header('Content-Type: application/json');

function ai_save_fail(string $message): void
{
    echo json_encode(['ok' => false, 'error' => $message]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ai_save_fail('Invalid request.');
}

$submitted = $_POST['_csrf_token'] ?? '';
$stored    = $_SESSION['_csrf_token'] ?? '';
if (!$stored || !hash_equals($stored, $submitted)) {
    ai_save_fail('Security token expired. Please refresh the page.');
}

ai_ensure_schema($conn);

$admin    = get_session_user();
$admin_id = (int) $admin['id'];
$action   = $_POST['action'] ?? 'save';
$draft_id = (int) ($_POST['draft_id'] ?? 0);

// ── Undo an update ────────────────────────────────────────────────
if ($action === 'undo') {
    // Only the admin who made the update can undo it
    $q = $conn->prepare("SELECT d.id, d.target_plan_id, d.original_json, d.status
                         FROM ai_plan_drafts d
                         JOIN ai_conversations c ON c.id = d.conversation_id
                         WHERE d.id = ? AND c.admin_user_id = ? LIMIT 1");
    $q->bind_param('ii', $draft_id, $admin_id);
    $q->execute();
    $d = $q->get_result()->fetch_assoc();

    if (!$d || $d['status'] !== 'saved' || empty($d['target_plan_id']) || empty($d['original_json'])) {
        ai_save_fail('There is nothing to undo for that change.');
    }
    $original = json_decode($d['original_json'], true);
    if (!is_array($original) || !ai_restore_plan($conn, (int) $d['target_plan_id'], $original)) {
        ai_save_fail('Could not restore the previous version.');
    }

    $u = $conn->prepare("UPDATE ai_plan_drafts SET status = 'undone' WHERE id = ?");
    $u->bind_param('i', $draft_id);
    $u->execute();

    echo json_encode([
        'ok'        => true,
        'undone'    => true,
        'plan_id'   => (int) $d['target_plan_id'],
        'plan_name' => $original['plan_name'] ?? '',
    ]);
    exit;
}

// ── Save / update ─────────────────────────────────────────────────
$member_id = (int) ($_POST['member_id'] ?? 0);
$plan_id   = (int) ($_POST['plan_id'] ?? 0);
$phase     = trim($_POST['phase'] ?? '');

if ($member_id <= 0) {
    ai_save_fail('No member selected.');
}
$chk = $conn->prepare("SELECT id FROM members WHERE id = ? LIMIT 1");
$chk->bind_param('i', $member_id);
$chk->execute();
if (!$chk->get_result()->fetch_assoc()) {
    ai_save_fail('That member no longer exists.');
}

// Meal times ride along invisibly, so an edit keeps "(8:30 PM)" on dinner
$times = json_decode((string) ($_POST['times'] ?? ''), true);
$times = is_array($times)
    ? array_filter(array_intersect_key($times, ai_plan_sections()), 'is_string')
    : [];

$draft = [
    'goal'         => trim($_POST['goal'] ?? ''),
    'diet_type'    => $_POST['diet_type'] ?? 'veg',
    'calories'     => (int) ($_POST['calories'] ?? 0),
    'duration'     => (int) ($_POST['duration'] ?? 2),
    'times'        => $times,
];
foreach (array_keys(ai_plan_sections()) as $key) {
    $draft[$key] = trim($_POST[$key] ?? '');
}

$meals = $draft['breakfast'] . $draft['lunch'] . $draft['dinner'];
if (trim($meals) === '') {
    ai_save_fail('The plan is empty — there are no meals to save.');
}

$json = json_encode($draft, JSON_UNESCAPED_UNICODE);

if ($plan_id > 0) {
    // ── Update an existing plan in place (renaming its phase if it was changed) ──
    $res = ai_update_plan($conn, $member_id, $plan_id, $draft, $phase);
    if (!$res['ok']) {
        ai_save_fail($res['error']);
    }

    // Record the update against a draft row so it can be undone — even when the
    // admin edited the plan by hand without asking the assistant for anything.
    $original_json = json_encode($res['original'], JSON_UNESCAPED_UNICODE);
    if ($draft_id <= 0) {
        $title = 'Edit: ' . $res['plan_name'];
        $c = $conn->prepare("INSERT INTO ai_conversations (admin_user_id, member_id, title) VALUES (?, ?, ?)");
        $c->bind_param('iis', $admin_id, $member_id, $title);
        $c->execute();
        $conv_id = (int) $conn->insert_id;

        $n = $conn->prepare("INSERT INTO ai_plan_drafts (conversation_id, member_id, draft_json) VALUES (?, ?, ?)");
        $n->bind_param('iis', $conv_id, $member_id, $json);
        $n->execute();
        $draft_id = (int) $conn->insert_id;
    }
    $u = $conn->prepare("UPDATE ai_plan_drafts
                         SET status = 'saved', saved_plan_id = ?, target_plan_id = ?, draft_json = ?, original_json = ?
                         WHERE id = ? AND member_id = ?");
    $u->bind_param('iissii', $plan_id, $plan_id, $json, $original_json, $draft_id, $member_id);
    $u->execute();

    echo json_encode([
        'ok'            => true,
        'updated'       => true,
        'renamed'       => $res['renamed'],
        'phase'         => ai_phase_of($res['plan_name']),
        'plan_id'       => $plan_id,
        'plan_name'     => $res['plan_name'],
        'view_url'      => 'diet_plan_details.php?id=' . $plan_id,
        'undo_draft_id' => $draft_id,
    ]);
    exit;
}

// ── Insert as the member's next phase ──
$trainer_name = html_entity_decode($admin['name'] ?? '', ENT_QUOTES);
$saved = ai_save_plan_as_phase($conn, $member_id, $draft, $phase, $trainer_name);
if (!$saved['ok']) {
    ai_save_fail($saved['error']);
}

if ($draft_id > 0) {
    $upd = $conn->prepare("UPDATE ai_plan_drafts SET status = 'saved', saved_plan_id = ?, draft_json = ? WHERE id = ? AND member_id = ?");
    $upd->bind_param('isii', $saved['plan_id'], $json, $draft_id, $member_id);
    $upd->execute();
}

echo json_encode([
    'ok'        => true,
    'updated'   => false,
    'plan_id'   => $saved['plan_id'],
    'plan_name' => $saved['plan_name'],
    'view_url'  => 'diet_plan_details.php?id=' . $saved['plan_id'],
]);
