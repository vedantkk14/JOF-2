<?php
require_once __DIR__ . '/../auth/auth_check.php';
require_role(['admin', 'trainer']);
require_once __DIR__ . '/../config.php';

$current_admin_id = (int) ($_SESSION['user_id'] ?? 0);
// Roles offered in the filter dropdown
$filter_roles = ['admin', 'counsellor', 'user'];
// All role slugs we know how to style a badge for
$valid_roles  = ['admin', 'trainer', 'counsellor', 'user'];

// ── SUSPEND / RE-ACTIVATE (toggle is_active) ─────────────────────
if (isset($_GET['toggle_id'])) {
    $tid = (int) $_GET['toggle_id'];
    if ($tid === $current_admin_id) {
        header('Location: user_info.php?msg=self_block');
        exit;
    }
    $stmt = $conn->prepare("UPDATE user_data SET is_active = 1 - is_active WHERE id = ? AND deleted_at IS NULL");
    $stmt->bind_param('i', $tid);
    $stmt->execute();
    header('Location: user_info.php?msg=toggled');
    exit;
}

// ── SOFT DELETE → move to Recycle Bin ───────────────────────────
if (isset($_GET['delete_id'])) {
    $did = (int) $_GET['delete_id'];
    if ($did === $current_admin_id) {
        header('Location: user_info.php?msg=self_delete');
        exit;
    }
    $stmt = $conn->prepare("UPDATE user_data SET deleted_at = NOW() WHERE id = ?");
    $stmt->bind_param('i', $did);
    $stmt->execute();
    header('Location: user_info.php?msg=deleted');
    exit;
}

// ── FILTER INPUTS ──────────────────────────────────────────────
$search_val = isset($_GET['search']) ? trim($_GET['search']) : '';
$filter_val = isset($_GET['filter']) ? trim($_GET['filter']) : '';   // role
$status_val = isset($_GET['status']) ? trim($_GET['status']) : '';   // active | restricted

// ── SUMMARY COUNTS (unfiltered) ────────────────────────────────
$counts = ['total' => 0, 'active' => 0, 'inactive' => 0];
$cq = $conn->query("SELECT is_active, COUNT(*) c FROM user_data WHERE deleted_at IS NULL GROUP BY is_active");
if ($cq) {
    while ($r = $cq->fetch_assoc()) {
        $counts['total'] += (int) $r['c'];
        $counts[((int) $r['is_active'] === 1) ? 'active' : 'inactive'] += (int) $r['c'];
    }
}
$bin_count = (int) ($conn->query("SELECT COUNT(*) c FROM user_data WHERE deleted_at IS NOT NULL")->fetch_assoc()['c'] ?? 0);

// ── BUILD LIST QUERY ───────────────────────────────────────────
$sql    = "SELECT id, full_name, email, role, is_active FROM user_data WHERE deleted_at IS NULL";
$types  = "";
$params = [];

if ($search_val !== '') {
    $like = "%{$search_val}%";
    $sql   .= " AND (full_name LIKE ? OR email LIKE ? OR id = ?)";
    $types .= "ssi";
    $params[] = $like;
    $params[] = $like;
    $params[] = (int) $search_val;
}

if ($filter_val !== '' && in_array($filter_val, $filter_roles, true)) {
    $sql   .= " AND role = ?";
    $types .= "s";
    $params[] = $filter_val;
}

if ($status_val === 'active') {
    $sql .= " AND is_active = 1";
} elseif ($status_val === 'restricted') {
    $sql .= " AND is_active = 0";
}

$sql .= " ORDER BY id DESC";

$stmt = $conn->prepare($sql);
if (!$stmt) {
    die("SQL Error: " . $conn->error);
}
if ($params) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();
$rows   = $result->fetch_all(MYSQLI_ASSOC);

// ── PDF EXPORT — print-styled view (browser "Save as PDF"), same pattern as invoices ──
if (isset($_GET['export']) && $_GET['export'] === 'pdf') {
    $fbits = [];
    if ($search_val !== '') $fbits[] = 'Search: "' . htmlspecialchars($search_val) . '"';
    if ($filter_val !== '') $fbits[] = 'Role: ' . htmlspecialchars(ucfirst($filter_val));
    if ($status_val !== '') $fbits[] = 'Status: ' . htmlspecialchars(ucfirst($status_val));
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <title>User Information — JOF India</title>
        <style>
            * { box-sizing: border-box; margin: 0; padding: 0; }
            body { font-family: Arial, Helvetica, sans-serif; color: #1f2937; padding: 32px 36px; }
            .doc-head { display: flex; justify-content: space-between; align-items: flex-start; border-bottom: 3px solid #F25C2A; padding-bottom: 14px; margin-bottom: 6px; }
            .doc-head h1 { font-size: 22px; color: #111827; }
            .doc-head .brand { font-size: 13px; font-weight: 700; color: #F25C2A; letter-spacing: .04em; }
            .doc-meta { font-size: 12px; color: #6b7280; margin-top: 10px; }
            .doc-meta span { margin-right: 16px; }
            table { width: 100%; border-collapse: collapse; margin-top: 18px; font-size: 12px; }
            thead th { background: #F25C2A; color: #fff; text-align: left; padding: 8px 10px; font-size: 11px; letter-spacing: .04em; text-transform: uppercase; }
            tbody td { padding: 7px 10px; border-bottom: 1px solid #e5e7eb; }
            tbody tr:nth-child(even) { background: #f9fafb; }
            .pill { display: inline-block; padding: 2px 9px; border-radius: 999px; font-size: 10px; font-weight: 700; }
            .pill.on { background: #dcfce7; color: #15803d; }
            .pill.off { background: #fee2e2; color: #b91c1c; }
            .role { font-weight: 700; }
            .foot { margin-top: 22px; font-size: 11px; color: #9ca3af; }
            .toolbar { margin-bottom: 20px; }
            .toolbar button, .toolbar a {
                font: inherit; font-size: 13px; padding: 9px 16px; border-radius: 9px; cursor: pointer;
                border: 1px solid #d1d5db; background: #fff; color: #374151; text-decoration: none; margin-right: 8px;
            }
            .toolbar button.primary { background: #F25C2A; color: #fff; border-color: #F25C2A; }
            @media print {
                body { padding: 0; }
                .toolbar { display: none; }
                thead th { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
                tbody tr:nth-child(even) { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            }
        </style>
    </head>
    <body>
        <div class="toolbar">
            <button class="primary" onclick="window.print()">Save as PDF / Print</button>
            <a href="user_info.php<?= qs(['export' => '']) ?>">Back</a>
        </div>

        <div class="doc-head">
            <div>
                <h1>User Information</h1>
                <div class="doc-meta">
                    <span><?= $fbits ? implode('</span><span>', $fbits) : 'All users' ?></span>
                    <span><?= count($rows) ?> record(s)</span>
                </div>
            </div>
            <div style="text-align:right;">
                <div class="brand">JOF INDIA</div>
                <div class="doc-meta">Generated <?= date('d M Y, H:i') ?></div>
            </div>
        </div>

        <table>
            <thead>
                <tr><th>ID</th><th>Name</th><th>Email</th><th>Role</th><th>Status</th></tr>
            </thead>
            <tbody>
                <?php if ($rows): foreach ($rows as $r): ?>
                    <tr>
                        <td><?= (int) $r['id'] ?></td>
                        <td><?= htmlspecialchars($r['full_name']) ?></td>
                        <td><?= htmlspecialchars($r['email']) ?></td>
                        <td class="role"><?= htmlspecialchars(ucfirst($r['role'])) ?></td>
                        <td>
                            <span class="pill <?= ((int) $r['is_active'] === 1) ? 'on' : 'off' ?>">
                                <?= ((int) $r['is_active'] === 1) ? 'Active' : 'Restricted' ?>
                            </span>
                        </td>
                    </tr>
                <?php endforeach; else: ?>
                    <tr><td colspan="5" style="text-align:center;padding:18px;color:#9ca3af;">No users match the current filters.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>

        <p class="foot">JOF India CRM — User Information export. Passwords are not included.</p>

        <script>window.addEventListener('load', function () { setTimeout(function () { window.print(); }, 300); });</script>
    </body>
    </html>
    <?php
    exit;
}

// Helper to preserve filters in links
function qs(array $overrides = []): string
{
    $base = [
        'search' => $_GET['search'] ?? '',
        'filter' => $_GET['filter'] ?? '',
        'status' => $_GET['status'] ?? '',
    ];
    $merged = array_filter(array_merge($base, $overrides), fn($v) => $v !== '' && $v !== null);
    return $merged ? '?' . http_build_query($merged) : '';
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" size="16x16" href="../icons/favicon-dark-logo.png" type="image/png">
    <title>JOF India | User Information</title>
    <link rel="stylesheet" href="../static/root.css">
    <style>
        /* ===== Modal (matched to members.php) ===== */
        .modal-overlay {
            position: fixed; inset: 0; width: 100%; height: 100%;
            background: rgba(0, 0, 0, 0.6); backdrop-filter: blur(5px);
            z-index: 100000; display: none; align-items: center; justify-content: center;
            opacity: 0; transition: opacity 0.3s ease;
        }
        .modal-overlay.active { display: flex; opacity: 1; }
        .modal-card {
            background: #fff; padding: 30px; border-radius: 20px;
            max-width: 400px; width: 90%; text-align: center;
            transform: translateY(20px); transition: transform 0.3s ease;
        }
        .modal-overlay.active .modal-card { transform: translateY(0); }
        .modal-btn-success {
            background: #10B981; color: #fff; border: none; padding: 12px;
            border-radius: 12px; font-weight: 600; cursor: pointer; transition: all 0.2s;
        }
        .modal-btn-success:hover { background: #059669; transform: translateY(-2px); }

        /* ===== Role badges ===== */
        .page-members .badge.role-admin      { background: #FEE2E2; color: #B91C1C; }
        .page-members .badge.role-trainer    { background: #EDE9FE; color: #6D28D9; }
        .page-members .badge.role-counsellor { background: #DBEAFE; color: #1D4ED8; }
        .page-members .badge.role-user       { background: #DCFCE7; color: #15803D; }

        /* ===== Inactive status pill ===== */
        .page-members .status-pill.inactive { background: #FEE2E2; color: #B91C1C; }
        .page-members .status-pill.inactive::before { background: #EF4444; }

        /* ===== Stat cards ===== */
        .stat-row {
            display: grid; grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 14px; margin-bottom: 22px;
        }
        @media (max-width: 760px) { .stat-row { grid-template-columns: 1fr; } }
        .stat-card {
            position: relative; overflow: hidden;
            display: flex; align-items: center; gap: 14px;
            padding: 18px; border-radius: 18px; text-decoration: none;
            border: 1px solid #EEF0F3; background: #fff; color: inherit;
            transition: transform .18s ease, box-shadow .18s ease, border-color .18s ease;
        }
        .stat-card::after {
            content: ''; position: absolute; left: 0; top: 0; bottom: 0; width: 4px;
            background: currentColor; opacity: .0; transition: opacity .18s ease;
        }
        .stat-card:hover { transform: translateY(-3px); box-shadow: 0 14px 30px rgba(17, 24, 39, .09); }
        .stat-card:hover::after { opacity: .5; }
        .stat-card.selected { border-color: currentColor; box-shadow: 0 0 0 2px currentColor inset; }
        .stat-card.selected::after { opacity: 1; }
        .stat-card .stat-ico {
            width: 44px; height: 44px; border-radius: 13px; flex-shrink: 0;
            display: flex; align-items: center; justify-content: center;
        }
        .stat-card .stat-ico img { width: 19px; height: 19px; }
        .stat-card .stat-num { font-size: 24px; font-weight: 800; line-height: 1; }
        .stat-card .stat-lbl {
            font-size: 11px; font-weight: 700; letter-spacing: .06em;
            text-transform: uppercase; color: #9CA3AF; margin-top: 5px;
        }
        .stat-card.c-total  { color: #F25C2A; }
        .stat-card.c-total  .stat-ico { background: #FFF1EC; color: #F25C2A; }
        .stat-card.c-total  .stat-num { color: #1F2937; }
        .stat-card.c-active { color: #15803D; background: #F3FDF6; border-color: #C7F0D4; }
        .stat-card.c-active .stat-ico { background: #DCFCE7; color: #15803D; }
        .stat-card.c-active .stat-num { color: #15803D; }
        .stat-card.c-rest   { color: #B91C1C; background: #FEF4F4; border-color: #FBD3D3; }
        .stat-card.c-rest   .stat-ico { background: #FEE2E2; color: #B91C1C; }
        .stat-card.c-rest   .stat-num { color: #B91C1C; }

        /* ===== Result meta row ===== */
        .result-meta {
            display: flex; align-items: center; justify-content: space-between;
            flex-wrap: wrap; gap: 10px; margin: 4px 2px 12px;
        }
        .result-meta .count { font-size: 13px; color: #6B7280; }
        .result-meta .count strong { color: #1F2937; }
        .chip-clear {
            display: inline-flex; align-items: center; gap: 6px;
            font-size: 12px; font-weight: 600; color: #B91C1C;
            background: #FEE2E2; border-radius: 999px; padding: 5px 12px; text-decoration: none;
        }
        .chip-clear img { width: 11px; height: 11px; }
        .btn-export {
            display: inline-flex; align-items: center; gap: 8px;
            background: #fff; border: 1px solid #E5E7EB; color: #374151;
            border-radius: 12px; padding: 9px 16px; font-size: 13px; font-weight: 600;
            text-decoration: none; transition: all .2s;
        }
        .btn-export:hover { background: #F9FAFB; border-color: #D1D5DB; }
        .btn-export img { width: 14px; height: 14px; }

        /* ===== Restrict / unblock action buttons ===== */
        .btn-restrict, .btn-unblock {
            border: none; cursor: pointer; border-radius: 10px;
            width: 34px; height: 34px; display: inline-flex;
            align-items: center; justify-content: center; transition: all 0.2s;
        }
        .btn-restrict { background: #FEF3C7; color: #B45309; }
        .btn-restrict:hover { background: #FDE68A; }
        .btn-unblock { background: #DCFCE7; color: #15803D; }
        .btn-unblock:hover { background: #BBF7D0; }
        .btn-restrict img, .btn-unblock img { width: 15px; height: 15px; }

        .empty-ico { width: 40px; height: 40px; opacity: .35; margin-bottom: 8px; }
    </style>
</head>

<body class="page-members">

    <button class="mobile-toggle" id="mobileToggle"><img src="../icons/bars-solid-full.svg" class="fa-solid fa-bars"></button>
    <button class="toggle-sidebar-btn" id="toggleBtn"><img src="../icons/chevron-left-solid-full.svg" class="fa-solid fa-chevron-left"></button>

    <div class="dashboard-container">

        <?php include 'sidebar.php'; ?>

        <main class="main-content">

            <header class="header-banner">
                <div class="header-text">
                    <h1>User Information</h1>
                    <p>Central directory of all system accounts &mdash; view, restrict, or remove login access</p>
                </div>
            </header>

            <div class="stat-row">
                <a href="user_info.php<?= qs(['status' => '']) ?>" class="stat-card c-total <?= $status_val === '' ? 'selected' : '' ?>">
                    <span class="stat-ico"><img src="../icons/users-solid-full.svg" class="fa-solid fa-users"></span>
                    <span><span class="stat-num"><?= $counts['total'] ?></span><span class="stat-lbl">Total Users</span></span>
                </a>
                <a href="user_info.php<?= qs(['status' => 'active']) ?>" class="stat-card c-active <?= $status_val === 'active' ? 'selected' : '' ?>">
                    <span class="stat-ico"><img src="../icons/user-check-solid-full.svg" class="fa-solid fa-user-check"></span>
                    <span><span class="stat-num"><?= $counts['active'] ?></span><span class="stat-lbl">Active</span></span>
                </a>
                <a href="user_info.php<?= qs(['status' => 'restricted']) ?>" class="stat-card c-rest <?= $status_val === 'restricted' ? 'selected' : '' ?>">
                    <span class="stat-ico"><img src="../icons/user-slash-solid-full.svg" class="fa-solid fa-user-slash"></span>
                    <span><span class="stat-num"><?= $counts['inactive'] ?></span><span class="stat-lbl">Restricted</span></span>
                </a>
            </div>

            <div class="toolbar">
                <form id="searchForm" method="GET" action="user_info.php" class="search-group flex-y-center w-full">
                    <input type="hidden" name="filter" id="filterInput" value="<?= htmlspecialchars($filter_val) ?>">
                    <input type="hidden" name="status" value="<?= htmlspecialchars($status_val) ?>">

                    <div class="search-bar">
                        <img src="../icons/magnifying-glass-solid-full.svg" class="fa-solid fa-magnifying-glass">
                        <input type="text" name="search" placeholder="Search by name, email or ID..."
                            value="<?= htmlspecialchars($search_val) ?>">
                    </div>

                    <div class="filter-wrapper">
                        <button type="button" class="filter-btn" id="filterToggleBtn" onclick="toggleFilterCard(event)">
                            <img src="../icons/filter-solid-full.svg" class="fa-solid fa-filter">
                            <?= $filter_val !== '' ? ucfirst($filter_val) : 'Filter' ?>
                        </button>
                        <div class="filter-card-popup" id="filterCard">
                            <div class="filter-header">Filter by Role</div>
                            <button type="button" class="filter-option-btn <?= $filter_val === '' ? 'active' : '' ?>"
                                onclick="applyFilter('')"><img src="../icons/check-solid-full.svg" class="fa-solid fa-check"> All Roles</button>
                            <?php foreach ($filter_roles as $r): ?>
                                <button type="button" class="filter-option-btn <?= $filter_val === $r ? 'active' : '' ?>"
                                    onclick="applyFilter('<?= $r ?>')"><img src="../icons/check-solid-full.svg" class="fa-solid fa-check"> <?= ucfirst($r) ?></button>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <button type="submit" class="filter-btn ml-10 btn-go">Go</button>
                </form>
            </div>

            <div class="result-meta">
                <span class="count">
                    <?php if ($search_val !== '' || $filter_val !== '' || $status_val !== ''): ?>
                        <a class="chip-clear" href="user_info.php"><img src="../icons/xmark-solid-full.svg" class="fa-solid fa-xmark"> Clear filters</a>
                    <?php endif; ?>
                </span>
                <a class="btn-export" href="user_info.php<?= qs(['export' => 'pdf']) ?>" target="_blank" rel="noopener">
                    <img src="../icons/download-solid-full.svg" class="fa-solid fa-download"> Export PDF
                </a>
            </div>

            <div class="table-card">
                <table class="styled-table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Name</th>
                            <th>Email</th>
                            <th>Role</th>
                            <th>Status</th>
                            <th class="text-center">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($rows): ?>
                            <?php foreach ($rows as $row): ?>
                                <?php
                                $uid       = (int) $row['id'];
                                $is_active = (int) $row['is_active'] === 1;
                                $is_self   = $uid === $current_admin_id;
                                $role      = strtolower($row['role']);
                                ?>
                                <tr>
                                    <td data-label="ID"><strong><?= $uid ?></strong></td>

                                    <td data-label="Name">
                                        <strong><?= htmlspecialchars($row['full_name']) ?></strong>
                                        <?php if ($is_self): ?><span class="badge-new">YOU</span><?php endif; ?>
                                    </td>

                                    <td data-label="Email" class="text-muted"><?= htmlspecialchars($row['email']) ?></td>

                                    <td data-label="Role">
                                        <span class="badge role-<?= in_array($role, $valid_roles, true) ? $role : 'user' ?>"><?= ucfirst($role) ?></span>
                                    </td>

                                    <td data-label="Status">
                                        <span class="status-pill <?= $is_active ? 'active' : 'inactive' ?>">
                                            <?= $is_active ? 'Active' : 'Restricted' ?>
                                        </span>
                                    </td>

                                    <td data-label="Actions">
                                        <div class="action-cell">
                                            <a href="user_details.php?id=<?= $uid ?>" class="btn-view" title="View Profile">
                                                <img src="../icons/eye-solid-full.svg" class="fa-solid fa-eye">
                                            </a>

                                            <?php if (!$is_self): ?>
                                                <?php if ($is_active): ?>
                                                    <button class="btn-restrict"
                                                        onclick="userConfirm('restrict', 'user_info.php?toggle_id=<?= $uid ?>', '<?= htmlspecialchars(addslashes($row['full_name'])) ?>')"
                                                        title="Restrict access">
                                                        <img src="../icons/user-slash-solid-full.svg" class="fa-solid fa-user-slash">
                                                    </button>
                                                <?php else: ?>
                                                    <button class="btn-unblock"
                                                        onclick="userConfirm('unblock', 'user_info.php?toggle_id=<?= $uid ?>', '<?= htmlspecialchars(addslashes($row['full_name'])) ?>')"
                                                        title="Re-activate access">
                                                        <img src="../icons/user-check-solid-full.svg" class="fa-solid fa-user-check">
                                                    </button>
                                                <?php endif; ?>

                                                <button class="btn-delete" style="border:none;cursor:pointer;"
                                                    onclick="userConfirm('delete', 'user_info.php?delete_id=<?= $uid ?>', '<?= htmlspecialchars(addslashes($row['full_name'])) ?>')"
                                                    title="Move to Recycle Bin">
                                                    <img src="../icons/trash-solid-full.svg" class="fa-solid fa-trash">
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="6" class="table-empty-row">
                                    <img src="../icons/users-solid-full.svg" class="fa-solid fa-users empty-ico"><br>
                                    No users match your filters.
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

        </main>
    </div>

    <!-- ===== CONFIRM MODAL ===== -->
    <div id="userConfirmModal" class="modal-overlay">
        <div class="modal-card">
            <div class="modal-icon-box danger">
                <img src="../icons/triangle-exclamation-solid-full.svg" class="fa-solid fa-triangle-exclamation" style="font-size:26px;">
            </div>
            <h2 class="modal-title" id="ucTitle">Are you sure?</h2>
            <p class="modal-desc" id="ucDesc"></p>
            <div class="modal-actions">
                <button onclick="document.getElementById('userConfirmModal').classList.remove('active')" class="modal-btn modal-btn-secondary">Cancel</button>
                <button id="ucConfirmBtn" class="modal-btn modal-btn-danger">Confirm</button>
            </div>
        </div>
    </div>

    <!-- ===== SUCCESS MODAL ===== -->
    <div id="userSuccessModal" class="modal-overlay">
        <div class="modal-card">
            <div class="modal-icon-box success">
                <img src="../icons/check-solid-full.svg" class="fa-solid fa-check" style="font-size:30px;">
            </div>
            <h2 class="modal-title" id="usTitle">Done!</h2>
            <p class="modal-desc" id="usDesc"></p>
            <button onclick="document.getElementById('userSuccessModal').classList.remove('active')" class="modal-btn modal-btn-success" style="margin-top:15px; width:100%;">Okay</button>
            <div class="timer-bar-container"><div id="usTimer" class="timer-bar"></div></div>
        </div>
    </div>

    <script>
        function toggleFilterCard(e) { e.stopPropagation(); document.getElementById('filterCard').classList.toggle('show'); }
        function applyFilter(v) { document.getElementById('filterInput').value = v; document.getElementById('searchForm').submit(); }

        const UC_COPY = {
            restrict: { title: 'Restrict this user?', desc: n => `${n} will be blocked from logging in until you re-activate them.`, btn: 'Restrict' },
            unblock:  { title: 'Re-activate this user?', desc: n => `${n} will be able to log in again.`, btn: 'Re-activate' },
            delete:   { title: 'Move user to Recycle Bin?', desc: n => `${n} will lose access and move to the Recycle Bin. You can restore them later.`, btn: 'Move to Bin' }
        };
        function userConfirm(type, url, name) {
            const c = UC_COPY[type];
            const modal = document.getElementById('userConfirmModal');
            document.getElementById('ucTitle').textContent = c.title;
            document.getElementById('ucDesc').textContent = c.desc(name);
            const okBtn = document.getElementById('ucConfirmBtn');
            okBtn.textContent = c.btn;
            okBtn.onclick = () => { window.location.href = url; };
            modal.classList.add('active');
        }

        document.addEventListener('DOMContentLoaded', function () {
            const toggleBtn = document.getElementById('toggleBtn'), mobileToggle = document.getElementById('mobileToggle'), body = document.body;
            if (toggleBtn) toggleBtn.addEventListener('click', () => body.classList.toggle('collapsed'));
            if (mobileToggle) mobileToggle.addEventListener('click', () => body.classList.toggle('sidebar-open'));

            const dropdownItems = document.querySelectorAll('.nav-item-dropdown');
            dropdownItems.forEach(item => {
                const arrow = item.querySelector('.nav-arrow');
                if (arrow) arrow.addEventListener('click', (e) => {
                    e.preventDefault(); e.stopPropagation();
                    dropdownItems.forEach(o => { if (o !== item) o.classList.remove('active'); });
                    item.classList.toggle('active');
                });
            });

            document.addEventListener('click', (e) => {
                const card = document.getElementById('filterCard');
                if (card.classList.contains('show') && !card.contains(e.target) && !document.getElementById('filterToggleBtn').contains(e.target)) card.classList.remove('show');
            });

            <?php if (isset($_GET['msg'])): ?>
            (function () {
                const map = {
                    deleted:     ['Moved to Recycle Bin', 'The user has been moved to the Recycle Bin.'],
                    toggled:     ['Access Updated', 'The user\'s login access has been updated.'],
                    self_block:  ['Not Allowed', 'You cannot restrict your own account.'],
                    self_delete: ['Not Allowed', 'You cannot delete your own account.']
                };
                const m = map[<?= json_encode($_GET['msg']) ?>];
                if (!m) return;
                document.getElementById('usTitle').textContent = m[0];
                document.getElementById('usDesc').textContent = m[1];
                const modal = document.getElementById('userSuccessModal');
                modal.classList.add('active');
                const bar = document.getElementById('usTimer');
                bar.style.transition = 'none'; bar.style.width = '100%';
                requestAnimationFrame(() => requestAnimationFrame(() => {
                    bar.style.transition = 'width 2.5s linear'; bar.style.width = '0%';
                }));
                setTimeout(() => modal.classList.remove('active'), 2500);
            })();
            <?php endif; ?>
        });

        function replaceSVG() {
            var images = document.querySelectorAll('img.fa-solid, img.fa-regular, img[class*="fa-"]');
            images.forEach(function (img) {
                if (img.classList.contains('svg-replaced')) return;
                img.classList.add('svg-replaced');
                var imgID = img.id, imgClass = img.className, imgURL = img.src;
                if (!imgURL.endsWith('.svg')) return;
                fetch(imgURL).then(r => r.text()).then(text => {
                    var svg = new DOMParser().parseFromString(text, "text/xml").getElementsByTagName('svg')[0];
                    if (!svg) return;
                    if (imgID) svg.setAttribute('id', imgID);
                    if (imgClass) svg.setAttribute('class', imgClass + ' replaced-svg');
                    svg.removeAttribute('xmlns:a'); svg.removeAttribute('width'); svg.removeAttribute('height');
                    svg.querySelectorAll('path').forEach(p => p.setAttribute('fill', 'currentColor'));
                    img.parentNode.replaceChild(svg, img);
                }).catch(err => console.error('Error fetching SVG:', err));
            });
        }
        replaceSVG();
        new MutationObserver(function (muts) {
            if (muts.some(m => m.addedNodes.length)) replaceSVG();
        }).observe(document.body, { childList: true, subtree: true });
    </script>

</body>

</html>
