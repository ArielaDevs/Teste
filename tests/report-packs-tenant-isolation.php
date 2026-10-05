<?php
/* 🔴 NEVER OVER THE WEB. See tests/README.md. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * Report-packs tenant isolation — read-by-id cross-tenant.
 *
 * WHY THIS EXISTS:
 * Pack access (includes/report_packs/access.php) is owner/share-based with
 * NO tenant dimension: a share to a TEAM reaches every member of that team
 * whichever company scopes them, and the pack's design can embed one
 * company's figures. get.php answers 404 for unshared packs (good — the
 * unknown-id and unshared cases are indistinguishable), but a team share
 * spanning the tenant boundary opens a tenant-A pack — and its data — to a
 * tenant-B analyst. (Block data itself is re-scoped per viewer; the pack
 * shell, its name, design and owner are not.)
 *
 * STATUS (pós-P0/P2.3): pack-tenant gates in rpRole()/rpListPacks() must
 * PASS; the unshared-pack probes guard the 404 behaviour. If any probe
 * fails, it is a REGRESSION — reopen with Agente 1. See
 * docs/testing/isolation-matrix.md.
 *
 * rpViewer() caches per analyst per process: both analysts are scoped and the
 * team membership created BEFORE the first role check, so the cache cannot
 * hide the leak.
 *
 * Run:  php tests/report-packs-tenant-isolation.php   (needs DB, 2+ tenants, 2 analysts)
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/i18n.php';
require_once __DIR__ . '/../includes/tenancy.php';
require_once __DIR__ . '/../includes/report_packs/access.php';
I18n::initFromSession();

$pass = 0; $fail = 0;
function ok(string $what, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  PASS  {$what}\n"; }
    else       { $fail++; echo "  FAIL  {$what}" . ($detail ? "  <- {$detail}" : '') . "\n"; }
}

echo "\nReport-packs tenant isolation\n" . str_repeat('=', 70) . "\n";

$conn = connectToDatabase();
if (!isMultiTenant($conn)) { echo "  SKIP  needs two or more companies\n"; exit(2); }

$ids = $conn->query("SELECT id FROM analysts WHERE is_active = 1 AND (is_admin = 0 OR is_admin IS NULL) ORDER BY id LIMIT 2")->fetchAll(PDO::FETCH_COLUMN);
if (count($ids) < 2) { echo "  SKIP  needs two non-admin analysts\n"; exit(2); }
[$r1, $r2] = array_map('intval', $ids);

$uniq = 'zz' . substr(preg_replace('/[^a-z0-9]/', '', strtolower(uniqid())), 0, 8);

$conn->beginTransaction();
try {
    $conn->prepare("INSERT INTO tenants (name, slug, is_default, is_active) VALUES (?, ?, 0, 1)")
        ->execute(["ZZ-ISOL-A {$uniq}", "zz-isol-a-{$uniq}"]);
    $tenantA = (int)$conn->lastInsertId();
    $conn->prepare("INSERT INTO tenants (name, slug, is_default, is_active) VALUES (?, ?, 0, 1)")
        ->execute(["ZZ-ISOL-B {$uniq}", "zz-isol-b-{$uniq}"]);
    $tenantB = (int)$conn->lastInsertId();
    ok('fixtures: two distinct tenants created', $tenantA > 0 && $tenantB > 0 && $tenantA !== $tenantB);

    foreach ([[$r1, $tenantA], [$r2, $tenantB]] as [$analyst, $tenant]) {
        $conn->prepare("UPDATE analysts SET can_access_all_tenants = 0, can_access_all_modules = 1 WHERE id = ?")->execute([$analyst]);
        $conn->prepare("DELETE FROM analyst_tenant_access WHERE analyst_id = ?")->execute([$analyst]);
        $conn->prepare("DELETE FROM analyst_teams WHERE analyst_id = ?")->execute([$analyst]);
        $conn->prepare("INSERT INTO analyst_tenant_access (analyst_id, tenant_id) VALUES (?, ?)")->execute([$analyst, $tenant]);
    }
    ok("analyst {$r1} scoped to A / analyst {$r2} scoped to B",
        getAccessibleTenantIds($conn, $r1) === [$tenantA] && getAccessibleTenantIds($conn, $r2) === [$tenantB]);

    // A team that spans the boundary: tenant-B analyst is a member.
    $conn->prepare("INSERT INTO teams (name, can_access_all_tenants, can_access_all_modules) VALUES (?, 0, 0)")
        ->execute(["ZZ-ISOL team {$uniq}"]);
    $team = (int)$conn->lastInsertId();
    $conn->prepare("INSERT INTO analyst_teams (analyst_id, team_id) VALUES (?, ?)")->execute([$r2, $team]);

    // Two company packs owned by the tenant-A analyst (004: tenant_id set =
    // company pack), one shared to the team, one not. Shares carry the
    // pack's company denormalised (F12c), as rpSaveShares() stamps them.
    $conn->prepare("INSERT INTO report_packs (name, description, owner_id, design, tenant_id) VALUES (?, ?, ?, '{}', ?)")
        ->execute(["ZZ-ISOL pack shared {$uniq}", 'shared fixture', $r1, $tenantA]);
    $packShared = (int)$conn->lastInsertId();
    $conn->prepare("INSERT INTO report_packs (name, description, owner_id, design, tenant_id) VALUES (?, ?, ?, '{}', ?)")
        ->execute(["ZZ-ISOL pack private {$uniq}", 'private fixture', $r1, $tenantA]);
    $packPrivate = (int)$conn->lastInsertId();
    $conn->prepare("INSERT INTO report_pack_shares (pack_id, tenant_id, target_type, target_id, target_value, can_edit) VALUES (?, ?, 'team', ?, NULL, 0)")
        ->execute([$packShared, $tenantA, $team]);

    echo "\nOwnership + unshared packs (guards that already hold):\n";
    ok('POSITIVE CONTROL: owner role on own pack', rpRole($conn, $r1, $packShared) === 'owner');
    ok('unshared pack of tenant A is not found for tenant-B analyst', rpRole($conn, $r2, $packPrivate) === null);
    ok('unknown pack id is not found (no oracle)', rpRole($conn, $r2, 2147483647) === null);
    $listIds = array_column(rpListPacks($conn, $r2), 'id');
    ok('unshared pack absent from tenant-B list', !in_array($packPrivate, $listIds, true), json_encode($listIds));

    echo "\nTeam share across the tenant boundary (P2.3 pack-tenant gate):\n";
    // Post-P0 rpRole()/rpListPacks() additionally require pack-tenant reach,
    // so the team share no longer crosses companies. Personal drafts
    // (tenant_id NULL) keep the old behaviour — covered by the NULL probe.
    $role = rpRole($conn, $r2, $packShared);
    ok('team-shared pack of tenant A is not found for tenant-B analyst', $role === null, 'role=' . var_export($role, true));
    $listIds = array_column(rpListPacks($conn, $r2), 'id');
    ok('team-shared pack absent from tenant-B list', !in_array($packShared, $listIds, true), json_encode($listIds));

    echo "\nF12c shares-attribution (shares carry the pack's company):\n";
    // rpSaveShares() stamps share.tenant_id from the parent pack (never the
    // request), NULL for personal drafts. rpSaveShares() manages its own
    // transaction so it cannot run inside this one; the probes below assert
    // the invariant on writer-shaped rows + the writer source itself.
    $shareTenant = $conn->query("SELECT tenant_id FROM report_pack_shares WHERE pack_id = {$packShared}")->fetchColumn();
    ok('POSITIVE CONTROL: fixture share carries the pack tenant', $shareTenant !== false && (int)$shareTenant === $tenantA, var_export($shareTenant, true));
    $saveSrc = file_get_contents(__DIR__ . '/../includes/report_packs/access.php');
    ok('writer stamps share.tenant_id from the parent pack (F12c)', $saveSrc !== false && strpos($saveSrc, 'INSERT INTO report_pack_shares (pack_id, tenant_id,') !== false);
} catch (Throwable $e) {
    ok('the run completed', false, get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
} finally {
    if ($conn->inTransaction()) $conn->rollBack();
}

$left = 0;
try {
    $left += (int)$conn->query("SELECT COUNT(*) FROM report_packs WHERE name LIKE 'ZZ-ISOL pack % {$uniq}'")->fetchColumn();
    $left += (int)$conn->query("SELECT COUNT(*) FROM teams WHERE name LIKE 'ZZ-ISOL team {$uniq}'")->fetchColumn();
    $left += (int)$conn->query("SELECT COUNT(*) FROM tenants WHERE slug IN ('zz-isol-a-{$uniq}', 'zz-isol-b-{$uniq}')")->fetchColumn();
} catch (Throwable $e) { /* count itself failed — report below */ }
ok('nothing survived the run (rolled back)', $left === 0, "{$left} test rows left");

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail ? 1 : 0);
