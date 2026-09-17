<?php
require_once __DIR__ . '/../auth/auth_check.php';
require_role(['admin', 'trainer']);
require_once __DIR__ . '/../config.php';
$csrf = generate_csrf_token();

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

// A currently-active member whose latest payment row is still an unverified 'Pending Setup'
// placeholder has submitted a renewal request without their ongoing membership being disrupted
// (see handlers/subscribe_payment.php) — surface them here too, alongside genuinely inactive
// members, so admin can verify/activate the renewal. Their real members.status stays 'active'
// the whole time; m.status is selected below so the template can tell the two cases apart.
$sql = "SELECT
            m.id, m.full_name, m.email, m.created_at, m.inactive_date, m.status AS member_status,
            (SELECT COUNT(*) FROM member_payments mp_count WHERE mp_count.member_id = m.id) as payment_count,
            (SELECT MAX(end_date) FROM member_payments mp_exp WHERE mp_exp.member_id = m.id) as latest_expiry,
            COALESCE(
                (SELECT membership_type FROM member_payments mp_plan WHERE mp_plan.member_id = m.id ORDER BY mp_plan.created_at DESC LIMIT 1),
                m.membership
            ) as membership
        FROM members m
        WHERE m.status = 'inactive'
           OR (m.status = 'active' AND (
                SELECT mp_latest.membership_type FROM member_payments mp_latest
                WHERE mp_latest.member_id = m.id
                ORDER BY mp_latest.created_at DESC, mp_latest.payment_id DESC LIMIT 1
           ) = 'Pending Setup')";

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
    <link rel="stylesheet" href="../static/root.css?v=<?= @filemtime(__DIR__ . '/../static/root.css') ?>">
    <style>
        /* ── Extend Plan action button ── */
        .page-members .btn-extend {
            display: inline-flex; align-items: center; justify-content: center;
            width: 32px; height: 32px; border-radius: 6px; margin-right: 5px;
            background: #E0F2FE; color: #0369A1; border: 1px solid #BAE6FD;
            text-decoration: none; cursor: pointer; transition: all .2s ease;
        }
        .page-members .btn-extend:hover {
            background: #BAE6FD; border-color: #7DD3FC; transform: translateY(-1px);
            box-shadow: 0 4px 10px rgba(3, 105, 161, .18);
        }
        .page-members .btn-extend img, .page-members .btn-extend svg { width: 14px; height: 14px; }

        /* ── Extend Plan modal ── */
        .ext-overlay {
            display: none; position: fixed; inset: 0; z-index: 10000; padding: 16px;
            background: rgba(15, 23, 42, .5); backdrop-filter: blur(4px);
            align-items: center; justify-content: center;
        }
        .ext-overlay.open { display: flex; }
        .ext-card {
            width: 100%; max-width: 440px; max-height: calc(100vh - 32px); overflow-y: auto;
            background: #fff; border-radius: 22px; box-shadow: 0 24px 60px rgba(15, 23, 42, .25);
            animation: popIn .3s cubic-bezier(.34, 1.56, .64, 1) both;
        }
        .ext-head {
            position: relative; display: flex; align-items: center; gap: 14px; padding: 22px 24px 18px;
            border-bottom: 1px solid #F1F5F9;
        }
        .ext-head-ic {
            width: 48px; height: 48px; flex-shrink: 0; border-radius: 14px; color: #fff;
            background: linear-gradient(135deg, #0EA5E9, #0369A1);
            display: flex; align-items: center; justify-content: center;
            box-shadow: 0 6px 16px rgba(14, 165, 233, .35);
        }
        .ext-head-ic svg { width: 22px; height: 22px; }
        .ext-head h2 { margin: 0 0 2px; font-size: 18px; color: #0F172A; }
        .ext-head p { margin: 0; font-size: 13px; color: #64748B; }
        .ext-head p b { color: #334155; }
        .ext-x {
            position: absolute; top: 14px; right: 14px; width: 32px; height: 32px; border-radius: 50%;
            border: none; background: #F1F5F9; color: #64748B; font-size: 20px; line-height: 1;
            display: flex; align-items: center; justify-content: center; cursor: pointer; transition: .2s;
        }
        .ext-x:hover { background: #E2E8F0; color: #0F172A; }
        .ext-body { padding: 20px 24px 24px; }

        .ext-dates { display: grid; grid-template-columns: 1fr auto 1fr; align-items: center; gap: 10px; margin-bottom: 20px; }
        .ext-date { padding: 11px 13px; border-radius: 12px; background: #FEF2F2; border: 1px solid #FECACA; }
        .ext-date span { display: block; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .04em; color: #B91C1C; margin-bottom: 3px; }
        .ext-date b { font-size: 14px; color: #0F172A; }
        .ext-date.new { background: #ECFDF5; border-color: #A7F3D0; }
        .ext-date.new span { color: #047857; }
        .ext-arrow { color: #94A3B8; font-size: 18px; }

        .ext-label { display: block; font-size: 12.5px; font-weight: 700; color: #334155; margin-bottom: 8px; }
        .ext-label span { font-weight: 500; color: #94A3B8; }
        .ext-chips { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 10px; }
        .ext-chip {
            padding: 8px 14px; border-radius: 999px; border: 1.5px solid #E2E8F0; background: #fff;
            color: #334155; font-size: 13px; font-weight: 600; cursor: pointer; transition: .15s;
        }
        .ext-chip:hover { border-color: #7DD3FC; background: #F0F9FF; }
        .ext-chip.active { border-color: #0284C7; background: #0284C7; color: #fff; }
        .ext-custom { display: flex; align-items: center; gap: 10px; margin-bottom: 18px; }
        .ext-custom input {
            width: 110px; padding: 10px 12px; border: 1.5px solid #E2E8F0; border-radius: 10px;
            font-size: 14px; font-weight: 600; color: #0F172A;
        }
        .ext-custom input:focus, .ext-body textarea:focus { outline: none; border-color: #0284C7; box-shadow: 0 0 0 3px rgba(2, 132, 199, .15); }
        .ext-custom span { font-size: 13px; color: #64748B; }
        .ext-body textarea {
            width: 100%; min-height: 70px; padding: 10px 12px; border: 1.5px solid #E2E8F0; border-radius: 10px;
            font-size: 13.5px; font-family: inherit; resize: vertical; margin-bottom: 16px; box-sizing: border-box;
        }
        .ext-summary {
            padding: 12px 14px; border-radius: 12px; background: #F0F9FF; border: 1px dashed #7DD3FC;
            font-size: 13px; color: #075985; line-height: 1.5; margin-bottom: 20px;
        }
        .ext-summary b { color: #0C4A6E; }
        .ext-actions { display: flex; gap: 10px; justify-content: flex-end; }
        .ext-btn {
            padding: 11px 20px; border-radius: 11px; font-size: 13.5px; font-weight: 700; cursor: pointer; transition: .2s;
        }
        .ext-btn.ghost { background: #fff; border: 1.5px solid #E2E8F0; color: #334155; }
        .ext-btn.ghost:hover { background: #F8FAFC; }
        .ext-btn.primary { background: #0284C7; border: 1.5px solid #0284C7; color: #fff; box-shadow: 0 6px 14px rgba(2, 132, 199, .3); }
        .ext-btn.primary:hover { background: #0369A1; border-color: #0369A1; }
        .ext-btn.primary:disabled { opacity: .5; cursor: not-allowed; box-shadow: none; }

        .ext-alert {
            display: flex; align-items: center; gap: 10px; margin: 0 0 16px; padding: 12px 16px; border-radius: 12px;
            background: #FEF2F2; border: 1px solid #FECACA; color: #991B1B; font-size: 13.5px; font-weight: 600;
        }

        @media (max-width: 480px) {
            .ext-head { padding: 18px 18px 14px; }
            .ext-body { padding: 16px 18px 20px; }
            .ext-dates { grid-template-columns: 1fr; }
            .ext-arrow { display: none; }
            .ext-actions .ext-btn { flex: 1; }
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
                    <h1>Inactive Members</h1>
                    <p>Members who registered via the public link and are awaiting activation</p>
                </div>
            </header>

            <?php if (isset($_GET['pause_err'])): ?>
                <div class="ext-alert" role="alert">
                    <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 8v5M12 16h.01"/></svg>
                    <?= htmlspecialchars($_GET['pause_err']) ?>
                </div>
            <?php endif; ?>

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
                                    <?php $is_active_renewal = $row['member_status'] === 'active'; ?>
                                    <td data-label="Name" class="nowrap">
                                        <strong><?= htmlspecialchars($row['full_name']) ?></strong>
                                        <?php if (!$is_active_renewal && (empty($row['membership']) || $row['membership'] === 'Pending Setup' || $row['payment_count'] == 0)): ?>
                                            <span class="badge-new">NEW</span>
                                        <?php endif; ?>
                                    </td>
                                    <td data-label="Email" class="text-muted"><?= htmlspecialchars($row['email'] ?? '-') ?></td>
                                    <td data-label="Status" class="nowrap">
                                        <?php if ($is_active_renewal): ?>
                                            <span class="status-pill" style="background:#EEF2FF;color:#4338CA;">🔄 Renewal Requested</span>
                                        <?php elseif (empty($row['membership']) || $row['membership'] === 'Pending Setup' || $row['payment_count'] == 0): ?>
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
                                        <?php if ($is_active_renewal): ?>
                                            <span class="days-left-pill safe">Still Active</span>
                                        <?php elseif ($row['inactive_date']):
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
                                        else:
                                            echo '<span class="days-left-pill safe">New</span>';
                                        endif;
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

                                            <?php if (!$is_active_renewal && $row['payment_count'] > 0 && !empty($row['latest_expiry'])): ?>
                                                <a href="javascript:void(0)"
                                                    onclick="openExtendModal(<?= (int) $row['id'] ?>, '<?= htmlspecialchars(addslashes($row['full_name']), ENT_QUOTES) ?>', '<?= htmlspecialchars($row['latest_expiry']) ?>')"
                                                    class="btn-extend" title="Extend Plan" aria-label="Extend Plan">
                                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="17" rx="2"/><path d="M16 2v4M8 2v4M3 10h18M12 13v5M9.5 15.5h5"/></svg>
                                                </a>
                                            <?php endif; ?>

                                            <a href="person_info.php?id=<?= $row['id'] ?>" class="btn-view"
                                                title="View Profile">
                                                <img src="../icons/eye-solid-full.svg" class="fa-solid fa-eye">
                                            </a>

                                            <?php if (!$is_active_renewal): ?>
                                                <button onclick="confirmDelete('inactive_members.php?delete_id=<?= $row['id'] ?>')"
                                                    class="btn-delete" style="border:none;cursor:pointer;" title="Delete">
                                                    <img src="../icons/trash-solid-full.svg" class="fa-solid fa-trash">
                                                </button>
                                            <?php endif; ?>
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

    <!-- ===== EXTEND PLAN MODAL ===== -->
    <div id="extendModal" class="ext-overlay" onclick="if (event.target === this) closeExtendModal()">
        <div class="ext-card" role="dialog" aria-modal="true" aria-labelledby="extendTitle">
            <div class="ext-head">
                <div class="ext-head-ic">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="17" rx="2"/><path d="M16 2v4M8 2v4M3 10h18M12 13v5M9.5 15.5h5"/></svg>
                </div>
                <div>
                    <h2 id="extendTitle">Extend Membership</h2>
                    <p>For <b id="extendMemberName"></b></p>
                </div>
                <button type="button" class="ext-x" onclick="closeExtendModal()" aria-label="Close">&times;</button>
            </div>

            <form id="extendForm" class="ext-body" method="POST" action="../handlers/pause_membership.php">
                <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="kind" value="extension">
                <input type="hidden" name="member_id" id="extendMemberId">
                <input type="hidden" name="redirect" value="inactive_members.php">

                <div class="ext-dates">
                    <div class="ext-date">
                        <span id="extendOldLabel">Plan ended</span>
                        <b id="extendOldExpiry">—</b>
                    </div>
                    <div class="ext-arrow">&rarr;</div>
                    <div class="ext-date new">
                        <span>New expiry</span>
                        <b id="extendNewExpiry">—</b>
                    </div>
                </div>

                <label class="ext-label">Extend by</label>
                <div class="ext-chips">
                    <?php foreach ([7, 15, 30, 60, 90] as $chip_days): ?>
                        <button type="button" class="ext-chip" data-days="<?= $chip_days ?>"><?= $chip_days ?> days</button>
                    <?php endforeach; ?>
                </div>
                <div class="ext-custom">
                    <input type="number" name="days" id="extendDays" min="1" max="365" value="30" required aria-label="Number of days">
                    <span>days (1–365)</span>
                </div>

                <label class="ext-label" for="extendReason">Reason <span>(optional)</span></label>
                <textarea name="reason" id="extendReason" maxlength="255"
                    placeholder="e.g. Courtesy extension, member was travelling…"></textarea>

                <div class="ext-summary" id="extendSummary"></div>

                <div class="ext-actions">
                    <button type="button" class="ext-btn ghost" onclick="closeExtendModal()">Cancel</button>
                    <button type="submit" class="ext-btn primary" id="extendSubmit">Extend Plan</button>
                </div>
            </form>
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

        // ── Extend Plan modal ──
        // Mirrors handlers/pause_membership.php (kind=extension): the extra days are
        // counted from today when the plan has already lapsed, otherwise from its end date.
        let extendBase = null;
        let extendLapsed = true;
        const fmtDate = d => d.toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' });

        function openExtendModal(memberId, memberName, latestExpiry) {
            const today = new Date();
            today.setHours(0, 0, 0, 0);
            const oldEnd = new Date(latestExpiry + 'T00:00:00');
            extendLapsed = oldEnd < today;
            extendBase = extendLapsed ? today : oldEnd;

            document.getElementById('extendMemberId').value = memberId;
            document.getElementById('extendMemberName').textContent = memberName;
            document.getElementById('extendOldLabel').textContent = extendLapsed ? 'Plan ended' : 'Plan ends';
            document.getElementById('extendOldExpiry').textContent = fmtDate(oldEnd);
            document.getElementById('extendDays').value = 30;
            document.getElementById('extendReason').value = '';
            updateExtendPreview();
            document.getElementById('extendModal').classList.add('open');
            document.getElementById('extendDays').focus();
        }

        function closeExtendModal() {
            document.getElementById('extendModal').classList.remove('open');
        }

        function updateExtendPreview() {
            if (!extendBase) return;
            const days = parseInt(document.getElementById('extendDays').value, 10);
            const valid = days >= 1 && days <= 365;

            document.querySelectorAll('.ext-chip').forEach(chip => {
                chip.classList.toggle('active', +chip.dataset.days === days);
            });
            document.getElementById('extendSubmit').disabled = !valid;

            const summary = document.getElementById('extendSummary');
            if (!valid) {
                document.getElementById('extendNewExpiry').textContent = '—';
                summary.innerHTML = 'Enter a number of days between <b>1</b> and <b>365</b>.';
                return;
            }

            const end = new Date(extendBase);
            end.setDate(end.getDate() + days);
            document.getElementById('extendNewExpiry').textContent = fmtDate(end);
            summary.innerHTML = (extendLapsed
                    ? 'The member becomes <b>active from today</b> and moves to Active Members. '
                    : '<b>' + days + ' days</b> are added after the current end date. ')
                + 'Plan valid until <b>' + fmtDate(end) + '</b>'
                + (extendLapsed ? ' (' + days + ' day' + (days === 1 ? '' : 's') + ').' : '.');
        }

        document.getElementById('extendDays').addEventListener('input', updateExtendPreview);
        document.querySelectorAll('.ext-chip').forEach(chip => {
            chip.addEventListener('click', () => {
                document.getElementById('extendDays').value = chip.dataset.days;
                updateExtendPreview();
            });
        });
        document.getElementById('extendForm').addEventListener('submit', () => {
            document.getElementById('extendSubmit').disabled = true;
            document.getElementById('extendSubmit').textContent = 'Extending…';
        });
        document.addEventListener('keydown', e => { if (e.key === 'Escape') closeExtendModal(); });

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