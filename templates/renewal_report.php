<?php
session_start();
require '../config.php';

// Check login
if (!isset($_SESSION['user_id'])) {
    header("Location: ../index.php");
    exit;
}

// 1. Get Filter (Default to 30 days)
$days_filter = isset($_GET['days']) ? intval($_GET['days']) : 30;
if (!in_array($days_filter, [7, 15, 30])) {
    $days_filter = 30;
}

$sql = "
    SELECT 
        m.id, 
        m.full_name, 
        m.phone_number, 
        mp.membership_type, 
        mp.end_date as expiry_date 
    FROM members m
    JOIN member_payments mp ON m.id = mp.member_id
    JOIN (
        SELECT member_id, MAX(end_date) as max_end_date
        FROM member_payments
        GROUP BY member_id
    ) latest ON mp.member_id = latest.member_id AND mp.end_date = latest.max_end_date
    WHERE 
        mp.end_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
        AND mp.end_date <= DATE_ADD(CURDATE(), INTERVAL ? DAY) 
    ORDER BY mp.end_date ASC
";

$members = [];
if ($stmt = $conn->prepare($sql)) {
    $stmt->bind_param("i", $days_filter);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $members[] = $row;
    }
    $stmt->close();
}

// Helper to calculate days remaining
function get_days_remaining($expiry_date)
{
    $today = new DateTime('today');
    $exp = new DateTime($expiry_date);
    $diff = $today->diff($exp);
    return $diff->invert ? -$diff->days : $diff->days;
}

// Helper for Avatar Color
function get_avatar_color($name)
{
    $colors = ['blue', 'orange', 'purple', 'green'];
    $index = strlen($name) % count($colors);
    return $colors[$index];
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" size="16x16" href="../icons/favicon-dark-logo.png" type="image/png">
    <title>JOF INDIA | Renewal Report</title>
    <link rel="stylesheet" href="../static/root.css">

</head>

<body class="page-dashboard page-reports page-received_payment page-renewal_report">

    <button class="mobile-toggle" id="mobileToggle">
        <img src="../icons/bars-solid-full.svg" class="fa-solid fa-bars">
    </button>

    <button class="toggle-sidebar-btn" id="toggleBtn">
        <img src="../icons/chevron-left-solid-full.svg" class="fa-solid fa-chevron-left">
    </button>

    <div class="dashboard-container">

        <?php include 'sidebar.php'; ?>

        <main class="main-content">

            <div class="page-header" style="margin-bottom: 20px;">
                <div class="header-text">
                    <h1>Membership Renewal & Expiry Report</h1>
                    <p>Track expiring memberships and manage renewals.</p>
                </div>
            </div>

            <section class="report-controls">
                <div class="control-left">
                    <label>Expiring In:</label>
                    <div class="filter-group">
                        <a href="?days=7" class="filter-btn <?= $days_filter == 7 ? 'active' : '' ?>">7 Days</a>
                        <a href="?days=15" class="filter-btn <?= $days_filter == 15 ? 'active' : '' ?>">15 Days</a>
                        <a href="?days=30" class="filter-btn <?= $days_filter == 30 ? 'active' : '' ?>">30 Days</a>
                    </div>
                </div>

            </section>

            <div class="table-panel">
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th>Member Name</th>
                                <th>Membership Plan</th>
                                <th>Expiry Date</th>
                                <th>Days Remaining</th>
                                <th>Contact</th>
                                <th>Status</th>
                            </tr>
                        </thead>

                        <tbody>
                            <?php if (count($members) > 0): ?>
                                <?php foreach ($members as $mem):
                                    $days_left = get_days_remaining($mem['expiry_date']);
                                    $avatar_class = get_avatar_color($mem['full_name']);

                                    // Status Logic
                                    $status_html = '';
                                    if ($days_left < 0) {
                                        $status_html = '<span class="status-pill expired"><img src="../icons/circle-exclamation-solid-full.svg" class="fa-solid fa-circle-exclamation" width="12"> Expired</span>';
                                        $days_text = '<span class="days-remaining" style="color: #718096;">' . abs($days_left) . ' Days Ago</span>';
                                    } elseif ($days_left <= 7) {
                                        $status_html = '<span class="status-pill expiring"><img src="../icons/clock-solid-full.svg" class="fa-solid fa-clock" width="12"> Expiring Soon</span>';
                                        $days_text = '<span class="days-remaining critical">' . $days_left . ' Days</span>';
                                    } else {
                                        $status_html = '<span class="status-pill active">Active</span>';
                                        $days_text = '<span class="days-remaining">' . $days_left . ' Days</span>';
                                    }

                                    // Initials
                                    $parts = explode(' ', $mem['full_name']);
                                    $initials = strtoupper(substr($parts[0], 0, 1));
                                    if (count($parts) > 1) {
                                        $initials .= strtoupper(substr($parts[count($parts) - 1], 0, 1));
                                    }
                                    ?>
                                    <tr>
                                        <td data-label="Member Name">
                                            <div class="user-info">
                                                <div class="avatar <?= $avatar_class ?>"><?= $initials ?></div>
                                                <div>
                                                    <span class="bold"><?= htmlspecialchars($mem['full_name']) ?></span>
                                                    <span class="email-sub">ID: #M-<?= $mem['id'] ?></span>
                                                </div>
                                            </div>
                                        </td>
                                        <td data-label="Membership Plan"><span
                                                class="plan-badge premium"><?= htmlspecialchars($mem['membership_type']) ?></span>
                                        </td>
                                        <td class="muted" data-label="Expiry Date">
                                            <?= date("M d, Y", strtotime($mem['expiry_date'])) ?>
                                        </td>
                                        <td data-label="Days Remaining"><?= $days_text ?></td>
                                        <td data-label="Contact">
                                            <div class="contact-actions">
                                                <a href="tel:<?= $mem['phone_number'] ?>" class="icon-btn"><img
                                                        src="../icons/phone-solid-full.svg" class="fa-solid fa-phone" width="14"></a>
                                                <a href="https://wa.me/<?= str_replace(['+', ' '], '', $mem['phone_number']) ?>"
                                                    target="_blank" class="icon-btn" style="color: #25D366;"><img
                                                        src="../icons/whatsapp-brands-solid-full.svg" class="fa-brands fa-whatsapp" width="14"></a>
                                            </div>
                                        </td>
                                        <td data-label="Status"><?= $status_html ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="6" style="text-align:center; padding: 20px;">No memberships expiring in the
                                        next <?= $days_filter ?> days.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        </main>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            // Toggle Logic
            const toggleBtn = document.getElementById('toggleBtn');
            const mobileToggle = document.getElementById('mobileToggle');
            const body = document.body;
            const sidebar = document.querySelector('.sidebar');

            if (toggleBtn) {
                toggleBtn.addEventListener('click', function () {
                    body.classList.toggle('collapsed');
                });
            }

            if (mobileToggle) {
                mobileToggle.addEventListener('click', function () {
                    body.classList.toggle('sidebar-open');
                });
            }

            document.addEventListener('click', function (event) {
                const isClickInsideSidebar = sidebar.contains(event.target);
                const isClickOnToggle = mobileToggle.contains(event.target);

                if (!isClickInsideSidebar && !isClickOnToggle && body.classList.contains('sidebar-open') && window.innerWidth <= 992) {
                    body.classList.remove('sidebar-open');
                }
            });

            // Dropdown Logic
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

        // Script to replace <img> storage icons with real SVGs for styling
        function replaceSVG() {
            document.querySelectorAll('img.fa-solid, img.fa-regular, img.nav-arrow').forEach(function (img) {
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
    </script>

</body>

</html>