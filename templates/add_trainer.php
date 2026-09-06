<?php
require_once __DIR__ . '/../auth/auth_check.php';
require_role(['admin', 'trainer']);
require_once __DIR__ . '/../config.php';

$success = "";
$error = "";

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $full_name = $_POST['full_name'] ?? '';
    $email = $_POST['email'] ?? '';
    $phone = $_POST['phone'] ?? '';
    $specialization = $_POST['specialization'] ?? '';
    $experience = $_POST['experience_years'] ?? 0;

    if (empty($full_name) || empty($email)) {
        $error = "Name and Email are required.";
    } else {
        // Insert into trainers table
        $sql = "INSERT INTO trainers (full_name, email, phone, specialization, experience_years) VALUES (?, ?, ?, ?, ?)";
        $stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_bind_param($stmt, "ssssi", $full_name, $email, $phone, $specialization, $experience);

        if (mysqli_stmt_execute($stmt)) {
            $success = "Trainer added successfully!";
        } else {
            $error = "Error: " . mysqli_error($conn);
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
    <title>Add Trainer | JOF INDIA</title>
    <link rel="stylesheet" href="../static/root.css">
</head>
<body class="page-pt_form">

    <div class="container">
        
        <div class="card-header">
            <div class="brand-area">
                <img src="../icons/logo-dark(1).png" class="brand-logo" alt="JOF">
                <div class="brand-text">
                    <h2>Add New Trainer</h2>
                    <p>Onboard a new fitness coach.</p>
                </div>
            </div>
            <a href="personal_training.php" class="close-btn">
                <img src="../icons/xmark-solid-full.svg" alt="close">
            </a>
        </div>

        <?php if ($success): ?>
            <div style="background:#d1fae5; color:#065f46; padding:15px; margin:20px; border-radius:8px; text-align:center;">
                <img src="../icons/check-circle-solid-full.svg" class="fa-solid fa-check-circle"> <?= $success ?>
            </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div style="background:#fee2e2; color:#b91c1c; padding:15px; margin:20px; border-radius:8px; text-align:center;">
                <img src="../icons/circle-exclamation-solid-full.svg" class="fa-solid fa-circle-exclamation"> <?= $error ?>
            </div>
        <?php endif; ?>

        <div class="card-body">
            <form method="POST" action="">

                <div class="input-group">
                    <label>Full Name</label>
                    <div class="input-wrapper">
                        <input type="text" name="full_name" class="form-input" placeholder="First Last" required>
                        <img src="../icons/user-tie-solid-full.svg" class="input-icon">
                    </div>
                </div>

                <div class="grid-2">
                    <div class="input-group">
                        <label>Email Address</label>
                        <div class="input-wrapper">
                            <input type="email" name="email" class="form-input" placeholder="Email address" required>
                            <img src="../icons/envelope-solid-full.svg" class="input-icon">
                        </div>
                    </div>
                    <div class="input-group">
                        <label>Phone Number</label>
                        <div class="input-wrapper">
                            <input type="text" name="phone" class="form-input" placeholder="Phone Number">
                            <img src="../icons/phone-solid-full.svg" class="input-icon">
                        </div>
                    </div>
                </div>

                <div class="grid-2">
                    <div class="input-group">
                        <label>Specialization</label>
                        <div class="input-wrapper">
                            <select name="specialization" class="form-input">
                                <option value="General Fitness">General Fitness</option>
                                <option value="Weight Loss">Weight Loss</option>
                                <option value="Muscle Building">Muscle Building</option>
                                <option value="Yoga">Yoga</option>
                                <option value="Crossfit">Crossfit</option>
                            </select>
                        </div>
                    </div>
                    <div class="input-group">
                        <label>Experience (Years)</label>
                        <div class="input-wrapper">
                            <input type="number" name="experience_years" class="form-input" placeholder="e.g. 5">
                        </div>
                    </div>
                </div>

                <div class="card-footer">
                    <a href="personal_training.php" class="btn btn-secondary">Back</a>
                    <button type="submit" class="btn btn-primary">Add Trainer</button>
                </div>

            </form>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
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
        });
    </script>
</body>
</html>