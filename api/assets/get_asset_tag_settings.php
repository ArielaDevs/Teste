<?php
/**
 * API: the asset tag numbering settings for one company, and the number the
 * next generated tag will carry.
 *
 * GET ?tenant_id= -> { success, settings: {enabled, format, start, scope, next_number, examples} }
 *
 * Enabled, format and start may differ per company; scope is install-wide.
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

    $tenantId = getActiveTenantId($conn, $analystId);
    if (isset($_GET['tenant_id']) && $_GET['tenant_id'] !== '' && isMultiTenant($conn)) {
        $wanted = (int)$_GET['tenant_id'];
        if (!analystCanAccessTenant($conn, $analystId, $wanted)) {
            echo json_encode(['success' => false, 'error' => 'You do not have access to this company.']);
            exit;
        }
        $tenantId = $wanted;
    }

    $cfg  = AssetTagsService::config($conn, $tenantId);
    $next = AssetTagsService::nextNumber($conn, $tenantId);

    echo json_encode([
        'success'  => true,
        'settings' => [
            'enabled'     => $cfg['asset_tag_autogen_enabled'] === '1',
            'format'      => $cfg['asset_tag_format'],
            'start'       => (int)$cfg['asset_tag_start'],
            'scope'       => $cfg['asset_tag_scope'],
            'next_number' => $next,
            'examples'    => AssetTagsService::preview($cfg, $next),
        ],
    ]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
