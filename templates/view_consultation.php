<?php
session_start();
require '../config.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: ../index.php");
    exit;
}

// === DELETE LOGIC ===
if (isset($_GET['delete_id'])) {
    $del_id = intval($_GET['delete_id']);
    $stmt_del = $conn->prepare("DELETE FROM consultations WHERE id = ?");
    $stmt_del->bind_param("i", $del_id);
    if ($stmt_del->execute()) {
        header("Location: view_consultation.php?msg=deleted");
        exit;
    }
}

// === SEARCH & FILTER LOGIC ===
$search_val = isset($_GET['search']) ? trim($_GET['search']) : '';
$filter_val = isset($_GET['filter']) ? trim($_GET['filter']) : '';

$sql = "SELECT id, member_name, trainer_name, payment_method, price_per_session, base_price, tax_amount, total_amount, status, created_at
        FROM consultations WHERE 1=1";

$types = "";
$params = [];

if (!empty($search_val)) {
    $search_term = "%" . $search_val . "%";
    $sql .= " AND (member_name LIKE ? OR trainer_name LIKE ?)";
    $types .= "ss";
    $params[] = $search_term;
    $params[] = $search_term;
}

if (!empty($filter_val)) {
    $sql .= " AND payment_method = ?";
    $types .= "s";
    $params[] = $filter_val;
}

$sql .= " ORDER BY id DESC";

$stmt = $conn->prepare($sql);
if (!$stmt) {
    die("SQL Error: " . $conn->error);
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
    <title>JOF INDIA | Consultations</title>
    <link rel="stylesheet" href="../static/root.css">
    <style>
        img[class*="fa-"],
        svg.replaced-svg {
            width: 1em;
            height: 1em;
            vertical-align: -0.125em;
        }

        .replaced-svg {
            display: inline-block;
        }

        .replaced-svg path {
            fill: currentColor;
        }

        @media (max-width: 992px) {
            .main-content {
                margin-left: 0 !important;
                width: 100% !important;
                padding: 80px 16px 20px !important;
            }

            .toolbar {
                flex-direction: column !important;
            }

            .search-group {
                width: 100% !important;
            }
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
                    <h1>Consultations</h1>
                    <p>View and manage all booked consultation sessions</p>
                </div>
            </header>

            <div class="toolbar">
                <form id="searchForm" method="GET" action="view_consultation.php" class="search-group">

                    <input type="hidden" name="filter" id="filterInput" value="<?= htmlspecialchars($filter_val) ?>">

                    <div class="search-bar">
                        <img src="../icons/magnifying-glass-solid-full.svg" class="fa-solid fa-magnifying-glass">
                        <input type="text" name="search" placeholder="Search by member or trainer..."
                            value="<?= htmlspecialchars($search_val) ?>">
                    </div>

                    <div class="filter-wrapper">
                        <button type="button" class="filter-btn" id="filterToggleBtn" onclick="toggleFilterCard(event)">
                            <img src="../icons/filter-solid-full.svg" class="fa-solid fa-filter">
                            <?= !empty($filter_val) ? htmlspecialchars($filter_val) : 'Filter' ?>
                        </button>
                        <div class="filter-card-popup" id="filterCard">
                            <div class="filter-header">Filter by Payment Method</div>
                            <button type="button" class="filter-option-btn <?= $filter_val == '' ? 'active' : '' ?>"
                                onclick="applyFilter('')">
                                <img src="../icons/check-solid-full.svg" class="fa-solid fa-check"> All
                            </button>
                            <button type="button" class="filter-option-btn <?= $filter_val == 'Cash' ? 'active' : '' ?>"
                                onclick="applyFilter('Cash')">
                                <img src="../icons/check-solid-full.svg" class="fa-solid fa-check"> Cash
                            </button>
                            <button type="button" class="filter-option-btn <?= $filter_val == 'UPI' ? 'active' : '' ?>"
                                onclick="applyFilter('UPI')">
                                <img src="../icons/check-solid-full.svg" class="fa-solid fa-check"> UPI
                            </button>
                            <button type="button" class="filter-option-btn <?= $filter_val == 'Card' ? 'active' : '' ?>"
                                onclick="applyFilter('Card')">
                                <img src="../icons/check-solid-full.svg" class="fa-solid fa-check"> Card
                            </button>
                            <button type="button"
                                class="filter-option-btn <?= $filter_val == 'Bank Transfer' ? 'active' : '' ?>"
                                onclick="applyFilter('Bank Transfer')">
                                <img src="../icons/check-solid-full.svg" class="fa-solid fa-check"> Bank Transfer
                            </button>
                            <button type="button"
                                class="filter-option-btn <?= $filter_val == 'Cheque' ? 'active' : '' ?>"
                                onclick="applyFilter('Cheque')">
                                <img src="../icons/check-solid-full.svg" class="fa-solid fa-check"> Cheque
                            </button>
                        </div>
                    </div>

                    <button type="submit" class="filter-btn"
                        style="margin-left: 10px; border:none; background:var(--text-main); color:white;">Go</button>
                </form>
                <a href="consultation.php" class="btn-primary" style="margin-left: auto;">
                    <img src="../icons/plus-solid-full.svg" class="fa-solid fa-plus"> Book New
                </a>
            </div>

            <div class="table-card">
                <table class="styled-table">
                    <thead>
                        <tr>
                            <th>Member</th>
                            <th>Trainer</th>
                            <th>Payment Method</th>
                            <th style="text-align:right;">Amount</th>
                            <th>Date</th>
                            <th style="text-align:center;">Actions</th>
                        </tr>
                    </thead>

                    <tbody>
                        <?php if ($result->num_rows > 0): ?>
                            <?php while ($row = $result->fetch_assoc()): ?>
                                <tr>
                                    <td data-label="Member">
                                        <strong><?= htmlspecialchars($row['member_name']) ?></strong>
                                    </td>

                                    <td data-label="Trainer" class="text-muted">
                                        <?= htmlspecialchars($row['trainer_name']) ?>
                                    </td>

                                    <td data-label="Payment Method">
                                        <span class="payment-badge">
                                            <img src="../icons/credit-card-solid-full.svg" class="fa-solid fa-credit-card"
                                                style="font-size:11px;">
                                            <?= htmlspecialchars($row['payment_method']) ?>
                                        </span>
                                    </td>

                                    <td data-label="Amount" style="text-align:right;">
                                        <span class="amount-cell">₹<?= number_format($row['total_amount'], 2) ?></span>
                                        <span class="tax-sub">Base: ₹<?= number_format($row['base_price'], 2) ?> + GST:
                                            ₹<?= number_format($row['tax_amount'], 2) ?></span>
                                    </td>

                                    <td data-label="Date" class="text-muted">
                                        <?= date("M d, Y", strtotime($row['created_at'])) ?>
                                    </td>

                                    <td data-label="Actions">
                                        <div class="action-cell">
                                            <button onclick="confirmDelete('view_consultation.php?delete_id=<?= $row['id'] ?>')"
                                                class="btn-delete" title="Delete">
                                                <img src="../icons/trash-solid-full.svg" class="fa-solid fa-trash">
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="6" style="text-align:center; padding:40px; color:#9CA3AF;">
                                    <img src="../icons/clipboard-list-solid-full.svg" class="fa-solid fa-clipboard-list"
                                        style="font-size:32px; margin-bottom:10px; display:block; opacity:0.3;">
                                    No consultation records found.
                                    <a href="consultation.php"
                                        style="display:block; margin-top:10px; color:#F25C2A; font-weight:600;">Book your
                                        first consultation →</a>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

        </main>
    </div>

    <script>
        function toggleFilterCard(e) {
            e.stopPropagation();
            document.getElementById('filterCard').classList.toggle('show');
        }
        function applyFilter(v) {
            document.getElementById('filterInput').value = v;
            document.getElementById('searchForm').submit();
        }

        document.addEventListener('DOMContentLoaded', function () {
            const toggleBtn = document.getElementById('toggleBtn');
            const mobileToggle = document.getElementById('mobileToggle');
            const body = document.body;

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
                const btn = document.getElementById('filterToggleBtn');
                if (card && card.classList.contains('show') && !card.contains(e.target) && !btn.contains(e.target)) {
                    card.classList.remove('show');
                }
            });
        });

        <?php if (isset($_GET['msg']) && $_GET['msg'] === 'deleted'): ?>
            // Show delete success popup
            window.addEventListener('DOMContentLoaded', () => {
                const modal = document.getElementById('deleteSuccessModal');
                modal.style.display = 'flex';

                // small delay to allow display flex to apply before opacity transition
                setTimeout(() => {
                    modal.classList.add('active');
                }, 10);

                const bar = document.getElementById('deleteTimer');
                bar.style.transition = 'none';
                bar.style.width = '100%';
                requestAnimationFrame(() => requestAnimationFrame(() => {
                    bar.style.transition = 'width 2s linear';
                    bar.style.width = '0%';
                }));
                setTimeout(() => {
                    modal.classList.remove('active');
                    setTimeout(() => { modal.style.display = 'none'; }, 300);
                }, 2000);
            });
        <?php endif; ?>

        function confirmDelete(url) {
            const modal = document.getElementById('confirmModal');
            modal.style.display = 'flex';
            setTimeout(() => { modal.classList.add('active'); }, 10);
            document.getElementById('confirmDeleteBtn').onclick = () => { window.location.href = url; };
        }
        function closeConfirm() {
            const modal = document.getElementById('confirmModal');
            modal.classList.remove('active');
            setTimeout(() => { modal.style.display = 'none'; }, 300);
        }
    </script>

    <!-- ===== CONFIRM DELETE MODAL ===== -->
    <div id="confirmModal" class="modal-overlay">
        <div class="modal-card">
            <div class="modal-icon-box danger">
                <img src="../icons/triangle-exclamation-solid-full.svg" class="fa-solid fa-triangle-exclamation"
                    style="font-size:28px;">
            </div>
            <h2 class="modal-title">Delete Record?</h2>
            <p class="modal-desc">This action cannot be undone. The consultation record will be permanently removed.</p>
            <div class="modal-actions">
                <button onclick="closeConfirm()" class="modal-btn modal-btn-secondary">Cancel</button>
                <button id="confirmDeleteBtn" class="modal-btn modal-btn-danger">
                    <img src="../icons/trash-solid-full.svg" class="fa-solid fa-trash"> Yes, Delete
                </button>
            </div>
        </div>
    </div>
    <!-- ===== DELETE SUCCESS MODAL ===== -->
    <div id="deleteSuccessModal" class="modal-overlay">
        <div class="modal-card" style="padding: 40px 36px;">
            <div class="modal-icon-box success" style="width:72px; height:72px;">
                <img src="../icons/trash-solid-full.svg" class="fa-solid fa-trash-can" style="font-size:28px;">
            </div>
            <h2 class="modal-title" style="font-size:20px;">Deleted Successfully!</h2>
            <p class="modal-desc" style="margin:0;">The consultation record has been removed.</p>
            <div
                style="margin-top: 22px; height: 4px; border-radius: 4px; background: #F3F4F6; overflow: hidden; width: 100%;">
                <div id="deleteTimer"
                    style="height: 100%; width: 100%; background: linear-gradient(90deg, #10b981, #34d399);"></div>
            </div>
        </div>
    </div>


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