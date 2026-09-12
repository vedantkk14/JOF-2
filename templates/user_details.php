<?php
require_once __DIR__ . '/../auth/auth_check.php';
require_role(['admin', 'trainer']);
require_once __DIR__ . '/../config.php';

$current_admin_id = (int) ($_SESSION['user_id'] ?? 0);
$valid_roles      = ['admin', 'trainer', 'counsellor', 'user'];

$uid = (int) ($_GET['id'] ?? 0);
if ($uid <= 0) {
    die("Invalid user");
}

$stmt = $conn->prepare("SELECT id, full_name, email, role, is_active, reset_token_hash, reset_token_expires_at FROM user_data WHERE id = ? LIMIT 1");
$stmt->bind_param('i', $uid);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();

if (!$user) {
    die("User not found");
}

$is_active = (int) $user['is_active'] === 1;
$is_self   = $uid === $current_admin_id;
$role      = strtolower($user['role']);
$role_cls  = in_array($role, $valid_roles, true) ? $role : 'user';

$has_reset = !empty($user['reset_token_hash']);
$reset_live = $has_reset && !empty($user['reset_token_expires_at']) && strtotime($user['reset_token_expires_at']) > time();
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" size="16x16" href="../icons/favicon-dark-logo.png" type="image/png">
    <title>User Profile | JOF India</title>
    <link rel="stylesheet" href="../static/root.css">
    <style>
        .role-chip {
            display: inline-block; padding: 4px 12px; border-radius: 999px;
            font-size: 12px; font-weight: 700; letter-spacing: .02em;
        }
        .role-chip.role-admin      { background: #FEE2E2; color: #B91C1C; }
        .role-chip.role-trainer    { background: #EDE9FE; color: #6D28D9; }
        .role-chip.role-counsellor { background: #DBEAFE; color: #1D4ED8; }
        .role-chip.role-user       { background: #DCFCE7; color: #15803D; }

        .pill {
            display: inline-flex; align-items: center; gap: 7px; padding: 5px 14px;
            border-radius: 999px; font-size: 13px; font-weight: 600;
        }
        .pill::before { content: ''; width: 8px; height: 8px; border-radius: 50%; }
        .pill.on  { background: #DCFCE7; color: #15803D; }
        .pill.on::before  { background: #22C55E; }
        .pill.off { background: #F3F4F6; color: #6B7280; }
        .pill.off::before { background: #9CA3AF; }

        .danger-btn {
            display: inline-flex; align-items: center; gap: 8px; padding: 10px 18px;
            border: none; border-radius: 12px; font-size: 14px; font-weight: 600;
            cursor: pointer; transition: all .2s;
        }
        .danger-btn img { width: 15px; height: 15px; }
        .danger-btn.restrict { background: #FEF3C7; color: #B45309; }
        .danger-btn.restrict:hover { background: #FDE68A; }
        .danger-btn.unblock  { background: #DCFCE7; color: #15803D; }
        .danger-btn.unblock:hover { background: #BBF7D0; }
        .danger-btn.remove   { background: #FEE2E2; color: #B91C1C; }
        .danger-btn.remove:hover { background: #FECACA; }

        .modal-overlay {
            position: fixed; inset: 0; background: rgba(0,0,0,.6); backdrop-filter: blur(5px);
            z-index: 100000; display: none; align-items: center; justify-content: center;
            opacity: 0; transition: opacity .3s ease;
        }
        .modal-overlay.active { display: flex; opacity: 1; }
        .modal-card {
            background: #fff; padding: 30px; border-radius: 20px; max-width: 400px; width: 90%;
            text-align: center; transform: translateY(20px); transition: transform .3s ease;
        }
        .modal-overlay.active .modal-card { transform: translateY(0); }
    </style>
</head>

<body class="page-person_info page-modal-styles">

    <button class="mobile-toggle" id="mobileToggle"><img src="../icons/bars-solid-full.svg" class="fa-solid fa-bars"></button>
    <button class="toggle-sidebar-btn" id="toggleBtn"><img src="../icons/chevron-left-solid-full.svg" class="fa-solid fa-chevron-left"></button>

    <div class="dashboard-container">

        <?php include 'sidebar.php'; ?>

        <main class="main-content">

            <div class="profile-header">
                <div class="user-identity">
                    <img src="https://ui-avatars.com/api/?name=<?= urlencode($user['full_name']) ?>&background=random&color=fff"
                        class="profile-avatar" alt="Profile">
                    <div class="user-text">
                        <h1><?= htmlspecialchars($user['full_name']) ?></h1>
                        <p class="member-id">User ID: #<?= str_pad((string) $uid, 4, '0', STR_PAD_LEFT) ?></p>
                        <span class="role-chip role-<?= $role_cls ?>"><?= ucfirst($role) ?></span>
                        <span class="pill <?= $is_active ? 'on' : 'off' ?>"><?= $is_active ? 'Active' : 'Restricted' ?></span>
                    </div>
                </div>

                <div class="header-actions">
                    <a href="user_info.php" class="back-btn">
                        <img src="../icons/arrow-left-solid-full.svg" class="fa-solid fa-arrow-left"> Back to User Logs
                    </a>
                </div>
            </div>

            <div class="profile-grid">

                <div class="column-left">
                    <div class="info-card">
                        <h3><img src="../icons/id-card-solid-full.svg" class="fa-solid fa-id-card card-icon"> Account Details</h3>

                        <div class="details-grid">
                            <div class="detail-box">
                                <label>User ID</label>
                                <span>#<?= str_pad((string) $uid, 4, '0', STR_PAD_LEFT) ?></span>
                            </div>
                            <div class="detail-box">
                                <label>Role</label>
                                <span class="text-capitalize"><?= htmlspecialchars($role) ?></span>
                            </div>
                            <div class="detail-box">
                                <label>Login Status</label>
                                <span><?= $is_active ? 'Active' : 'Restricted' ?></span>
                            </div>
                            <div class="detail-box">
                                <label>Account Type</label>
                                <span><?= $is_self ? 'This is you' : 'Standard' ?></span>
                            </div>
                        </div>

                        <div class="detail-full">
                            <label>Email Address</label>
                            <p><?= htmlspecialchars($user['email']) ?></p>
                        </div>
                    </div>
                </div>

                <div class="column-right">
                    <div class="info-card">
                        <h3><img src="../icons/shield-halved-solid-full.svg" class="fa-solid fa-shield-halved card-icon"> Security</h3>

                        <div class="details-grid">
                            <div class="detail-box">
                                <label>Password Reset Requested</label>
                                <span><?= $has_reset ? 'Yes' : 'No' ?></span>
                            </div>
                            <div class="detail-box">
                                <label>Reset Link State</label>
                                <span><?= $reset_live ? 'Active' : ($has_reset ? 'Expired' : '—') ?></span>
                            </div>
                            <div class="detail-box">
                                <label>Reset Expires</label>
                                <span><?= !empty($user['reset_token_expires_at']) ? date('d M Y, H:i', strtotime($user['reset_token_expires_at'])) : '—' ?></span>
                            </div>
                        </div>
                    </div>

                    <div class="info-card mt-20">
                        <h3><img src="../icons/user-tag-solid-full.svg" class="fa-solid fa-user-tag card-icon"> Admin Actions</h3>

                        <?php if ($is_self): ?>
                            <p style="color:var(--text-muted); font-size:14px;">You cannot restrict or delete your own account.</p>
                        <?php else: ?>
                            <div style="display:flex; gap:12px; flex-wrap:wrap; margin-top:6px;">
                                <?php if ($is_active): ?>
                                    <button class="danger-btn restrict"
                                        onclick="udConfirm('restrict', 'user_info.php?toggle_id=<?= $uid ?>')">
                                        <img src="../icons/user-slash-solid-full.svg" class="fa-solid fa-user-slash"> Restrict Access
                                    </button>
                                <?php else: ?>
                                    <button class="danger-btn unblock"
                                        onclick="udConfirm('unblock', 'user_info.php?toggle_id=<?= $uid ?>')">
                                        <img src="../icons/user-check-solid-full.svg" class="fa-solid fa-user-check"> Re-activate Access
                                    </button>
                                <?php endif; ?>

                                <button class="danger-btn remove"
                                    onclick="udConfirm('delete', 'user_info.php?delete_id=<?= $uid ?>')">
                                    <img src="../icons/trash-solid-full.svg" class="fa-solid fa-trash"> Delete User
                                </button>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

            </div>

        </main>
    </div>

    <div id="udModal" class="modal-overlay">
        <div class="modal-card">
            <div class="modal-icon-box danger">
                <img src="../icons/triangle-exclamation-solid-full.svg" class="fa-solid fa-triangle-exclamation" style="font-size:26px;">
            </div>
            <h2 class="modal-title" id="udTitle">Are you sure?</h2>
            <p class="modal-desc" id="udDesc"></p>
            <div class="modal-actions">
                <button onclick="document.getElementById('udModal').classList.remove('active')" class="modal-btn modal-btn-secondary">Cancel</button>
                <button id="udOk" class="modal-btn modal-btn-danger">Confirm</button>
            </div>
        </div>
    </div>

    <script>
        const UD_COPY = {
            restrict: ['Restrict this user?', '<?= htmlspecialchars(addslashes($user['full_name'])) ?> will be blocked from logging in until re-activated.', 'Restrict'],
            unblock:  ['Re-activate this user?', '<?= htmlspecialchars(addslashes($user['full_name'])) ?> will be able to log in again.', 'Re-activate'],
            delete:   ['Move user to Recycle Bin?', '<?= htmlspecialchars(addslashes($user['full_name'])) ?> will lose access and move to the Recycle Bin. You can restore them later.', 'Move to Bin']
        };
        function udConfirm(type, url) {
            const c = UD_COPY[type];
            document.getElementById('udTitle').textContent = c[0];
            document.getElementById('udDesc').textContent = c[1];
            const ok = document.getElementById('udOk');
            ok.textContent = c[2];
            ok.onclick = () => { window.location.href = url; };
            document.getElementById('udModal').classList.add('active');
        }

        document.addEventListener('DOMContentLoaded', function () {
            const toggleBtn = document.getElementById('toggleBtn'), mobileToggle = document.getElementById('mobileToggle'), body = document.body;
            if (toggleBtn) toggleBtn.addEventListener('click', () => body.classList.toggle('collapsed'));
            if (mobileToggle) mobileToggle.addEventListener('click', () => body.classList.toggle('sidebar-open'));

            const dropdownItems = document.querySelectorAll('.nav-item-dropdown');
            dropdownItems.forEach(item => {
                const arrow = item.querySelector('.nav-arrow');
                if (arrow) arrow.addEventListener('click', (e) => {
                    e.preventDefault(); e.stopPropagation();
                    dropdownItems.forEach(o => { if (o !== item) o.classList.remove('active'); });
                    item.classList.toggle('active');
                });
            });
        });

        function replaceSVG() {
            var images = document.querySelectorAll('img.fa-solid, img.fa-regular, img[class*="fa-"]');
            images.forEach(function (img) {
                if (img.classList.contains('svg-replaced')) return;
                img.classList.add('svg-replaced');
                var imgID = img.id, imgClass = img.className, imgURL = img.src;
                if (!imgURL.endsWith('.svg')) return;
                fetch(imgURL).then(r => r.text()).then(text => {
                    var svg = new DOMParser().parseFromString(text, "text/xml").getElementsByTagName('svg')[0];
                    if (!svg) return;
                    if (imgID) svg.setAttribute('id', imgID);
                    if (imgClass) svg.setAttribute('class', imgClass + ' replaced-svg');
                    svg.removeAttribute('xmlns:a'); svg.removeAttribute('width'); svg.removeAttribute('height');
                    svg.querySelectorAll('path').forEach(p => p.setAttribute('fill', 'currentColor'));
                    img.parentNode.replaceChild(svg, img);
                }).catch(err => console.error('Error fetching SVG:', err));
            });
        }
        replaceSVG();
        new MutationObserver(function (muts) {
            if (muts.some(m => m.addedNodes.length)) replaceSVG();
        }).observe(document.body, { childList: true, subtree: true });
    </script>
</body>

</html>
