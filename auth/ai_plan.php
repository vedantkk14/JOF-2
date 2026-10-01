<?php
/**
 * auth/ai_plan.php
 * ─────────────────────────────────────────────────────────────────
 * Moves plans between the two shapes they live in:
 *
 *   draft    eight editable sections (wake_up … guidelines) + meta + times
 *   columns  diet_plans' four text columns, each holding several sections
 *            under "🌅 **WAKE UP (6 AM):**"-style headers
 *
 * The grouping and emoji labels mirror templates/create_diet_plan.php and
 * templates/add_new_phase.php exactly, so plans written by the assistant are
 * indistinguishable from hand-written ones — the meal-summary parser, the PDF
 * export and the member portal all keep working unchanged.
 */

require_once __DIR__ . '/diet_plan_schema.php';
require_once __DIR__ . '/ai_context.php';   // ai_client_name_for_member()

if (!function_exists('ai_plan_sections')) {
    /**
     * The eight sections in print order: the label and emoji each is written
     * under, and which diet_plans column it lives in.
     */
    function ai_plan_sections(): array
    {
        return [
            'wake_up'      => ['label' => 'WAKE UP',      'emoji' => '🌅', 'column' => 'breakfast'],
            'post_workout' => ['label' => 'POST WORKOUT', 'emoji' => '💪', 'column' => 'breakfast'],
            'breakfast'    => ['label' => 'BREAKFAST',    'emoji' => '🍳', 'column' => 'breakfast'],
            'lunch'        => ['label' => 'LUNCH',        'emoji' => '🍛', 'column' => 'lunch'],
            'snack'        => ['label' => 'MID MEAL',     'emoji' => '🍎', 'column' => 'snack'],
            'dinner'       => ['label' => 'DINNER',       'emoji' => '🍲', 'column' => 'dinner'],
            'pre_sleep'    => ['label' => 'PRE-SLEEP',    'emoji' => '🌙', 'column' => 'dinner'],
            'guidelines'   => ['label' => 'GUIDELINES',   'emoji' => '📝', 'column' => 'dinner'],
        ];
    }
}

if (!function_exists('ai_clean_plan_text')) {
    /**
     * Sanitises one section of text before it's stored.
     *
     * Models emit non-breaking spaces and hyphens that are invisible in a
     * textarea but survive into the PDF; they're swapped for plain equivalents.
     * Invalid UTF-8 is dropped so a mangled paste can't fail the write with a
     * charset error. Markdown and HTML are removed because these fields print
     * straight onto the member's PDF, where they'd show up as literal clutter.
     */
    function ai_clean_plan_text(string $s): string
    {
        $s = mb_convert_encoding($s, 'UTF-8', 'UTF-8');
        $s = str_replace(["\xC2\xA0", "\xE2\x80\x91"], [' ', '-'], $s);
        $s = preg_replace('/<br\s*\/?>/i', "\n", $s);
        $s = strip_tags($s);
        $s = str_replace(['**', '```'], '', $s);
        $s = preg_replace('/^#{1,6}\s*/m', '', $s);
        return trim(preg_replace("/\n{3,}/", "\n\n", $s));
    }
}

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

if (!function_exists('ai_phase_of')) {
    /** "Rahul Sharma - Week 3 & 4" → "Week 3 & 4" */
    function ai_phase_of(string $plan_name): string
    {
        $parts = explode(' - ', $plan_name, 2);
        return trim($parts[1] ?? $plan_name);
    }
}

if (!function_exists('ai_validate_phase')) {
    /**
     * Checks a phase name before it becomes part of a plan_name.
     *
     * " - " is refused because plan names are "<Client> - <Phase>" and
     * add_new_phase.php splits on it — a phase like "Maintenance - Month 2"
     * would be cut to "Maintenance" there.
     *
     * @return array{ok:bool, phase:string, error:string}
     */
    function ai_validate_phase(string $phase): array
    {
        $phase = trim(preg_replace('/\s+/', ' ', ai_clean_plan_text($phase)));
        if ($phase === '') {
            return ['ok' => false, 'phase' => '', 'error' => 'The phase name can\'t be empty.'];
        }
        if (mb_strlen($phase) > 60) {
            return ['ok' => false, 'phase' => '', 'error' => 'Keep the phase name under 60 characters.'];
        }
        if (str_contains($phase, ' - ')) {
            return ['ok' => false, 'phase' => '', 'error' => 'A phase name can\'t contain " - " — that\'s what separates the client name from the phase. Try "Week 13 & 14" or "Maintenance".'];
        }
        return ['ok' => true, 'phase' => $phase, 'error' => ''];
    }
}

if (!function_exists('ai_plan_name_taken')) {
    /**
     * Whether another plan already uses this exact name (the collation makes the
     * comparison case-insensitive). Prevents new duplicates like the two
     * "Demo plan - Week 7 & 8" rows already in the data.
     */
    function ai_plan_name_taken(mysqli $conn, string $plan_name, int $except_id = 0): bool
    {
        $stmt = $conn->prepare("SELECT id FROM diet_plans WHERE plan_name = ? AND id != ? LIMIT 1");
        $stmt->bind_param('si', $plan_name, $except_id);
        $stmt->execute();
        return (bool) $stmt->get_result()->fetch_assoc();
    }
}

if (!function_exists('ai_draft_to_columns')) {
    /**
     * Groups the eight draft sections into the four diet_plans text columns.
     * Meal times travel in $d['times'] and are written back into the header —
     * "🍲 **DINNER (8:30 PM):**" — so a plan's schedule survives every edit.
     *
     * @return array{breakfast:string, lunch:string, snack:string, dinner:string}
     */
    function ai_draft_to_columns(array $d): array
    {
        $times = is_array($d['times'] ?? null) ? $d['times'] : [];
        $cols  = ['breakfast' => [], 'lunch' => [], 'snack' => [], 'dinner' => []];

        foreach (ai_plan_sections() as $key => $s) {
            $text = ai_clean_plan_text((string) ($d[$key] ?? ''));
            if ($text === '') {
                continue;
            }
            $time  = trim(str_replace(['(', ')'], '', ai_clean_plan_text((string) ($times[$key] ?? ''))));
            $label = $s['label'] . ($time !== '' ? ' (' . $time . ')' : '');
            $cols[$s['column']][] = $s['emoji'] . ' **' . $label . ':**' . "\n" . $text;
        }

        return array_map(fn(array $parts): string => implode("\n\n", $parts), $cols);
    }
}

if (!function_exists('ai_columns_to_draft')) {
    /**
     * The reverse of ai_draft_to_columns(): reads a saved diet_plans row back into
     * the eight editable sections.
     *
     * Handles every header spelling found in real data — "WAKE UP:", "WAKE UP :"
     * and "WAKE UP (6 AM):" — and keeps the times, so editing a plan never
     * silently strips "(8:30 PM)" off its dinner. Nothing is ever discarded:
     * text before the first header, headerless columns and unrecognised sections
     * all land in that column's main field.
     */
    function ai_columns_to_draft(array $row): array
    {
        $sections = ai_plan_sections();
        $by_label = [];
        foreach ($sections as $key => $s) {
            $by_label[$s['label']] = $key;
        }

        $draft = array_fill_keys(array_keys($sections), '');
        $times = [];

        // optional emoji, then **LABEL (optional time) optional-colon**
        $header = '/[^\S\n]*[\p{So}\p{Sk}\x{FE0F}\x{200D}]*[^\S\n]*\*\*\s*([A-Za-z][A-Za-z \-]*?)\s*(?:\(([^)]*)\))?\s*:?\s*\*\*/u';

        foreach (['breakfast', 'lunch', 'snack', 'dinner'] as $col) {
            $text = (string) ($row[$col] ?? '');
            if (trim($text) === '') {
                continue;
            }
            if (!preg_match_all($header, $text, $hits, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
                $draft[$col] = trim($text);
                continue;
            }

            $lead = trim(substr($text, 0, $hits[0][0][1]));
            if ($lead !== '') {
                $draft[$col] = $lead;
            }

            foreach ($hits as $i => $hit) {
                $start = $hit[0][1] + strlen($hit[0][0]);
                $end   = isset($hits[$i + 1]) ? $hits[$i + 1][0][1] : strlen($text);
                $body  = trim(substr($text, $start, $end - $start));
                $label = strtoupper(trim(preg_replace('/\s+/', ' ', $hit[1][0])));

                $key = $by_label[$label] ?? null;
                if ($key === null) {
                    $key  = $col;
                    $body = $label . ': ' . $body;
                }
                $draft[$key] = $draft[$key] !== '' ? $draft[$key] . "\n" . $body : $body;

                if (isset($hit[2]) && $hit[2][1] !== -1 && trim($hit[2][0]) !== '') {
                    $times[$key] = trim($hit[2][0]);
                }
            }
        }

        $draft['goal']      = (string) ($row['goal'] ?? '');
        $draft['diet_type'] = in_array($row['diet_type'] ?? '', ['veg', 'nonveg', 'vegan'], true) ? $row['diet_type'] : 'veg';
        $draft['calories']  = (int) ($row['calories'] ?? 0);
        $draft['duration']  = (int) ($row['duration'] ?? 2);
        $draft['times']     = $times;

        return $draft;
    }
}

if (!function_exists('ai_member_plans')) {
    /**
     * Every saved plan belonging to a member, oldest first. A plan belongs to a
     * member through an assignment row or through the "<Client> - <Phase>" name —
     * the same rule ai_member_context() uses.
     */
    function ai_member_plans(mysqli $conn, int $member_id): array
    {
        $like = ai_client_name_for_member($conn, $member_id) . ' - %';
        $stmt = $conn->prepare("SELECT DISTINCT dp.id, dp.plan_name, dp.goal, dp.calories, dp.created_at
                                FROM diet_plans dp
                                LEFT JOIN diet_plan_assignments a ON a.plan_id = dp.id
                                WHERE a.member_id = ? OR dp.plan_name LIKE ?
                                ORDER BY dp.id ASC");
        $stmt->bind_param('is', $member_id, $like);
        $stmt->execute();
        $res = $stmt->get_result();

        $plans = [];
        while ($row = $res->fetch_assoc()) {
            $row['phase'] = ai_phase_of($row['plan_name']);
            $plans[] = $row;
        }
        return $plans;
    }
}

if (!function_exists('ai_member_owns_plan')) {
    /**
     * The full diet_plans row if it belongs to this member, otherwise null.
     * Every read and write of a saved plan goes through this, so a crafted
     * request can never touch another member's plan.
     */
    function ai_member_owns_plan(mysqli $conn, int $member_id, int $plan_id): ?array
    {
        $like = ai_client_name_for_member($conn, $member_id) . ' - %';
        $stmt = $conn->prepare("SELECT dp.* FROM diet_plans dp
                                LEFT JOIN diet_plan_assignments a ON a.plan_id = dp.id AND a.member_id = ?
                                WHERE dp.id = ? AND (a.member_id IS NOT NULL OR dp.plan_name LIKE ?)
                                LIMIT 1");
        $stmt->bind_param('iis', $member_id, $plan_id, $like);
        $stmt->execute();
        return $stmt->get_result()->fetch_assoc() ?: null;
    }
}

if (!function_exists('ai_save_plan_as_phase')) {
    /**
     * Writes a reviewed draft to diet_plans as a brand-new phase and links it to
     * the member via diet_plan_assignments, so it shows in their portal at once.
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

        if (trim($phase) === '') {
            $phase = ai_next_phase_name($conn, $client_name);
        }
        $check = ai_validate_phase($phase);
        if (!$check['ok']) {
            $out['error'] = $check['error'];
            return $out;
        }
        $plan_name = $client_name . ' - ' . $check['phase'];
        if (ai_plan_name_taken($conn, $plan_name)) {
            $out['error'] = 'A plan called "' . $plan_name . '" already exists. Choose a different phase name.';
            return $out;
        }

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

if (!function_exists('ai_update_plan')) {
    /**
     * Overwrites an existing plan's content with a reviewed draft, and renames
     * its phase if $new_phase differs from the current one.
     *
     * Author and resources are never touched. A rename keeps the client part of
     * the name exactly as stored ("Divesh kanki"), so the plan still files under
     * the same person. The row as it stood before the update comes back in
     * 'original' so the caller can offer Undo — including the old name.
     *
     * @return array{ok:bool, plan_id:int, plan_name:string, renamed:bool, original:?array, error:string}
     */
    function ai_update_plan(mysqli $conn, int $member_id, int $plan_id, array $draft, string $new_phase = ''): array
    {
        $out = ['ok' => false, 'plan_id' => $plan_id, 'plan_name' => '', 'renamed' => false, 'original' => null, 'error' => ''];

        $row = ai_member_owns_plan($conn, $member_id, $plan_id);
        if (!$row) {
            $out['error'] = 'That plan was not found for this member.';
            return $out;
        }

        $plan_name = $row['plan_name'];
        if (trim($new_phase) !== '') {
            $check = ai_validate_phase($new_phase);
            if (!$check['ok']) {
                $out['error'] = $check['error'];
                return $out;
            }
            if ($check['phase'] !== ai_phase_of($row['plan_name'])) {
                $prefix = trim(explode(' - ', $row['plan_name'], 2)[0]);
                $plan_name = $prefix . ' - ' . $check['phase'];
                if (ai_plan_name_taken($conn, $plan_name, $plan_id)) {
                    $out['error'] = 'A plan called "' . $plan_name . '" already exists. Choose a different phase name.';
                    return $out;
                }
                $out['renamed'] = true;
            }
        }

        $diet_type = in_array($draft['diet_type'] ?? '', ['veg', 'nonveg', 'vegan'], true) ? $draft['diet_type'] : $row['diet_type'];
        $goal      = trim((string) ($draft['goal'] ?? ''));
        $duration  = max(1, (int) ($draft['duration'] ?? $row['duration'] ?? 2));
        $calories  = max(0, (int) ($draft['calories'] ?? 0));
        $cols      = ai_draft_to_columns($draft);

        if ($goal === '') {
            $out['error'] = 'The plan needs a goal before it can be saved.';
            return $out;
        }

        try {
            $conn->begin_transaction();
            $stmt = $conn->prepare("UPDATE diet_plans
                                    SET plan_name = ?, diet_type = ?, goal = ?, duration = ?, calories = ?,
                                        breakfast = ?, lunch = ?, snack = ?, dinner = ?
                                    WHERE id = ?");
            $stmt->bind_param(
                'sssiissssi',
                $plan_name,
                $diet_type,
                $goal,
                $duration,
                $calories,
                $cols['breakfast'],
                $cols['lunch'],
                $cols['snack'],
                $cols['dinner'],
                $plan_id
            );
            $stmt->execute();

            // Older plans are linked by name only; make sure this one reaches the portal
            $as = $conn->prepare("INSERT IGNORE INTO diet_plan_assignments (plan_id, member_id) VALUES (?, ?)");
            $as->bind_param('ii', $plan_id, $member_id);
            $as->execute();

            $conn->commit();

            $out['ok']        = true;
            $out['plan_name'] = $plan_name;
            $out['original']  = $row;
        } catch (Throwable $e) {
            $conn->rollback();
            error_log('[AI] plan update failed: ' . $e->getMessage());
            $out['error'] = 'Could not update the plan. Please try again.';
        }

        return $out;
    }
}

if (!function_exists('ai_restore_plan')) {
    /** Puts a plan's content back exactly as captured in $original (Undo). */
    function ai_restore_plan(mysqli $conn, int $plan_id, array $original): bool
    {
        try {
            // The old name is restored too, so undoing a rename really undoes it
            $stmt = $conn->prepare("UPDATE diet_plans
                                    SET plan_name = COALESCE(NULLIF(?, ''), plan_name),
                                        diet_type = ?, goal = ?, duration = ?, calories = ?,
                                        breakfast = ?, lunch = ?, snack = ?, dinner = ?
                                    WHERE id = ?");
            $name      = (string) ($original['plan_name'] ?? '');
            $diet_type = (string) ($original['diet_type'] ?? 'veg');
            $goal      = (string) ($original['goal'] ?? '');
            $duration  = (int) ($original['duration'] ?? 2);
            $calories  = (int) ($original['calories'] ?? 0);
            $b = (string) ($original['breakfast'] ?? '');
            $l = (string) ($original['lunch'] ?? '');
            $s = (string) ($original['snack'] ?? '');
            $d = (string) ($original['dinner'] ?? '');
            $stmt->bind_param('sssiissssi', $name, $diet_type, $goal, $duration, $calories, $b, $l, $s, $d, $plan_id);
            $stmt->execute();
            return true;
        } catch (Throwable $e) {
            error_log('[AI] plan restore failed: ' . $e->getMessage());
            return false;
        }
    }
}
