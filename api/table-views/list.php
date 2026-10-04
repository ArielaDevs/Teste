<?php
/**
 * API: saved views for one table (discussion #96).
 * GET ?table_key=assets&q=
 *
 * Returns the views this analyst can see — their own, ones shared with a team
 * they belong to, and public ones. Also returns the teams they could share
 * into, so the editor can offer them without a second request, and the id of
 * their default view for this table.
 *
 * ⚠️ NOT gated on one module. Four different modules run this table, so the
 * gate is the table_key itself: you are asking for views of a table you are
 * already looking at, and every view is filtered by who may see it. Gating on
 * 'assets' would refuse somebody using the tasks table.
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

try {
    $tableKey = (string)($_GET['table_key'] ?? '');
    if (!in_array($tableKey, TABLE_VIEW_KEYS, true)) {
        throw new Exception('Unknown table');
    }
    // Every table key is also its module's key, so the table you ask about is the
    // module you must have.
    requireModuleAccessJson($tableKey);

    $conn      = connectToDatabase();
    $analystId = (int)$_SESSION['analyst_id'];

    // The teams this analyst could share into. Only their own — sharing into a
    // team you are not in is not a thing that should exist, and the save path
    // refuses it too.
    $teams = [];
    $ids   = tableViewTeamIds($conn, $analystId);
    if ($ids) {
        $in   = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $conn->prepare("SELECT id, name FROM teams WHERE id IN ($in) AND is_active = 1 ORDER BY name");
        $stmt->execute($ids);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $t) {
            $teams[] = ['id' => (int)$t['id'], 'name' => $t['name']];
        }
    }

    // The personal default lives in user_preferences rather than on the view:
    // it is a fact about the READER, not the view, and two people can have
    // different defaults pointing at the same shared view.
    $defaultId = null;
    $pref = $conn->prepare("SELECT preference_value FROM user_preferences WHERE analyst_id = ? AND preference_key = ?");
    $pref->execute([$analystId, 'dtview_default_' . $tableKey]);
    $stored = $pref->fetchColumn();
    if ($stored !== false && $stored !== null && $stored !== '') {
        $defaultId = (int)$stored;
    }

    echo json_encode([
        'success'    => true,
        'views'      => tableViewList($conn, $analystId, $tableKey, trim((string)($_GET['q'] ?? ''))),
        'teams'      => $teams,
        'default_id' => $defaultId,
    ]);
} catch (Throwable $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
