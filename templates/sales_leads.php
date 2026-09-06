<?php
session_start();
require '../config.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: ../index.php");
    exit;
}

// Search & Filter Logic
$search_val = isset($_GET['search']) ? trim($_GET['search']) : '';
$filter_val = isset($_GET['filter']) ? trim($_GET['filter']) : '';

$sql = "SELECT * FROM sales_leads WHERE 1=1";
$params = [];
$types = "";

if (!empty($search_val)) {
    $search_term = "%" . $search_val . "%";
    $sql .= " AND (full_name LIKE ? OR phone_number LIKE ? OR email LIKE ?)";
    $types .= "sss";
    $params[] = $search_term;
    $params[] = $search_term;
    $params[] = $search_term;
}

if (!empty($filter_val)) {
    $sql .= " AND lead_status = ?";
    $types .= "s";
    $params[] = $filter_val;
}

$sql .= " ORDER BY created_at DESC";

if (!empty($params)) {
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
} else {
    $result = $conn->query($sql);
}

// Metrics Logic
$totalNewLeads = $conn->query("SELECT COUNT(*) FROM sales_leads WHERE lead_status = 'New'")->fetch_row()[0];
$hotLeads = $conn->query("SELECT COUNT(*) FROM sales_leads WHERE lead_status = 'Trial Booked'")->fetch_row()[0];
$contactedCount = $conn->query("SELECT COUNT(*) FROM sales_leads WHERE lead_status = 'Contacted'")->fetch_row()[0];
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" size="16x16" href="../icons/favicon-dark-logo.png" type="image/png">
    <title>JOF INDIA | New Leads Pipeline</title>
    <link rel="stylesheet" href="../static/root.css">
    <style>
        .action-btns .btn-success {
            padding: 0 !important;display: inline-flex !important;align-items: center;justify-content: center;width: 30px !important;height: 30px !important;
        }
        .action-btns .btn-success img,.action-btns .btn-success svg {
            width: 15px !important;height: 15px !important;margin: 0 !important;
        }
    </style>
</head>

<body class="page-sales">
    <!-- Mobile Toggle Button -->
    <button class="mobile-toggle" id="mobileToggle">
        <img src="../icons/bars-solid-full.svg" class="fa-solid fa-bars">
    </button>

    <!-- Desktop Toggle Button -->
    <button class="toggle-sidebar-btn" id="toggleBtn">
        <img src="../icons/chevron-left-solid-full.svg" class="fa-solid fa-chevron-left">
    </button>

    <div class="dashboard-container">

        <?php include 'sidebar.php'; ?>

        <main class="main-content">

            <!-- Page Header -->
            <header class="page-header">
                <div class="header-text">
                    <h1>New Leads Pipeline</h1>
                    <p>Track potential members and manage conversion funnel</p>
                </div>
            </header>

            <!-- Metric Cards -->
            <div class="metrics-grid">
                <div class="metric-card">
                    <div class="metric-header">
                        <div class="metric-icon orange-gradient">
                            <img src="../icons/users-solid-full.svg" class="fa-solid fa-users" alt="JO" width="20">
                        </div>
                    </div>
                    <div class="metric-body">
                        <h3>Total New Leads</h3>
                        <h2><?= $totalNewLeads ?></h2>
                        <span class="metric-change positive">
                            <img src="../icons/arrow-up-solid-full.svg" alt="JO" width="20"> Active Pipeline
                        </span>
                    </div>
                </div>

                <div class="metric-card">
                    <div class="metric-header">
                        <div class="metric-icon purple-gradient">
                            <img src="../icons/fire-solid-full.svg" class="fa-solid fa-fire" alt="JO" width="20">
                        </div>
                    </div>
                    <div class="metric-body">
                        <h3>Hot Leads</h3>
                        <h2><?= $hotLeads ?></h2>
                        <span class="metric-change neutral">
                            <img src="../icons/bolt-solid-full.svg" alt="JO" width="20"> Likely to join soon
                        </span>
                    </div>
                </div>

                <div class="metric-card">
                    <div class="metric-header">
                        <div class="metric-icon green-gradient">
                            <img src="../icons/phone-solid-full.svg" class="fa-solid fa-phone" alt="JO" width="20">
                        </div>
                    </div>
                    <div class="metric-body">
                        <h3>In Contact</h3>
                        <h2><?= $contactedCount ?></h2>
                        <span class="metric-change positive">
                            <img src="../icons/check-solid-full.svg" alt="JO" width="20"> Pending Response
                        </span>
                    </div>
                </div>
            </div>

            <!-- Leads Table -->
            <div class="table-card">
                <div class="table-header">
                    <h3>Active Leads</h3>
                    <div class="search-filter">
                        <form method="GET" action="" style="display:contents;">
                            <div class="search-box">
                                <img src="../icons/magnifying-glass-solid-full.svg" alt="JO" width="20">
                                <input type="text" name="search" placeholder="Search leads..."
                                    value="<?= htmlspecialchars($search_val) ?>">
                            </div>
                            <select name="filter" onchange="this.form.submit()"
                                style="padding: 10px; border-radius: 10px; border: 1px solid #e2e8f0; outline: none; cursor:pointer;">
                                <option value="">All Statuses</option>
                                <option value="New" <?= $filter_val == 'New' ? 'selected' : '' ?>>New</option>
                                <option value="Contacted" <?= $filter_val == 'Contacted' ? 'selected' : '' ?>>Contacted
                                </option>
                                <option value="Trial Booked" <?= $filter_val == 'Trial Booked' ? 'selected' : '' ?>>Trial
                                    Booked</option>
                                <option value="Converted" <?= $filter_val == 'Converted' ? 'selected' : '' ?>>Converted
                                </option>
                                <option value="Lost" <?= $filter_val == 'Lost' ? 'selected' : '' ?>>Lost</option>
                            </select>
                        </form>
                        <a href="add_lead.php" class="btn btn-primary">
                            <img src="../icons/plus-solid-full.svg" alt="Add" width="20"
                                style="filter: brightness(0) invert(1);"> Add New
                        </a>
                    </div>
                </div>

                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Contact</th>
                            <th>Email</th>
                            <th>Source</th>
                            <th>Follow Up</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($result->num_rows > 0): ?>
                            <?php while ($row = $result->fetch_assoc()): ?>
                                <?php
                                // Badge Class Logic
                                $badgeClass = 'badge-social';
                                $source = $row['source'];
                                if ($source == 'Walk-in')
                                    $badgeClass = 'badge-walkin';
                                elseif ($source == 'Word of Mouth')
                                    $badgeClass = 'badge-referral';
                                elseif ($source == 'Google Ads' || $source == 'Flyer/Poster')
                                    $badgeClass = 'badge-ads';
                                ?>
                                <tr>
                                    <td data-label="Name">
                                        <strong><?= htmlspecialchars($row['full_name']) ?></strong>
                                    </td>
                                    <td data-label="Contact">
                                        <?= htmlspecialchars($row['phone_number']) ?>
                                    </td>
                                    <td data-label="Email">
                                        <?= !empty($row['email']) ? htmlspecialchars($row['email']) : '<span class="text-muted">-</span>' ?>
                                    </td>
                                    <td data-label="Source">
                                        <span class="badge <?= $badgeClass ?>"><?= htmlspecialchars($source) ?></span>
                                    </td>
                                    <td data-label="Follow Up">
                                        <?= !empty($row['follow_up_date']) ? date('d M Y', strtotime($row['follow_up_date'])) : '<span class="text-muted">-</span>' ?>
                                    </td>
                                    <td data-label="Actions">
                                        <div class="action-btns">
                                            <a href="tel:<?= htmlspecialchars($row['phone_number']) ?>"
                                                class="btn btn-secondary" title="Call">
                                                <img src="../icons/phone-solid-full.svg" class="fa-solid fa-phone" alt="JO" width="14">
                                            </a>
                                            <a href="https://wa.me/<?= str_replace(['+', ' '], '', $row['phone_number']) ?>"
                                                target="_blank" class="btn btn-secondary" title="WhatsApp">
                                                <img src="../icons/whatsapp-brands-solid-full.svg" class="fa-brands fa-whatsapp" alt="JO" width="14">
                                            </a>
                                            <!-- Edit / Convert Action -->
                                            <?php if ($row['lead_status'] != 'Converted'): ?>
                                                <a href="add_member.php?lead_id=<?= $row['id'] ?>" class="btn btn-success"
                                                    title="Convert to Member">
                                                    <img src="../icons/user-plus-solid-full.svg" class="fa-solid fa-user-plus icon-white" alt="JO">
                                                </a>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="6" style="text-align:center; padding: 20px;">No leads found. Add a new lead to
                                    get started!</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

        </main>
    </div>

    <!-- JavaScript for Toggle Functionality -->
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const toggleBtn = document.getElementById('toggleBtn');
            const mobileToggle = document.getElementById('mobileToggle');
            const body = document.body;
            const sidebar = document.querySelector('.sidebar');

            // Desktop toggle button
            if (toggleBtn) {
                toggleBtn.addEventListener('click', function () {
                    body.classList.toggle('collapsed');
                });
            }

            // Mobile toggle button
            if (mobileToggle) {
                mobileToggle.addEventListener('click', function () {
                    body.classList.toggle('sidebar-open');
                });
            }

            // Close sidebar when clicking outside on mobile
            document.addEventListener('click', function (event) {
                const isClickInsideSidebar = sidebar.contains(event.target);
                const isClickOnToggle = mobileToggle.contains(event.target);

                if (!isClickInsideSidebar && !isClickOnToggle && body.classList.contains('sidebar-open') && window.innerWidth <= 992) {
                    body.classList.remove('sidebar-open');
                }
            });

            // Dropdown functionality
            const dropdownItems = document.querySelectorAll('.nav-item-dropdown');
            dropdownItems.forEach(item => {
                const link = item.querySelector('.nav-link');
                const arrow = link ? link.querySelector('.nav-arrow') : null;

                if (arrow) {
                    arrow.addEventListener('click', function (e) {
                        e.preventDefault();
                        e.stopPropagation();

                        dropdownItems.forEach(otherItem => {
                            if (otherItem !== item) {
                                otherItem.classList.remove('active');
                            }
                        });

                        item.classList.toggle('active');
                    });
                }
            });
        });
    </script>

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            function replaceSVG() {
                var images = document.querySelectorAll('img.fa-solid, img.fa-regular, img.fa-brands, img.nav-arrow');
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