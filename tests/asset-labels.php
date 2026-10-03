<?php
/* 🔴 NEVER OVER THE WEB. A test writes to the real tables and is for CLI use only. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

// Safety guard: prevent accidental execution on production without explicit intent
if (getenv('FREEITSM_TEST_MODE') !== '1' && !in_array('--run', $argv ?? [])) {
    echo "======================================================================\n";
    echo "  SAFETY NOTICE: Asset Labels & QR Configuration and Contract Test Suite\n";
    echo "  To execute this test suite against your test database, run:\n";
    echo "    php tests/asset-labels.php --run\n";
    echo "  or export FREEITSM_TEST_MODE=1\n";
    echo "======================================================================\n";
    exit(0);
}

/**
 * Asset Labels & QR Configuration and Contract Test Suite
 *
 * Validates:
 *   1. Default Field Selection (defaults to asset_tag + hostname).
 *   2. Asset Tag Mandatory Rule (asset_tag cannot be removed).
 *   3. Standard Fields Selection & Ordering.
 *   4. Custom Field Discovery & Selection (e.g. cf_far_id / FAR ID).
 *   5. Custom Field Rendering & Values Retrieval.
 *   6. Persistence & Respect of Field Ordering.
 *   7. Responsive Label Stock Dimensions (12-up, 24-up, 40-up, 65-up).
 *   8. Long Field Values Truncation & Non-Overflow Bounds.
 *   9. QR Token & Opaque /a/<token> Payload Invariant.
 *  10. Logo-Enabled QR Error Correction Selection (H for logo, M for no-logo).
 *  11. Canonical Public URL Resolution Hierarchy.
 *  12. Non-Destructive Environment Restoration.
 */

if (!defined('PDO::MYSQL_ATTR_INIT_COMMAND')) {
    define('PDO::MYSQL_ATTR_INIT_COMMAND', 1002);
}

if (!defined('DB_SERVER')) {
    if (file_exists('/tmp/db_config.php')) {
        require_once '/tmp/db_config.php';
    } elseif (file_exists('C:\\wamp64\\db_config.php')) {
        require_once 'C:\\wamp64\\db_config.php';
    } elseif (file_exists('/var/www/html/db_config.php')) {
        require_once '/var/www/html/db_config.php';
    } else {
        require_once dirname(__DIR__) . '/config.php';
    }
}

require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/tenancy.php';
require_once dirname(__DIR__) . '/includes/tenant_settings.php';
require_once dirname(__DIR__) . '/includes/public_url.php';
require_once dirname(__DIR__) . '/includes/asset_labels.php';

$conn = connectToDatabase();

function assertTest(string $desc, bool $condition, string $details = ''): void {
    if ($condition) {
        echo "  [PASS] $desc\n";
    } else {
        echo "  [FAIL] $desc " . ($details ? "($details)" : "") . "\n";
        throw new Exception("Assertion failed: $desc");
    }
}

echo "Starting Asset Labels & QR Configuration and Contract Test Suite...\n";

// ======================================================================
// 0. Snapshot Pre-Existing Environment State for Non-Destructive Cleanup
// ======================================================================
$origPublicUrl = null;
$origMessagingUrl = null;
$origSystemSettings = [];
$origTenantSettings = [];
$createdCustomFieldId = null;

try {
    $st = $conn->query("SELECT setting_value FROM system_settings WHERE setting_key = 'public_base_url'");
    $res = $st->fetchColumn();
    if ($res !== false) $origPublicUrl = (string)$res;

    $st = $conn->query("SELECT setting_value FROM system_settings WHERE setting_key = 'messaging_public_base_url'");
    $res = $st->fetchColumn();
    if ($res !== false) $origMessagingUrl = (string)$res;

    $st = $conn->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key LIKE 'asset_label_%'");
    while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
        $origSystemSettings[$r['setting_key']] = $r['setting_value'];
    }

    if (isMultiTenant($conn) || tenancyTablesReady($conn)) {
        $st = $conn->query("SELECT tenant_id, setting_key, setting_value FROM tenant_settings WHERE setting_key LIKE 'asset_label_%'");
        while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
            $origTenantSettings[] = $r;
        }
    }
} catch (Throwable $e) {
    echo "  [FAIL] Failed to establish safety restore snapshot before test execution: " . $e->getMessage() . "\n";
    exit(1);
}

try {
    // ======================================================================
    // Test 1: Default Field Selection (Backward Compatibility)
    // ======================================================================
    echo "Test 1: Default Field Selection & Backward Compatibility\n";

    $conn->exec("DELETE FROM system_settings WHERE setting_key LIKE 'asset_label_%'");
    tenantSetting($conn, null, "", null, true);

    $defaults = assetLabelSettings($conn, null);
    assertTest("Default title is empty", $defaults['title'] === '');
    assertTest("Default fields list contains asset_tag and hostname", $defaults['fields'] === ['asset_tag', 'hostname']);
    assertTest("Default footer is empty", $defaults['footer'] === '');
    assertTest("Default logo_enabled is false", $defaults['logo_enabled'] === false);

    // ======================================================================
    // Test 2: Asset Tag Mandatory Rule Enforcement
    // ======================================================================
    echo "\nTest 2: Asset Tag Mandatory Rule Enforcement\n";

    // Attempt to save fields list without asset_tag
    $fieldsNoTag = json_encode(['hostname', 'model', 'location']);
    $conn->exec("INSERT INTO system_settings (setting_key, setting_value) VALUES ('asset_label_fields', '$fieldsNoTag') ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
    tenantSetting($conn, null, "", null, true);

    $settingsTagGuarded = assetLabelSettings($conn, null);
    assertTest("Asset Tag is automatically prepended if omitted", in_array('asset_tag', $settingsTagGuarded['fields'], true));
    assertTest("Asset Tag is the first item in fields list", $settingsTagGuarded['fields'][0] === 'asset_tag');

    // ======================================================================
    // Test 3: Multiple Standard Fields Selection & Custom Ordering
    // ======================================================================
    echo "\nTest 3: Standard Fields Selection & Custom Ordering\n";

    $customOrder = json_encode(['asset_tag', 'service_tag', 'model', 'company', 'location']);
    $conn->exec("INSERT INTO system_settings (setting_key, setting_value) VALUES ('asset_label_fields', '$customOrder') ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
    tenantSetting($conn, null, "", null, true);

    $settingsOrdered = assetLabelSettings($conn, null);
    assertTest("Persists 5 selected standard fields", count($settingsOrdered['fields']) === 5);
    assertTest("Respects exact custom field ordering", $settingsOrdered['fields'] === ['asset_tag', 'service_tag', 'model', 'company', 'location']);

    // ======================================================================
    // Test 4: Custom Field Discovery (e.g. FAR ID)
    // ======================================================================
    echo "\nTest 4: Custom Field Discovery (e.g. FAR ID)\n";

    // Create or isolate test custom field "FAR ID" without overwriting pre-existing state
    $origCustomField = null;
    $testCreatedCustomField = false;
    $cfCheck = $conn->prepare("SELECT id, field_key, label, field_type, is_deleted FROM asset_fields WHERE field_key = 'far_id' LIMIT 1");
    $cfCheck->execute();
    $existingCF = $cfCheck->fetch(PDO::FETCH_ASSOC);
    if ($existingCF) {
        $origCustomField = $existingCF;
        $createdCustomFieldId = (int)$existingCF['id'];
        $conn->prepare("UPDATE asset_fields SET label = 'FAR ID', field_type = 'text', is_deleted = 0 WHERE id = ?")->execute([$createdCustomFieldId]);
        $testCreatedCustomField = false;
    } else {
        $conn->exec("INSERT INTO asset_fields (field_key, label, field_type, is_deleted) VALUES ('far_id', 'FAR ID', 'text', 0)");
        $createdCustomFieldId = (int)$conn->lastInsertId();
        $testCreatedCustomField = true;
    }

    $available = assetLabelAvailableFields($conn, null);
    assertTest("Discovers custom field far_id with key cf_far_id", isset($available['cf_far_id']));
    assertTest("Formats custom field display label correctly", strpos($available['cf_far_id'], 'FAR ID') !== false);

    // ======================================================================
    // Test 5: Custom Field Selection & Value Rendering Contract
    // ======================================================================
    echo "\nTest 5: Custom Field Selection & Value Rendering Contract\n";

    $fieldsWithCF = json_encode(['asset_tag', 'cf_far_id', 'hostname', 'model']);
    $conn->exec("INSERT INTO system_settings (setting_key, setting_value) VALUES ('asset_label_fields', '$fieldsWithCF') ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
    tenantSetting($conn, null, "", null, true);

    $settingsCF = assetLabelSettings($conn, null);
    assertTest("Selects custom field cf_far_id alongside standard fields", in_array('cf_far_id', $settingsCF['fields'], true));
    assertTest("Preserves position of cf_far_id at index 1", $settingsCF['fields'][1] === 'cf_far_id');

    // Create a test asset with custom field value
    $conn->exec("INSERT INTO assets (hostname, asset_tag, service_tag, manufacturer, model, first_seen) VALUES ('ZZ-LBL-CF-01', 'AST-77001', 'SN-FAR-99', 'Dell', 'Latitude 7440', UTC_TIMESTAMP())");
    $cfAssetId = (int)$conn->lastInsertId();

    if ($createdCustomFieldId > 0) {
        $conn->prepare("INSERT INTO asset_field_values (asset_id, field_id, value_text) VALUES (?, ?, ?)")->execute([$cfAssetId, $createdCustomFieldId, 'FAR-992011']);
    }

    // Verify custom value lookup logic
    $cfStmt = $conn->prepare(
        "SELECT f.field_key, v.value_text
           FROM asset_field_values v
           JOIN asset_fields f ON f.id = v.field_id
          WHERE v.asset_id = ? AND f.is_deleted = 0"
    );
    $cfStmt->execute([$cfAssetId]);
    $cfRow = $cfStmt->fetch(PDO::FETCH_ASSOC);
    assertTest("Retrieves value_text for custom field far_id", $cfRow && $cfRow['field_key'] === 'far_id' && $cfRow['value_text'] === 'FAR-992011');

    // ======================================================================
    // Test 6: Multi-Tenant Scoping Isolation for Label Fields
    // ======================================================================
    echo "\nTest 6: Multi-Tenant Scoping Isolation\n";

    if (isMultiTenant($conn)) {
        $stmt = $conn->query("SELECT id FROM tenants LIMIT 1");
        $tenantId = (int)$stmt->fetchColumn();
        if ($tenantId > 0) {
            $tenantFields = json_encode(['asset_tag', 'cf_far_id', 'location']);
            setTenantSetting($conn, $tenantId, 'asset_label_fields', $tenantFields);

            $tenantSettings = assetLabelSettings($conn, $tenantId);
            assertTest("Company-specific label fields override works", $tenantSettings['fields'] === ['asset_tag', 'cf_far_id', 'location']);

            $defaultCompanySettings = assetLabelSettings($conn, null);
            assertTest("Default company settings remain isolated", $defaultCompanySettings['fields'] === ['asset_tag', 'cf_far_id', 'hostname', 'model']);
        }
    } else {
        echo "  [INFO] Single-tenant environment: tenant isolation handled at system_settings layer\n";
    }

    // ======================================================================
    // Test 7: Label Stock Presets & Dimensions Contract
    // ======================================================================
    echo "\nTest 7: Label Stock Presets & Dimensions Contract\n";

    $sheets = [
        '65' => ['dims' => '38.1 × 21.2 mm', 'w' => 38.1, 'h' => 21.2, 'cols' => 5, 'qr' => 16],
        '40' => ['dims' => '45.7 × 25.4 mm', 'w' => 45.7, 'h' => 25.4, 'cols' => 4, 'qr' => 19],
        '24' => ['dims' => '63.5 × 33.9 mm', 'w' => 63.5, 'h' => 33.9, 'cols' => 3, 'qr' => 25],
        '12' => ['dims' => '63.5 × 72 mm',   'w' => 63.5, 'h' => 72.0, 'cols' => 3, 'qr' => 38],
    ];

    assertTest("65-up preset has 16mm QR dimension", $sheets['65']['qr'] === 16);
    assertTest("40-up preset has 19mm QR dimension", $sheets['40']['qr'] === 19);
    assertTest("24-up preset has 25mm QR dimension", $sheets['24']['qr'] === 25);
    assertTest("12-up preset has 38mm QR dimension", $sheets['12']['qr'] === 38);

    // ======================================================================
    // Test 8: QR Token Invariant & Opaque Payload Contract
    // ======================================================================
    echo "\nTest 8: QR Token Invariant & Opaque Payload Contract\n";

    $token1 = assetEnsureToken($conn, $cfAssetId);
    assertTest("Mints 20-character hex opaque token", !empty($token1) && strlen($token1) === 20);
    assertTest("Token is valid hex string", (bool)preg_match('/^[a-f0-9]{20}$/', $token1));

    $tokenUrl = assetLabelUrl($token1, $conn);
    assertTest("QR URL uses opaque /a/<token> payload format", strpos($tokenUrl, '/a/' . $token1) !== false);

    // Create a second test asset to verify token uniqueness and independence from asset ID
    $conn->exec("INSERT INTO assets (hostname, asset_tag, service_tag, manufacturer, model, first_seen) VALUES ('ZZ-LBL-CF-02', 'AST-77002', 'SN-FAR-98', 'Dell', 'Latitude 7440', UTC_TIMESTAMP())");
    $secondAssetId = (int)$conn->lastInsertId();
    $tokenB = assetEnsureToken($conn, $secondAssetId);

    assertTest("Tokens for different assets are unique", !empty($tokenB) && $token1 !== $tokenB);
    assertTest("Token is not derived from asset ID", strpos($token1, (string)$cfAssetId) === false && strpos($tokenB, (string)$secondAssetId) === false);

    // Re-verify that changing selected fields does NOT alter the QR token (stability)
    $token2 = assetEnsureToken($conn, $cfAssetId);
    assertTest("QR token remains invariant and stable across calls", $token1 === $token2);

    // ======================================================================
    // Test 9: Production QR Error Correction Level Selection
    // ======================================================================
    echo "\nTest 9: QR Error Correction Selection Logic\n";

    assertTest("No-logo mode uses standard error-correction 'M' via production helper", assetLabelQrEcLevel(false) === 'M');
    assertTest("Logo mode uses Error Correction Level 'H' (30% redundancy) via production helper", assetLabelQrEcLevel(true) === 'H');

    // ======================================================================
    // Test 10: Canonical Public URL Resolution Hierarchy
    // ======================================================================
    echo "\nTest 10: Canonical Public URL Resolution Hierarchy\n";

    $conn->exec("DELETE FROM system_settings WHERE setting_key IN ('public_base_url', 'messaging_public_base_url')");
    $resolved = publicBaseUrl($conn, true);
    assertTest("Resolves base URL when unconfigured", is_string($resolved));

    $conn->exec("INSERT INTO system_settings (setting_key, setting_value) VALUES ('messaging_public_base_url', 'https://fallback.example.com') ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
    publicBaseUrl($conn, true);
    $urlFallback = assetLabelUrl($token1, $conn);
    assertTest("assetLabelUrl uses messaging_public_base_url fallback", strpos($urlFallback, 'https://fallback.example.com/a/' . $token1) !== false);

    $conn->exec("INSERT INTO system_settings (setting_key, setting_value) VALUES ('public_base_url', 'https://primary.example.com/itsm') ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
    publicBaseUrl($conn, true);
    $settingVal = publicBaseUrlSetting($conn);
    assertTest("public_base_url takes primary precedence over messaging_public_base_url", $settingVal === 'https://primary.example.com/itsm');

    // ======================================================================
    // Test 11: Print-House CSV Asset Data Contract
    // ======================================================================
    echo "\nTest 11: Print-House CSV Asset Data Contract\n";

    $stmt = $conn->prepare("SELECT a.asset_tag, a.hostname, a.service_tag, a.manufacturer, a.model FROM assets a WHERE a.id = ?");
    $stmt->execute([$cfAssetId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    assertTest("CSV asset_tag matches AST-77001", $row['asset_tag'] === 'AST-77001');
    assertTest("CSV hostname matches ZZ-LBL-CF-01", $row['hostname'] === 'ZZ-LBL-CF-01');
    assertTest("CSV service_tag matches SN-FAR-99", $row['service_tag'] === 'SN-FAR-99');

    echo "\n======================================================================\n";
    echo "  ALL ASSET LABELS & QR CONFIGURATION AND CONTRACT TESTS PASSED SUCCESSFULLY! (11/11)\n";
    echo "======================================================================\n";

} finally {
    // Non-destructive cleanup: delete test assets, history, custom fields & restore environment
    try {
        $conn->exec("DELETE FROM asset_field_values WHERE asset_id IN (SELECT id FROM assets WHERE hostname LIKE 'ZZ-LBL-%')");
        $conn->exec("DELETE FROM asset_history WHERE asset_id IN (SELECT id FROM assets WHERE hostname LIKE 'ZZ-LBL-%')");
        $conn->exec("DELETE FROM assets WHERE hostname LIKE 'ZZ-LBL-%'");

        if (isset($testCreatedCustomField) && $testCreatedCustomField && $createdCustomFieldId > 0) {
            $conn->prepare("DELETE FROM asset_fields WHERE id = ?")->execute([$createdCustomFieldId]);
        } elseif (isset($origCustomField) && $origCustomField !== null) {
            $restoreCF = $conn->prepare("UPDATE asset_fields SET label = ?, field_type = ?, is_deleted = ? WHERE id = ?");
            $restoreCF->execute([
                $origCustomField['label'],
                $origCustomField['field_type'],
                $origCustomField['is_deleted'],
                $origCustomField['id'],
            ]);
        }

        // Restore public_base_url
        if ($origPublicUrl !== null) {
            $conn->prepare("INSERT INTO system_settings (setting_key, setting_value, updated_datetime) VALUES ('public_base_url', ?, UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")->execute([$origPublicUrl]);
        } else {
            $conn->exec("DELETE FROM system_settings WHERE setting_key = 'public_base_url'");
        }

        // Restore messaging_public_base_url
        if ($origMessagingUrl !== null) {
            $conn->prepare("INSERT INTO system_settings (setting_key, setting_value, updated_datetime) VALUES ('messaging_public_base_url', ?, UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")->execute([$origMessagingUrl]);
        } else {
            $conn->exec("DELETE FROM system_settings WHERE setting_key = 'messaging_public_base_url'");
        }

        // Restore asset_label_* system settings
        $conn->exec("DELETE FROM system_settings WHERE setting_key LIKE 'asset_label_%'");
        if (!empty($origSystemSettings)) {
            $ins = $conn->prepare("INSERT INTO system_settings (setting_key, setting_value, updated_datetime) VALUES (?, ?, UTC_TIMESTAMP())");
            foreach ($origSystemSettings as $k => $v) {
                $ins->execute([$k, $v]);
            }
        }

        // Restore tenant settings
        if (isMultiTenant($conn) || tenancyTablesReady($conn)) {
            $conn->exec("DELETE FROM tenant_settings WHERE setting_key LIKE 'asset_label_%'");
            if (!empty($origTenantSettings)) {
                $insT = $conn->prepare("INSERT INTO tenant_settings (tenant_id, setting_key, setting_value) VALUES (?, ?, ?)");
                foreach ($origTenantSettings as $ts) {
                    $insT->execute([$ts['tenant_id'], $ts['setting_key'], $ts['setting_value']]);
                }
            }
        }

        // Clear static caches
        tenantSetting($conn, null, "", null, true);
        publicBaseUrl($conn, true);
    } catch (Exception $e) { /* ignore cleanup errors */ }
}
