<?php
/**
 * API Endpoint: Ticket search for the "Link to ticket" picker (issue #38).
 * GET ?source_ticket_id=&q=  — scoped to the source ticket's company, excludes
 * the source ticket itself.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/ticket_links.php';

header('Content-Type: application/json');
if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}
requireModuleAccessJson('tickets');

$sourceId = (int)($_GET['source_ticket_id'] ?? 0);
$q = trim((string)($_GET['q'] ?? ''));

try {
    $conn = connectToDatabase();
    echo json_encode(ticketLinkableList($conn, (int)$_SESSION['analyst_id'], $sourceId, $q));
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
