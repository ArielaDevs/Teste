<?php
/**
 * Self-service: the requester marks one of their own tickets Confidential.
 * POST JSON { ticket_id }
 *
 * Discussion #62. One way only: a requester can make their ticket confidential
 * (kept from their managers) but never make it Normal again. Lowering is a
 * service-desk decision, because a department or mailbox may have made it
 * confidential for a reason the requester cannot see - and a requester talked
 * into "un-confidentialling" a grievance by the manager it is about is exactly
 * the case this exists to prevent.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/ticket_sensitivity.php';

header('Content-Type: application/json');

if (empty($_SESSION['ss_user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$userId   = (int)$_SESSION['ss_user_id'];
$in       = json_decode(file_get_contents('php://input'), true) ?: [];
$ticketId = (int)($in['ticket_id'] ?? 0);
if (!$ticketId) {
    echo json_encode(['success' => false, 'error' => 'Ticket ID required']);
    exit;
}

try {
    $conn = connectToDatabase();
    if (!ticketSensitivityReady($conn)) {
        echo json_encode(['success' => false, 'error' => 'Not available yet on this service desk.']);
        exit;
    }

    // Their own ticket only - the same ownership rule as every portal endpoint.
    $st = $conn->prepare("SELECT id FROM tickets WHERE id = ? AND user_id = ? AND deleted_datetime IS NULL");
    $st->execute([$ticketId, $userId]);
    if (!$st->fetchColumn()) {
        echo json_encode(['success' => false, 'error' => 'Ticket not found']);
        exit;
    }

    ticketSensitivityRaise($conn, $ticketId, 'the requester marked it confidential');
    echo json_encode(['success' => true, 'sensitivity' => 'confidential']);

} catch (Exception $e) {
    error_log('[self-service] mark_confidential: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Could not update the ticket']);
}
