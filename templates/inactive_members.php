<?php
require_once __DIR__ . '/../auth/auth_check.php';
require_role(['admin', 'trainer']);
require_once __DIR__ . '/../config.php';

// === DELETE LOGIC ===
if (isset($_GET['delete_id'])) {
    $del_id = intval($_GET['delete_id']);
    $stmt_del = $conn->prepare("UPDATE members SET status = 'recycled', inactive_date = NOW() WHERE id = ?");
    $stmt_del->bind_param("i", $del_id);
    if ($stmt_del->execute()) {
        header("Location: inactive_members.php?msg=recycled");
        exit;
    }
}

// === AUTO-EXPIRY: Move active members with expired plans to inactive ===
$conn->query("
    UPDATE members m
    SET m.status = 'inactive', m.inactive_date = NOW()
    WHERE m.status = 'active'
    AND (
        (EXISTS (SELECT 1 FROM member_payments mp WHERE mp.member_id = m.id)
         AND NOT EXISTS (
            SELECT 1 FROM member_payments mp2
            WHERE mp2.member_id = m.id
            AND mp2.end_date > CURDATE()
         ))
        OR
        (NOT EXISTS (SELECT 1 FROM member_payments mp3 WHERE mp3.member_id = m.id)
         AND m.created_at < DATE_SUB(NOW(), INTERVAL 30 DAY))
    )
");

// === AUTO-EXPIRY FOR PT: Sync PT expiration with membership expiration ===
// This moves any active PT sessions for inactive members to the expired list
$conn->query("UPDATE personal_training pt
              JOIN members m ON pt.member_id = m.id
              SET pt.end_date = DATE_SUB(CURDATE(), INTERVAL 1 DAY)
              WHERE m.status = 'inactive' AND pt.end_date >= CURDATE()");

// === AUTO-RECYCLE: Move inactive members to recycled after 15 days ===
$conn->query("UPDATE members SET status = 'recycled' WHERE status = 'inactive' AND inactive_date IS NOT NULL AND inactive_date < DATE_SUB(NOW(), INTERVAL 15 DAY)");

// === SEARCH ===
$search_val = isset($_GET['search']) ? trim($_GET['search']) : '';

$sql = "SELECT 
            m.id, m.full_name, m.email, m.created_at, m.inactive_date,
            (SELECT COUNT(*) FROM member_payments mp_count WHERE mp_count.member_id = m.id) as payment_count,
            (SELECT MAX(end_date) FROM member_payments mp_exp WHERE mp_exp.member_id = m.id) as latest_expiry,
            COALESCE(
                (SELECT membership_type FROM member_payments mp_plan WHERE mp_plan.member_id = m.id ORDER BY mp_plan.created_at DESC LIMIT 1),
                m.membership
            ) as membership
        FROM members m 
        WHERE m.status = 'inactive'";

$types = "";
$params = [];

if (!empty($search_val)) {
    $search_term = "%" . $search_val . "%";
    $sql .= " AND (m.full_name LIKE ? OR m.email LIKE ? OR m.phone_number LIKE ?)";
    $types .= "sss";
    $params[] = $search_term;
    $params[] = $search_term;
    $params[] = $search_term;
}

$sql .= " ORDER BY m.id DESC";

$stmt = $conn->prepare($sql);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" size="16x16" href="../icons/favicon-dark-logo.png" type="image/png">
    <title>JOF India | Inactive Members</title>
    <link rel="stylesheet" href="../static/root.css">

</head>

<body class="page-members">

    <button class="mobile-toggle" id="mobileToggle"><img src="../icons/bars-solid-full.svg" class="fa-solid fa-bars"></button>
    <button class="toggle-sidebar-btn" id="toggleBtn"><img src="../icons/chevron-left-solid-full.svg" class="fa-solid fa-chevron-left"></button>

    <div class="dashboard-container">

        <?php include 'sidebar.php'; ?>

        <main class="main-content">

            <header class="header-banner">
                <div class="header-text">
                    <h1>Inactive Members</h1>
                    <p>Members who registered via the public link and are awaiting activation</p>
                </div>
            </header>

            <div class="toolbar">
                <form id="searchForm" method="GET" action="inactive_members.php" class="search-group flex-y-center w-full">
                    <div class="search-bar">
                        <img src="../icons/magnifying-glass-solid-full.svg" class="fa-solid fa-magnifying-glass">
                        <input type="text" name="search" placeholder="Search inactive members..."
                            value="<?= htmlspecialchars($search_val) ?>">
                    </div>
                    <button type="submit" class="filter-btn ml-10 btn-go">Go</button>
                </form>

                <a href="add_member.php" class="btn-primary ml-auto"
                    target="_blank">
                    <img src="../icons/link-solid-full.svg" class="fa-solid fa-link"> Registration Link
                </a>
            </div>

            <div class="table-card">
                <table class="styled-table">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Email</th>
                            <th>Status</th>
                            <th>Registered</th>
                            <th>Recycle In</th>
                            <th class="text-center" style="min-width: 180px;">Actions</th>
                        </tr>
                    </thead>

                    <tbody>
                        <?php if ($result->num_rows > 0): ?>
                            <?php while ($row = $result->fetch_assoc()): ?>
                                <tr>
                                    <td data-label="Name" class="nowrap">
                                        <strong><?= htmlspecialchars($row['full_name']) ?></strong>
                                        <?php if (empty($row['membership']) || $row['membership'] === 'Pending Setup' || $row['payment_count'] == 0): ?>
                                            <span class="badge-new">NEW</span>
                                        <?php endif; ?>
                                    </td>
                                    <td data-label="Email" class="text-muted"><?= htmlspecialchars($row['email'] ?? '-') ?></td>
                                    <td data-label="Status" class="nowrap">
                                        <?php if (empty($row['membership']) || $row['membership'] === 'Pending Setup' || $row['payment_count'] == 0): ?>
                                            <span class="status-pill status-pill-pending">Pending Payment</span>
                                        <?php elseif ($row['payment_count'] > 0): ?>
                                            <span class="status-pill expired" title="Expired on: <?= date("d M Y", strtotime($row['latest_expiry'])) ?>">Membership Expired</span>
                                        <?php else: ?>
                                            <span class="status-pill awaiting">Awaiting Activation</span>
                                        <?php endif; ?>
                                    </td>
                                    <td data-label="Registered">
                                        <?= date("d M Y", strtotime($row['created_at'])) ?>
                                    </td>
                                    <td data-label="Recycle In">
                                        <?php
                                        if ($row['inactive_date']) {
                                            $inactive_ts = strtotime($row['inactive_date']);
                                            $recycle_ts = strtotime('+15 days', $inactive_ts);
                                            $days_left = floor(($recycle_ts - time()) / (60 * 60 * 24));
                                            if ($days_left <= 3) {
                                                echo '<span class="days-left-pill danger">' . max(0, $days_left) . ' days</span>';
                                            } elseif ($days_left <= 7) {
                                                echo '<span class="days-left-pill warning">' . $days_left . ' days</span>';
                                            } else {
                                                echo '<span class="days-left-pill safe">' . $days_left . ' days</span>';
                                            }
                                        } else {
                                            echo '<span class="days-left-pill safe">New</span>';
                                        }
                                        ?>
                                    </td>
                                    <td data-label="Actions">
                                        <div class="action-cell" style="display: flex; gap: 8px; justify-content: center; flex-wrap: nowrap; min-width: 180px;">
                                            <a href="notify_member.php?id=<?= $row['id'] ?>&type=expiry"
                                                class="btn-notify overdue" title="Send Renewal Reminder">
                                                <img src="../icons/bell-solid-full.svg" class="fa-solid fa-bell">
                                            </a>

                                            <a href="javascript:void(0)" onclick="openActivationModal(<?= $row['id'] ?>)" class="btn-activate"
                                                title="Activate & Add Payment">
                                                <img src="../icons/indian-rupee-sign-solid-full.svg" class="fa-solid fa-indian-rupee-sign">
                                            </a>

                                            <a href="person_info.php?id=<?= $row['id'] ?>" class="btn-view"
                                                title="View Profile">
                                                <img src="../icons/eye-solid-full.svg" class="fa-solid fa-eye">
                                            </a>

                                            <button onclick="confirmDelete('inactive_members.php?delete_id=<?= $row['id'] ?>')"
                                                class="btn-delete" style="border:none;cursor:pointer;" title="Delete">
                                                <img src="../icons/trash-solid-full.svg" class="fa-solid fa-trash">
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="6" class="table-empty-container">
                                    <img src="../icons/user-slash-solid-full.svg" class="fa-solid fa-user-slash table-empty-icon">
                                    No inactive members found. Share the registration link to get started!
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

        </main>
    </div>

    <script>
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
        });
    </script>

    <!-- ===== CONFIRM DELETE MODAL ===== -->
    <div id="confirmModal" class="modal-overlay-generic">
        <div class="modal-content-generic">
            <div class="modal-icon-circle bg-danger-light">
                <img src="../icons/triangle-exclamation-solid-full.svg" class="fa-solid fa-triangle-exclamation text-danger header-icon-orange" style="font-size: 26px;">
            </div>
            <h2 class="modal-title-custom">Move to Recycle Bin?</h2>
            <p class="modal-text-custom">This member will be moved to the recycle bin and can be restored or permanently from there.</p>
            <div class="modal-btn-group">
                <button onclick="document.getElementById('confirmModal').style.display='none'" class="btn-outline">Cancel</button>
                <button id="confirmDeleteBtn" class="btn-danger-modal">
                    <img src="../icons/trash-solid-full.svg" class="fa-solid fa-trash mr-8"> Move to Bin
                </button>
            </div>
        </div>
    </div>

    <!-- ===== SUCCESS MODAL ===== -->
    <div id="successModal" class="modal-overlay-generic">
        <div class="modal-content-generic">
            <div class="modal-icon-circle bg-success-gradient">
                <img src="../icons/check-solid-full.svg" class="fa-solid fa-check icon-white" style="font-size: 30px;">
            </div>
            <h2 class="modal-title-custom">Moved to Bin!</h2>
            <p class="modal-text-custom" style="margin-bottom: 0;">The member has been moved to the recycle bin.</p>
            <div class="timer-bar-container">
                <div id="timerBar" class="timer-bar">
                </div>
            </div>
        </div>
    </div>

    

    <div id="activationModal" style="
        display:none; position:fixed; inset:0; z-index:10000;
        background:rgba(0,0,0,0.45); backdrop-filter:blur(4px);
        justify-content:center; align-items:center;">
        <div style="
            background:#fff; border-radius:20px; padding:36px;
            text-align:center; max-width:400px; width:90%;
            box-shadow:0 20px 40px rgba(0,0,0,0.18);
            animation:popIn 0.3s cubic-bezier(.34,1.56,.64,1) both;">
            <div style="width:64px;height:64px;border-radius:50%;background:#EFF6FF;
                display:flex;align-items:center;justify-content:center;margin:0 auto 16px;">
                 <img src="../icons/bolt-solid-full.svg" class="fa-solid fa-bolt" alt="JO">
            </div>
            <h2 style="margin:0 0 8px;font-size:22px;color:#111827;">Activate Membership</h2>
            <p style="margin:0 0 24px;font-size:15px;color:#6B7280;">Is this a <b>New Registration</b> or a <b>Membership Renewal</b>?</p>
            <div style="display:grid; grid-template-columns: 1fr 1fr; gap:12px;">
                <button onclick="goToActivation('new')" style="padding:14px; border-radius:12px; border:2px solid #E5E7EB;
                    background:#fff; color:#374151; font-size:14px; font-weight:700; cursor:pointer; transition: all 0.2s;">
                    <img src="../icons/user-plus-solid-full.svg" class="fa-solid fa-user-plus icon-success" alt="New" style="display:block; margin:0 auto 8px;"> New
                </button>
                <button onclick="goToActivation('renewal')" style="padding:14px; border-radius:12px; border:2px solid #E5E7EB;
                    background:#fff; color:#374151; font-size:14px; font-weight:700; cursor:pointer; transition: all 0.2s;">
                    <img src="../icons/arrows-rotate-solid-full.svg" class="fa-solid fa-arrows-rotate icon-orange" alt="Renewal" style="display:block; margin:0 auto 8px;"> Renewal
                </button>
            </div>
            <button onclick="document.getElementById('activationModal').style.display='none'" style="margin-top:20px; background:none; border:none; color:#9CA3AF; cursor:pointer; font-size:13px; text-decoration:underline;">Cancel</button>
        </div>
    </div>

    <script>
        let currentMemberId = null;

        function openActivationModal(memberId) {
            currentMemberId = memberId;
            document.getElementById('activationModal').style.display = 'flex';
        }

        function goToActivation(type) {
            if (currentMemberId) {
                window.location.href = `payment_details.php?activate_id=${currentMemberId}&type=${type}`;
            }
        }

        function confirmDelete(url) {
            const modal = document.getElementById('confirmModal');
            modal.style.display = 'flex';
            document.getElementById('confirmDeleteBtn').onclick = () => {
                modal.style.display = 'none';
                window.location.href = url;
            };
        }

        <?php if (isset($_GET['msg']) && $_GET['msg'] === 'recycled'): ?>
            window.addEventListener('DOMContentLoaded', () => {
                const modal = document.getElementById('successModal');
                modal.style.display = 'flex';
                const bar = document.getElementById('timerBar');
                bar.style.transition = 'none'; bar.style.width = '100%';
                requestAnimationFrame(() => requestAnimationFrame(() => {
                    bar.style.transition = 'width 2s linear'; bar.style.width = '0%';
                }));
                setTimeout(() => { modal.style.display = 'none'; }, 2000);
            });
        <?php endif; ?>
    </script>

    <script>
        function replaceSVG() {
            var images = document.querySelectorAll('img.fa-solid, img.fa-regular, img[class*="fa-"]');
            images.forEach(function(img) {
                if (img.classList.contains('svg-replaced')) return;
                img.classList.add('svg-replaced');
                
                var imgID = img.id;
                var imgClass = img.className;
                var imgURL = img.src;
                
                if (!imgURL.endsWith('.svg')) return;

                fetch(imgURL)
                    .then(response => response.text())
                    .then(text => {
                        var parser = new DOMParser();
                        var xmlDoc = parser.parseFromString(text, "text/xml");
                        var svg = xmlDoc.getElementsByTagName('svg')[0];

                        if (!svg) return;

                        if (typeof imgID !== 'undefined' && imgID !== '') {
                            svg.setAttribute('id', imgID);
                        }
                        if (typeof imgClass !== 'undefined' && imgClass !== '') {
                            svg.setAttribute('class', imgClass + ' replaced-svg');
                        }

                        svg.removeAttribute('xmlns:a');
                        svg.removeAttribute('width');
                        svg.removeAttribute('height');

                        var paths = svg.querySelectorAll('path');
                        paths.forEach(function(path) {
                            path.setAttribute('fill', 'currentColor');
                        });

                        img.parentNode.replaceChild(svg, img);
                    })
                    .catch(err => console.error('Error fetching SVG:', err));
            });
        }
        
        replaceSVG();

        var observer = new MutationObserver(function(mutations) {
            var shouldRun = false;
            mutations.forEach(function(mutation) {
                if (mutation.addedNodes.length) {
                    shouldRun = true;
                }
            });
            if (shouldRun) replaceSVG();
        });
        
        observer.observe(document.body, { childList: true, subtree: true });
    </script>
</body>

</html>