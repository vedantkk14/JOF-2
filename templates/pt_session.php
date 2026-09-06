<?php
session_start();
// Enable error reporting to debug issues
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
require '../config.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: ../index.php");
    exit;
}

// Auto-expire members whose membership end_date has passed (mirrors members.php logic)
$conn->query("UPDATE members m
              JOIN (
                  SELECT member_id, MAX(end_date) AS latest_end
                  FROM member_payments
                  GROUP BY member_id
              ) latest_pay ON m.id = latest_pay.member_id
              SET m.status = 'inactive', m.inactive_date = NOW()
              WHERE m.status = 'active' AND latest_pay.latest_end < CURDATE()");

// Auto-expire PT sessions when membership expires
$conn->query("UPDATE personal_training pt
              JOIN members m ON pt.member_id = m.id
              SET pt.end_date = DATE_SUB(CURDATE(), INTERVAL 1 DAY)
              WHERE m.status = 'inactive' AND pt.end_date >= CURDATE()");


// FETCH MEMBERS — only those who appear on the personal_training.php page:
//   1. Have a PT package with total_sessions > 0
//   2. PT end_date has not passed (or all sessions completed)
//   3. Member status = active
//   4. Member has personal_training = 1
$sql = "SELECT DISTINCT m.id, m.full_name, pt.id as pt_id, pt.start_date
        FROM members m
        JOIN personal_training pt ON m.id = pt.member_id
        WHERE pt.total_sessions > 0
          AND (pt.end_date >= CURDATE()
               OR (SELECT COUNT(*) FROM pt_sessions ps WHERE ps.pt_id = pt.id AND ps.status = 'Completed') >= pt.total_sessions)
          AND m.status = 'active'
          AND m.personal_training = 1
        ORDER BY m.full_name ASC";

try {
    $members_result = mysqli_query($conn, $sql);
    $members = [];
    while ($row = mysqli_fetch_assoc($members_result)) {
        $members[] = $row;
    }
} catch (Exception $e) {
    die("Database Error: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" size="16x16" href="../icons/favicon-dark-logo.png" type="image/png">
    <title>PT Sessions | JOF INDIA</title>

    <link rel="stylesheet" href="../static/root.css">
    <style>
        /* Premium Design System for PT Sessions */
        :root {
            --pt-primary: #F25C2A;
            --pt-primary-light: #ff8c69;
            --pt-secondary: #7C3AED;
            --pt-success: #10B981;
            --pt-warning: #F59E0B;
            --pt-danger: #EF4444;
            --pt-bg: #F9FAFB;
        }

        /* Modal Specificity Overrides */
        .page-calendar .modal-overlay {
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.4);
            backdrop-filter: blur(8px);
            z-index: 10000;
            display: none;
            align-items: center;
            justify-content: center;
            opacity: 0;
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            padding: 20px;
            pointer-events: none;
        }

        .page-calendar .modal-overlay.active {
            display: flex;
            opacity: 1;
            pointer-events: all;
        }

        .page-calendar .modal-card {
            background: #fff;
            border-radius: 24px;
            width: 95%;
            max-width: 400px;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.15);
            overflow: hidden;
            text-align: left;
            transform: scale(0.9) translateY(30px);
            transition: all 0.4s cubic-bezier(0.34, 1.56, 0.64, 1);
            position: relative;
        }

        .page-calendar .modal-overlay.active .modal-card {
            transform: scale(1) translateY(0);
        }

        .page-calendar .modal-header {
            padding: 32px 32px 12px;
            display: flex;
            align-items: center;
            background: #fff;
            text-align: left;
        }

        .page-calendar .modal-header h3 {
            font-size: 20px;
            font-weight: 800;
            color: #1a202c;
            margin: 0;
            letter-spacing: -0.6px;
            line-height: 1.2;
            flex: 1;
            padding-right: 40px;
        }

        .page-calendar .modal-card form {
            padding: 0 24px 24px;
            text-align: left;
        }

        .page-calendar .modal-card form::before {
            content: "";
            display: block;
            height: 1px;
            background: #f1f5f9;
            margin: 8px 0 24px;
        }

        .page-calendar .form-group {
            margin-bottom: 20px;
            text-align: left;
        }

        .page-calendar .form-group label {
            display: block;
            font-size: 11px;
            font-weight: 800;
            color: #64748b;
            margin-bottom: 8px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            text-align: left;
        }

        .page-calendar .member-select,
        .page-calendar .form-group input {
            width: 100%;
            padding: 12px 16px !important;
            border: 1px solid transparent !important;
            border-radius: 14px !important;
            font-size: 15px;
            font-weight: 600;
            outline: none;
            transition: all 0.2s ease;
            background: #f8fafc !important;
            color: #1e293b;
            box-sizing: border-box;
        }

        .page-calendar .member-select:focus,
        .page-calendar .form-group input:focus {
            background: #fff !important;
            border-color: #e2e8f0 !important;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05) !important;
        }

        .page-calendar .modal-close-btn {
            position: absolute;
            top: 24px;
            right: 24px;
            background: none;
            border: none;
            padding: 8px;
            cursor: pointer;
            color: #000;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s ease;
            flex-shrink: 0;
            border-radius: 50%;
            z-index: 10;
        }

        .page-calendar .modal-close-btn:hover {
            background: #f1f5f9;
        }

        .page-calendar .modal-close-btn img {
            width: 20px;
            height: 20px;
            opacity: 0.8;
        }

        .page-calendar .modal-close-btn:hover {
            background: #FEE2E2;
            color: #EF4444;
            transform: rotate(90deg);
        }

        .page-calendar .modal-footer {
            padding: 16px 24px 24px;
            display: flex;
            justify-content: flex-end;
            gap: 12px;
        }

        .page-calendar .modal-card form .btn-group {
            display: flex;
            gap: 12px;
            margin-top: 25px;
        }

        .page-calendar .modal-card form .btn-group .btn-cancel {
            flex: 1;
        }

        .page-calendar .modal-card form .btn-group .btn-confirm {
            flex: 2;
        }

        .page-calendar .btn-confirm {
            padding: 12px 24px;
            background: var(--pt-primary);
            color: white;
            border: none;
            border-radius: 14px;
            font-weight: 800;
            font-size: 15px;
            cursor: pointer;
            transition: all 0.2s ease;
            width: 100%;
        }

        .page-calendar .btn-confirm:hover {
            transform: translateY(-2px);
            filter: brightness(1.1);
        }

        .page-calendar .btn-cancel {
            padding: 12px 24px;
            background: #fff;
            color: #64748b;
            border: 1px solid #e2e8f0;
            border-radius: 14px;
            font-weight: 700;
            font-size: 15px;
            cursor: pointer;
            transition: all 0.2s ease;
            width: 100%;
        }

        .page-calendar .btn-cancel:hover {
            background: #f8fafc;
        }

        /* Animations */
        @keyframes popIn {
            0% {
                transform: scale(0.9) translateY(20px);
                opacity: 0;
            }

            100% {
                transform: scale(1) translateY(0);
                opacity: 1;
            }
        }

        @keyframes ptModalIn {
            0% {
                transform: translateY(30px);
                opacity: 0;
            }

            100% {
                transform: translateY(0);
                opacity: 1;
            }
        }

        @keyframes slideInRight {
            from {
                transform: translateX(100%);
                opacity: 0;
            }

            to {
                transform: translateX(0);
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

        .page-calendar .info-modal-card {
            background: #fff;
            border-radius: 32px;
            width: 95%;
            max-width: 400px;
            padding: 40px 32px;
            text-align: center;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.15);
            position: relative;
        }

        .page-calendar .modal-overlay.active .info-modal-card {
            transform: scale(1) translateY(0);
        }

        .btn-success-modal {
            margin-top: 25px;
            width: 100%;
            padding: 16px;
            background: var(--pt-success);
            color: white;
            border: none;
            border-radius: 18px;
            font-weight: 700;
            font-size: 16px;
            cursor: pointer;
            transition: all 0.2s;
        }

        .btn-success-modal:hover {
            transform: translateY(-2px);
            filter: brightness(1.1);
        }

        /* Responsive Mobile Styles */
        @media (max-width: 600px) {
            .page-calendar .modal-overlay {
                align-items: flex-end;
                padding: 0;
            }

            .page-calendar .modal-card {
                max-width: 100%;
                border-radius: 30px 30px 0 0;
                transform: translateY(100%);
            }

            .page-calendar .modal-overlay.active .modal-card {
                transform: translateY(0);
            }
        }

        /* ── Universal Toast Notification ── */
        .pt-toast-notification {
            display: none;
            position: fixed;
            bottom: 28px;
            right: 28px;
            z-index: 12000;
            background: #fff;
            border-radius: 16px;
            padding: 16px 22px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.16), 0 2px 8px rgba(0, 0, 0, 0.06);
            align-items: center;
            gap: 14px;
            min-width: 300px;
            max-width: 420px;
            border-left: 4px solid transparent;
        }

        .pt-toast-notification.show {
            display: flex;
            animation: toastSlideIn 0.35s cubic-bezier(0.34, 1.56, 0.64, 1) both;
        }

        .pt-toast-notification.toast-out {
            animation: toastSlideOut 0.3s ease forwards;
        }

        .pt-toast-notification.toast-success {
            border-left-color: #10B981;
        }

        .pt-toast-notification.toast-error {
            border-left-color: #EF4444;
        }

        .pt-toast-notification.toast-warning {
            border-left-color: #F59E0B;
        }

        .pt-toast-icon {
            width: 42px;
            height: 42px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        .toast-success .pt-toast-icon {
            background: linear-gradient(135deg, #D1FAE5, #A7F3D0);
        }

        .toast-error .pt-toast-icon {
            background: linear-gradient(135deg, #FEE2E2, #FECACA);
        }

        .toast-warning .pt-toast-icon {
            background: linear-gradient(135deg, #FEF3C7, #FDE68A);
        }

        .pt-toast-icon img {
            width: 20px;
            height: 20px;
        }

        .pt-toast-body {
            flex: 1;
            min-width: 0;
        }

        .pt-toast-title {
            font-size: 14px;
            font-weight: 700;
            color: #111827;
            margin-bottom: 2px;
        }

        .pt-toast-msg {
            font-size: 12.5px;
            color: #6B7280;
            line-height: 1.4;
            word-break: break-word;
        }

        .pt-toast-close {
            background: none;
            border: none;
            cursor: pointer;
            padding: 4px;
            border-radius: 6px;
            opacity: 0.4;
            transition: opacity 0.2s, background 0.2s;
            flex-shrink: 0;
        }

        .pt-toast-close:hover {
            opacity: 1;
            background: #F3F4F6;
        }

        .pt-toast-close img {
            width: 14px;
            height: 14px;
        }

        .pt-toast-timer {
            position: absolute;
            bottom: 0;
            left: 4px;
            right: 0;
            height: 3px;
            border-radius: 0 0 16px 16px;
            overflow: hidden;
        }

        .pt-toast-timer-bar {
            height: 100%;
            width: 100%;
            border-radius: 3px;
            transition: width linear;
        }

        .toast-success .pt-toast-timer-bar {
            background: linear-gradient(90deg, #10B981, #34D399);
        }

        .toast-error .pt-toast-timer-bar {
            background: linear-gradient(90deg, #EF4444, #F87171);
        }

        .toast-warning .pt-toast-timer-bar {
            background: linear-gradient(90deg, #F59E0B, #FBBF24);
        }

        @keyframes toastSlideIn {
            from {
                transform: translateX(120%);
                opacity: 0;
            }

            to {
                transform: translateX(0);
                opacity: 1;
            }
        }

        @keyframes toastSlideOut {
            from {
                transform: translateX(0);
                opacity: 1;
            }

            to {
                transform: translateX(120%);
                opacity: 0;
            }
        }

        @media (max-width: 500px) {
            .pt-toast-notification {
                left: 16px;
                right: 16px;
                bottom: 20px;
                min-width: auto;
            }
        }
    </style>
</head>

<body class="page-calendar">

    <button class="mobile-toggle" id="mobileToggle"><img src="../icons/bars-solid-full.svg"
            style="width: 22px; height: 22px; filter: brightness(0) invert(1);"></button>

    <button class="toggle-sidebar-btn" id="toggleBtn"><img src="../icons/chevron-left-solid-full.svg"
            style="width: 14px; height: 14px; filter: brightness(0) invert(1);"></button>
    <div class="dashboard-container">
        <?php include 'sidebar.php'; ?>

        <main class="main-content">
            <div class="calendar-wrapper">

                <div class="calendar-main">
                    <div class="calendar-header">
                        <div class="calendar-title-section">
                            <h2 id="monthYear"></h2>
                            <p class="calendar-subtitle">Manage and track PT sessions</p>
                        </div>
                        <div class="calendar-controls">
                            <select id="memberFilter" class="member-select-filter">
                                <option value="0">All Active Members</option>
                                <?php foreach ($members as $m): ?>
                                    <option value="<?= $m['id'] ?>"><?= htmlspecialchars($m['full_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <div class="nav-buttons">
                                <button class="cal-nav-btn" id="prevBtn" title="Previous Month">
                                    <img src="../icons/chevron-left-solid-full.svg" style="width: 14px; height: 14px;">
                                </button>
                                <button class="cal-today-btn" id="todayBtn">Today</button>
                                <button class="cal-nav-btn" id="nextBtn" title="Next Month">
                                    <img src="../icons/chevron-right-solid-full.svg" style="width: 14px; height: 14px;">
                                </button>
                            </div>
                        </div>
                    </div>
                    <div class="days-grid">
                        <div>Sun</div>
                        <div>Mon</div>
                        <div>Tue</div>
                        <div>Wed</div>
                        <div>Thu</div>
                        <div>Fri</div>
                        <div>Sat</div>
                    </div>
                    <div class="dates-grid" id="datesGrid"></div>
                </div>

                <div class="events-panel">
                    <div class="panel-header">
                        <h3 id="panelTitle">Upcoming Sessions</h3>
                        <div style="display:flex; align-items:center; gap:8px;">
                            <button class="add-event-btn" onclick="openPackageModal()"
                                title="Add Additional Sessions to Package"
                                style="background:#10B981; border:none; border-radius:6px; width:34px; height:34px; color:white; cursor:pointer; display:flex; align-items:center; justify-content:center;">
                                <img src="../icons/box-open-solid-full.svg"
                                    style="width:18px; height:18px; filter: brightness(0) invert(1);">
                            </button>
                            <button class="add-event-btn" onclick="openModal()" title="Schedule Session"
                                style="background:var(--pt-primary); border:none; border-radius:6px; width:34px; height:34px; color:white; cursor:pointer; display:flex; align-items:center; justify-content:center;">
                                <img src="../icons/plus-solid-full.svg"
                                    style="width:16px; height:16px; filter: brightness(0) invert(1);">
                            </button>
                            <button id="ptEmailBtn" onclick="sendPtCalendarEmail()" title="Send PT schedule to member"
                                style="background:#7C3AED; border:none; border-radius:6px; width:34px; height:34px; color:white; cursor:pointer; display:flex; align-items:center; justify-content:center; transition:opacity 0.2s;">
                                <img src="../icons/envelope-solid-full.svg"
                                    style="width:18px; height:18px; filter: brightness(0) invert(1);">
                            </button>
                        </div>
                    </div>
                    <div class="event-list" id="eventList">
                        <div class="empty-state">Loading sessions...</div>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <div class="modal-overlay" id="eventModal">
        <div class="modal-card">
            <div class="modal-header">
                <h3 id="modalTitle">Schedule Session</h3>
                <button onclick="closeModal()" class="modal-close-btn">
                    <img src="../icons/xmark-solid-full.svg" style="width:18px; height:18px;">
                </button>
            </div>
            <form id="eventForm">
                <input type="hidden" id="sessionId">

                <div class="form-group">
                    <label>Select Member</label>
                    <select id="modalMemberSelect" class="member-select" required>
                        <option value="">-- Choose Member --</option>
                        <?php foreach ($members as $m): ?>
                            <option value="<?= $m['id'] ?>" data-ptid="<?= $m['pt_id'] ?>"
                                data-startdate="<?= $m['start_date'] ?>">
                                <?= htmlspecialchars($m['full_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group" id="rescheduleTypeGroup" style="display:none; margin-top:15px;">
                    <label>Who Requested Change?</label>
                    <div style="display:flex; gap:12px; background:#F9FAFB; padding:12px; border-radius:12px;">
                        <label class="radio-option" style="display:flex; align-items:center; gap:8px; cursor:pointer;">
                            <input type="radio" name="r_type" value="member">
                            <span style="color:#EF4444; font-weight:700; font-size:14px;">Member</span>
                        </label>
                        <label class="radio-option" style="display:flex; align-items:center; gap:8px; cursor:pointer;">
                            <input type="radio" name="r_type" value="trainer">
                            <span style="color:#F59E0B; font-weight:700; font-size:14px;">Trainer</span>
                        </label>
                    </div>
                </div>

                <div style="display:grid; grid-template-columns: 1fr 1fr; gap:20px; margin-top:10px;">
                    <div class="form-group">
                        <label>Date</label>
                        <input type="date" id="eventDate" required>
                    </div>
                    <div class="form-group">
                        <label>Time</label>
                        <input type="time" id="eventTime" required>
                    </div>
                </div>

                <div class="btn-group">
                    <button type="button" onclick="closeModal()" class="btn-cancel">Cancel</button>
                    <button type="submit" class="btn-confirm" id="modalSubmitBtn">Confirm</button>
                </div>
            </form>
        </div>
    </div>

    <div class="modal-overlay" id="packageModal">
        <div class="modal-card">
            <div class="modal-header">
                <h3>Add Sessions to Package</h3>
                <button type="button" onclick="closePackageModal()" class="modal-close-btn">
                    <img src="../icons/xmark-solid-full.svg" style="width:18px; height:18px;">
                </button>
            </div>
            <form id="packageForm">
                <div class="form-group">
                    <label>Select Member</label>
                    <select id="packageMemberSelect" class="member-select" required>
                        <option value="">-- Choose Member --</option>
                        <?php foreach ($members as $m): ?>
                            <option value="<?= $m['id'] ?>" data-ptid="<?= $m['pt_id'] ?>">
                                <?= htmlspecialchars($m['full_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group" style="margin-top:20px;">
                    <label>Number of Sessions to Add</label>
                    <input type="number" id="additionalSessions" min="1" required placeholder="e.g. 5">
                </div>

                <div class="btn-group">
                    <button type="button" onclick="closePackageModal()" class="btn-cancel">Cancel</button>
                    <button type="submit" class="btn-confirm" id="packageSubmitBtn"
                        style="background:var(--pt-success);">Add Sessions</button>
                </div>
            </form>
        </div>
    </div>


    <div id="successModal" class="modal-overlay">
        <div class="info-modal-card">
            <div
                style="width:72px; height:72px; border-radius:50%; background:linear-gradient(135deg,#10B981,#34D399); 
                display:flex; align-items:center; justify-content:center; margin:0 auto 24px; box-shadow: 0 10px 20px -5px rgba(16, 185, 129, 0.3);">
                <img src="../icons/check-solid-full.svg"
                    style="width:32px; height:32px; filter: brightness(0) invert(1);">
            </div>
            <h2 id="successModalTitle" style="margin:0 0 10px; font-size:22px; color:#111827; font-weight:800;">Success
            </h2>
            <p id="successModalSub" style="margin:0; font-size:14px; color:#6B7280; line-height:1.6;"></p>

            <div id="successTimerContainer"
                style="margin-top:22px; height:4px; border-radius:4px; background:#F3F4F6; overflow:hidden;">
                <div id="successTimer"
                    style="height:100%; width:100%; background:linear-gradient(90deg,#10B981,#34D399); transition:width 2s linear;">
                </div>
            </div>

            <button onclick="closeSuccessModal()" class="btn-success-modal">Done</button>
        </div>
    </div>


    <div class="modal-overlay" id="exhaustedModal">
        <div class="info-modal-card">
            <div
                style="width:72px; height:72px; border-radius:50%; background:#FEF3C7; display:flex; align-items:center; justify-content:center; margin:0 auto 24px; box-shadow: 0 10px 20px -5px rgba(245, 158, 11, 0.2);">
                <img src="../icons/triangle-exclamation-solid-full.svg" style="width:32px; height:32px;">
            </div>
            <h2 style="margin:0 0 8px; font-size:22px; color:#111827; font-weight:800;">Sessions Exhausted</h2>
            <p style="margin:0 0 24px; font-size:14px; color:#6B7280; line-height:1.5;">This member's PT package has no
                remaining sessions.</p>

            <div style="display:flex; gap:12px; justify-content:center; margin-bottom:24px;">
                <div
                    style="flex:1; background:#F9FAFB; border-radius:14px; padding:16px 8px; border:1px solid #F3F4F6;">
                    <div style="font-size:22px; font-weight:800; color:#111827;" id="exh_total">—</div>
                    <div
                        style="font-size:10px; color:#9CA3AF; margin-top:4px; font-weight:700; text-transform:uppercase;">
                        Total</div>
                </div>
                <div
                    style="flex:1; background:#FEF2F2; border-radius:14px; padding:16px 8px; border:1px solid #FEE2E2;">
                    <div style="font-size:22px; font-weight:800; color:#DC2626;" id="exh_used">—</div>
                    <div
                        style="font-size:10px; color:#9CA3AF; margin-top:4px; font-weight:700; text-transform:uppercase;">
                        Used</div>
                </div>
                <div
                    style="flex:1; background:#F0FDF4; border-radius:14px; padding:16px 8px; border:1px solid #DCFCE7;">
                    <div style="font-size:22px; font-weight:800; color:#16A34A;" id="exh_available">—</div>
                    <div
                        style="font-size:10px; color:#9CA3AF; margin-top:4px; font-weight:700; text-transform:uppercase;">
                        Left</div>
                </div>
            </div>

            <p style="font-size:13px; color:#6B7280; margin:0 0 24px;">
                To add more sessions, click
                <strong onclick="closeExhaustedModal(); openPackageModal();"
                    style="color:#10B981; cursor:pointer; text-decoration:underline; font-weight:700;">Add
                    Sessions</strong>.
            </p>
            <button onclick="closeExhaustedModal()" class="btn-confirm"
                style="width:100%; background:#111827; box-shadow: 0 10px 15px -3px rgba(17, 24, 39, 0.2);">Got
                it</button>
        </div>
    </div>


    <div class="modal-overlay" id="ptEmailModal">
        <div class="modal-card"
            style="max-width: 520px; width: 100%; padding: 0; display: flex; flex-direction: column; max-height: 85vh; border-radius: 20px; background: #fff; overflow: hidden; box-sizing: border-box;">

            <div
                style="width: 100%; box-sizing: border-box; padding: 20px 24px; border-bottom: 1px solid #E5E7EB; display: flex; justify-content: space-between; align-items: center; background: #fff;">
                <div>
                    <h3
                        style="margin: 0; font-size: 20px; font-weight: 800; color: #111827; display: flex; align-items: center; gap: 12px;">
                        <div
                            style="width: 40px; height: 40px; border-radius: 12px; background: #FFF7ED; display: flex; align-items: center; justify-content: center;">
                            <img src="../icons/envelope-solid-full.svg"
                                style="width: 20px; height: 20px; filter: invert(48%) sepia(70%) saturate(2476%) hue-rotate(345deg) brightness(98%) contrast(93%);">
                        </div>
                        Preview Email
                    </h3>
                    <div id="ptEmailModalSub"
                        style="margin: 6px 0 0 52px; font-size: 13px; color: #6B7280; font-weight: 500;">
                    </div>
                </div>
                <button type="button" onclick="closePtEmailModal()"
                    style="background: #F3F4F6; border: none; width: 32px; height: 32px; border-radius: 50%; display: flex; align-items: center; justify-content: center; cursor: pointer; transition: 0.2s;">
                    <img src="../icons/xmark-solid-full.svg" style="width: 14px; height: 14px; opacity: 0.5;">
                </button>
            </div>

            <div id="ptEmailModalInfo"
                style="width: 100%; box-sizing: border-box; padding: 16px 24px; background: #F9FAFB; border-bottom: 1px solid #E5E7EB; font-size: 14px; color: #374151; display: flex; align-items: center; flex-wrap: wrap; gap: 8px;">
            </div>

            <div id="ptEmailModalList"
                style="width: 100%; box-sizing: border-box; padding: 0; overflow-y: auto; background: #fff; flex: 1;">
            </div>

            <div
                style="width: 100%; box-sizing: border-box; padding: 16px 24px; background: #fff; border-top: 1px solid #E5E7EB; display: flex; justify-content: flex-end; gap: 12px;">
                <button type="button" onclick="closePtEmailModal()"
                    style="padding: 12px 24px; border-radius: 12px; border: 1px solid #E5E7EB; background: #fff; color: #4B5563; font-weight: 600; font-size: 14px; cursor: pointer; transition: 0.2s;">
                    Cancel
                </button>
                <button type="button" id="ptEmailSendBtn" onclick="confirmSendPtEmail()"
                    style="padding: 12px 24px; border-radius: 12px; border: none; background: var(--pt-primary); color: #fff; font-weight: 700; font-size: 14px; cursor: pointer; display: flex; align-items: center; gap: 8px; transition: 0.2s; box-shadow: 0 4px 12px rgba(242, 92, 42, 0.25);">
                    <img src="../icons/paper-plane-solid-full.svg"
                        style="width: 16px; height: 16px; filter: brightness(0) invert(1);"> Send Email
                </button>
            </div>

        </div>
    </div>

    <div id="ptEmailToast" style="
        display:none; position:fixed; bottom:28px; right:28px; z-index:11000;
        background:#fff; border-radius:14px; padding:16px 22px;
        box-shadow:0 8px 30px rgba(0,0,0,0.18);
        align-items:center; gap:14px; min-width:280px;">
        <div id="ptEmailToastIcon"
            style="width:40px;height:40px;border-radius:50%;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
            <img src="../icons/circle-notch-solid-full.svg" class="spin-icon"
                style="width:20px;height:20px; filter: invert(47%) sepia(91%) saturate(2224%) hue-rotate(345deg) brightness(96%) contrast(91%);">
        </div>
        <div>
            <div id="ptEmailToastTitle" style="font-size:14px;font-weight:700;color:#111827;">Sending email...</div>
            <div id="ptEmailToastMsg" style="font-size:12px;color:#6B7280;margin-top:2px;"></div>
        </div>
    </div>

    <!-- Universal Toast Notification -->
    <div class="pt-toast-notification" id="ptToastNotification">
        <div class="pt-toast-icon" id="ptToastIcon">
            <img src="../icons/check-solid-full.svg" id="ptToastIconImg">
        </div>
        <div class="pt-toast-body">
            <div class="pt-toast-title" id="ptToastTitle"></div>
            <div class="pt-toast-msg" id="ptToastMsg"></div>
        </div>
        <button class="pt-toast-close" onclick="hideToast()">
            <img src="../icons/xmark-solid-full.svg">
        </button>
        <div class="pt-toast-timer">
            <div class="pt-toast-timer-bar" id="ptToastTimerBar"></div>
        </div>
    </div>

    <script>
        const dateGrid = document.getElementById('datesGrid');
        const eventList = document.getElementById('eventList');
        const modal = document.getElementById('eventModal');
        const memberFilter = document.getElementById('memberFilter');
        const eventForm = document.getElementById('eventForm');
        let currentDate = new Date();
        let allSessions = [];

        document.addEventListener('DOMContentLoaded', () => {
            fetchSessions();
            renderCalendar();

            document.getElementById('prevBtn').onclick = () => { currentDate.setMonth(currentDate.getMonth() - 1); renderCalendar(); };
            document.getElementById('nextBtn').onclick = () => { currentDate.setMonth(currentDate.getMonth() + 1); renderCalendar(); };
            document.getElementById('todayBtn').onclick = () => { currentDate = new Date(); renderCalendar(); renderSidePanel(); };

            memberFilter.addEventListener('change', () => {
                renderCalendar();
                renderSidePanel();
            });

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
        });

        // 0. DATE RESTRICTION LOGIC
        const modalMemberSelect = document.getElementById('modalMemberSelect');
        const eventDateInput = document.getElementById('eventDate');

        function updateMinDate() {
            const selectedOption = modalMemberSelect.options[modalMemberSelect.selectedIndex];
            if (selectedOption && selectedOption.dataset.startdate) {
                eventDateInput.min = selectedOption.dataset.startdate;
            } else {
                eventDateInput.removeAttribute('min');
            }
        }

        modalMemberSelect.addEventListener('change', updateMinDate);

        // 1. FETCH
        function fetchSessions() {
            fetch('../handlers/calendar_handler.php?action=fetch')
                .then(async r => {
                    if (!r.ok) {
                        const text = await r.text();
                        throw new Error(`Server Error (${r.status}): ${text.substring(0, 100)}`);
                    }
                    return r.json();
                })
                .then(data => {
                    allSessions = data;
                    renderCalendar();
                    renderSidePanel();
                })
                .catch(err => {
                    eventList.innerHTML = '<div class="empty-state">Error loading data.</div>';
                    console.error("Fetch Error:", err);
                });
        }

        // 2. RENDER LIST
        function renderSidePanel(filterDate = null) {
            eventList.innerHTML = "";
            let title = document.getElementById('panelTitle');
            const selectedMemberId = memberFilter.value;
            let filtered = [];
            let todayStr = new Date().toISOString().split('T')[0];

            if (filterDate) {
                title.innerText = "Sessions on " + filterDate;
                filtered = allSessions.filter(s => s.session_date === filterDate);
            }
            else if (selectedMemberId !== "0") {
                title.innerText = "Upcoming Schedule";
                filtered = allSessions.filter(s => s.session_date >= todayStr && s.member_id == selectedMemberId);
            }
            else {
                title.innerText = "Today's Sessions (" + todayStr + ")";
                filtered = allSessions.filter(s => s.session_date === todayStr);
            }

            if (selectedMemberId !== "0") filtered = filtered.filter(s => s.member_id == selectedMemberId);

            if (filtered.length === 0) { eventList.innerHTML = '<div class="empty-state">No sessions found.</div>'; return; }

            filtered.forEach(s => {
                let statusClass = 'scheduled';
                const rType = s.reschedule_type || 'none';
                const isGeneralEvent = s.event_type === 'general_event';

                // Set status class
                if (isGeneralEvent) {
                    statusClass = 'general-event';
                } else if (s.status === 'Completed') {
                    statusClass = 'completed';
                } else if (rType === 'member') {
                    statusClass = 'rescheduled-member';
                } else if (rType === 'trainer') {
                    statusClass = 'rescheduled-trainer';
                }

                // Display title (member name for PT sessions, event title for general events)
                const displayTitle = isGeneralEvent
                    ? `<img src="../icons/dumbbell-solid-full.svg" style="width: 14px; height: 14px; color:#14B8A6; margin-right:5px; vertical-align: middle;">${s.member_name}`
                    : s.member_name;

                let html = `
                    <div class="event-card ${statusClass}">
                        <div class="event-info">
                            <h4>${displayTitle}</h4>
                            <p style="display:flex; align-items:center; gap:6px; color:#6B7280; font-size:13px;"><img src="../icons/clock-solid-full.svg" style="width:14px; height:14px;"> ${s.session_date} @ ${s.session_time.substring(0, 5)}</p>
                            ${s.status === 'Completed' ? '<span class="event-status">COMPLETED</span>' : ''}
                            ${isGeneralEvent ? '<span style="color:#0F766E; font-weight:600; font-size:12px; margin-top:4px; display:block;"><img src="../icons/calendar-day-solid-full.svg" style="width:12px; height:12px; margin-right:5px;">Dashboard Event</span>' : ''}
                        </div>
                        <div class="event-actions">
                            ${!isGeneralEvent && s.status !== 'Completed' ? `
                                <button class="btn-icon-sm btn-reschedule" title="Reschedule" onclick="openReschedule(${s.id}, '${s.member_id}', '${s.session_date}', '${s.session_time}')">
                                    <img src="../icons/calendar-day-solid-full.svg" style="width:14px; height:14px; filter: invert(34%) sepia(85%) saturate(2311%) hue-rotate(243deg) brightness(96%) contrast(92%);">
                                </button>
                                <button class="btn-icon-sm btn-delete" title="Delete Session" onclick="deleteSession(${s.id})">
                                    <img src="../icons/trash-solid-full.svg" style="width:14px; height:14px; filter: invert(33%) sepia(94%) saturate(4646%) hue-rotate(345deg) brightness(98%) contrast(91%);">
                                </button>
                            ` : ''}
                        </div>
                    </div>
                `;
                eventList.innerHTML += html;
            });
        }

        // 3. RENDER CALENDAR
        function renderCalendar() {
            dateGrid.innerHTML = "";
            const year = currentDate.getFullYear();
            const month = currentDate.getMonth();
            document.getElementById('monthYear').innerText = new Date(year, month).toLocaleString('default', { month: 'long', year: 'numeric' });
            const firstDay = new Date(year, month, 1).getDay();
            const daysInMonth = new Date(year, month + 1, 0).getDate();
            const selectedMemberId = memberFilter.value;

            for (let i = 0; i < firstDay; i++) dateGrid.innerHTML += '<div class="date empty"></div>';

            for (let i = 1; i <= daysInMonth; i++) {
                let dateStr = `${year}-${String(month + 1).padStart(2, '0')}-${String(i).padStart(2, '0')}`;
                let hasSession = allSessions.some(s => s.session_date === dateStr && (selectedMemberId === "0" || s.member_id == selectedMemberId));
                let dayClass = hasSession ? 'date has-event' : 'date';
                if (dateStr === new Date().toISOString().split('T')[0]) dayClass += ' today';

                // Add event dot if there are sessions
                let eventDot = hasSession ? '<span class="event-dot"></span>' : '';

                dateGrid.innerHTML += `<div class="${dayClass}" onclick="renderSidePanel('${dateStr}')">${i}${eventDot}</div>`;
            }
        }

        function openReschedule(id, memberId, date, time) {
            document.getElementById('sessionId').value = id;
            document.getElementById('modalMemberSelect').value = memberId;
            document.getElementById('modalMemberSelect').disabled = true;
            updateMinDate();
            document.getElementById('eventDate').value = date;
            document.getElementById('eventTime').value = time;
            document.getElementById('modalTitle').innerText = "Reschedule Session";
            document.getElementById('rescheduleTypeGroup').style.display = 'block';
            modal.classList.add('active');
        }

        function openModal() {
            eventForm.reset();
            document.getElementById('sessionId').value = "";
            document.getElementById('modalTitle').innerText = "Schedule Session";
            document.getElementById('modalMemberSelect').disabled = false;
            document.getElementById('rescheduleTypeGroup').style.display = 'none';
            if (memberFilter.value !== "0") {
                document.getElementById('modalMemberSelect').value = memberFilter.value;
            }
            updateMinDate();
            modal.classList.add('active');
        }

        function closeModal() { modal.classList.remove('active'); }

        const packageModal = document.getElementById('packageModal');
        const packageForm = document.getElementById('packageForm');

        function openPackageModal() {
            packageForm.reset();
            if (memberFilter.value !== "0") document.getElementById('packageMemberSelect').value = memberFilter.value;
            packageModal.classList.add('active');
        }

        function closePackageModal() { packageModal.classList.remove('active'); }

        packageForm.addEventListener('submit', (e) => {
            e.preventDefault();
            const btn = document.getElementById('packageSubmitBtn');
            const originalText = btn.innerText;
            btn.innerText = "Processing...";
            btn.disabled = true;

            const sel = document.getElementById('packageMemberSelect');
            const ptId = sel.options[sel.selectedIndex].getAttribute('data-ptid');
            const sessions = document.getElementById('additionalSessions').value;

            fetch(`../handlers/calendar_handler.php?action=update_package`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ pt_id: ptId, sessions: sessions })
            })
                .then(async r => {
                    const text = await r.text();
                    try {
                        const json = JSON.parse(text);
                        if (!r.ok) throw new Error(json.message || r.statusText);
                        return json;
                    } catch (e) {
                        throw new Error(`Invalid Server Response:\n${text.substring(0, 150)}...`);
                    }
                })
                .then(data => {
                    if (data.status === 'success') {
                        closePackageModal();
                        showSuccessModal('Sessions Added!', 'The sessions have been added to the member\'s package.');
                    } else {
                        closePackageModal();
                        showToast('error', 'Failed to Add Sessions', data.message || 'Something went wrong. Please try again.');
                    }
                })
                .catch(err => {
                    closePackageModal();
                    showToast('error', 'Request Failed', err.message || 'Could not connect to server. Please try again.');
                })
                .finally(() => {
                    btn.innerText = originalText;
                    btn.disabled = false;
                });
        });


        // Delete session
        function deleteSession(sessionId) {
            fetch(`../handlers/calendar_handler.php?action=delete`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ id: sessionId })
            })
                .then(r => r.json())
                .then(data => {
                    if (data.status === 'success') {
                        showSuccessModal('Session Deleted', 'The session has been removed from the calendar.');
                    } else {
                        alert('Error: ' + (data.message || 'Failed to delete session'));
                    }
                })
                .catch(err => {
                    console.error('Delete error:', err);
                    alert('Failed to delete session. Please try again.');
                });
        }

        // --- SUBMIT FORM WITH ROBUST DEBUGGING ---
        eventForm.addEventListener('submit', (e) => {
            e.preventDefault();
            console.log("Form submitted!");

            // Basic UI State
            const btn = document.getElementById('modalSubmitBtn');
            const originalText = btn.innerText;
            btn.innerText = "Processing...";
            btn.disabled = true;

            const id = document.getElementById('sessionId').value;
            const action = id ? 'reschedule' : 'add';
            console.log("Action:", action);

            const sel = document.getElementById('modalMemberSelect');
            console.log("Selected option:", sel.selectedIndex, sel.value);

            const ptId = sel.options[sel.selectedIndex].getAttribute('data-ptid');
            console.log("PT ID from data-ptid:", ptId);

            let rType = 'none';
            if (action === 'reschedule') {
                const rOptions = document.getElementsByName('r_type');
                for (let r of rOptions) if (r.checked) rType = r.value;
            }

            const inputDate = document.getElementById('eventDate').value;
            const startDate = sel.options[sel.selectedIndex].dataset.startdate;

            if (startDate && inputDate < startDate) {
                showToast('warning', 'Invalid Date', `Session date cannot be before the Joining Date (${startDate}).`);
                btn.innerText = originalText;
                btn.disabled = false;
                return;
            }

            const payload = {
                id: id,
                pt_id: ptId,
                member_id: sel.value,
                date: document.getElementById('eventDate').value,
                time: document.getElementById('eventTime').value,
                reschedule_type: rType
            };

            console.log("Payload:", payload);
            console.log("Sending to:", `../handlers/calendar_handler.php?action=${action}`);

            fetch(`../handlers/calendar_handler.php?action=${action}`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            })
                .then(async r => {
                    console.log("Response status:", r.status);
                    const text = await r.text();
                    console.log("Response text:", text);

                    try {
                        const json = JSON.parse(text);
                        if (!r.ok) throw new Error(json.message || r.statusText);
                        return json;
                    } catch (e) {
                        console.error("JSON Parse Error:", e);
                        throw new Error(`Invalid Server Response:\n${text.substring(0, 150)}...`);
                    }
                })
                .then(data => {
                    console.log("Success data:", data);
                    if (data.status === 'success') {
                        closeModal();
                        if (action === 'add') {
                            showSuccessModal('Session Scheduled!', 'The PT session has been added to the calendar.');
                        } else {
                            showSuccessModal('Session Rescheduled!', 'The session has been moved to the new date and time.');
                        }
                    } else if (data.type === 'exhausted') {
                        closeModal();
                        showExhaustedModal(data.total, data.used, data.available);
                    } else {
                        closeModal();
                        showToast('error', 'Scheduling Failed', data.message || 'Could not schedule session. Please try again.');
                    }
                })
                .catch(err => {
                    console.error("Submission Error:", err);
                    closeModal();
                    showToast('error', 'Request Failed', err.message || 'Could not connect to server. Please try again.');
                })
                .finally(() => {
                    btn.innerText = originalText;
                    btn.disabled = false;
                });
        });

        function showSuccessModal(title, subtitle, autoClose = true) {
            const modal = document.getElementById('successModal');
            document.getElementById('successModalTitle').innerText = title;
            document.getElementById('successModalSub').innerText = subtitle;
            modal.classList.add('active');

            const timerContainer = document.getElementById('successTimerContainer');
            const bar = document.getElementById('successTimer');

            if (autoClose) {
                timerContainer.style.display = 'block';
                bar.style.transition = 'none';
                bar.style.width = '100%';
                requestAnimationFrame(() => {
                    requestAnimationFrame(() => {
                        bar.style.transition = 'width 2s linear';
                        bar.style.width = '0%';
                    });
                });
                setTimeout(() => {
                    if (modal.classList.contains('active')) closeSuccessModal();
                }, 2000);
            } else {
                timerContainer.style.display = 'none';
            }
        }

        function closeSuccessModal() {
            document.getElementById('successModal').classList.remove('active');
            fetchSessions();
        }

        function showExhaustedModal(total, used, available) {
            document.getElementById('exh_total').innerText = total;
            document.getElementById('exh_used').innerText = used;
            document.getElementById('exh_available').innerText = available;
            document.getElementById('exhaustedModal').classList.add('active');
        }
        function closeExhaustedModal() {
            document.getElementById('exhaustedModal').classList.remove('active');
        }


        // Email Specific Logic
        let _ptEmailMemberId = null;

        document.getElementById('memberFilter').addEventListener('change', function () {
            const btn = document.getElementById('ptEmailBtn');
            btn.style.opacity = (this.value && this.value !== '0') ? '1' : '0.5';
        });

        const STATUS_COLORS = {
            'Scheduled': { bg: '#DBEAFE', color: '#1D4ED8' },
            'Rescheduled-Member': { bg: '#FEE2E2', color: '#DC2626' },
            'Rescheduled-Trainer': { bg: '#FEF3C7', color: '#92400E' },
        };

        function statusPill(status) {
            const c = STATUS_COLORS[status] || { bg: '#F3F4F6', color: '#374151' };
            return `<span style="display:inline-block;padding:2px 10px;border-radius:20px;font-size:10px;font-weight:700; background:${c.bg};color:${c.color};">${status}</span>`;
        }

        function sendPtCalendarEmail() {
            const memberId = document.getElementById('memberFilter').value;
            if (!memberId || memberId === '0') {
                const sel = document.getElementById('memberFilter');
                sel.style.border = '2px solid #7C3AED';
                sel.style.boxShadow = '0 0 0 3px rgba(124,58,237,0.2)';
                sel.focus();
                setTimeout(() => { sel.style.border = ''; sel.style.boxShadow = ''; }, 2500);
                showPtEmailToast('<img src="../icons/triangle-exclamation-solid-full.svg" style="width:20px;height:20px;filter: invert(68%) sepia(74%) saturate(3062%) hue-rotate(359deg) brightness(98%) contrast(96%);">',
                    'Select a Member', 'Please pick a specific member from the dropdown first.', '#F59E0B');
                setTimeout(() => { document.getElementById('ptEmailToast').style.display = 'none'; }, 3500);
                return;
            }
            _ptEmailMemberId = memberId;

            const btn = document.getElementById('ptEmailBtn');
            btn.innerHTML = '<img src="../icons/circle-notch-solid-full.svg" class="spin-icon" style="width:18px;height:18px; filter: brightness(0) invert(1);">';
            btn.style.pointerEvents = 'none';

            const fd = new FormData();
            fd.append('member_id', memberId);
            fd.append('action', 'preview');

            fetch('../handlers/send_pt_calendar_email.php', { method: 'POST', body: fd })
                .then(r => r.json())
                .then(data => {
                    btn.innerHTML = '<img src="../icons/envelope-solid-full.svg" style="width:18px;height:18px;filter: brightness(0) invert(1);">';
                    btn.style.pointerEvents = 'auto';

                    if (!data.success) {
                        showPtEmailToast('<img src="../icons/xmark-solid-full.svg" style="width:20px;height:20px;color:#EF4444;">',
                            'No Data', data.message, '#EF4444');
                        setTimeout(() => { document.getElementById('ptEmailToast').style.display = 'none'; }, 4000);
                        return;
                    }

                    // Populate modal text
                    document.getElementById('ptEmailModalSub').textContent =
                        data.count + ' upcoming session' + (data.count !== 1 ? 's' : '') + ' will be shared via email.';

                    document.getElementById('ptEmailModalInfo').innerHTML =
                        `<strong style="color: #111827;">To: ${data.member_name}</strong> <span style="color: #D1D5DB; margin: 0 4px;">|</span> <img src="../icons/envelope-solid-full.svg" style="width:14px;height:14px;vertical-align:middle; opacity: 0.5;"> ${data.member_email}`;

                    // Build session list with strictly constrained widths and borders
                    let html = '';
                    let prevDate = '';
                    data.sessions.forEach(s => {
                        if (s.date !== prevDate) {
                            html += `<div style="width: 100%; box-sizing: border-box; padding: 12px 24px; background: #F3F4F6; font-size: 11px; font-weight: 800; color: #6B7280; text-transform: uppercase; letter-spacing: 1px; display: flex; align-items: center; gap: 8px; border-top: 1px solid #E5E7EB; border-bottom: 1px solid #E5E7EB;">
                                <img src="../icons/calendar-day-solid-full.svg" style="width:12px;height:12px; opacity: 0.6;"> ${s.date}
                            </div>`;
                            prevDate = s.date;
                        }

                        const notes = s.notes
                            ? `<div style="font-size:12px; color:#9CA3AF; margin-top:4px; display:flex; align-items:center; gap:6px;"><img src="../icons/note-sticky-solid-full.svg" style="width:12px;height:12px; opacity: 0.6;"> ${s.notes}</div>`
                            : '';

                        html += `<div style="width: 100%; box-sizing: border-box; display:flex; align-items:center; justify-content: space-between; padding: 16px 24px; border-bottom: 1px solid #F9FAFB;">
                            <div>
                                <div style="font-weight:700; font-size:15px; color:#111827; display:flex; align-items:center; gap:8px;">
                                    <img src="../icons/clock-solid-full.svg" style="width:14px;height:14px; opacity: 0.4;"> ${s.time}
                                </div>
                                <div style="font-size:13px; color:#6B7280; margin-top:4px; display:flex; align-items:center; gap:8px;">
                                    <img src="../icons/user-solid-full.svg" style="width:13px;height:13px; opacity: 0.4;"> ${s.trainer}
                                </div>
                                ${notes}
                            </div>
                            <div>${statusPill(s.status)}</div>
                        </div>`;
                    });

                    document.getElementById('ptEmailModalList').innerHTML = html;
                    document.getElementById('ptEmailModal').classList.add('active');
                })
                .catch(() => {
                    btn.innerHTML = '<img src="../icons/envelope-solid-full.svg" style="width:18px;height:18px;filter: brightness(0) invert(1);">';
                    btn.style.pointerEvents = 'auto';
                    showPtEmailToast('<img src="../icons/xmark-solid-full.svg" style="width:20px;height:20px;color:#EF4444;">',
                        'Network Error', 'Please try again.', '#EF4444');
                    setTimeout(() => { document.getElementById('ptEmailToast').style.display = 'none'; }, 4000);
                });
        }

        function closePtEmailModal() {
            document.getElementById('ptEmailModal').classList.remove('active');
        }

        function confirmSendPtEmail() {
            if (!_ptEmailMemberId) return;

            const sendBtn = document.getElementById('ptEmailSendBtn');
            sendBtn.innerHTML = '<img src="../icons/circle-notch-solid-full.svg" class="spin-icon" style="width:16px;height:16px;filter: brightness(0) invert(1);"> Sending...';
            sendBtn.style.pointerEvents = 'none';

            const fd = new FormData();
            fd.append('member_id', _ptEmailMemberId);
            fd.append('action', 'send');

            fetch('../handlers/send_pt_calendar_email.php', { method: 'POST', body: fd })
                .then(r => r.json())
                .then(data => {
                    sendBtn.innerHTML = '<img src="../icons/paper-plane-solid-full.svg" style="width:16px;height:16px;filter: brightness(0) invert(1);"> Send Email';
                    sendBtn.style.pointerEvents = 'auto';
                    closePtEmailModal();

                    if (data.success) {
                        showToast('success', 'Email Sent Successfully!', data.message || 'The PT schedule has been emailed to the member.');
                    } else {
                        showToast('error', 'Email Failed', data.message || 'Could not send email. Please try again.');
                    }
                })
                .catch(() => {
                    sendBtn.innerHTML = '<img src="../icons/paper-plane-solid-full.svg" style="width:16px;height:16px;filter: brightness(0) invert(1);"> Send Email';
                    sendBtn.style.pointerEvents = 'auto';
                    closePtEmailModal();
                    showToast('error', 'Network Error', 'Could not connect to server. Please try again.');
                });
        }

        function showPtEmailToast(icon, title, msg, color) {
            const toast = document.getElementById('ptEmailToast');
            document.getElementById('ptEmailToastIcon').innerHTML = icon;
            document.getElementById('ptEmailToastIcon').style.background = color + '20';
            document.getElementById('ptEmailToastTitle').textContent = title;
            document.getElementById('ptEmailToastMsg').textContent = msg;
            toast.style.display = 'flex';
            toast.style.animation = 'slideInRight 0.35s ease both';
        }

        // ── Universal Toast System ──
        let _toastTimer = null;

        function showToast(type, title, msg, duration = 4500) {
            const toast = document.getElementById('ptToastNotification');
            const iconImg = document.getElementById('ptToastIconImg');
            const timerBar = document.getElementById('ptToastTimerBar');

            // Clear any existing timer/animation
            if (_toastTimer) clearTimeout(_toastTimer);
            toast.classList.remove('show', 'toast-out', 'toast-success', 'toast-error', 'toast-warning');

            // Set type class
            toast.classList.add('toast-' + type);

            // Set icon
            const icons = {
                success: '../icons/check-solid-full.svg',
                error: '../icons/xmark-solid-full.svg',
                warning: '../icons/triangle-exclamation-solid-full.svg'
            };
            iconImg.src = icons[type] || icons.warning;

            // Apply icon color filters
            const filters = {
                success: 'invert(52%) sepia(83%) saturate(535%) hue-rotate(112deg) brightness(95%) contrast(94%)',
                error: 'invert(33%) sepia(94%) saturate(4646%) hue-rotate(345deg) brightness(98%) contrast(91%)',
                warning: 'invert(68%) sepia(74%) saturate(3062%) hue-rotate(359deg) brightness(98%) contrast(96%)'
            };
            iconImg.style.filter = filters[type] || '';

            // Set text
            document.getElementById('ptToastTitle').textContent = title;
            document.getElementById('ptToastMsg').textContent = msg;

            // Timer bar animation
            timerBar.style.transition = 'none';
            timerBar.style.width = '100%';
            requestAnimationFrame(() => {
                requestAnimationFrame(() => {
                    timerBar.style.transition = `width ${duration}ms linear`;
                    timerBar.style.width = '0%';
                });
            });

            // Show toast
            toast.classList.add('show');

            // Auto-dismiss
            _toastTimer = setTimeout(() => {
                hideToast();
            }, duration);
        }

        function hideToast() {
            const toast = document.getElementById('ptToastNotification');
            toast.classList.add('toast-out');
            setTimeout(() => {
                toast.classList.remove('show', 'toast-out', 'toast-success', 'toast-error', 'toast-warning');
            }, 300);
            if (_toastTimer) {
                clearTimeout(_toastTimer);
                _toastTimer = null;
            }
        }

        // Close modal on overlay click
        document.getElementById('ptEmailModal').addEventListener('click', function (e) {
            if (e.target === this) closePtEmailModal();
        });
    </script>

    <style>
        .spin-icon {
            animation: spin 2s infinite linear;
        }

        @keyframes spin {
            from {
                transform: rotate(0deg);
            }

            to {
                transform: rotate(360deg);
            }
        }
    </style>
</body>

</html>