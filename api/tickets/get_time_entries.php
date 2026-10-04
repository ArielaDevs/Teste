<?php
/**
 * API Endpoint: List time entries for a ticket
 *
 * Returns only active (is_active = 1) entries, newest entry_datetime first.
 * Each row carries the logging analyst's name for inline display.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/tenancy.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}
requireModuleAccessJson('tickets');

$ticket_id = $_GET['ticket_id'] ?? null;

if (!$ticket_id) {
    echo json_encode(['success' => false, 'error' => 'Ticket ID required']);
    exit;
}

try {
    $conn = connectToDatabase();

    // Multi-tenancy: don't reveal a ticket in a company this analyst can't access.
    if (!analystCanAccessTicket($conn, (int)$_SESSION['analyst_id'], $ticket_id)) {
        echo json_encode(['success' => false, 'error' => 'Ticket not found']);
        exit;
    }

    // Time tracking switched off for this ticket's company (discussion #72).
    // ⚠️ Checked HERE and not only in the browser: hiding a panel is not the same
    // as turning a feature off, and an endpoint that still answers is one URL away
    // from putting the panel back. The rows are untouched — this returns none.
    require_once '../../includes/tenant_settings.php';
    if (!timeTrackingUiOn($conn, ticketTenantId($conn, (int)$ticket_id))) {
        echo json_encode(['success' => true, 'time_entries' => [], 'total_minutes' => 0, 'disabled' => true]);
        exit;
    }

    $sql = "SELECT
                te.id,
                te.ticket_id,
                te.analyst_id,
                te.notes,
                te.time_spent_minutes,
                te.entry_datetime,
                te.created_datetime,
                te.updated_datetime,
                a.full_name AS analyst_name
            FROM ticket_time_entries te
            JOIN analysts a ON te.analyst_id = a.id
            WHERE te.ticket_id = ? AND te.is_active = 1
            ORDER BY te.entry_datetime DESC, te.id DESC";

    $stmt = $conn->prepare($sql);
    $stmt->execute([$ticket_id]);
    $entries = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $totalMinutes = 0;
    foreach ($entries as &$entry) {
        $entry['time_spent_minutes'] = (int)$entry['time_spent_minutes'];
        $totalMinutes += $entry['time_spent_minutes'];
        foreach (['entry_datetime', 'created_datetime', 'updated_datetime'] as $col) {
            if (!empty($entry[$col])) {
                $entry[$col] = date('Y-m-d\TH:i:s', strtotime($entry[$col]));
            }
        }
    }

    echo json_encode([
        'success' => true,
        'entries' => $entries,
        'total_minutes' => $totalMinutes
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
