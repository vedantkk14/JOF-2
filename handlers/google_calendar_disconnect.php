<?php
/**
 * handlers/google_calendar_disconnect.php
 * Removes a member's stored Calendar tokens (does not touch already-created
 * events on their calendar — those stay, just won't auto-update anymore;
 * this only stops future syncing).
 */

session_start();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../auth/google_calendar_helper.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Not logged in']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

google_calendar_disconnect($conn, (int) $_SESSION['user_id']);

echo json_encode(['success' => true]);
