<?php
session_start();
require '../config.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: ../index.php");
    exit;
}

// Handle Delete
if (isset($_GET['delete_id'])) {
    $deleteId = intval($_GET['delete_id']);
    mysqli_query($conn, "DELETE FROM personal_training WHERE id = {$deleteId}");
    header("Location: inactive_pt.php?msg=deleted");
    exit;
}

// Fetch PENDING records (no sessions assigned yet: total_sessions = 0)
$sql_pending = "
    SELECT 
        pt.id,
        pt.total_sessions,
        pt.sessions_used,
        pt.start_date,
        pt.end_date,
        pt.pt_fees,
        pt.member_id,
        m.full_name AS member_name,
        COALESCE(
            (SELECT membership_type FROM member_payments mp_plan WHERE mp_plan.member_id = m.id ORDER BY mp_plan.created_at DESC LIMIT 1),
            m.membership
        ) AS membership,
        COALESCE(t.full_name, 'Not Assigned') AS trainer_name
    FROM personal_training pt
    JOIN members m ON pt.member_id = m.id
    LEFT JOIN trainers t ON pt.trainer_id = t.id
    WHERE pt.total_sessions = 0 AND m.status = 'active' AND m.personal_training = 1
    ORDER BY pt.created_at DESC
";

// Fetch EXPIRED records (end_date < today, sessions not all completed OR membership is inactive)
$sql_expired = "
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
        COALESCE(t.full_name, 'Not Assigned') AS trainer_name
    FROM personal_training pt
    JOIN members m ON pt.member_id = m.id
    LEFT JOIN trainers t ON pt.trainer_id = t.id
    WHERE ((pt.total_sessions > 0 AND pt.end_date < CURDATE() AND (SELECT COUNT(*) FROM pt_sessions ps WHERE ps.pt_id = pt.id AND ps.status = 'Completed') < pt.total_sessions)
       OR (m.status = 'inactive'))
       AND m.personal_training = 1
    ORDER BY pt.end_date DESC
";

$pending_result = mysqli_query($conn, $sql_pending);
$expired_result = mysqli_query($conn, $sql_expired);

if (!$pending_result || !$expired_result)
    die("Database Error: " . mysqli_error($conn));

$pending_count = mysqli_num_rows($pending_result);
$expired_count = mysqli_num_rows($expired_result);

function badgeClass($membership)
{
    $plan = strtolower($membership ?? '');
    if (str_contains($plan, 'premium'))
        return 'purple';
    elseif (str_contains($plan, 'elite'))
        return 'gold';
    elseif (str_contains($plan, 'vip'))
        return 'amber';
    elseif (str_contains($plan, 'standard'))
        return 'blue';
    elseif ($plan !== '')
        return 'teal';
    else
        return 'gray';
}

?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" size="16x16" href="../icons/favicon-dark-logo.png" type="image/png">
    <title>Inactive PT | JOF INDIA</title>
    <!-- <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"> -->
    <link rel="stylesheet" href="../static/root.css">
</head>

<body class="page-members page-personal_training">

    <button class="mobile-toggle" id="mobileToggle"><img src="../icons/bars-solid-full.svg" class="fa-solid fa-bars"></button>
    <button class="toggle-sidebar-btn" id="toggleBtn"><img src="../icons/chevron-left-solid-full.svg" class="fa-solid fa-chevron-left"></button>

    <div class="dashboard-container">
        <?php include 'sidebar.php'; ?>

        <main class="main-content">
            <header class="header-banner"
                style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:12px;">
                <div class="header-text">
                    <h1>Inactive PT Packages</h1>
                    <p>Pending session assignment &amp; expired PT packages.</p>
                </div>
                <a href="personal_training.php" style="display:inline-flex; align-items:center; gap:8px; background:#F25C2A; color:#fff; padding:8px 18px; border-radius:8px; font-size:14px; font-weight:600; text-decoration:none; box-shadow:0 4px 12px rgba(242,92,42,0.3);">
                    <img src="../icons/arrow-left-solid-full.svg" class="fa-solid fa-arrow-left"> Back to Active PT
                </a>
            </header>

            <div class="toolbar">
                <div class="search-group" style="display:flex; align-items:center; width:100%;">
                    <div class="search-bar">
                        <img src="../icons/magnifying-glass-solid-full.svg" class="fa-solid fa-magnifying-glass">
                        <input type="text" id="ptSearch" placeholder="Search members..." oninput="filterTable()">
                    </div>
                </div>
            </div>

            <!-- ===== SECTION 1: PENDING (No sessions assigned) ===== -->
            <div style="margin-bottom:32px;">
                <div style="display:flex; align-items:center; gap:10px; margin-bottom:12px;">
                    <span style="background:#FEF3C7; color:#92400E; padding:4px 12px; border-radius:20px; font-size:13px; font-weight:700;">
                        <img src="../icons/clock-solid-full.svg" class="fa-solid fa-clock"> Pending — No Sessions Assigned
                    </span>
                    <span style="font-size:13px; color:#9CA3AF;"><?= $pending_count ?>
                        record<?= $pending_count != 1 ? 's' : '' ?></span>
                </div>

                <div class="table-card">
                    <table class="styled-table">
                        <thead>
                            <tr>
                                <th>Member Name</th>
                                <th>Membership</th>
                                <th>Trainer</th>

                                <th>Start Date</th>
                                <th>End Date</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($pending_count > 0): ?>
                                <?php while ($row = mysqli_fetch_assoc($pending_result)): ?>
                                    <tr data-name="<?= strtolower(htmlspecialchars($row['member_name'])) ?>"
                                        data-section="pending">
                                        <td data-label="Member"><strong><?= htmlspecialchars($row['member_name']) ?></strong>
                                        </td>
                                        <td data-label="Membership">
                                            <span
                                                class="badge <?= badgeClass($row['membership']) ?>"><?= htmlspecialchars($row['membership']) ?></span>
                                        </td>
                                        <td data-label="Trainer"><?= htmlspecialchars($row['trainer_name']) ?></td>

                                        <td data-label="Start"><?= date('M d, Y', strtotime($row['start_date'])) ?></td>
                                        <td data-label="End"><?= date('M d, Y', strtotime($row['end_date'])) ?></td>
                                        <td data-label="Status">
                                            <span class="pt-status expiring">Pending</span>
                                        </td>
                                        <td data-label="Actions" class="action-icons" style="white-space:nowrap;">
                                            <div style="display:inline-flex; align-items:center; gap:6px;">
                                                <!-- Add Sessions to Package -->
                                                <button
                                                    onclick="openPackageModal(<?= $row['id'] ?>, '<?= htmlspecialchars($row['member_name'], ENT_QUOTES) ?>')"
                                                    style="background:#10B981; color:#fff; border:none; border-radius:6px; padding:6px 10px; font-size:12px; font-weight:600; cursor:pointer; display:inline-flex; align-items:center; gap:5px;"
                                                    title="Add Sessions to Package">
                                                    <img src="../icons/box-open-solid-full.svg" class="fa-solid fa-box-open"> Assign Sessions
                                                </button>
                                                <button onclick="ptConfirmDelete('inactive_pt.php?delete_id=<?= $row['id'] ?>')"
                                                    class="btn-delete" style="border:none;cursor:pointer;" title="Delete">
                                                    <img src="../icons/trash-solid-full.svg" class="fa-solid fa-trash">
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr><td colspan="8" style="text-align:center; padding:30px; color:#9CA3AF;">
                                    <img src="../icons/circle-check-solid-full.svg" class="fa-solid fa-circle-check" style="font-size:24px; color:#10B981; display:block; margin-bottom:8px;">
                                    No pending PT packages.
                                </td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- ===== SECTION 2: EXPIRED ===== -->
            <div>
                <div style="display:flex; align-items:center; gap:10px; margin-bottom:12px;">
                    <span style="background:#FEE2E2; color:#991B1B; padding:4px 12px; border-radius:20px; font-size:13px; font-weight:700;">
                        <img src="../icons/calendar-xmark-solid-full.svg" class="fa-solid fa-calendar-xmark"> Expired Packages
                    </span>
                    <span style="font-size:13px; color:#9CA3AF;"><?= $expired_count ?>
                        record<?= $expired_count != 1 ? 's' : '' ?></span>
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
                                <th>Expired On</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($expired_count > 0): ?>
                                <?php while ($row = mysqli_fetch_assoc($expired_result)): ?>
                                    <?php
                                    $used = min($row['sessions_used'], $row['total_sessions']);
                                    $remaining = max(0, $row['total_sessions'] - $used);
                                    ?>
                                    <tr data-name="<?= strtolower(htmlspecialchars($row['member_name'])) ?>"
                                        data-section="expired">
                                        <td data-label="Member"><strong><?= htmlspecialchars($row['member_name']) ?></strong>
                                        </td>
                                        <td data-label="Membership">
                                            <span
                                                class="badge <?= badgeClass($row['membership']) ?>"><?= htmlspecialchars($row['membership']) ?></span>
                                        </td>
                                        <td data-label="Trainer"><?= htmlspecialchars($row['trainer_name']) ?></td>
                                        <td data-label="Total"><?= $row['total_sessions'] ?></td>
                                        <td data-label="Used"><?= $used ?></td>
                                        <td data-label="Remaining" style="font-weight:700; color:#DC2626;"><?= $remaining ?>
                                        </td>
                                        <td data-label="Expired On"><?= date('M d, Y', strtotime($row['end_date'])) ?></td>
                                        <td data-label="Actions" class="action-icons" style="white-space:nowrap;">
                                            <div style="display:inline-flex; align-items:center; gap:6px;">
                                                <a href="member_pt_details.php?pt_id=<?= $row['id'] ?>" class="btn-view" title="View Details">
                                                    <img src="../icons/eye-solid-full.svg" class="fa-solid fa-eye">
                                                </a>
                                                <button onclick="ptConfirmDelete('inactive_pt.php?delete_id=<?= $row['id'] ?>')"
                                                    class="btn-delete" style="border:none;cursor:pointer;" title="Delete">
                                                    <img src="../icons/trash-solid-full.svg" class="fa-solid fa-trash">
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr><td colspan="9" style="text-align:center; padding:30px; color:#9CA3AF;">
                                    <img src="../icons/circle-check-solid-full.svg" class="fa-solid fa-circle-check" style="font-size:24px; color:#10B981; display:block; margin-bottom:8px;">
                                    No expired PT packages.
                                </td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </main>
    </div>

    <!-- ===== ADD SESSIONS TO PACKAGE MODAL ===== -->
    <div id="packageModal"
        style="display:none; position:fixed; inset:0; z-index:10000; background:rgba(0,0,0,0.5); backdrop-filter:blur(4px); justify-content:center; align-items:center;">
        <div
            style="background:#fff; border-radius:20px; padding:32px; max-width:400px; width:90%; box-shadow:0 24px 60px rgba(0,0,0,0.2); animation:ptPopIn 0.3s cubic-bezier(.34,1.56,.64,1) both;">
            <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:20px;">
                <div>
                    <div style="font-size:18px; font-weight:800; color:#111827;">Add Sessions to Package</div>
                    <div id="packageModalSub" style="font-size:13px; color:#6B7280; margin-top:3px;"></div>
                </div>
                <button onclick="closePackageModal()"
                    style="background:#F3F4F6; border:none; border-radius:8px; width:32px; height:32px; cursor:pointer; font-size:16px; color:#6B7280;">✕</button>
            </div>

            <label style="display:block; font-size:13px; font-weight:600; color:#374151; margin-bottom:8px;">Number of
                Sessions to Add</label>
            <input type="number" id="sessionCount" min="1" max="100" value="10"
                style="width:100%; padding:12px; border:1px solid #E5E7EB; border-radius:10px; font-size:16px; font-weight:600; color:#111827; box-sizing:border-box; margin-bottom:20px;">

            <div style="display:flex; gap:10px;">
                <button onclick="closePackageModal()"
                    style="flex:1; padding:11px; border-radius:10px; border:1px solid #D1D5DB; background:#fff; color:#374151; font-size:14px; font-weight:600; cursor:pointer;">Cancel</button>
                <button id="packageSaveBtn" onclick="savePackage()"
                    style="flex:2; padding:11px; border-radius:10px; border:none; background:linear-gradient(135deg,#10B981,#34D399); color:#fff; font-size:14px; font-weight:700; cursor:pointer; display:flex; align-items:center; justify-content:center; gap:8px;">
                    <img src="../icons/plus-solid-full.svg" class="fa-solid fa-plus"> Assign &amp; Activate
                </button>
            </div>
        </div>
    </div>

    <!-- Confirm Delete Modal -->
    <div id="ptConfirmModal" style="display:none; position:fixed; inset:0; z-index:10001; background:rgba(0,0,0,0.45); backdrop-filter:blur(4px); justify-content:center; align-items:center;">
        <div style="background:#fff; border-radius:20px; padding:36px; text-align:center; max-width:360px; width:90%; box-shadow:0 20px 40px rgba(0,0,0,0.18); animation:ptPopIn 0.3s cubic-bezier(.34,1.56,.64,1) both;">
            <div style="width:64px;height:64px;border-radius:50%;background:#FEE2E2;display:flex;align-items:center;justify-content:center;margin:0 auto 16px;">
                <img src="../icons/triangle-exclamation-solid-full.svg" class="fa-solid fa-triangle-exclamation" style="font-size:26px;color:#DC2626;">
            </div>
            <h2 style="margin:0 0 8px;font-size:19px;color:#111827;">Delete PT Package?</h2>
            <p style="margin:0 0 24px;font-size:14px;color:#6B7280;">This will permanently delete the PT package and all
                its session records.</p>
            <div style="display:flex;gap:12px;justify-content:center;">
                <button onclick="document.getElementById('ptConfirmModal').style.display='none'"
                    style="padding:10px 24px;border-radius:10px;border:1px solid #D1D5DB;background:#fff;color:#374151;font-size:14px;font-weight:600;cursor:pointer;">Cancel</button>
                <button id="ptConfirmDeleteBtn"
                    style="padding:10px 24px;border-radius:10px;border:none;background:#DC2626;color:#fff;font-size:14px;font-weight:600;cursor:pointer;">
                    <img src="../icons/trash-solid-full.svg" class="fa-solid fa-trash"> Yes, Delete
                </button>
            </div>
        </div>
    </div>

    <!-- Success Toast -->
    <div id="successToast" style="display:none; position:fixed; bottom:28px; right:28px; z-index:11000; background:#fff; border-radius:14px; padding:16px 22px; box-shadow:0 8px 30px rgba(0,0,0,0.18); align-items:center; gap:14px; min-width:280px;">
        <div style="width:40px;height:40px;border-radius:50%;background:#D1FAE5;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
            <img src="../icons/check-solid-full.svg" class="fa-solid fa-check" style="color:#10B981;font-size:18px;">
        </div>
        <div>
            <div style="font-size:14px;font-weight:700;color:#111827;">Sessions Assigned!</div>
            <div style="font-size:12px;color:#6B7280;margin-top:2px;">Member is now active in Personal Training.</div>
        </div>
    </div>

    

    <script>
        document.getElementById('toggleBtn')?.addEventListener('click', () => document.body.classList.toggle('collapsed'));
        document.getElementById('mobileToggle')?.addEventListener('click', () => document.body.classList.toggle('sidebar-open'));

        function filterTable() {
            const query = document.getElementById('ptSearch').value.toLowerCase();
            document.querySelectorAll('.styled-table tbody tr[data-name]').forEach(row => {
                row.style.display = row.dataset.name.includes(query) ? '' : 'none';
            });
        }
        
        // Sidebar dropdown arrows
        document.querySelectorAll('.nav-item-dropdown').forEach(item => {
            const arrow = item.querySelector('.nav-arrow');
            if (arrow) arrow.addEventListener('click', e => {
                e.preventDefault(); e.stopPropagation();
                document.querySelectorAll('.nav-item-dropdown').forEach(o => { if (o !== item) o.classList.remove('active'); });
                item.classList.toggle('active');
            });
        });

        // ===== PACKAGE MODAL =====
        let _packagePtId = null;

        function openPackageModal(ptId, memberName) {
            _packagePtId = ptId;
            document.getElementById('packageModalSub').textContent = 'For: ' + memberName;
            document.getElementById('sessionCount').value = 10;
            document.getElementById('packageModal').style.display = 'flex';
        }

        function closePackageModal() {
            document.getElementById('packageModal').style.display = 'none';
            _packagePtId = null;
        }

        function savePackage() {
            const sessions = parseInt(document.getElementById('sessionCount').value);
            if (!_packagePtId || isNaN(sessions) || sessions < 1) {
                alert('Please enter a valid number of sessions.');
                return;
            }

            const btn = document.getElementById('packageSaveBtn');
            btn.innerHTML = '<img src="../icons/circle-notch-solid-full.svg" class="fa-solid fa-spinner fa-spin"> Saving...';
            btn.style.pointerEvents = 'none';

            fetch('../handlers/calendar_handler.php?action=update_package', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ pt_id: _packagePtId, sessions: sessions })
            })
            .then(r => r.json())
            .then(data => {
                btn.innerHTML = '<img src="../icons/plus-solid-full.svg" class="fa-solid fa-plus"> Assign & Activate';
                btn.style.pointerEvents = 'auto';
                closePackageModal();

                if (data.status === 'success') {
                    // Show success toast then redirect to personal_training.php
                    const toast = document.getElementById('successToast');
                    toast.style.display = 'flex';
                    setTimeout(() => {
                        window.location.href = 'personal_training.php?activated=1';
                    }, 2000);
                } else {
                    alert('Error: ' + (data.message || 'Could not update package.'));
                }
            })
            .catch(() => {
                btn.innerHTML = '<img src="../icons/plus-solid-full.svg" class="fa-solid fa-plus"> Assign & Activate';
                btn.style.pointerEvents = 'auto';
                alert('Network error. Please try again.');
            });
        }

        // Close package modal on overlay click
        document.getElementById('packageModal').addEventListener('click', function (e) {
            if (e.target === this) closePackageModal();
        });

        // ===== DELETE CONFIRM =====
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
                const toast = document.getElementById('successToast');
                toast.querySelector('div:last-child > div:first-child').textContent = 'Package Deleted';
                toast.querySelector('div:last-child > div:last-child').textContent = 'The PT package has been removed.';
                toast.style.display = 'flex';
                setTimeout(() => { toast.style.display = 'none'; }, 3000);
            });
        <?php endif; ?>
    </script>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
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
        });
    </script>
</body>

</html>