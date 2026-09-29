<?php
/* 🔴 NEVER OVER THE WEB. A test writes to the real tables and is for CLI use only. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

// Safety guard: prevent accidental execution on production without explicit intent
if (getenv('FREEITSM_TEST_MODE') !== '1' && !in_array('--run', $argv ?? [])) {
    echo "======================================================================\n";
    echo "  SAFETY NOTICE: Asset Reconciliation Test Suite\n";
    echo "  To execute this test suite against your test database, run:\n";
    echo "    php tests/asset-reconciliation.php --run\n";
    echo "  or export FREEITSM_TEST_MODE=1\n";
    echo "======================================================================\n";
    exit(0);
}

/**
 * Asset Reconciliation & Stable Device Identity Test Suite
 *
 * Exercises the connector-independent device reconciliation hierarchy:
 *   Tier 1: Explicit authoritative connector link
 *   Tier 2: Clean hardware serial_number / service_tag with ambiguity guard
 *   Tier 3: Hostname fallback
 *   Tier 4: Genuine new device
 *
 * Plus:
 *   - Hostname collision protection (skips mutation and sets hostname_conflict = true)
 *   - Configurable ignored serials from system_settings (Discovery & Reconciliation)
 *   - Cross-tenant scoping isolation across connectors
 *   - Intune connector-to-company scoping and rename cycles
 *   - Atomic audit trail in asset_history with analyst_id = NULL
 */

if (!defined('DB_SERVER')) {
    if (file_exists('/tmp/db_config.php')) {
        require_once '/tmp/db_config.php';
    } elseif (file_exists('C:\\wamp64\\db_config.php')) {
        require_once 'C:\\wamp64\\db_config.php';
    } elseif (file_exists('/var/www/html/db_config.php')) {
        require_once '/var/www/html/db_config.php';
    }
}

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/services/assets.php';
require_once __DIR__ . '/../includes/intune.php';

$pass = 0;
$fail = 0;

function ok(string $what, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) {
        $pass++;
        echo "  PASS  {$what}\n";
    } else {
        $fail++;
        echo "  FAIL  {$what}" . ($detail ? "  <- {$detail}" : '') . "\n";
    }
}

echo "\nAsset Reconciliation & Stable Device Identity\n" . str_repeat('=', 70) . "\n";

$conn = connectToDatabase();
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Clean up any stale fixtures from previous runs before starting
$conn->exec("DELETE FROM intune_devices WHERE intune_id LIKE 'INTUNE-DEV-%' OR intune_id LIKE 'INTUNE-STALE-%'");
$conn->exec("DELETE FROM asset_history WHERE asset_id IN (SELECT id FROM assets WHERE hostname LIKE 'GAL-LTW-%' OR hostname LIKE 'ZZ-%' OR hostname LIKE 'COLLIDE-%' OR hostname LIKE 'DEFCO-%' OR hostname LIKE 'COMP%' OR hostname LIKE 'CLIENTX-%')");
$conn->exec("DELETE FROM assets WHERE hostname LIKE 'GAL-LTW-%' OR hostname LIKE 'ZZ-%' OR hostname LIKE 'COLLIDE-%' OR hostname LIKE 'DEFCO-%' OR hostname LIKE 'COMP%' OR hostname LIKE 'CLIENTX-%'");
$conn->exec("DELETE FROM tenants WHERE name LIKE 'ZZ-Tenant-%' OR name LIKE 'MSP-%'");

// Test fixtures tracking for clean teardown
$cleanupAssetIds = [];
$cleanupIntuneIds = [];
$cleanupTenantIds = [];

try {
    // -------------------------------------------------------------------------
    // Unit tests: isUsableServiceTag
    // -------------------------------------------------------------------------
    echo "\nUnit Tests: isUsableServiceTag & Normalization\n";
    
    ok("Rejects null serial", !AssetsService::isUsableServiceTag(null));
    ok("Rejects empty string", !AssetsService::isUsableServiceTag(""));
    ok("Rejects whitespace-only string", !AssetsService::isUsableServiceTag("   "));
    ok("Rejects default 'TO BE FILLED BY O.E.M.' (case-insensitive)", !AssetsService::isUsableServiceTag("to be filled by o.e.m."));
    ok("Rejects default 'DEFAULT STRING'", !AssetsService::isUsableServiceTag("Default String"));
    ok("Rejects default 'NONE'", !AssetsService::isUsableServiceTag("none"));
    ok("Rejects default 'SYSTEM SERIAL NUMBER'", !AssetsService::isUsableServiceTag("System Serial Number"));
    ok("Rejects default 'NOT SPECIFIED'", !AssetsService::isUsableServiceTag("not specified"));
    ok("Rejects default '123456789'", !AssetsService::isUsableServiceTag("123456789"));
    ok("Accepts short 2-character valid serial", AssetsService::isUsableServiceTag("A1"));
    ok("Accepts valid manufacturer serial", AssetsService::isUsableServiceTag("C02G90XXMD6M"));
    ok("Accepts serial with hyphens/dots", AssetsService::isUsableServiceTag("CN-0M90R1.74261"));

    // -------------------------------------------------------------------------
    // Test A: Existing authoritative connector relationship wins (Tier 1)
    // -------------------------------------------------------------------------
    echo "\nTest A: Existing authoritative connector relationship wins (Tier 1)\n";

    $stmt = $conn->prepare("INSERT INTO assets (hostname, service_tag, first_seen, last_seen) VALUES ('ZZ-ORIG-HOST', 'ZZ-ORIG-SN', UTC_TIMESTAMP(), UTC_TIMESTAMP())");
    $stmt->execute();
    $assetA = (int)$conn->lastInsertId();
    $cleanupAssetIds[] = $assetA;

    $resA = AssetsService::reconcileAsset(
        $conn,
        [
            'asset_id'    => $assetA,
            'service_tag' => 'COMPLETELY-DIFF-SERIAL',
            'hostname'    => 'ZZ-NEW-HOST-A',
        ],
        null,
        'Intune',
        true // Authoritative explicit link
    );

    ok("Test A: Matched by explicit_link", $resA['matched_by'] === 'explicit_link');
    ok("Test A: Resolved to correct asset_id", $resA['asset_id'] === $assetA);
    ok("Test A: Hostname was updated", $resA['hostname_updated'] === true);
    ok("Test A: No collision", $resA['hostname_conflict'] === false);

    $currHostA = $conn->query("SELECT hostname FROM assets WHERE id = {$assetA}")->fetchColumn();
    ok("Test A: Database hostname changed to ZZ-NEW-HOST-A", $currHostA === 'ZZ-NEW-HOST-A');

    // -------------------------------------------------------------------------
    // Test B: Unique serial beats changed hostname & writes atomic audit (Tier 2)
    // -------------------------------------------------------------------------
    echo "\nTest B: Unique serial beats changed hostname & writes atomic audit (Tier 2)\n";

    $stmt = $conn->prepare("INSERT INTO assets (hostname, service_tag, first_seen, last_seen) VALUES ('GAL-LTW-147', 'SERIAL-B-123', UTC_TIMESTAMP(), UTC_TIMESTAMP())");
    $stmt->execute();
    $assetB = (int)$conn->lastInsertId();
    $cleanupAssetIds[] = $assetB;

    $resB = AssetsService::reconcileAsset(
        $conn,
        [
            'service_tag' => 'SERIAL-B-123',
            'hostname'    => 'GAL-LTW-201',
        ],
        null,
        'system-info',
        false
    );

    ok("Test B: Matched by service_tag", $resB['matched_by'] === 'service_tag');
    ok("Test B: Resolved to existing asset", $resB['asset_id'] === $assetB);
    ok("Test B: Hostname updated flag set", $resB['hostname_updated'] === true);
    ok("Test B: No collision", $resB['hostname_conflict'] === false);

    $currHostB = $conn->query("SELECT hostname FROM assets WHERE id = {$assetB}")->fetchColumn();
    ok("Test B: Database hostname mutated to GAL-LTW-201", $currHostB === 'GAL-LTW-201');

    // Verify atomic audit entry in asset_history
    $auditStmt = $conn->prepare("SELECT analyst_id, field_name, old_value, new_value FROM asset_history WHERE asset_id = ? AND field_name = 'hostname' ORDER BY id DESC LIMIT 1");
    $auditStmt->execute([$assetB]);
    $auditRow = $auditStmt->fetch(PDO::FETCH_ASSOC);

    ok("Test B: Audit entry exists in asset_history", (bool)$auditRow);
    ok("Test B: Audit analyst_id is NULL (automated attribution)", $auditRow && $auditRow['analyst_id'] === null);
    ok("Test B: Audit old_value is GAL-LTW-147", $auditRow && $auditRow['old_value'] === 'GAL-LTW-147');
    ok("Test B: Audit new_value is GAL-LTW-201", $auditRow && $auditRow['new_value'] === 'GAL-LTW-201');

    // -------------------------------------------------------------------------
    // Test C: Duplicate serial does NOT guess (Ambiguity Guard)
    // -------------------------------------------------------------------------
    echo "\nTest C: Duplicate serial does NOT guess (Ambiguity Guard)\n";

    $stmt = $conn->prepare("INSERT INTO assets (hostname, service_tag, first_seen, last_seen) VALUES ('ZZ-DUP-1', 'SERIAL-AMBIG-99', UTC_TIMESTAMP(), UTC_TIMESTAMP())");
    $stmt->execute();
    $assetC1 = (int)$conn->lastInsertId();
    $cleanupAssetIds[] = $assetC1;

    $stmt = $conn->prepare("INSERT INTO assets (hostname, service_tag, first_seen, last_seen) VALUES ('ZZ-DUP-2', 'SERIAL-AMBIG-99', UTC_TIMESTAMP(), UTC_TIMESTAMP())");
    $stmt->execute();
    $assetC2 = (int)$conn->lastInsertId();
    $cleanupAssetIds[] = $assetC2;

    $resC = AssetsService::reconcileAsset(
        $conn,
        [
            'service_tag' => 'SERIAL-AMBIG-99',
            'hostname'    => 'ZZ-UNKNOWN-NAME',
        ],
        null,
        'system-info',
        false
    );

    ok("Test C: Refused to silently pick one", $resC['asset_id'] === null);
    ok("Test C: Flagged as ambiguous", $resC['ambiguous'] === true);
    ok("Test C: Matched by is none", $resC['matched_by'] === 'none');

    // -------------------------------------------------------------------------
    // Test D: Hostname collision protection
    // -------------------------------------------------------------------------
    echo "\nTest D: Hostname collision protection\n";

    $stmt = $conn->prepare("INSERT INTO assets (hostname, service_tag, first_seen, last_seen) VALUES ('COLLIDE-HOST-A', 'SERIAL-COLLIDE-A', UTC_TIMESTAMP(), UTC_TIMESTAMP())");
    $stmt->execute();
    $assetColA = (int)$conn->lastInsertId();
    $cleanupAssetIds[] = $assetColA;

    $stmt = $conn->prepare("INSERT INTO assets (hostname, service_tag, first_seen, last_seen) VALUES ('COLLIDE-HOST-B', 'SERIAL-COLLIDE-B', UTC_TIMESTAMP(), UTC_TIMESTAMP())");
    $stmt->execute();
    $assetColB = (int)$conn->lastInsertId();
    $cleanupAssetIds[] = $assetColB;

    // Incoming payload for Asset A claims its new hostname is COLLIDE-HOST-B (which already belongs to Asset B)
    $resCol = AssetsService::reconcileAsset(
        $conn,
        [
            'service_tag' => 'SERIAL-COLLIDE-A',
            'hostname'    => 'COLLIDE-HOST-B',
        ],
        null,
        'system-info',
        false
    );

    ok("Test D: Reconciles identity to Asset A", $resCol['asset_id'] === $assetColA);
    ok("Test D: Hostname mutation was prevented (hostname_updated = false)", $resCol['hostname_updated'] === false);
    ok("Test D: Hostname conflict flagged (hostname_conflict = true)", $resCol['hostname_conflict'] === true);

    $hostAStill = $conn->query("SELECT hostname FROM assets WHERE id = {$assetColA}")->fetchColumn();
    ok("Test D: Asset A hostname remained COLLIDE-HOST-A", $hostAStill === 'COLLIDE-HOST-A');

    // -------------------------------------------------------------------------
    // Test E: Hostname fallback (Tier 3)
    // -------------------------------------------------------------------------
    echo "\nTest E: Hostname fallback when serial missing/unusable (Tier 3)\n";

    $stmt = $conn->prepare("INSERT INTO assets (hostname, service_tag, first_seen, last_seen) VALUES ('ZZ-HOST-ONLY', 'SERIAL-E', UTC_TIMESTAMP(), UTC_TIMESTAMP())");
    $stmt->execute();
    $assetE = (int)$conn->lastInsertId();
    $cleanupAssetIds[] = $assetE;

    $resE = AssetsService::reconcileAsset(
        $conn,
        [
            'service_tag' => '', // missing serial
            'hostname'    => 'ZZ-HOST-ONLY',
        ],
        null,
        'system-info',
        false
    );

    ok("Test E: Matched by hostname", $resE['matched_by'] === 'hostname');
    ok("Test E: Resolved to correct asset", $resE['asset_id'] === $assetE);
    ok("Test E: Hostname not mutated", $resE['hostname_updated'] === false);

    // -------------------------------------------------------------------------
    // Test F: Generic serial ignored & falls to hostname
    // -------------------------------------------------------------------------
    echo "\nTest F: Generic serial ignored & falls to hostname\n";

    $stmt = $conn->prepare("INSERT INTO assets (hostname, service_tag, first_seen, last_seen) VALUES ('ZZ-GENERIC-HOST', 'REAL-SERIAL-F', UTC_TIMESTAMP(), UTC_TIMESTAMP())");
    $stmt->execute();
    $assetF = (int)$conn->lastInsertId();
    $cleanupAssetIds[] = $assetF;

    $resF = AssetsService::reconcileAsset(
        $conn,
        [
            'service_tag' => 'To Be Filled By O.E.M.',
            'hostname'    => 'ZZ-GENERIC-HOST',
        ],
        null,
        'system-info',
        false
    );

    ok("Test F: Generic serial ignored, matched by hostname", $resF['matched_by'] === 'hostname');
    ok("Test F: Resolved to Asset F", $resF['asset_id'] === $assetF);

    // -------------------------------------------------------------------------
    // Test G: Cross-tenant protection
    // -------------------------------------------------------------------------
    echo "\nTest G: Cross-tenant protection\n";

    $conn->exec("INSERT INTO tenants (name, is_active, created_datetime) VALUES ('ZZ-Tenant-1', 1, UTC_TIMESTAMP())");
    $tenant1 = (int)$conn->lastInsertId();
    $cleanupTenantIds[] = $tenant1;

    $conn->exec("INSERT INTO tenants (name, is_active, created_datetime) VALUES ('ZZ-Tenant-2', 1, UTC_TIMESTAMP())");
    $tenant2 = (int)$conn->lastInsertId();
    $cleanupTenantIds[] = $tenant2;

    $stmt = $conn->prepare("INSERT INTO assets (hostname, service_tag, tenant_id, first_seen, last_seen) VALUES ('ZZ-TEN1-PC', 'SHARED-SERIAL-G', ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())");
    $stmt->execute([$tenant1]);
    $assetG1 = (int)$conn->lastInsertId();
    $cleanupAssetIds[] = $assetG1;

    $stmt = $conn->prepare("INSERT INTO assets (hostname, service_tag, tenant_id, first_seen, last_seen) VALUES ('ZZ-TEN2-PC', 'SHARED-SERIAL-G', ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())");
    $stmt->execute([$tenant2]);
    $assetG2 = (int)$conn->lastInsertId();
    $cleanupAssetIds[] = $assetG2;

    $resG_Ten2 = AssetsService::reconcileAsset(
        $conn,
        ['service_tag' => 'SHARED-SERIAL-G', 'hostname' => 'NEW-NAME'],
        $tenant2,
        'system-info'
    );
    ok("Test G: Tenant 2 inquiry resolves exclusively to Asset G2", $resG_Ten2['asset_id'] === $assetG2);

    $resG_Ten1 = AssetsService::reconcileAsset(
        $conn,
        ['service_tag' => 'SHARED-SERIAL-G', 'hostname' => 'NEW-NAME'],
        $tenant1,
        'system-info'
    );
    ok("Test G: Tenant 1 inquiry resolves exclusively to Asset G1", $resG_Ten1['asset_id'] === $assetG1);

    // -------------------------------------------------------------------------
    // Test H: Intune full rename cycle
    // -------------------------------------------------------------------------
    echo "\nTest H: Intune full rename cycle\n";

    $stmt = $conn->prepare("INSERT INTO assets (hostname, service_tag, first_seen, last_seen) VALUES ('GAL-LTW-147', 'SERIAL-INTUNE-H', UTC_TIMESTAMP(), UTC_TIMESTAMP())");
    $stmt->execute();
    $assetH = (int)$conn->lastInsertId();
    $cleanupAssetIds[] = $assetH;

    $stmt = $conn->prepare("INSERT INTO intune_devices (intune_id, asset_id, device_name, serial_number) VALUES ('INTUNE-DEV-H-1', ?, 'GAL-LTW-147', 'SERIAL-INTUNE-H')");
    $stmt->execute([$assetH]);
    $intuneDevId = (int)$conn->lastInsertId();
    $cleanupIntuneIds[] = $intuneDevId;

    $conn->exec("UPDATE intune_devices SET device_name = 'GAL-LTW-H-RENAMED' WHERE id = {$intuneDevId}");
    $assetsBefore = (int)$conn->query("SELECT COUNT(*) FROM assets")->fetchColumn();

    $syncResult = intuneLinkDevicesToAssets($conn, null);

    $assetsAfter = (int)$conn->query("SELECT COUNT(*) FROM assets")->fetchColumn();
    $intuneAssetId = (int)$conn->query("SELECT asset_id FROM intune_devices WHERE id = {$intuneDevId}")->fetchColumn();
    $updatedHostnameH = $conn->query("SELECT hostname FROM assets WHERE id = {$assetH}")->fetchColumn();

    ok("Test H: Intune asset_id remains {$assetH}", $intuneAssetId === $assetH);
    ok("Test H: No duplicate stub asset created (count unchanged)", $assetsBefore === $assetsAfter);
    ok("Test H: Asset hostname updated to GAL-LTW-H-RENAMED", $updatedHostnameH === 'GAL-LTW-H-RENAMED');

    // -------------------------------------------------------------------------
    // Test I: Intune Company / Tenant Scoping
    // -------------------------------------------------------------------------
    echo "\nTest I: Intune Company / Tenant Scoping\n";

    $conn->exec("INSERT INTO tenants (name, is_active, created_datetime) VALUES ('MSP-Client-X', 1, UTC_TIMESTAMP())");
    $tenantX = (int)$conn->lastInsertId();
    $cleanupTenantIds[] = $tenantX;

    // Device in Tenant X
    $stmt = $conn->prepare("INSERT INTO assets (hostname, service_tag, tenant_id, first_seen, last_seen) VALUES ('CLIENTX-PC-01', 'SERIAL-INTUNE-MSP-X', ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())");
    $stmt->execute([$tenantX]);
    $assetX = (int)$conn->lastInsertId();
    $cleanupAssetIds[] = $assetX;

    // Incoming unlinked Intune device matching Tenant X serial
    $stmt = $conn->prepare("INSERT INTO intune_devices (intune_id, asset_id, device_name, serial_number) VALUES ('INTUNE-DEV-X-99', NULL, 'CLIENTX-PC-01-NEWNAME', 'SERIAL-INTUNE-MSP-X')");
    $stmt->execute();
    $intuneDevX = (int)$conn->lastInsertId();
    $cleanupIntuneIds[] = $intuneDevX;

    // Run sync explicitly scoped to Tenant X
    intuneLinkDevicesToAssets($conn, $tenantX);

    $linkedAssetIdX = (int)$conn->query("SELECT asset_id FROM intune_devices WHERE id = {$intuneDevX}")->fetchColumn();
    $updatedHostX = $conn->query("SELECT hostname FROM assets WHERE id = {$assetX}")->fetchColumn();
    $assetXTenant = $conn->query("SELECT tenant_id FROM assets WHERE id = {$assetX}")->fetchColumn();

    ok("Test I: Intune device correctly links to Tenant X asset", $linkedAssetIdX === $assetX);
    ok("Test I: Asset in Tenant X renamed in place", $updatedHostX === 'CLIENTX-PC-01-NEWNAME');
    ok("Test I: Asset tenant_id preserved as Tenant X", (int)$assetXTenant === $tenantX);

    // -------------------------------------------------------------------------
    // Test J: Cross-Company Explicit Link Rejection & Isolation
    // -------------------------------------------------------------------------
    echo "
Test J: Cross-Company Explicit Link Rejection & Isolation
";

    $conn->exec("INSERT INTO tenants (name, is_active, created_datetime) VALUES ('MSP-Co-A', 1, UTC_TIMESTAMP())");
    $tenantA = (int)$conn->lastInsertId();
    $cleanupTenantIds[] = $tenantA;

    $conn->exec("INSERT INTO tenants (name, is_active, created_datetime) VALUES ('MSP-Co-B', 1, UTC_TIMESTAMP())");
    $tenantB = (int)$conn->lastInsertId();
    $cleanupTenantIds[] = $tenantB;

    // Asset 202 in Company B
    $stmt = $conn->prepare("INSERT INTO assets (hostname, service_tag, tenant_id, first_seen, last_seen) VALUES ('COMPB-HOST-ORIG', 'SERIAL-B-202', ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())");
    $stmt->execute([$tenantB]);
    $asset202 = (int)$conn->lastInsertId();
    $cleanupAssetIds[] = $asset202;

    // Intune record synced for Company A has a stale explicit link to Asset 202 (Company B)
    $stmt = $conn->prepare("INSERT INTO intune_devices (intune_id, asset_id, device_name, serial_number) VALUES ('INTUNE-STALE-LINK-J', ?, 'COMPA-NEW-HOST', 'GENERIC-J')");
    $stmt->execute([$asset202]);
    $intuneDevJ = (int)$conn->lastInsertId();
    $cleanupIntuneIds[] = $intuneDevJ;

    // Direct resolution inquiry in Company A context with explicit_link = true
    $resJ = AssetsService::resolveAssetIdentity(
        $conn,
        ['asset_id' => $asset202, 'service_tag' => 'GENERIC-J', 'hostname' => 'COMPA-NEW-HOST'],
        $tenantA,
        true
    );
    ok("Test J: Cross-company explicit link rejected (not matched to Company B asset)", $resJ['asset_id'] !== $asset202);

    // Sync Intune for Company A
    intuneLinkDevicesToAssets($conn, $tenantA);

    // Verify Company B asset was untouched
    $hostB = $conn->query("SELECT hostname FROM assets WHERE id = {$asset202}")->fetchColumn();
    ok("Test J: Company B asset hostname untouched", $hostB === 'COMPB-HOST-ORIG');

    // Verify intune_devices.asset_id was repointed to a newly created Company A stub, not Company B asset
    $newLinkedIdJ = (int)$conn->query("SELECT asset_id FROM intune_devices WHERE id = {$intuneDevJ}")->fetchColumn();
    $cleanupAssetIds[] = $newLinkedIdJ;
    $newAssetJTenant = (int)$conn->query("SELECT tenant_id FROM assets WHERE id = {$newLinkedIdJ}")->fetchColumn();
    ok("Test J: Intune device repointed to new Company A asset", $newLinkedIdJ !== $asset202 && $newAssetJTenant === $tenantA);

    // -------------------------------------------------------------------------
    // Test K: Same Serial in Two Companies (Composite Index Isolation)
    // -------------------------------------------------------------------------
    echo "
Test K: Same Serial in Two Companies
";

    $stmt = $conn->prepare("INSERT INTO assets (hostname, service_tag, tenant_id, first_seen, last_seen) VALUES ('COMPA-PC-DUAL', 'SERIAL-DUAL-001', ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())");
    $stmt->execute([$tenantA]);
    $assetK1 = (int)$conn->lastInsertId();
    $cleanupAssetIds[] = $assetK1;

    $stmt = $conn->prepare("INSERT INTO assets (hostname, service_tag, tenant_id, first_seen, last_seen) VALUES ('COMPB-PC-DUAL', 'SERIAL-DUAL-001', ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())");
    $stmt->execute([$tenantB]);
    $assetK2 = (int)$conn->lastInsertId();
    $cleanupAssetIds[] = $assetK2;

    $resK_A = AssetsService::reconcileAsset($conn, ['service_tag' => 'SERIAL-DUAL-001', 'hostname' => 'COMPA-PC-DUAL'], $tenantA, 'system');
    $resK_B = AssetsService::reconcileAsset($conn, ['service_tag' => 'SERIAL-DUAL-001', 'hostname' => 'COMPB-PC-DUAL'], $tenantB, 'system');

    ok("Test K: Company A inquiry resolves exclusively to Company A asset", $resK_A['asset_id'] === $assetK1);
    ok("Test K: Company B inquiry resolves exclusively to Company B asset", $resK_B['asset_id'] === $assetK2);

    // -------------------------------------------------------------------------
    // Test L: Default Company (tenant_id = NULL) Representation
    // -------------------------------------------------------------------------
    echo "
Test L: Default Company (tenant_id = NULL) Representation
";

    $stmt = $conn->prepare("INSERT INTO assets (hostname, service_tag, tenant_id, first_seen, last_seen) VALUES ('DEFCO-HOST-OLD', 'SERIAL-DEFCO-101', NULL, UTC_TIMESTAMP(), UTC_TIMESTAMP())");
    $stmt->execute();
    $assetL = (int)$conn->lastInsertId();
    $cleanupAssetIds[] = $assetL;

    $resL = AssetsService::reconcileAsset(
        $conn,
        ['service_tag' => 'SERIAL-DEFCO-101', 'hostname' => 'DEFCO-HOST-NEW'],
        null, // Default company
        'Intune'
    );

    $hostL = $conn->query("SELECT hostname FROM assets WHERE id = {$assetL}")->fetchColumn();
    $tenantL = $conn->query("SELECT tenant_id FROM assets WHERE id = {$assetL}")->fetchColumn();

    ok("Test L: Default company serial resolves to NULL-tenant asset", $resL['asset_id'] === $assetL);
    ok("Test L: Hostname updated on NULL-tenant asset", $hostL === 'DEFCO-HOST-NEW');
    ok("Test L: NULL tenant_id preserved on asset", $tenantL === null);

    // -------------------------------------------------------------------------
    // Test M: Stale Cross-Company Link + Valid Target Company Serial
    // -------------------------------------------------------------------------
    echo "
Test M: Stale Cross-Company Link + Valid Target Company Serial
";

    // Asset in Company B
    $stmt = $conn->prepare("INSERT INTO assets (hostname, service_tag, tenant_id, first_seen, last_seen) VALUES ('HOST-MB-ORIG', 'SERIAL-B-999', ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())");
    $stmt->execute([$tenantB]);
    $assetMB = (int)$conn->lastInsertId();
    $cleanupAssetIds[] = $assetMB;

    // Asset in Company A
    $stmt = $conn->prepare("INSERT INTO assets (hostname, service_tag, tenant_id, first_seen, last_seen) VALUES ('HOST-MA-OLD', 'SERIAL-A-888', ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())");
    $stmt->execute([$tenantA]);
    $assetMA = (int)$conn->lastInsertId();
    $cleanupAssetIds[] = $assetMA;

    // Intune device has stale link to Company B asset, but incoming serial is for Company A asset
    $stmt = $conn->prepare("INSERT INTO intune_devices (intune_id, asset_id, device_name, serial_number) VALUES ('INTUNE-DEV-M-55', ?, 'HOST-MA-NEW', 'SERIAL-A-888')");
    $stmt->execute([$assetMB]);
    $intuneDevM = (int)$conn->lastInsertId();
    $cleanupIntuneIds[] = $intuneDevM;

    // Run Intune sync scoped to Company A
    intuneLinkDevicesToAssets($conn, $tenantA);

    $linkedAssetIdM = (int)$conn->query("SELECT asset_id FROM intune_devices WHERE id = {$intuneDevM}")->fetchColumn();
    $updatedHostMA  = $conn->query("SELECT hostname FROM assets WHERE id = {$assetMA}")->fetchColumn();
    $hostMB         = $conn->query("SELECT hostname FROM assets WHERE id = {$assetMB}")->fetchColumn();

    ok("Test M: Intune device repointed to valid Company A asset", $linkedAssetIdM === $assetMA);
    ok("Test M: Company A asset hostname renamed in place", $updatedHostMA === 'HOST-MA-NEW');
    ok("Test M: Company B asset completely untouched", $hostMB === 'HOST-MB-ORIG');

    // -------------------------------------------------------------------------
    // Test N: Server-Side Capability & Settings Key Permission Enforcement
    // -------------------------------------------------------------------------
    echo "
Test N: Server-Side Capability & Settings Key Enforcement
";
    require_once __DIR__ . '/../includes/capabilities.php';
    require_once __DIR__ . '/../includes/settings_keys.php';
    require_once __DIR__ . '/../includes/rbac.php';

    $ownerRecon  = settingKeyOwner('asset_reconciliation_ignored_serials');
    $ownerIntune = settingKeyOwner('intune_company_id');

    ok("Test N: Ignored serials key is owned by Cap::ASSETS_RECONCILIATION", ($ownerRecon['cap'] ?? '') === Cap::ASSETS_RECONCILIATION);
    ok("Test N: Intune company key is owned by Cap::ASSETS_INTUNE", ($ownerIntune['cap'] ?? '') === Cap::ASSETS_INTUNE);

    // Clean up any stale test records
    $conn->exec("DELETE FROM rbac_role_capabilities WHERE role_id IN (SELECT id FROM rbac_roles WHERE name = 'QA Recon Role')");
    $conn->exec("DELETE FROM rbac_analyst_roles WHERE analyst_id IN (SELECT id FROM analysts WHERE username LIKE 'test_analyst_qa_recon%')");
    $conn->exec("DELETE FROM rbac_roles WHERE name = 'QA Recon Role'");
    $conn->exec("DELETE FROM analysts WHERE username LIKE 'test_analyst_qa_recon%'");

    try {
        // Analyst 1: No capabilities
        $conn->exec("INSERT INTO analysts (username, email, full_name, password_hash, is_admin, is_active, created_datetime) VALUES ('test_analyst_qa_recon1', 'qa_recon1@test.local', 'QA Recon 1', 'hash', 0, 1, UTC_TIMESTAMP())");
        $analystId1 = (int)$conn->lastInsertId();

        $canWriteReconWithout = analystCanWriteSettingKey($conn, $analystId1, 'asset_reconciliation_ignored_serials');
        ok("Test N: Analyst without capability cannot write ignored serials", $canWriteReconWithout === false);

        $canWriteIntuneWithout = analystCanWriteSettingKey($conn, $analystId1, 'intune_company_id');
        ok("Test N: Analyst without capability cannot write intune company", $canWriteIntuneWithout === false);

        // Analyst 2: With assets.reconciliation capability
        $conn->exec("INSERT INTO analysts (username, email, full_name, password_hash, is_admin, is_active, created_datetime) VALUES ('test_analyst_qa_recon2', 'qa_recon2@test.local', 'QA Recon 2', 'hash', 0, 1, UTC_TIMESTAMP())");
        $analystId2 = (int)$conn->lastInsertId();

        $conn->exec("INSERT INTO rbac_roles (name, is_active, created_datetime) VALUES ('QA Recon Role', 1, UTC_TIMESTAMP())");
        $roleId = (int)$conn->lastInsertId();
        $conn->exec("INSERT INTO rbac_analyst_roles (analyst_id, role_id) VALUES ({$analystId2}, {$roleId})");
        $conn->exec("INSERT INTO rbac_role_capabilities (role_id, capability_key) VALUES ({$roleId}, '" . Cap::ASSETS_RECONCILIATION . "')");

        $canWriteReconWith = analystCanWriteSettingKey($conn, $analystId2, 'asset_reconciliation_ignored_serials');
        ok("Test N: Analyst with assets.reconciliation capability CAN write ignored serials", $canWriteReconWith === true);

        $canWriteIntuneStillNo = analystCanWriteSettingKey($conn, $analystId2, 'intune_company_id');
        ok("Test N: Analyst with assets.reconciliation still CANNOT write intune settings", $canWriteIntuneStillNo === false);
    } finally {
        $conn->exec("DELETE FROM rbac_role_capabilities WHERE role_id IN (SELECT id FROM rbac_roles WHERE name = 'QA Recon Role')");
        $conn->exec("DELETE FROM rbac_analyst_roles WHERE analyst_id IN (SELECT id FROM analysts WHERE username LIKE 'test_analyst_qa_recon%')");
        $conn->exec("DELETE FROM rbac_roles WHERE name = 'QA Recon Role'");
        $conn->exec("DELETE FROM analysts WHERE username LIKE 'test_analyst_qa_recon%'");
    }
} catch (Throwable $e) {
    echo "EXCEPTION: " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n";
    $fail++;
} finally {
    if (!empty($cleanupIntuneIds)) {
        $in = implode(',', $cleanupIntuneIds);
        $conn->exec("DELETE FROM intune_devices WHERE id IN ({$in})");
    }
    if (!empty($cleanupAssetIds)) {
        $in = implode(',', $cleanupAssetIds);
        $conn->exec("DELETE FROM asset_history WHERE asset_id IN ({$in})");
        $conn->exec("DELETE FROM assets WHERE id IN ({$in})");
    }
    if (!empty($cleanupTenantIds)) {
        $in = implode(',', $cleanupTenantIds);
        $conn->exec("DELETE FROM tenants WHERE id IN ({$in})");
    }
}

echo "\n" . str_repeat('=', 70) . "\n";
echo "Results: {$pass} passed, {$fail} failed.\n\n";

if ($fail > 0) {
    exit(1);
}
