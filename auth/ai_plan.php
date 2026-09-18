<?php
/**
 * auth/ai_plan.php
 * ─────────────────────────────────────────────────────────────────
 * Turns a reviewed draft into a real diet_plans row.
 *
 * The section → column grouping and the emoji labels mirror
 * templates/create_diet_plan.php and templates/add_new_phase.php exactly, so
 * plans written by the assistant are indistinguishable from hand-written ones
 * — the meal-summary parser, the PDF export and the member portal all keep
 * working with no changes.
 */

require_once __DIR__ . '/diet_plan_schema.php';
require_once __DIR__ . '/ai_context.php';   // ai_client_name_for_member()

if (!function_exists('ai_next_phase_name')) {
    /**
     * Next consecutive two-week block for a client, using the same rule as
     * add_new_phase.php: find the highest week number already used, continue
     * from there. First plan for a client starts at "Week 1 & 2".
     */
    function ai_next_phase_name(mysqli $conn, string $client_name): string
    {
        $like = $client_name . ' - %';
        $stmt = $conn->prepare("SELECT plan_name FROM diet_plans WHERE plan_name LIKE ? ORDER BY id ASC");
        $stmt->bind_param('s', $like);
        $stmt->execute();
        $res = $stmt->get_result();

        $highest = 0;
        while ($row = $res->fetch_assoc()) {
            $parts = explode(' - ', $row['plan_name']);
            if (isset($parts[1]) && preg_match('/Week\s*(\d+)\s*&\s*(\d+)/i', $parts[1], $m)) {
                $highest = max($highest, (int) $m[1], (int) $m[2]);
            }
        }

        $start = $highest + 1;
        return 'Week ' . $start . ' & ' . ($start + 1);
    }
}

if (!function_exists('ai_draft_to_columns')) {
    /**
     * Groups the eight draft sections into the four diet_plans text columns,
     * with the same emoji headers the existing pages write.
     *
     * @return array{breakfast:string, lunch:string, snack:string, dinner:string}
     */
    function ai_draft_to_columns(array $d): array
    {
        // Models like to emit non-breaking spaces and non-breaking hyphens, which
        // are invisible in the admin's textarea but survive into the PDF. Swap them
        // for their plain equivalents, and drop any byte sequence that isn't valid
        // UTF-8 so a mangled paste can never fail the INSERT with a charset error.
        $clean = static function (string $s): string {
            $s = mb_convert_encoding($s, 'UTF-8', 'UTF-8');
            $s = str_replace(["\xC2\xA0", "\xE2\x80\x91"], [' ', '-'], $s);
            // These fields print straight onto the member's PDF, so any markdown or
            // HTML the model slipped in would show up there as literal clutter.
            $s = preg_replace('/<br\s*\/?>/i', "\n", $s);
            $s = strip_tags($s);
            $s = str_replace(['**', '```'], '', $s);
            $s = preg_replace('/^#{1,6}\s*/m', '', $s);
            return preg_replace("/\n{3,}/", "\n\n", $s);
        };
        $get = fn(string $k): string => trim($clean((string) ($d[$k] ?? '')));

        $breakfast = '';
        if ($get('wake_up') !== '') {
            $breakfast .= "🌅 **WAKE UP:**\n" . $get('wake_up') . "\n\n";
        }
        if ($get('post_workout') !== '') {
            $breakfast .= "💪 **POST WORKOUT:**\n" . $get('post_workout') . "\n\n";
        }
        if ($get('breakfast') !== '') {
            $breakfast .= "🍳 **BREAKFAST:**\n" . $get('breakfast');
        }

        $lunch = $get('lunch') !== '' ? "🍛 **LUNCH:**\n" . $get('lunch') : '';
        $snack = $get('snack') !== '' ? "🍎 **MID MEAL:**\n" . $get('snack') : '';

        $dinner = '';
        if ($get('dinner') !== '') {
            $dinner .= "🍲 **DINNER:**\n" . $get('dinner') . "\n\n";
        }
        if ($get('pre_sleep') !== '') {
            $dinner .= "🌙 **PRE-SLEEP:**\n" . $get('pre_sleep') . "\n\n";
        }
        if ($get('guidelines') !== '') {
            $dinner .= "📝 **GUIDELINES:**\n" . $get('guidelines');
        }

        return [
            'breakfast' => rtrim($breakfast),
            'lunch'     => $lunch,
            'snack'     => $snack,
            'dinner'    => rtrim($dinner),
        ];
    }
}

if (!function_exists('ai_save_plan_as_phase')) {
    /**
     * Writes the reviewed draft to diet_plans and links it to the member via
     * diet_plan_assignments, so it shows up in their portal straight away.
     *
     * @return array{ok:bool, plan_id:int, plan_name:string, error:string}
     */
    function ai_save_plan_as_phase(mysqli $conn, int $member_id, array $draft, string $phase, string $trainer_name): array
    {
        $out = ['ok' => false, 'plan_id' => 0, 'plan_name' => '', 'error' => ''];

        $client_name = ai_client_name_for_member($conn, $member_id);
        if ($client_name === '') {
            $out['error'] = 'Could not determine the client name for this member.';
            return $out;
        }

        $phase = trim($phase) !== '' ? trim($phase) : ai_next_phase_name($conn, $client_name);
        $plan_name = $client_name . ' - ' . $phase;

        $diet_type = in_array($draft['diet_type'] ?? '', ['veg', 'nonveg', 'vegan'], true) ? $draft['diet_type'] : 'veg';
        $goal      = trim((string) ($draft['goal'] ?? ''));
        $duration  = max(1, (int) ($draft['duration'] ?? 2));
        $calories  = max(0, (int) ($draft['calories'] ?? 0));
        $cols      = ai_draft_to_columns($draft);

        if ($goal === '') {
            $out['error'] = 'The plan needs a goal before it can be saved.';
            return $out;
        }

        $conn->begin_transaction();
        try {
            $sql = "INSERT INTO diet_plans (plan_name, diet_type, goal, duration, calories, trainer_name, breakfast, lunch, snack, dinner)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param(
                'sssiisssss',
                $plan_name,
                $diet_type,
                $goal,
                $duration,
                $calories,
                $trainer_name,
                $cols['breakfast'],
                $cols['lunch'],
                $cols['snack'],
                $cols['dinner']
            );
            $stmt->execute();
            $plan_id = (int) $conn->insert_id;

            $as = $conn->prepare("INSERT IGNORE INTO diet_plan_assignments (plan_id, member_id) VALUES (?, ?)");
            $as->bind_param('ii', $plan_id, $member_id);
            $as->execute();

            $conn->commit();

            $out['ok']        = true;
            $out['plan_id']   = $plan_id;
            $out['plan_name'] = $plan_name;
        } catch (Throwable $e) {
            $conn->rollback();
            error_log('[AI] plan save failed: ' . $e->getMessage());
            $out['error'] = 'Could not save the plan. Please try again.';
        }

        return $out;
    }
}
