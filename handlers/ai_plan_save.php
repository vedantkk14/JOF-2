<?php
/**
 * handlers/ai_plan_save.php
 * ─────────────────────────────────────────────────────────────────
 * Saves an admin-reviewed draft as the member's next diet-plan phase.
 *
 * The fields posted here are whatever the admin left in the draft panel after
 * editing — the model's original output is only a starting point, and nothing
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

$admin     = get_session_user();
$member_id = (int) ($_POST['member_id'] ?? 0);
$draft_id  = (int) ($_POST['draft_id'] ?? 0);
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

// Rebuild the draft from the posted (edited) fields
$draft = [
    'goal'         => trim($_POST['goal'] ?? ''),
    'diet_type'    => $_POST['diet_type'] ?? 'veg',
    'calories'     => (int) ($_POST['calories'] ?? 0),
    'duration'     => (int) ($_POST['duration'] ?? 2),
    'wake_up'      => trim($_POST['wake_up'] ?? ''),
    'breakfast'    => trim($_POST['breakfast'] ?? ''),
    'post_workout' => trim($_POST['post_workout'] ?? ''),
    'lunch'        => trim($_POST['lunch'] ?? ''),
    'snack'        => trim($_POST['snack'] ?? ''),
    'dinner'       => trim($_POST['dinner'] ?? ''),
    'pre_sleep'    => trim($_POST['pre_sleep'] ?? ''),
    'guidelines'   => trim($_POST['guidelines'] ?? ''),
];

$meals = $draft['breakfast'] . $draft['lunch'] . $draft['dinner'];
if (trim($meals) === '') {
    ai_save_fail('The plan is empty — there are no meals to save.');
}

$trainer_name = html_entity_decode($admin['name'] ?? '', ENT_QUOTES);
$saved = ai_save_plan_as_phase($conn, $member_id, $draft, $phase, $trainer_name);

if (!$saved['ok']) {
    ai_save_fail($saved['error']);
}

if ($draft_id > 0) {
    $upd = $conn->prepare("UPDATE ai_plan_drafts SET status = 'saved', saved_plan_id = ?, draft_json = ? WHERE id = ? AND member_id = ?");
    $json = json_encode($draft, JSON_UNESCAPED_UNICODE);
    $upd->bind_param('isii', $saved['plan_id'], $json, $draft_id, $member_id);
    $upd->execute();
}

echo json_encode([
    'ok'        => true,
    'plan_id'   => $saved['plan_id'],
    'plan_name' => $saved['plan_name'],
    'view_url'  => 'diet_plan_details.php?id=' . $saved['plan_id'],
]);
