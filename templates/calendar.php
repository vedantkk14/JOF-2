<?php
session_start();

// Security: Redirect if not logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: ../index.php");
    exit;
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" size="16x16" href="../icons/favicon-dark-logo.png" type="image/png">
    <title>JOF | Admin Calendar</title>
    <link rel="stylesheet" href="../static/root.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>
        /* ── Add Event Modal (scoped to calendar page) ── */
        .page-calendar .modal-overlay {
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,0.45);
            backdrop-filter: blur(4px);
            -webkit-backdrop-filter: blur(4px);
            z-index: 10000;
            display: none;
            align-items: center;
            justify-content: center;
            padding: 16px;
            opacity: 0;
            transition: opacity 0.2s ease;
            pointer-events: none;
        }
        .page-calendar .modal-overlay.active {
            display: flex;
            opacity: 1;
            pointer-events: all;
        }
        .page-calendar .modal-card {
            background: #fff;
            border-radius: 16px;
            width: 340px;
            max-width: 100%;
            box-shadow: 0 8px 32px rgba(0,0,0,0.18);
            overflow: hidden;
            position: relative;
            transform: scale(0.94) translateY(14px);
            transition: transform 0.28s cubic-bezier(0.34, 1.56, 0.64, 1);
        }
        .page-calendar .modal-overlay.active .modal-card {
            transform: scale(1) translateY(0);
        }

        /* Header */
        .page-calendar .modal-header {
            padding: 16px 18px 14px;
            border-bottom: 1px solid #F0F0F0;
        }
        .page-calendar .modal-header h3 {
            margin: 0;
            font-size: 15px;
            font-weight: 700;
            color: #111827;
        }
        .page-calendar #closeModalBtn {
            position: absolute;
            top: 10px;
            right: 12px;
            width: 28px;
            height: 28px;
            border: none;
            background: #F3F4F6;
            border-radius: 50%;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #6B7280;
            font-size: 13px;
            transition: background 0.15s, color 0.15s, transform 0.2s;
            padding: 0;
            z-index: 5;
        }
        .page-calendar #closeModalBtn:hover {
            background: #F25C2A;
            color: #fff;
            transform: rotate(90deg);
        }

        /* Form */
        .page-calendar .modal-card form {
            padding: 16px 18px 18px;
            display: flex;
            flex-direction: column;
            gap: 12px;
        }
        .page-calendar .modal-card .form-group {
            display: flex;
            flex-direction: column;
            gap: 5px;
        }
        .page-calendar .modal-card .form-group label {
            font-size: 12px;
            font-weight: 600;
            color: #374151;
            display: block;
        }
        .page-calendar .modal-card .form-group input,
        .page-calendar .modal-card .form-group select {
            width: 100%;
            padding: 9px 12px;
            border: 1.5px solid #E5E7EB;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 400;
            color: #111827;
            background: #fff;
            outline: none;
            box-sizing: border-box;
            transition: border-color 0.15s, box-shadow 0.15s;
            -webkit-appearance: none;
            appearance: none;
        }
        .page-calendar .modal-card .form-group select {
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24'%3E%3Cpath fill='%236B7280' d='M7 10l5 5 5-5z'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 10px center;
            background-size: 16px;
            padding-right: 30px;
            background-color: #fff;
        }
        .page-calendar .modal-card .form-group input:focus,
        .page-calendar .modal-card .form-group select:focus {
            border-color: #F25C2A;
            box-shadow: 0 0 0 3px rgba(242,92,42,0.10);
        }

        /* Save button */
        .page-calendar .modal-card .btn-save {
            width: 100%;
            padding: 11px;
            background: #F25C2A;
            color: #fff;
            border: none;
            border-radius: 9px;
            font-size: 14px;
            font-weight: 700;
            cursor: pointer;
            transition: background 0.15s, transform 0.15s;
            margin-top: 2px;
        }
        .page-calendar .modal-card .btn-save:hover {
            background: #d94e20;
            transform: translateY(-1px);
        }
        .page-calendar .modal-card .btn-save:active {
            transform: translateY(0);
        }
    </style>
</head>

<body class="page-calendar">
    <button class="mobile-toggle" id="mobileToggle">
        <i class="fa-solid fa-bars"></i>
    </button>

    <button class="toggle-sidebar-btn" id="toggleBtn">
        <i class="fa-solid fa-chevron-left"></i>
    </button>

    <div class="dashboard-container">

        <?php include 'sidebar.php'; ?>
        <main class="main-content">
            <div class="calendar-wrapper">

                <div class="calendar-main">
                    <div class="calendar-header">
                        <div class="month-year">
                            <h2 id="monthYear">January 2026</h2>
                        </div>
                        <div class="cal-actions">
                            <button class="cal-btn" id="prevBtn"><i class="fa-solid fa-chevron-left"></i></button>
                            <button class="cal-btn" id="todayBtn">Today</button>
                            <button class="cal-btn" id="nextBtn"><i class="fa-solid fa-chevron-right"></i></button>
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
                        <h3>Upcoming Events</h3>
                        <button class="add-event-btn" id="openModalBtn">
                            <i class="fa-solid fa-plus"></i>
                        </button>
                    </div>
                    <div class="event-list" id="eventList">
                        <div class="empty-state">Loading events...</div>
                    </div>
                </div>

            </div>
        </main>
    </div>

    <div class="modal-overlay" id="eventModal">
        <div class="modal-card">
            <div class="modal-header">
                <h3>Add New Event</h3>
                <button id="closeModalBtn"><i class="fa-solid fa-xmark"></i></button>
            </div>
            <form id="eventForm">
                <div class="form-group">
                    <label>Event Title</label>
                    <input type="text" name="title" id="eventTitle" placeholder="e.g. Staff Meeting" required>
                </div>
                <div class="form-group">
                    <label>Date</label>
                    <input type="date" name="date" id="eventDate" required>
                </div>
                <div class="form-group">
                    <label>Time</label>
                    <input type="time" name="time" id="eventTime" required>
                </div>
                <div class="form-group">
                    <label>Type</label>
                    <select name="type" id="eventType">
                        <option value="meeting">Meeting</option>
                        <option value="training">Training</option>
                        <option value="maintenance">Maintenance</option>
                        <option value="other">Other</option>
                    </select>
                </div>
                <button type="submit" class="btn-save">Save Event</button>
            </form>
        </div>
    </div>

    <script>
        const dateGrid = document.getElementById('datesGrid');
        const monthYear = document.getElementById('monthYear');
        const prevBtn = document.getElementById('prevBtn');
        const nextBtn = document.getElementById('nextBtn');
        const todayBtn = document.getElementById('todayBtn');
        const eventList = document.getElementById('eventList');

        // Modal Elements
        const modal = document.getElementById('eventModal');
        const openBtn = document.getElementById('openModalBtn');
        const closeBtn = document.getElementById('closeModalBtn');
        const eventForm = document.getElementById('eventForm');

        let currentDate = new Date();
        let events = [];

        // Load data on start
        document.addEventListener('DOMContentLoaded', () => {
            loadEventsFromServer();

            // Modal Listeners
            openBtn.addEventListener('click', () => {
                document.getElementById('eventDate').valueAsDate = new Date();
                modal.classList.add('active');
            });
            closeBtn.addEventListener('click', () => modal.classList.remove('active'));

            // Dropdown Logic (Sidebar)
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
        });

        // 1. FETCH EVENTS (From Database)
        async function loadEventsFromServer() {
            try {
                // Using relative path to your existing handler
                const response = await fetch("../handlers/fetch_events.php");
                if (!response.ok) throw new Error("Failed to fetch");

                events = await response.json();
                renderCalendar();
            } catch (error) {
                console.error("Error loading events:", error);
                eventList.innerHTML = '<div class="empty-state">Error loading data</div>';
            }
        }

        // 2. RENDER CALENDAR
        function renderCalendar() {
            dateGrid.innerHTML = "";
            const year = currentDate.getFullYear();
            const month = currentDate.getMonth();

            const firstDay = new Date(year, month, 1).getDay();
            const lastDate = new Date(year, month + 1, 0).getDate();
            const prevLastDate = new Date(year, month, 0).getDate();

            const months = ["January", "February", "March", "April", "May", "June", "July", "August", "September", "October", "November", "December"];
            monthYear.innerHTML = `${months[month]} ${year}`;

            // Previous Month Dates
            for (let i = firstDay; i > 0; i--) {
                dateGrid.innerHTML += `<div class="date prev-date">${prevLastDate - i + 1}</div>`;
            }

            // Current Month Dates
            for (let i = 1; i <= lastDate; i++) {
                let isToday = i === new Date().getDate() && month === new Date().getMonth() && year === new Date().getFullYear() ? "today" : "";

                // Format YYYY-MM-DD
                let monthStr = String(month + 1).padStart(2, '0');
                let dayStr = String(i).padStart(2, '0');
                let dateString = `${year}-${monthStr}-${dayStr}`;

                let dayEvents = events.filter(e => e.date === dateString);
                let eventDot = dayEvents.length > 0 ? '<div class="event-dot"></div>' : '';

                // Click date to open modal for that date
                dateGrid.innerHTML += `<div class="date ${isToday}" onclick="openModalForDate('${dateString}')">${i}${eventDot}</div>`;
            }

            // Next Month Dates
            const lastDayIndex = new Date(year, month + 1, 0).getDay();
            for (let i = 1; i <= 7 - lastDayIndex - 1; i++) {
                dateGrid.innerHTML += `<div class="date next-date">${i}</div>`;
            }

            renderEventList();
        }

        // Helper to open modal with specific date
        window.openModalForDate = function (dateStr) {
            document.getElementById('eventDate').value = dateStr;
            modal.classList.add('active');
        }

        // 3. RENDER EVENT LIST (Sidebar)
        function renderEventList() {
            eventList.innerHTML = "";

            // Filter only future events (or today)
            const today = new Date();
            today.setHours(0, 0, 0, 0);

            let futureEvents = events.filter(e => {
                // Parse date safely
                const eDate = new Date(e.date + ' ' + (e.time || '00:00'));
                return new Date(e.date) >= today;
            });

            // Sort by date
            futureEvents.sort((a, b) => new Date(a.date + ' ' + a.time) - new Date(b.date + ' ' + b.time));

            if (futureEvents.length === 0) {
                eventList.innerHTML = '<div class="empty-state">No upcoming events</div>';
                return;
            }

            futureEvents.forEach((event) => {
                const eventDate = new Date(event.date);
                const dayName = eventDate.toLocaleDateString('en-US', { weekday: 'short' });
                const monthName = eventDate.toLocaleDateString('en-US', { month: 'short' });
                const dateNum = eventDate.getDate();
                const fullDate = `${dayName}, ${monthName} ${dateNum}`;

                // Time calculation
                const eventTime = new Date(event.date + 'T' + event.time);
                const daysUntil = Math.floor((eventDate - today) / (1000 * 60 * 60 * 24));

                let statusText = daysUntil === 0 ? 'Today' : (daysUntil === 1 ? 'Tomorrow' : `${daysUntil} days away`);
                let statusClass = daysUntil === 0 ? 'status-today' : 'status-upcoming';

                // Ensure Type exists
                let type = event.type || 'other';

                const eventHTML = `
                    <div class="event-card ${type}">
                        <div class="event-badge" style="background-color: ${getTypeColor(type)};">
                            <i class="fa-solid ${getTypeIcon(type)}"></i>
                        </div>
                        <div class="event-details">
                            <div class="event-header">
                                <h4>${event.title}</h4>
                                <span class="event-status ${statusClass}">${statusText}</span>
                            </div>
                            <div class="event-meta">
                                <div class="meta-item"><i class="fa-solid fa-calendar"></i><span>${fullDate}</span></div>
                                <div class="meta-item"><i class="fa-solid fa-clock"></i><span>${event.time.substring(0, 5)}</span></div>
                            </div>
                        </div>
                    </div>
                `;
                eventList.innerHTML += eventHTML;
            });
        }

        // 4. SAVE EVENT (To Database)
        eventForm.addEventListener('submit', (e) => {
            e.preventDefault();

            const formData = new FormData(eventForm);

            fetch('../handlers/add_event.php', {
                method: 'POST',
                body: formData
            })
                .then(response => response.json())
                .then(data => {
                    if (data.status === 'success') {
                        modal.classList.remove('active');
                        eventForm.reset();
                        loadEventsFromServer(); // Refresh
                    } else {
                        alert('Error: ' + data.message);
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Failed to connect to server.');
                });
        });

        // Helpers
        function getTypeIcon(type) {
            switch (type) {
                case 'meeting': return 'fa-handshake';
                case 'training': return 'fa-person-running';
                case 'gym': return 'fa-dumbbell';
                case 'maintenance': return 'fa-wrench';
                default: return 'fa-calendar-check';
            }
        }

        function getTypeColor(type) {
            switch (type) {
                case 'meeting': return '#3B82F6';
                case 'training': return '#10B981';
                case 'gym': return '#F25C2A';
                case 'maintenance': return '#EF4444';
                default: return '#888';
            }
        }

        // Navigation
        prevBtn.addEventListener('click', () => { currentDate.setMonth(currentDate.getMonth() - 1); renderCalendar(); });
        nextBtn.addEventListener('click', () => { currentDate.setMonth(currentDate.getMonth() + 1); renderCalendar(); });
        todayBtn.addEventListener('click', () => { currentDate = new Date(); renderCalendar(); });

        // Sidebar Toggles
        const toggleBtn = document.getElementById('toggleBtn');
        const mobileToggle = document.getElementById('mobileToggle');
        const body = document.body;

        if (toggleBtn) toggleBtn.addEventListener('click', () => body.classList.toggle('collapsed'));
        if (mobileToggle) mobileToggle.addEventListener('click', () => body.classList.toggle('sidebar-open'));
    </script>
</body>


</html>