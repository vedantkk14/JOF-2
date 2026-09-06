<?php
require_once __DIR__ . '/../auth/auth_check.php';
require_role(['admin', 'trainer']);
require_once __DIR__ . '/../config.php';

// Query for converted members
$converted_sql = "SELECT 
    m.full_name,
    m.phone_number,
    sl.source,
    m.membership,
    m.created_at
FROM members m
INNER JOIN sales_leads sl ON m.lead_source_id = sl.id
WHERE sl.lead_status = 'Converted'
ORDER BY m.created_at DESC";

$converted_result = $conn->query($converted_sql);


// --- 1. FUNNEL DATA ---
// Total Leads
$total_leads_res = $conn->query("SELECT COUNT(*) as count FROM sales_leads");
$total_leads = $total_leads_res->fetch_assoc()['count'];

// Contacted (Status: Contacted, Trial Booked, Converted)
$contacted_res = $conn->query("SELECT COUNT(*) as count FROM sales_leads WHERE lead_status IN ('Contacted', 'Trial Booked', 'Converted')");
$contacted = $contacted_res->fetch_assoc()['count'];

// Trial Booked (Status: Trial Booked, Converted)
$trial_res = $conn->query("SELECT COUNT(*) as count FROM sales_leads WHERE lead_status IN ('Trial Booked', 'Converted')");
$trial = $trial_res->fetch_assoc()['count'];

// Joined (Status: Converted)
$joined_res = $conn->query("SELECT COUNT(*) as count FROM sales_leads WHERE lead_status = 'Converted'");
$joined = $joined_res->fetch_assoc()['count'];

// --- 2. LOST LEADS DATA ---
$lost_sql = "SELECT full_name, phone_number, source, remarks, updated_at 
             FROM sales_leads 
             WHERE lead_status = 'Lost' 
             ORDER BY updated_at DESC LIMIT 10";
$lost_result = $conn->query($lost_sql);
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" size="16x16" href="../icons/favicon-dark-logo.png" type="image/png">
    <title>JOF INDIA | Sales Performance Reports</title>
    <link rel="stylesheet" href="../static/root.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
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
                    <h1>Sales Performance Reports</h1>
                    <p>Analyze conversion rates and lost opportunities</p>
                </div>
            </header>

            <!-- Section 1: Lead Conversion -->
            <div class="chart-card">
                <div class="chart-header">
                    <h3><img src="../icons/filter-circle-dollar-solid-full.svg" class="fa-solid fa-filter-circle-dollar" alt="JO" width="20"> Lead Conversion Funnel</h3>
                    <p>Track the journey from initial leads to joined members</p>
                </div>
                <div class="chart-container">
                    <canvas id="funnelChart"></canvas>
                </div>
            </div>

            <!-- Converted Members Table -->
            <div class="table-card">
                <div class="table-header">
                    <h3><img src="../icons/user-check-solid-full.svg" class="fa-solid fa-user-check" alt="JO" width="20"> Converted Members</h3>
                </div>

                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Contact</th>
                            <th>Source</th>
                            <th>Membership</th>
                            <th>Date Joined</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($converted_result && $converted_result->num_rows > 0): ?>
                            <?php while ($row = $converted_result->fetch_assoc()): ?>
                                <?php
                                // Badge Class Logic for Source
                                $badgeClass = 'badge-social';
                                $source = $row['source'];
                                if ($source == 'Walk-in')
                                    $badgeClass = 'badge-walkin';
                                elseif ($source == 'Word of Mouth')
                                    $badgeClass = 'badge-referral';
                                elseif ($source == 'Google Ads' || $source == 'Flyer/Poster')
                                    $badgeClass = 'badge-ads';

                                // Membership badge color
                                $membershipClass = 'badge-social';
                                if ($row['membership'] == 'Gold')
                                    $membershipClass = 'badge-referral';
                                elseif ($row['membership'] == 'Silver')
                                    $membershipClass = 'badge-walkin';
                                elseif ($row['membership'] == 'Bronze')
                                    $membershipClass = 'badge-ads';
                                ?>
                                <tr>
                                    <td data-label="Name">
                                        <strong>
                                            <?= htmlspecialchars($row['full_name']) ?>
                                        </strong>
                                    </td>
                                    <td data-label="Contact">
                                        <?= htmlspecialchars($row['phone_number']) ?>
                                    </td>
                                    <td data-label="Source">
                                        <span class="badge <?= $badgeClass ?>">
                                            <?= htmlspecialchars($source) ?>
                                        </span>
                                    </td>
                                    <td data-label="Membership">
                                        <span class="badge <?= $membershipClass ?>">
                                            <?= htmlspecialchars($row['membership']) ?>
                                        </span>
                                    </td>
                                    <td data-label="Date Joined">
                                        <?= date('M d, Y', strtotime($row['created_at'])) ?>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="5" style="text-align:center; padding: 20px;">No converted members yet. Convert
                                    leads to see them here!</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- Section 2: Cancellation/Lost Report -->
            <div class="table-card">
                <div class="table-header">
                    <h3><img src="../icons/circle-xmark-solid-full.svg" class="fa-solid fa-circle-xmark" alt="JO" width="20"> Lost Leads & Cancellations</h3>
                </div>

                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Contact</th>
                            <th>Source</th>
                            <th>Reason Not Joined</th>
                            <th>Date Lost</th>
                        </tr>
                    </thead>
                    <tbody>

                        <?php if ($lost_result && $lost_result->num_rows > 0): ?>
                            <?php while ($lead = $lost_result->fetch_assoc()): ?>
                                <?php
                                $sourceBadge = 'badge-social';
                                $src = $lead['source'];
                                if ($src == 'Walk-in') $sourceBadge = 'badge-walkin';
                                elseif ($src == 'Word of Mouth') $sourceBadge = 'badge-referral';
                                elseif ($src == 'Google Ads' || $src == 'Flyer/Poster') $sourceBadge = 'badge-ads';
                                ?>
                                <tr>
                                    <td data-label="Name"><strong><?= htmlspecialchars($lead['full_name']) ?></strong></td>
                                    <td data-label="Contact"><?= htmlspecialchars($lead['phone_number']) ?></td>
                                    <td data-label="Source"><span class="badge <?= $sourceBadge ?>"><?= htmlspecialchars($src) ?></span></td>
                                    <td data-label="Reason"><?= htmlspecialchars($lead['remarks'] ?: 'No Reason Provided') ?></td>
                                    <td data-label="Date Lost"><?= date('M d, Y', strtotime($lead['updated_at'])) ?></td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="5" style="text-align:center; padding:20px; color:#666;">No lost leads recorded yet.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

        </main>
    </div>

    <!-- JavaScript for Toggle Functionality and Charts -->
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

            // === CHART.JS - Conversion Funnel Chart ===
            const funnelCtx = document.getElementById('funnelChart').getContext('2d');
            new Chart(funnelCtx, {
                type: 'bar',
                data: {

                    labels: ['Leads Generated', 'Contacted', 'Trial Booked', 'Joined'],
                    datasets: [{
                        label: 'Number of People',
                        data: [<?= $total_leads ?>, <?= $contacted ?>, <?= $trial ?>, <?= $joined ?>],
                        backgroundColor: [
                            'rgba(242, 92, 42, 0.8)',
                            'rgba(167, 139, 250, 0.8)',
                            'rgba(52, 211, 153, 0.8)',
                            'rgba(59, 130, 246, 0.8)'
                        ],
                        borderRadius: 10,
                        borderWidth: 0
                    }]
                },
                options: {
                    indexAxis: 'y',
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            display: false
                        },
                        tooltip: {
                            backgroundColor: '#1a202c',
                            titleColor: '#fff',
                            bodyColor: '#fff',
                            padding: 12,
                            borderColor: '#F25C2A',
                            borderWidth: 1
                        }
                    },
                    scales: {
                        x: {
                            beginAtZero: true,
                            grid: {
                                color: 'rgba(0, 0, 0, 0.05)'
                            }
                        },
                        y: {
                            grid: {
                                display: false
                            }
                        }
                    }
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
