<?php
/**
 * handlers/ai_plans.php
 * ─────────────────────────────────────────────────────────────────
 * Read-only lookups for the assistant's "Working on" dropdown (GET → JSON).
 *
 *   ?action=list&member_id=N              every saved plan for the member
 *   ?action=load&member_id=N&plan_id=M    one plan, split into editable sections
 *
 * Ownership is checked on every load, so a plan id from another member returns
 * "not found" rather than that member's plan.
 */

require_once __DIR__ . '/../auth/auth_check.php';
require_role(['admin', 'trainer']);
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../auth/ai_plan.php';

header('Content-Type: application/json');

$action    = $_GET['action'] ?? 'list';
$member_id = (int) ($_GET['member_id'] ?? 0);

if ($member_id <= 0) {
    echo json_encode(['ok' => false, 'error' => 'No member selected.']);
    exit;
}

if ($action === 'list') {
    $plans = array_map(fn(array $p): array => [
        'id'       => (int) $p['id'],
        'phase'    => $p['phase'],
        'goal'     => $p['goal'],
        'calories' => (int) $p['calories'],
        'created'  => date('d M', strtotime($p['created_at'])),
    ], ai_member_plans($conn, $member_id));

    echo json_encode(['ok' => true, 'plans' => $plans]);
    exit;
}

if ($action === 'load') {
    $plan_id = (int) ($_GET['plan_id'] ?? 0);
    $row = ai_member_owns_plan($conn, $member_id, $plan_id);
    if (!$row) {
        echo json_encode(['ok' => false, 'error' => 'That plan was not found for this member.']);
        exit;
    }

    echo json_encode([
        'ok'        => true,
        'plan_id'   => (int) $row['id'],
        'plan_name' => $row['plan_name'],
        'phase'     => ai_phase_of($row['plan_name']),
        'draft'     => ai_columns_to_draft($row),
    ]);
    exit;
}

echo json_encode(['ok' => false, 'error' => 'Unknown action.']);
