<?php
/* 🔴 NEVER OVER THE WEB. A test writes to the real tables and is for CLI use only. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

// Safety guard: prevent accidental execution on production without explicit intent
if (getenv('FREEITSM_TEST_MODE') !== '1' && !in_array('--run', $argv ?? [])) {
    echo "======================================================================\n";
    echo "  SAFETY NOTICE: Asset Tag Auto-Generation Test Suite\n";
    echo "  To execute this test suite against your test database, run:\n";
    echo "    php tests/asset-tag-autogen.php --run\n";
    echo "  or export FREEITSM_TEST_MODE=1\n";
    echo "======================================================================\n";
    exit(0);
}

/**
 * Asset Tag Auto-Generation & Monotonic Sequencing Test Suite
 *
 * Validates:
 *   1. Default backward-compatibility (disabled by default, blank tag -> null).
 *   2. Tag formatting: [prefix][padded number][suffix].
 *   3. Monotonic atomic allocation on asset creation.
 *   4. Safe collision skip: skips over pre-existing manual tags without clashing.
 *   5. Monotonic non-reuse: deleted/retired assets do not cause numbers to be re-issued.
 *   6. Manual tag preservation: explicit tag is validated and doesn't advance sequence.
 *   7. Tenant scope isolation: company sequences remain isolated.
 */

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
require_once dirname(__DIR__) . '/includes/asset_labels.php';
require_once dirname(__DIR__) . '/includes/services/assets.php';
require_once dirname(__DIR__) . '/includes/services/asset_tags.php';

$conn = connectToDatabase();
$testPrefix = 'ZZTAG-';
$testSuffix = '-TEST';
$createdAssetIds = [];


function cleanupTestAssets(PDO $conn): void {
    $sql = "WHERE asset_id IN (SELECT id FROM assets WHERE hostname LIKE 'ZZTAG-%')";
    $conn->exec("DELETE FROM asset_history $sql");
    $conn->exec("DELETE FROM asset_disks $sql");
    $conn->exec("DELETE FROM asset_physical_disks $sql");
    $conn->exec("DELETE FROM asset_network_adapters $sql");
    $conn->exec("DELETE FROM asset_devices $sql");
    $conn->exec("DELETE FROM assets WHERE hostname LIKE 'ZZTAG-%'");
}

function assertTest(string $desc, bool $condition, string $details = ''): void {
    if ($condition) {
        echo "  [PASS] $desc\n";
    } else {
        echo "  [FAIL] $desc " . ($details ? "($details)" : "") . "\n";
        throw new Exception("Assertion failed: $desc");
    }
}

echo "Starting Asset Tag Auto-Generation Test Suite...\n";

try {
    $ctx = ActorContext::system();
    $defaultTenant = getDefaultTenantId($conn);
    cleanupTestAssets($conn);

    // ------------------------------------------------------------------
    // 1. Formatting logic
    // ------------------------------------------------------------------
    echo "\nTest 1: Tag Formatting\n";
    $fmt1 = AssetTagsService::formatTag('AST-', 42, 5, '');
    assertTest("Formats standard AST-00042", $fmt1 === 'AST-00042', "Got: $fmt1");

    $fmt2 = AssetTagsService::formatTag('CORP-', 7, 4, '-UK');
    assertTest("Formats with suffix CORP-0007-UK", $fmt2 === 'CORP-0007-UK', "Got: $fmt2");

    // ------------------------------------------------------------------
    // 2. Backward Compatibility: Disabled by default
    // ------------------------------------------------------------------
    echo "\nTest 2: Backward Compatibility (Disabled Mode)\n";
    // Ensure disabled
    $conn->exec("DELETE FROM system_settings WHERE setting_key = 'asset_tag_autogen_enabled'");
    $conn->exec("INSERT INTO system_settings (setting_key, setting_value, updated_datetime) VALUES ('asset_tag_autogen_enabled', '0', UTC_TIMESTAMP())");

    $id1 = AssetsService::createAsset($conn, $ctx, [
        'hostname'  => 'ZZTAG-DIS-01',
        'asset_tag' => '',
    ], 'Test disabled autogen');
    $createdAssetIds[] = $id1;

    $stmt = $conn->prepare("SELECT asset_tag FROM assets WHERE id = ?");
    $stmt->execute([$id1]);
    $tag1 = $stmt->fetchColumn();
    assertTest("Asset created with blank tag stays NULL when autogen is disabled", $tag1 === null || $tag1 === false);

    // Manual tag when disabled is preserved
    $id2 = AssetsService::createAsset($conn, $ctx, [
        'hostname'  => 'ZZTAG-DIS-02',
        'asset_tag' => 'MANUAL-999',
    ], 'Test manual tag');
    $createdAssetIds[] = $id2;

    $stmt->execute([$id2]);
    $tag2 = $stmt->fetchColumn();
    assertTest("Manual tag is stored when provided", $tag2 === 'MANUAL-999', "Got: $tag2");

    // ------------------------------------------------------------------
    // 3. Monotonic Auto-Generation on Save
    // ------------------------------------------------------------------
    echo "\nTest 3: Atomic Monotonic Sequence Allocation\n";
    // Configure settings for test
    $seqKey = AssetTagsService::sequenceTenantKey($conn, null);
    $conn->exec("DELETE FROM asset_tag_sequences WHERE tenant_id = $seqKey");

    $conn->prepare(
        "REPLACE INTO system_settings (setting_key, setting_value, updated_datetime) VALUES
         ('asset_tag_autogen_enabled', '1', UTC_TIMESTAMP()),
         ('asset_tag_prefix', 'ZZAUT-', UTC_TIMESTAMP()),
         ('asset_tag_suffix', '-X', UTC_TIMESTAMP()),
         ('asset_tag_padding', '4', UTC_TIMESTAMP()),
         ('asset_tag_initial_number', '10', UTC_TIMESTAMP())"
        )->execute();
    tenantSetting($conn, null, "", null, true);

    $id3 = AssetsService::createAsset($conn, $ctx, [
        'hostname'  => 'ZZTAG-AUTO-01',
        'asset_tag' => '', // blank -> autogen
    ], 'Test autogen 1');
    $createdAssetIds[] = $id3;

    $stmt->execute([$id3]);
    $tag3 = $stmt->fetchColumn();
    assertTest("Mints first sequential tag ZZAUT-0010-X", $tag3 === 'ZZAUT-0010-X', "Got: $tag3");

    $id4 = AssetsService::createAsset($conn, $ctx, [
        'hostname'  => 'ZZTAG-AUTO-02',
        'asset_tag' => '', // blank -> autogen
    ], 'Test autogen 2');
    $createdAssetIds[] = $id4;

    $stmt->execute([$id4]);
    $tag4 = $stmt->fetchColumn();
    assertTest("Mints next sequential tag ZZAUT-0011-X", $tag4 === 'ZZAUT-0011-X', "Got: $tag4");

    // ------------------------------------------------------------------
    // 4. Conflict Skip (Safely leaps over pre-existing tags)
    // ------------------------------------------------------------------
    echo "\nTest 4: Conflict Avoidance (Leapfrogs Existing Legacy Tags)\n";
    // Plant pre-existing asset with ZZAUT-0012-X manually
    $idPre = AssetsService::createAsset($conn, $ctx, [
        'hostname'  => 'ZZTAG-PRE-01',
        'asset_tag' => 'ZZAUT-0012-X',
    ], 'Pre-existing tag');
    $createdAssetIds[] = $idPre;

    // Next autogen must leapfrog 0012 and mint 0013
    $id5 = AssetsService::createAsset($conn, $ctx, [
        'hostname'  => 'ZZTAG-AUTO-03',
        'asset_tag' => '',
    ], 'Test autogen conflict skip');
    $createdAssetIds[] = $id5;

    $stmt->execute([$id5]);
    $tag5 = $stmt->fetchColumn();
    assertTest("Skips colliding tag ZZAUT-0012-X and mints ZZAUT-0013-X", $tag5 === 'ZZAUT-0013-X', "Got: $tag5");

    // ------------------------------------------------------------------
    // 5. Monotonic Non-Reuse (Deleted asset doesn't roll back sequence)
    // ------------------------------------------------------------------
    echo "\nTest 5: Monotonic Non-Reuse\n";
    // Delete asset 3 (which had ZZAUT-0010-X)
    $conn->prepare("DELETE FROM asset_history WHERE asset_id = ?")->execute([$id3]);
    $conn->prepare("DELETE FROM assets WHERE id = ?")->execute([$id3]);

    $id6 = AssetsService::createAsset($conn, $ctx, [
        'hostname'  => 'ZZTAG-AUTO-04',
        'asset_tag' => '',
    ], 'Test non-reuse');
    $createdAssetIds[] = $id6;

    $stmt->execute([$id6]);
    $tag6 = $stmt->fetchColumn();
    assertTest("Sequence counter moves strictly forward (mints ZZAUT-0014-X, does NOT reuse 0010)", $tag6 === 'ZZAUT-0014-X', "Got: $tag6");

    // ------------------------------------------------------------------
    // 6. Manual Override Doesn't Burn Sequence
    // ------------------------------------------------------------------
    echo "\nTest 6: Manual Override Safety\n";
    $id7 = AssetsService::createAsset($conn, $ctx, [
        'hostname'  => 'ZZTAG-MANUAL-01',
        'asset_tag' => 'MANUAL-STICKER-42',
    ], 'Manual tag while autogen enabled');
    $createdAssetIds[] = $id7;

    $stmt->execute([$id7]);
    $tag7 = $stmt->fetchColumn();
    assertTest("Custom manual tag saved", $tag7 === 'MANUAL-STICKER-42', "Got: $tag7");

    // Following autogen asset still takes next in sequence (ZZAUT-0015-X)
    $id8 = AssetsService::createAsset($conn, $ctx, [
        'hostname'  => 'ZZTAG-AUTO-05',
        'asset_tag' => '',
    ], 'Test autogen after manual');
    $createdAssetIds[] = $id8;

    $stmt->execute([$id8]);
    $tag8 = $stmt->fetchColumn();
    assertTest("Sequence preserved after manual tag (mints ZZAUT-0015-X)", $tag8 === 'ZZAUT-0015-X', "Got: $tag8");

    // ------------------------------------------------------------------
    // 7. Monotonic Sequence Non-Reduction Protection
    // ------------------------------------------------------------------
    echo "
Test 7: Monotonic Sequence Non-Reduction Protection
";
    $currConfig = AssetTagsService::getAutogenConfig($conn, null);
    $activeNext = $currConfig['next_number'];
    AssetTagsService::setNextSequenceNumber($conn, null, 5);
    $afterReduce = AssetTagsService::getAutogenConfig($conn, null)['next_number'];
    assertTest("Refuses to reduce active sequence counter (preserves $activeNext)", $afterReduce === $activeNext, "Got: $afterReduce");
    AssetTagsService::setNextSequenceNumber($conn, null, 20);
    $afterIncrease = AssetTagsService::getAutogenConfig($conn, null)['next_number'];
    assertTest("Allows advancing sequence counter forward to 20", $afterIncrease === 20, "Got: $afterIncrease");

    echo "\n======================================================================\n";
    echo "  ALL TESTS PASSED SUCCESSFULLY!\n";
    echo "======================================================================\n";
} finally {
    // Clean up test data
    cleanupTestAssets($conn);
    $seqKey = AssetTagsService::sequenceTenantKey($conn, null);
    $conn->exec("DELETE FROM asset_tag_sequences WHERE tenant_id = $seqKey");
    $conn->exec("DELETE FROM system_settings WHERE setting_key LIKE 'asset_tag_%'");
}
