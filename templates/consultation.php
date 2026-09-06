<?php
require_once __DIR__ . '/../auth/auth_check.php';
require_role(['admin', 'trainer']);
require_once __DIR__ . '/../config.php';

// Fetch only members who opted for Personal Training AND do not already have an active/unexhausted PT package
$members = [];
$mem_sql = "
    SELECT id, full_name, email 
    FROM members 
    WHERE personal_training = 1 
    AND id NOT IN (
        SELECT member_id 
        FROM personal_training 
        WHERE status = 'active' AND sessions_used < total_sessions
    )
    ORDER BY full_name ASC
";
$mem_res = mysqli_query($conn, $mem_sql);
while ($row = mysqli_fetch_assoc($mem_res)) {
    $members[] = $row;
}

// Fetch Trainers (no hardcoded rates — admin enters price)
$trainers = [];
$t_res = mysqli_query($conn, "SELECT id, full_name, specialization FROM trainers ORDER BY full_name ASC");
while ($row = mysqli_fetch_assoc($t_res)) {
    $trainers[] = $row;
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" size="16x16" href="../icons/favicon-dark-logo.png" type="image/png">
    <title>JOF India | Book Consultation</title>
    <link rel="stylesheet" href="../static/root.css">

    <style>
        img[class*="fa-"],
        svg.replaced-svg {
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
    </style>
</head>

<body class="page-add_member">

    <div class="container">

        <div class="card-header">
            <div class="brand-area">
                <img src="../icons/logo-dark(1).png" class="brand-logo" alt="JOF">
                <div class="brand-text">
                    <h2>Book a Consultation</h2>
                    <p>Assign trainer sessions to PT members.</p>
                </div>
            </div>
            <a href="view_consultation.php" class="close-btn">
                <img src="../icons/xmark-solid-full.svg" alt="close">
            </a>
        </div>

        <form id="consultationForm" action="../handlers/consultation_handler.php" method="POST">
            <div class="card-body">

                <div class="form-grid">

                    <!-- Member Text Input -->
                    <div class="input-group full-width">
                        <label>Member Name *</label>
                        <div class="input-wrapper">
                            <input type="text" name="member_name" class="form-input" placeholder="e.g. Rohit Sharma"
                                required>
                            <img src="../icons/users-solid-full.svg" class="input-icon" alt="member">
                        </div>
                    </div>

                    <!-- Trainer Text Input -->
                    <div class="input-group full-width">
                        <label>Trainer Name *</label>
                        <div class="input-wrapper">
                            <input type="text" name="trainer_name" class="form-input" placeholder="e.g. Amit Verma"
                                required>
                            <img src="../icons/user-solid-full.svg" class="input-icon" alt="trainer">
                        </div>
                    </div>

                    <!-- Price per Session (Admin enters manually) -->
                    <div class="input-group full-width">
                        <label>Price per Session (₹) *</label>
                        <div class="input-wrapper">
                            <input type="number" name="price_per_session" id="pricePerSession"
                                class="form-input has-prefix" placeholder="2500" min="0" step="0.01"
                                oninput="calculatePrice()" required>
                            <span class="input-prefix">₹</span>
                        </div>
                    </div>

                    <!-- Payment Method -->
                    <div class="input-group full-width">
                        <label>Payment Method *</label>
                        <div class="radio-group" style="margin-top: 10px;">
                            <label class="radio-option">
                                <input type="radio" name="payment_method" value="Cash" required> Cash
                            </label>
                            <label class="radio-option">
                                <input type="radio" name="payment_method" value="UPI" required> UPI
                            </label>
                        </div>
                    </div>

                    <!-- Pricing Summary -->
                    <div class="full-width">
                        <label
                            style="font-size:13px; font-weight:600; color:var(--text-dark); margin-bottom:8px; display:block;">Pricing
                            Summary</label>
                        <div class="price-summary">
                            <div class="price-row">
                                <span>Base Amount</span>
                                <span class="amount">₹<span id="basePrice">0.00</span></span>
                            </div>
                            <div class="price-row total">
                                <span>Total Payable</span>
                                <span>₹<span id="totalAmount">0.00</span></span>
                            </div>
                        </div>
                    </div>

                </div>

                <!-- Hidden fields for handler -->
                <input type="hidden" name="base_price" id="hiddenBasePrice">
                <input type="hidden" name="total_amount" id="hiddenTotalAmount">

            </div>

            <div class="card-footer">
                <a href="view_consultation.php" class="btn btn-secondary">Cancel</a>
                <button type="submit" class="btn btn-primary">
                    Confirm & Generate Invoice →
                </button>
            </div>
        </form>

    </div>

    <!-- Success Modal (hidden by default, shown by JS after successful submission) -->
    <div class="modal-overlay" id="successModal" style="display:none;">
        <div class="modal-card">
            <button class="close-modal-btn" onclick="redirectToConsultations()"><img src="../icons/xmark-solid-full.svg"
                    class="fas fa-times"></button>
            <h2 style="color:#111827; margin-bottom:10px;">Booking Complete!</h2>
            <p style="color:#6B7280; margin-bottom:15px;">Consultation booked successfully.</p>
            <p style="color:#F25C2A; font-weight:600; font-size:14px; margin-bottom:20px;"><img
                    src="../icons/envelope-solid-full.svg" class="fas fa-envelope"> Invoice Generated Kindly check Downloads</p>
            <button onclick="redirectToConsultations()" class="btn-success">View Consultations <img
                    src="../icons/arrow-right-solid-full.svg" class="fas fa-arrow-right"></button>
        </div>
    </div>

    <style>
        #successModal.active {
            display: flex !important;
        }
    </style>
    <script>
        function calculatePrice() {
            const rate = parseFloat(document.getElementById('pricePerSession').value || 0);

            // Check which payment method is selected
            const paymentMethod = document.querySelector('input[name="payment_method"]:checked');
            const methodValue = paymentMethod ? paymentMethod.value : null;

            const base = rate;
            const total = base;

            // Update UI
            document.getElementById('basePrice').innerText = base.toFixed(2);
            document.getElementById('totalAmount').innerText = total.toFixed(2);

            // Update Hidden Fields
            document.getElementById('hiddenBasePrice').value = base.toFixed(2);
            document.getElementById('hiddenTotalAmount').value = total.toFixed(2);
        }

        // Add event listeners to radio buttons to recalculate when toggled
        document.querySelectorAll('input[name="payment_method"]').forEach(radio => {
            radio.addEventListener('change', calculatePrice);
        });

        function redirectToConsultations() {
            window.location.href = 'view_consultation.php';
        }

        // Handle Form Submission via AJAX
        document.getElementById('consultationForm').addEventListener('submit', function (e) {
            e.preventDefault(); // Prevent standard form submission

            const submitBtn = this.querySelector('button[type="submit"]');
            const originalBtnText = submitBtn.innerHTML;

            // Show loading state
            submitBtn.innerHTML = '<img src="../icons/circle-notch-solid-full.svg" class="fas fa-spinner fa-spin"> Processing...';
            submitBtn.disabled = true;

            const formData = new FormData(this);

            fetch(this.action, {
                method: 'POST',
                body: formData
            })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        // Trigger download of the PDF
                        const link = document.createElement('a');
                        link.href = 'data:application/pdf;base64,' + data.pdf_base64;
                        link.download = data.invoice_no + '.pdf';
                        document.body.appendChild(link);
                        link.click();
                        document.body.removeChild(link);

                        // Show Modal
                        document.getElementById('successModal').classList.add('active');
                    } else {
                        alert('Error: ' + data.message);
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('An unexpected error occurred while processing the request.');
                })
                .finally(() => {
                    // Restore button state
                    submitBtn.innerHTML = originalBtnText;
                    submitBtn.disabled = false;
                });
        });
    </script>

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            function replaceSVG() {
                var images = document.querySelectorAll('img.fa-solid, img.fa-regular, img[class*="fa-"]');
                images.forEach(function (img) {
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
        });
    </script>
</body>

</html>