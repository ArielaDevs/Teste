<?php
/**
 * API Endpoint: Get physical asset label & QR branding configuration.
 *
 * GET /api/assets/get_asset_label_settings.php
 */

session_start(['read_and_close' => true]);

require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/rbac.php';
require_once '../../includes/tenancy.php';
require_once '../../includes/tenant_settings.php';
require_once '../../includes/capabilities.php';
require_once '../../includes/asset_labels.php';

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

    $settings = assetLabelSettings($conn, $tenantId);
    $availableFields = assetLabelAvailableFields($conn, $tenantId);
    $printFields = assetLabelPrintFields($conn, $tenantId);

    echo json_encode([
        'success'          => true,
        'settings'         => $settings,
        'available_fields' => $availableFields,
        'print_fields'     => $printFields,
    ]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
