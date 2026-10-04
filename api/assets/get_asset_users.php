<?php
/**
 * API Endpoint: Get users assigned to an asset
 * Returns list of users with assignment details
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';

header('Content-Type: application/json');

// Check if user is logged in
if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}
requireModuleAccessJson('assets');

// Get asset_id parameter
$assetId = $_GET['asset_id'] ?? null;

if (!$assetId) {
    echo json_encode(['success' => false, 'error' => 'Asset ID is required']);
    exit;
}

try {
    require_once '../../includes/tenancy.php';
    $conn = connectToDatabase();
    // Multi-tenancy: only serve child data for an asset in this analyst's companies.
    if (!analystCanAccessAsset($conn, (int)$_SESSION['analyst_id'], (int)$assetId)) {
        echo json_encode(['success' => false, 'error' => 'Asset not found']);
        exit;
    }

    // Check if users_assets table exists
    $tableCheck = $conn->prepare("SELECT COUNT(*) as cnt FROM information_schema.tables WHERE table_schema = ? AND table_name = 'users_assets'");
    $tableCheck->execute([DB_NAME]);
    $tableExists = (int)$tableCheck->fetch(PDO::FETCH_ASSOC)['cnt'] > 0;

    if (!$tableExists) {
        // Table doesn't exist yet, return empty array
        echo json_encode([
            'success' => true,
            'users' => [],
            'message' => 'users_assets table not created yet'
        ]);
        exit;
    }

    // A holder is EITHER a requester or an analyst (see AssetsService), so both
    // are LEFT JOINed and the name comes from whichever is set.
    //
    // 🔴 The INNER JOIN on `users` had to go. It was correct while every holder
    // was a requester, and the moment one is not it silently DROPS the row -
    // the asset would show as held by nobody rather than showing an error.
    //
    // holder_type lets the screen say which kind it is; `display_name` keeps its
    // old name so nothing reading this payload has to change to keep working.
    $sql = "SELECT
                ua.id as assignment_id,
                ua.user_id,
                ua.analyst_id,
                CASE WHEN ua.analyst_id IS NOT NULL THEN 'analyst' ELSE 'user' END AS holder_type,
                ua.assigned_datetime,
                ua.expected_return_date,
                ua.notes,
                COALESCE(u.display_name, ha.full_name) AS display_name,
                COALESCE(u.email, ha.email)            AS email,
                an.full_name as assigned_by_name
            FROM users_assets ua
            LEFT JOIN users u     ON ua.user_id = u.id
            LEFT JOIN analysts ha ON ua.analyst_id = ha.id
            LEFT JOIN analysts an ON ua.assigned_by_analyst_id = an.id
            WHERE ua.asset_id = ?
              -- An orphan row (the referenced person has gone) would otherwise
              -- render as a blank holder. There is no foreign key on the old
              -- data, so these exist on real installs.
              AND (u.id IS NOT NULL OR ha.id IS NOT NULL)
            ORDER BY ua.assigned_datetime DESC";

    $stmt = $conn->prepare($sql);
    $stmt->execute([$assetId]);
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'users' => $users
    ]);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>
