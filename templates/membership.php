<?php
require_once __DIR__ . '/../auth/auth_check.php';
require_role(['admin', 'trainer']);
require_once __DIR__ . '/../config.php';

// Fetch Plans with Active Member Count
$sql = "
    SELECT 
        mp.*, 
        (SELECT COUNT(*) FROM members m 
         WHERE m.status = 'active' AND 
         COALESCE(
             (SELECT membership_type FROM member_payments mp_p WHERE mp_p.member_id = m.id ORDER BY mp_p.created_at DESC LIMIT 1),
             m.membership
         ) = mp.plan_name
        ) as active_count 
    FROM membership_plans mp 
    ORDER BY mp.price ASC
";

$result = mysqli_query($conn, $sql);

// Fetch Upcoming Add-on Service Bookings
$bookings_sql = "
    SELECT 
        b.id,
        b.service_type,
        b.scheduled_date,
        b.scheduled_time,
        b.price,
        b.status,
        m.full_name as member_name
    FROM addon_services_bookings b
    JOIN members m ON b.member_id = m.id
    WHERE b.scheduled_date >= CURDATE()
    ORDER BY b.scheduled_date ASC, b.scheduled_time ASC
";
$bookings_result = mysqli_query($conn, $bookings_sql);

// Colors array
$colors = ['blue', 'purple', 'orange', 'green'];
$colorIndex = 0;
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" size="16x16" href="../icons/favicon-dark-logo.png" type="image/png">
    <title>Membership Plans | JoF Fitness</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="../static/root.css">


</head>

<body class="page-membership">
    <button class="mobile-toggle" id="mobileToggle"><i class="fa-solid fa-bars"></i></button>
    <button class="toggle-sidebar-btn" id="toggleBtn"><i class="fa-solid fa-chevron-left"></i></button>

    <div class="dashboard-container">

        <?php include 'sidebar.php'; ?>

        <main class="main-content">

            <header class="page-header">
                <div class="header-text">
                    <h1>Membership Plans</h1>
                    <p>Create and manage pricing tiers for your studio.</p>
                </div>
                <div class="header-actions">
                    <a href="add_membership.php" class="add-btn">
                        <img src="../icons/plus-solid-full.svg" alt="plus" width="14" style="filter: brightness(0) invert(1);"> Create Plan
                    </a>
                </div>
            </header>

            <!-- Tabs for Membership Plans and Add-on Services -->
            <div class="tabs-container" style="margin-bottom: 30px;">
                <div class="tabs-header" style="display: flex; gap: 10px; border-bottom: 2px solid #E5E7EB;">
                    <button class="tab-btn active" onclick="switchTab('plans')" id="plansTab" style="padding: 12px 24px; background: none; border: none; border-bottom: 3px solid #F25C2A; 
                        color: #F25C2A; font-weight: 600; cursor: pointer; transition: all 0.3s;">
                        <img id="plansTabIcon" src="../icons/credit-card-solid-full.svg" alt="credit-card" width="16" style="vertical-align: middle; margin-bottom: 2px; margin-right: 5px; filter: invert(49%) sepia(87%) saturate(2206%) hue-rotate(344deg) brightness(100%) contrast(92%);"> Membership Plans
                    </button>
                    <button class="tab-btn" onclick="switchTab('services')" id="servicesTab" style="padding: 12px 24px; background: none; border: none; border-bottom: 3px solid transparent; 
                        color: #6B7280; font-weight: 600; cursor: pointer; transition: all 0.3s;">
                        <img id="servicesTabIcon" src="../icons/spa-solid-full.svg" alt="spa" width="16" style="vertical-align: middle; margin-bottom: 2px; margin-right: 5px; filter: invert(49%) sepia(8%) saturate(909%) hue-rotate(182deg) brightness(94%) contrast(90%);"> Add-on Services
                    </button>
                </div>
            </div>

            <!-- Membership Plans Tab Content -->
            <section class="plans-grid tab-content" id="plansContent">

                <?php if (mysqli_num_rows($result) > 0): ?>
                    <?php while ($row = mysqli_fetch_assoc($result)): ?>
                        <?php
                        $currentColor = $colors[$colorIndex % 4];
                        $colorIndex++;

                        $featuresList = explode("\n", $row['features']);

                        $period = "";
                        if ($row['duration_unit'] == 'Month')
                            $period = "/mo";
                        elseif ($row['duration_unit'] == 'Year')
                            $period = "/yr";
                        else
                            $period = "/" . $row['duration_value'] . "w";

                        // --- DESCRIPTION FORMATTER ---
                        // 1. Get raw text
                        $rawDesc = htmlspecialchars($row['description']);

                        // 2. Replace the specific "dot" bullet format you used ( .Text -> <br> check Text)
                        // This regex finds a dot preceded by space or start of line, and replaces it with a line break + icon
                        $formattedDesc = preg_replace('/(\s|^)\.(\w)/', '<br><img src="../icons/check-solid-full.svg" alt="check" width="14"> $2', $rawDesc);

                        // 3. Also handle normal newlines if you edit it properly later
                        $formattedDesc = nl2br($formattedDesc);

                        // 4. Remove leading <br> if it exists from the first item
                        if (strpos($formattedDesc, '<br>') === 0) {
                            $formattedDesc = substr($formattedDesc, 4);
                        }
                        ?>

                        <div class="plan-card">
                            <div class="color-strip <?= $currentColor ?>"></div>

                            <div class="plan-header">
                                <h2><?= htmlspecialchars($row['plan_name']) ?></h2>
                                <div class="price">
                                    <span class="currency">₹</span><?= number_format($row['price']) ?><span
                                        class="period"><?= $period ?></span>
                                </div>

                                <div class="desc">
                                    <?= $formattedDesc ?>
                                </div>
                            </div>

                            <div class="stats-row">
                                <div class="stat">
                                    <img src="../icons/users-solid-full.svg" alt="users" width="16">
                                    <strong><?= $row['active_count'] ?> Active</strong>
                                </div>
                                <div class="stat">
                                    <img src="../icons/clock-solid-full.svg" alt="clock" width="16">
                                    <strong><?= htmlspecialchars($row['duration_value'] . ' ' . $row['duration_unit']) . ($row['duration_value'] > 1 ? 's' : '') ?></strong>
                                </div>
                                <?php if (!empty($row['max_classes'])): ?>
                                <div class="stat">
                                    <img src="../icons/dumbbell-solid-full.svg" alt="dumbbell" width="16">
                                    <strong><?= htmlspecialchars($row['max_classes']) ?></strong>
                                </div>
                                <?php endif; ?>
                            </div>

                            <ul class="features">
                                <?php foreach ($featuresList as $feat): ?>
                                    <?php if (trim($feat) != ''): ?>
                                        <li><img src="../icons/check-solid-full.svg" alt="check" width="14">
                                            <?= htmlspecialchars(trim($feat)) ?></li>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </ul>

                            <div class="plan-footer">
                                <a href="edit_membership.php?id=<?= $row['id'] ?>" class="icon-btn edit" title="Edit Plan">
                                    <img src="../icons/pen-solid-full.svg" alt="pen" width="16">
                                </a>
                                <button
                                    onclick="openDeleteModal(<?= $row['id'] ?>, '<?= htmlspecialchars($row['plan_name'], ENT_QUOTES) ?>')"
                                    class="icon-btn delete" title="Delete Plan" style="border:none; cursor:pointer;">
                                    <img src="../icons/trash-can-solid-full.svg" alt="trash-can" width="16">
                                </button>
                            </div>
                        </div>

                    <?php endwhile; ?>
                <?php else: ?>
                    <p style="grid-column: 1/-1; text-align:center; color:#666;">No membership plans created yet.</p>
                <?php endif; ?>

            </section>

            <!-- Add-on Services Tab Content -->
            <section class="tab-content" id="servicesContent" style="display: none;">
                <div style="text-align: center; padding: 40px 20px;">
                    <button onclick="openBookingModal()" class="add-btn" style="font-size: 16px; padding: 15px 30px;">
                        <img src="../icons/calendar-plus-solid-full.svg" alt="calendar-plus" width="14" style="vertical-align: middle; margin-bottom: 2px; margin-right: 6px; filter: brightness(0) invert(1);"> Book Add-on Service
                    </button>
                    <p style="color: #6B7280; margin-top: 15px;">Schedule physiotherapy, nutrition consultation, massage
                        therapy, and more</p>
                </div>

                <!-- Upcoming Bookings Table -->
                <div class="table-card" style="margin-top: 30px;">
                    <div class="table-header"
                        style="padding: 20px; background: white; border-bottom: 1px solid #E5E7EB;">
                        <h3 style="font-size: 18px; color: #111827; display: flex; align-items: center; gap: 8px;">
                            <img src="../icons/list-solid-full.svg" alt="list" width="16" style="filter: brightness(0) saturate(100%) invert(41%) sepia(96%) saturate(1636%) hue-rotate(346deg) brightness(97%) contrast(93%);"> 
                            Upcoming Bookings
                        </h3>
                    </div>
                    <div style="padding: 20px; background: white;">
                        <?php if ($bookings_result && mysqli_num_rows($bookings_result) > 0): ?>
                            <div style="overflow-x: auto;">
                                <table style="width: 100%; border-collapse: collapse;">
                                    <thead>
                                        <tr style="background: #F9FAFB; text-align: left;">
                                            <th style="padding: 12px; font-weight: 600; color: #374151; font-size: 13px;">
                                                Member</th>
                                            <th style="padding: 12px; font-weight: 600; color: #374151; font-size: 13px;">
                                                Service</th>
                                            <th style="padding: 12px; font-weight: 600; color: #374151; font-size: 13px;">
                                                Date</th>
                                            <th style="padding: 12px; font-weight: 600; color: #374151; font-size: 13px;">
                                                Time</th>
                                            <th style="padding: 12px; font-weight: 600; color: #374151; font-size: 13px;">
                                                Price</th>
                                            <th style="padding: 12px; font-weight: 600; color: #374151; font-size: 13px;">
                                                Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php while ($booking = mysqli_fetch_assoc($bookings_result)): ?>
                                            <tr style="border-bottom: 1px solid #E5E7EB;">
                                                <td style="padding: 12px; color: #111827; font-size: 14px; display: flex; align-items: center; gap: 6px;">
                                                    <img src="../icons/user-solid-full.svg" alt="user" width="14"
                                                        style="filter: invert(49%) sepia(8%) saturate(909%) hue-rotate(182deg) brightness(94%) contrast(90%);">
                                                    <?= htmlspecialchars($booking['member_name']) ?>
                                                </td>
                                                <td style="padding: 12px; color: #6B7280; font-size: 14px;">
                                                    <?= htmlspecialchars($booking['service_type']) ?>
                                                </td>
                                                <td style="padding: 12px; color: #6B7280; font-size: 14px;">
                                                    <?= date('M d, Y', strtotime($booking['scheduled_date'])) ?>
                                                </td>
                                                <td style="padding: 12px; color: #6B7280; font-size: 14px;">
                                                    <?= date('h:i A', strtotime($booking['scheduled_time'])) ?>
                                                </td>
                                                <td style="padding: 12px; color: #111827; font-weight: 600; font-size: 14px;">
                                                    <?php if ($booking['price']): ?>
                                                        ₹<?= number_format($booking['price'], 0) ?>
                                                    <?php else: ?>
                                                        <span style="color: #9CA3AF;">-</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td style="padding: 12px;">
                                                    <?php
                                                    $status_color = '#10B981'; // Green for confirmed
                                                    if ($booking['status'] == 'pending')
                                                        $status_color = '#F59E0B'; // Orange
                                                    if ($booking['status'] == 'cancelled')
                                                        $status_color = '#EF4444'; // Red
                                                    ?>
                                                    <span
                                                        style="background: <?= $status_color ?>20; color: <?= $status_color ?>; 
                                                        padding: 4px 12px; border-radius: 12px; font-size: 12px; font-weight: 600;">
                                                        <?= ucfirst($booking['status']) ?>
                                                    </span>
                                                </td>
                                            </tr>
                                        <?php endwhile; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <p style="color: #6B7280; text-align: center; padding: 20px 0;">
                                No bookings yet. Click the button above to schedule a service.
                            </p>
                        <?php endif; ?>
                    </div>
                </div>
            </section>
        </main>
    </div>

    <!-- Booking Modal -->
    <div id="bookingModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; 
        background: rgba(0,0,0,0.5); z-index: 9999; justify-content: center; align-items: center;">
        <div style="background: white; border-radius: 20px; width: 90%; max-width: 600px; max-height: 90vh; 
            overflow-y: auto; box-shadow: 0 20px 25px -5px rgba(0,0,0,0.3);">
            <div
                style="padding: 25px; border-bottom: 1px solid #E5E7EB; display: flex; justify-content: space-between; align-items: center;">
                <h2 style="font-size: 22px; color: #111827; margin: 0; display: flex; align-items: center; gap: 8px;">
                    <img src="../icons/calendar-check-solid-full.svg" alt="calendar-check" width="18" style="filter: brightness(0) saturate(100%) invert(41%) sepia(96%) saturate(1636%) hue-rotate(346deg) brightness(97%) contrast(93%);"> 
                    Book Add-on Service
                </h2>
                <button onclick="closeBookingModal()" style="background: none; border: none; font-size: 24px; 
                    color: #6B7280; cursor: pointer; padding: 0; width: 30px; height: 30px;">&times;</button>
            </div>

            <form action="../handlers/addon_service_handler.php" method="POST" style="padding: 25px;">
                <div style="margin-bottom: 20px;">
                    <label style="display: block; margin-bottom: 8px; color: #374151; font-weight: 600;">Select Member
                        *</label>
                    <select name="member_id" required style="width: 100%; padding: 12px; border: 1px solid #D1D5DB; 
                        border-radius: 10px; font-size: 14px;">
                        <option value="" disabled selected>Choose a member</option>
                        <?php
                        $members_sql = "SELECT id, full_name, email FROM members ORDER BY full_name ASC";
                        $members_result = $conn->query($members_sql);
                        if ($members_result && $members_result->num_rows > 0) {
                            while ($member = $members_result->fetch_assoc()) {
                                echo '<option value="' . $member['id'] . '" data-email="' . htmlspecialchars($member['email']) . '">'
                                    . htmlspecialchars($member['full_name']) . '</option>';
                            }
                        }
                        ?>
                    </select>
                </div>

                <div style="margin-bottom: 20px;">
                    <label style="display: block; margin-bottom: 8px; color: #374151; font-weight: 600;">Service Type
                        *</label>
                    <select name="service_type" required style="width: 100%; padding: 12px; border: 1px solid #D1D5DB; 
                        border-radius: 10px; font-size: 14px;">
                        <option value="" disabled selected>Select service</option>
                        <option value="Physiotherapy Session">Physiotherapy Session</option>
                        <option value="Nutrition Consultation">Nutrition Consultation</option>
                        <option value="Blood Test">Blood Test</option>
                        <option value="Full Body Checkup">Full Body Checkup</option>
                        <option value="Emtional Health Checkup">Emotional Health Checkup</option>
                    </select>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-bottom: 20px;">
                    <div>
                        <label style="display: block; margin-bottom: 8px; color: #374151; font-weight: 600;">Date
                            *</label>
                        <input type="date" name="scheduled_date" required min="<?php echo date('Y-m-d'); ?>"
                            style="width: 100%; padding: 12px; border: 1px solid #D1D5DB; border-radius: 10px; font-size: 14px;">
                    </div>
                    <div>
                        <label style="display: block; margin-bottom: 8px; color: #374151; font-weight: 600;">Time
                            *</label>
                        <input type="time" name="scheduled_time" required
                            style="width: 100%; padding: 12px; border: 1px solid #D1D5DB; border-radius: 10px; font-size: 14px;">
                    </div>
                </div>

                <div style="margin-bottom: 20px;">
                    <label style="display: block; margin-bottom: 8px; color: #374151; font-weight: 600;">Price
                        (₹)</label>
                    <input type="number" name="price" placeholder="e.g., 500" step="0.01"
                        style="width: 100%; padding: 12px; border: 1px solid #D1D5DB; border-radius: 10px; font-size: 14px;">
                </div>

                <div style="margin-bottom: 20px;">
                    <label style="display: block; margin-bottom: 8px; color: #374151; font-weight: 600;">Notes</label>
                    <textarea name="notes" rows="3" placeholder="Any special instructions or requirements"
                        style="width: 100%; padding: 12px; border: 1px solid #D1D5DB; border-radius: 10px; font-size: 14px; resize: vertical;">
                    </textarea>
                </div>

                <div style="display: flex; gap: 10px; justify-content: flex-end;">
                    <button type="button" onclick="closeBookingModal()" style="padding: 12px 24px; background: #F3F4F6; 
                        color: #374151; border: none; border-radius: 10px; font-weight: 600; cursor: pointer;">
                        Cancel
                    </button>
                    <button type="submit" style="padding: 12px 24px; background: #F25C2A; color: white; border: none; 
                        border-radius: 10px; font-weight: 600; cursor: pointer; display: flex; align-items: center; gap: 6px;">
                        <img src="../icons/check-solid-full.svg" alt="check" width="14" style="filter: brightness(0) invert(1);"> Confirm Booking
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <div id="deletePlanModal"
        style="display:none; position:fixed; inset:0; z-index:10000; background:rgba(0,0,0,0.5); backdrop-filter:blur(4px); justify-content:center; align-items:center;">
        <div
            style="background:#fff; border-radius:20px; padding:36px; text-align:center; max-width:380px; width:90%; box-shadow:0 24px 60px rgba(0,0,0,0.2); animation:planPopIn 0.3s cubic-bezier(.34,1.56,.64,1) both;">
            <div
                style="width:68px;height:68px;border-radius:50%;background:#FEE2E2;display:flex;align-items:center;justify-content:center;margin:0 auto 18px;">
                <i class="fa-solid fa-triangle-exclamation" style="font-size:28px;color:#DC2626;"></i>
            </div>
            <h2 style="margin:0 0 8px;font-size:20px;font-weight:800;color:#111827;">Delete Membership Plan?</h2>
            <p id="deletePlanName" style="margin:0 0 6px;font-size:15px;font-weight:700;color:#F25C2A;"></p>
            <p style="margin:0 0 28px;font-size:13.5px;color:#6B7280;line-height:1.6;">This will permanently delete this
                plan. Members currently on this plan will not be affected, but no new members can be assigned to it.</p>
            <div style="display:flex;gap:12px;justify-content:center;">
                <button onclick="closeDeleteModal()"
                    style="padding:11px 26px;border-radius:10px;border:1px solid #D1D5DB;background:#fff;color:#374151;font-size:14px;font-weight:600;cursor:pointer;">Cancel</button>
                <a id="deletePlanConfirmBtn" href="#"
                    style="padding:11px 26px;border-radius:10px;border:none;background:#DC2626;color:#fff;font-size:14px;font-weight:700;cursor:pointer;text-decoration:none;display:inline-flex;align-items:center;gap:7px;">
                    <img src="../icons/trash-can-solid-full.svg" alt="trash-can" width="14" style="filter: brightness(0) invert(1);"> Yes, Delete
                </a>
            </div>
        </div>
    </div>


    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const body = document.body;
            document.getElementById('toggleBtn')?.addEventListener('click', () => body.classList.toggle('collapsed'));
            document.getElementById('mobileToggle')?.addEventListener('click', () => body.classList.toggle('sidebar-open'));
        });

        // Tab switching function
        function switchTab(tab) {
            const plansTab = document.getElementById('plansTab');
            const servicesTab = document.getElementById('servicesTab');
            const plansContent = document.getElementById('plansContent');
            const servicesContent = document.getElementById('servicesContent');
            const plansTabIcon = document.getElementById('plansTabIcon');
            const servicesTabIcon = document.getElementById('servicesTabIcon');

            // CSS filters to dynamically match the text colors (#F25C2A active, #6B7280 inactive)
            const activeFilter = 'invert(49%) sepia(87%) saturate(2206%) hue-rotate(344deg) brightness(100%) contrast(92%)';
            const inactiveFilter = 'invert(49%) sepia(8%) saturate(909%) hue-rotate(182deg) brightness(94%) contrast(90%)';

            if (tab === 'plans') {
                plansTab.style.borderBottomColor = '#F25C2A';
                plansTab.style.color = '#F25C2A';
                plansTabIcon.style.filter = activeFilter;

                servicesTab.style.borderBottomColor = 'transparent';
                servicesTab.style.color = '#6B7280';
                servicesTabIcon.style.filter = inactiveFilter;

                plansContent.style.display = 'grid';
                servicesContent.style.display = 'none';
            } else {
                servicesTab.style.borderBottomColor = '#F25C2A';
                servicesTab.style.color = '#F25C2A';
                servicesTabIcon.style.filter = activeFilter;

                plansTab.style.borderBottomColor = 'transparent';
                plansTab.style.color = '#6B7280';
                plansTabIcon.style.filter = inactiveFilter;

                servicesContent.style.display = 'block';
                plansContent.style.display = 'none';
            }
        }

        // Modal functions
        function openBookingModal() {
            document.getElementById('bookingModal').style.display = 'flex';
        }

        function closeBookingModal() {
            document.getElementById('bookingModal').style.display = 'none';
        }

        // Delete Plan modal
        function openDeleteModal(planId, planName) {
            document.getElementById('deletePlanName').textContent = planName;
            document.getElementById('deletePlanConfirmBtn').href = '../handlers/membership_handler.php?action=delete&id=' + planId;
            document.getElementById('deletePlanModal').style.display = 'flex';
        }

        function closeDeleteModal() {
            document.getElementById('deletePlanModal').style.display = 'none';
        }

        document.getElementById('deletePlanModal')?.addEventListener('click', function (e) {
            if (e.target === this) closeDeleteModal();
        });

        // Close modal on outside click
        document.getElementById('bookingModal')?.addEventListener('click', function (e) {
            if (e.target === this) {
                closeBookingModal();
            }
        });
    </script>
</body>


</html>