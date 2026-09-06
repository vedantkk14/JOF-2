<?php
require_once __DIR__ . '/../auth/auth_check.php';
require_role(['admin', 'trainer']);
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <link rel="icon" size="16x16" href="../icons/favicon-dark-logo.png" type="image/png">
    <title>Create Membership Plan | JOF Fitness</title>
    <link rel="stylesheet" href="../static/root.css?v=<?php echo time(); ?>">
    <style>
        img[class*="fa-"], svg.replaced-svg {
            width: 1em;
            height: 1em;
            vertical-align: -0.125em;
        }
        .replaced-svg {
            display: inline-block;
        }
        .replaced-svg path {
            fill: currentColor;
        }
        /* Fix id-card icon specifically based on user feedback */
        .section-title img {
            vertical-align: middle;
            margin-right: 8px;
        }

    </style>
</head>

<body class="page-membership page-add_membership">
    <button class="mobile-toggle" id="mobileToggle"><img src="../icons/bars-solid-full.svg" class="fa-solid fa-bars"></button>
    <button class="toggle-sidebar-btn" id="toggleBtn"><img src="../icons/chevron-left-solid-full.svg" class="fa-solid fa-chevron-left"></button>

    <div class="dashboard-container">

        <?php include 'sidebar.php'; ?>

        <!-- MAIN CONTENT -->
        <main class="main-content">
            <header class="page-header">
                <div class="header-text">
                    <h1>Create Membership Plan</h1>
                    <p>Add a new pricing tier and benefits for your gym members.</p>
                </div>
                
                <a href="membership.php"
                    style="text-decoration:none; display:inline-flex; align-items:center; gap:8px; font-size:13px;
                           padding:8px 18px; border-radius:8px; background:#F25C2A; color:#fff;
                           font-weight:600; box-shadow:0 4px 12px rgba(242,92,42,0.3);"
                    onmouseover="this.style.background='#d94e20'" onmouseout="this.style.background='#F25C2A'">
                    <img src="../icons/arrow-left-solid-full.svg" class="fa-solid fa-arrow-left"> Back to Plans
                </a>
            </header>

            <div class="form-container">

                <form class="membership-form" id="membershipForm" action="../handlers/membership_handler.php"
                    method="POST">

                    <!-- Basic Info -->
                    <div class="form-section">
                        <h3 class="section-title"><img src="../icons/id-card-solid-full.svg" alt="id-card" width="16" style="filter: brightness(0) saturate(100%) invert(26%) sepia(89%) saturate(3061%) hue-rotate(349deg) brightness(89%) contrast(92%);">
                            Basic Information</h3>

                        <div class="input-group">
                            <label>Plan Name</label>
                            <div class="input-wrapper">
                                <input type="text" name="plan_name" class="form-input"
                                    placeholder="e.g. Gold Membership" required />
                                <img src="../icons/tag-solid-full.svg" alt="tag" class="input-icon" width="16" style="filter: brightness(0) saturate(100%) invert(41%) sepia(96%) saturate(1636%) hue-rotate(346deg) brightness(97%) contrast(93%);">
                            </div>
                        </div>

                        <div class="input-group">
                            <label>Description <span class="opt">(Optional)</span></label>
                            <div class="input-wrapper">
                                <textarea name="description" class="form-input" rows="2"
                                    placeholder="Describe the core benefits of this plan..."></textarea>
                                <img src="../icons/align-left-solid-full.svg" alt="align-left" class="input-icon top-icon" width="16" style="filter: brightness(0) saturate(100%) invert(40%) sepia(70%) saturate(2000%) hue-rotate(230deg) brightness(95%) contrast(90%);">
                            </div>
                        </div>
                    </div>

                    <!-- Pricing & Duration -->
                    <div class="form-section">
                        <h3 class="section-title"><img src="../icons/file-invoice-solid-full.svg"
                                alt="file-invoice-dollar" width="16" style="filter: brightness(0) saturate(100%) invert(26%) sepia(89%) saturate(3061%) hue-rotate(349deg) brightness(89%) contrast(92%);"> Pricing &amp; Duration</h3>

                        <div class="form-row">
                            <div class="input-group">
                                <label>Price (₹)</label>
                                <div class="input-wrapper">
                                    <input type="number" name="price" class="form-input" placeholder="4999" step="0.01"
                                        required />
                                    <img src="../icons/indian-rupee-sign-solid-full.svg" alt="rupee" class="input-icon" width="16" style="filter: brightness(0) saturate(100%) invert(55%) sepia(52%) saturate(600%) hue-rotate(110deg) brightness(95%) contrast(90%);">
                                </div>
                            </div>

                            <div class="input-group">
                                <label>Duration</label>
                                <div class="duration-box">
                                    <div class="input-wrapper">
                                        <input type="number" name="duration_value" class="form-input" placeholder="1"
                                            min="1" required />
                                        <img src="../icons/hourglass-half-solid-full.svg" alt="hourglass" class="input-icon" width="16" style="filter: brightness(0) saturate(100%) invert(40%) sepia(85%) saturate(1500%) hue-rotate(200deg) brightness(95%) contrast(90%);">
                                    </div>
                                    <div class="input-wrapper" style="flex:1.5;">
                                        <select name="duration_unit" class="form-input" required>
                                            <option value="Month">Month(s)</option>
                                            <option value="Year">Year(s)</option>
                                            <option value="Week">Week(s)</option>
                                        </select>
                                        <img src="../icons/calendar-day-solid-full.svg" alt="calendar-alt"
                                            class="input-icon" width="16">
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="input-group">
                            <label>Max Classes / Period <span class="opt">(Optional)</span></label>
                            <div class="input-wrapper">
                                <input type="text" name="max_classes" class="form-input"
                                    placeholder="Leave empty for unlimited access" />
                                <img src="../icons/dumbbell-solid-full.svg" alt="dumbbell" class="input-icon" width="16" style="filter: brightness(0) saturate(100%) invert(60%) sepia(70%) saturate(1200%) hue-rotate(10deg) brightness(100%) contrast(90%);">
                            </div>
                        </div>
                    </div>


                    <!-- Features -->
                    <div class="form-section" style="border-bottom:none;">
                        <h3 class="section-title"><img src="../icons/list-check-solid-full.svg" alt="list-check"
                                width="16" style="filter: brightness(0) saturate(100%) invert(26%) sepia(89%) saturate(3061%) hue-rotate(349deg) brightness(89%) contrast(92%);"> Features</h3>

                        <div class="input-group">
                            <label>Plan Features <span class="opt">(Optional)</span></label>
                            <p class="input-hint">Enter one feature per line to display as a bulleted list.</p>
                            <div class="input-wrapper">
                                <textarea name="features" class="form-input" rows="5"></textarea>
                            </div>
                        </div>
                    </div>

                </form>

                <div class="form-actions">
                    <a href="membership.php" class="btn cancel-btn"><img src="../icons/arrow-left-solid-full.svg"
                            alt="arrow-left" width="14" style="filter: brightness(0) saturate(100%) invert(16%) sepia(10%) saturate(500%) hue-rotate(180deg);"> Cancel</a>
                    <button type="submit" form="membershipForm" class="btn create-btn">
                        <img src="../icons/plus-solid-full.svg" alt="plus" width="16" style="filter: brightness(0) invert(1);"> Create Plan
                    </button>
                </div>

            </div>
        </main>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const body = document.body;
            document.getElementById('toggleBtn')?.addEventListener('click', () => body.classList.toggle('collapsed'));
            document.getElementById('mobileToggle')?.addEventListener('click', () => body.classList.toggle('sidebar-open'));

            // Sidebar dropdown arrows
            document.querySelectorAll('.nav-item-dropdown').forEach(item => {
                const arrow = item.querySelector('.nav-arrow');
                if (arrow) arrow.addEventListener('click', e => {
                    e.preventDefault(); e.stopPropagation();
                    document.querySelectorAll('.nav-item-dropdown').forEach(o => { if (o !== item) o.classList.remove('active'); });
                    item.classList.toggle('active');
                });
            });
        });
    </script>
    
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            function replaceSVG() {
                var images = document.querySelectorAll('img.fa-solid, img.fa-regular, img[class*="fa-"], img.nav-arrow');

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
        });
    </script>
</body>


</html>