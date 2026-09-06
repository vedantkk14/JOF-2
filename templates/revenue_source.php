<?php
session_start();
require '../config.php';

// Check login
if (!isset($_SESSION['user_id'])) {
    header("Location: ../index.php");
    exit;
}

// --- 1. Date Filter Logic ---
$filter = isset($_GET['filter']) ? $_GET['filter'] : 'this_month';
$date_condition = "";

switch ($filter) {
    case 'today':
        $date_condition = "AND DATE(created_at) = CURDATE()";
        $period_label = "Today";
        break;
    case 'this_week':
        $date_condition = "AND YEARWEEK(created_at, 1) = YEARWEEK(CURDATE(), 1)";
        $period_label = "This Week";
        break;
    case 'this_month':
        $date_condition = "AND MONTH(created_at) = MONTH(CURDATE()) AND YEAR(created_at) = YEAR(CURDATE())";
        $period_label = "This Month";
        break;
    case 'this_year':
        $date_condition = "AND YEAR(created_at) = YEAR(CURDATE())";
        $period_label = "This Year";
        break;
    case 'all_time':
        $date_condition = "";
        $period_label = "All Time";
        break;
    default:
        $date_condition = "AND MONTH(created_at) = MONTH(CURDATE()) AND YEAR(created_at) = YEAR(CURDATE())";
        $period_label = "This Month";
        break;
}

// --- 2. Fetch Aggregated Revenue Data ---
$sql = "
    SELECT membership_type, SUM(count) as count, SUM(total_revenue) as total_revenue
    FROM (
        SELECT 
            membership_type, 
            COUNT(*) as count, 
            SUM(amount_received) as total_revenue
        FROM member_payments
        WHERE 1=1 $date_condition
        GROUP BY membership_type
        
        UNION ALL
        
        SELECT 
            service_type as membership_type,
            COUNT(*) as count,
            SUM(price) as total_revenue
        FROM addon_services_bookings
        WHERE status != 'cancelled' $date_condition
        GROUP BY service_type
        
        UNION ALL
        
        SELECT 
            'Consultation' as membership_type,
            COUNT(*) as count,
            SUM(total_amount) as total_revenue
        FROM consultations
        WHERE 1=1 $date_condition
    ) as combined_revenue
    GROUP BY membership_type
    ORDER BY total_revenue DESC
";

$result = $conn->query($sql);
$revenue_data = [];
$total_period_revenue = 0;

if ($result) {
    while ($row = $result->fetch_assoc()) {
        $row['avg_revenue'] = $row['count'] > 0 ? round($row['total_revenue'] / $row['count']) : 0;

        // Categorize for UI enhancements (Icons/Colors)
        $type_lower = strtolower($row['membership_type']);

        // NEW: Detect Consultations automatically
        if (strpos($type_lower, 'consultation') !== false) {
            $row['category'] = 'consultation';
        } elseif (strpos($type_lower, 'pt') !== false || strpos($type_lower, 'personal') !== false) {
            $row['category'] = 'pt';
        } elseif (strpos($type_lower, 'diet') !== false) {
            $row['category'] = 'diet';
        } elseif (strpos($type_lower, 'yoga') !== false || strpos($type_lower, 'zumba') !== false) {
            $row['category'] = 'class';
        } else {
            $row['category'] = 'membership';
        }

        $revenue_data[] = $row;
        $total_period_revenue += $row['total_revenue'];
    }
}

// Find Highest and Lowest
$highest_source = empty($revenue_data) ? null : $revenue_data[0];
$lowest_source = empty($revenue_data) ? null : $revenue_data[count($revenue_data) - 1];

// Prepare Data for Charts
$chart_labels = [];
$chart_values = [];
$chart_colors = [];

// Predefined colors
$color_palette = ['#3b82f6', '#10b981', '#ec4899', '#f59e0b', '#8b5cf6', '#ef4444', '#6366f1'];

foreach ($revenue_data as $index => $item) {
    $chart_labels[] = $item['membership_type'];
    $chart_values[] = $item['total_revenue'];
    $chart_colors[] = $color_palette[$index % count($color_palette)];
}

?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" size="16x16" href="../icons/favicon-dark-logo.png" type="image/png">
    <title>JOF INDIA | Revenue Source</title>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <link rel="stylesheet" href="../static/root.css">

</head>

<body class="page-revenue_source">

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
                    <h1>Revenue Analysis</h1>
                    <p>Track profitability by service type.</p>
                </div>
            </div>

            <section class="report-controls" style="margin-bottom: 24px;">
                <div class="control-left">
                    <label>Period:</label>
                    <div class="filter-group">
                        <a href="?filter=this_month"
                            class="filter-btn <?= $filter == 'this_month' ? 'active' : '' ?>">Month</a>
                        <a href="?filter=this_year"
                            class="filter-btn <?= $filter == 'this_year' ? 'active' : '' ?>">Year</a>
                        <a href="?filter=all_time" class="filter-btn <?= $filter == 'all_time' ? 'active' : '' ?>">All
                            Time</a>
                    </div>
                </div>
                <div class="control-right">
                    <h3 style="margin: 0; color: #4a5568;">Total: <span
                            style="color:#059669; font-weight:700;">₹<?= number_format($total_period_revenue) ?></span>
                    </h3>
                </div>
            </section>

            <section class="kpi-grid">
                <?php if ($highest_source): ?>
                    <div class="stat-card">
                        <div class="stat-header">
                            <div class="stat-icon green-gradient">
                                <img src="../icons/wallet-solid-full.svg" class="fa-solid fa-wallet" width="24">
                            </div>
                        </div>
                        <div class="stat-body">
                            <h3>Highest Revenue Source</h3>
                            <h2><?= htmlspecialchars($highest_source['membership_type']) ?></h2>
                            <p class="growth positive">
                                ₹<?= number_format($highest_source['total_revenue']) ?> (<?= $period_label ?>)
                            </p>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if ($lowest_source): ?>
                    <div class="stat-card">
                        <div class="stat-header">
                            <div class="stat-icon red-gradient">
                                <img src="../icons/sack-dollar-solid-full.svg" class="fa-solid fa-sack-dollar" width="24">
                            </div>
                        </div>
                        <div class="stat-body">
                            <h3>Needs Improvement</h3>
                            <h2><?= htmlspecialchars($lowest_source['membership_type']) ?></h2>
                            <p class="growth negative">
                                Only ₹<?= number_format($lowest_source['total_revenue']) ?> (<?= $period_label ?>)
                            </p>
                        </div>
                    </div>
                <?php endif; ?>
            </section>

            <div class="charts-split">
                <div class="chart-card">
                    <div class="chart-header">
                        <h3>Revenue Distribution</h3>
                    </div>
                    <div class="chart-container" style="height: 300px;">
                        <canvas id="revenuePieChart"></canvas>
                    </div>
                </div>

                <div class="chart-card">
                    <div class="chart-header">
                        <h3>Quick Stats</h3>
                    </div>
                    <div style="display: flex; flex-direction: column; gap: 15px;">
                        <?php foreach (array_slice($revenue_data, 0, 5) as $item): ?>
                            <div
                                style="display: flex; justify-content: space-between; align-items: center; padding-bottom: 10px; border-bottom: 1px solid #f7fafc;">
                                <div>
                                    <div style="font-weight: 600; font-size: 0.95rem;">
                                        <?= htmlspecialchars($item['membership_type']) ?>
                                    </div>
                                    <div style="font-size: 0.8rem; color: #718096;"><?= $item['count'] ?> transactions</div>
                                </div>
                                <div style="font-weight: 700; color: #2d3748;">₹<?= number_format($item['total_revenue']) ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <div class="table-panel">
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th>Service / Plan Type</th>
                                <th>Count</th>
                                <th class="right">Total Revenue</th>
                                <th class="right">Avg / Transaction</th>
                                <th>Contribution</th>
                            </tr>
                        </thead>

                        <tbody>
                            <?php if (count($revenue_data) > 0): ?>
                                <?php foreach ($revenue_data as $row):
                                    $percent = ($total_period_revenue > 0) ? ($row['total_revenue'] / $total_period_revenue) * 100 : 0;

                                    // NEW: Icons based on category including Consultations
                                    $icon = 'credit-card-regular-full.svg';
                                    $colorClass = 'blue';

                                    if ($row['category'] == 'consultation') {
                                        $icon = 'clipboard-user-solid-full.svg';
                                        $colorClass = 'pink';
                                    } elseif ($row['category'] == 'pt') {
                                        $icon = 'dumbbell-solid-full.svg';
                                        $colorClass = 'purple';
                                    } elseif ($row['category'] == 'diet') {
                                        $icon = 'carrot-solid-full.svg';
                                        $colorClass = 'green';
                                    } elseif ($row['category'] == 'class') {
                                        $icon = 'users-solid-full.svg';
                                        $colorClass = 'orange';
                                    }
                                    ?>
                                    <tr>
                                        <td data-label="Service Type">
                                            <div class="user-info">
                                                <div class="avatar <?= $colorClass ?>"><img src="../icons/<?= $icon ?>"
                                                        class="fa-solid" width="16"></div>
                                                <div>
                                                    <span
                                                        class="bold"><?= htmlspecialchars($row['membership_type']) ?: 'Unknown' ?></span>
                                                    <span class="email-sub"><?= ucfirst($row['category']) ?></span>
                                                </div>
                                            </div>
                                        </td>
                                        <td data-label="Count"><?= $row['count'] ?></td>
                                        <td class="amount right" data-label="Total Revenue">
                                            ₹<?= number_format($row['total_revenue']) ?></td>
                                        <td class="right" data-label="Avg">₹<?= number_format($row['avg_revenue']) ?></td>
                                        <td data-label="Contribution">
                                            <div style="display: flex; align-items: center; gap: 10px;">
                                                <div class="progress-bar-bg" style="margin-top:0; width: 100px;">
                                                    <div class="progress-bar-fill fill-<?= $percent > 50 ? 'high' : ($percent > 20 ? 'medium' : 'low') ?>"
                                                        style="width: <?= $percent ?>%;"></div>
                                                </div>
                                                <span
                                                    style="font-size: 0.8rem; font-weight: 600; color: #718096;"><?= round($percent) ?>%</span>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="5" style="text-align:center; padding: 20px;">No revenue data for this
                                        period.</td>
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
            // Sidebar Toggles
            const toggleBtn = document.getElementById('toggleBtn');
            const mobileToggle = document.getElementById('mobileToggle');
            const body = document.body;
            const sidebar = document.querySelector('.sidebar');

            if (toggleBtn) toggleBtn.addEventListener('click', () => body.classList.toggle('collapsed'));
            if (mobileToggle) mobileToggle.addEventListener('click', () => body.classList.toggle('sidebar-open'));

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
                            if (otherItem !== item) otherItem.classList.remove('active');
                        });
                        item.classList.toggle('active');
                    });
                }
            });

            // Chart.js - Revenue Pie Chart
            const ctx = document.getElementById('revenuePieChart').getContext('2d');

            <?php if (!empty($chart_labels)): ?>
                new Chart(ctx, {
                    type: 'doughnut',
                    data: {
                        labels: <?= json_encode($chart_labels) ?>,
                        datasets: [{
                            data: <?= json_encode($chart_values) ?>,
                            backgroundColor: <?= json_encode($chart_colors) ?>,
                            borderWidth: 0,
                            hoverOffset: 4
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        cutout: '65%',
                        plugins: {
                            legend: {
                                position: 'bottom',
                                labels: {
                                    padding: 20,
                                    usePointStyle: true
                                }
                            }
                        }
                    }
                });
            <?php endif; ?>
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