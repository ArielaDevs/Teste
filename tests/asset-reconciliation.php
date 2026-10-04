<?php
/* 🔴 NEVER OVER THE WEB. See tests/README.md. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * Asset reconciliation (PR #164) - "is this incoming device one we already
 * have?" - and the Intune link rules built on it.
 *
 * Based on Sandy's original suite. Everything runs inside ONE transaction that
 * is always rolled back: every row it makes is prefixed ZZREC-, and nothing is
 * ever deleted by pattern, so it is safe to run on a real install.
 *
 * Run: php tests/asset-reconciliation.php
 */

$root = dirname(__DIR__);
require_once "$root/config.php";
require_once "$root/includes/functions.php";
require_once "$root/includes/services/assets.php";
require_once "$root/includes/intune.php";

$pass = 0; $fail = 0;
function ok(string $label, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; printf("  PASS %-70s\n", $label); }
    else       { $fail++; printf("  FAIL %-70s %s\n", $label, $detail); }
}

$conn = connectToDatabase();
$ctx  = ActorContext::system('Reconciliation test');
$tenants = isMultiTenant($conn)
    ? array_values(array_diff(array_map('intval', $conn->query("SELECT id FROM tenants WHERE is_active = 1 ORDER BY id")->fetchAll(PDO::FETCH_COLUMN)), [getDefaultTenantId($conn)]))
    : [];

$asset = function (string $host, ?string $serial, ?int $tenant = null) use ($conn): int {
    $conn->prepare("INSERT INTO assets (hostname, service_tag, tenant_id, first_seen) VALUES (?, ?, ?, UTC_TIMESTAMP())")
         ->execute([$host, $serial, $tenant]);
    return (int)$conn->lastInsertId();
};
$hostOf = fn(int $id) => (string)$conn->query("SELECT hostname FROM assets WHERE id = $id")->fetchColumn();
$ignored = AssetsService::getIgnoredServiceTags($conn);

echo "\nAsset reconciliation\n" . str_repeat('=', 72) . "\n";
$conn->beginTransaction();
try {
    echo "\nPlaceholder serials\n";
    ok('a blank serial is not usable', !AssetsService::isUsableServiceTag('  ', $ignored));
    ok('"To be filled by O.E.M." is not usable (any case)', !AssetsService::isUsableServiceTag('to be filled by o.e.m.', $ignored));
    ok('a real serial is usable', AssetsService::isUsableServiceTag('ZZREC-5CG1234', $ignored));

    echo "\nTier 2 - serial, and the renamed machine\n";
    $a = $asset('ZZREC-OLDNAME', 'ZZREC-SER-A');
    $r = AssetsService::reconcileAsset($conn, $ctx, ['service_tag' => 'ZZREC-SER-A', 'hostname' => 'ZZREC-NEWNAME'], null, 'test');
    ok('a renamed machine is found by its serial', $r['asset_id'] === $a && $r['matched_by'] === 'service_tag');
    ok('...and takes its new name', $r['hostname_updated'] && $hostOf($a) === 'ZZREC-NEWNAME');
    $h = $conn->query("SELECT analyst_id, new_value FROM asset_history WHERE asset_id = $a AND field_name = 'hostname'")->fetch(PDO::FETCH_ASSOC);
    ok('...recorded in its history with no analyst', $h && $h['analyst_id'] === null && strpos($h['new_value'], 'ZZREC-NEWNAME') === 0, json_encode($h));

    echo "\nTier 2 - the ambiguity guard\n";
    $d1 = $asset('ZZREC-DUP1', 'ZZREC-SER-DUP');
    $d2 = $asset('ZZREC-DUP2', 'ZZREC-SER-DUP');
    $r = AssetsService::resolveAssetIdentity($conn, ['service_tag' => 'ZZREC-SER-DUP', 'hostname' => 'ZZREC-ELSEWHERE'], null);
    ok('two assets sharing a serial: no guess', $r['asset_id'] === null && $r['ambiguous']);
    $r = AssetsService::resolveAssetIdentity($conn, ['service_tag' => 'ZZREC-SER-DUP', 'hostname' => 'ZZREC-DUP2'], null);
    ok('POSITIVE CONTROL: serial AND name together pick one', $r['asset_id'] === $d2 && $r['ambiguous']);

    echo "\nTier 3 - hostname, and the laptop refresh\n";
    $p = $asset('ZZREC-PLACEHOLDER', 'Default string');
    $r = AssetsService::resolveAssetIdentity($conn, ['service_tag' => 'Default string', 'hostname' => 'ZZREC-PLACEHOLDER'], null);
    ok('a placeholder serial falls through to the name', $r['asset_id'] === $p && $r['matched_by'] === 'hostname');
    $n = $asset('ZZREC-NOSERIAL', null);
    $r = AssetsService::resolveAssetIdentity($conn, ['service_tag' => 'ZZREC-SER-N', 'hostname' => 'ZZREC-NOSERIAL'], null);
    ok('an asset entered without a serial is found by name', $r['asset_id'] === $n);
    $old = $asset('ZZREC-LT-042', 'ZZREC-SER-OLD');
    $r = AssetsService::resolveAssetIdentity($conn, ['service_tag' => 'ZZREC-SER-NEW', 'hostname' => 'ZZREC-LT-042'], null);
    ok('LAPTOP REFRESH: a different good serial under the same name is a new asset', $r['asset_id'] === null);
    ok('...flagged with the asset whose name it reuses', $r['hostname_reused'] === $old);
    ok('...and the old laptop keeps its serial', $conn->query("SELECT service_tag FROM assets WHERE id = $old")->fetchColumn() === 'ZZREC-SER-OLD');
    $r = AssetsService::resolveAssetIdentity($conn, ['service_tag' => 'ZZREC-SER-OLD', 'hostname' => 'ZZREC-LT-042'], null);
    ok('POSITIVE CONTROL: the same serial under that name still matches', $r['asset_id'] === $old);

    echo "\nRename collisions\n";
    $c1 = $asset('ZZREC-COLL-A', 'ZZREC-SER-C1');
    $asset('ZZREC-COLL-B', 'ZZREC-SER-C2');
    $r = AssetsService::reconcileAsset($conn, $ctx, ['service_tag' => 'ZZREC-SER-C1', 'hostname' => 'ZZREC-COLL-B'], null, 'test');
    ok('a rename onto a name another asset has is refused', $r['asset_id'] === $c1 && $r['hostname_conflict'] && $hostOf($c1) === 'ZZREC-COLL-A');

    echo "\nCreating a discovered asset\n";
    $new = AssetsService::createDiscoveredAsset($conn, $ctx, ['hostname' => 'ZZREC-FOUND', 'service_tag' => 'ZZREC-SER-F', 'bogus_column' => 'x'], null, 'the test', $old);
    $row = $conn->query("SELECT hostname, service_tag, last_seen FROM assets WHERE id = $new")->fetch(PDO::FETCH_ASSOC);
    ok('is written with its fields, and last_seen set', $row['hostname'] === 'ZZREC-FOUND' && $row['service_tag'] === 'ZZREC-SER-F' && $row['last_seen'] !== null);
    $notes = $conn->query("SELECT field_name FROM asset_history WHERE asset_id = $new ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
    ok('...with "discovered" (not "created" - see the last_seen repair in db_verify) and "hostname reused"', $notes === ['asset_discovered', 'hostname_reused'], json_encode($notes));

    if (count($tenants) >= 1) {
        echo "\nCompanies\n";
        $x = $tenants[0];
        $other = $asset('ZZREC-CO-X', 'ZZREC-SER-X', $x);
        $r = AssetsService::resolveAssetIdentity($conn, ['service_tag' => 'ZZREC-SER-X', 'hostname' => 'ZZREC-CO-X'], null);
        ok('another company\'s asset is not matched', $r['asset_id'] === null);
        $r = AssetsService::resolveAssetIdentity($conn, ['service_tag' => 'ZZREC-SER-X'], null, false, null, true);
        ok('POSITIVE CONTROL: "any company" (Intune with no company set) finds it', $r['asset_id'] === $other);

        echo "\nIntune\n";
        $conn->prepare("INSERT INTO intune_devices (intune_id, asset_id, device_name, serial_number) VALUES ('ZZREC-INT-1', ?, 'ZZREC-CO-X', 'ZZREC-SER-X')")->execute([$other]);
        $stubsBefore = (int)$conn->query("SELECT COUNT(*) FROM assets")->fetchColumn();
        intuneLinkDevicesToAssets($conn);
        $link = $conn->query("SELECT asset_id FROM intune_devices WHERE intune_id = 'ZZREC-INT-1'")->fetchColumn();
        ok('a machine moved to another company KEEPS its Intune link', (int)$link === $other);
        $conn->prepare("INSERT INTO intune_devices (intune_id, device_name, serial_number) VALUES ('ZZREC-INT-2', 'ZZREC-INTUNE-NAME', 'ZZREC-SER-A')")->execute();
        intuneLinkDevicesToAssets($conn);
        $link2 = $conn->query("SELECT asset_id FROM intune_devices WHERE intune_id = 'ZZREC-INT-2'")->fetchColumn();
        ok('an unlinked device is linked by serial, not given a stub', (int)$link2 === $a);
        $stubs = (int)$conn->query("SELECT COUNT(*) FROM assets")->fetchColumn() - $stubsBefore;
        ok('...so no stubs were created for either', $stubs === 0, "$stubs created");
        $conn->prepare("UPDATE intune_devices SET device_name = 'ZZREC-RENAMED-IN-INTUNE' WHERE intune_id = 'ZZREC-INT-1'")->execute();
        intuneLinkDevicesToAssets($conn);
        $syncOn = (intuneGetSettings($conn)['sync_hostnames'] ?? false);
        ok($syncOn ? 'with renaming on, the asset takes the Intune name' : 'with renaming off, the asset keeps its name',
           $hostOf($other) === ($syncOn ? 'ZZREC-RENAMED-IN-INTUNE' : 'ZZREC-CO-X'), $hostOf($other));
    } else {
        echo "  SKIP  company and Intune checks need a second company\n";
    }
} catch (Throwable $e) {
    ok('the run completed', false, get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
} finally {
    if ($conn->inTransaction()) $conn->rollBack();
}

$left = (int)$conn->query("SELECT COUNT(*) FROM assets WHERE hostname LIKE 'ZZREC-%'")->fetchColumn()
      + (int)$conn->query("SELECT COUNT(*) FROM intune_devices WHERE intune_id LIKE 'ZZREC-%'")->fetchColumn();
ok('nothing survived the run (rolled back)', $left === 0, "$left rows left");
echo "\n{$pass} passed, {$fail} failed\n";
exit($fail ? 1 : 0);
