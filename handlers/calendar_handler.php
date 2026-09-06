<?php

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../error_log.txt');

function debug_log($message)
{
    $logFile = __DIR__ . '/../debug_calendar.log';
    $timestamp = date('Y-m-d H:i:s');
    file_put_contents($logFile, "[$timestamp] $message\n", FILE_APPEND);
}

debug_log("=== Calendar Handler Called ===");
debug_log("Action: " . ($_GET['action'] ?? 'none'));
debug_log("Method: " . $_SERVER['REQUEST_METHOD']);
debug_log("Raw Input: " . file_get_contents('php://input'));

header('Content-Type: application/json');

try {
    require '../config.php';

    $input = file_get_contents('php://input');
    $data = json_decode($input, true);
    $action = $_GET['action'] ?? '';

    // FETCH
    if ($action === 'fetch') {
        $conn->query("UPDATE pt_sessions SET status = 'Completed' WHERE (status = 'Scheduled' OR status = 'Rescheduled') AND session_date < CURDATE()");

        // Fetch PT sessions
        $sql = "SELECT 
                    ps.id,
                    ps.pt_id,
                    ps.member_id,
                    ps.session_date,
                    ps.session_time,
                    ps.status,
                    ps.reschedule_type,
                    m.full_name AS member_name,
                    'pt_session' AS event_type
                FROM pt_sessions ps 
                JOIN members m ON ps.member_id = m.id 
                WHERE ps.status != 'Cancelled'
                
                UNION ALL
                
                -- Fetch gym-type events from dashboard
                SELECT 
                    e.id,
                    NULL AS pt_id,
                    NULL AS member_id,
                    e.event_date AS session_date,
                    e.event_time AS session_time,
                    'Scheduled' AS status,
                    'none' AS reschedule_type,
                    e.title AS member_name,
                    'general_event' AS event_type
                FROM events e
                WHERE e.event_type = 'gym'
                
                ORDER BY session_date ASC";

        $result = $conn->query($sql);
        $events = [];
        while ($row = $result->fetch_assoc())
            $events[] = $row;
        echo json_encode($events);
        exit;
    }

    // ADD SESSION
    if ($action === 'add') {
        debug_log("--- ADD SESSION START ---");
        debug_log("Received pt_id: " . ($data['pt_id'] ?? 'NULL'));
        debug_log("Received member_id: " . ($data['member_id'] ?? 'NULL'));
        debug_log("Received date: " . ($data['date'] ?? 'NULL'));
        debug_log("Received time: " . ($data['time'] ?? 'NULL'));

        $pt_id = intval($data['pt_id']);
        $member_id = intval($data['member_id']);
        $date = $data['date'];
        $time = $data['time'];

        debug_log("Parsed pt_id: $pt_id, member_id: $member_id");

        if (!$pt_id || !$member_id) {
            debug_log("ERROR: Invalid IDs");
            throw new Exception("Invalid Member ID or PT Package ID.");
        }

        // Check against ACTUAL count for robustness
        debug_log("Checking PT package existence...");
        $check = $conn->query("SELECT total_sessions, start_date FROM personal_training WHERE id = $pt_id");
        if (!$check || $check->num_rows === 0) {
            debug_log("ERROR: PT Package not found for ID $pt_id");
            throw new Exception("PT Package not found.");
        }

        $stats = $check->fetch_assoc();
        $total_sessions = intval($stats['total_sessions']);
        $package_start_date = $stats['start_date'];

        // Validate Date: Cannot be before PT joining date
        if ($date < $package_start_date) {
            debug_log("ERROR: Session date ($date) is before PT start date ($package_start_date)");
            throw new Exception("Session date cannot be before the PT joining date ($package_start_date).");
        }
        debug_log("Total sessions: $total_sessions");

        // Count actual sessions (excluding Cancelled)
        debug_log("Counting actual sessions...");
        $countQuery = $conn->query("SELECT COUNT(*) as used FROM pt_sessions WHERE pt_id = $pt_id AND status != 'Cancelled'");
        $countRow = $countQuery->fetch_assoc();
        $actual_used = intval($countRow['used']);
        debug_log("Actual used: $actual_used");

        if ($actual_used >= $total_sessions) {
            debug_log("Package exhausted: $actual_used >= $total_sessions");
            $available = $total_sessions - $actual_used;
            echo json_encode([
                'status'    => 'error',
                'type'      => 'exhausted',
                'message'   => 'Package exhausted',
                'total'     => $total_sessions,
                'used'      => $actual_used,
                'available' => max(0, $available)
            ]);
            exit;
        }

        // Insert
        debug_log("Preparing insert statement...");
        $stmt = $conn->prepare("INSERT INTO pt_sessions (pt_id, member_id, session_date, session_time, status, reschedule_type) VALUES (?, ?, ?, ?, 'Scheduled', 'none')");
        if (!$stmt) {
            debug_log("ERROR: SQL Prepare failed: " . $conn->error);
            throw new Exception("SQL Prepare Error: " . $conn->error);
        }

        $stmt->bind_param("iiss", $pt_id, $member_id, $date, $time);
        debug_log("Executing insert...");

        if ($stmt->execute()) {
            debug_log("Insert successful!");
            // Sync usage count
            $conn->query("UPDATE personal_training SET sessions_used = (SELECT COUNT(*) FROM pt_sessions WHERE pt_id = $pt_id AND status != 'Cancelled') WHERE id = $pt_id");
            debug_log("Sessions_used synced");
            echo json_encode(['status' => 'success']);
        } else {
            debug_log("ERROR: Insert failed: " . $stmt->error);
            throw new Exception("Insert failed: " . $stmt->error);
        }
        exit;
    }

    // RESCHEDULE
    if ($action === 'reschedule') {
        debug_log("--- RESCHEDULE SESSION START ---");
        debug_log("Session ID: " . ($data['id'] ?? 'NULL'));
        debug_log("Reschedule Type: " . ($data['reschedule_type'] ?? 'NULL'));

        $stmt = $conn->prepare("UPDATE pt_sessions ps 
                                JOIN personal_training pt ON ps.pt_id = pt.id 
                                SET ps.session_date = ?, ps.session_time = ?, ps.status = 'Rescheduled', ps.reschedule_type = ? 
                                WHERE ps.id = ? AND ? >= pt.start_date");
        $stmt->bind_param("sssis", $data['date'], $data['time'], $data['reschedule_type'], $data['id'], $data['date']);

        if ($stmt->execute()) {
            if ($conn->affected_rows === 0) {
                // Check if it exists but failed the date check
                $check = $conn->query("SELECT pt.start_date FROM pt_sessions ps JOIN personal_training pt ON ps.pt_id = pt.id WHERE ps.id = " . intval($data['id']));
                if ($check && $row = $check->fetch_assoc()) {
                    if ($data['date'] < $row['start_date']) {
                        throw new Exception("Session date cannot be before the PT joining date (" . $row['start_date'] . ").");
                    }
                }
                throw new Exception("Update failed or no changes made.");
            }
            debug_log("Reschedule successful!");
            echo json_encode(['status' => 'success']);
        } else {
            debug_log("ERROR: Reschedule failed: " . $stmt->error);
            throw new Exception("Update failed: " . $stmt->error);
        }
        exit;
    }

    // DELETE
    if ($action === 'delete') {
        debug_log("--- DELETE SESSION START ---");
        debug_log("Session ID: " . ($data['id'] ?? 'NULL'));

        $session_id = intval($data['id']);

        // First get the pt_id to update the count
        $getStmt = $conn->prepare("SELECT pt_id FROM pt_sessions WHERE id = ?");
        $getStmt->bind_param("i", $session_id);
        $getStmt->execute();
        $result = $getStmt->get_result();
        $session = $result->fetch_assoc();

        if (!$session) {
            throw new Exception("Session not found");
        }

        $pt_id = $session['pt_id'];

        // Delete the session
        $stmt = $conn->prepare("DELETE FROM pt_sessions WHERE id = ?");
        $stmt->bind_param("i", $session_id);

        if ($stmt->execute()) {
            debug_log("Delete successful!");

            // Update sessions_used count
            $conn->query("UPDATE personal_training SET sessions_used = (SELECT COUNT(*) FROM pt_sessions WHERE pt_id = $pt_id AND status != 'Cancelled') WHERE id = $pt_id");
            debug_log("Sessions_used count updated");

            echo json_encode(['status' => 'success']);
        } else {
            debug_log("ERROR: Delete failed: " . $stmt->error);
            throw new Exception("Delete failed: " . $stmt->error);
        }
        exit;
    }

    // UPDATE PACKAGE SESSIONS
    if ($action === 'update_package') {
        debug_log("--- UPDATE PACKAGE START ---");
        $pt_id = intval($data['pt_id'] ?? 0);
        $additional_sessions = intval($data['sessions'] ?? 0);

        if (!$pt_id || $additional_sessions <= 0) {
            throw new Exception("Invalid package ID or number of sessions.");
        }

        $stmt = $conn->prepare("UPDATE personal_training SET total_sessions = total_sessions + ? WHERE id = ?");
        $stmt->bind_param("ii", $additional_sessions, $pt_id);

        if ($stmt->execute()) {
            debug_log("Update package successful!");
            echo json_encode(['status' => 'success']);
        } else {
            debug_log("ERROR: Update package failed: " . $stmt->error);
            throw new Exception("Update package failed: " . $stmt->error);
        }
        exit;
    }

} catch (Exception $e) {
    // Send detailed error to frontend
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
?>