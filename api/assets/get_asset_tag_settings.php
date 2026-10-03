<?php
/**
 * API Endpoint: Get asset tag auto-generation settings & sequence status.
 *
 * GET /api/assets/get_asset_tag_settings.php?tenant_id=...
 */

session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/rbac.php';
require_once '../../includes/tenancy.php';
require_once '../../includes/capabilities.php';
require_once '../../includes/services/asset_tags.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

requireModuleAccessJson('assets');
requireCapabilityJson(Cap::ASSETS_TAGS);

try {
    $conn = connectToDatabase();
    $analystId = (int)$_SESSION['analyst_id'];

    $tenantId = null;
    if (isset($_GET['tenant_id']) && $_GET['tenant_id'] !== '' && isMultiTenant($conn)) {
        $wanted = (int)$_GET['tenant_id'];
        if (!analystCanAccessTenant($conn, $analystId, $wanted)) {
            echo json_encode(['success' => false, 'error' => 'You do not have access to this company.']);
            exit;
        }
        $tenantId = $wanted;
    } else {
        $tenantId = getActiveTenantId($conn, $analystId);
    }

    $config = AssetTagsService::getAutogenConfig($conn, $tenantId);

    echo json_encode([
        'success'  => true,
        'settings' => $config,
    ]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
