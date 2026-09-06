<?php
require_once __DIR__ . '/../auth/auth_check.php';
require_role(['admin', 'trainer']);
require_once __DIR__ . '/../config.php';

// ── DELETE HANDLER 
if (isset($_GET['delete_id'])) {
    $del_id = intval($_GET['delete_id']);
    $del_stmt = $conn->prepare("DELETE FROM member_payments WHERE payment_id = ?");
    $del_stmt->bind_param("i", $del_id);
    $del_stmt->execute();
    $del_stmt->close();
    header("Location: payment_installments.php?deleted=1");
    exit;
}

// Fetch Data
$sql = "SELECT 
            m.id as member_id, 
            m.full_name, 
            m.email,
            mp.payment_id,
            mp.membership_type, 
            mp.total_amount, 
            mp.amount_received,
            mp.balance_pending,
            mp.installments_count,
            mp.next_due_date,
            (SELECT COUNT(*) FROM installment_payments ip WHERE ip.payment_id = mp.payment_id) as extra_installments_paid
        FROM members m
        JOIN member_payments mp ON m.id = mp.member_id
        ORDER BY mp.payment_id DESC";

$result = $conn->query($sql);

if (!$result) {
    die("<b>Database Error:</b> " . $conn->error);
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <link rel="icon" size="16x16" href="../icons/favicon-dark-logo.png" type="image/png">
    <title>Installment Payments | JOF INDIA</title>

    <link rel="stylesheet" href="../static/root.css">
</head>

<body class="page-payment_installments">

    <button class="mobile-toggle" id="mobileToggle"><img src="../icons/bars-solid-full.svg" class="fa-solid fa-bars"></button>
    <button class="toggle-sidebar-btn" id="toggleBtn"><img src="../icons/chevron-left-solid-full.svg" class="fa-solid fa-chevron-left"></button>

    <div class="dashboard-container">

        <?php include 'sidebar.php'; ?>

        <main class="main-content">

            <div class="page-header">
                <div class="header-text">
                    <h1>Installment Payments</h1>
                    <p>Track partial payments and dues from registered members.</p>
                </div>
                <div class="header-actions">
                    <a href="download_all_system_invoices.php" target="_blank" class="add-btn">
                        <img src="../icons/download-solid-full.svg" class="fa-solid fa-download" width="16"> Download All Invoices
                    </a>
                </div>
            </div>

            <div class="table-panel">
                <div class="panel-header" style="display: flex; justify-content: space-between; align-items: center;">
                    <h2>Installment Records</h2>
                </div>
                <div class="table-responsive">
                    <table class="styled-table">
                        <thead>
                            <tr>
                                <th class="col-name">Name</th>
                                <th class="col-plan">Membership</th>
                                <th class="col-amount">Total</th>
                                <th class="col-amount">Paid</th>
                                <th class="col-amount">Pending</th>
                                <th class="col-date">Next Due</th>
                                <th class="col-invoice text-center">Invoices</th>
                                <th class="col-actions text-center">Actions</th>
                            </tr>
                        </thead>

                        <tbody>
                            <?php if ($result && $result->num_rows > 0): ?>
                                <?php while ($row = $result->fetch_assoc()):
                                    // Calculations
                                    $total = floatval($row['total_amount']);
                                    $received = floatval($row['amount_received']);
                                    $pending = floatval($row['balance_pending']);

                                    $total_installments = intval($row['installments_count']);
                                    $paid_installments = 1 + intval($row['extra_installments_paid']);

                                    // If balance is cleared, assume all are paid
                                    if ($pending <= 0) {
                                        $paid_installments = $total_installments;
                                    }

                                    $days_left = 999;
                                    if (!empty($row['next_due_date']) && $pending > 0) {
                                        $due_date = strtotime($row['next_due_date']);
                                        $today = time();
                                        $diff = $due_date - $today;
                                        $days_left = floor($diff / (60 * 60 * 24));
                                    }
                                    ?>
                                    <tr>
                                        <td data-label="Name">
                                            <div class="user-info">
                                                <div style="display: flex; flex-direction: column;">
                                                    <span class="bold"><?= htmlspecialchars($row['full_name']) ?></span>
                                                    <span class="email-sub"><?= htmlspecialchars($row['email']) ?></span>
                                                </div>
                                            </div>
                                        </td>

                                        <td data-label="Membership">
                                            <div class="membership-cell">
                                                <span class="plan-badge"><?= htmlspecialchars($row['membership_type']) ?></span>
                                                <?php if ($total_installments > 1): ?>
                                                    <div class="installment-label" style="font-size: 11px; color: #6B7280; margin-top: 4px; display: flex; align-items: center; gap: 4px;">
                                                        <img src="../icons/layer-group-solid-full.svg" class="fa-solid fa-layer-group" width="12"> <?= $paid_installments ?> / <?= $total_installments ?> PAID
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                        </td>

                                        <td data-label="Total" class="col-amount amount-total">
                                            ₹<?= number_format($total) ?>
                                        </td>
                                        <td data-label="Paid" class="col-amount amount-paid">
                                            ₹<?= number_format($received) ?></td>

                                        <td data-label="Pending" class="col-amount amount-pending"
                                            style="<?= $pending > 0 ? 'color:#EF4444;' : 'color:#059669;' ?>">
                                            <?= $pending > 0 ? '₹' . number_format($pending) : 'Cleared' ?>
                                        </td>

                                        <td data-label="Next Due" class="col-date">
                                            <?= ($pending > 0 && !empty($row['next_due_date'])) ? date("M d, Y", strtotime($row['next_due_date'])) : '—' ?>
                                        </td>


                                        <td data-label="Invoices" class="col-invoice text-center">
                                            <div class="actions-cell">
                                                <a href="invoice.php?payment_id=<?= $row['payment_id'] ?>" target="_blank" class="btn-icon invoice" title="Generate Single Invoice">
                                                    <img src="../icons/file-invoice-dollar-solid-full.svg" class="fa-solid fa-file-invoice-dollar">
                                                </a>
                                                <a href="download_all_invoices.php?member_id=<?= $row['member_id'] ?>" target="_blank" class="btn-icon invoice" title="Download All Invoices">
                                                    <img src="../icons/download-solid-full.svg" class="fa-solid fa-download">
                                                </a>
                                            </div>
                                        </td>

                                        <td data-label="Actions" class="col-actions text-center">
                                            <div class="actions-cell">
                                                <?php if ($pending > 0 && $days_left <= 15):
                                                    $notifyType = ($days_left < 0) ? 'overdue' : 'payment_due';
                                                    $btnClass = ($days_left < 0) ? 'overdue' : 'due-soon';
                                                    $title = ($days_left < 0) ? 'Send Overdue Alert' : 'Send Reminder (' . $days_left . ' days left)';
                                                    ?>
                                                    <a href="notify_member.php?id=<?= $row['member_id'] ?>&type=<?= $notifyType ?>&amount=<?= $pending ?>&date=<?= $row['next_due_date'] ?>"
                                                        class="btn-icon <?= $btnClass ?>" title="<?= $title ?>">
                                                        <img src="../icons/bell-solid-full.svg" class="fa-solid fa-bell">
                                                    </a>
                                                <?php endif; ?>

                                                <a href="update_installment.php?id=<?= $row['payment_id'] ?>" class="btn-icon edit" title="Edit Payment">
                                                    <img src="../icons/pencil-solid-full.svg" class="fa-solid fa-pencil">
                                                </a>

                                                <button onclick="confirmDeleteInstallment('payment_installments.php?delete_id=<?= $row['payment_id'] ?>')"
                                                    class="btn-icon delete" style="border:none;cursor:pointer;" title="Delete Payment">
                                                    <img src="../icons/trash-solid-full.svg" class="fa-solid fa-trash">
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="8" style="text-align: center; padding: 60px 20px;">
                                        <div style="display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 16px;">
                                            <img src="../icons/receipt-solid-full.svg" alt="No Data" width="48" style="opacity: 0.1; filter: grayscale(1);">
                                            <div style="color: #6B7280; font-size: 15px; font-weight: 500;">No payment details or pending installments found.</div>
                                        </div>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            </div>

        </main>
    </div>

    <div id="instConfirmModal" style="
        display:none; position:fixed; inset:0; z-index:10000;
        background:rgba(0,0,0,0.45); backdrop-filter:blur(4px);
        justify-content:center; align-items:center;">
        <div style="
            background:#fff; border-radius:20px; padding:36px;
            text-align:center; max-width:360px; width:90%;
            box-shadow:0 20px 40px rgba(0,0,0,0.18);
            animation:instPopIn 0.3s cubic-bezier(.34,1.56,.64,1) both;">
            <div style="width:64px;height:64px;border-radius:50%;background:#FEE2E2;
                display:flex;align-items:center;justify-content:center;margin:0 auto 16px;">
                <img src="../icons/triangle-exclamation-solid-full.svg" alt="Warning" width="26" style="filter: invert(20%) sepia(88%) saturate(3862%) hue-rotate(352deg) brightness(91%) contrast(94%);">
            </div>
            <h2 style="margin:0 0 8px;font-size:19px;color:#111827;">Delete Record?</h2>
            <p style="margin:0 0 24px;font-size:14px;color:#6B7280;">This payment record will be permanently deleted.
                This action cannot be undone.</p>
            <div style="display:flex;gap:12px;justify-content:center;">
                <button onclick="document.getElementById('instConfirmModal').style.display='none'" style="padding:10px 24px;border-radius:10px;border:1px solid #D1D5DB;
                    background:#fff;color:#374151;font-size:14px;font-weight:600;cursor:pointer;">Cancel</button>
                <button id="instConfirmDeleteBtn" style="padding:10px 24px;border-radius:10px;border:none;
                    background:#DC2626;color:#fff;font-size:14px;font-weight:600;cursor:pointer; display: flex; align-items: center; gap: 8px;">
                    <img src="../icons/trash-solid-full.svg" alt="" width="14" style="filter: brightness(0) invert(1);"> Yes, Delete
                </button>
            </div>
        </div>
    </div>

    <div id="instSuccessModal" style="
        display:none; position:fixed; inset:0; z-index:9999;
        background:rgba(0,0,0,0.45); backdrop-filter:blur(4px);
        justify-content:center; align-items:center;">
        <div style="
            background:#fff; border-radius:20px; padding:40px 36px;
            text-align:center; max-width:360px; width:90%;
            box-shadow:0 20px 40px rgba(0,0,0,0.18);
            animation:instPopIn 0.3s cubic-bezier(.34,1.56,.64,1) both;">
            <div style="width:72px;height:72px;border-radius:50%;
                background:linear-gradient(135deg,#10b981,#34d399);
                display:flex;align-items:center;justify-content:center;margin:0 auto 18px;">
                <img src="../icons/trash-solid-full.svg" alt="Deleted" width="28" style="filter: brightness(0) invert(1);">
            </div>
            <h2 style="margin:0 0 8px;font-size:20px;color:#111827;">Deleted Successfully!</h2>
            <p style="margin:0;font-size:14px;color:#6B7280;">The payment record has been removed.</p>
            <div style="margin-top:22px;height:4px;border-radius:4px;background:#F3F4F6;overflow:hidden;">
                <div id="instTimer"
                    style="height:100%;width:100%;background:linear-gradient(90deg,#10b981,#34d399);transition:width 2s linear;">
                </div>
            </div>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            // Sidebar Toggles
            const toggleBtn = document.getElementById('toggleBtn');
            const mobileToggle = document.getElementById('mobileToggle');
            const body = document.body;

            if (toggleBtn) toggleBtn.addEventListener('click', () => body.classList.toggle('collapsed'));
            if (mobileToggle) mobileToggle.addEventListener('click', () => body.classList.toggle('sidebar-open'));

            // Sidebar Dropdown (arrow click)
            document.querySelectorAll('.nav-item-dropdown').forEach(item => {
                const arrow = item.querySelector('.nav-arrow');
                if (arrow) {
                    arrow.addEventListener('click', function (e) {
                        e.preventDefault();
                        e.stopPropagation();
                        document.querySelectorAll('.nav-item-dropdown').forEach(other => {
                            if (other !== item) other.classList.remove('active');
                        });
                        item.classList.toggle('active');
                    });
                }
            });

            // SVG Replacement Logic
            function replaceSVG() {
                var images = document.querySelectorAll('img.fa-solid, img.fa-regular, img[class*="fa-"], img.nav-arrow');
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

                            if (imgID) svg.setAttribute('id', imgID);
                            if (imgClass) svg.setAttribute('class', imgClass + ' replaced-svg');

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
                    if (mutation.addedNodes.length) shouldRun = true;
                });
                if (shouldRun) replaceSVG();
            });

            observer.observe(document.body, { childList: true, subtree: true });
        });

        // Global Modal Helpers
        function confirmDeleteInstallment(url) {
            const modal = document.getElementById('instConfirmModal');
            modal.style.display = 'flex';
            document.getElementById('instConfirmDeleteBtn').onclick = () => {
                modal.style.display = 'none';
                window.location.href = url;
            };
        }

        <?php if (isset($_GET['deleted'])): ?>
            window.addEventListener('DOMContentLoaded', () => {
                const modal = document.getElementById('instSuccessModal');
                modal.style.display = 'flex';
                const bar = document.getElementById('instTimer');
                bar.style.transition = 'none'; bar.style.width = '100%';
                requestAnimationFrame(() => requestAnimationFrame(() => {
                    bar.style.transition = 'width 2s linear'; bar.style.width = '0%';
                }));
                setTimeout(() => { modal.style.display = 'none'; }, 2000);
            });
        <?php endif; ?>
    </script>

</body>

</html>