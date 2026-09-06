<?php
// templates/print_combined_plan.php
session_start();
require '../config.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: ../index.php");
    exit;
}

if (!isset($_GET['id'])) {
    die("Error: No plan selected.");
}

$current_plan_id = intval($_GET['id']);

// 1. Fetch the CURRENT selected plan
$stmt = $conn->prepare("SELECT * FROM diet_plans WHERE id = ?");
$stmt->bind_param("i", $current_plan_id);
$stmt->execute();
$current_plan = $stmt->get_result()->fetch_assoc();

if (!$current_plan) {
    die("Plan not found.");
}

// 2. Extract Client Name
$name_parts = explode(" - ", $current_plan['plan_name']);
$client_name = trim($name_parts[0]);

// 3. Fetch HISTORY (Cumulative)
$history_sql = "SELECT * FROM diet_plans 
                WHERE plan_name LIKE ? 
                AND id <= ? 
                ORDER BY id ASC";

$search_name = $client_name . "%"; 
$stmt_hist = $conn->prepare($history_sql);
$stmt_hist->bind_param("si", $search_name, $current_plan_id);
$stmt_hist->execute();
$all_plans = $stmt_hist->get_result();

// Helper for bold headers formatting
function formatDietText($text) {
    if (empty($text)) return '<span class="text-muted">Not specified</span>';
    $text = htmlspecialchars($text);
    // Style headers like **Header** -> Bold Orange Text
    $text = preg_replace('/\*\*(.*?)\*\*/', '<div class="meal-subhead">$1</div>', $text);
    return nl2br($text);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <link rel="icon" size="16x16" href="../icons/favicon-dark-logo.png" type="image/png">
    <title>Lifestyle Chart | <?= htmlspecialchars($client_name) ?></title>
    <link rel="stylesheet" href="../static/root.css">
    
    <style>
        /* --- GLOBAL STYLES --- */
        :root {
            --brand-color: #F25C2A;
            --brand-dark: #d94e20;
            --text-dark: #1F2937;
            --text-light: #6B7280;
            --bg-gray: #F3F4F6;
        }

        body { 
            font-family: 'Poppins', sans-serif; 
            background: #555; 
            margin: 0; 
            padding: 40px 0; 
            color: var(--text-dark);
        }
        
        /* --- PAPER LAYOUT --- */
        .page-container {
            background: white; 
            width: 210mm; /* A4 Width */
            min-height: 297mm; /* A4 Height */
            margin: 0 auto 40px auto; 
            padding: 0;
            box-shadow: 0 10px 30px rgba(0,0,0,0.3); 
            position: relative;
            overflow: hidden; /* Clip watermark */
            page-break-after: always;
        }
        
        .page-container:last-child {
            page-break-after: auto;
        }

        /* --- WATERMARK BACKGROUND --- */
        .page-container::before {
            content: "";
            position: absolute;
            top: 50%;
            left: 50%;
            width: 500px; /* Logo Size */
            height: 500px;
            background-image: url('../icons/logo-dark(1).png'); /* Ensure this path is correct */
            background-repeat: no-repeat;
            background-position: center;
            background-size: contain;
            opacity: 0.04; /* Very faint */
            transform: translate(-50%, -50%) grayscale(100%);
            z-index: 0;
            pointer-events: none;
        }

        /* --- CONTENT LAYER (Above Watermark) --- */
        .content-layer {
            position: relative;
            z-index: 1;
            padding: 40px 50px;
            height: 100%;
            box-sizing: border-box;
        }

        /* --- HEADER --- */
        .brand-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 3px solid var(--brand-color);
            padding-bottom: 20px;
            margin-bottom: 30px;
        }

        .brand-logo img {
            height: 60px;
            width: auto;
        }

        .client-info {
            text-align: right;
        }

        .client-name {
            font-size: 22px;
            font-weight: 700;
            text-transform: uppercase;
            color: var(--text-dark);
            margin: 0;
        }

        .plan-phase {
            font-size: 14px;
            color: var(--brand-color);
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 1px;
            margin-top: 5px;
        }

        /* --- STATS BAR --- */
        .stats-bar {
            display: flex;
            background: var(--bg-gray);
            border-radius: 8px;
            padding: 12px 20px;
            margin-bottom: 30px;
            justify-content: space-between;
        }

        .stat-item {
            font-size: 13px;
            color: var(--text-dark);
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .stat-item i { color: var(--brand-color); }

        /* --- MEAL ROWS --- */
        .meal-row {
            display: flex;
            margin-bottom: 25px;
            border: 1px solid #E5E7EB;
            border-radius: 8px;
            overflow: hidden;
            background: rgba(255, 255, 255, 0.9); /* Slight white bg for readability */
        }

        .meal-label {
            width: 140px;
            background: #FAFAFA;
            border-right: 1px solid #E5E7EB;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            text-align: center;
            padding: 15px;
            flex-shrink: 0;
        }

        .meal-icon-circle {
            width: 40px;
            height: 40px;
            background: #FFF7ED;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 8px;
            color: var(--brand-color);
            font-size: 18px;
        }

        .meal-title {
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
            color: var(--text-light);
            letter-spacing: 0.5px;
        }

        .meal-content {
            padding: 20px;
            font-size: 14px;
            line-height: 1.7;
            color: #374151;
            flex-grow: 1;
        }

        /* --- TEXT FORMATTING --- */
        .meal-subhead {
            color: var(--brand-color);
            font-weight: 700;
            font-size: 13px;
            text-transform: uppercase;
            margin-top: 10px;
            margin-bottom: 5px;
            display: block;
            border-left: 3px solid var(--brand-color);
            padding-left: 8px;
        }

        .text-muted { color: #9CA3AF; font-style: italic; font-size: 12px; }

        /* --- FOOTER --- */
        .page-footer {
            margin-top: 40px;
            padding-top: 20px;
            border-top: 1px solid #E5E7EB;
            text-align: center;
            font-size: 11px;
            color: var(--text-light);
            display: flex;
            justify-content: space-between;
        }

        /* --- ACTION BAR (Screen Only) --- */
        .action-bar {
            position: fixed; top: 0; left: 0; right: 0;
            background: var(--text-dark); color: white;
            padding: 12px; text-align: center; z-index: 1000;
            box-shadow: 0 4px 12px rgba(0,0,0,0.2);
            display: flex; justify-content: center; align-items: center; gap: 20px;
        }
        
        .btn-print {
            background: var(--brand-color); border: none; color: white;
            padding: 8px 24px; border-radius: 6px; font-weight: 600;
            cursor: pointer; display: flex; align-items: center; gap: 8px;
            font-family: 'Poppins', sans-serif; transition: background 0.2s;
        }
        .btn-print:hover { background: var(--brand-dark); }

        /* --- PRINT MEDIA QUERIES --- */
        @media print {
            body { 
                background: white; 
                padding: 0; 
                margin: 0;
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;
            }
            .action-bar { display: none !important; }
            .page-container {
                box-shadow: none;
                margin: 0;
                width: 100%;
                min-height: 100vh;
                page-break-after: always;
            }
            /* Ensure watermark prints */
            .page-container::before {
                opacity: 0.05 !important;
                display: block !important;
            }
        }
    </style>
</head>
<body>

    <div class="action-bar">
        <span>Generating history for: <b><?= htmlspecialchars($client_name) ?></b></span>
        <button class="btn-print" onclick="window.print()">
            <i class="fa-solid fa-print"></i> Save as PDF
        </button>
    </div>

    <?php while ($plan = $all_plans->fetch_assoc()): 
        // Get Phase Name specifically
        $p_parts = explode(" - ", $plan['plan_name']);
        $phase_title = isset($p_parts[1]) ? $p_parts[1] : $plan['plan_name'];
    ?>
    
    <div class="page-container">
        <div class="content-layer">
            
            <div class="brand-header">
                <div class="brand-logo">
                    <img src="../icons/logo-dark(1).png" alt="JOF Fitness">
                </div>
                <div class="client-info">
                    <h1 class="client-name"><?= htmlspecialchars($client_name) ?></h1>
                    <div class="plan-phase">Lifestyle Chart • <?= htmlspecialchars($phase_title) ?></div>
                </div>
            </div>

            <div class="stats-bar">
                <div class="stat-item"><i class="fa-solid fa-bullseye"></i> Goal: <?= htmlspecialchars($plan['goal']) ?></div>
                <div class="stat-item"><i class="fa-regular fa-clock"></i> Duration: <?= $plan['duration'] ?> Weeks</div>
                <div class="stat-item"><i class="fa-solid fa-fire"></i> Calories: <?= $plan['calories'] ?> kcal</div>
                <div class="stat-item"><i class="fa-solid fa-user-tie"></i> Trainer: <?= htmlspecialchars($plan['trainer_name']) ?></div>
            </div>

            <div class="meal-schedule">
                
                <div class="meal-row">
                    <div class="meal-label">
                        <div class="meal-icon-circle"><i class="fa-regular fa-sun"></i></div>
                        <div class="meal-title">Morning Block</div>
                    </div>
                    <div class="meal-content">
                        <?= formatDietText($plan['breakfast']) ?>
                    </div>
                </div>

                <div class="meal-row">
                    <div class="meal-label">
                        <div class="meal-icon-circle"><i class="fa-solid fa-cloud-sun"></i></div>
                        <div class="meal-title">Lunch</div>
                    </div>
                    <div class="meal-content">
                        <?= formatDietText($plan['lunch']) ?>
                    </div>
                </div>

                <div class="meal-row">
                    <div class="meal-label">
                        <div class="meal-icon-circle"><i class="fa-solid fa-mug-hot"></i></div>
                        <div class="meal-title">Mid Meal</div>
                    </div>
                    <div class="meal-content">
                        <?= formatDietText($plan['snack']) ?>
                    </div>
                </div>

                <div class="meal-row">
                    <div class="meal-label">
                        <div class="meal-icon-circle"><i class="fa-solid fa-moon"></i></div>
                        <div class="meal-title">Night Block</div>
                    </div>
                    <div class="meal-content">
                        <?= formatDietText($plan['dinner']) ?>
                    </div>
                </div>

            </div>

            <div class="page-footer">
                <div>&copy; <?= date('Y') ?> JOF Fitness. All rights reserved.</div>
                <div><b>Trainer Contact:</b> <?= htmlspecialchars($plan['trainer_name']) ?></div>
            </div>

        </div>
    </div>

    <?php endwhile; ?>

</body>
</html>