<?php
/**
 * API Endpoint: Get ticket audit history
 * Returns all audit entries for a ticket
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/tenancy.php';

header('Content-Type: application/json');

// Check if user is logged in
if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}
requireModuleAccessJson('tickets');

$ticketId = $_GET['ticket_id'] ?? null;

if (!$ticketId) {
    echo json_encode(['success' => false, 'error' => 'Ticket ID required']);
    exit;
}

try {
    $conn = connectToDatabase();

    // Multi-tenancy: don't reveal a ticket in a company this analyst can't access.
    if (!analystCanAccessTicket($conn, (int)$_SESSION['analyst_id'], $ticketId)) {
        echo json_encode(['success' => false, 'error' => 'Ticket not found']);
        exit;
    }

    $sql = "SELECT
                ta.id,
                ta.ticket_id,
                ta.field_name,
                ta.old_value,
                ta.new_value,
                ta.created_datetime,
                ta.analyst_id,
                a.full_name as analyst_name
            FROM ticket_audit ta
            LEFT JOIN analysts a ON ta.analyst_id = a.id
            WHERE ta.ticket_id = ?
            ORDER BY ta.created_datetime DESC";

    $stmt = $conn->prepare($sql);
    $stmt->execute([$ticketId]);
    $audit = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Say WHICH case an unresolved author is, rather than leaving the browser to
    // print "Unknown" — the same three-way split `get_notes.php` already makes,
    // and for the same reason: "Unknown" says *we do not know* when the truth is
    // *we did not look*, and it hides the thing worth knowing.
    //
    //   analyst — a real person, resolved by the LEFT JOIN
    //   system  — analyst_id IS NULL: the workflow engine wrote it (GH #120).
    //             `add_ticket_note` sets NULL deliberately to mark an entry as
    //             automation rather than a person
    //   former  — a real analyst_id with no row left. Deleting an analyst is a
    //             hard DELETE that reassigns nothing, so this is the normal
    //             state of every entry someone made before they left
    //
    // The label itself is resolved in the browser, which is where the
    // translations live; this endpoint only says which case it is.
    foreach ($audit as &$row) {
        if ($row['analyst_name']) {
            $row['author_kind'] = 'analyst';
        } elseif ($row['analyst_id'] === null) {
            $row['author_kind'] = 'system';
        } else {
            $row['author_kind'] = 'former';
        }
    }
    unset($row);

    // Views (discussion #62, step 2), merged into the same newest-first list so
    // the Audit window - desktop and phone both read this endpoint - shows them
    // without a change of its own. Service-desk views appear here and only here;
    // the requester is shown managers' views alone (see includes/ticket_views.php).
    require_once '../../includes/ticket_views.php';
    $views = ticketViewsAsAuditRows($conn, (int)$ticketId);
    if ($views) {
        $audit = array_merge($audit, $views);
        usort($audit, function ($a, $b) {
            return strcmp((string)$b['created_datetime'], (string)$a['created_datetime']);
        });
    }

    echo json_encode([
        'success' => true,
        'audit' => $audit
    ]);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}

?>
