<?php
require_once __DIR__ . '/../auth/auth_check.php';
require_role(['admin', 'trainer']);
require_once __DIR__ . '/../config.php';

// ---------------------------------------------------------
// 1. HANDLE ASSIGN TRAINER (POST REQUEST)
// ---------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['assign_trainer_btn'])) {
    $pt_id_to_update = intval($_POST['pt_id']);
    $trainer_id_sel = intval($_POST['trainer_id']);

    if ($pt_id_to_update > 0 && $trainer_id_sel > 0) {
        // Update the record with the real trainer ID
        $update_sql = "UPDATE personal_training SET trainer_id = ? WHERE id = ?";
        $stmt = $conn->prepare($update_sql);
        $stmt->bind_param("ii", $trainer_id_sel, $pt_id_to_update);

        if ($stmt->execute()) {
            // Success: Refresh page
            header("Location: personal_training.php?msg=assigned");
            exit;
        } else {
            $error = "Error assigning trainer: " . $conn->error;
        }
    }
}

// ---------------------------------------------------------
// 2. HANDLE DELETE
// ---------------------------------------------------------
if (isset($_GET['delete_id'])) {
    $deleteId = intval($_GET['delete_id']);
    $deleteSql = "DELETE FROM personal_training WHERE id = {$deleteId}";
    mysqli_query($conn, $deleteSql);
    header("Location: personal_training.php?msg=deleted");
    exit;
}

// ---------------------------------------------------------
// 3. FETCH DATA
// ---------------------------------------------------------

// A. Fetch Trainers for the Dropdown
$trainers_query = "SELECT id, full_name FROM trainers";
$trainers_result = mysqli_query($conn, $trainers_query);
$trainers_list = [];
if ($trainers_result) {
    while ($t = mysqli_fetch_assoc($trainers_result)) {
        $trainers_list[] = $t;
    }
}

// B. Fetch PT Records
$sql = "
    SELECT 
        pt.id,
        pt.total_sessions,
        pt.sessions_used,
        (SELECT COUNT(*) FROM pt_sessions ps WHERE ps.pt_id = pt.id AND ps.status = 'Completed') AS actually_completed,
        pt.start_date,
        pt.end_date,
        pt.member_id,
        m.full_name AS member_name,
        COALESCE(
            (SELECT membership_type FROM member_payments mp_plan WHERE mp_plan.member_id = m.id ORDER BY mp_plan.created_at DESC LIMIT 1),
            m.membership
        ) AS membership,
        t.id AS current_trainer_id,
        COALESCE(t.full_name, 'Not Assigned') AS trainer_name
    FROM personal_training pt
    JOIN members m ON pt.member_id = m.id
    LEFT JOIN trainers t ON pt.trainer_id = t.id
    WHERE pt.total_sessions > 0
      AND (pt.end_date >= CURDATE()
           OR (pt.total_sessions > 0 AND (SELECT COUNT(*) FROM pt_sessions ps WHERE ps.pt_id = pt.id AND ps.status = 'Completed') >= pt.total_sessions))
    ORDER BY pt.created_at DESC
";

$result = mysqli_query($conn, $sql);
if (!$result) {
    die("Database Error: " . mysqli_error($conn));
}

// Count records in inactive_pt (expired OR no sessions assigned) for the banner
$inactive_count_result = mysqli_query($conn, "
    SELECT COUNT(*) AS cnt FROM personal_training pt
    WHERE pt.total_sessions = 0
       OR (pt.end_date < CURDATE() AND (SELECT COUNT(*) FROM pt_sessions ps WHERE ps.pt_id = pt.id AND ps.status = 'Completed') < pt.total_sessions)
");
$inactive_count = $inactive_count_result ? mysqli_fetch_assoc($inactive_count_result)['cnt'] : 0;



// Status Helper
function ptStatus($endDate, $total, $completed)
{
    $today = strtotime(date('Y-m-d'));
    $end = strtotime($endDate);
    $daysLeft = ceil(($end - $today) / 86400);

    if ($total > 0 && $completed >= $total)
        return ['label' => 'Completed', 'class' => 'completed'];
    if ($daysLeft < 0)
        return ['label' => 'Expired', 'class' => 'expired'];
    if ($daysLeft <= 7)
        return ['label' => "Expiring ({$daysLeft} days)", 'class' => 'expiring'];
    return ['label' => 'Active', 'class' => 'active'];
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" size="16x16" href="../icons/favicon-dark-logo.png" type="image/png">
    <title>Personal Training | JOF INDIA</title>
    <!-- <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"> -->
    <link rel="stylesheet" href="../static/root.css">
    <style>
        /* Premium Modal Support for PT Page */
        .page-members .modal-overlay {
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.4);
            backdrop-filter: blur(8px);
            z-index: 10000;
            display: none;
            align-items: center;
            justify-content: center;
            opacity: 0;
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            padding: 20px;
            pointer-events: none;
        }

        .page-members .modal-overlay.active {
            display: flex;
            opacity: 1;
            pointer-events: all;
        }

        .page-members .modal-card {
            background: #fff;
            border-radius: 20px;
            width: 100%;
            max-width: 400px;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
            overflow: visible;
            transform: scale(0.9) translateY(30px);
            transition: all 0.4s cubic-bezier(0.34, 1.56, 0.64, 1);
            position: relative;
        }

        .page-members .modal-overlay.active .modal-card {
            transform: scale(1) translateY(0);
        }

        .page-members .modal-header {
            padding: 20px 24px;
            border-bottom: 1px solid #F3F4F6;
            display: flex;
            align-items: center;
            justify-content: space-between;
            background: #fff;
            border-radius: 20px 20px 0 0;
        }

        .page-members .modal-header h3 {
            font-size: 19px;
            font-weight: 800;
            color: #111827;
            margin: 0;
            letter-spacing: -0.5px;
        }

        .page-members .modal-header button {
            background: #F3F4F6;
            border: none;
            width: 36px;
            height: 36px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .page-members .modal-header button:hover {
            background: #E5E7EB;
            transform: rotate(90deg);
        }

        .page-members .modal-content {
            padding: 24px;
        }

        .page-members .form-group {
            margin-bottom: 18px;
        }

        .page-members .form-group label {
            display: block;
            font-size: 12px;
            font-weight: 700;
            color: #4B5563;
            margin-bottom: 6px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .page-members .form-select {
            width: 100%;
            padding: 14px 16px !important;
            border: 2px solid #F3F4F6 !important;
            border-radius: 14px !important;
            font-size: 15px;
            font-weight: 500;
            outline: none;
            transition: all 0.2s ease;
            background: #F9FAFB !important;
            color: #111827;
            cursor: pointer;
            appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 24 24' stroke='%236B7280'%3E%3Cpath stroke-linecap='round' stroke-linejoin='round' stroke-width='2' d='M19 9l-7 7-7-7'%3E%3C/path%3E%3C/svg%3E") !important;
            background-repeat: no-repeat !important;
            background-position: right 16px center !important;
            background-size: 18px !important;
        }
        
        @keyframes ptPopIn {
            0% { transform: scale(0.9) translateY(20px); opacity: 0; }
            100% { transform: scale(1) translateY(0); opacity: 1; }
        }

        @media (max-width: 600px) {
            .page-members .modal-overlay { align-items: flex-end; padding: 0; }
            .page-members .modal-card { max-width: 100%; border-radius: 30px 30px 0 0; transform: translateY(100%); }
            .page-members .modal-overlay.active .modal-card { transform: translateY(0); }
        }
    </style>
</head>

<body class="page-members">

    <button class="mobile-toggle" id="mobileToggle"><img src="../icons/bars-solid-full.svg"
            class="fa-solid fa-bars"></button>
    <button class="toggle-sidebar-btn" id="toggleBtn"><img src="../icons/chevron-left-solid-full.svg"
            class="fa-solid fa-chevron-left"></button>

    <div class="dashboard-container">
        <?php include 'sidebar.php'; ?>

        <main class="main-content">
            <header class="header-banner">
                <div class="header-text">
                    <h1>Personal Training</h1>
                    <p>Track trainer assignments and sessions.</p>
                </div>
            </header>

            <?php if ($inactive_count > 0): ?>
                <div
                    style="background:#FEF2F2; border:1px solid #FECACA; border-radius:10px; padding:12px 18px; margin-bottom:16px; display:flex; align-items:center; justify-content:space-between; gap:12px;">
                    <span style="color:#991B1B; font-size:14px; font-weight:500;">
                        <img src="../icons/triangle-exclamation-solid-full.svg" class="fa-solid fa-triangle-exclamation"
                            style="margin-right:6px;">
                        <strong><?= $inactive_count ?></strong> PT package<?= $inactive_count > 1 ? 's are' : ' is' ?>
                        pending session assignment or expired — hidden from this view.
                    </span>
                    <a href="inactive_pt.php"
                        style="background:#DC2626; color:#fff; padding:6px 14px; border-radius:6px; font-size:13px; font-weight:600; text-decoration:none; white-space:nowrap;">
                        <img src="../icons/eye-solid-full.svg" class="fa-solid fa-eye"> View Inactive
                    </a>
                </div>
            <?php endif; ?>

            <?php if (isset($_GET['activated'])): ?>
                <div id="scheduleBanner"
                    style="background:linear-gradient(135deg,#ECFDF5,#D1FAE5); border:1px solid #6EE7B7; border-radius:10px; padding:14px 20px; margin-bottom:16px; display:flex; align-items:center; justify-content:space-between; gap:12px; box-shadow:0 2px 8px rgba(16,185,129,0.12);">
                    <div style="display:flex; align-items:center; gap:12px;">
                        <div
                            style="width:40px; height:40px; border-radius:10px; background:#10B981; display:flex; align-items:center; justify-content:center; flex-shrink:0;">
                            <img src="../icons/calendar-plus-solid-full.svg" class="fa-solid fa-calendar-plus"
                                style="color:#fff; font-size:18px;">
                        </div>
                        <div>
                            <div style="font-size:14px; font-weight:700; color:#065F46;">🎉 Sessions Assigned Successfully!
                            </div>
                            <div style="font-size:13px; color:#047857; margin-top:2px;">
                                The member is now active. Don't forget to <strong>schedule their PT sessions</strong> on the
                                calendar.
                            </div>
                        </div>
                    </div>
                    <div style="display:flex; align-items:center; gap:8px; flex-shrink:0;">
                        <a href="pt_session.php"
                            style="background:#10B981; color:#fff; padding:8px 16px; border-radius:8px; font-size:13px; font-weight:600; text-decoration:none; white-space:nowrap;">
                            <img src="../icons/calendar-day-solid-full.svg" class="fa-solid fa-calendar-days"> Schedule
                            Sessions
                        </a>
                        <button
                            onclick="document.getElementById('scheduleBanner').style.display='none'; history.replaceState(null,'',location.pathname)"
                            style="background:none; border:none; color:#6B7280; font-size:18px; cursor:pointer; padding:4px 8px; line-height:1;">✕</button>
                    </div>
                </div>
            <?php endif; ?>

            <div class="toolbar">
                <div class="search-group" style="display:flex; align-items:center; width:100%;">
                    <div class="search-bar">
                        <img src="../icons/magnifying-glass-solid-full.svg" class="fa-solid fa-magnifying-glass">
                        <input type="text" id="ptSearch" placeholder="Search members..." oninput="filterTable()">
                    </div>

                    <div class="filter-wrapper">
                        <button type="button" class="filter-btn" id="ptFilterBtn" onclick="togglePtFilter(event)">
                            <img src="../icons/filter-solid-full.svg" class="fa-solid fa-filter"> <span
                                id="ptFilterLabel">Filter</span>
                        </button>
                        <div class="filter-card-popup" id="ptFilterCard">
                            <div class="filter-header">Status</div>
                            <button type="button" class="filter-option-btn active" onclick="setPtFilter('', this)"><img
                                    src="../icons/check-solid-full.svg" class="fa-solid fa-check"> All</button>
                            <button type="button" class="filter-option-btn" onclick="setPtFilter('active', this)"><img
                                    src="../icons/check-solid-full.svg" class="fa-solid fa-check"> Active</button>
                            <button type="button" class="filter-option-btn" onclick="setPtFilter('expiring', this)"><img
                                    src="../icons/check-solid-full.svg" class="fa-solid fa-check"> Expiring</button>
                            <button type="button" class="filter-option-btn" onclick="setPtFilter('expired', this)"><img
                                    src="../icons/check-solid-full.svg" class="fa-solid fa-check"> Expired</button>
                            <button type="button" class="filter-option-btn"
                                onclick="setPtFilter('completed', this)"><img src="../icons/check-solid-full.svg"
                                    class="fa-solid fa-check"> Completed</button>
                        </div>
                    </div>

                    <button type="button" class="filter-btn" onclick="filterTable()"
                        style="margin-left:10px; border:none; background:var(--text-main); color:white;">Go</button>
                </div>

                <a href="add_trainer.php" class="btn-primary"
                    style="margin-left:auto; display:inline-flex; align-items:center; gap:8px;">
                    <img src="../icons/user-plus-solid-full.svg" class="fa-solid fa-user-plus"> Add Trainer
                </a>
            </div>

            <div class="table-card">
                <table class="styled-table">
                    <thead>
                        <tr>
                            <th>Member Name</th>
                            <th>Membership</th>
                            <th>Trainer</th>
                            <th>Total</th>
                            <th>Used</th>
                            <th>Remaining</th>
                            <th>Valid Till</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (mysqli_num_rows($result) > 0): ?>
                            <?php while ($row = mysqli_fetch_assoc($result)): ?>
                                <?php
                                $used = min($row['sessions_used'], $row['total_sessions']);
                                $completed = $row['actually_completed'];
                                $remaining = max(0, $row['total_sessions'] - $used);
                                $status = ptStatus($row['end_date'], $row['total_sessions'], $completed);
                                $isUnassigned = ($row['trainer_name'] === 'Not Assigned');
                                ?>
                                <tr data-name="<?= strtolower(htmlspecialchars($row['member_name'])) ?>"
                                    data-status="<?= $status['class'] ?>">
                                    <td data-label="Member"><strong><?= htmlspecialchars($row['member_name']) ?></strong></td>
                                    <td data-label="Membership"><?php
                                    $plan = strtolower($row['membership'] ?? '');
                                    if (str_contains($plan, 'premium'))
                                        $badgeClass = 'purple';
                                    elseif (str_contains($plan, 'elite'))
                                        $badgeClass = 'gold';
                                    elseif (str_contains($plan, 'vip'))
                                        $badgeClass = 'amber';
                                    elseif (str_contains($plan, 'standard'))
                                        $badgeClass = 'blue';
                                    elseif ($plan !== '')
                                        $badgeClass = 'teal';
                                    else
                                        $badgeClass = 'gray';
                                    ?><span
                                            class="badge <?= $badgeClass ?>"><?= htmlspecialchars($row['membership']) ?></span>
                                    </td>

                                    <td data-label="Trainer">
                                        <?php if ($isUnassigned): ?>
                                            <button
                                                onclick="openAssignModal(<?= $row['id'] ?>, '<?= htmlspecialchars($row['member_name']) ?>')"
                                                class="btn-assign">
                                                <img src="../icons/user-plus-solid-full.svg" class="fa-solid fa-user-plus"> Assign
                                            </button>
                                        <?php else: ?>
                                            <span style="color:#333; font-weight:500;">
                                                <?= htmlspecialchars($row['trainer_name']) ?>
                                            </span>
                                        <?php endif; ?>
                                    </td>

                                    <td data-label="Total"><?= $row['total_sessions'] ?></td>
                                    <td data-label="Used"><?= $used ?></td>
                                    <td data-label="Remaining" class="sessions-remaining"><?= $remaining ?></td>
                                    <td data-label="Valid Till"><?= date('M d, Y', strtotime($row['end_date'])) ?></td>
                                    <td data-label="Status"><span
                                            class="pt-status <?= $status['class'] ?>"><?= $status['label'] ?></span></td>
                                    <td data-label="Actions" class="action-icons" style="white-space:nowrap;">
                                        <div style="display:inline-flex; align-items:center; gap:6px;">
                                            <a href="#"
                                                onclick="openAssignModal(<?= $row['id'] ?>, '<?= htmlspecialchars($row['member_name']) ?>'); return false;"
                                                class="btn-view"
                                                title="<?= $isUnassigned ? 'Assign Trainer' : 'Change Trainer' ?>"><img
                                                    src="../icons/user-pen-solid-full.svg" class="fa-solid fa-user-pen"></a>
                                            <a href="member_pt_details.php?pt_id=<?= $row['id'] ?>" class="btn-view"
                                                title="View Details"><img src="../icons/eye-solid-full.svg"
                                                    class="fa-solid fa-eye"></a>
                                            <button
                                                onclick="ptConfirmDelete('personal_training.php?delete_id=<?= $row['id'] ?>')"
                                                class="btn-delete" style="border:none;cursor:pointer;" title="Delete"><img
                                                    src="../icons/trash-solid-full.svg" class="fa-solid fa-trash"></button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="9" style="text-align:center;padding:20px;">No Personal Training records found.
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </main>
    </div>

    <!-- ===== ASSIGN/CHANGE TRAINER MODAL ===== -->
    <div class="modal-overlay" id="assignModal">
        <div class="modal-card">
            <div class="modal-header">
                <h3>Assign Trainer</h3>
                <button type="button" onclick="closeAssignModal()"><img src="../icons/xmark-solid-full.svg" class="fa-solid fa-xmark" style="width:16px; height:16px;"></button>
            </div>
            <div class="modal-content">
                <p id="assignMemberName" style="color:#6B7280; font-size:14px; margin-bottom:20px; font-weight:500;"></p>

                <form method="POST">
                    <input type="hidden" name="pt_id" id="assignPtId">
                    <div class="form-group">
                        <label>Select Trainer</label>
                        <select name="trainer_id" id="assignTrainerSelect" class="form-select" required>
                            <option value="" disabled selected>Select a Trainer</option>
                            <?php foreach ($trainers_list as $trainer): ?>
                                <option value="<?= $trainer['id'] ?>"><?= htmlspecialchars($trainer['full_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <button type="submit" name="assign_trainer_btn" class="btn-save" style="width:100%; padding:14px; border-radius:12px; border:none; background:linear-gradient(135deg,#F25C2A,#ff8c69); color:#fff; font-size:15px; font-weight:700; cursor:pointer; margin-top:10px; box-shadow:0 10px 15px -3px rgba(242, 92, 42, 0.2); transition:all 0.2s;">
                        Save Assignment
                    </button>
                </form>
            </div>
        </div>
    </div>

    <script>
        // Sidebar Toggle
        document.getElementById('toggleBtn')?.addEventListener('click', () => document.body.classList.toggle('collapsed'));
        document.getElementById('mobileToggle')?.addEventListener('click', () => document.body.classList.toggle('sidebar-open'));

        // --- Search & Filter Logic ---
        let currentStatus = '';

        function filterTable() {
            const query = document.getElementById('ptSearch').value.toLowerCase();
            const rows = document.querySelectorAll('.styled-table tbody tr[data-name]');
            rows.forEach(row => {
                const nameMatch = row.dataset.name.includes(query);
                const statusMatch = currentStatus === '' || row.dataset.status === currentStatus;
                row.style.display = (nameMatch && statusMatch) ? '' : 'none';
            });
        }

        function setPtFilter(status, btn) {
            currentStatus = status;
            const label = status === '' ? 'Filter' : btn.textContent.trim();
            document.getElementById('ptFilterLabel').textContent = label;
            document.querySelectorAll('#ptFilterCard .filter-option-btn').forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            document.getElementById('ptFilterCard').classList.remove('show');
            filterTable();
        }

        function togglePtFilter(e) {
            e.stopPropagation();
            document.getElementById('ptFilterCard').classList.toggle('show');
        }

        document.addEventListener('click', (e) => {
            const card = document.getElementById('ptFilterCard');
            const btn = document.getElementById('ptFilterBtn');
            if (card && card.classList.contains('show') && !card.contains(e.target) && !btn.contains(e.target)) {
                card.classList.remove('show');
            }
        });

        // Modal Logic
        const assignModal = document.getElementById('assignModal');
        const assignPtId = document.getElementById('assignPtId');
        const assignMemberName = document.getElementById('assignMemberName');

        function openAssignModal(ptId, memberName) {
            assignPtId.value = ptId;
            assignMemberName.innerText = "Assigning trainer for " + memberName;
            assignModal.classList.add('active');
        }

        function closeAssignModal() {
            assignModal.classList.remove('active');
        }

        // Close on outside click
        window.onclick = function (event) {
            if (event.target == assignModal) {
                closeAssignModal();
            }
        }
    </script>

    <!-- ===== CONFIRM DELETE MODAL ===== -->
    <div id="ptConfirmModal" style="
        display:none; position:fixed; inset:0; z-index:10000;
        background:rgba(0,0,0,0.45); backdrop-filter:blur(4px);
        justify-content:center; align-items:center;">
        <div style="
            background:#fff; border-radius:20px; padding:36px;
            text-align:center; max-width:360px; width:90%;
            box-shadow:0 20px 40px rgba(0,0,0,0.18);
            animation:ptPopIn 0.3s cubic-bezier(.34,1.56,.64,1) both;">
            <div style="width:64px;height:64px;border-radius:50%;background:#FEE2E2;
                display:flex;align-items:center;justify-content:center;margin:0 auto 16px;">
                <img src="../icons/triangle-exclamation-solid-full.svg" class="fa-solid fa-triangle-exclamation"
                    style="font-size:26px;color:#DC2626;">
            </div>
            <h2 style="margin:0 0 8px;font-size:19px;color:#111827;">Delete PT Package?</h2>
            <p style="margin:0 0 24px;font-size:14px;color:#6B7280;">This PT package and all its session records will be
                permanently deleted. This cannot be undone.</p>
            <div style="display:flex;gap:12px;justify-content:center;">
                <button onclick="document.getElementById('ptConfirmModal').style.display='none'" style="padding:10px 24px;border-radius:10px;border:1px solid #D1D5DB;
                    background:#fff;color:#374151;font-size:14px;font-weight:600;cursor:pointer;">Cancel</button>
                <button id="ptConfirmDeleteBtn" style="padding:10px 24px;border-radius:10px;border:none;
                    background:#DC2626;color:#fff;font-size:14px;font-weight:600;cursor:pointer;">
                    <img src="../icons/trash-solid-full.svg" class="fa-solid fa-trash"> Yes, Delete
                </button>
            </div>
        </div>
    </div>

    <!-- ===== DELETE SUCCESS MODAL ===== -->
    <div id="ptSuccessModal" style="
        display:none; position:fixed; inset:0; z-index:9999;
        background:rgba(0,0,0,0.45); backdrop-filter:blur(4px);
        justify-content:center; align-items:center;">
        <div style="
            background:#fff; border-radius:20px; padding:40px 36px;
            text-align:center; max-width:360px; width:90%;
            box-shadow:0 20px 40px rgba(0,0,0,0.18);
            animation:ptPopIn 0.3s cubic-bezier(.34,1.56,.64,1) both;">
            <div style="width:72px;height:72px;border-radius:50%;
                background:linear-gradient(135deg,#10b981,#34d399);
                display:flex;align-items:center;justify-content:center;margin:0 auto 18px;">
                <img src="../icons/check-solid-full.svg" class="fa-solid fa-check" style="font-size:30px;color:#fff;">
            </div>
            <h2 style="margin:0 0 8px;font-size:20px;color:#111827;">Deleted Successfully!</h2>
            <p style="margin:0;font-size:14px;color:#6B7280;">The PT package has been removed.</p>
            <div style="margin-top:22px;height:4px;border-radius:4px;background:#F3F4F6;overflow:hidden;">
                <div id="ptTimer"
                    style="height:100%;width:100%;background:linear-gradient(90deg,#10b981,#34d399);transition:width 2s linear;">
                </div>
            </div>
        </div>
    </div>



    <script>
        function ptConfirmDelete(url) {
            const modal = document.getElementById('ptConfirmModal');
            modal.style.display = 'flex';
            document.getElementById('ptConfirmDeleteBtn').onclick = () => {
                modal.style.display = 'none';
                window.location.href = url;
            };
        }

        <?php if (isset($_GET['msg']) && $_GET['msg'] === 'deleted'): ?>
            window.addEventListener('DOMContentLoaded', () => {
                const modal = document.getElementById('ptSuccessModal');
                modal.style.display = 'flex';
                const bar = document.getElementById('ptTimer');
                bar.style.transition = 'none'; bar.style.width = '100%';
                requestAnimationFrame(() => requestAnimationFrame(() => {
                    bar.style.transition = 'width 2s linear'; bar.style.width = '0%';
                }));
                setTimeout(() => { modal.style.display = 'none'; }, 2000);
            });
        <?php endif; ?>
    </script>

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            function replaceSVG() {
                var images = document.querySelectorAll('img.fa-solid, img.fa-regular, img[class*="fa-"]');
                images.forEach(function (img) {
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
                            paths.forEach(function (path) {
                                path.setAttribute('fill', 'currentColor');
                            });

                            img.parentNode.replaceChild(svg, img);
                        })
                        .catch(err => console.error('Error fetching SVG:', err));
                });
            }

            replaceSVG();

            var observer = new MutationObserver(function (mutations) {
                var shouldRun = false;
                mutations.forEach(function (mutation) {
                    if (mutation.addedNodes.length) {
                        shouldRun = true;
                    }
                });
                if (shouldRun) replaceSVG();
            });

            observer.observe(document.body, { childList: true, subtree: true });
        });
    </script>
</body>

</html>