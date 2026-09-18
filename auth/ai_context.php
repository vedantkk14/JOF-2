<?php
/**
 * auth/ai_context.php
 * ─────────────────────────────────────────────────────────────────
 * Builds the "everything about this member" packet the assistant sees, and
 * computes the calorie/macro targets it must design to.
 *
 * The maths lives here, not in the prompt: an LLM asked to compute a calorie
 * target will produce a plausible number that doesn't survive a calculator.
 * PHP works out BMR/TDEE/macros, and the model is told to hit those figures.
 *
 * Deliberately excluded from the packet: email, phone, payment amounts and
 * progress photos. The assistant never needs them, so they never leave the DB.
 */

if (!function_exists('ai_member_context')) {
    /**
     * @return array|null  null when the member doesn't exist
     */
    function ai_member_context(mysqli $conn, int $member_id): ?array
    {
        $stmt = $conn->prepare("SELECT id, user_id, full_name, age, gender, height, weight,
                                       medical_issues, diet_type, membership, status, personal_training, created_at
                                FROM members WHERE id = ? LIMIT 1");
        $stmt->bind_param('i', $member_id);
        $stmt->execute();
        $member = $stmt->get_result()->fetch_assoc();
        if (!$member) {
            return null;
        }

        $ctx = ['member' => $member];

        // Latest + earliest measurements, so the model can see direction of travel
        $ms = $conn->prepare("SELECT chest_nipple_line, waist_navel_line, thigh_mid, hip_widest_part, recorded_at
                              FROM member_measurements WHERE member_id = ? ORDER BY recorded_at DESC, id DESC LIMIT 1");
        $ms->bind_param('i', $member_id);
        $ms->execute();
        $ctx['measure_latest'] = $ms->get_result()->fetch_assoc() ?: null;

        $ms2 = $conn->prepare("SELECT chest_nipple_line, waist_navel_line, thigh_mid, hip_widest_part, recorded_at
                               FROM member_measurements WHERE member_id = ? ORDER BY recorded_at ASC, id ASC LIMIT 1");
        $ms2->bind_param('i', $member_id);
        $ms2->execute();
        $ctx['measure_first'] = $ms2->get_result()->fetch_assoc() ?: null;

        // Latest self-assessment
        $mt = $conn->prepare("SELECT mood, sleep_quality, energy_level, hunger_craving, recorded_at
                              FROM metrics WHERE member_id = ? ORDER BY recorded_at DESC, id DESC LIMIT 1");
        $mt->bind_param('i', $member_id);
        $mt->execute();
        $ctx['metrics'] = $mt->get_result()->fetch_assoc() ?: null;

        // Current membership (a confirmed row, never a 'Pending Setup' placeholder)
        $mp = $conn->prepare("SELECT membership_type, start_date, end_date
                              FROM member_payments
                              WHERE member_id = ? AND membership_type != 'Pending Setup'
                              ORDER BY created_at DESC, payment_id DESC LIMIT 1");
        $mp->bind_param('i', $member_id);
        $mp->execute();
        $ctx['payment'] = $mp->get_result()->fetch_assoc() ?: null;

        // Training frequency — workout_logs keys on the login account, not the member row
        $ctx['workouts_30d'] = 0;
        if (!empty($member['user_id'])) {
            $wl = $conn->prepare("SELECT COUNT(*) c FROM workout_logs
                                  WHERE user_id = ? AND workout_date >= CURDATE() - INTERVAL 30 DAY");
            $wl->bind_param('i', $member['user_id']);
            $wl->execute();
            $ctx['workouts_30d'] = (int) ($wl->get_result()->fetch_assoc()['c'] ?? 0);
        }

        // Diet-plan history: plans reach a member either through an explicit
        // assignment row or through the "<Client> - <Phase>" naming convention.
        $like = ai_client_name_for_member($conn, $member_id) . ' - %';
        $dp = $conn->prepare("SELECT DISTINCT dp.id, dp.plan_name, dp.goal, dp.diet_type, dp.calories,
                                     dp.duration, dp.breakfast, dp.lunch, dp.snack, dp.dinner, dp.created_at
                              FROM diet_plans dp
                              LEFT JOIN diet_plan_assignments a ON a.plan_id = dp.id
                              WHERE a.member_id = ? OR dp.plan_name LIKE ?
                              ORDER BY dp.id ASC");
        $dp->bind_param('is', $member_id, $like);
        $dp->execute();
        $plans = [];
        $res = $dp->get_result();
        while ($row = $res->fetch_assoc()) {
            $plans[] = $row;
        }
        $ctx['plans'] = $plans;

        return $ctx;
    }
}

if (!function_exists('ai_client_name_for_member')) {
    /**
     * The name diet plans are filed under for this member. Existing plans use
     * whatever the admin typed ("Vedant K."), so prefer the prefix of a plan
     * already linked by assignment, and fall back to their full name.
     */
    function ai_client_name_for_member(mysqli $conn, int $member_id): string
    {
        $stmt = $conn->prepare("SELECT dp.plan_name FROM diet_plans dp
                                JOIN diet_plan_assignments a ON a.plan_id = dp.id
                                WHERE a.member_id = ? ORDER BY dp.id DESC LIMIT 1");
        $stmt->bind_param('i', $member_id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        if ($row && str_contains($row['plan_name'], ' - ')) {
            return trim(explode(' - ', $row['plan_name'])[0]);
        }

        $stmt = $conn->prepare("SELECT full_name FROM members WHERE id = ? LIMIT 1");
        $stmt->bind_param('i', $member_id);
        $stmt->execute();
        return trim($stmt->get_result()->fetch_assoc()['full_name'] ?? '');
    }
}

if (!function_exists('ai_nutrition_targets')) {
    /**
     * Mifflin-St Jeor BMR → TDEE → goal-adjusted calories → macro split.
     *
     * Returns ['usable' => false, 'reason' => '...'] when the profile is missing
     * or implausible, so the caller can ask for a correction instead of building
     * a plan on bad numbers. Real profiles in this database include 321 cm / 23 kg
     * typos, which would otherwise yield a confident, badly wrong calorie target.
     */
    function ai_nutrition_targets(array $ctx, string $goal = ''): array
    {
        $m      = $ctx['member'];
        $weight = (float) ($m['weight'] ?? 0);
        $height = (float) ($m['height'] ?? 0);
        $age    = (int) ($m['age'] ?? 0);
        $gender = strtolower((string) ($m['gender'] ?? ''));

        $missing = [];
        if ($height <= 0) { $missing[] = 'height'; }
        if ($weight <= 0) { $missing[] = 'weight'; }
        if ($age <= 0)    { $missing[] = 'age'; }
        if ($missing) {
            return ['usable' => false, 'reason' => 'Missing from the profile: ' . implode(', ', $missing) . '.'];
        }

        $odd = [];
        if ($height < 120 || $height > 250) { $odd[] = 'height ' . $height . ' cm'; }
        if ($weight < 25 || $weight > 300)  { $odd[] = 'weight ' . $weight . ' kg'; }
        if ($age < 12 || $age > 100)        { $odd[] = 'age ' . $age; }
        if (!$odd) {
            $bmi_check = $weight / (($height / 100) ** 2);
            if ($bmi_check < 10 || $bmi_check > 60) {
                $odd[] = 'height/weight combination (BMI ' . round($bmi_check, 1) . ')';
            }
        }
        if ($odd) {
            return [
                'usable' => false,
                'reason' => 'The profile has values that look wrong: ' . implode(', ', $odd)
                    . '. Correct the member\'s profile before generating a plan.',
            ];
        }

        $bmr = (10 * $weight) + (6.25 * $height) - (5 * $age) + ($gender === 'male' ? 5 : -161);

        // Activity factor from logged sessions in the last 30 days
        $sessions = (int) ($ctx['workouts_30d'] ?? 0);
        $factor = match (true) {
            $sessions >= 20 => 1.725,
            $sessions >= 12 => 1.55,
            $sessions >= 5  => 1.375,
            default         => 1.2,
        };
        $tdee = $bmr * $factor;

        $goal_l = strtolower($goal);
        $target = match (true) {
            str_contains($goal_l, 'fat') || str_contains($goal_l, 'weight loss') || str_contains($goal_l, 'lean') => $tdee - 450,
            str_contains($goal_l, 'muscle') || str_contains($goal_l, 'gain') || str_contains($goal_l, 'bulk')     => $tdee + 350,
            default => $tdee,
        };
        $target = max(1200, round($target / 10) * 10);

        // Protein scales with the goal; fat is a fixed share; carbohydrate fills the gap.
        $protein_per_kg = str_contains($goal_l, 'muscle') || str_contains($goal_l, 'gain') ? 2.0 : 1.8;
        $protein_g = round($weight * $protein_per_kg);
        $fat_g     = round(($target * 0.25) / 9);
        $carbs_g   = max(0, round(($target - ($protein_g * 4) - ($fat_g * 9)) / 4));

        return [
            'usable'          => true,
            'bmr'             => round($bmr),
            'tdee'            => round($tdee),
            'activity_factor' => $factor,
            'sessions_30d'    => $sessions,
            'target_calories' => (int) $target,
            'protein_g'       => (int) $protein_g,
            'fat_g'           => (int) $fat_g,
            'carbs_g'         => (int) $carbs_g,
            'bmi'             => round($weight / (($height / 100) ** 2), 1),
        ];
    }
}

if (!function_exists('ai_apply_target_overrides')) {
    /**
     * Lets the admin overrule the calculator from the chat box.
     *
     * The computed target stops the MODEL inventing numbers; it was never meant
     * to stop the trainer. "Increase calories to 2200", "add 300 calories" and
     * "180 g protein" all take precedence over the formula, and the override is
     * echoed in the prompt so the meals are actually built to it.
     *
     * @return array the targets, with any override applied and 'overridden' set
     */
    function ai_apply_target_overrides(array $targets, string $message): array
    {
        if (empty($targets['usable']) || trim($message) === '') {
            return $targets;
        }

        $msg   = strtolower($message);
        $notes = [];
        $cal   = (int) $targets['target_calories'];

        // Work on the clause around the word "calories" rather than the whole
        // sentence, so "more protein, same calories" isn't read as "more calories".
        if (preg_match('/([^.;,]{0,60})\b(?:k?cals?|calories|calorie)\b([^.;,]{0,40})/', $msg, $m)) {
            $before = $m[1];
            $after  = $m[2];
            $clause = $before . ' ' . $after;

            $up   = '/\b(increase|raise|bump|add|more|higher|up)\b/';
            $down = '/\b(reduce|decrease|lower|less|fewer|cut|drop)\b/';

            if (preg_match('/\b(same|unchanged|keep|maintain|as is)\b/', $clause)) {
                // explicitly asked to leave them alone
            } elseif (preg_match('/\b(?:to|at|=|of)\s*(\d{3,5})\b/', $clause, $n)) {
                $cal = (int) $n[1];
                $notes[] = 'calorie target set to ' . $cal . ' by the admin';
            } elseif (preg_match($up, $clause) && preg_match('/\b(\d{2,4})\b/', $clause, $n) && (int) $n[1] < 1000) {
                $cal += (int) $n[1];
                $notes[] = 'calories increased by ' . (int) $n[1] . ' at the admin\'s request';
            } elseif (preg_match($down, $clause) && preg_match('/\b(\d{2,4})\b/', $clause, $n) && (int) $n[1] < 1000) {
                $cal -= (int) $n[1];
                $notes[] = 'calories reduced by ' . (int) $n[1] . ' at the admin\'s request';
            } elseif (preg_match('/\b(\d{4,5})\b/', $clause, $n)) {
                $cal = (int) $n[1];
                $notes[] = 'calorie target set to ' . $cal . ' by the admin';
            } elseif (preg_match($up, $clause)) {
                $cal += 250;
                $notes[] = 'calories increased at the admin\'s request';
            } elseif (preg_match($down, $clause)) {
                $cal -= 250;
                $notes[] = 'calories reduced at the admin\'s request';
            }
        }

        $cal = max(1000, min(6000, $cal));
        $changed = $cal !== (int) $targets['target_calories'];
        $targets['target_calories'] = $cal;

        // Explicit protein, e.g. "180 g protein"
        $protein = (int) $targets['protein_g'];
        if (preg_match('/\b(\d{2,3})\s*g?\s*(?:of\s*)?protein\b/', $msg, $m)) {
            $protein = (int) $m[1];
            $notes[] = 'protein set to ' . $protein . ' g by the admin';
            $changed = true;
        } elseif (preg_match('/\b(more|higher|increase|raise)\b[^.]{0,20}\bprotein\b/', $msg)) {
            $protein = (int) round($protein * 1.15);
            $notes[] = 'protein increased at the admin\'s request';
            $changed = true;
        }
        $targets['protein_g'] = max(30, min(400, $protein));

        // Fat stays a quarter of intake; carbohydrate absorbs the rest
        $targets['fat_g']   = (int) round(($cal * 0.25) / 9);
        $targets['carbs_g'] = (int) max(0, round(($cal - ($targets['protein_g'] * 4) - ($targets['fat_g'] * 9)) / 4));

        $targets['overridden']    = $changed;
        $targets['override_note'] = implode('; ', $notes);

        return $targets;
    }
}

if (!function_exists('ai_recent_ingredients')) {
    /**
     * The distinct foods a plan used, as a plain list.
     *
     * Handing the model the previous phase in full makes it anchor on that text
     * and produce a find-and-replace of it. Giving it only the ingredient names —
     * as things to move away from — breaks the anchor while keeping what's needed
     * to avoid repeating. Words are ranked by frequency, so staples surface first.
     */
    function ai_recent_ingredients(array $plan, int $limit = 28): string
    {
        $text = strtolower(implode(' ', [
            $plan['breakfast'] ?? '', $plan['lunch'] ?? '', $plan['snack'] ?? '', $plan['dinner'] ?? '',
        ]));
        $text = preg_replace('/\*\*[^*]*\*\*/', ' ', $text);           // our own section headers
        $text = preg_replace('/[^a-z\s-]/u', ' ', $text);              // digits, emoji, punctuation

        // Units, measures and filler carry no signal about what the food actually was
        $stop = array_flip([
            'g', 'kg', 'ml', 'l', 'tsp', 'tbsp', 'cup', 'cups', 'katori', 'glass', 'piece', 'pieces',
            'small', 'medium', 'large', 'half', 'one', 'two', 'three', 'four', 'with', 'and', 'or',
            'the', 'of', 'in', 'on', 'a', 'an', 'to', 'for', 'plus', 'each', 'cooked', 'raw', 'fresh',
            'mixed', 'sliced', 'chopped', 'boiled', 'grilled', 'roasted', 'sauteed', 'steamed', 'low',
            'fat', 'free', 'no', 'added', 'optional', 'total', 'approx', 'kcal', 'calories', 'protein',
            'carbs', 'daily', 'water', 'salt', 'spices', 'seeds', 'powder', 'oil', 'extra', 'lightly',
        ]);

        $freq = [];
        foreach (preg_split('/\s+/', $text, -1, PREG_SPLIT_NO_EMPTY) as $w) {
            $w = trim($w, '-');
            if (mb_strlen($w) < 3 || isset($stop[$w])) {
                continue;
            }
            $freq[$w] = ($freq[$w] ?? 0) + 1;
        }
        arsort($freq);

        return implode(', ', array_slice(array_keys($freq), 0, $limit));
    }
}

if (!function_exists('ai_context_to_text')) {
    /**
     * Renders the packet as the compact text block that goes into the prompt.
     * Roughly 1,500–2,500 tokens, which matters against Groq's per-minute cap.
     *
     * @param bool $for_plan  true when generating a plan: the previous phase is
     *                        reduced to an ingredient list so the model builds
     *                        something new instead of rewording the old one.
     */
    function ai_context_to_text(array $ctx, array $targets, bool $for_plan = false): string
    {
        $m = $ctx['member'];
        $L = [];

        $L[] = '## MEMBER PROFILE';
        $L[] = 'Name: ' . $m['full_name'];
        $L[] = 'Age: ' . ($m['age'] ?: 'not recorded')
            . ' | Gender: ' . ($m['gender'] ?: 'not recorded')
            . ' | Height: ' . ($m['height'] ? $m['height'] . ' cm' : 'not recorded')
            . ' | Weight: ' . ($m['weight'] ? $m['weight'] . ' kg' : 'not recorded');
        $L[] = 'Dietary preference: ' . ($m['diet_type'] ?: 'not recorded');
        $L[] = 'Medical notes (member-reported): ' . (trim((string) $m['medical_issues']) !== '' ? $m['medical_issues'] : 'none reported');
        $L[] = 'Personal training: ' . ((int) $m['personal_training'] === 1 ? 'yes' : 'no');

        if (!empty($ctx['payment'])) {
            $p = $ctx['payment'];
            $L[] = 'Membership: ' . $p['membership_type'] . ' (until ' . $p['end_date'] . ')';
        }

        $L[] = '';
        $L[] = '## CALCULATED TARGETS (authoritative — do not recalculate)';
        if ($targets['usable']) {
            $L[] = 'BMI: ' . $targets['bmi'] . ' | BMR: ' . $targets['bmr'] . ' kcal | TDEE: ' . $targets['tdee']
                . ' kcal (activity factor ' . $targets['activity_factor'] . ', ' . $targets['sessions_30d'] . ' sessions logged in 30 days)';
            $L[] = 'TARGET: ' . $targets['target_calories'] . ' kcal/day — protein ' . $targets['protein_g']
                . ' g, fat ' . $targets['fat_g'] . ' g, carbohydrate ' . $targets['carbs_g'] . ' g';
        } else {
            $L[] = 'CANNOT BE CALCULATED. ' . ($targets['reason'] ?? '');
            $L[] = 'Tell the admin this plainly and ask them to fix the profile. Do NOT estimate calorie or macro targets yourself.';
        }

        if (!empty($ctx['measure_latest'])) {
            $la = $ctx['measure_latest'];
            $fi = $ctx['measure_first'];
            $L[] = '';
            $L[] = '## MEASUREMENTS';
            $L[] = 'Latest (' . date('d M Y', strtotime($la['recorded_at'])) . '): chest ' . $la['chest_nipple_line']
                . ', waist ' . $la['waist_navel_line'] . ', hip ' . $la['hip_widest_part'] . ', thigh ' . $la['thigh_mid'] . ' (cm)';
            if ($fi && $fi['recorded_at'] !== $la['recorded_at']) {
                $L[] = 'Change since ' . date('d M Y', strtotime($fi['recorded_at'])) . ': waist '
                    . sprintf('%+.1f', (float) $la['waist_navel_line'] - (float) $fi['waist_navel_line']) . ' cm, chest '
                    . sprintf('%+.1f', (float) $la['chest_nipple_line'] - (float) $fi['chest_nipple_line']) . ' cm';
            }
        }

        if (!empty($ctx['metrics'])) {
            $mt = $ctx['metrics'];
            $L[] = '';
            $L[] = '## SELF-ASSESSMENT (1–10, recorded ' . date('d M Y', strtotime($mt['recorded_at'])) . ')';
            $L[] = 'Mood ' . $mt['mood'] . ' | Sleep ' . $mt['sleep_quality']
                . ' | Energy ' . $mt['energy_level'] . ' | Hunger/cravings ' . $mt['hunger_craving'];
        }

        $L[] = '';
        $L[] = '## DIET PLAN HISTORY';
        if (empty($ctx['plans'])) {
            $L[] = 'No previous plans — this would be their first.';
        } else {
            foreach ($ctx['plans'] as $p) {
                $L[] = '- ' . $p['plan_name'] . ' | ' . $p['goal'] . ' | ' . $p['calories'] . ' kcal | '
                    . $p['diet_type'] . ' | created ' . date('d M Y', strtotime($p['created_at']));
            }
            $last = end($ctx['plans']);
            $L[] = '';
            if ($for_plan) {
                // Names only, never the text — see ai_recent_ingredients()
                $L[] = '## ALREADY USED IN "' . $last['plan_name'] . '" — DO NOT BUILD THE NEW PHASE AROUND THESE';
                $L[] = ai_recent_ingredients($last);
                $L[] = 'Choose different main proteins, different grains and different vegetables this time.';
            } else {
                $L[] = '## MOST RECENT PHASE IN FULL — "' . $last['plan_name'] . '"';
                foreach (['breakfast', 'lunch', 'snack', 'dinner'] as $col) {
                    if (trim((string) $last[$col]) !== '') {
                        $L[] = $last[$col];
                    }
                }
            }
        }

        return implode("\n", $L);
    }
}
