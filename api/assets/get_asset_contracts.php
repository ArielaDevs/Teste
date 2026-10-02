<?php
/**
 * API: Contracts covering an asset (discussion #106).
 * GET ?asset_id=
 *
 * The other half of the feature, and the half that pays for it: standing on a
 * handset and asking "what agreement is this on, and when can we get out of
 * it?" is the question the requester actually had.
 *
 * Gated on the ASSETS module, because this is the asset's own page — but the
 * contract titles are only returned to somebody who may see the contracts
 * module, so an asset manager without it gets an empty panel rather than a list
 * of agreements they cannot open.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/contract_assets.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}
requireModuleAccessJson('assets');

try {
    $assetId = (int)($_GET['asset_id'] ?? 0);
    if ($assetId <= 0) {
        throw new Exception('asset_id is required');
    }

    $conn      = connectToDatabase();
    $analystId = (int)$_SESSION['analyst_id'];

    if (!contractAssetCanReach($conn, $analystId, $assetId)) {
        throw new Exception('No such asset');
    }

    $permitted = analystCanAccessModule($conn, $analystId, 'contracts');

    echo json_encode([
        'success'   => true,
        'permitted' => $permitted,
        'contracts' => $permitted ? contractsForAsset($conn, $assetId, (int)$_SESSION['analyst_id']) : [],
    ]);
} catch (Throwable $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
