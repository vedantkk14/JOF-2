<?php
require_once __DIR__ . '/../../auth/auth_check.php';
require_role(['user']);
$user = get_session_user();

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../auth/profile_helper.php';

$uid       = (int) $user['id'];
$csrf      = generate_csrf_token();
$photo_deleted = isset($_GET['photo_deleted']);
$saved     = isset($_GET['saved']) || $photo_deleted;
$errors    = [];
$notice    = '';

/* ────────────────── DELETE ONE PROGRESS PHOTO (manual only) ────────────────── */
// Posted only by the standalone #photoDelForm, which lives OUTSIDE the profile
// form — a delete can never ride along with a normal "Save Profile".
// `del` = "<column>:<member_measurements.id>"
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_photo') {
    if (hash_equals($_SESSION['_csrf_token'] ?? '', $_POST['_csrf_token'] ?? '')) {
        $del_mid = get_user_member_id($conn, $uid);
        [$del_col, $del_rid] = array_pad(explode(':', (string) ($_POST['del'] ?? ''), 2), 2, '');
        $del_rid = (int) $del_rid;
        $allowed_cols = ['front_view_image', 'side_view_image', 'back_view_image'];

        if ($del_mid && $del_rid && in_array($del_col, $allowed_cols, true)) {
            $s = mysqli_prepare($conn, "SELECT `$del_col` AS fname FROM member_measurements WHERE id = ? AND member_id = ?");
            mysqli_stmt_bind_param($s, 'ii', $del_rid, $del_mid);
            mysqli_stmt_execute($s);
            $row = mysqli_fetch_assoc(mysqli_stmt_get_result($s));

            if ($row && !empty($row['fname'])) {
                // Clear every snapshot of this member that points at the same file
                // (older saves could reference one photo from several rows).
                $u = mysqli_prepare($conn, "UPDATE member_measurements SET `$del_col` = NULL WHERE member_id = ? AND `$del_col` = ?");
                mysqli_stmt_bind_param($u, 'is', $del_mid, $row['fname']);
                mysqli_stmt_execute($u);

                $path = __DIR__ . '/../../uploads/progress_photos/' . basename($row['fname']);
                if (is_file($path)) {
                    @unlink($path);
                }
            }
        }
    }
    header('Location: user_profile.php?photo_deleted=1#sec-photos');
    exit;
}

/* ─────────────────────────── SAVE ─────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // CSRF (validate only — long-lived page)
    if (!hash_equals($_SESSION['_csrf_token'] ?? '', $_POST['_csrf_token'] ?? '')) {
        $errors[] = 'Your session expired. Please refresh the page and try again.';
    } else {
        $full_name = trim($_POST['full_name'] ?? '');
        $phone     = preg_replace('/\D/', '', $_POST['phone_number'] ?? '');
        $email     = trim($_POST['email'] ?? '');
        $gender    = $_POST['gender'] ?? '';
        $diet      = $_POST['diet_type'] ?? '';
        $age       = (int) ($_POST['age'] ?? 0);
        $height    = (float) ($_POST['height'] ?? 0);
        $weight    = (float) ($_POST['weight'] ?? 0);
        $medical   = trim($_POST['medical_issues'] ?? '');

        $clamp = fn($v) => max(1, min(10, (int) $v));
        $mood   = $clamp($_POST['mood'] ?? 5);
        $sleep  = $clamp($_POST['sleep_quality'] ?? 5);
        $energy = $clamp($_POST['energy_level'] ?? 5);
        $hunger = $clamp($_POST['hunger_craving'] ?? 5);

        $chest = (float) ($_POST['chest'] ?? 0);
        $waist = (float) ($_POST['waist'] ?? 0);
        $hips  = (float) ($_POST['hips'] ?? 0);
        $thigh = (float) ($_POST['thigh'] ?? 0);

        // ── light validation ──
        if ($full_name === '' || !preg_match('/^[A-Za-z]{2,}(?:\s+[A-Za-z]+)+$/', $full_name)) {
            $errors[] = 'Enter your full name (first and last, letters only).';
        }
        if ($phone !== '' && !preg_match('/^\d{10}$/', $phone)) {
            $errors[] = 'Phone number must be exactly 10 digits.';
        }
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Enter a valid email address.';
        }
        if ($age !== 0 && ($age < 12 || $age > 100)) {
            $errors[] = 'Enter a realistic age.';
        }
        if ($height !== 0.0 && ($height < 80 || $height > 250)) {
            $errors[] = 'Enter height in cm (80–250).';
        }
        if ($weight !== 0.0 && ($weight < 25 || $weight > 350)) {
            $errors[] = 'Enter weight in kg (25–350).';
        }

        if (!$errors) {
            $mid = get_user_member_id($conn, $uid);

            // ── members: create or update ──
            if ($mid) {
                $sql = "UPDATE members SET full_name=?, phone_number=?, email=?, gender=?, diet_type=?,
                        age=?, height=?, weight=?, medical_issues=? WHERE id=? AND user_id=?";
                $st = mysqli_prepare($conn, $sql);
                mysqli_stmt_bind_param(
                    $st,
                    'sssssiddsii',
                    $full_name, $phone, $email, $gender, $diet,
                    $age, $height, $weight, $medical, $mid, $uid
                );
                mysqli_stmt_execute($st);
            } else {
                $sql = "INSERT INTO members
                        (user_id, full_name, phone_number, email, gender, diet_type, membership, status,
                         personal_training, age, height, weight, medical_issues, created_at)
                        VALUES (?, ?, ?, ?, ?, ?, 'Pending', 'inactive', 0, ?, ?, ?, ?, NOW())";
                $st = mysqli_prepare($conn, $sql);
                mysqli_stmt_bind_param(
                    $st,
                    'isssssidds',
                    $uid, $full_name, $phone, $email, $gender, $diet,
                    $age, $height, $weight, $medical
                );
                mysqli_stmt_execute($st);
                $mid = mysqli_insert_id($conn);
            }

            // ── metrics: append a new snapshot only if it changed ──
            $s = mysqli_prepare($conn, "SELECT mood, sleep_quality, energy_level, hunger_craving
                FROM metrics WHERE member_id=? ORDER BY recorded_at DESC, id DESC LIMIT 1");
            mysqli_stmt_bind_param($s, 'i', $mid);
            mysqli_stmt_execute($s);
            $prev = mysqli_fetch_assoc(mysqli_stmt_get_result($s));
            $changed = !$prev
                || (int) $prev['mood'] !== $mood
                || (int) $prev['sleep_quality'] !== $sleep
                || (int) $prev['energy_level'] !== $energy
                || (int) $prev['hunger_craving'] !== $hunger;
            if ($changed) {
                $s = mysqli_prepare($conn, "INSERT INTO metrics
                    (member_id, mood, sleep_quality, hunger_craving, energy_level, recorded_at)
                    VALUES (?, ?, ?, ?, ?, NOW())");
                mysqli_stmt_bind_param($s, 'iiiii', $mid, $mood, $sleep, $hunger, $energy);
                mysqli_stmt_execute($s);
            }

            // ── latest existing snapshot (used to detect real measurement changes) ──
            $s = mysqli_prepare($conn, "SELECT * FROM member_measurements WHERE member_id=? ORDER BY recorded_at DESC, id DESC LIMIT 1");
            mysqli_stmt_bind_param($s, 'i', $mid);
            mysqli_stmt_execute($s);
            $latest_measure = mysqli_fetch_assoc(mysqli_stmt_get_result($s));

            // ── progress photos (optional) — each upload is ADDED to the history ──
            $upload_dir = __DIR__ . '/../../uploads/progress_photos/';
            $save_photo = function (string $field) use ($upload_dir, $mid): ?string {
                if (empty($_FILES[$field]['name']) || ($_FILES[$field]['error'] ?? 1) !== 0) {
                    return null;
                }
                if ($_FILES[$field]['size'] > 10 * 1024 * 1024) {
                    return null;
                }
                $ext = strtolower(pathinfo($_FILES[$field]['name'], PATHINFO_EXTENSION));
                if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)) {
                    return null;
                }
                $name = $field . '_' . $mid . '_' . time() . '_' . bin2hex(random_bytes(3)) . '.' . $ext;
                if (!is_dir($upload_dir)) {
                    @mkdir($upload_dir, 0777, true);
                }
                return move_uploaded_file($_FILES[$field]['tmp_name'], $upload_dir . $name) ? $name : null;
            };
            $front = $save_photo('front_view');
            $side  = $save_photo('side_view');
            $back  = $save_photo('back_view');
            $has_photo = $front || $side || $back;

            // ── measurements + photos ──
            // A save that changes the measurements or uploads photos writes a NEW snapshot
            // row. Existing rows are never updated, so every earlier photo stays in the
            // history until the member deletes it. A snapshot only holds the photos
            // uploaded in that save; the latest photo per view is read across all rows.
            $has_measure = $chest > 0 && $waist > 0 && $hips > 0 && $thigh > 0;
            $measurements_changed = $has_measure && (!$latest_measure
                || number_format((float) $latest_measure['chest_nipple_line'], 1) !== number_format($chest, 1)
                || number_format((float) $latest_measure['waist_navel_line'], 1) !== number_format($waist, 1)
                || number_format((float) $latest_measure['hip_widest_part'], 1) !== number_format($hips, 1)
                || number_format((float) $latest_measure['thigh_mid'], 1) !== number_format($thigh, 1));

            if (!$has_measure && $latest_measure) {
                // Photos-only save: the new snapshot keeps the current measurements
                $chest = (float) $latest_measure['chest_nipple_line'];
                $waist = (float) $latest_measure['waist_navel_line'];
                $thigh = (float) $latest_measure['thigh_mid'];
                $hips  = (float) $latest_measure['hip_widest_part'];
            }

            if ($measurements_changed || $has_photo) {
                if ($has_measure || $latest_measure) {
                    $s = mysqli_prepare($conn, "INSERT INTO member_measurements
                        (member_id, chest_nipple_line, waist_navel_line, thigh_mid, hip_widest_part,
                         front_view_image, side_view_image, back_view_image, recorded_at)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())");
                    mysqli_stmt_bind_param($s, 'iddddsss', $mid, $chest, $waist, $thigh, $hips, $front, $side, $back);
                    mysqli_stmt_execute($s);
                } else {
                    $notice = 'Add your body measurements to save progress photos.';
                    foreach ([$front, $side, $back] as $orphan) {
                        if ($orphan) {
                            @unlink($upload_dir . $orphan);
                        }
                    }
                }
            }

            header('Location: user_profile.php?saved=1' . ($notice ? '&notice=' . urlencode($notice) : ''));
            exit;
        }
    }
}

/* ─────────────────────────── LOAD ─────────────────────────── */
$profile = get_user_profile($conn, $uid);
$m  = $profile['member']  ?? [];
$mt = $profile['metrics'] ?? [];
$ms = $profile['measure'] ?? [];
$status = user_profile_status($conn, $uid);

// On a validation error, keep what the user just typed instead of DB values
if ($errors && $_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach (['full_name', 'phone_number', 'email', 'gender', 'diet_type', 'age', 'height', 'weight', 'medical_issues'] as $k) {
        if (isset($_POST[$k])) {
            $m[$k] = $_POST[$k];
        }
    }
    foreach (['mood', 'sleep_quality', 'energy_level', 'hunger_craving'] as $k) {
        if (isset($_POST[$k])) {
            $mt[$k] = (int) $_POST[$k];
        }
    }
    foreach (['chest' => 'chest_nipple_line', 'waist' => 'waist_navel_line', 'hips' => 'hip_widest_part', 'thigh' => 'thigh_mid'] as $post => $col) {
        if (isset($_POST[$post])) {
            $ms[$col] = $_POST[$post];
        }
    }
}

$miss = fn(string $label) => in_array($label, $status['missing'], true);

// sensible defaults for a brand-new profile
$v = fn($k, $d = '') => htmlspecialchars((string) ($m[$k] ?? $d), ENT_QUOTES, 'UTF-8');
$acct_name  = html_entity_decode($user['name'], ENT_QUOTES);
$def_name   = $m['full_name'] ?? $acct_name;
$def_email  = $m['email'] ?? '';
if ($def_email === '') {
    // fall back to the login account's email
    $s = mysqli_prepare($conn, "SELECT email FROM user_data WHERE id = ?");
    mysqli_stmt_bind_param($s, 'i', $uid);
    mysqli_stmt_execute($s);
    $def_email = mysqli_fetch_assoc(mysqli_stmt_get_result($s))['email'] ?? '';
}
$initials = strtoupper(substr(preg_replace('/[^A-Za-z ]/', '', $acct_name), 0, 1)
    . (strpos(trim($acct_name), ' ') !== false ? substr(strrchr(trim($acct_name), ' '), 1, 1) : ''));
$initials = $initials ?: 'U';

$photo_dir = '../../uploads/progress_photos/';
$mv = fn($k) => (int) ($mt[$k] ?? 5);
$notice = $notice ?: ($_GET['notice'] ?? '');

// Full photo history, one entry per upload session (oldest → newest). Older saves
// copied the same file into several snapshot rows, so each file is only counted the
// first time it appears. Powers the upload slots (latest per view), Before/After and
// the dated history timeline.
$photo_sessions = [];
$photo_by_view  = ['front_view_image' => [], 'side_view_image' => [], 'back_view_image' => []];
if (!empty($profile['member_id'])) {
    $ph = mysqli_prepare($conn, "SELECT id, front_view_image, side_view_image, back_view_image, recorded_at
        FROM member_measurements
        WHERE member_id = ?
          AND (front_view_image IS NOT NULL OR side_view_image IS NOT NULL OR back_view_image IS NOT NULL)
        ORDER BY recorded_at ASC, id ASC");
    mysqli_stmt_bind_param($ph, 'i', $profile['member_id']);
    mysqli_stmt_execute($ph);
    $ph_res = mysqli_stmt_get_result($ph);
    $seen_files = [];
    while ($ph_row = mysqli_fetch_assoc($ph_res)) {
        $session_photos = [];
        foreach (array_keys($photo_by_view) as $col) {
            $file = $ph_row[$col] ?? '';
            if ($file === '' || isset($seen_files[$file]) || !is_file(__DIR__ . '/../../uploads/progress_photos/' . basename($file))) {
                continue;
            }
            $seen_files[$file] = true;
            $session_photos[$col] = $file;
            $photo_by_view[$col][] = ['id' => (int) $ph_row['id'], 'file' => $file, 'date' => $ph_row['recorded_at']];
        }
        if ($session_photos) {
            $photo_sessions[] = ['id' => (int) $ph_row['id'], 'date' => $ph_row['recorded_at'], 'photos' => $session_photos];
        }
    }
}
$latest_photo = array_map(fn(array $list) => $list ? $list[count($list) - 1] : null, $photo_by_view);
$photo_total  = array_sum(array_map('count', $photo_by_view));

// section completeness (for checklist + per-section pills)
$sec_done = [
    'personal'   => !$miss('Full name') && !$miss('Phone number') && !$miss('Email address') && !$miss('Gender') && !$miss('Diet preference'),
    'health'     => !$miss('Age') && !$miss('Height') && !$miss('Weight'),
    'assessment' => !$miss('Self-assessment'),
    'measure'    => !$miss('Body measurements'),
    'photos'     => $photo_total > 0,
];
$ring_c = 2 * M_PI * 52;
$ring_off = $ring_c * (1 - $status['percent'] / 100);

$ACTIVE_NAV = 'profile';
$PAGE_TITLE = 'My Profile';
require __DIR__ . '/_shell_top.php';
?>
<style>
        :root {
            --bg: #F4F5F7;
            --card: #FFFFFF;
            --border: #ECEEF1;
            --ink: #1E2230;
            --ink-soft: #6B7280;
            --ink-faint: #9CA3AF;
            --coral: #FF6B47;
            --coral-dark: #E5502B;
            --coral-tint: #FFEDE7;
            --green: #1FA971;
            --green-tint: #E7F8F0;
            --amber: #F0A93A;
            --amber-tint: #FDF3E2;
            --red: #E5484D;
            --red-tint: #FCEBEC;
            --shadow: 0 1px 2px rgba(20, 20, 30, .04), 0 8px 24px -12px rgba(20, 20, 30, .08);
            --radius: 18px;
            --sidebar-w: 264px;
            --sidebar-w-collapsed: 84px;
        }

        * { box-sizing: border-box; margin: 0; padding: 0; min-width: 0; }

        html, body { max-width: 100%; overflow-x: hidden; }
        img { max-width: 100%; }

        body {
            font-family: 'Inter', sans-serif;
            background: var(--bg);
            color: var(--ink);
            -webkit-font-smoothing: antialiased;
        }

        h1, h2, h3, .brand-name { font-family: 'Sora', sans-serif; }
        button, input, select, textarea { font-family: inherit; }
        button { cursor: pointer; }
        a { text-decoration: none; color: inherit; }
        ul { list-style: none; }

        /* ===== Shell / sidebar / topbar ===== */
        .shell { display: flex; min-height: 100vh; }

        .sidebar {
            width: var(--sidebar-w); flex-shrink: 0; background: var(--card);
            border-right: 1px solid var(--border); display: flex; flex-direction: column;
            padding: 22px 16px; transition: width .28s ease, transform .28s ease;
            position: relative; z-index: 40;
        }

        .shell.collapsed .sidebar { width: var(--sidebar-w-collapsed); }
        .brand { display: flex; align-items: center; gap: 12px; padding: 6px 46px 26px 10px; }

        .brand-mark {
            width: 40px; height: 40px; flex-shrink: 0; background: #fff; border: 1px solid var(--border);
            border-radius: 12px; display: flex; align-items: center; justify-content: center; overflow: hidden;
        }

        .brand-mark img { width: 26px; height: 26px; object-fit: contain; display: block; }
        .brand-text { overflow: hidden; white-space: nowrap; }
        .brand-name { font-size: 16px; font-weight: 700; line-height: 1.15; }
        .brand-sub { font-size: 11.5px; color: var(--ink-soft); font-weight: 500; }
        .shell.collapsed .brand-text { display: none; }

        .nav-group { flex: 1; display: flex; flex-direction: column; gap: 2px; margin-top: 6px; }

        .nav-item {
            display: flex; align-items: center; gap: 14px; padding: 12px 14px; border-radius: 12px;
            color: var(--ink-soft); font-weight: 600; font-size: 14.5px; white-space: nowrap;
            overflow: hidden; transition: background .15s ease, color .15s ease;
        }

        .nav-item svg { flex-shrink: 0; width: 20px; height: 20px; }
        .nav-item:hover { background: var(--coral-tint); color: var(--coral-dark); }
        .nav-item.active { background: var(--coral); color: #fff; }
        .shell.collapsed .nav-label { display: none; }
        .shell.collapsed .nav-item { justify-content: center; padding: 12px; }
        .nav-bottom { border-top: 1px solid var(--border); padding-top: 10px; margin-top: 10px; }

        .rail-toggle {
            position: absolute; top: 16px; right: 12px; z-index: 3; width: 30px; height: 30px;
            display: flex; align-items: center; justify-content: center; border: 1px solid var(--border);
            background: var(--card); border-radius: 9px; color: var(--ink-soft);
            transition: background .15s ease, color .15s ease, border-color .15s ease;
        }

        .rail-toggle:hover { background: var(--coral-tint); color: var(--coral-dark); border-color: var(--coral); }
        .rail-toggle svg { width: 16px; height: 16px; }
        .rail-toggle .ic-close { display: none; }
        .rail-toggle .ic-collapse { transition: transform .28s ease; }
        .shell.collapsed .rail-toggle .ic-collapse { transform: rotate(180deg); }
        .shell.collapsed .brand { flex-direction: column; gap: 10px; padding: 50px 8px 24px; align-items: center; }
        .shell.collapsed .rail-toggle { top: 14px; right: 50%; transform: translateX(50%); }

        .drawer-overlay { display: none; position: fixed; inset: 0; background: rgba(20, 22, 30, .45); z-index: 35; }
        .drawer-overlay.show { display: block; }

        .hamburger {
            display: none; width: 40px; height: 40px; align-items: center; justify-content: center;
            border-radius: 10px; border: 1px solid var(--border); background: var(--card);
        }

        .main { flex: 1; min-width: 0; padding: 22px 30px 130px; }

        .topbar { display: flex; align-items: center; justify-content: space-between; margin-bottom: 20px; gap: 14px; }
        .topbar-left { display: flex; align-items: center; gap: 14px; }
        .page-title { font-size: 20px; font-weight: 700; }
        .topbar-right { display: flex; align-items: center; gap: 14px; }

        .profile-chip {
            display: flex; align-items: center; gap: 10px; padding: 5px 12px 5px 5px; border-radius: 30px;
            background: var(--card); border: 1px solid var(--border);
        }

        .avatar {
            width: 34px; height: 34px; border-radius: 50%; background: var(--coral-tint); color: var(--coral-dark);
            font-weight: 700; font-size: 13px; display: flex; align-items: center; justify-content: center;
        }

        .profile-name { font-size: 13.5px; font-weight: 600; line-height: 1.1; }
        .profile-role { font-size: 11px; color: var(--ink-soft); }

        /* ===== Layout ===== */
        .pf-layout { display: grid; grid-template-columns: 316px minmax(0, 1fr); gap: 22px; align-items: start; }

        /* ===== Summary card ===== */
        .pf-summary {
            position: sticky; top: 22px; background: var(--card); border: 1px solid var(--border);
            border-radius: var(--radius); box-shadow: var(--shadow); overflow: hidden;
        }

        .pf-sum-head {
            background: linear-gradient(150deg, #FF7A57, #E5502B); color: #fff; padding: 22px 20px 20px;
            display: flex; align-items: center; gap: 14px;
        }

        .pf-sum-avatar {
            width: 52px; height: 52px; border-radius: 16px; background: rgba(255, 255, 255, .22);
            display: flex; align-items: center; justify-content: center; font-family: 'Sora', sans-serif;
            font-weight: 800; font-size: 19px; flex-shrink: 0;
        }

        .pf-sum-head .nm { font-family: 'Sora', sans-serif; font-weight: 700; font-size: 16px; line-height: 1.2; }
        .pf-sum-head .rl { font-size: 12px; opacity: .9; margin-top: 2px; }

        .pf-ring-wrap { padding: 22px 20px 6px; display: flex; justify-content: center; }
        .pf-ring { position: relative; width: 130px; height: 130px; }
        .pf-ring svg { transform: rotate(-90deg); }
        .pf-ring .lbl { position: absolute; inset: 0; display: flex; flex-direction: column; align-items: center; justify-content: center; }
        .pf-ring .pct { font-family: 'Sora', sans-serif; font-weight: 800; font-size: 26px; color: var(--coral-dark); }
        .pf-ring.done .pct { color: var(--green); }
        .pf-ring .sub { font-size: 11px; color: var(--ink-soft); }

        .pf-checklist { padding: 8px 14px 18px; }

        .pf-chk {
            display: flex; align-items: center; gap: 11px; width: 100%; text-align: left;
            padding: 9px 10px; border-radius: 10px; border: none; background: transparent;
            font-size: 13px; font-weight: 600; color: var(--ink-soft); transition: background .15s ease;
        }

        .pf-chk:hover { background: var(--bg); color: var(--ink); }

        .pf-chk .mk {
            width: 20px; height: 20px; border-radius: 50%; flex-shrink: 0; display: flex;
            align-items: center; justify-content: center; border: 2px solid var(--border); background: #fff;
        }

        .pf-chk .mk svg { width: 11px; height: 11px; opacity: 0; }
        .pf-chk.ok { color: var(--ink); }
        .pf-chk.ok .mk { background: var(--green); border-color: var(--green); color: #fff; }
        .pf-chk.ok .mk svg { opacity: 1; }
        .pf-chk .opt { margin-left: auto; font-size: 10px; font-weight: 700; color: var(--ink-faint); text-transform: uppercase; }

        .pf-mini {
            display: flex; gap: 10px; padding: 0 18px 20px;
        }

        .pf-mini .box {
            flex: 1; background: var(--bg); border-radius: 12px; padding: 11px 12px; text-align: center;
        }

        .pf-mini .box b { display: block; font-family: 'Sora', sans-serif; font-weight: 800; font-size: 16px; }
        .pf-mini .box small { font-size: 10.5px; color: var(--ink-soft); }

        /* ===== Section tabs ===== */
        .pf-tabs {
            position: sticky; top: 0; z-index: 20; display: flex; gap: 6px; padding: 12px 0;
            background: linear-gradient(var(--bg) 70%, transparent); overflow-x: auto; scrollbar-width: none;
        }

        .pf-tabs::-webkit-scrollbar { display: none; }

        .pf-tab {
            flex-shrink: 0; padding: 8px 14px; border-radius: 10px; border: 1px solid var(--border);
            background: var(--card); font-size: 12.5px; font-weight: 700; color: var(--ink-soft);
            display: inline-flex; align-items: center; gap: 7px; transition: all .15s ease;
        }

        .pf-tab .dot { width: 6px; height: 6px; border-radius: 50%; background: var(--amber); }
        .pf-tab.ok .dot { background: var(--green); }
        .pf-tab:hover { color: var(--ink); }
        .pf-tab.active { background: var(--coral); border-color: var(--coral); color: #fff; }
        .pf-tab.active .dot { background: #fff; }

        /* ===== Section cards ===== */
        .pf-card {
            background: var(--card); border: 1px solid var(--border); border-radius: var(--radius);
            box-shadow: var(--shadow); padding: 24px; margin-bottom: 16px; scroll-margin-top: 66px;
        }

        .pf-card-head { display: flex; align-items: flex-start; gap: 13px; margin-bottom: 20px; }

        .pf-card-head .no {
            width: 30px; height: 30px; border-radius: 9px; flex-shrink: 0; background: var(--coral-tint);
            color: var(--coral-dark); font-family: 'Sora', sans-serif; font-weight: 800; font-size: 14px;
            display: flex; align-items: center; justify-content: center;
        }

        .pf-card-head h2 { font-size: 15.5px; font-weight: 700; }
        .pf-card-head p { font-size: 12px; color: var(--ink-soft); margin-top: 2px; }

        .pf-card-head .tag {
            margin-left: auto; flex-shrink: 0; font-size: 11px; font-weight: 800; padding: 4px 10px;
            border-radius: 20px; background: var(--amber-tint); color: #92600c;
        }

        .pf-card-head .tag.ok { background: var(--green-tint); color: #0f7a53; }

        .grid2 { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 16px; }
        .grid2 .full { grid-column: 1 / -1; }

        .field label {
            display: flex; align-items: center; gap: 8px; font-size: 12px; font-weight: 700;
            color: var(--ink-soft); margin-bottom: 7px; text-transform: uppercase; letter-spacing: .03em;
        }

        .field label .need {
            font-size: 9.5px; font-weight: 800; letter-spacing: .04em; color: var(--coral-dark);
            background: var(--coral-tint); padding: 2px 7px; border-radius: 20px;
        }

        .field input[type=text],
        .field input[type=tel],
        .field input[type=email],
        .field input[type=number],
        .field textarea {
            width: 100%; padding: 12px 13px; border: 1.5px solid var(--border); border-radius: 11px;
            font-size: 14px; color: var(--ink); background: #fff; outline: none;
            transition: border-color .15s ease, box-shadow .15s ease;
        }

        .field textarea { resize: vertical; min-height: 84px; }

        .field input:focus,
        .field textarea:focus { border-color: var(--coral); box-shadow: 0 0 0 3px var(--coral-tint); }

        .field.need-fill input,
        .field.need-fill .seg,
        .field.need-fill textarea { border-color: #f6c8a8; }

        .field .hint { font-size: 11.5px; color: var(--ink-faint); margin-top: 6px; }

        /* segmented control */
        .seg {
            display: flex; gap: 4px; background: var(--bg); border: 1.5px solid var(--border);
            border-radius: 12px; padding: 4px; flex-wrap: wrap;
        }

        .seg-opt { flex: 1 0 auto; }
        .seg-opt input { position: absolute; opacity: 0; pointer-events: none; }

        .seg-opt span {
            display: block; text-align: center; padding: 9px 12px; border-radius: 9px; font-size: 13px;
            font-weight: 600; color: var(--ink-soft); white-space: nowrap; transition: all .15s ease;
        }

        .seg-opt:hover span { color: var(--ink); }
        .seg-opt input:checked + span { background: var(--coral); color: #fff; box-shadow: var(--shadow); }
        .seg-opt input:focus-visible + span { outline: 2px solid var(--coral-dark); outline-offset: 2px; }

        /* sliders */
        .scale { margin-bottom: 18px; }
        .scale:last-child { margin-bottom: 0; }

        .scale-top { display: flex; justify-content: space-between; align-items: baseline; margin-bottom: 9px; }
        .scale-top .nm { font-size: 13.5px; font-weight: 600; }
        .scale-top .rd { font-size: 12px; font-weight: 700; color: var(--coral-dark); }
        .scale-top .rd b { font-family: 'Sora', sans-serif; font-size: 15px; }

        input[type=range] {
            width: 100%; -webkit-appearance: none; appearance: none; height: 8px; border-radius: 99px;
            background: linear-gradient(90deg, var(--coral) var(--p, 50%), var(--coral-tint) var(--p, 50%));
            outline: none;
        }

        input[type=range]::-webkit-slider-thumb {
            -webkit-appearance: none; appearance: none; width: 22px; height: 22px; border-radius: 50%;
            background: #fff; border: 3px solid var(--coral); box-shadow: var(--shadow); cursor: pointer;
        }

        input[type=range]::-moz-range-thumb {
            width: 18px; height: 18px; border-radius: 50%; background: #fff; border: 3px solid var(--coral); cursor: pointer;
        }

        .scale-foot {
            display: flex; justify-content: space-between; font-size: 10.5px; color: var(--ink-faint);
            margin-top: 6px; font-weight: 600;
        }

        /* BMI tile */
        .bmi-tile {
            display: flex; align-items: center; gap: 14px; background: var(--bg); border-radius: 12px; padding: 14px 16px;
        }

        .bmi-tile .n { font-family: 'Sora', sans-serif; font-weight: 800; font-size: 26px; line-height: 1; }
        .bmi-tile .c { font-size: 12px; font-weight: 700; color: var(--ink-soft); }
        .bmi-tile .c small { display: block; font-weight: 500; color: var(--ink-faint); margin-top: 2px; font-size: 11px; }

        /* photos */
        .photo-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 14px; }
        .photo-cell { }

        .photo-slot {
            position: relative; aspect-ratio: 3 / 4; border-radius: 14px; overflow: hidden;
            border: 1.5px dashed var(--border); background: var(--bg); display: flex; align-items: center;
            justify-content: center; cursor: pointer; transition: border-color .15s ease;
        }

        .photo-slot:hover { border-color: var(--coral); }
        .photo-slot input { position: absolute; inset: 0; opacity: 0; cursor: pointer; }
        .photo-slot img { width: 100%; height: 100%; object-fit: cover; }

        .photo-slot .ph { text-align: center; color: var(--ink-faint); font-size: 12px; font-weight: 700; }
        .photo-slot .ph svg { width: 24px; height: 24px; margin-bottom: 6px; }

        .photo-slot .ov {
            position: absolute; inset: 0; background: rgba(15, 23, 42, .55); color: #fff; font-size: 12px;
            font-weight: 700; display: flex; align-items: center; justify-content: center; gap: 6px;
            opacity: 0; transition: opacity .15s ease;
        }

        .photo-slot .ov svg { width: 14px; height: 14px; }
        .photo-slot:hover .ov { opacity: 1; }
        .photo-cap { text-align: center; font-size: 12px; font-weight: 700; color: var(--ink-soft); margin-top: 7px; }

        /* Before / After comparison + photo history */
        .ba-wrap, .ph-history { margin-top: 26px; padding-top: 22px; border-top: 1px solid var(--border); }
        .ba-title { font-size: 14.5px; font-weight: 700; color: var(--ink); margin-bottom: 3px; }
        .ba-sub { font-size: 12.5px; color: var(--ink-soft); margin-bottom: 16px; }

        .ba-row { margin-bottom: 20px; }
        .ba-row:last-child { margin-bottom: 0; }
        .ba-label {
            display: flex; align-items: center; gap: 10px; font-size: 13px; font-weight: 700;
            color: var(--ink); margin-bottom: 8px;
        }
        .ba-days {
            font-size: 11px; font-weight: 700; color: var(--coral-dark); background: var(--coral-tint);
            padding: 2px 9px; border-radius: 20px;
        }

        .ba-pair { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
        .ba-cell { position: relative; border-radius: 14px; overflow: hidden; aspect-ratio: 3 / 4; background: var(--bg); }
        .ba-cell img { width: 100%; height: 100%; object-fit: cover; display: block; }
        .ba-tag {
            position: absolute; left: 8px; bottom: 8px; font-size: 10px; font-weight: 800; letter-spacing: .03em;
            padding: 4px 9px; border-radius: 20px; background: rgba(15, 23, 42, .72); color: #fff;
        }
        .ba-tag.after { background: rgba(31, 169, 113, .88); }

        .ba-cell img[data-lightbox] { cursor: zoom-in; }
        .photo-cap small { display: block; font-size: 11px; font-weight: 600; color: var(--ink-faint); margin-top: 2px; }
        .photo-note { font-size: 12px; color: var(--ink-soft); margin-top: 12px; }

        /* Photo history timeline (newest first) */
        .ph-history-head { display: flex; align-items: flex-start; justify-content: space-between; gap: 12px; }
        .ph-count {
            flex-shrink: 0; font-size: 11.5px; font-weight: 700; color: var(--coral-dark); background: var(--coral-tint);
            padding: 4px 11px; border-radius: 20px; white-space: nowrap;
        }
        .ph-timeline { position: relative; padding-left: 24px; }
        .ph-timeline::before {
            content: ''; position: absolute; left: 6px; top: 8px; bottom: 8px; width: 2px;
            background: var(--border); border-radius: 2px;
        }
        .ph-session { position: relative; padding-bottom: 22px; }
        .ph-session:last-child { padding-bottom: 0; }
        .ph-session-dot {
            position: absolute; left: -24px; top: 3px; width: 14px; height: 14px; border-radius: 50%;
            background: #fff; border: 3px solid var(--ink-faint); box-sizing: border-box;
        }
        .ph-session:first-child .ph-session-dot { border-color: var(--coral); }
        .ph-session-head {
            display: flex; align-items: center; flex-wrap: wrap; gap: 8px; margin-bottom: 10px;
            font-size: 12.5px; color: var(--ink-soft);
        }
        .ph-session-head b { font-size: 13.5px; color: var(--ink); }
        .ph-latest {
            font-size: 10px; font-weight: 800; letter-spacing: .04em; text-transform: uppercase;
            color: #fff; background: var(--coral); padding: 2px 8px; border-radius: 20px;
        }
        .ph-session-grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 10px; max-width: 420px; }
        .ph-thumb { position: relative; margin: 0; min-width: 0; }
        .ph-open {
            display: block; width: 100%; padding: 0; aspect-ratio: 3 / 4; overflow: hidden; cursor: zoom-in;
            border: 1px solid var(--border); border-radius: 12px; background: var(--bg);
        }
        .ph-open img { width: 100%; height: 100%; object-fit: cover; display: block; transition: transform .25s ease; }
        .ph-open:hover img { transform: scale(1.04); }
        .ph-thumb figcaption { text-align: center; font-size: 11.5px; font-weight: 700; color: var(--ink-soft); margin-top: 5px; }
        .ph-del-btn {
            position: absolute; top: 6px; right: 6px; width: 24px; height: 24px; border-radius: 50%; border: none;
            background: rgba(15, 23, 42, .65); color: #fff; font-size: 15px; line-height: 1; cursor: pointer;
            display: flex; align-items: center; justify-content: center;
            opacity: 0; transition: opacity .15s ease, background .15s ease;
        }
        .ph-thumb:hover .ph-del-btn, .ph-del-btn:focus-visible { opacity: 1; }
        .ph-del-btn:hover { background: var(--red); }
        @media (hover: none) { .ph-del-btn { opacity: 1; } }

        /* Photo lightbox */
        .ph-lightbox {
            position: fixed; inset: 0; z-index: 3000; padding: 20px; background: rgba(15, 23, 42, .88);
            display: flex; align-items: center; justify-content: center;
        }
        .ph-lightbox[hidden] { display: none; }
        .ph-lightbox figure { margin: 0; max-width: min(92vw, 560px); text-align: center; }
        .ph-lightbox img { display: block; max-width: 100%; max-height: 80vh; margin: 0 auto; border-radius: 14px; }
        .ph-lightbox figcaption { margin-top: 10px; color: #fff; font-size: 13px; font-weight: 600; }
        .ph-lb-close {
            position: absolute; top: 16px; right: 16px; width: 40px; height: 40px; border-radius: 50%; border: none;
            background: rgba(255, 255, 255, .15); color: #fff; font-size: 24px; line-height: 1; cursor: pointer;
        }
        .ph-lb-close:hover { background: rgba(255, 255, 255, .28); }

        /* messages / toast */
        .msg { padding: 13px 16px; border-radius: 12px; font-size: 13.5px; font-weight: 600; margin-bottom: 16px; }
        .msg.err { background: var(--red-tint); color: #9b1c1c; }
        .msg.info { background: var(--amber-tint); color: #92600c; }
        .msg ul { list-style: disc; margin: 6px 0 0 18px; font-weight: 500; }

        .toast {
            position: fixed; top: 18px; right: 18px; z-index: 90; display: flex; align-items: center; gap: 10px;
            background: var(--card); border: 1px solid var(--border); border-left: 4px solid var(--green);
            border-radius: 12px; padding: 13px 16px; box-shadow: 0 12px 34px -10px rgba(20, 20, 30, .3);
            font-size: 13.5px; font-weight: 600; animation: tin .3s cubic-bezier(.3, .7, .3, 1);
        }

        .toast.out { animation: tout .25s ease forwards; }
        .toast .ic { width: 24px; height: 24px; border-radius: 7px; background: var(--green-tint); color: var(--green); display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
        .toast .ic svg { width: 14px; height: 14px; }

        @keyframes tin { from { opacity: 0; transform: translateX(30px); } }
        @keyframes tout { to { opacity: 0; transform: translateX(30px); } }

        /* save bar */
        .save-bar {
            position: fixed; left: var(--sidebar-w); right: 0; bottom: 0; z-index: 30;
            background: rgba(255, 255, 255, .94); backdrop-filter: blur(8px); border-top: 1px solid var(--border);
            padding: 14px 30px; display: flex; align-items: center; gap: 14px;
            transform: translateY(140%); transition: transform .26s cubic-bezier(.3, .7, .3, 1), left .28s ease;
        }

        .save-bar.show { transform: translateY(0); }
        .shell.collapsed ~ .save-bar { left: var(--sidebar-w-collapsed); }
        .save-bar .lead { margin-right: auto; font-size: 13px; font-weight: 700; color: var(--ink); display: flex; align-items: center; gap: 8px; }
        .save-bar .lead::before { content: ""; width: 8px; height: 8px; border-radius: 50%; background: var(--amber); }

        .btn {
            display: inline-flex; align-items: center; gap: 8px; padding: 11px 22px; border-radius: 11px;
            font-weight: 700; font-size: 14px; border: none;
        }

        .btn-primary { background: var(--coral); color: #fff; transition: background .18s ease, transform .18s ease; }
        .btn-primary:hover { background: var(--coral-dark); transform: translateY(-1px); }
        .btn-ghost { background: var(--bg); color: var(--ink); border: 1px solid var(--border); }
        .btn-ghost:hover { border-color: var(--coral); color: var(--coral-dark); }

        /* ===== Responsive ===== */
        @media (max-width: 1180px) {
            .pf-layout { grid-template-columns: 1fr; }
            .pf-summary { position: static; }
            .pf-ring-wrap { padding-bottom: 4px; }
        }

        @media (max-width: 1080px) {
            .grid2 { grid-template-columns: 1fr; }
        }

        @media (max-width: 860px) {
            .sidebar { position: fixed; top: 0; left: 0; bottom: 0; transform: translateX(-100%); width: 270px; }
            .shell.drawer-open .sidebar { transform: translateX(0); }
            .shell.collapsed .sidebar { width: 280px; }
            .shell.collapsed .brand { flex-direction: row; align-items: center; gap: 12px; padding: 6px 46px 26px 10px; }
            .shell.collapsed .brand-text, .shell.collapsed .nav-label { display: block; }
            .shell.collapsed .nav-item { justify-content: flex-start; padding: 12px 14px; }
            .rail-toggle, .shell.collapsed .rail-toggle { top: 18px; right: 14px; transform: none; }
            .rail-toggle .ic-collapse { display: none; }
            .rail-toggle .ic-close { display: block; }
            .hamburger { display: flex; }
            .main { padding: 16px 16px 140px; }
            .save-bar, .shell.collapsed ~ .save-bar { left: 0; padding: 12px 16px; }
            .save-bar .btn { flex: 1; justify-content: center; }
            .save-bar .lead { width: 100%; margin-bottom: 4px; }
            .profile-role { display: none; }
            .photo-grid { grid-template-columns: 1fr 1fr; }
        }

        @media (max-width: 460px) {
            .photo-grid { grid-template-columns: 1fr; }
            .pf-mini { flex-direction: column; }
        }

        a:focus-visible, button:focus-visible, input:focus-visible, textarea:focus-visible {
            outline: 2px solid var(--coral-dark); outline-offset: 2px;
        }

        @media (prefers-reduced-motion: reduce) { *, *::before, *::after { transition-duration: .001s !important; animation-duration: .001s !important; } }

        /* ════════ Mobile hardening ════════ */
        @media (max-width: 640px) {
            .main { padding: 14px 13px 140px; }
            .topbar { gap: 10px; }
            .topbar-right { gap: 10px; }
            .profile-chip .profile-name {
                max-width: 92px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
            }
            .pf-card { padding: 18px; }
            .pf-card-head { gap: 10px; }
            .pf-card-head .tag { margin-left: 0; align-self: flex-start; }
            .pf-card-head { flex-wrap: wrap; }
            .pf-card-head > div:not(.no) { flex: 1 1 auto; }
            .seg { gap: 4px; }
            .seg-opt span { padding: 9px 10px; font-size: 12.5px; }
            .bmi-tile { padding: 12px 14px; }
            .bmi-tile .n { font-size: 22px; }
            .save-bar .lead { font-size: 12px; }
        }

        @media (max-width: 400px) {
            .profile-chip > div:last-child { display: none; }
            .page-title { font-size: 16px; }
            .pf-card { padding: 15px; }
            .grid2 { gap: 12px; }
            .pf-tabs { gap: 5px; padding: 10px 0; }
            .pf-tab { padding: 7px 11px; font-size: 12px; }
            .pf-sum-head { padding: 18px 16px; }
            .pf-sum-avatar { width: 46px; height: 46px; font-size: 17px; }
        }
    </style>

            <?php if ($notice): ?>
                <div class="msg info"><?= htmlspecialchars($notice, ENT_QUOTES, 'UTF-8') ?></div>
            <?php endif; ?>
            <?php if ($errors): ?>
                <div class="msg err">
                    Please fix the following:
                    <ul>
                        <?php foreach ($errors as $e): ?>
                            <li><?= htmlspecialchars($e, ENT_QUOTES, 'UTF-8') ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <div class="pf-layout">

                <!-- ── Summary ── -->
                <aside class="pf-summary">
                    <div class="pf-sum-head">
                        <div class="pf-sum-avatar"><?= htmlspecialchars($initials, ENT_QUOTES, 'UTF-8') ?></div>
                        <div>
                            <div class="nm"><?= htmlspecialchars(($m['full_name'] ?? $acct_name) ?: 'New Member', ENT_QUOTES, 'UTF-8') ?>
                            </div>
                            <div class="rl">JOF Member</div>
                        </div>
                    </div>

                    <div class="pf-ring-wrap">
                        <div class="pf-ring <?= $status['complete'] ? 'done' : '' ?>">
                            <svg width="130" height="130" viewBox="0 0 130 130">
                                <circle cx="65" cy="65" r="52" stroke="var(--border)" stroke-width="12" fill="none" />
                                <circle cx="65" cy="65" r="52"
                                    stroke="<?= $status['complete'] ? 'var(--green)' : 'var(--coral)' ?>"
                                    stroke-width="12" fill="none" stroke-linecap="round"
                                    stroke-dasharray="<?= round($ring_c, 1) ?>"
                                    stroke-dashoffset="<?= round($ring_off, 1) ?>" />
                            </svg>
                            <div class="lbl">
                                <span class="pct"><?= (int) $status['percent'] ?>%</span>
                                <span class="sub"><?= $status['complete'] ? 'all set' : 'complete' ?></span>
                            </div>
                        </div>
                    </div>

                    <div class="pf-checklist">
                        <?php
                        $chk = [
                            ['personal', 'Personal info'],
                            ['health', 'Health details'],
                            ['assessment', 'Self assessment'],
                            ['measure', 'Body measurements'],
                            ['photos', 'Progress photos'],
                        ];
                        foreach ($chk as [$key, $label]):
                            $ok = $sec_done[$key];
                            ?>
                            <button type="button" class="pf-chk <?= $ok ? 'ok' : '' ?>" data-goto="sec-<?= $key ?>">
                                <span class="mk">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3.5"
                                        stroke-linecap="round" stroke-linejoin="round">
                                        <path d="M20 6L9 17l-5-5" />
                                    </svg>
                                </span>
                                <?= $label ?>
                                <?php if ($key === 'photos'): ?><span class="opt">optional</span><?php endif; ?>
                            </button>
                        <?php endforeach; ?>
                    </div>

                    <div class="pf-mini">
                        <div class="box">
                            <b id="miniBmi"><?= ($m['height'] ?? 0) > 0 && ($m['weight'] ?? 0) > 0 ? number_format($m['weight'] / (($m['height'] / 100) ** 2), 1) : '—' ?></b>
                            <small>BMI</small>
                        </div>
                        <div class="box">
                            <b><?= ($m['age'] ?? 0) > 0 ? (int) $m['age'] : '—' ?></b>
                            <small>Age</small>
                        </div>
                        <div class="box">
                            <b><?= ($m['weight'] ?? 0) > 0 ? rtrim(rtrim((string) $m['weight'], '0'), '.') : '—' ?></b>
                            <small>Weight</small>
                        </div>
                    </div>
                </aside>

                <!-- ── Form column ── -->
                <div>
                    <nav class="pf-tabs" id="pfTabs">
                        <?php foreach ([
                            'sec-personal' => ['Personal', $sec_done['personal']],
                            'sec-health' => ['Health', $sec_done['health']],
                            'sec-assessment' => ['Assessment', $sec_done['assessment']],
                            'sec-measure' => ['Measurements', $sec_done['measure']],
                            'sec-photos' => ['Photos', $sec_done['photos']],
                        ] as $id => [$label, $ok]): ?>
                            <button type="button" class="pf-tab <?= $ok ? 'ok' : '' ?>" data-goto="<?= $id ?>">
                                <span class="dot"></span><?= $label ?>
                            </button>
                        <?php endforeach; ?>
                    </nav>

                    <form method="POST" enctype="multipart/form-data" id="profileForm">
                        <input type="hidden" name="_csrf_token"
                            value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">

                        <!-- Personal -->
                        <section class="pf-card" id="sec-personal">
                            <div class="pf-card-head">
                                <div class="no">1</div>
                                <div>
                                    <h2>Personal Information</h2>
                                    <p>How we identify and reach you</p>
                                </div>
                                <span class="tag <?= $sec_done['personal'] ? 'ok' : '' ?>">
                                    <?= $sec_done['personal'] ? 'Complete' : 'Needs info' ?>
                                </span>
                            </div>
                            <div class="grid2">
                                <div class="field full <?= $miss('Full name') ? 'need-fill' : '' ?>">
                                    <label>Full Name <?php if ($miss('Full name')): ?><span
                                            class="need">Needed</span><?php endif; ?></label>
                                    <input type="text" name="full_name" required placeholder="First Last"
                                        value="<?= htmlspecialchars($def_name, ENT_QUOTES, 'UTF-8') ?>">
                                </div>
                                <div class="field <?= $miss('Phone number') ? 'need-fill' : '' ?>">
                                    <label>Phone Number <?php if ($miss('Phone number')): ?><span
                                            class="need">Needed</span><?php endif; ?></label>
                                    <input type="tel" name="phone_number" maxlength="10" inputmode="numeric"
                                        placeholder="10-digit number" value="<?= $v('phone_number') ?>">
                                </div>
                                <div class="field <?= $miss('Email address') ? 'need-fill' : '' ?>">
                                    <label>Email Address <?php if ($miss('Email address')): ?><span
                                            class="need">Needed</span><?php endif; ?></label>
                                    <input type="email" name="email" placeholder="you@example.com"
                                        value="<?= htmlspecialchars($def_email, ENT_QUOTES, 'UTF-8') ?>">
                                </div>
                                <div class="field <?= $miss('Gender') ? 'need-fill' : '' ?>">
                                    <label>Gender <?php if ($miss('Gender')): ?><span
                                            class="need">Needed</span><?php endif; ?></label>
                                    <div class="seg">
                                        <?php foreach (['male' => 'Male', 'female' => 'Female', 'other' => 'Other'] as $gk => $gl): ?>
                                            <label class="seg-opt">
                                                <input type="radio" name="gender" value="<?= $gk ?>"
                                                    <?= ($m['gender'] ?? '') === $gk ? 'checked' : '' ?>>
                                                <span><?= $gl ?></span>
                                            </label>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                                <div class="field full <?= $miss('Diet preference') ? 'need-fill' : '' ?>">
                                    <label>Diet Preference <?php if ($miss('Diet preference')): ?><span
                                            class="need">Needed</span><?php endif; ?></label>
                                    <div class="seg">
                                        <?php
                                        $diets = ['non-veg' => 'Non-Veg', 'veg' => 'Veg', 'vegan' => 'Vegan', 'eggetarian' => 'Eggetarian', 'keto' => 'Keto'];
                                        foreach ($diets as $dk => $dl): ?>
                                            <label class="seg-opt">
                                                <input type="radio" name="diet_type" value="<?= $dk ?>"
                                                    <?= ($m['diet_type'] ?? '') === $dk ? 'checked' : '' ?>>
                                                <span><?= $dl ?></span>
                                            </label>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>
                        </section>

                        <!-- Health -->
                        <section class="pf-card" id="sec-health">
                            <div class="pf-card-head">
                                <div class="no">2</div>
                                <div>
                                    <h2>Health Details</h2>
                                    <p>Used to tailor your plans</p>
                                </div>
                                <span class="tag <?= $sec_done['health'] ? 'ok' : '' ?>">
                                    <?= $sec_done['health'] ? 'Complete' : 'Needs info' ?>
                                </span>
                            </div>
                            <div class="grid2">
                                <div class="field <?= $miss('Age') ? 'need-fill' : '' ?>">
                                    <label>Age <?php if ($miss('Age')): ?><span class="need">Needed</span><?php endif; ?></label>
                                    <input type="number" name="age" id="ageInput" min="12" max="100" placeholder="e.g. 26"
                                        value="<?= $v('age') ?>">
                                </div>
                                <div class="field <?= $miss('Height') ? 'need-fill' : '' ?>">
                                    <label>Height (cm) <?php if ($miss('Height')): ?><span
                                            class="need">Needed</span><?php endif; ?></label>
                                    <input type="number" name="height" id="hInput" step="0.1" min="80" max="250"
                                        placeholder="e.g. 175" value="<?= $v('height') ?>">
                                </div>
                                <div class="field <?= $miss('Weight') ? 'need-fill' : '' ?>">
                                    <label>Weight (kg) <?php if ($miss('Weight')): ?><span
                                            class="need">Needed</span><?php endif; ?></label>
                                    <input type="number" name="weight" id="wInput" step="0.1" min="25" max="350"
                                        placeholder="e.g. 72" value="<?= $v('weight') ?>">
                                </div>
                                <div class="field">
                                    <label>Body Mass Index</label>
                                    <div class="bmi-tile">
                                        <span class="n" id="bmiVal">—</span>
                                        <span class="c"><span id="bmiCat">Enter height &amp; weight</span><small>Auto-calculated</small></span>
                                    </div>
                                </div>
                                <div class="field full">
                                    <label>Medical Issues / Injuries</label>
                                    <textarea name="medical_issues"
                                        placeholder="List any injuries, surgeries or conditions. Write 'None' if not applicable."><?= $v('medical_issues') ?></textarea>
                                </div>
                            </div>
                        </section>

                        <!-- Assessment -->
                        <section class="pf-card" id="sec-assessment">
                            <div class="pf-card-head">
                                <div class="no">3</div>
                                <div>
                                    <h2>Self Assessment</h2>
                                    <p>Rate how you've been feeling lately</p>
                                </div>
                                <span class="tag <?= $sec_done['assessment'] ? 'ok' : '' ?>">
                                    <?= $sec_done['assessment'] ? 'Saved' : 'Not saved' ?>
                                </span>
                            </div>
                            <?php foreach (['mood' => 'Mood', 'sleep_quality' => 'Sleep Quality', 'energy_level' => 'Energy Level', 'hunger_craving' => 'Hunger Control'] as $sk => $sl):
                                $sv = $mv($sk); ?>
                                <div class="scale">
                                    <div class="scale-top">
                                        <span class="nm"><?= $sl ?></span>
                                        <span class="rd"><span id="word_<?= $sk ?>">—</span> · <b
                                                id="val_<?= $sk ?>"><?= $sv ?></b>/10</span>
                                    </div>
                                    <input type="range" name="<?= $sk ?>" min="1" max="10" value="<?= $sv ?>"
                                        data-scale>
                                    <div class="scale-foot"><span>Low</span><span>High</span></div>
                                </div>
                            <?php endforeach; ?>
                        </section>

                        <!-- Measurements -->
                        <section class="pf-card" id="sec-measure">
                            <div class="pf-card-head">
                                <div class="no">4</div>
                                <div>
                                    <h2>Body Measurements</h2>
                                    <p>In inches — fill all four to save a snapshot</p>
                                </div>
                                <span class="tag <?= $sec_done['measure'] ? 'ok' : '' ?>">
                                    <?= $sec_done['measure'] ? 'Saved' : 'Not saved' ?>
                                </span>
                            </div>
                            <div class="grid2 <?= $miss('Body measurements') ? '' : '' ?>">
                                <?php
                                $mez = [
                                    'chest' => ['Chest', 'chest_nipple_line'],
                                    'waist' => ['Waist', 'waist_navel_line'],
                                    'hips' => ['Hips', 'hip_widest_part'],
                                    'thigh' => ['Thighs', 'thigh_mid'],
                                ];
                                foreach ($mez as $name => [$label, $col]): ?>
                                    <div class="field <?= $miss('Body measurements') ? 'need-fill' : '' ?>">
                                        <label><?= $label ?></label>
                                        <input type="number" name="<?= $name ?>" step="0.1" min="0" placeholder="0.0"
                                            value="<?= htmlspecialchars((string) ($ms[$col] ?? ''), ENT_QUOTES) ?>">
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </section>

                        <!-- Photos -->
                        <section class="pf-card" id="sec-photos">
                            <div class="pf-card-head">
                                <div class="no">5</div>
                                <div>
                                    <h2>Physique Progress Photos</h2>
                                    <p>Optional — new uploads are added to your history</p>
                                </div>
                                <span class="tag ok">Optional</span>
                            </div>
                            <div class="photo-grid">
                                <?php
                                $slots = [
                                    'front_view' => ['Front View', 'front_view_image'],
                                    'side_view' => ['Side View', 'side_view_image'],
                                    'back_view' => ['Back View', 'back_view_image'],
                                ];
                                foreach ($slots as $field => [$cap, $col]):
                                    $cur = $latest_photo[$col]['file'] ?? ''; ?>
                                    <div class="photo-cell">
                                        <label class="photo-slot">
                                            <input type="file" name="<?= $field ?>" accept="image/*">
                                            <?php if ($cur): ?>
                                                <img src="<?= $photo_dir . htmlspecialchars($cur, ENT_QUOTES) ?>"
                                                    alt="<?= $cap ?>">
                                                <span class="ov">
                                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                                        stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round">
                                                        <path d="M12 5v14M5 12h14" />
                                                    </svg>Add new
                                                </span>
                                            <?php else: ?>
                                                <span class="ph">
                                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                                        stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                        <path d="M3 7h4l2-3h6l2 3h4v13H3z" />
                                                        <circle cx="12" cy="13" r="3.5" />
                                                    </svg>
                                                    Add photo
                                                </span>
                                            <?php endif; ?>
                                        </label>
                                        <div class="photo-cap"><?= $cap ?>
                                            <?php if ($cur): ?>
                                                <small>Latest · <?= date('d M Y', strtotime($latest_photo[$col]['date'])) ?></small>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <p class="photo-note">New photos are added to your history &mdash; your earlier photos are always kept.</p>

                            <?php
                            $view_labels = ['front_view_image' => 'Front View', 'side_view_image' => 'Side View', 'back_view_image' => 'Back View'];
                            $has_any_compare = false;
                            foreach ($photo_by_view as $__list) {
                                if (count($__list) >= 2) { $has_any_compare = true; break; }
                            }
                            ?>

                            <?php if ($has_any_compare): ?>
                                <div class="ba-wrap">
                                    <h3 class="ba-title">Before &amp; After</h3>
                                    <p class="ba-sub">Your earliest photo next to your most recent one, for each angle
                                        you've uploaded at least twice.</p>
                                    <?php foreach ($view_labels as $col => $label):
                                        $list = $photo_by_view[$col];
                                        if (count($list) < 2) {
                                            continue;
                                        }
                                        $before = $list[0];
                                        $after = end($list);
                                        $days_apart = max(0, (int) floor((strtotime($after['date']) - strtotime($before['date'])) / 86400));
                                        ?>
                                        <div class="ba-row">
                                            <div class="ba-label"><?= $label ?>
                                                <span class="ba-days"><?= $days_apart ?> day<?= $days_apart === 1 ? '' : 's' ?> apart</span>
                                            </div>
                                            <div class="ba-pair">
                                                <div class="ba-cell">
                                                    <img src="<?= $photo_dir . htmlspecialchars($before['file'], ENT_QUOTES) ?>"
                                                        alt="Before" loading="lazy"
                                                        data-lightbox="<?= $photo_dir . htmlspecialchars($before['file'], ENT_QUOTES) ?>"
                                                        data-caption="<?= $label ?> · Before · <?= date('d M Y', strtotime($before['date'])) ?>">
                                                    <span class="ba-tag before">BEFORE ·
                                                        <?= date('d M Y', strtotime($before['date'])) ?></span>
                                                </div>
                                                <div class="ba-cell">
                                                    <img src="<?= $photo_dir . htmlspecialchars($after['file'], ENT_QUOTES) ?>"
                                                        alt="After" loading="lazy"
                                                        data-lightbox="<?= $photo_dir . htmlspecialchars($after['file'], ENT_QUOTES) ?>"
                                                        data-caption="<?= $label ?> · After · <?= date('d M Y', strtotime($after['date'])) ?>">
                                                    <span class="ba-tag after">AFTER ·
                                                        <?= date('d M Y', strtotime($after['date'])) ?></span>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>

                            <?php if ($photo_sessions): ?>
                                <div class="ph-history">
                                    <div class="ph-history-head">
                                        <div>
                                            <h3 class="ba-title">Photo History</h3>
                                            <p class="ba-sub">Every photo you've uploaded, newest first. They stay here
                                                until you remove one yourself.</p>
                                        </div>
                                        <span class="ph-count"><?= $photo_total ?> photo<?= $photo_total === 1 ? '' : 's' ?></span>
                                    </div>
                                    <div class="ph-timeline">
                                        <?php foreach (array_reverse($photo_sessions) as $si => $sess):
                                            $n_photos = count($sess['photos']); ?>
                                            <div class="ph-session">
                                                <span class="ph-session-dot"></span>
                                                <div class="ph-session-head">
                                                    <b><?= date('d M Y', strtotime($sess['date'])) ?></b>
                                                    <span><?= $n_photos ?> photo<?= $n_photos === 1 ? '' : 's' ?></span>
                                                    <?php if ($si === 0): ?><span class="ph-latest">Latest</span><?php endif; ?>
                                                </div>
                                                <div class="ph-session-grid">
                                                    <?php foreach ($view_labels as $col => $label):
                                                        if (empty($sess['photos'][$col])) {
                                                            continue;
                                                        }
                                                        $src = $photo_dir . htmlspecialchars($sess['photos'][$col], ENT_QUOTES); ?>
                                                        <figure class="ph-thumb">
                                                            <button type="button" class="ph-open" data-lightbox="<?= $src ?>"
                                                                data-caption="<?= $label ?> · <?= date('d M Y', strtotime($sess['date'])) ?>"
                                                                aria-label="View <?= $label ?> photo">
                                                                <img src="<?= $src ?>" alt="<?= $label ?>" loading="lazy">
                                                            </button>
                                                            <figcaption><?= $label ?></figcaption>
                                                            <!-- Submits the standalone #photoDelForm below, never the profile form -->
                                                            <button type="submit" form="photoDelForm" name="del"
                                                                value="<?= $col ?>:<?= (int) $sess['id'] ?>" class="ph-del-btn"
                                                                title="Delete this photo" aria-label="Delete this photo"
                                                                onclick="return confirm('Delete this photo? This can\'t be undone.');">&times;</button>
                                                        </figure>
                                                    <?php endforeach; ?>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </section>
                    </form>

                    <!-- Photo delete form: kept OUTSIDE #profileForm (forms can't nest) -->
                    <form method="POST" id="photoDelForm" hidden>
                        <input type="hidden" name="action" value="delete_photo">
                        <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
                    </form>

                    <div class="ph-lightbox" id="phLightbox" hidden>
                        <button type="button" class="ph-lb-close" aria-label="Close">&times;</button>
                        <figure>
                            <img id="phLbImg" src="" alt="Progress photo">
                            <figcaption id="phLbCap"></figcaption>
                        </figure>
                    </div>
                </div>
            </div>

    <div class="save-bar" id="saveBar">
        <span class="lead">You have unsaved changes</span>
        <button type="button" class="btn btn-ghost" id="discardBtn">Discard</button>
        <button type="submit" form="profileForm" class="btn btn-primary">Save Profile</button>
    </div>

    <?php if ($saved && !$errors): ?>
        <div class="toast" id="savedToast">
            <span class="ic">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round"
                    stroke-linejoin="round">
                    <path d="M20 6L9 17l-5-5" />
                </svg>
            </span>
            <?= $photo_deleted ? 'Photo deleted' : 'Profile saved' ?>
        </div>
    <?php endif; ?>

    <script>
        /* ===== Section navigation (tabs + checklist) ===== */
        function gotoSection(id) {
            const el = document.getElementById(id);
            if (el) el.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
        document.querySelectorAll('[data-goto]').forEach(b => {
            b.addEventListener('click', () => gotoSection(b.dataset.goto));
        });

        const tabs = [...document.querySelectorAll('.pf-tab')];
        const sections = tabs.map(t => document.getElementById(t.dataset.goto));
        const spy = new IntersectionObserver((entries) => {
            entries.forEach(en => {
                if (!en.isIntersecting) return;
                const i = sections.indexOf(en.target);
                tabs.forEach((t, k) => t.classList.toggle('active', k === i));
            });
        }, { rootMargin: '-45% 0px -50% 0px', threshold: 0 });
        sections.forEach(s => s && spy.observe(s));

        /* ===== BMI ===== */
        const hIn = document.getElementById('hInput');
        const wIn = document.getElementById('wInput');
        function calcBmi() {
            const h = parseFloat(hIn.value) / 100, w = parseFloat(wIn.value);
            const out = document.getElementById('bmiVal');
            const cat = document.getElementById('bmiCat');
            const mini = document.getElementById('miniBmi');
            if (h > 0 && w > 0) {
                const b = w / (h * h);
                out.textContent = b.toFixed(1);
                if (mini) mini.textContent = b.toFixed(1);
                let label, color;
                if (b < 18.5) { label = 'Underweight'; color = 'var(--amber)'; }
                else if (b < 25) { label = 'Healthy range'; color = 'var(--green)'; }
                else if (b < 30) { label = 'Overweight'; color = 'var(--amber)'; }
                else { label = 'Obese'; color = 'var(--red)'; }
                cat.textContent = label;
                out.style.color = color;
            } else {
                out.textContent = '—'; out.style.color = '';
                cat.textContent = 'Enter height & weight';
            }
        }
        hIn.addEventListener('input', calcBmi);
        wIn.addEventListener('input', calcBmi);
        calcBmi();

        /* ===== Phone: digits only ===== */
        const phone = document.querySelector('input[name="phone_number"]');
        phone?.addEventListener('input', () => { phone.value = phone.value.replace(/\D/g, '').slice(0, 10); });

        /* ===== Sliders ===== */
        const WORDS = ['Very low', 'Low', 'Low', 'Below avg', 'Average', 'Average', 'Good', 'Good', 'Great', 'Excellent'];
        document.querySelectorAll('input[data-scale]').forEach(r => {
            const name = r.name;
            const paint = () => {
                const val = +r.value;
                r.style.setProperty('--p', ((val - 1) / 9 * 100) + '%');
                document.getElementById('val_' + name).textContent = val;
                document.getElementById('word_' + name).textContent = WORDS[val - 1];
            };
            r.addEventListener('input', paint);
            paint();
        });

        /* ===== Photo preview ===== */
        document.querySelectorAll('.photo-slot input[type=file]').forEach(inp => {
            inp.addEventListener('change', () => {
                if (!inp.files || !inp.files[0]) return;
                const slot = inp.closest('.photo-slot');
                let img = slot.querySelector('img');
                if (!img) {
                    img = document.createElement('img');
                    slot.appendChild(img);
                    const ov = document.createElement('span');
                    ov.className = 'ov';
                    ov.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14M5 12h14"/></svg>Add new';
                    slot.appendChild(ov);
                }
                const ph = slot.querySelector('.ph'); if (ph) ph.remove();
                img.src = URL.createObjectURL(inp.files[0]);
                const cap = slot.parentElement.querySelector('.photo-cap small');
                if (cap) cap.textContent = 'New · added when you save';
            });
        });

        /* ===== Photo lightbox ===== */
        const lightbox = document.getElementById('phLightbox');
        if (lightbox) {
            const lbImg = document.getElementById('phLbImg');
            const lbCap = document.getElementById('phLbCap');
            const closeLightbox = () => { lightbox.hidden = true; lbImg.src = ''; };
            document.querySelectorAll('[data-lightbox]').forEach(el => {
                el.addEventListener('click', () => {
                    lbImg.src = el.dataset.lightbox;
                    lbCap.textContent = el.dataset.caption || '';
                    lightbox.hidden = false;
                });
            });
            lightbox.addEventListener('click', e => {
                if (e.target === lightbox || e.target.closest('.ph-lb-close')) closeLightbox();
            });
            document.addEventListener('keydown', e => { if (e.key === 'Escape' && !lightbox.hidden) closeLightbox(); });
        }

        /* ===== Dirty state → save bar ===== */
        const form = document.getElementById('profileForm');
        const saveBar = document.getElementById('saveBar');
        let dirty = false;
        function markDirty() { if (!dirty) { dirty = true; saveBar.classList.add('show'); } }
        form.addEventListener('input', markDirty);
        form.addEventListener('change', markDirty);
        form.addEventListener('submit', () => { dirty = false; });
        document.getElementById('discardBtn').addEventListener('click', () => { dirty = false; location.href = 'user_profile.php'; });
        window.addEventListener('beforeunload', (e) => { if (dirty) { e.preventDefault(); e.returnValue = ''; } });
        <?php if ($errors): ?>saveBar.classList.add('show');<?php endif; ?>

        /* ===== Saved toast ===== */
        const toast = document.getElementById('savedToast');
        if (toast) {
            setTimeout(() => { toast.classList.add('out'); setTimeout(() => toast.remove(), 250); }, 3200);
            if (history.replaceState) history.replaceState(null, '', 'user_profile.php');
        }
    </script>

<?php require __DIR__ . '/_shell_bottom.php'; ?>
