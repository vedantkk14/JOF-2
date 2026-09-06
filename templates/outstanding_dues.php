<?php
require_once __DIR__ . '/../auth/auth_check.php';
require_role(['admin', 'trainer']);
require_once __DIR__ . '/../config.php';

// === FETCH OVERDUE MEMBERS ===
$sql = "SELECT 
            m.id as member_id, 
            m.full_name, 
            m.email,
            m.phone_number,
            mp.payment_id,
            mp.membership_type,
            mp.end_date,
            mp.total_amount, 
            mp.amount_received,
            mp.balance_pending, 
            mp.next_due_date
        FROM members m
        JOIN member_payments mp ON m.id = mp.member_id
        WHERE mp.balance_pending > 0 
          AND mp.next_due_date <= CURDATE() 
        ORDER BY mp.next_due_date ASC";

$result = $conn->query($sql);

if (!$result) {
    die("<div style='color:red; padding:20px;'><b>SQL Error:</b> " . $conn->error . "</div>");
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <link rel="icon" size="16x16" href="../icons/favicon-dark-logo.png" type="image/png">
    <title>Outstanding Dues | JOF INDIA</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" />
    <link rel="stylesheet" href="../static/root.css">
</head>

<body class="page-outstanding_dues">

    <button class="mobile-toggle" id="mobileToggle"><i class="fa-solid fa-bars"></i></button>
    <button class="toggle-sidebar-btn" id="toggleBtn"><i class="fa-solid fa-chevron-left"></i></button>

    <div class="dashboard-container">

        <?php include 'sidebar.php'; ?>

        <main class="main-content">

            <div class="page-header">
                <div class="header-text">
                    <h1>Outstanding Dues</h1>
                    <p>Track members who have missed their scheduled installment date.</p>
                </div>
            </div>

            <div class="table-panel">
                <div class="panel-header">
                    <h2>Overdue Payments</h2>
                </div>
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th class="col-name">Member Name</th>
                                <th class="col-date">Plan Expiry</th>
                                <th class="col-amount">Total Fee</th>
                                <th class="col-amount">Paid</th>
                                <th class="col-amount">Outstanding</th>
                                <th class="col-date">Due Date</th>
                                <th class="col-actions">Notify Via</th>
                            </tr>
                        </thead>

                        <tbody>
                            <?php if ($result && $result->num_rows > 0): ?>
                                <?php while ($row = $result->fetch_assoc()):
                                    // Calculate Days Overdue
                                    $due_date = strtotime($row['next_due_date']);
                                    $today = time();
                                    $diff = $today - $due_date;
                                    $days_overdue = floor($diff / (60 * 60 * 24));
                                    ?>
                                    <tr>
                                        <td data-label="Name">
                                            <div class="user-info">
                                                <div>
                                                    <span class="bold"><?= htmlspecialchars($row['full_name']) ?></span>
                                                    <span class="email-sub"><?= htmlspecialchars($row['email']) ?></span>
                                                </div>
                                            </div>
                                        </td>

                                        <td data-label="Plan Expiry" class="col-date">
                                            <?= !empty($row['end_date']) ? date("M d, Y", strtotime($row['end_date'])) : '-' ?>
                                        </td>

                                        <td data-label="Total Fee" class="col-amount amount-total">₹<?= number_format($row['total_amount']) ?></td>
                                        <td data-label="Paid" class="col-amount amount-paid">₹<?= number_format($row['amount_received']) ?></td>

                                        <td data-label="Outstanding" class="col-amount amount-pending" style="color:#DC2626; font-weight:700;">
                                            ₹<?= number_format($row['balance_pending']) ?>
                                        </td>

                                        <td data-label="Due Date" class="col-date">
                                            <div style="display:flex; flex-direction:column; align-items:flex-end;">
                                                <span style="color:#DC2626; font-weight:600;"><?= date("M d, Y", strtotime($row['next_due_date'])) ?></span>
                                                <span class="days-overdue"><?= $days_overdue ?> Days Late</span>
                                            </div>
                                        </td>

                                        <td data-label="Notify Via" class="col-actions">
                                            <div class="actions-cell">
                                                <?php 
                                                    $clean_phone = preg_replace('/\D/', '', $row['phone_number'] ?? '');
                                                    $wa_url = !empty($clean_phone) ? "https://wa.me/{$clean_phone}?text=" . urlencode("Hi {$row['full_name']}, your payment of ₹" . number_format($row['balance_pending']) . " is overdue.") : "#";
                                                ?>
                                                <a href="<?= $wa_url ?>"
                                                    target="_blank" class="channel-badge whatsapp">
                                                    <i class="fa-brands fa-whatsapp"></i> Chat
                                                </a>

                                                <a href="notify_member.php?id=<?= $row['member_id'] ?>&type=overdue&amount=<?= $row['balance_pending'] ?>&date=<?= $row['next_due_date'] ?>"
                                                    class="channel-badge email">
                                                    <i class="fa-solid fa-envelope"></i> Email
                                                </a>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="7" style="text-align: center; padding: 50px; color: #6B7280;">
                                        <img src="../icons/circle-check-solid-full.svg" alt="Success" width="40" style="filter: invert(48%) sepia(79%) saturate(2476%) hue-rotate(119deg) brightness(98%) contrast(92%); margin-bottom: 15px;">
                                        <p style="font-size:16px;">No outstanding dues!</p>
                                        <p style="font-size:13px; opacity:0.7;">(Or no dues scheduled for today/past)</p>
                                    </td>
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

            if (toggleBtn) toggleBtn.addEventListener('click', () => body.classList.toggle('collapsed'));
            if (mobileToggle) mobileToggle.addEventListener('click', () => body.classList.toggle('sidebar-open'));

            // Sidebar Dropdowns
            const dropdownItems = document.querySelectorAll('.nav-item-dropdown');
            dropdownItems.forEach(item => {
                const link = item.querySelector('.nav-link');
                const arrow = link ? link.querySelector('.nav-arrow') : null;
                if (arrow) {
                    arrow.addEventListener('click', function (e) {
                        e.preventDefault();
                        e.stopPropagation();
                        // Close other dropdowns
                        dropdownItems.forEach(otherItem => {
                            if (otherItem !== item) otherItem.classList.remove('active');
                        });
                        item.classList.toggle('active');
                    });
                }
            });
        });
    </script>
</body>

</html>
