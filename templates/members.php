<?php
require_once __DIR__ . '/../auth/auth_check.php';
require_role(['admin', 'trainer']);
require_once __DIR__ . '/../config.php';

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
$conn->query("UPDATE personal_training pt
              JOIN members m ON pt.member_id = m.id
              SET pt.end_date = DATE_SUB(CURDATE(), INTERVAL 1 DAY)
              WHERE m.status = 'inactive' AND pt.end_date >= CURDATE()");

// === AUTO-RECYCLE: Move inactive members to recycled after 15 days ===
$conn->query("UPDATE members SET status = 'recycled' WHERE status = 'inactive' AND inactive_date IS NOT NULL AND inactive_date < DATE_SUB(NOW(), INTERVAL 15 DAY)");

// === FETCH RECENTLY EXPIRED MEMBERS (last 5 days) for alert banner ===
$expired_members = [];
$exp_alert = $conn->query("
    SELECT m.full_name, m.inactive_date,
           (SELECT MAX(mp.end_date) FROM member_payments mp WHERE mp.member_id = m.id) as expired_on
    FROM members m
    WHERE m.status = 'inactive'
    AND m.inactive_date >= DATE_SUB(NOW(), INTERVAL 5 DAY)
    AND EXISTS (SELECT 1 FROM member_payments mp WHERE mp.member_id = m.id)
    ORDER BY m.inactive_date DESC
");
if ($exp_alert && $exp_alert->num_rows > 0) {
    while ($exp_row = $exp_alert->fetch_assoc()) {
        $expired_members[] = $exp_row['full_name'];
    }
}

// === DELETE LOGIC ===
if (isset($_GET['delete_id'])) {
    $del_id = intval($_GET['delete_id']);
    $stmt_del = $conn->prepare("UPDATE members SET status = 'recycled', inactive_date = NOW() WHERE id = ?");
    $stmt_del->bind_param("i", $del_id);
    if ($stmt_del->execute()) {
        header("Location: members.php?msg=recycled");
        exit;
    } else {
        die("Error deleting member: " . $stmt_del->error);
    }
}

$search_val = isset($_GET['search']) ? trim($_GET['search']) : '';
$filter_val = isset($_GET['filter']) ? trim($_GET['filter']) : '';

// Fetch distinct membership plans for filter dropdown
$plan_query = "SELECT DISTINCT plan_name FROM membership_plans ORDER BY plan_name ASC";
$plan_result = $conn->query($plan_query);
$membership_plans_list = [];
if ($plan_result) {
    while ($p_row = $plan_result->fetch_assoc()) {
        $membership_plans_list[] = $p_row['plan_name'];
    }
}

$sql = "SELECT 
            m.id, m.full_name, m.email, m.created_at,
            (SELECT COUNT(*) FROM member_payments mp_count WHERE mp_count.member_id = m.id) as payment_count,
            (SELECT MAX(end_date) FROM member_payments mp_exp WHERE mp_exp.member_id = m.id) as latest_expiry,
            COALESCE(
                (SELECT membership_type FROM member_payments mp_plan WHERE mp_plan.member_id = m.id ORDER BY mp_plan.created_at DESC LIMIT 1),
                m.membership
            ) as membership,
            (SELECT MIN(next_due_date) 
             FROM member_payments mp 
             WHERE mp.member_id = m.id AND mp.balance_pending > 0) as payment_due_date,
            (SELECT installments_count FROM member_payments mp WHERE mp.member_id = m.id ORDER BY mp.created_at DESC LIMIT 1) as total_installments,
            (SELECT COUNT(*) FROM installment_payments ip WHERE ip.payment_id = (SELECT payment_id FROM member_payments WHERE member_id = m.id ORDER BY created_at DESC LIMIT 1)) as extra_installments_paid,
            (SELECT balance_pending FROM member_payments mp WHERE mp.member_id = m.id ORDER BY created_at DESC LIMIT 1) as balance_pending
        FROM members m 
        WHERE m.status = 'active'";

$types = "";
$params = [];

if (!empty($search_val)) {
    $search_term = "%" . $search_val . "%";
    $sql .= " AND (m.full_name LIKE ? OR m.email LIKE ?)";
    $types .= "ss";
    $params[] = $search_term;
    $params[] = $search_term;
}


$sql .= " ORDER BY m.id DESC";

$stmt = $conn->prepare($sql);
if (!$stmt) {
    die("SQL Error: " . $conn->error . " <br> Query: " . $sql);
}
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
    <title>JOF India | Members</title>
    <link rel="stylesheet" href="../static/root.css">
    <style>
        .modal-overlay {
            position: fixed;
            top: 0; left: 0; width: 100%; height: 100%;
            background: rgba(0,0,0,0.6);
            backdrop-filter: blur(5px);
            z-index: 100000;
            display: none;
            align-items: center;
            justify-content: center;
            opacity: 0;
            transition: opacity 0.3s ease;
        }
        .modal-overlay.active {
            display: flex;
            opacity: 1;
        }
        .modal-card {
            background: #fff;
            padding: 30px;
            border-radius: 20px;
            max-width: 400px;
            width: 90%;
            text-align: center;
            transform: translateY(20px);
            transition: transform 0.3s ease;
        }
        .modal-overlay.active .modal-card {
            transform: translateY(0);
        }
        .modal-btn-success {
            background: #10B981;
            color: #fff;
            border: none;
            padding: 12px;
            border-radius: 12px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
        }
        .modal-btn-success:hover {
            background: #059669;
            transform: translateY(-2px);
        }

        /* Expiry Alert Banner */
        .expiry-alert-banner {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            background: #FEF2F2;
            border: 1px solid #FECACA;
            border-left: 4px solid #EF4444;
            border-radius: 12px;
            padding: 14px 20px;
            margin-bottom: 10px;
            animation: slideDown 0.4s ease;
        }
        .expiry-alert-text {
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 14px;
            color: #7F1D1D;
            line-height: 1.5;
        }
        .expiry-alert-text .fa-triangle-exclamation {
            color: #EF4444;
            font-size: 16px;
            flex-shrink: 0;
            width: 16px;
            height: 16px;
        }
        .expiry-alert-btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: #10B981;
            color: #fff;
            padding: 8px 16px;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 600;
            text-decoration: none;
            white-space: nowrap;
            transition: all 0.2s;
        }
        .expiry-alert-btn:hover {
            background: #059669;
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(16, 185, 129, 0.3);
        }
        .expiry-alert-btn .fa-eye {
            width: 14px;
            height: 14px;
        }
        @keyframes slideDown {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
        }
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
                    <h1>Members</h1>
                    <p>Manage gym members and their health information</p>
                </div>
            </header>

            <?php if (!empty($expired_members)): ?>
                <div class="expiry-alert-banner">
                    <div class="expiry-alert-text">
                        <img src="../icons/triangle-exclamation-solid-full.svg" class="fa-solid fa-triangle-exclamation">
                        <span>
                            <?php
                            $count = count($expired_members);
                            $names = array_map('htmlspecialchars', $expired_members);
                            if ($count === 1) {
                                echo '<strong>' . $names[0] . '</strong>\'s membership has expired and was moved to inactive.';
                            } else {
                                $last = array_pop($names);
                                echo '<strong>' . implode('</strong>, <strong>', $names) . '</strong> and <strong>' . $last . '</strong> — memberships expired, moved to inactive.';
                            }
                            ?>
                        </span>
                    </div>
                    <a href="inactive_members.php" class="expiry-alert-btn">
                        <img src="../icons/eye-solid-full.svg" class="fa-solid fa-eye"> View Inactive
                    </a>
                </div>
            <?php endif; ?>

            <div class="toolbar">
                <form id="searchForm" method="GET" action="members.php" class="search-group flex-y-center w-full">
                    <input type="hidden" name="filter" id="filterInput" value="<?= htmlspecialchars($filter_val) ?>">

                    <div class="search-bar">
                        <img src="../icons/magnifying-glass-solid-full.svg" class="fa-solid fa-magnifying-glass">
                        <input type="text" name="search" placeholder="Search members..."
                            value="<?= htmlspecialchars($search_val) ?>">
                    </div>

                    <div class="filter-wrapper">
                        <button type="button" class="filter-btn" id="filterToggleBtn" onclick="toggleFilterCard(event)">
                            <img src="../icons/filter-solid-full.svg" class="fa-solid fa-filter">
                            <?= !empty($filter_val) ? ucfirst(str_replace('_', ' ', $filter_val)) : 'Filter' ?>
                        </button>
                        <div class="filter-card-popup" id="filterCard">
                            <div class="filter-header">Filter by Plan</div>
                            <button type="button" class="filter-option-btn <?= $filter_val == '' ? 'active' : '' ?>"
                                onclick="applyFilter('')"><img src="../icons/check-solid-full.svg" class="fa-solid fa-check"> All Plans</button>
                            <?php foreach ($membership_plans_list as $plan): ?>
                                <button type="button" class="filter-option-btn <?= $filter_val == $plan ? 'active' : '' ?>"
                                    onclick="applyFilter('<?= htmlspecialchars($plan) ?>')"><img src="../icons/check-solid-full.svg" class="fa-solid fa-check"> <?= htmlspecialchars($plan) ?></button>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <button type="submit" class="filter-btn ml-10 btn-go">Go</button>
                </form>
                <a href="add_member.php" class="btn-primary ml-auto"><img src="../icons/user-plus-solid-full.svg"
                        class="fa-solid fa-user-plus"> Add Member</a>
            </div>

            <div class="table-card">
                <table class="styled-table">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Email</th>
                            <th>Membership</th>
                            <th class="text-center">Installments</th>
                            <th>Status</th>
                            <th class="text-center">Actions</th>
                        </tr>
                    </thead>

                    <tbody>
                        <?php if ($result->num_rows > 0): ?>

                            <?php while ($rows = $result->fetch_assoc()): ?>

                                <?php
                                // --- 1. MEMBERSHIP EXPIRY LOGIC ---
                                if (!empty($rows['latest_expiry'])) {
                                    $expiry_timestamp = strtotime($rows['latest_expiry']);
                                    $mem_days_left = floor(($expiry_timestamp - time()) / (60 * 60 * 24));
                                } else {
                                    $mem_days_left = 999; // Assume safe if no end_date found yet (they will be in inactive_members anyway if they never paid)
                                }

                                // --- 2. PAYMENT DUE LOGIC ---
                                $pay_days_left = 999;
                                $has_pending_payment = false;

                                if (!empty($rows['payment_due_date'])) {
                                    $has_pending_payment = true;
                                    $pay_diff = strtotime($rows['payment_due_date']) - time();
                                    $pay_days_left = floor($pay_diff / (60 * 60 * 24));
                                }

                                // --- 3. DETERMINE STATUS & NOTIFICATION ---
                                $status = "Active";
                                $statusClass = "active";
                                $notificationBtn = "";

                                // PRIORITY 1: Payment Overdue
                                if ($has_pending_payment && $pay_days_left < 0) {
                                    $status = "Payment Overdue";
                                    $statusClass = "expired"; // Red
                                    $notificationBtn = '<a href="notify_member.php?id=' . $rows['id'] . '&type=overdue" class="btn-notify overdue" title="Payment Overdue!"><img src="../icons/bell-solid-full.svg" class="fa-solid fa-bell"></a>';

                                    // PRIORITY 2: Membership Expired
                                } elseif ($mem_days_left < 0) {
                                    $status = "Expired";
                                    $statusClass = "expired";
                                    $notificationBtn = '<a href="notify_member.php?id=' . $rows['id'] . '&type=expiry" class="btn-notify overdue" title="Membership Expired"><img src="../icons/bell-solid-full.svg" class="fa-solid fa-bell"></a>';

                                    // PRIORITY 3: Payment Due Soon
                                } elseif ($has_pending_payment && $pay_days_left <= 15) {
                                    $status = "Payment Due: $pay_days_left days";
                                    $statusClass = "pending-status"; // Yellow/Orange
                                    $notificationBtn = '<a href="notify_member.php?id=' . $rows['id'] . '&type=payment_due" class="btn-notify due-soon" title="Payment Reminder"><img src="../icons/bell-solid-full.svg" class="fa-solid fa-bell"></a>';

                                    // PRIORITY 4: Membership Expiring Soon
                                } elseif ($mem_days_left <= 15) {
                                    $status = "Expiring: $mem_days_left days";
                                    $statusClass = "pending-status";
                                    $notificationBtn = '<a href="notify_member.php?id=' . $rows['id'] . '&type=expiry_soon" class="btn-notify due-soon" title="Membership Renewal"><img src="../icons/bell-solid-full.svg" class="fa-solid fa-bell"></a>';
                                }

                                // --- 4. DETERMINE STATUS CATEGORY FOR FILTERING ---
                                $statusCategory = $rows['membership'];


                                // --- 5. APPLY STATUS FILTER ---
                                if (!empty($filter_val) && $filter_val !== $statusCategory) {
                                    continue; // Skip this member, doesn't match filter
                                }
                                ?>

                                <tr>
                                    <td data-label="Name">
                                        <strong><?= htmlspecialchars($rows['full_name']) ?></strong>
                                        <?php if (isset($rows['payment_count']) && $rows['payment_count'] == 0): ?>
                                            <span class="badge-new">NEW</span>
                                        <?php endif; ?>
                                    </td>
                                    <td data-label="Email" class="text-muted"><?= htmlspecialchars($rows['email']) ?></td>
                                    <td data-label="Membership">
                                        <span class="badge purple"><?= htmlspecialchars($rows['membership']) ?></span>
                                    </td>
                                    
                                    <td data-label="Installments" class="text-center">
                                        <?php
                                        if ($rows['payment_count'] > 0) {
                                            $tot_inst = intval($rows['total_installments']);
                                            $paid_inst = 1 + intval($rows['extra_installments_paid']);
                                            
                                            // If balance is cleared, they paid all installments
                                            if (floatval($rows['balance_pending']) <= 0) {
                                                $paid_inst = $tot_inst;
                                            }
                                            
                                            if ($tot_inst > 0) {
                                                echo "<span style='font-weight:600; font-size:14px; color:#4B5563;'>" . $paid_inst . " / " . $tot_inst . "</span>";
                                            } else {
                                                echo "-";
                                            }
                                        } else {
                                            echo "-";
                                        }
                                        ?>
                                    </td>

                                    <td data-label="Status">
                                        <span class="status-pill <?= $statusClass ?>">
                                            <?= $status ?>
                                        </span>
                                    </td>

                                    <td data-label="Actions">
                                        <div class="action-cell">
                                            <?= $notificationBtn ?>


                                            <a href="person_info.php?id=<?= $rows['id'] ?>" class="btn-view"
                                                title="View Profile">
                                                <img src="../icons/eye-solid-full.svg" class="fa-solid fa-eye">
                                            </a>
                                            <button onclick="memberConfirmDelete('members.php?delete_id=<?= $rows['id'] ?>')"
                                                class="btn-delete" style="border:none;cursor:pointer;" title="Delete">
                                                <img src="../icons/trash-solid-full.svg" class="fa-solid fa-trash">
                                            </button>
                                        </div>
                                    </td>

                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="6" class="table-empty-row">No members found.</td>
                            </tr>
                        <?php endif; ?>

                    </tbody>
                </table>
            </div>

        </main>
    </div>

    <script>
        function toggleFilterCard(e) { e.stopPropagation(); document.getElementById('filterCard').classList.toggle('show'); }
        function applyFilter(v) { document.getElementById('filterInput').value = v; document.getElementById('searchForm').submit(); }
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
        });
    </script>

    <!-- ===== CONFIRM DELETE MODAL ===== -->
    <div id="memConfirmModal" class="modal-overlay">
        <div class="modal-card">
            <div class="modal-icon-box danger">
                <img src="../icons/triangle-exclamation-solid-full.svg" class="fa-solid fa-triangle-exclamation" style="font-size:26px;">
            </div>
            <h2 class="modal-title">Move to Recycle Bin?</h2>
            <p class="modal-desc">This member will be moved to the recycle bin and can be restored or permanently deleted from there.</p>
            <div class="modal-actions">
                <button onclick="document.getElementById('memConfirmModal').classList.remove('active')" class="modal-btn modal-btn-secondary">Cancel</button>
                <button id="memConfirmDeleteBtn" class="modal-btn modal-btn-danger">
                    <img src="../icons/trash-solid-full.svg" class="fa-solid fa-trash"> Move to Bin
                </button>
            </div>
        </div>
    </div>

    <!-- ===== DELETE SUCCESS MODAL ===== -->
    <div id="memSuccessModal" class="modal-overlay">
        <div class="modal-card">
            <div class="modal-icon-box success">
                <img src="../icons/check-solid-full.svg" class="fa-solid fa-check" style="font-size:30px;">
            </div>
            <h2 class="modal-title">Moved to Bin!</h2>
            <p class="modal-desc">The member has been moved to the recycle bin.</p>
            <button onclick="document.getElementById('memSuccessModal').classList.remove('active')" class="modal-btn modal-btn-success" style="margin-top: 15px; width: 100%;">Okay</button>
            <div class="timer-bar-container">
                <div id="memTimer" class="timer-bar"></div>
            </div>
        </div>
    </div>

    <script>
        function memberConfirmDelete(url) {
            const modal = document.getElementById('memConfirmModal');
            modal.classList.add('active');
            document.getElementById('memConfirmDeleteBtn').onclick = () => {
                modal.classList.remove('active');
                window.location.href = url;
            };
        }

        <?php if (isset($_GET['msg']) && $_GET['msg'] === 'recycled'): ?>
        window.addEventListener('DOMContentLoaded', () => {
            const modal = document.getElementById('memSuccessModal');
            modal.classList.add('active');
            const bar = document.getElementById('memTimer');
            bar.style.transition = 'none'; bar.style.width = '100%';
            requestAnimationFrame(() => requestAnimationFrame(() => {
                bar.style.transition = 'width 2s linear'; bar.style.width = '0%';
            }));
            setTimeout(() => { modal.classList.remove('active'); }, 2000);
        });
        <?php endif; ?>

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