<?php
/**
 * API: Watchtower Dashboard — Unified attention summary across all modules
 * GET — Returns attention items from every module in a single response
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/watchtower_queries.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}
requireModuleAccessJson('watchtower');

try {
    $conn = connectToDatabase();
    $analystId = (int)$_SESSION['analyst_id'];

    // Whose work (#58). ?scope= wins for a single request so the toggle feels
    // instant; otherwise the analyst's remembered choice. Anything unrecognised
    // falls through to 'all', which is exactly the old behaviour.
    $scope = isset($_GET['scope']) && wtScopeIsValid((string)$_GET['scope'])
        ? (string)$_GET['scope']
        : wtScopeFor($conn, $analystId);

    $data = getWatchtowerData($conn, $analystId, $scope);
    $data['scope']            = $scope;
    $data['impersonal_cards'] = wtImpersonalCards();
    $data['impersonal_mode']  = wtImpersonalOnMine($conn, $analystId);

    echo json_encode(array_merge(
        ['success' => true, 'generated_at' => gmdate('Y-m-d\TH:i:s\Z')],
        $data
    ));

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
