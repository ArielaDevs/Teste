<?php
/**
 * API Endpoint: Save physical asset label & QR branding configuration.
 *
 * POST /api/assets/save_asset_label_settings.php
 */

session_start(['read_and_close' => true]);

require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/rbac.php';
require_once '../../includes/tenancy.php';
require_once '../../includes/tenant_settings.php';
require_once '../../includes/capabilities.php';
require_once '../../includes/uploads.php';
require_once '../../includes/branding.php';
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

    $isMultipart = !empty($_POST) || isset($_FILES['logo']);
    $data = $isMultipart ? $_POST : (json_decode(file_get_contents('php://input'), true) ?: []);

    $tenantId = null;
    if (isset($data['tenant_id']) && $data['tenant_id'] !== '' && isMultiTenant($conn)) {
        $wanted = (int)$data['tenant_id'];
        if (analystCanAccessTenant($conn, $analystId, $wanted)) {
            $tenantId = $wanted;
        }
    } else {
        $tenantId = getActiveTenantId($conn, $analystId);
    }

    $title        = trim((string)($data['title'] ?? ''));
    $footer       = trim((string)($data['footer'] ?? ''));
    $logoEnabled  = !empty($data['logo_enabled']) ? '1' : '0';
    $showFieldLabels = !empty($data['show_field_labels']) ? '1' : '0';

    if (mb_strlen($title) > 100) {
        throw new Exception('Header text cannot exceed 100 characters.');
    }
    if (mb_strlen($footer) > 100) {
        throw new Exception('Footer text cannot exceed 100 characters.');
    }

    $available = assetLabelAvailableFields($conn, $tenantId);

    // Parse ordered fields input
    $rawFields = $data['fields'] ?? null;
    $fields = [];
    if (is_array($rawFields)) {
        $fields = $rawFields;
    } elseif (is_string($rawFields) && $rawFields !== '') {
        $decoded = json_decode($rawFields, true);
        if (is_array($decoded)) {
            $fields = $decoded;
        } else {
            $fields = array_map('trim', explode(',', $rawFields));
        }
    }

    // Filter to valid keys
    $validFields = [];
    foreach ($fields as $f) {
        $f = trim((string)$f);
        if ($f !== '' && isset($available[$f]) && !in_array($f, $validFields, true)) {
            $validFields[] = $f;
        }
    }

    // Asset Tag is mandatory: ensure present
    if (!in_array('asset_tag', $validFields, true)) {
        array_unshift($validFields, 'asset_tag');
    }

    // Logo management: upload / remove / preserve (follows identical rules to system branding)
    $removeLogo = !empty($data['remove_logo']);
    $hasFile = isset($_FILES['logo']) && is_array($_FILES['logo']) && $_FILES['logo']['error'] !== UPLOAD_ERR_NO_FILE;
    $currentCustom = (string)tenantSetting($conn, $tenantId, KEY_LABEL_LOGO_PATH, '');

    if ($hasFile) {
        $uploadDir = __DIR__ . '/../../system/uploads/branding';
        uploadPrepareWebServableDir($uploadDir);
        if (!is_dir($uploadDir) || !is_writable($uploadDir)) {
            throw new Exception('The branding upload folder is not writable.');
        }

        // Store new file following identical 2MB and image whitelist rules as branding settings
        $stored = uploadStoreFile($_FILES['logo'], $uploadDir, UPLOAD_TYPES_IMAGE, 2 * 1024 * 1024);
        $newLogoPath = 'system/uploads/branding/' . $stored['stored_name'];

        // Clean up previous custom logo if it was an uploaded file and NOT the system branding logo
        if ($currentCustom !== '' && brandingPathIsSafe($currentCustom)) {
            $prevFile = __DIR__ . '/../../' . $currentCustom;
            if (file_exists($prevFile)) {
                @unlink($prevFile);
            }
        }
        $logoPath = $newLogoPath;
    } elseif ($removeLogo) {
        if ($currentCustom !== '' && brandingPathIsSafe($currentCustom)) {
            $prevFile = __DIR__ . '/../../' . $currentCustom;
            if (file_exists($prevFile)) {
                @unlink($prevFile);
            }
        }
        $logoPath = '';
    } else {
        $logoPath = $currentCustom;
    }

    $jsonFields = json_encode($validFields);

    // Persist settings (company-scoped if tenant context, else system_settings)
    if ($tenantId !== null && $tenantId > 0 && isMultiTenant($conn)) {
        setTenantSetting($conn, $tenantId, KEY_LABEL_TITLE, $title);
        setTenantSetting($conn, $tenantId, KEY_LABEL_FIELDS, $jsonFields);
        setTenantSetting($conn, $tenantId, KEY_LABEL_FOOTER, $footer);
        setTenantSetting($conn, $tenantId, KEY_LABEL_LOGO_ENABLED, $logoEnabled);
        setTenantSetting($conn, $tenantId, KEY_LABEL_LOGO_PATH, $logoPath);
        setTenantSetting($conn, $tenantId, KEY_LABEL_SHOW_FIELD_LABELS, $showFieldLabels);
    } else {
        $upsert = function(PDO $conn, string $key, ?string $val): void {
            $stmt = $conn->prepare(
                "INSERT INTO system_settings (setting_key, setting_value, updated_datetime) 
                 VALUES (?, ?, UTC_TIMESTAMP()) 
                 ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_datetime = UTC_TIMESTAMP()"
            );
            $stmt->execute([$key, $val]);
        };
        $upsert($conn, KEY_LABEL_TITLE, $title);
        $upsert($conn, KEY_LABEL_FIELDS, $jsonFields);
        $upsert($conn, KEY_LABEL_FOOTER, $footer);
        $upsert($conn, KEY_LABEL_LOGO_ENABLED, $logoEnabled);
        $upsert($conn, KEY_LABEL_LOGO_PATH, $logoPath);
        $upsert($conn, KEY_LABEL_SHOW_FIELD_LABELS, $showFieldLabels);
    }

    // Clear static memory cache
    tenantSetting($conn, null, "", null, true);

    $updated = assetLabelSettings($conn, $tenantId);
    $availableFields = assetLabelAvailableFields($conn, $tenantId);
    $printFields = assetLabelPrintFields($conn, $tenantId);

    echo json_encode([
        'success'          => true,
        'settings'         => $updated,
        'available_fields' => $availableFields,
        'print_fields'     => $printFields,
    ]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
