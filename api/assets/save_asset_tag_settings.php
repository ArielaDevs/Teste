<?php
/**
 * API Endpoint: Save asset tag auto-generation settings & sequence counter.
 *
 * POST /api/assets/save_asset_tag_settings.php
 */

session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/rbac.php';
require_once '../../includes/tenancy.php';
require_once '../../includes/tenant_settings.php';
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

    $data = json_decode(file_get_contents('php://input'), true) ?: [];

    $tenantId = null;
    if (isset($data['tenant_id']) && $data['tenant_id'] !== '' && isMultiTenant($conn)) {
        $wanted = (int)$data['tenant_id'];
        if (!analystCanAccessTenant($conn, $analystId, $wanted)) {
            echo json_encode(['success' => false, 'error' => 'You do not have access to this company.']);
            exit;
        }
        $tenantId = $wanted;
    } else {
        $tenantId = getActiveTenantId($conn, $analystId);
    }

    $enabled = !empty($data['enabled']) ? '1' : '0';
    $prefix  = trim((string)($data['prefix'] ?? 'AST-'));
    $suffix  = trim((string)($data['suffix'] ?? ''));
    $padding = max(1, min(12, (int)($data['padding'] ?? 5)));
    $initNum = max(1, (int)($data['initial_number'] ?? 1));

    // Length and character validation for prefix/suffix
    if (mb_strlen($prefix) > 20) {
        throw new Exception('Tag prefix cannot exceed 20 characters.');
    }
    if (mb_strlen($suffix) > 20) {
        throw new Exception('Tag suffix cannot exceed 20 characters.');
    }
    if (!preg_match('/^[A-Za-z0-9_\-\.\/]*$/', $prefix)) {
        throw new Exception('Tag prefix contains invalid characters. Use letters, numbers, hyphens, and underscores.');
    }
    if (!preg_match('/^[A-Za-z0-9_\-\.\/]*$/', $suffix)) {
        throw new Exception('Tag suffix contains invalid characters. Use letters, numbers, hyphens, and underscores.');
    }

    // Save configuration settings
    if ($tenantId !== null && $tenantId > 0 && isMultiTenant($conn)) {
        setTenantSetting($conn, $tenantId, AssetTagsService::KEY_AUTOGEN_ENABLED, $enabled);
        setTenantSetting($conn, $tenantId, AssetTagsService::KEY_PREFIX, $prefix);
        setTenantSetting($conn, $tenantId, AssetTagsService::KEY_SUFFIX, $suffix);
        setTenantSetting($conn, $tenantId, AssetTagsService::KEY_PADDING, (string)$padding);
        setTenantSetting($conn, $tenantId, AssetTagsService::KEY_INITIAL_NUMBER, (string)$initNum);
    } else {
        $stmt = $conn->prepare(
            "INSERT INTO system_settings (setting_key, setting_value, updated_datetime)
             VALUES (?, ?, UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_datetime = UTC_TIMESTAMP()"
        );
        $stmt->execute([AssetTagsService::KEY_AUTOGEN_ENABLED, $enabled]);
        $stmt->execute([AssetTagsService::KEY_PREFIX, $prefix]);
        $stmt->execute([AssetTagsService::KEY_SUFFIX, $suffix]);
        $stmt->execute([AssetTagsService::KEY_PADDING, (string)$padding]);
        $stmt->execute([AssetTagsService::KEY_INITIAL_NUMBER, (string)$initNum]);
    }

    // If next_number explicitly passed, update the sequence counter
    if (isset($data['next_number']) && $data['next_number'] !== '') {
        $nextNum = max(1, (int)$data['next_number']);
        AssetTagsService::setNextSequenceNumber($conn, $tenantId, $nextNum);
    }

    $updatedConfig = AssetTagsService::getAutogenConfig($conn, $tenantId);

    echo json_encode([
        'success'  => true,
        'settings' => $updatedConfig,
    ]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
