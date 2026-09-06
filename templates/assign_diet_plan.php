<?php
require_once __DIR__ . '/../auth/auth_check.php';
require_role(['admin', 'trainer']);
require_once __DIR__ . '/../config.php';

// 1. Get Plan ID if passed
$preselected_plan_id = isset($_GET['plan_id']) ? intval($_GET['plan_id']) : 0;
$selected_plan = null;

$plans_result = $conn->query("SELECT * FROM diet_plans ORDER BY created_at DESC");

if ($preselected_plan_id > 0) {
    $stmt = $conn->prepare("SELECT * FROM diet_plans WHERE id = ?");
    $stmt->bind_param("i", $preselected_plan_id);
    $stmt->execute();
    $selected_plan = $stmt->get_result()->fetch_assoc();
}

// 2. Fetch Members
$members_result = $conn->query("SELECT id, full_name, email FROM members WHERE status = 'active' ORDER BY full_name ASC");
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" size="16x16" href="../icons/favicon-dark-logo.png" type="image/png">
    <title>Assign Diet Plan | JOF INDIA</title>
    <link rel="stylesheet" href="../static/root.css">
    <style>
        .modal-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.6);
            backdrop-filter: blur(4px);
            display: none;
            justify-content: center;
            align-items: center;
            z-index: 1000;
            animation: fadeIn 0.3s ease-in-out;
        }

        .modal-card {
            background: white;
            padding: 40px;
            border-radius: 20px;
            text-align: center;
            width: 90%;
            max-width: 400px;
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1);
            transform: scale(0.9);
            animation: scaleUp 0.3s ease-in-out forwards;
        }

        .success-icon-container {
            width: 80px;
            height: 80px;
            background: #DEF7EC;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 20px;
        }

        .success-icon-container i {
            font-size: 40px;
            color: #03543F;
        }

        .btn-success {
            background-color: #F25C2A;
            color: white;
            padding: 12px 24px;
            border-radius: 10px;
            text-decoration: none;
            font-weight: 600;
            display: inline-block;
            width: 100%;
            border: none;
            cursor: pointer;
            transition: background 0.2s;
        }

        .btn-success:hover {
            background-color: #d64615;
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
            }

            to {
                opacity: 1;
            }
        }

        @keyframes fa-spin {
            0% {
                transform: rotate(0deg);
            }

            100% {
                transform: rotate(360deg);
            }
        }

        .fa-spin {
            animation: fa-spin 2s infinite linear;
        }

        .fa-spinner {
            display: inline-block;
        }

        .btn-success:disabled {
            opacity: 0.7;
            cursor: not-allowed;
        }

        /* Sidebar Toggle Arrow Fix */
        .toggle-sidebar-btn img {
            transition: transform 0.4s ease;
        }

        body.collapsed .toggle-sidebar-btn img {
            transform: rotate(180deg);
        }

        /* SweetAlert Customization */
        .swal2-popup {
            border-radius: 24px !important;
        }

        .swal2-backdrop-show {
            backdrop-filter: blur(8px) !important;
            background: rgba(0, 0, 0, 0.4) !important;
        }
    </style>
    <!-- SweetAlert2 -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
</head>

<body class="page-assign_diet_plan">

    <button class="mobile-toggle" id="mobileToggle"><img src="../icons/bars-solid-full.svg"
            style="width: 22px; height: 22px; filter: brightness(0) invert(1);"></button>
    <button class="toggle-sidebar-btn" id="toggleBtn"><img src="../icons/chevron-left-solid-full.svg"
            style="width: 14px; height: 14px; filter: brightness(0) invert(1);"></button>

    <div class="dashboard-container">

        <?php include 'sidebar.php'; ?>

        <main class="main-content">

            <header class="page-header" style="display:flex; justify-content:space-between; align-items:flex-start;">
                <div class="header-text">
                    <h1>Send Diet Plan</h1>
                    <p>Assign <b
                            id="headerPlanName"><?= $selected_plan ? htmlspecialchars($selected_plan['plan_name']) : 'a Plan' ?></b>
                        to your members.</p>
                </div>
                <div style="display:flex; flex-direction:column; align-items:flex-end; gap:10px; margin-top:4px;">
                    <a href="diet-plans.php" style="text-decoration:none; display:inline-flex; align-items:center; gap:8px; font-size:13px;
                               padding:8px 18px; border-radius:8px; background:#F25C2A; color:#fff;
                               font-weight:600; box-shadow:0 4px 12px rgba(242,92,42,0.3);"
                        onmouseover="this.style.background='#d94e20'" onmouseout="this.style.background='#F25C2A'">
                        <img src="../icons/arrow-left-solid-full.svg" alt="arrow-left" width="14"
                            style="filter: brightness(0) invert(1);"> Back to Plans
                    </a>
                </div>
            </header>

            <section class="content-grid">

                <div class="panel main-panel">
                    <div class="panel-header">
                        <h2>Select Members</h2>
                        <div class="search-box">
                            <img src="../icons/magnifying-glass-solid-full.svg" alt="magnifying-glass" width="16"
                                style="opacity:0.5;">
                            <input type="text" id="memberSearch" placeholder="Search member..."
                                onkeyup="filterMembers()">
                        </div>
                    </div>

                    <form action="#" class="assign-form">
                        <div class="member-list" id="memberList">
                            <?php if ($members_result->num_rows > 0): ?>
                                <?php while ($member = $members_result->fetch_assoc()):
                                    $parts = explode(" ", $member['full_name']);
                                    $initials = strtoupper(substr($parts[0], 0, 1));
                                    if (count($parts) > 1)
                                        $initials .= strtoupper(substr($parts[count($parts) - 1], 0, 1));
                                    $colors = ['color-1', 'color-2', 'color-3', 'color-4'];
                                    $bgClass = $colors[array_rand($colors)];
                                    ?>
                                    <label class="member-item">
                                        <input type="checkbox" name="member[]" value="<?= $member['id'] ?>">
                                        <div class="avatar <?= $bgClass ?>"><?= $initials ?></div>
                                        <div class="member-info">
                                            <span class="m-name"><?= htmlspecialchars($member['full_name']) ?></span>
                                            <span class="m-phone"
                                                style="font-size:12px; color:#6B7280;"><?= htmlspecialchars($member['email']) ?></span>
                                        </div>
                                    </label>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <p style="padding:20px; color:#888;">No members found.</p>
                            <?php endif; ?>
                        </div>

                        <div class="actions">
                            <a href="diet-plans.php" class="btn cancel-btn">Cancel</a>
                            <button type="submit" class="btn send-btn" id="sendPlanBtn">
                                <img src="../icons/envelope-solid-full.svg" alt="envelope" width="16"
                                    style="margin-right:5px; filter: brightness(0) invert(1);"> Send Email
                            </button>
                        </div>
                    </form>
                </div>

                <aside class="side-panel">
                    <div class="info-card plan-summary">
                        <div class="icon-box"><img src="../icons/clipboard-list-solid-full.svg" alt="clipboard-list"
                                width="16"
                                style="filter: brightness(0) saturate(100%) invert(41%) sepia(96%) saturate(1636%) hue-rotate(346deg) brightness(97%) contrast(93%);">
                        </div>
                        <div style="width: 100%;">
                            <h3>Selected Plan</h3>
                            <select id="dietPlanSelect" class="plan-dropdown"
                                style="width: 100%; padding: 8px; border-radius: 6px; border: 1px solid #E5E7EB; margin-top: 5px; font-family: 'Poppins', sans-serif;">
                                <?php
                                $plans_result->data_seek(0);
                                while ($p = $plans_result->fetch_assoc()):
                                    $selected = ($p['id'] == $preselected_plan_id) ? 'selected' : '';
                                    ?>
                                    <option value="<?= $p['id'] ?>"
                                        data-desc="<?= htmlspecialchars($p['goal']) . ' | ' . $p['duration'] . ' Weeks' ?>"
                                        <?= $selected ?>>
                                        <?= htmlspecialchars($p['plan_name']) ?>
                                    </option>
                                <?php endwhile; ?>
                            </select>
                            <p class="muted" id="planDescription" style="margin-top: 5px;">
                                <?= $selected_plan ? htmlspecialchars($selected_plan['goal']) : 'Select a plan above' ?>
                            </p>
                        </div>
                    </div>

                    <div class="info-card note-card">
                        <h3><img src="../icons/comment-dots-regular-full.svg" alt="comment-dots" width="16"
                                style="margin-right:8px; filter: brightness(0) saturate(100%) invert(16%) sepia(10%) saturate(500%) hue-rotate(180deg);">
                            Add
                            Personal Note</h3>
                        <textarea class="note-box" placeholder="E.g. Focus on hydration this week..."></textarea>
                    </div>
                </aside>

            </section>

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

            // Sidebar Dropdown Arrows
            const dropdownItems = document.querySelectorAll('.nav-item-dropdown');
            dropdownItems.forEach(item => {
                const link = item.querySelector('.nav-link');
                const arrow = link ? link.querySelector('.nav-arrow') : null;
                if (arrow) {
                    arrow.addEventListener('click', function (e) {
                        e.preventDefault();
                        e.stopPropagation();
                        dropdownItems.forEach(other => { if (other !== item) other.classList.remove('active'); });
                        item.classList.toggle('active');
                    });
                }
            });

            // Plan Selection Logic
            const dietPlanSelect = document.getElementById('dietPlanSelect');
            const planDescription = document.getElementById('planDescription');
            const headerPlanName = document.getElementById('headerPlanName');

            if (dietPlanSelect) {
                dietPlanSelect.addEventListener('change', function () {
                    const selectedOption = dietPlanSelect.options[dietPlanSelect.selectedIndex];
                    const desc = selectedOption.getAttribute('data-desc') || 'Select a plan above';
                    const name = selectedOption.text.trim();

                    if (planDescription) planDescription.textContent = desc;
                    if (headerPlanName) headerPlanName.textContent = name;
                });
            }

            const assignForm = document.querySelector('.assign-form');

            if (assignForm) {
                assignForm.addEventListener('submit', function (e) {
                    e.preventDefault();

                    const planId = dietPlanSelect ? dietPlanSelect.value : 0;
                    const selectedMembers = document.querySelectorAll('input[name="member[]"]:checked');

                    if (selectedMembers.length === 0) {
                        Swal.fire('No Member Selected', 'Please select at least one member.', 'warning');
                        return;
                    }

                    const formData = new FormData();
                    formData.append('plan_id', planId);
                    selectedMembers.forEach(checkbox => formData.append('member[]', checkbox.value));

                    // UI Loading State
                    const btn = document.getElementById('sendPlanBtn');
                    const originalText = btn.innerHTML;
                    btn.innerHTML = '<img src="../icons/circle-notch-solid-full.svg" class="fa-spin" width="16" style="margin-right:8px; filter: brightness(0) invert(1);"> Sending...';
                    btn.disabled = true;

                    fetch('../handlers/assign_diet_plan_handler.php', {
                        method: 'POST',
                        body: formData
                    })
                        .then(response => response.json())
                        .then(data => {
                            if (data.success) {
                                // Reset form
                                selectedMembers.forEach(cb => cb.checked = false);

                                // Show SweetAlert Success
                                Swal.fire({
                                    title: 'Sent Successfully!',
                                    text: data.message || "The diet plan has been sent successfully!",
                                    icon: 'success',
                                    confirmButtonText: 'Done',
                                    confirmButtonColor: '#F25C2A',
                                    allowOutsideClick: false,
                                    backdrop: true
                                }).then((result) => {
                                    if (result.isConfirmed) {
                                        window.location.href = 'diet-plans.php';
                                    }
                                });
                            } else {
                                Swal.fire('Error', data.error || 'Failed to send emails.', 'error');
                            }
                        })
                        .catch(err => {
                            console.error(err);
                            Swal.fire('Error', 'An unexpected error occurred.', 'error');
                        })
                        .finally(() => {
                            // Restore button state
                            btn.innerHTML = originalText;
                            btn.disabled = false;
                        });
                });
            }
        });

        // Search Filter
        function filterMembers() {
            let input = document.getElementById('memberSearch').value.toLowerCase();
            let items = document.querySelectorAll('.member-item');
            items.forEach(item => {
                let name = item.querySelector('.m-name').innerText.toLowerCase();
                item.style.display = name.includes(input) ? "" : "none";
            });
        }
    </script>

</html>