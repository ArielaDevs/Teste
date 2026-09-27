<?php
/**
 * API: the tickets a MANAGER may see - raised by the people they manage
 * (discussion #62, step 3). GET, same row shape as get_tickets.php plus who
 * raised each one.
 *
 * 🔑 Every candidate ticket goes through portalTicketAccess() - the SAME rule the
 * ticket page, the attachments, the documents and the recordings use - rather
 * than a second, SQL-shaped copy of it. A list that disagreed with the page it
 * links to would show a manager tickets they are then refused, or worse, hide
 * nothing the page hides. The price is a few small queries per ticket, which is
 * why the list stops at the 300 most recently updated.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/managers.php';

header('Content-Type: application/json');

if (!isset($_SESSION['ss_user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}
$userId = (int)$_SESSION['ss_user_id'];

const TEAM_TICKETS_LIMIT = 300;

try {
    $conn = connectToDatabase();

    $team = managerTeamUserIds($conn, $userId);   // [] when off, not ready, or they have left
    if (!$team) {
        echo json_encode(['success' => true, 'tickets' => [], 'is_manager' => false]);
        exit;
    }

    $in = implode(',', array_fill(0, count($team), '?'));
    $stmt = $conn->prepare(
        "SELECT t.id, t.ticket_number, t.subject, ts.name AS status, tp.name AS priority,
                ts.colour AS status_colour, ts.is_closed,
                t.created_datetime, t.updated_datetime,
                d.name AS department_name,
                COALESCE(NULLIF(u.display_name, ''), u.email) AS requester_name,
                (SELECT LEFT(e.body_preview, 160) FROM emails e
                  WHERE e.ticket_id = t.id
                  ORDER BY e.received_datetime DESC LIMIT 1) AS preview
           FROM tickets t
      LEFT JOIN ticket_statuses ts   ON ts.id = t.status_id
      LEFT JOIN ticket_priorities tp ON tp.id = t.priority_id
      LEFT JOIN departments d        ON d.id  = t.department_id
      LEFT JOIN users u              ON u.id  = t.user_id
          WHERE t.user_id IN ($in) AND t.deleted_datetime IS NULL
       ORDER BY t.updated_datetime DESC
          LIMIT " . TEAM_TICKETS_LIMIT
    );
    $stmt->execute($team);

    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $t) {
        $access = portalTicketAccess($conn, $userId, (int)$t['id']);
        if (!$access) continue;                 // another company, confidential and hidden, ...
        if ($access['stub']) {
            // That it exists, and nothing it says.
            $t['subject'] = '';
            $t['preview'] = '';
            $t['priority'] = null;
            $t['department_name'] = null;
        }
        $t['stub'] = $access['stub'];
        $t['sensitivity'] = ticketSensitivityNormalise($access['ticket']['sensitivity']);
        $out[] = $t;
    }

    echo json_encode(['success' => true, 'tickets' => $out, 'is_manager' => true, 'limit' => TEAM_TICKETS_LIMIT]);
} catch (Exception $e) {
    error_log('[self-service] get_team_tickets: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Could not load the tickets']);
}
