<?php
require_once __DIR__ . '/../auth/auth_check.php';
require_role(['admin', 'trainer']);
require_once __DIR__ . '/../config.php';

$filter_roles = ['admin', 'counsellor', 'user'];
$valid_roles  = ['admin', 'trainer', 'counsellor', 'user'];

// ── RESTORE ────────────────────────────────────────────────────
if (isset($_GET['restore_id'])) {
    $rid = (int) $_GET['restore_id'];
    $stmt = $conn->prepare("UPDATE user_data SET deleted_at = NULL WHERE id = ?");
    $stmt->bind_param('i', $rid);
    $stmt->execute();
    header('Location: user_recycle_bin.php?msg=restored');
    exit;
}

// ── PERMANENT DELETE ───────────────────────────────────────────
if (isset($_GET['purge_id'])) {
    $pid = (int) $_GET['purge_id'];
    $stmt = $conn->prepare("DELETE FROM user_data WHERE id = ? AND deleted_at IS NOT NULL");
    $stmt->bind_param('i', $pid);
    $stmt->execute();
    header('Location: user_recycle_bin.php?msg=purged');
    exit;
}

// ── SEARCH + ROLE FILTER ───────────────────────────────────────
$search_val = isset($_GET['search']) ? trim($_GET['search']) : '';
$filter_val = isset($_GET['filter']) ? trim($_GET['filter']) : '';

$sql    = "SELECT id, full_name, email, role, deleted_at FROM user_data WHERE deleted_at IS NOT NULL";
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

$sql .= " ORDER BY deleted_at DESC";

$stmt = $conn->prepare($sql);
if (!$stmt) {
    die("SQL Error: " . $conn->error);
}
if ($params) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();

$bin_total = (int) ($conn->query("SELECT COUNT(*) c FROM user_data WHERE deleted_at IS NOT NULL")->fetch_assoc()['c'] ?? 0);
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" size="16x16" href="../icons/favicon-dark-logo.png" type="image/png">
    <title>JOF India | User Recycle Bin</title>
    <link rel="stylesheet" href="../static/root.css">
    <style>
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

        .page-members .badge.role-admin      { background: #FEE2E2; color: #B91C1C; }
        .page-members .badge.role-trainer    { background: #EDE9FE; color: #6D28D9; }
        .page-members .badge.role-counsellor { background: #DBEAFE; color: #1D4ED8; }
        .page-members .badge.role-user       { background: #DCFCE7; color: #15803D; }

        .btn-restore {
            border: none; cursor: pointer; border-radius: 10px;
            width: 34px; height: 34px; display: inline-flex;
            align-items: center; justify-content: center; transition: all 0.2s;
            background: #DCFCE7; color: #15803D;
        }
        .btn-restore:hover { background: #BBF7D0; }
        .btn-restore img { width: 15px; height: 15px; }

        .bin-note {
            display: flex; align-items: center; gap: 10px;
            background: #FEF9C3; border: 1px solid #FDE68A; border-left: 4px solid #F59E0B;
            border-radius: 12px; padding: 12px 18px; margin-bottom: 10px;
            font-size: 13px; color: #92400E;
        }
        .bin-note img { width: 16px; height: 16px; flex-shrink: 0; }
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
                    <h1>User Recycle Bin</h1>
                    <p>Deleted accounts &mdash; restore them or remove them permanently</p>
                </div>
            </header>

            <div class="bin-note">
                <img src="../icons/triangle-exclamation-solid-full.svg" class="fa-solid fa-triangle-exclamation">
                Users here cannot log in. <strong><?= $bin_total ?></strong> account<?= $bin_total === 1 ? '' : 's' ?> in the bin.
            </div>

            <div class="toolbar">
                <form id="searchForm" method="GET" action="user_recycle_bin.php" class="search-group flex-y-center w-full">
                    <input type="hidden" name="filter" id="filterInput" value="<?= htmlspecialchars($filter_val) ?>">

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
                <a href="user_info.php" class="btn-primary ml-auto">
                    <img src="../icons/arrow-left-solid-full.svg" class="fa-solid fa-arrow-left"> Back to All Users
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
                            <th>Deleted On</th>
                            <th class="text-center">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($result->num_rows > 0): ?>
                            <?php while ($row = $result->fetch_assoc()): ?>
                                <?php
                                $uid  = (int) $row['id'];
                                $role = strtolower($row['role']);
                                ?>
                                <tr>
                                    <td data-label="ID"><strong>#<?= str_pad((string) $uid, 4, '0', STR_PAD_LEFT) ?></strong></td>
                                    <td data-label="Name"><strong><?= htmlspecialchars($row['full_name']) ?></strong></td>
                                    <td data-label="Email" class="text-muted"><?= htmlspecialchars($row['email']) ?></td>
                                    <td data-label="Role">
                                        <span class="badge role-<?= in_array($role, $valid_roles, true) ? $role : 'user' ?>"><?= ucfirst($role) ?></span>
                                    </td>
                                    <td data-label="Deleted On" class="text-muted">
                                        <?= $row['deleted_at'] ? date('d M Y, H:i', strtotime($row['deleted_at'])) : '—' ?>
                                    </td>
                                    <td data-label="Actions">
                                        <div class="action-cell">
                                            <button class="btn-restore"
                                                onclick="binConfirm('restore', 'user_recycle_bin.php?restore_id=<?= $uid ?>', '<?= htmlspecialchars(addslashes($row['full_name'])) ?>')"
                                                title="Restore user">
                                                <img src="../icons/arrows-rotate-solid-full.svg" class="fa-solid fa-arrows-rotate">
                                            </button>
                                            <button class="btn-delete" style="border:none;cursor:pointer;"
                                                onclick="binConfirm('purge', 'user_recycle_bin.php?purge_id=<?= $uid ?>', '<?= htmlspecialchars(addslashes($row['full_name'])) ?>')"
                                                title="Delete permanently">
                                                <img src="../icons/trash-solid-full.svg" class="fa-solid fa-trash">
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="6" class="table-empty-row">Recycle Bin is empty.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

        </main>
    </div>

    <div id="binConfirmModal" class="modal-overlay">
        <div class="modal-card">
            <div class="modal-icon-box danger">
                <img src="../icons/triangle-exclamation-solid-full.svg" class="fa-solid fa-triangle-exclamation" style="font-size:26px;">
            </div>
            <h2 class="modal-title" id="bcTitle">Are you sure?</h2>
            <p class="modal-desc" id="bcDesc"></p>
            <div class="modal-actions">
                <button onclick="document.getElementById('binConfirmModal').classList.remove('active')" class="modal-btn modal-btn-secondary">Cancel</button>
                <button id="bcConfirmBtn" class="modal-btn modal-btn-danger">Confirm</button>
            </div>
        </div>
    </div>

    <div id="binSuccessModal" class="modal-overlay">
        <div class="modal-card">
            <div class="modal-icon-box success">
                <img src="../icons/check-solid-full.svg" class="fa-solid fa-check" style="font-size:30px;">
            </div>
            <h2 class="modal-title" id="bsTitle">Done!</h2>
            <p class="modal-desc" id="bsDesc"></p>
            <button onclick="document.getElementById('binSuccessModal').classList.remove('active')" class="modal-btn modal-btn-success" style="margin-top:15px; width:100%;">Okay</button>
            <div class="timer-bar-container"><div id="bsTimer" class="timer-bar"></div></div>
        </div>
    </div>

    <script>
        function toggleFilterCard(e) { e.stopPropagation(); document.getElementById('filterCard').classList.toggle('show'); }
        function applyFilter(v) { document.getElementById('filterInput').value = v; document.getElementById('searchForm').submit(); }

        const BC_COPY = {
            restore: { title: 'Restore this user?', desc: n => `${n} will be able to log in again and reappear in All Users.`, btn: 'Restore', danger: false },
            purge:   { title: 'Delete permanently?', desc: n => `${n} will be erased from the system for good. This cannot be undone.`, btn: 'Delete Forever', danger: true }
        };
        function binConfirm(type, url, name) {
            const c = BC_COPY[type];
            document.getElementById('bcTitle').textContent = c.title;
            document.getElementById('bcDesc').textContent = c.desc(name);
            const okBtn = document.getElementById('bcConfirmBtn');
            okBtn.textContent = c.btn;
            okBtn.className = 'modal-btn ' + (c.danger ? 'modal-btn-danger' : 'modal-btn-success');
            okBtn.onclick = () => { window.location.href = url; };
            document.getElementById('binConfirmModal').classList.add('active');
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
                    restored: ['User Restored', 'The account has been restored and can log in again.'],
                    purged:   ['Permanently Deleted', 'The account has been erased from the system.']
                };
                const m = map[<?= json_encode($_GET['msg']) ?>];
                if (!m) return;
                document.getElementById('bsTitle').textContent = m[0];
                document.getElementById('bsDesc').textContent = m[1];
                const modal = document.getElementById('binSuccessModal');
                modal.classList.add('active');
                const bar = document.getElementById('bsTimer');
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
