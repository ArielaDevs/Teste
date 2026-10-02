<?php
/**
 * API: List the assets linked to a ticket (discussion #57).
 *
 * Returns everything the reading pane's card needs in one call — the fields
 * discussion #57 asked to see without leaving the ticket: asset tag, make and
 * model, serial number, warranty, type and location.
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

try {
    $ticketId = isset($_GET['ticket_id']) ? (int)$_GET['ticket_id'] : 0;
    if ($ticketId <= 0) throw new Exception('ticket_id is required');

    $conn      = connectToDatabase();
    $analystId = (int)$_SESSION['analyst_id'];

    if (!analystCanAccessTicket($conn, $analystId, $ticketId)) {
        throw new Exception('Ticket not found');
    }

    // The ticket gate doesn't cover the assets this hydrates. A link made before
    // a company was split, or by an all-access analyst, can straddle two
    // companies — so scope the asset too rather than trusting the invariant.
    [$tAsset, $aAsset] = activeTenantFilter($conn, $analystId, 'a');

    $stmt = $conn->prepare(
        "SELECT ta.id AS link_id,
                a.id AS asset_id, a.hostname, a.manufacturer, a.model,
                a.service_tag, a.asset_tag, a.warranty_expiry,
                t.name AS type_name, l.name AS location_name,
                ta.created_datetime
           FROM ticket_assets ta
           JOIN assets a           ON a.id = ta.asset_id
      LEFT JOIN asset_types t      ON t.id = a.asset_type_id
      LEFT JOIN asset_locations l  ON l.id = a.location_id
          WHERE ta.ticket_id = ?" . $tAsset . "
       ORDER BY a.hostname, a.asset_tag"
    );
    $stmt->execute(array_merge([$ticketId], $aAsset));
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) {
        $r['link_id']  = (int)$r['link_id'];
        $r['asset_id'] = (int)$r['asset_id'];
    }

    echo json_encode(['success' => true, 'links' => $rows]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
