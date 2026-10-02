<?php
/**
 * API: create or update a saved view (discussion #96).
 *
 * POST { table_key, name, description?, visibility, team_id?, config }  create
 * POST { id, ...same }                                                  update
 *
 * ⚠️ Only the owner may update. A view shared with a team is readable by that
 * team and writable by nobody else — see includes/table_views.php.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/table_views.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

$in = json_decode(file_get_contents('php://input'), true);
if (!is_array($in)) {
    echo json_encode(['success' => false, 'error' => 'Invalid JSON']);
    exit;
}

// Every table key is also its module's key (see list.php).
$tableKey = (string)($in['table_key'] ?? '');
if (in_array($tableKey, TABLE_VIEW_KEYS, true)) {
    requireModuleAccessJson($tableKey);
}

try {
    $conn = connectToDatabase();
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $id = tableViewSave($conn, (int)$_SESSION['analyst_id'], $in);
    echo json_encode(['success' => true, 'id' => $id]);
} catch (Throwable $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
