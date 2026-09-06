<?php
require_once __DIR__ . '/../auth/auth_check.php';
require_role(['admin', 'trainer']);
require_once __DIR__ . '/../config.php';


// === PERMANENT DELETE LOGIC ===
if (isset($_GET['delete_id'])) {
    $del_id = intval($_GET['delete_id']);
    $stmt_del = $conn->prepare("DELETE FROM members WHERE id = ? AND status = 'recycled'");
    $stmt_del->bind_param("i", $del_id);
    if ($stmt_del->execute()) {
        header("Location: RecycleBin.php?msg=deleted");
        exit;
    }
}

// === SEARCH ===
$search_val = isset($_GET['search']) ? trim($_GET['search']) : '';

$sql = "SELECT id, full_name, email, phone_number, gender, created_at, inactive_date
        FROM members 
        WHERE status = 'recycled'";

$types = "";
$params = [];

if (!empty($search_val)) {
    $search_term = "%" . $search_val . "%";
    $sql .= " AND (full_name LIKE ? OR email LIKE ? OR phone_number LIKE ?)";
    $types .= "sss";
    $params[] = $search_term;
    $params[] = $search_term;
    $params[] = $search_term;
}

$sql .= " ORDER BY inactive_date DESC";

$stmt = $conn->prepare($sql);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" size="16x16" href="../icons/favicon-dark-logo.png" type="image/png">
    <title>JOF INDIA | Recycle Bin</title>
    <!-- <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"> -->
    <link rel="stylesheet" href="../static/root.css">

    
</head>

<body class="page-members">

    <button class="mobile-toggle" id="mobileToggle"><img src="../icons/bars-solid-full.svg" class="fa-solid fa-bars"></button>
    <button class="toggle-sidebar-btn" id="toggleBtn"><img src="../icons/chevron-left-solid-full.svg" class="fa-solid fa-chevron-left"></button>

    <div class="dashboard-container">

        <?php include 'sidebar.php'; ?>

        <main class="main-content">

            <header class="header-banner">
                <div class="header-text">
                    <h1>Recycle Bin</h1>
                    <p>Members moved here after 15 days of inactivity. Restore or permanently delete.</p>
                </div>
            </header>

            <div class="toolbar">
                <form id="searchForm" method="GET" action="RecycleBin.php" class="search-group w-full flex-item-center">
                    <div class="search-bar">
                        <img src="../icons/magnifying-glass-solid-full.svg" class="fa-solid fa-magnifying-glass">
                        <input type="text" name="search" placeholder="Search recycled members..."
                            value="<?= htmlspecialchars($search_val) ?>">
                    </div>
                    <button type="submit" class="btn-go ml-10">Go</button>
                </form>
            </div>

            <div class="table-card">
                <table class="styled-table">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Email</th>
                            <th>Phone</th>
                            <th>Recycled Since</th>
                            <th style="text-align:center;">Actions</th>
                        </tr>
                    </thead>

                    <tbody>
                        <?php if ($result->num_rows > 0): ?>
                            <?php while ($row = $result->fetch_assoc()): ?>
                                <tr>
                                    <td data-label="Name" class="nowrap">
                                        <strong class="v-middle">
                                            <?= htmlspecialchars($row['full_name']) ?>
                                        </strong>
                                        <span class="recycled-badge ml-6 v-middle">RECYCLED</span>
                                    </td>
                                    <td data-label="Email" class="text-muted">
                                        <?= htmlspecialchars($row['email'] ?? '-') ?>
                                    </td>
                                    <td data-label="Phone">
                                        <?= htmlspecialchars($row['phone_number'] ?? '-') ?>
                                    </td>
                                    <td data-label="Recycled Since">
                                        <?= $row['inactive_date'] ? date("d M Y", strtotime($row['inactive_date'])) : '-' ?>
                                    </td>
                                    <td data-label="Actions">
                                        <div class="action-cell">
                                            <a href="open_payment.php?member_id=<?= $row['id'] ?>" class="btn-activate"
                                                title="Activate & Add Payment">
                                                <img src="../icons/indian-rupee-sign-solid-full.svg" class="fa-solid fa-indian-rupee-sign">
                                            </a>

                                            <a href="person_info.php?id=<?= $row['id'] ?>" class="btn-view"
                                                title="View Profile">
                                                <img src="../icons/eye-solid-full.svg" class="fa-solid fa-eye">
                                            </a>

                                            <button onclick="confirmDelete('RecycleBin.php?delete_id=<?= $row['id'] ?>')"
                                                class="btn-delete border-none cursor-pointer"
                                                title="Permanently Delete">
                                                <img src="../icons/trash-solid-full.svg" class="fa-solid fa-trash">
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="5" class="table-empty-container">
                                    <img src="../icons/recycle-solid-full.svg" class="fa-solid fa-recycle table-empty-icon">
                                    Recycle bin is empty. Members appear here after 15 days of inactivity.
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

        </main>
    </div>

    <script>
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
    </script>

    <!-- ===== CONFIRM DELETE MODAL ===== -->
    <div id="confirmModal" class="modal-overlay-generic">
        <div class="modal-content-generic">
            <div class="modal-icon-circle bg-danger-light">
                <img src="../icons/triangle-exclamation-solid-full.svg" class="fa-solid fa-triangle-exclamation text-danger header-icon-orange" style="font-size: 26px;">
            </div>
            <h2 class="modal-title-custom">Permanently Delete?</h2>
            <p class="modal-text-custom">This member and all their records will be permanently deleted. This action cannot be undone.</p>
            <div class="modal-btn-group">
                <button onclick="document.getElementById('confirmModal').style.display='none'" class="btn-outline">Cancel</button>
                <button id="confirmDeleteBtn" class="btn-danger-modal">
                    <img src="../icons/trash-solid-full.svg" class="fa-solid fa-trash mr-8"> Yes, Delete
                </button>
            </div>
        </div>
    </div>

    <!-- ===== SUCCESS MODAL ===== -->
    <div id="successModal" class="modal-overlay-generic">
        <div class="modal-content-generic">
            <div class="modal-icon-circle bg-success-gradient">
                <img src="../icons/check-solid-full.svg" class="fa-solid fa-check icon-white" style="font-size: 30px;">
            </div>
            <h2 id="successTitle" class="modal-title-custom">Success!</h2>
            <p id="successMsg" class="modal-text-custom">Action completed.</p>
            <div class="timer-bar-container">
                <div id="timerBar" class="timer-bar">
                </div>
            </div>
        </div>
    </div>

    <script>
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

    

    <script>
        function confirmDelete(url) {
            const modal = document.getElementById('confirmModal');
            modal.style.display = 'flex';
            document.getElementById('confirmDeleteBtn').onclick = () => {
                modal.style.display = 'none';
                window.location.href = url;
            };
        }

        <?php if (isset($_GET['msg']) && $_GET['msg'] === 'deleted'): ?>
                window.addEventListener('DOMContentLoaded', () => {
                    document.getElementById('successTitle').textContent = 'Permanently Deleted!';
                    document.getElementById('successMsg').textContent = 'The member has been permanently removed.';
                    const modal = document.getElementById('successModal');
                    modal.style.display = 'flex';
                    const bar = document.getElementById('timerBar');
                    bar.style.transition = 'none'; bar.style.width = '100%';
                    requestAnimationFrame(() => requestAnimationFrame(() => {
                        bar.style.transition = 'width 2s linear'; bar.style.width = '0%';
                    }));
                    setTimeout(() => { modal.style.display = 'none'; }, 2000);
                });
        <?php endif; ?>

    </script>
</body>

</html>