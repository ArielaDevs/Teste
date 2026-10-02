<?php
/* 🔴 NEVER OVER THE WEB. See tests/README.md. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * People across companies - who may read whom.
 *
 * A person (a row in `users`) belongs to one company. An analyst limited to
 * other companies must not be able to read them by id, from any screen.
 *
 * ⚠️ Needs a restriction to test, and on most installs nobody is restricted.
 * Everything runs inside a transaction that is ALWAYS rolled back.
 *
 * Run:  php tests/people-scope.php
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/i18n.php';
require_once __DIR__ . '/../includes/tenancy.php';
require_once __DIR__ . '/../includes/services/assets.php';
I18n::initFromSession();

$pass = 0; $fail = 0;
function ok(string $what, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  PASS  {$what}\n"; }
    else       { $fail++; echo "  FAIL  {$what}" . ($detail ? "  <- {$detail}" : '') . "\n"; }
}

echo "\nPeople across companies\n" . str_repeat('=', 70) . "\n";

$conn = connectToDatabase();
if (!isMultiTenant($conn)) { echo "  SKIP  needs two or more companies\n"; exit(0); }
$default = getDefaultTenantId($conn);
$others  = array_values(array_diff(array_map('intval', $conn->query("SELECT id FROM tenants WHERE is_active = 1 ORDER BY id")->fetchAll(PDO::FETCH_COLUMN)), [$default]));
if (count($others) < 2) { echo "  SKIP  needs two companies besides Default\n"; exit(0); }
[$mine, $theirs] = $others;

// Not an admin, and chosen before anything asks about their companies
// (getAccessibleTenantIds() caches per analyst for the life of the process).
$r = 0;
foreach ($conn->query("SELECT id FROM analysts WHERE is_active = 1 AND (is_admin = 0 OR is_admin IS NULL) ORDER BY id")->fetchAll(PDO::FETCH_COLUMN) as $cand) {
    if (analystCanAccessModule($conn, (int)$cand, 'assets')) { $r = (int)$cand; break; }
}
if (!$r) { echo "  SKIP  needs a non-admin analyst with Assets\n"; exit(0); }

$conn->beginTransaction();
try {
    $conn->prepare("UPDATE analysts SET can_access_all_tenants = 0 WHERE id = ?")->execute([$r]);
    $conn->prepare("DELETE FROM analyst_tenant_access WHERE analyst_id = ?")->execute([$r]);
    $conn->prepare("DELETE FROM analyst_teams WHERE analyst_id = ?")->execute([$r]);
    $conn->prepare("INSERT INTO analyst_tenant_access (analyst_id, tenant_id) VALUES (?, ?)")->execute([$r, $mine]);
    ok("analyst {$r} is limited to company {$mine}", getAccessibleTenantIds($conn, $r) === [$mine]);

    $mk = function (?int $tenant, string $name, ?int $manager = null) use ($conn): int {
        $conn->prepare("INSERT INTO users (email, display_name, tenant_id, is_active, manager_id, phone) VALUES (?, ?, ?, 1, ?, '01632 960000')")
             ->execute([strtolower(str_replace(' ', '.', $name)) . '.pstest@example.invalid', $name, $tenant, $manager]);
        return (int)$conn->lastInsertId();
    };
    $boss    = $mk($mine, 'PS Boss');
    $uMine   = $mk($mine, 'PS Mine', $boss);
    $uTheirs = $mk($theirs, 'PS Theirs', $boss);     // one manager, reports in two companies
    $ctx = new ActorContext(actorId: $r, companyScope: [$mine], source: 'ui');

    echo "\nAssets > Users (one person and what they hold):\n";
    ok('someone in another company reads as no such person', AssetsService::assetsForUser($conn, $ctx, $uTheirs) === null);
    $mineRow = AssetsService::assetsForUser($conn, $ctx, $uMine);
    ok('POSITIVE CONTROL: someone in their own company is returned', $mineRow !== null && $mineRow['user']['id'] === $uMine);
    $bossRow = AssetsService::assetsForUser($conn, $ctx, $boss);
    $reports = array_column($bossRow['user']['reports'] ?? [], 'id');
    ok('a manager\'s reports in another company are not listed', !in_array($uTheirs, $reports, true), json_encode($reports));
    ok('POSITIVE CONTROL: reports in their own company are', in_array($uMine, $reports, true), json_encode($reports));
} catch (Throwable $e) {
    ok('the run completed', false, get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
} finally {
    if ($conn->inTransaction()) $conn->rollBack();
}

$left = (int)$conn->query("SELECT COUNT(*) FROM users WHERE email LIKE '%.pstest@example.invalid'")->fetchColumn();
ok('nothing survived the run (rolled back)', $left === 0, "{$left} test users left");

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail ? 1 : 0);
