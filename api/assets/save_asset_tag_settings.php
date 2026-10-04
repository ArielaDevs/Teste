<?php
/**
 * API: save the asset tag numbering settings for one company.
 *
 * POST { tenant_id?, enabled, format, start, scope, next_number? }
 *   -> { success, settings } | { success:false, error, problems? }
 *
 * The format is checked by the same AssetTagsService::validateFormat() the
 * live preview uses, so what the preview refuses is refused here too.
 * next_number can only move the counter FORWARD: a number already handed out
 * must never be handed out again (a tag may be on a sticker on a laptop).
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

    $tenantId = getActiveTenantId($conn, $analystId);
    if (isset($data['tenant_id']) && $data['tenant_id'] !== '' && isMultiTenant($conn)) {
        $wanted = (int)$data['tenant_id'];
        if (!analystCanAccessTenant($conn, $analystId, $wanted)) {
            echo json_encode(['success' => false, 'error' => 'You do not have access to this company.']);
            exit;
        }
        $tenantId = $wanted;
    }

    $enabled = !empty($data['enabled']) ? '1' : '0';
    $format  = trim((string)($data['format'] ?? AssetTagsService::DEFAULTS['asset_tag_format']));
    $start   = max(1, (int)($data['start'] ?? 1));
    $scope   = ($data['scope'] ?? 'per_company') === 'global' ? 'global' : 'per_company';

    $problems = AssetTagsService::validateFormat($format);
    if ($problems) {
        echo json_encode(['success' => false, 'error' => implode(' ', $problems), 'problems' => $problems]);
        exit;
    }

    $perCompany = [
        'asset_tag_autogen_enabled' => $enabled,
        'asset_tag_format'          => $format,
        'asset_tag_start'           => (string)$start,
    ];
    // A company's own answer on a multi-company install; the install-wide one
    // otherwise. Scope is always install-wide: two companies counting two ways
    // in one install would make "the next number" mean different things.
    if (isMultiTenant($conn) && $tenantId !== null && $tenantId > 0) {
        foreach ($perCompany as $key => $value) setTenantSetting($conn, $tenantId, $key, $value);
    } else {
        $up = $conn->prepare(
            "INSERT INTO system_settings (setting_key, setting_value, updated_datetime)
             VALUES (?, ?, UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_datetime = UTC_TIMESTAMP()"
        );
        foreach ($perCompany as $key => $value) $up->execute([$key, $value]);
    }
    $conn->prepare(
        "INSERT INTO system_settings (setting_key, setting_value, updated_datetime)
         VALUES ('asset_tag_scope', ?, UTC_TIMESTAMP())
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_datetime = UTC_TIMESTAMP()"
    )->execute([$scope]);
    AssetTagsService::forget();

    if (isset($data['next_number']) && $data['next_number'] !== '') {
        AssetTagsService::setNextNumber($conn, $tenantId, (int)$data['next_number']);
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
