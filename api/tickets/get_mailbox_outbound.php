<?php
/**
 * API Endpoint: Get the outbound send log for a mailbox
 * GET: ?mailbox_id=N&search=text&status=failed&page=1
 *
 * The outbound counterpart to get_mailbox_activity.php. Same shape of response so the
 * activity modal can render either tab with the same paging code.
 *
 * mailbox_id=0 is meaningful, not a mistake: sends that never resolved a mailbox are
 * logged with a NULL mailbox_id, and those are among the most useful rows in the table
 * ("no mailbox could send this"). Without a way to ask for them they would be written
 * and never seen.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/email_log.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}
requireModuleAccessJson('tickets');

$mailboxId = $_GET['mailbox_id'] ?? null;
$search    = trim($_GET['search'] ?? '');
$status    = $_GET['status'] ?? '';          // '', 'sent', 'failed' or 'skipped'
$page      = max(1, (int)($_GET['page'] ?? 1));
$perPage   = 50;
$offset    = ($page - 1) * $perPage;

if ($mailboxId === null || $mailboxId === '') {
    echo json_encode(['success' => false, 'error' => 'Mailbox ID is required']);
    exit;
}

try {
    $conn = connectToDatabase();

    // 0 means "the sends that had no mailbox".
    if ((int)$mailboxId === 0) {
        $where  = "WHERE mailbox_id IS NULL";
        $params = [];
    } else {
        $where  = "WHERE mailbox_id = ?";
        $params = [(int)$mailboxId];
    }

    if ($search !== '') {
        $where .= " AND (to_address LIKE ? OR subject LIKE ? OR error_message LIKE ?)";
        $s = '%' . $search . '%';
        $params[] = $s; $params[] = $s; $params[] = $s;
    }
    if (in_array($status, ['sent', 'failed', 'skipped'], true)) {
        $where .= " AND status = ?";
        $params[] = $status;
    }

    $countStmt = $conn->prepare("SELECT COUNT(*) FROM email_send_log $where");
    $countStmt->execute($params);
    $total = (int)$countStmt->fetchColumn();

    $sql = "SELECT id, mailbox_id, ticket_id, route, provider, auth_mode, to_address,
                   subject, status, error_message, created_datetime
            FROM email_send_log $where
            ORDER BY created_datetime DESC, id DESC
            LIMIT $perPage OFFSET $offset";
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $entries = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Route label resolved server-side so there is one list of routes, not two.
    foreach ($entries as &$e) {
        $e['route_label'] = emailLogRouteLabel((string)$e['route']);
    }
    unset($e);

    // Failure count ignores the status filter, so the tab badge does not read zero
    // simply because you are currently looking at the successes.
    $failWhere  = ((int)$mailboxId === 0) ? "mailbox_id IS NULL" : "mailbox_id = ?";
    $failParams = ((int)$mailboxId === 0) ? [] : [(int)$mailboxId];
    $failStmt = $conn->prepare("SELECT COUNT(*) FROM email_send_log WHERE $failWhere AND status = 'failed'");
    $failStmt->execute($failParams);

    echo json_encode([
        'success'   => true,
        'entries'   => $entries,
        'total'     => $total,
        'failed'    => (int)$failStmt->fetchColumn(),
        'page'      => $page,
        'per_page'  => $perPage,
        'routes'    => EMAIL_LOG_ROUTES,
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
