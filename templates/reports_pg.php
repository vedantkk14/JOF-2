<?php
require_once __DIR__ . '/../auth/auth_check.php';
require_role(['admin', 'trainer']);
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../auth/revenue_helper.php';

// Default Date Range: Current Month
$start_date = date('Y-m-01');
$end_date = date('Y-m-t');

// Handle Date Filter (if submitted)
if (isset($_GET['start_date']) && isset($_GET['end_date'])) {
    $s = trim($_GET['start_date']);
    $e = trim($_GET['end_date']);
    // Validate date format (YYYY-MM-DD)
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $s) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $e)) {
        $start_date = $conn->real_escape_string($s);
        $end_date = $conn->real_escape_string($e);
    }
}

// Built on the shared revenue ledger (auth/revenue_helper.php): the original signup/
// renewal payment and each later installment are separate, correctly-dated
// transactions, so a date-range filter here never double-counts or misses money
// that came in as an installment against an older membership row.
$revenue_sql = "
    SELECT COALESCE(SUM(amount), 0) as total FROM (" . revenue_ledger_sql() . ") AS ledger
    WHERE DATE(tx_date) BETWEEN '$start_date' AND '$end_date'
";
$revenue_result = $conn->query($revenue_sql);
$total_revenue = ($revenue_result) ? ($revenue_result->fetch_assoc()['total'] ?? 0) : 0;

// Total Due Payments (Outstanding) - This usually means *currently* causing debt, regardless of when it started
// So we look for balance_pending > 0 and due_date <= today
$dues_sql = "SELECT COALESCE(SUM(mp.balance_pending), 0) as total FROM member_payments mp INNER JOIN members m ON mp.member_id = m.id WHERE mp.balance_pending > 0 AND mp.next_due_date <= CURDATE()";
$dues_result = $conn->query($dues_sql);
$total_dues = $dues_result->fetch_assoc()['total'];

// Total Due Members (Count)
$due_members_sql = "SELECT COUNT(DISTINCT mp.member_id) as count FROM member_payments mp INNER JOIN members m ON mp.member_id = m.id WHERE mp.balance_pending > 0 AND mp.next_due_date <= CURDATE()";
$due_members_result = $conn->query($due_members_sql);
$total_due_members = $due_members_result->fetch_assoc()['count'];


// --- 2. CHART DATA: REVENUE TREND (Last 6 Months) ---
$trend_labels = [];
$trend_data = [];

for ($i = 5; $i >= 0; $i--) {
    $month_start = date("Y-m-01", strtotime("-$i months"));
    $month_end = date("Y-m-t", strtotime("-$i months"));
    $month_label = date("M", strtotime("-$i months"));

    $month_rev_sql = "
        SELECT COALESCE(SUM(amount), 0) as total FROM (" . revenue_ledger_sql() . ") AS ledger
        WHERE DATE(tx_date) BETWEEN '$month_start' AND '$month_end'
    ";
    $month_rev_res = $conn->query($month_rev_sql);
    $month_total = $month_rev_res->fetch_assoc()['total'] ?? 0;

    $trend_labels[] = $month_label;
    $trend_data[] = $month_total;
}

// --- 3. CHART DATA: MEMBER STATUS (Active vs Expired) ---
// Active: Valid membership (end_date >= CURDATE())
// Expired: end_date < CURDATE()
// Judged by each member's LATEST confirmed payment row only — a plain COUNT(DISTINCT)
// over every row would double-count a renewed member (their old expired row still
// matches "expired" even though their new row makes them active), inflating both
// buckets and skewing the retention rate below.
$status_sql = "
    SELECT
        SUM(CASE WHEN mp.end_date >= CURDATE() THEN 1 ELSE 0 END) as active_count,
        SUM(CASE WHEN mp.end_date <  CURDATE() THEN 1 ELSE 0 END) as expired_count
    FROM member_payments mp
    INNER JOIN (
        SELECT member_id, MAX(payment_id) as latest_pid
        FROM member_payments
        WHERE membership_type != 'Pending Setup'
        GROUP BY member_id
    ) lp ON lp.member_id = mp.member_id AND lp.latest_pid = mp.payment_id
";
$status_row = $conn->query($status_sql)->fetch_assoc();
$active_mem = (int) ($status_row['active_count'] ?? 0);
$expired_mem = (int) ($status_row['expired_count'] ?? 0);

// Calculate Retention Rate
$total_mems = $active_mem + $expired_mem;
$retention_rate = $total_mems > 0 ? round(($active_mem / $total_mems) * 100) : 0;

?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" size="16x16" href="../icons/favicon-dark-logo.png" type="image/png">
    <title>JOF INDIA | Reports</title>
    <link rel="stylesheet" href="../static/root.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>

    
</head>

<body class="page-dashboard page-reports">

    <button class="mobile-toggle" id="mobileToggle">
        <img src="../icons/bars-solid-full.svg" class="fa-solid fa-bars">
    </button>

    <button class="toggle-sidebar-btn" id="toggleBtn">
        <img src="../icons/chevron-left-solid-full.svg" class="fa-solid fa-chevron-left">
    </button>

    <div class="dashboard-container">

        <?php include 'sidebar.php'; ?>

        <main class="main-content">

            <header class="header-banner">
                <div class="header-text">
                    <h1>KPI Reports</h1>
                    <p>Comprehensive insights into revenue, growth, and retention.</p>
                </div>
            </header>

            <section class="report-controls">
                <form method="GET" class="control-left">
                    <label>Report Period:</label>
                    <div class="date-inputs">
                        <div class="input-wrapper">
                            <span>From</span>
                            <input type="date" name="start_date" value="<?= $start_date ?>">
                        </div>
                        <div class="input-wrapper">
                            <span>To</span>
                            <input type="date" name="end_date" value="<?= $end_date ?>">
                        </div>
                        <button type="submit" class="btn-filter"
                            style="margin-left: 10px; padding: 0.5rem 1rem; background: var(--primary-color); color: white; border: none; border-radius: 8px; cursor: pointer;">Filter</button>
                    </div>
                </form>

                <!-- <div class="control-right">
                    <button class="btn-export pdf">
                        <img src="../icons/file-solid-full.svg" width="14" class="btn-icon"> Export PDF
                    </button>
                </div> -->
            </section>

            <section class="kpi-grid">

                <div class="stat-card">
                    <div class="stat-header">
                        <div class="stat-icon blue-gradient">
                            <img src="../icons/wallet-solid-full.svg" class="fa-solid fa-wallet" width="24">
                        </div>
                        <div class="menu-dots"><img src="../icons/ellipsis-solid-full.svg" class="fa-solid fa-ellipsis" width="20"></div>
                    </div>
                    <div class="stat-body">
                        <h3>Total Revenue</h3>
                        <h2>₹<?= number_format($total_revenue) ?></h2>
                        <p class="growth positive">
                            Collected this period
                        </p>
                    </div>
                </div>

                <div class="stat-card">
                    <div class="stat-header">
                        <div class="stat-icon red-gradient">
                            <img src="../icons/sack-dollar-solid-full.svg" class="fa-solid fa-sack-dollar" width="24">
                        </div>
                        <div class="menu-dots"><img src="../icons/ellipsis-solid-full.svg" class="fa-solid fa-ellipsis" width="20"></div>
                    </div>
                    <div class="stat-body">
                        <h3>Total Due Payments</h3>
                        <h2>₹<?= number_format($total_dues) ?></h2>
                        <p class="growth negative">
                            Pending collection
                        </p>
                    </div>
                </div>

                <div class="stat-card">
                    <div class="stat-header">
                        <div class="stat-icon orange-gradient">
                            <img src="../icons/users-solid-full.svg" class="fa-solid fa-users" width="24">
                        </div>
                        <div class="menu-dots"><img src="../icons/ellipsis-solid-full.svg" class="fa-solid fa-ellipsis" width="20"></div>
                    </div>
                    <div class="stat-body">
                        <h3>Total Due Members</h3>
                        <h2><?= $total_due_members ?></h2>
                        <p class="growth neutral">
                            Members with outstanding bills
                        </p>
                    </div>
                </div>
            </section>

            <section class="content-grid">

                <div class="panel chart-panel">
                    <div class="panel-header">
                        <h3>Revenue Trends (Last 6 Months)</h3>
                    </div>
                    <div class="chart-container-wrapper">
                        <canvas id="revenueChart"></canvas>
                    </div>
                </div>

                <div class="panel chart-panel">
                    <div class="panel-header">
                        <h3>Member Status & Retention</h3>
                    </div>
                    <div class="chart-container-wrapper">
                        <canvas id="memberStatusChart"></canvas>
                    </div>
                    <div class="chart-meta" style="text-align: center; margin-top: 10px;">
                        <span><b style="color:#F25C2A"><?= $retention_rate ?>%</b> Retention Rate</span>
                    </div>
                </div>

            </section>

            <h3 class="section-title">Detailed Reports</h3>
            <section class="reports-actions">

                <a href="renewal_report.php" class="report-card">
                    <div class="icon-circle soft-blue">
                        <img src="../icons/circle-exclamation-solid-full (1).svg" width="24">
                    </div>
                    <div class="card-text">
                        <h4>Expiring Soon</h4>
                        <p>Renewal Reminders</p>
                    </div>
                    <div class="watermark-icon"><img src="../icons/circle-exclamation-solid-full (1).svg"></div>
                </a>

                <a href="revenue_source.php" class="report-card">
                    <div class="icon-circle soft-green">
                        <img src="../icons/dollar-sign-solid-full.svg" width="24">
                    </div>
                    <div class="card-text">
                        <h4>Revenue Sources</h4>
                        <p>Profitable Services</p>
                    </div>
                    <div class="watermark-icon"><img src="../icons/dollar-sign-solid-full.svg"></div>
                </a>

            </section>

        </main>
    </div>

    <!-- JavaScript -->
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            // --- UI Interaction ---
            const toggleBtn = document.getElementById('toggleBtn');
            const mobileToggle = document.getElementById('mobileToggle');
            const body = document.body;
            const sidebar = document.querySelector('.sidebar');

            if (toggleBtn) toggleBtn.addEventListener('click', () => body.classList.toggle('collapsed'));
            if (mobileToggle) mobileToggle.addEventListener('click', () => {
                body.classList.toggle('sidebar-open');
            });

            // Mobile outside click
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

            // --- CHART 1: REVENUE TREND (Bar) ---
            const revenueCtx = document.getElementById('revenueChart').getContext('2d');
            new Chart(revenueCtx, {
                type: 'bar',
                data: {
                    labels: <?= json_encode($trend_labels) ?>,
                    datasets: [{
                        label: 'Revenue (₹)',
                        data: <?= json_encode($trend_data) ?>,
                        backgroundColor: '#4C6EF5', // Blue
                        borderRadius: 6,
                        barThickness: 20
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            backgroundColor: '#1E293B',
                            titleColor: '#fff',
                            bodyColor: '#fff',
                            borderColor: '#4C6EF5',
                            borderWidth: 1,
                            padding: 10
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            grid: { color: 'rgba(0,0,0,0.05)' }
                        },
                        x: {
                            grid: { display: false }
                        }
                    }
                }
            });

            // --- CHART 2: MEMBER STATUS (Doughnut) ---
            const memberCtx = document.getElementById('memberStatusChart').getContext('2d');
            new Chart(memberCtx, {
                type: 'doughnut',
                data: {
                    labels: ['Active Members', 'Expired/Inactive'],
                    datasets: [{
                        data: [<?= $active_mem ?>, <?= $expired_mem ?>],
                        backgroundColor: ['#34D399', '#F87171'], // Green, Red
                        borderWidth: 0,
                        hoverOffset: 4
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    cutout: '70%',
                    plugins: {
                        legend: {
                            position: 'bottom',
                            labels: { usePointStyle: true, boxWidth: 8 }
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