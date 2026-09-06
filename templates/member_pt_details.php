<?php
session_start();
require '../config.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: ../index.php");
    exit;
}

if (!isset($_GET['pt_id'])) {
    die("PT ID not provided.");
}

$pt_id = intval($_GET['pt_id']);

// 1. Fetch PT Details
$sql = "
    SELECT 
        pt.*,
        m.full_name,
        m.phone_number,
        m.email,
        m.id as member_id,
        t.full_name as trainer_name,
        t.specialization as trainer_type
    FROM personal_training pt
    JOIN members m ON pt.member_id = m.id
    LEFT JOIN trainers t ON pt.trainer_id = t.id
    WHERE pt.id = ?
";

$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $pt_id);
$stmt->execute();
$result = $stmt->get_result();
$pt = $result->fetch_assoc();

if (!$pt) {
    die("PT Record not found.");
}

// 2. Auto-complete past sessions
// Mark any scheduled sessions from past dates as completed
mysqli_query($conn, "UPDATE pt_sessions SET status = 'Completed' WHERE status = 'Scheduled' AND session_date < CURDATE()");

// 3. Fetch Sessions
$sessionSql = "SELECT * FROM pt_sessions WHERE pt_id = ? ORDER BY session_date ASC, session_time ASC";
$s_stmt = $conn->prepare($sessionSql);
$s_stmt->bind_param("i", $pt_id);
$s_stmt->execute();
$sessionResult = $s_stmt->get_result();
$sessions = [];
while ($row = $sessionResult->fetch_assoc()) {
    $sessions[] = $row;
}

// Pass sessions to JS for the calendar
$sessions_json = json_encode($sessions);

// 3. Stats
$total = intval($pt['total_sessions']);
$used = count(array_filter($sessions, function($s) { return $s['status'] !== 'Cancelled'; }));
$remaining = max(0, $total - $used);

$m_id = $pt['member_id'];
$total_fees = floatval($pt['pt_fees']);
$paid_amount = 0;

$payQuery = "
    SELECT SUM(amount_received) as total_paid
    FROM member_payments 
    WHERE member_id = $m_id 
    AND (membership_type LIKE 'Consultation%' OR membership_type LIKE 'Personal Training%')
    AND start_date = '{$pt['start_date']}'
";
$payRes = mysqli_query($conn, $payQuery);
if ($payRes && mysqli_num_rows($payRes) > 0) {
    $payRow = mysqli_fetch_assoc($payRes);
    $paid_amount = floatval($payRow['total_paid'] ?? 0);
}

if ($paid_amount == 0 && $total_fees > 0) {
    $payQuery2 = "
        SELECT amount_received 
        FROM member_payments 
        WHERE member_id = $m_id 
        AND (membership_type LIKE 'Consultation%' OR membership_type LIKE 'Personal Training%')
        ORDER BY payment_id DESC LIMIT 1
    ";
    $payRes2 = mysqli_query($conn, $payQuery2);
    if ($payRes2 && mysqli_num_rows($payRes2) > 0) {
        $payRow2 = mysqli_fetch_assoc($payRes2);
        // Ensure we don't overestimate if they paid for multiple things. 
        // We'll trust the specific recent payment if it matches or is near total fees.
        if (floatval($payRow2['amount_received']) > 0) {
            $paid_amount = floatval($payRow2['amount_received']);
        }
    }
}

$pending = max(0, $total_fees - $paid_amount);
$pay_status = ($pending == 0) ? 'Paid' : 'Partial';
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" size="16x16" href="../icons/favicon-dark-logo.png" type="image/png">
    <title><?= htmlspecialchars($pt['full_name']) ?> - PT Details</title>
    <link rel="stylesheet" href="../static/root.css">
</head>

<body class="page-dashboard page-member_pt_details">
    <button class="mobile-toggle" id="mobileToggle"><img src="../icons/bars-solid-full.svg" class="fa-solid fa-bars"></button>
    <button class="toggle-sidebar-btn" id="toggleBtn"><img src="../icons/chevron-left-solid-full.svg" class="fa-solid fa-chevron-left"></button>

    <div class="dashboard-container">
        <?php include 'sidebar.php'; ?>

        <main class="main-content">
            <header class="header">
                <div class="user-profile">
                    <div class="avatar"><?= strtoupper(substr($pt['full_name'], 0, 1)) ?></div>
                    <div class="user-info">
                        <h2><?= htmlspecialchars($pt['full_name']) ?></h2>
                        <div class="meta">Member ID: <?= $pt['member_id'] ?> <span class="status-badge">Active
                                Member</span></div>
                    </div>
                </div>
                <a href="personal_training.php" class="btn-back" style="display:flex; align-items:center; gap:8px;"><img src="../icons/arrow-left-solid-full.svg" class="fa-solid fa-arrow-left" style="width:14px; height:14px; filter:brightness(0) invert(1);"> Back to PT List</a>
            </header>

            <div class="grid-container">

                <div class="left-col">
                    <div class="card">
                        <div class="card-title"><img src="../icons/dumbbell-solid-full.svg" class="fa-solid fa-dumbbell"> Personal Training Details</div>
                        <div class="stats-grid">
                            <div class="stat-box">
                                <div class="stat-label">PT Included</div>
                                <div class="stat-value green">Yes</div>
                            </div>
                            <div class="stat-box">
                                <div class="stat-label">Trainer Assigned</div>
                                <div class="stat-value"><?= htmlspecialchars($pt['trainer_name'] ?? 'Not Assigned') ?>
                                </div>
                            </div>
                            <div class="stat-box">
                                <div class="stat-label">PT Start Date</div>
                                <div class="stat-value"><?= date('M d, Y', strtotime($pt['start_date'])) ?></div>
                            </div>
                            <div class="stat-box">
                                <div class="stat-label">PT End Date</div>
                                <div class="stat-value"><?= date('M d, Y', strtotime($pt['end_date'])) ?></div>
                            </div>
                            <div class="stat-box">
                                <div class="stat-label">Total Sessions</div>
                                <div class="stat-value"><?= $total ?></div>
                            </div>
                            <div class="stat-box">
                                <div class="stat-label">Sessions Used</div>
                                <div class="stat-value"><?= $used ?></div>
                            </div>
                            <div class="stat-box highlight">
                                <div class="stat-label">Sessions Remaining</div>
                                <div class="stat-value red"><?= $remaining ?></div>
                            </div>
                            <div class="stat-box">
                                <div class="stat-label">PT Status</div>
                                <div class="stat-value green">Active</div>
                            </div>
                        </div>
                    </div>

                    <div class="card">
                        <div class="card-title"><img src="../icons/indian-rupee-sign-solid-full.svg" class="fa-solid fa-indian-rupee-sign"> PT Payment Details</div>
                        <div class="payment-row">
                            <div class="stat-box">
                                <div class="stat-label">PT Total Fees</div>
                                <div class="stat-value">₹<?= number_format($total_fees) ?></div>
                            </div>
                            <div class="stat-box">
                                <div class="stat-label">Paid Amount</div>
                                <div class="stat-value green">₹<?= number_format($paid_amount) ?></div>
                            </div>
                            <div class="stat-box">
                                <div class="stat-label">Pending Amount</div>
                                <div class="stat-value red">₹<?= number_format($pending) ?></div>
                            </div>
                            <div class="stat-box highlight">
                                <div class="stat-label">Payment Status</div>
                                <div class="stat-value"><?= $pay_status ?></div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="right-col">
                    <div class="card">
                        <div class="card-title"><img src="../icons/calendar-check-solid-full.svg" class="fa-regular fa-calendar-check"> Session Calendar</div>
                        <p style="font-size:13px; color:#6B7280; margin-bottom:15px;">
                            View scheduled and completed sessions.
                        </p>

                        <div class="mini-calendar">
                            <div class="calendar-header">
                                <button class="cal-nav-btn" id="prevMonth"><img src="../icons/chevron-left-solid-full.svg"
                                        class="fa-solid fa-chevron-left"></button>
                                <div class="month-label" id="monthLabel">January 2026</div>
                                <button class="cal-nav-btn" id="nextMonth"><img src="../icons/chevron-right-solid-full.svg"
                                        class="fa-solid fa-chevron-right"></button>
                            </div>
                            <div class="calendar-weekdays">
                                <div>Su</div>
                                <div>Mo</div>
                                <div>Tu</div>
                                <div>We</div>
                                <div>Th</div>
                                <div>Fr</div>
                                <div>Sa</div>
                            </div>
                            <div class="calendar-days" id="calendarGrid">
                            </div>
                        </div>

                        <div class="legend">
                            <div class="legend-item">
                                <div class="dot-sm" style="background:var(--success)"></div> Done
                            </div>
                            <div class="legend-item">
                                <div class="dot-sm" style="background:var(--today-orange)"></div> Today
                            </div>
                            <div class="legend-item">
                                <div class="dot-sm" style="background:#DBEAFE; border:1px solid #1E40AF"></div> Plan
                            </div>
                            <div class="legend-item">
                                <div class="dot-sm" style="background:#EF4444"></div> Rescheduled (Member)
                            </div>
                            <div class="legend-item">
                                <div class="dot-sm" style="background:#F59E0B"></div> Rescheduled (Trainer)
                            </div>
                            <div class="legend-item">
                                <div class="dot-sm" style="background:var(--danger)"></div> Missed
                            </div>
                        </div>

                        <a href="pt_session.php"
                            style="display:block; text-align:center; margin-top:20px; color:var(--primary); font-size:13px; font-weight:500; text-decoration:none;">
                            Full Schedule <img src="../icons/arrow-right-solid-full.svg" class="fa-solid fa-arrow-right">
                        </a>
                    </div>

                    <div class="card">
                        <div class="card-title"><img src="../icons/user-tie-solid-full.svg" class="fa-solid fa-user-tie"> Trainer Info</div>
                        <div class="trainer-card">
                            <div class="t-avatar"><?= strtoupper(substr($pt['trainer_name'] ?? 'NA', 0, 2)) ?></div>
                            <div>
                                <div style="font-weight:600;">
                                    <?= htmlspecialchars($pt['trainer_name'] ?? 'Not Assigned') ?>
                                </div>
                                <div style="font-size:12px; color:#6B7280;">
                                    <?= htmlspecialchars($pt['trainer_type'] ?? 'General Trainer') ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

            </div> <!-- End grid-container -->
        </main> <!-- End main-content -->
    </div> <!-- End dashboard-container -->

    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const body = document.body;
            document.getElementById('toggleBtn')?.addEventListener('click', () => body.classList.toggle('collapsed'));
            document.getElementById('mobileToggle')?.addEventListener('click', () => body.classList.toggle('sidebar-open'));
        });

        const sessions = <?= $sessions_json ?>;
        const grid = document.getElementById('calendarGrid');
        const label = document.getElementById('monthLabel');
        let currentCalDate = new Date();

        // 1. Render Calendar Function
        function renderCalendar() {
            grid.innerHTML = "";
            const year = currentCalDate.getFullYear();
            const month = currentCalDate.getMonth();

            // Set Header
            label.innerText = currentCalDate.toLocaleDateString('en-US', { month: 'long', year: 'numeric' });

            const firstDay = new Date(year, month, 1).getDay();
            const daysInMonth = new Date(year, month + 1, 0).getDate();
            const todayStr = new Date().toISOString().split('T')[0];

            // Empty slots for start
            for (let i = 0; i < firstDay; i++) {
                grid.innerHTML += `<div class="day-cell empty"></div>`;
            }

            // Day cells
            for (let i = 1; i <= daysInMonth; i++) {
                // Construct date string YYYY-MM-DD
                const dateObj = new Date(year, month, i);
                const offset = dateObj.getTimezoneOffset();
                const localDate = new Date(dateObj.getTime() - (offset * 60 * 1000));
                const dateStr = localDate.toISOString().split('T')[0];

                let classes = "day-cell";
                let tooltip = "";
                let isSession = false;

                // Check Today
                if (dateStr === todayStr) {
                    classes += " is-today";
                }

                // Check Session
                const session = sessions.find(s => s.session_date === dateStr);
                if (session) {
                    isSession = true;
                    classes += " has-session";

                    const status = session.status.toLowerCase();
                    const rescheduleType = session.reschedule_type || 'none';

                    if (status === 'missed') classes += " status-missed";
                    else if (status === 'rescheduled') {
                        // Check who requested the reschedule
                        if (rescheduleType === 'member') {
                            classes += " status-rescheduled-member";
                            let note = session.notes ? session.notes : "Rescheduled by Member";
                            tooltip = `data-tooltip="${note}"`;
                        } else if (rescheduleType === 'trainer') {
                            classes += " status-rescheduled-trainer";
                            let note = session.notes ? session.notes : "Rescheduled by Trainer";
                            tooltip = `data-tooltip="${note}"`;
                        } else {
                            // Default reschedule (no type specified)
                            classes += " status-rescheduled-member";
                            let note = session.notes ? session.notes : "Rescheduled";
                            tooltip = `data-tooltip="${note}"`;
                        }
                    }
                    else if (status === 'completed') classes += " status-completed";
                    else if (dateStr === todayStr) classes += " status-today";
                    else if (status === 'scheduled') classes += " status-scheduled";

                    // Default tooltip if not set by reschedule logic above
                    if (!tooltip) {
                        tooltip = `data-tooltip="${session.status} @ ${session.session_time.substring(0, 5)}"`;
                    }
                }

                grid.innerHTML += `<div class="${classes}" ${tooltip}>${i}</div>`;
            }
        }

        // 2. Event Listeners
        document.getElementById('prevMonth').addEventListener('click', () => {
            currentCalDate.setMonth(currentCalDate.getMonth() - 1);
            renderCalendar();
        });

        document.getElementById('nextMonth').addEventListener('click', () => {
            currentCalDate.setMonth(currentCalDate.getMonth() + 1);
            renderCalendar();
        });

        // Init
        renderCalendar();

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