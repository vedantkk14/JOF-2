<?php
session_start();
require '../config.php';

// Authentication Check
if (!isset($_SESSION['user_id'])) {
    header("Location: ../index.php");
    exit;
}

// Handle Delete Request
if (isset($_GET['action']) && $_GET['action'] == 'delete' && isset($_GET['id'])) {
    $delete_id = intval($_GET['id']);
    $del_stmt = $conn->prepare("DELETE FROM member_enquiries WHERE id = ?");
    if ($del_stmt) {
        $del_stmt->bind_param("i", $delete_id);
        $del_stmt->execute();
        $del_stmt->close();
    }
    header("Location: view_enquiries.php");
    exit;
}

// === FETCH METRICS ===
$totalEnquiriesRes = $conn->query("SELECT COUNT(*) FROM member_enquiries");
$totalEnquiries = ($totalEnquiriesRes) ? $totalEnquiriesRes->fetch_row()[0] : 0;

// Fetch enquiries
$sql = "SELECT * FROM member_enquiries ORDER BY id DESC";
$result = $conn->query($sql);

if (!$result) {
    // If created_at doesn't exist, fallback to basic query
    $sql = "SELECT id, full_name, email, phone_number FROM member_enquiries ORDER BY id DESC";
    $result = $conn->query($sql);
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <link rel="icon" size="16x16" href="../icons/favicon-dark-logo.png" type="image/png">
    <title>Contact Enquiries | JOF INDIA</title>

    <link rel="stylesheet" href="../static/root.css">

    <style>
        /* CSS to fix the huge search icon and other minor alignment issues */
        .page-sales .search-box img,
        .page-sales .search-box svg {
            width: 18px !important;
            height: 18px !important;
            position: absolute;
            left: 12px;
            top: 50%;
            transform: translateY(-50%);
            opacity: 0.6;
        }

        .page-sales .search-box input {
            padding-left: 40px !important;
        }

        .page-sales .metric-card .metric-icon img,
        .page-sales .metric-card .metric-icon svg {
            width: 20px !important;
            height: 20px !important;
        }

        .page-sales .metric-change img,
        .page-sales .metric-change svg {
            width: 14px !important;
            height: 14px !important;
        }

        .page-sales .action-btns {
            display: flex;
            gap: 8px;
        }

        .page-sales .btn-icon-sm {
            width: 34px;
            height: 34px;
            padding: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 10px;
        }

        .page-sales .btn-icon-sm img,
        .page-sales .btn-icon-sm svg {
            width: 18px !important;
            height: 18px !important;
            min-width: 18px !important;
            min-height: 18px !important;
        }

        .page-sales .btn-delete {
            padding: 6px 10px;
            background-color: #fee2e2;
            color: #ef4444;
            border-radius: 6px;
            text-decoration: none;
            transition: all 0.2s ease;
            border: none;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            width: 34px;
            height: 34px;
        }

        .page-sales .btn-delete:hover {
            background-color: #ef4444;
            color: #fff;
        }

        .page-sales .btn-delete:focus {
            outline: none;
            box-shadow: none;
        }

        .page-sales .btn-delete img,
        .page-sales .btn-delete svg {
            width: 14px !important;
            height: 14px !important;
        }

        /* Modal typography (not defined in root.css) */
        .modal-title-custom {
            font-family: 'Poppins', sans-serif;
            font-size: 20px;
            font-weight: 700;
            color: #111827;
            margin: 0 0 8px;
        }

        .modal-text-custom {
            font-family: 'Poppins', sans-serif;
            font-size: 14px;
            color: #6B7280;
            line-height: 1.5;
            margin: 0 0 24px;
        }

        .modal-icon-circle img,
        .modal-icon-circle svg {
            width: 28px !important;
            height: 28px !important;
        }

        .modal-btn-group .btn-danger-modal {
            display: inline-flex;
            align-items: center;
        }

        .modal-btn-group .btn-danger-modal img,
        .modal-btn-group .btn-danger-modal svg {
            width: 14px !important;
            height: 14px !important;
        }
    </style>
</head>

<body class="page-sales">

    <!-- Mobile Toggle Button -->
    <button class="mobile-toggle" id="mobileToggle">
        <img src="../icons/bars-solid-full.svg" class="fa-solid fa-bars" alt="Menu">
    </button>

    <!-- Desktop Toggle Button -->
    <button class="toggle-sidebar-btn" id="toggleBtn">
        <img src="../icons/chevron-left-solid-full.svg" class="fa-solid fa-chevron-left" alt="Toggle">
    </button>

    <div class="dashboard-container">

        <?php include 'sidebar.php'; ?>

        <main class="main-content">

            <!-- Page Header -->
            <header class="page-header">
                <div class="header-text">
                    <h1>Contact Enquiries</h1>
                    <p>Manage and track visitors reaching out through the website.</p>
                </div>
            </header>

            <!-- Metric Cards -->
            <div class="metrics-grid">
                <div class="metric-card">
                    <div class="metric-header">
                        <div class="metric-icon orange-gradient">
                            <img src="../icons/envelope-solid-full.svg" class="fa-solid fa-envelope" alt="Icon">
                        </div>
                    </div>
                    <div class="metric-body">
                        <h3>Total Enquiries</h3>
                        <h2><?= $totalEnquiries ?></h2>
                        <span class="metric-change positive">
                            <img src="../icons/arrow-up-solid-full.svg" class="fa-solid fa-arrow-up" alt="Up"> Live Feed
                        </span>
                    </div>
                </div>

                <div class="metric-card">
                    <div class="metric-header">
                        <div class="metric-icon green-gradient">
                            <img src="../icons/user-check-solid-full.svg" class="fa-solid fa-user-check" alt="Icon">
                        </div>
                    </div>
                    <div class="metric-body">
                        <h3>Follow-ups Pending</h3>
                        <h2><?= $totalEnquiries ?></h2>
                        <span class="metric-change neutral">
                            <img src="../icons/clock-solid-full.svg" class="fa-solid fa-clock" alt="Clock"> Response
                            required
                        </span>
                    </div>
                </div>
            </div>

            <div class="table-card">
                <div class="table-header">
                    <h3>Recent Enquiries</h3>
                    <div class="search-filter">
                        <div class="search-box">
                            <img src="../icons/magnifying-glass-solid-full.svg" class="fa-solid fa-magnifying-glass"
                                alt="Search">
                            <input type="text" id="searchInput" placeholder="Filter by name, email or phone...">
                        </div>
                    </div>
                </div>

                <div class="table-responsive">
                    <table class="data-table" id="enquiriesTable">
                        <thead>
                            <tr>
                                <th>Submission Date</th>
                                <th>Full Name</th>
                                <th>Contact Details</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($result && $result->num_rows > 0): ?>
                                <?php while ($row = $result->fetch_assoc()): ?>
                                    <tr>
                                        <td data-label="Date">
                                            <span class="text-muted">
                                                <?= isset($row['created_at']) ? date('d M, Y', strtotime($row['created_at'])) : date('d M, Y') ?>
                                            </span>
                                        </td>
                                        <td data-label="Name">
                                            <div class="user-info">
                                                <div class="user-name">
                                                    <strong><?= htmlspecialchars($row['full_name']) ?></strong></div>
                                                <div class="text-muted-sm"><?= htmlspecialchars($row['email']) ?></div>
                                            </div>
                                        </td>
                                        <td data-label="Contact">
                                            <div class="contact-info">
                                                <span><?= htmlspecialchars($row['phone_number']) ?></span>
                                            </div>
                                        </td>
                                        <td data-label="Actions">
                                            <div class="action-btns">
                                                <a href="tel:<?= htmlspecialchars($row['phone_number']) ?>"
                                                    class="btn btn-secondary btn-icon-sm" title="Call User">
                                                    <img src="../icons/phone-solid-full.svg" class="fa-solid fa-phone"
                                                        alt="Call">
                                                </a>
                                                <a href="https://wa.me/91<?= preg_replace('/[^0-9]/', '', $row['phone_number']) ?>"
                                                    target="_blank" class="btn btn-secondary btn-icon-sm"
                                                    title="WhatsApp Message">
                                                    <img src="../icons/whatsapp-brands-solid-full.svg"
                                                        class="fa-brands fa-whatsapp" alt="WA">
                                                </a>
                                                <!-- Convert to member -->
                                                <a href="add_member.php?name=<?= urlencode($row['full_name']) ?>&phone=<?= urlencode($row['phone_number']) ?>&email=<?= urlencode($row['email']) ?>"
                                                    class="btn btn-success btn-icon-sm" title="Convert to Member">
                                                    <img src="../icons/user-plus-solid-full.svg"
                                                        class="fa-solid fa-user-plus icon-white" alt="Add Lead">
                                                </a>
                                                <!-- Delete Enquiry -->
                                                <button onclick="confirmDeleteEnquiry('?action=delete&id=<?= $row['id'] ?>')"
                                                    class="btn-delete" title="Delete Enquiry">
                                                    <img src="../icons/trash-solid-full.svg" class="fa-solid fa-trash"
                                                        alt="Delete">
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="4" class="no-enquiries" style="text-align: center; padding: 40px;">
                                        <div class="text-muted">
                                            <img src="../icons/inbox-solid-full.svg" class="fa-solid fa-inbox"
                                                style="width: 48px; height: 48px; opacity: 0.2; margin-bottom: 15px;">
                                            <p>No enquiries found.</p>
                                        </div>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        </main>
    </div>

    <!-- ===== CONFIRM DELETE MODAL ===== -->
    <div id="confirmDeleteModal" class="modal-overlay-generic">
        <div class="modal-content-generic">
            <div class="modal-icon-circle bg-danger-light">
                <img src="../icons/triangle-exclamation-solid-full.svg"
                    class="fa-solid fa-triangle-exclamation text-danger" style="font-size: 26px;">
            </div>
            <h2 class="modal-title-custom">Delete Enquiry?</h2>
            <p class="modal-text-custom">This enquiry will be permanently deleted. This action cannot be undone.</p>
            <div class="modal-btn-group">
                <button onclick="document.getElementById('confirmDeleteModal').style.display='none'"
                    class="btn-outline">Cancel</button>
                <button id="confirmDeleteEnquiryBtn" class="btn-danger-modal">
                    <img src="../icons/trash-solid-full.svg" class="fa-solid fa-trash"
                        style="width:14px;height:14px;margin-right:6px;filter:brightness(0) invert(1);"> Delete
                </button>
            </div>
        </div>
    </div>

    <!-- JavaScript -->
    <script>
        function confirmDeleteEnquiry(url) {
            const modal = document.getElementById('confirmDeleteModal');
            modal.style.display = 'flex';
            document.getElementById('confirmDeleteEnquiryBtn').onclick = () => {
                modal.style.display = 'none';
                window.location.href = url;
            };
        }

        document.addEventListener('DOMContentLoaded', function () {
            // Sidebar Toggles
            const toggleBtn = document.getElementById('toggleBtn');
            const mobileToggle = document.getElementById('mobileToggle');
            const body = document.body;
            const sidebar = document.querySelector('.sidebar');

            if (toggleBtn) toggleBtn.addEventListener('click', () => body.classList.toggle('collapsed'));
            if (mobileToggle) mobileToggle.addEventListener('click', () => body.classList.toggle('sidebar-open'));

            // Close sidebar when clicking outside on mobile
            document.addEventListener('click', function (event) {
                const isClickInsideSidebar = sidebar.contains(event.target);
                const isClickOnToggle = mobileToggle ? mobileToggle.contains(event.target) : false;

                if (!isClickInsideSidebar && !isClickOnToggle && body.classList.contains('sidebar-open') && window.innerWidth <= 992) {
                    body.classList.remove('sidebar-open');
                }
            });

            // Sidebar Dropdowns
            const dropdownItems = document.querySelectorAll('.nav-item-dropdown');
            dropdownItems.forEach(item => {
                const link = item.querySelector('.nav-link');
                const arrow = link ? link.querySelector('.nav-arrow') : null;
                if (arrow) {
                    arrow.addEventListener('click', function (e) {
                        e.preventDefault();
                        e.stopPropagation();
                        dropdownItems.forEach(otherItem => {
                            if (otherItem !== item) otherItem.classList.remove('active');
                        });
                        item.classList.toggle('active');
                    });
                }
            });

            // Search filter for table
            const searchInput = document.getElementById('searchInput');
            const table = document.getElementById('enquiriesTable');
            if (searchInput) {
                searchInput.addEventListener('input', function () {
                    const filter = searchInput.value.toLowerCase();
                    const rows = table.querySelectorAll('tbody tr');
                    rows.forEach(row => {
                        const text = row.textContent.toLowerCase();
                        row.style.display = text.includes(filter) ? '' : 'none';
                    });
                });
            }

            // SVG Replacement
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