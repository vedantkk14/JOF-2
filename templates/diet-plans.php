<?php
require_once __DIR__ . '/../auth/auth_check.php';
require_role(['admin', 'trainer']);
require_once __DIR__ . '/../config.php';

// 2. Handle Deletion (All phases for a specific client)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_client'])) {
    $client_to_delete = trim($_POST['delete_client']);

    // We match the exact client name OR anything that starts with "ClientName - "
    $exact_name = $client_to_delete;
    $like_name = $client_to_delete . " - %";

    $del_sql = "DELETE FROM diet_plans WHERE plan_name = ? OR plan_name LIKE ?";
    $del_stmt = $conn->prepare($del_sql);
    $del_stmt->bind_param("ss", $exact_name, $like_name);

    if ($del_stmt->execute()) {
        $success_msg = "All phases for " . htmlspecialchars($client_to_delete) . " have been deleted.";
    } else {
        $error_msg = "Failed to delete plans.";
    }
}

// 3. Fetch Plans
$sql = "SELECT * FROM diet_plans ORDER BY created_at DESC";
$result = $conn->query($sql);

$client_groups = [];

if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        // Extract Name: "Shubham - Week 1 & 2" -> "Shubham"
        $parts = explode(" - ", $row['plan_name']);
        $client_name = trim($parts[0]);
        $phase_name = isset($parts[1]) ? trim($parts[1]) : 'General Plan';

        // Store only the LATEST plan for the card
        if (!isset($client_groups[$client_name])) {
            $client_groups[$client_name] = [
                'latest_data' => $row,
                'current_phase' => $phase_name,
                'history_count' => 1
            ];
        } else {
            $client_groups[$client_name]['history_count']++;
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" size="16x16" href="../icons/favicon-dark-logo.png" type="image/png">
    <title>JOF INDIA | Diet Plans</title>
    <link rel="stylesheet" href="../static/root.css">
    <style>
        .diet-card-header-inner {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .btn-trash {
            background: rgba(239, 68, 68, 0.1);
            border: 1px solid rgba(239, 68, 68, 0.2);
            border-radius: 6px;
            cursor: pointer;
            padding: 6px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s;
        }

        .btn-trash:hover {
            background: rgba(239, 68, 68, 0.2);
        }

        /* --- Search Bar Styles --- */
        .search-box {
            position: relative;
            display: flex;
            align-items: center;
        }

        .search-box .search-icon {
            position: absolute;
            left: 14px;
            opacity: 0.5;
            filter: invert(36%) sepia(10%) saturate(1469%) hue-rotate(183deg) brightness(98%) contrast(93%);
        }

        .search-input {
            padding: 10px 15px 10px 38px;
            border: 1px solid #E2E8F0;
            border-radius: 10px;
            font-size: 14px;
            width: 250px;
            outline: none;
            transition: all 0.2s;
            background: #fff;
        }

        .search-input:focus {
            border-color: #10B981;
            box-shadow: 0 0 0 3px rgba(16, 185, 129, 0.1);
        }

        /* Responsive search */
        @media (max-width: 768px) {
            .header-actions {
                flex-direction: column;
                align-items: flex-start;
                gap: 15px;
            }

            .search-input {
                width: 100%;
            }
        }

        /* --- Updated Modal Styles --- */
        .modal-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.4);
            z-index: 1000;
            align-items: center;
            justify-content: center;
            opacity: 0;
            transition: opacity 0.2s ease-in-out;
            backdrop-filter: blur(2px);
        }

        .modal-overlay.active {
            display: flex;
            opacity: 1;
        }

        .modal-card {
            background: #fff;
            padding: 40px 30px;
            border-radius: 20px;
            width: 90%;
            max-width: 360px;
            text-align: center;
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
            transform: translateY(20px);
            transition: transform 0.2s ease-in-out;
        }

        .modal-overlay.active .modal-card {
            transform: translateY(0);
        }

        .modal-icon {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 64px;
            height: 64px;
            background: #ffe4e6;
            border-radius: 50%;
            margin: 0 auto 20px auto;
        }

        .modal-title {
            margin: 0 0 12px 0;
            color: #111827;
            font-size: 22px;
            font-weight: 700;
        }

        .modal-desc {
            color: #6B7280;
            font-size: 15px;
            line-height: 1.5;
            margin: 0 0 28px 0;
        }

        .modal-actions {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }

        .btn-cancel {
            background: #ffffff;
            color: #374151;
            border: 1px solid #D1D5DB;
            padding: 12px 20px;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
            font-size: 15px;
            width: 100%;
            transition: background 0.2s;
        }

        .btn-cancel:hover {
            background: #F3F4F6;
        }

        .btn-confirm-delete {
            background: #DC2626;
            color: #ffffff;
            border: none;
            padding: 12px 20px;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
            font-size: 15px;
            width: 100%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            transition: background 0.2s;
        }

        .btn-confirm-delete:hover {
            background: #B91C1C;
        }
    </style>
</head>

<body class="page-diet-plans">

    <button class="mobile-toggle" id="mobileToggle">
        <img src="../icons/bars-solid-full.svg" class="fa-solid fa-bars" style="filter: brightness(0) invert(1);">
    </button>

    <button class="toggle-sidebar-btn" id="toggleBtn">
        <img src="../icons/chevron-left-solid-full.svg" class="fa-solid fa-chevron-left"
            style="filter: brightness(0) invert(1);">
    </button>

    <div class="dashboard-container">

        <?php include 'sidebar.php'; ?>

        <main class="main-content">

            <header class="header-banner">
                <div class="header-text">
                    <h1>Diet Plans</h1>
                    <p>Manage nutrition schedules. Plans are grouped by client.</p>
                </div>
                <div class="header-actions" style="gap:15px; display:flex; align-items:center;">
                    <div class="search-box">
                        <img src="../icons/magnifying-glass-solid-full.svg" alt="search" width="16" class="search-icon">
                        <input type="text" id="dietSearchInput" placeholder="Search member..." class="search-input">
                    </div>
                    <a href="create_diet_plan.php" class="create-plan-btn sidebar-btn">
                        <img src="../icons/plus-solid-full.svg" alt="plus" width="20"> Create New Plan
                    </a>
                </div>
            </header>

            <?php if (isset($success_msg)): ?>
                <div style="background:#dcfce7; color:#166534; padding:12px 20px; margin-bottom:20px; border-radius:8px;">
                    <?= $success_msg ?>
                </div>
            <?php endif; ?>

            <?php if (isset($error_msg)): ?>
                <div style="background:#fee2e2; color:#b91c1c; padding:12px 20px; margin-bottom:20px; border-radius:8px;">
                    <?= $error_msg ?>
                </div>
            <?php endif; ?>

            <div class="diet-cards-grid">

                <?php if (!empty($client_groups)): ?>
                    <?php foreach ($client_groups as $client_name => $group):
                        $row = $group['latest_data'];
                        $history = $group['history_count'];

                        // Styling Logic
                        $goal = strtolower($row['goal']);
                        $theme = "green";
                        $icon = "fa-apple-whole";
                        $badge = "Wellness";

                        if (strpos($goal, 'loss') !== false) {
                            $theme = "orange";
                            $icon = "fa-fire";
                            $badge = "Fat Loss";
                        } elseif (strpos($goal, 'muscle') !== false) {
                            $theme = "purple";
                            $icon = "fa-dumbbell";
                            $badge = "Muscle Gain";
                        }
                        ?>

                        <div class="diet-card">
                            <div class="diet-card-header"
                                style="display: flex; justify-content: space-between; align-items: center;">
                                <div class="diet-card-header-inner">
                                    <div class="icon-square <?= $theme ?>-bg">
                                        <img src="../icons/dumbbell-solid-full.svg" style="color: var(--<?= $theme ?>-dark);">
                                    </div>
                                </div>

                                <button type="button" class="btn-trash open-delete-modal"
                                    data-client="<?= htmlspecialchars($client_name) ?>" title="Delete All Phases">
                                    <img src="../icons/trash-solid-full.svg" alt="Delete" width="12"
                                        style="filter: invert(36%) sepia(74%) saturate(2469%) hue-rotate(343deg) brightness(98%) contrast(93%);">
                                </button>
                            </div>

                            <div class="diet-card-body">
                                <h3><?= htmlspecialchars($client_name) ?></h3>

                                <p class="desc" style="color:#2D3748; font-weight:500;">
                                    Current: <span
                                        style="color:#F25C2A;"><?= htmlspecialchars($group['current_phase']) ?></span>
                                </p>

                                <p class="desc" style="font-size:12px; margin-top:4px;">
                                    Goal: <?= htmlspecialchars($row['goal']) ?> � Type: <?= ucfirst($row['diet_type']) ?>
                                </p>

                                <div class="diet-stats">
                                    <div class="stat">
                                        <img src="../icons/clock-solid-full.svg" alt="clock" width="20"
                                            style="margin-right: 5px;">
                                        <?= $row['duration'] ?> Wks
                                    </div>
                                    <div class="stat">
                                        <img src="../icons/layer-group-solid-full.svg" alt="layer-group" width="20"
                                            style="margin-right: 5px;">
                                        <?= $history ?> Phases
                                    </div>
                                </div>
                            </div>

                            <div class="diet-card-footer">
                                <a href="diet_plan_details.php?id=<?= $row['id'] ?>" class="btn-outline"
                                    style="text-decoration: none; display: inline-flex; justify-content: center; align-items: center; flex:1;">
                                    View Details
                                </a>

                                <a href="assign_diet_plan.php?plan_id=<?= $row['id'] ?>" class="btn-fill"
                                    style="text-decoration:none; display:inline-flex; justify-content:center; align-items:center; flex:1; margin-left:10px;">
                                    <img src="../icons/clipboard-list-solid-full.svg" alt="assign" width="16"
                                        style="margin-right:5px; filter: brightness(0) invert(1);"> Assign
                                </a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div
                        style="grid-column: 1/-1; text-align: center; padding: 60px; background: white; border-radius: 16px; box-shadow: 0 4px 20px rgba(0,0,0,0.05);">
                        <img src="../icons/carrot-solid-full.svg" alt="carrot" width="20"
                            style="margin-bottom: 20px; opacity: 0.5;">
                        <h3 style="color: #2D3748; margin-bottom: 10px;">No Plans Yet</h3>
                        <p style="color: #A0AEC0;">Create your first diet plan to get started!</p>
                        <a href="create_diet_plan.php" class="create-plan-btn sidebar-btn"
                            style="display: inline-block; margin-top: 20px; text-decoration: none; width: auto;">
                            <img src="../icons/plus-solid-full.svg" alt="plus" width="20"> Create New Plan
                        </a>
                    </div>
                <?php endif; ?>

            </div>

        </main>
    </div>

    <div class="modal-overlay" id="deleteModal">
        <div class="modal-card">
            <div class="modal-icon">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512" fill="#DC2626" width="28" height="28">
                    <path
                        d="M256 32c14.2 0 27.3 7.5 34.5 19.8l216 368c7.3 12.4 7.3 27.7 .2 40.1S486.3 480 472 480H40c-14.3 0-27.6-7.7-34.7-20.1s-7-27.8 .2-40.1l216-368C228.7 39.5 241.8 32 256 32zm0 128c-13.3 0-24 10.7-24 24V296c0 13.3 10.7 24 24 24s24-10.7 24-24V184c0-13.3-10.7-24-24-24zm32 224a32 32 0 1 0 -64 0 32 32 0 1 0 64 0z" />
                </svg>
            </div>
            <h3 class="modal-title">Delete Diet Plan?</h3>
            <p class="modal-desc">
                All phases for <strong id="modalClientName"></strong> will be permanently deleted. This action cannot be
                undone.
            </p>

            <form method="POST" action="" style="margin: 0;">
                <input type="hidden" name="delete_client" id="modalClientInput" value="">
                <div class="modal-actions">
                    <button type="button" class="btn-cancel" id="closeModalBtn">Cancel</button>
                    <button type="submit" class="btn-confirm-delete">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 448 512" fill="#ffffff" width="16"
                            height="16">
                            <path
                                d="M135.2 17.7L128 32H32C14.3 32 0 46.3 0 64S14.3 96 32 96H416c17.7 0 32-14.3 32-32s-14.3-32-32-32H320l-7.2-14.3C307.4 6.8 296.3 0 284.2 0H163.8c-12.1 0-23.2 6.8-28.6 17.7zM416 128H32L53.2 467c1.6 25.3 22.6 45 47.9 45H346.9c25.3 0 46.3-19.7 47.9-45L416 128z" />
                        </svg>
                        Delete Plan
                    </button>
                </div>
            </form>
        </div>
    </div>


    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const toggleBtn = document.getElementById('toggleBtn');
            const mobileToggle = document.getElementById('mobileToggle');
            const body = document.body;
            if (toggleBtn) toggleBtn.addEventListener('click', () => body.classList.toggle('collapsed'));
            if (mobileToggle) mobileToggle.addEventListener('click', () => body.classList.toggle('sidebar-open'));

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

            // Modal Logic
            const modal = document.getElementById('deleteModal');
            const closeModalBtn = document.getElementById('closeModalBtn');
            const modalClientName = document.getElementById('modalClientName');
            const modalClientInput = document.getElementById('modalClientInput');
            const deleteButtons = document.querySelectorAll('.open-delete-modal');

            // Open Modal
            deleteButtons.forEach(btn => {
                btn.addEventListener('click', function () {
                    const client = this.getAttribute('data-client');
                    modalClientName.textContent = client;
                    modalClientInput.value = client;
                    modal.classList.add('active');
                });
            });

            // Close Modal via Cancel Button
            if (closeModalBtn) {
                closeModalBtn.addEventListener('click', () => {
                    modal.classList.remove('active');
                });
            }

            // Close Modal by clicking outside the card
            modal.addEventListener('click', function (e) {
                if (e.target === modal) {
                    modal.classList.remove('active');
                }
            });

            // Diet Plan Search Filtering
            const searchInput = document.getElementById('dietSearchInput');
            const dietCards = document.querySelectorAll('.diet-card');

            if (searchInput) {
                searchInput.addEventListener('input', function (e) {
                    const searchTerm = e.target.value.toLowerCase().trim();

                    dietCards.forEach(card => {
                        const clientName = card.querySelector('.diet-card-body h3').textContent.toLowerCase();

                        // Toggle display based on match
                        if (clientName.includes(searchTerm)) {
                            card.style.display = 'block';
                        } else {
                            card.style.display = 'none';
                        }
                    });
                });
            }

        });
    </script>
</body>

</html>