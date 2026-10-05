<?php
/* 🔴 NEVER OVER THE WEB. See tests/README.md. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * Tenant isolation for tickets — zero cross-tenant leakage.
 *
 * WHY THIS EXISTS (not just what it asserts):
 * Ticket isolation rests on five different gates, and each has failed
 * independently before (see includes/tenancy.php history: fail-open catches,
 * unscoped list queries, trusted client-supplied tenant_id, portal checks that
 * only looked at user_id). A test that only checks analystCanAccessTicket()
 * would pass while the list endpoint, the API, the portal or the search still
 * leaked — so this drives all five paths against the real guards with the same
 * two-tenant fixture and asserts ZERO rows from the wrong tenant in each.
 *
 * NOTE ON HARNESS: this repo has no PHPUnit / composer dependency (no
 * phpunit.xml, no vendor/phpunit). The suite convention is plain CLI scripts
 * (`php tests/<name>.php`) with a transaction that is ALWAYS rolled back.
 * This file follows that convention so it actually runs here, with one
 * function per requested scenario.
 *
 * Run:  php tests/ticket-tenant-isolation.php   (needs a DB with 2+ tenants)
 *
 * FASE 1 (sprint de isolamento): §6 estende a URL direta aos outros guards
 * (asset/change/problem/task/CMDB/domain) e §7 estende a busca global aos
 * ramos de assets e knowledge — incluindo a semântica partilhada (NULL) do
 * knowledge, que DEVE continuar visível.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/i18n.php';
require_once __DIR__ . '/../includes/tenancy.php';
require_once __DIR__ . '/../includes/managers.php';
require_once __DIR__ . '/../api/v1/lib/auth.php';
I18n::initFromSession();

$pass = 0; $fail = 0;
function ok(string $what, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  PASS  {$what}\n"; }
    else       { $fail++; echo "  FAIL  {$what}" . ($detail ? "  <- {$detail}" : '') . "\n"; }
}

echo "\nTicket tenant isolation\n" . str_repeat('=', 70) . "\n";

$conn = connectToDatabase();
if (!isMultiTenant($conn)) { echo "  SKIP  needs two or more companies\n"; exit(0); }

// A non-admin analyst; tenancy helpers cache per analyst per process, so the
// analyst row is picked BEFORE the transaction scopes it to tenant A only.
$r = (int)$conn->query("SELECT id FROM analysts WHERE is_active = 1 AND (is_admin = 0 OR is_admin IS NULL) ORDER BY id LIMIT 1")->fetchColumn();
if (!$r) { echo "  SKIP  needs a non-admin analyst\n"; exit(0); }

$uniq = 'zz' . substr(preg_replace('/[^a-z0-9]/', '', strtolower(uniqid())), 0, 8);

$conn->beginTransaction();
try {
    // ── Fixtures: two DISTINCT tenants + one analyst scoped to A only ──
    $conn->prepare("INSERT INTO tenants (name, slug, is_default, is_active) VALUES (?, ?, 0, 1)")
        ->execute(["ZZ-ISOL-A {$uniq}", "zz-isol-a-{$uniq}"]);
    $tenantA = (int)$conn->lastInsertId();
    $conn->prepare("INSERT INTO tenants (name, slug, is_default, is_active) VALUES (?, ?, 0, 1)")
        ->execute(["ZZ-ISOL-B {$uniq}", "zz-isol-b-{$uniq}"]);
    $tenantB = (int)$conn->lastInsertId();
    ok('fixtures: two distinct tenants created', $tenantA > 0 && $tenantB > 0 && $tenantA !== $tenantB);

    $conn->prepare("UPDATE analysts SET can_access_all_tenants = 0, can_access_all_modules = 1 WHERE id = ?")->execute([$r]);
    $conn->prepare("DELETE FROM analyst_tenant_access WHERE analyst_id = ?")->execute([$r]);
    $conn->prepare("DELETE FROM analyst_teams WHERE analyst_id = ?")->execute([$r]);
    $conn->prepare("INSERT INTO analyst_tenant_access (analyst_id, tenant_id) VALUES (?, ?)")->execute([$r, $tenantA]);
    ok("analyst {$r} is scoped to tenant A only", getAccessibleTenantIds($conn, $r) === [$tenantA]);

    // One portal user per tenant + one ticket per tenant sharing a search marker.
    $mkUser = function (int $tenant, string $tag) use ($conn, $uniq): int {
        $conn->prepare("INSERT INTO users (email, display_name, tenant_id, is_active) VALUES (?, ?, ?, 1)")
            ->execute(["zz-isol-{$tag}-{$uniq}@example.invalid", "ZZ Isol {$tag} {$uniq}", $tenant]);
        return (int)$conn->lastInsertId();
    };
    $userA = $mkUser($tenantA, 'a');
    $userB = $mkUser($tenantB, 'b');

    $mkTicket = function (int $tenant, ?int $userId, string $tag) use ($conn, $uniq): int {
        $no = "ZZ-ISOL-{$uniq}-" . strtoupper($tag);
        $conn->prepare("INSERT INTO tickets (tenant_id, ticket_number, subject, user_id) VALUES (?, ?, ?, ?)")
            ->execute([$tenant, $no, "ZZ-ISOL marker {$uniq} {$tag} subject", $userId]);
        return (int)$conn->lastInsertId();
    };
    $ticketA = $mkTicket($tenantA, $userA, 'a');
    $ticketB = $mkTicket($tenantB, $userB, 'b');

    // Simulate "working in tenant A" for the list/search filters.
    $_SESSION['active_tenant_id'] = $tenantA;
    $_SESSION['active_tenant_all'] = false;

    // ── 1. Direct-URL access: analyst of A opens ticket of B by id ──
    echo "\n1. Direct URL (detail-by-id gate):\n";
    ok('POSITIVE CONTROL: own ticket is reachable', analystCanAccessTicket($conn, $r, $ticketA));
    ok('tenant B ticket is denied to tenant A analyst', analystCanAccessTicket($conn, $r, $ticketB) === false);
    ok('unknown ticket id is denied (no oracle)', analystCanAccessTicket($conn, $r, 2147483647) === false);

    // ── 2. List without an explicit tenant filter ──
    // The endpoint must apply ticketTenantFilter(); an unfiltered query is shown
    // only as the control that proves both fixtures exist.
    echo "\n2. Ticket list scoping:\n";
    $rawIds = $conn->query("SELECT id FROM tickets WHERE subject LIKE '%ZZ-ISOL marker {$uniq}%'")->fetchAll(PDO::FETCH_COLUMN);
    ok('CONTROL: unfiltered SQL sees both fixtures', in_array($ticketA, array_map('intval', $rawIds), true) && in_array($ticketB, array_map('intval', $rawIds), true));
    [$fSql, $fArgs] = ticketTenantFilter($conn, $r, 't');
    $st = $conn->prepare("SELECT t.id, t.tenant_id FROM tickets t WHERE t.subject LIKE ?{$fSql}");
    $st->execute(array_merge(["%ZZ-ISOL marker {$uniq}%"], $fArgs));
    $listed = $st->fetchAll(PDO::FETCH_ASSOC);
    $listedIds = array_map('intval', array_column($listed, 'id'));
    ok('POSITIVE CONTROL: own ticket is listed', in_array($ticketA, $listedIds, true), json_encode($listedIds));
    $leak = array_values(array_filter($listed, fn($row) => (int)$row['tenant_id'] === $tenantB));
    ok('zero cross-tenant rows in the scoped list', $leak === [], json_encode($leak));

    // ── 3. Webhook/API payload with forged tenant_id ──
    // Transport/auth rule: the key's company scope wins; a client-supplied
    // company id outside the scope is 403, never honoured.
    echo "\n3. Forged tenant_id in API/webhook payload:\n";
    $apiKey = ['company_scope' => [$tenantA]]; // key issued for tenant A only
    ok('CONTROL: key reaches its own company', apiKeyCanAccessTenant($conn, $apiKey, $tenantA));
    ok('forged company_id (tenant B) is refused', apiKeyCanAccessTenant($conn, $apiKey, $tenantB) === false);
    ok('forged ticket write is refused (unknown-to-key => false)', apiKeyCanAccessTicket($conn, $apiKey, $ticketB) === false);
    ok('POSITIVE CONTROL: own ticket write is allowed', apiKeyCanAccessTicket($conn, $apiKey, $ticketA));
    [$kSql, $kArgs] = apiKeyTicketFilter($conn, $apiKey, 't');
    $kst = $conn->prepare("SELECT t.id FROM tickets t WHERE t.subject LIKE ?{$kSql}");
    $kst->execute(array_merge(["%ZZ-ISOL marker {$uniq}%"], $kArgs));
    $kIds = array_map('intval', $kst->fetchAll(PDO::FETCH_COLUMN));
    ok('key-scoped list contains zero tenant-B rows', !in_array($ticketB, $kIds, true) && in_array($ticketA, $kIds, true), json_encode($kIds));
    // Same rule on the analyst side: cannot file INTO a company they cannot reach.
    ok('analyst cannot assign/move into the forged tenant', analystCanAssignTenant($conn, $r, $tenantB) === false);

    // ── 4. Self-service portal after a session swap ──
    // portalTicketAccess() is keyed on (portal user, ticket): swapping the
    // session to user B must not expose user A's (tenant A) ticket and vice
    // versa. The manager path additionally requires same-company.
    echo "\n4. Self-service portal session swap:\n";
    $aSeesOwn = portalTicketAccess($conn, $userA, $ticketA);
    ok('POSITIVE CONTROL: requester opens their own ticket', $aSeesOwn !== null && $aSeesOwn['role'] === 'requester');
    ok('portal user of A gets "not found" on tenant B ticket', portalTicketAccess($conn, $userA, $ticketB) === null);
    // The swap: session now acts as user B; A's ticket must still be invisible.
    $swapped = portalTicketAccess($conn, $userB, $ticketA);
    ok('after session swap to tenant B, tenant A ticket is still not found', $swapped === null);
    ok('POSITIVE CONTROL: swapped session opens its own ticket', portalTicketAccess($conn, $userB, $ticketB) !== null);
    // Portal list equivalent: requester-scoped query returns zero foreign rows.
    $pst = $conn->prepare("SELECT id FROM tickets WHERE user_id = ? AND deleted_datetime IS NULL");
    $pst->execute([$userB]);
    $portalIds = array_map('intval', $pst->fetchAll(PDO::FETCH_COLUMN));
    ok('portal list for swapped user holds zero tenant-A rows', !in_array($ticketA, $portalIds, true), json_encode($portalIds));

    // ── 5. Global search (command palette) ──
    // Mirrors api/system/global_search.php: the ticket branch runs through
    // ticketTenantFilter(), so a shared substring must never surface B.
    echo "\n5. Global search:\n";
    $like = "%ZZ-ISOL marker {$uniq}%";
    [$sSql, $sArgs] = ticketTenantFilter($conn, $r, 't');
    $sst = $conn->prepare("SELECT t.id, t.ticket_number, t.subject, t.tenant_id FROM tickets t WHERE t.deleted_datetime IS NULL AND (t.ticket_number LIKE ? OR t.subject LIKE ?){$sSql} ORDER BY t.updated_datetime DESC LIMIT 6");
    $sst->execute(array_merge([$like, $like], $sArgs));
    $hits = $sst->fetchAll(PDO::FETCH_ASSOC);
    $hitTenants = array_unique(array_map(fn($row) => (int)$row['tenant_id'], $hits));
    ok('POSITIVE CONTROL: search finds the own-tenant ticket', in_array($ticketA, array_map(fn($row) => (int)$row['id'], $hits), true), json_encode($hits));
    ok('zero cross-tenant hits for the shared marker', !in_array($tenantB, $hitTenants, true), json_encode($hits));

    // ── 6. Direct-URL gates for the other tenant-scoped entities (Fase 1) ──
    // Same shape as §1: each guard has a positive control so a broken checker
    // cannot pass by denying everything. CMDB needs a class row; when the
    // install has none the CMDB pair is skipped, never failed.
    echo "\n6. Direct URL gates (asset/change/problem/task/CMDB/domain):\n";
    $conn->prepare("INSERT INTO assets (hostname, tenant_id) VALUES (?, ?)")->execute(["ZZ-ISOL-HOST-A-{$uniq}", $tenantA]);
    $assetA = (int)$conn->lastInsertId();
    $conn->prepare("INSERT INTO assets (hostname, tenant_id) VALUES (?, ?)")->execute(["ZZ-ISOL-HOST-B-{$uniq}", $tenantB]);
    $assetB = (int)$conn->lastInsertId();
    $conn->prepare("INSERT INTO changes (tenant_id, title) VALUES (?, ?)")->execute([$tenantA, "ZZ-ISOL change A {$uniq}"]);
    $changeA = (int)$conn->lastInsertId();
    $conn->prepare("INSERT INTO changes (tenant_id, title) VALUES (?, ?)")->execute([$tenantB, "ZZ-ISOL change B {$uniq}"]);
    $changeB = (int)$conn->lastInsertId();
    $conn->prepare("INSERT INTO problems (tenant_id, title) VALUES (?, ?)")->execute([$tenantA, "ZZ-ISOL problem A {$uniq}"]);
    $problemA = (int)$conn->lastInsertId();
    $conn->prepare("INSERT INTO problems (tenant_id, title) VALUES (?, ?)")->execute([$tenantB, "ZZ-ISOL problem B {$uniq}"]);
    $problemB = (int)$conn->lastInsertId();
    $conn->prepare("INSERT INTO tasks (tenant_id, title, created_by_id) VALUES (?, ?, ?)")->execute([$tenantA, "ZZ-ISOL task A {$uniq}", $r]);
    $taskA = (int)$conn->lastInsertId();
    $conn->prepare("INSERT INTO tasks (tenant_id, title, created_by_id) VALUES (?, ?, ?)")->execute([$tenantB, "ZZ-ISOL task B {$uniq}", $r]);
    $taskB = (int)$conn->lastInsertId();
    $conn->prepare("INSERT INTO domains (tenant_id, domain_name) VALUES (?, ?)")->execute([$tenantA, "zz-isol-a-{$uniq}.invalid"]);
    $domainA = (int)$conn->lastInsertId();
    $conn->prepare("INSERT INTO domains (tenant_id, domain_name) VALUES (?, ?)")->execute([$tenantB, "zz-isol-b-{$uniq}.invalid"]);
    $domainB = (int)$conn->lastInsertId();

    $pairs = [
        ['asset',   'analystCanAccessAsset',   $assetA,   $assetB],
        ['change',  'analystCanAccessChange',  $changeA,  $changeB],
        ['problem', 'analystCanAccessProblem', $problemA, $problemB],
        ['task',    'analystCanAccessTask',    $taskA,    $taskB],
        ['domain',  'analystCanAccessDomain',  $domainA,  $domainB],
    ];
    foreach ($pairs as [$label, $fn, $own, $foreign]) {
        ok("POSITIVE CONTROL: own {$label} is reachable", $fn($conn, $r, $own));
        ok("other-tenant {$label} is denied by id", $fn($conn, $r, $foreign) === false);
    }
    ok('unknown asset id is denied (no oracle)', analystCanAccessAsset($conn, $r, 2147483647) === false);

    $classId = (int)$conn->query("SELECT id FROM cmdb_classes ORDER BY id LIMIT 1")->fetchColumn();
    if ($classId > 0) {
        $conn->prepare("INSERT INTO cmdb_objects (class_id, name, tenant_id) VALUES (?, ?, ?)")->execute([$classId, "ZZ-ISOL CI A {$uniq}", $tenantA]);
        $ciA = (int)$conn->lastInsertId();
        $conn->prepare("INSERT INTO cmdb_objects (class_id, name, tenant_id) VALUES (?, ?, ?)")->execute([$classId, "ZZ-ISOL CI B {$uniq}", $tenantB]);
        $ciB = (int)$conn->lastInsertId();
        ok('POSITIVE CONTROL: own CI is reachable', analystCanAccessCmdbObject($conn, $r, $ciA));
        ok('other-tenant CI is denied by id', analystCanAccessCmdbObject($conn, $r, $ciB) === false);
    } else {
        echo "  SKIP  CMDB pair needs a cmdb_classes row\n";
    }

    // ── 7. Global-search branches beyond tickets (Fase 1) ──
    // Mirrors api/system/global_search.php: assets go through activeTenantFilter
    // (same-tenant only), knowledge through knowledgeTenantFilterForCompany
    // where NULL means SHARED WITH EVERY COMPANY — deliberately different from
    // tickets, so the shared article must stay visible while B's must not.
    echo "\n7. Global search branches (assets/knowledge):\n";
    [$aSql, $aArgs] = activeTenantFilter($conn, $r, 'o');
    $ast = $conn->prepare("SELECT o.id, o.tenant_id FROM assets o WHERE o.hostname LIKE ?{$aSql}");
    $ast->execute(array_merge(["%ZZ-ISOL-HOST-%{$uniq}%"], $aArgs));
    $aHits = $ast->fetchAll(PDO::FETCH_ASSOC);
    $aIds = array_map(fn($row) => (int)$row['id'], $aHits);
    ok('POSITIVE CONTROL: asset search finds own-tenant host', in_array($assetA, $aIds, true), json_encode($aHits));
    ok('zero cross-tenant asset hits', !in_array($assetB, $aIds, true), json_encode($aHits));

    $conn->prepare("INSERT INTO knowledge_articles (tenant_id, title, author_id) VALUES (?, ?, ?)")->execute([$tenantA, "ZZ-ISOL article A {$uniq}", $r]);
    $conn->prepare("INSERT INTO knowledge_articles (tenant_id, title, author_id) VALUES (?, ?, ?)")->execute([$tenantB, "ZZ-ISOL article B {$uniq}", $r]);
    $conn->prepare("INSERT INTO knowledge_articles (tenant_id, title, author_id) VALUES (NULL, ?, ?)")->execute(["ZZ-ISOL shared article {$uniq}", $r]);
    [$kSql, $kArgs] = knowledgeTenantFilter($conn, $r, 'a');
    $kst = $conn->prepare("SELECT a.id, a.title, a.tenant_id FROM knowledge_articles a WHERE a.title LIKE ?{$kSql}");
    $kst->execute(array_merge(["%ZZ-ISOL%{$uniq}%"], $kArgs));
    $kHits = $kst->fetchAll(PDO::FETCH_ASSOC);
    $kTitles = array_column($kHits, 'title');
    $kTenants = array_map(fn($row) => $row['tenant_id'] === null ? 'shared' : (int)$row['tenant_id'], $kHits);
    ok('POSITIVE CONTROL: knowledge search finds own-tenant article', in_array("ZZ-ISOL article A {$uniq}", $kTitles, true), json_encode($kTitles));
    ok('POSITIVE CONTROL: shared (NULL-tenant) article stays visible', in_array("ZZ-ISOL shared article {$uniq}", $kTitles, true), json_encode($kTitles));
    ok('zero cross-tenant knowledge hits', !in_array($tenantB, $kTenants, true), json_encode($kHits));
} catch (Throwable $e) {
    ok('the run completed', false, get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
} finally {
    if (session_status() === PHP_SESSION_ACTIVE) { unset($_SESSION['active_tenant_id'], $_SESSION['active_tenant_all']); }
    else { if (isset($_SESSION['active_tenant_id'])) unset($_SESSION['active_tenant_id']); if (isset($_SESSION['active_tenant_all'])) unset($_SESSION['active_tenant_all']); }
    if ($conn->inTransaction()) $conn->rollBack();
}

// Rollback proof: nothing with our marker may survive.
$left = 0;
try {
    $left += (int)$conn->query("SELECT COUNT(*) FROM tickets WHERE ticket_number LIKE 'ZZ-ISOL-{$uniq}-%'")->fetchColumn();
    $left += (int)$conn->query("SELECT COUNT(*) FROM users WHERE email LIKE 'zz-isol-%-{$uniq}@example.invalid'")->fetchColumn();
    $left += (int)$conn->query("SELECT COUNT(*) FROM tenants WHERE slug IN ('zz-isol-a-{$uniq}', 'zz-isol-b-{$uniq}')")->fetchColumn();
    $left += (int)$conn->query("SELECT COUNT(*) FROM assets WHERE hostname LIKE 'ZZ-ISOL-HOST-%-{$uniq}'")->fetchColumn();
    $left += (int)$conn->query("SELECT COUNT(*) FROM changes WHERE title LIKE 'ZZ-ISOL change % {$uniq}'")->fetchColumn();
    $left += (int)$conn->query("SELECT COUNT(*) FROM problems WHERE title LIKE 'ZZ-ISOL problem % {$uniq}'")->fetchColumn();
    $left += (int)$conn->query("SELECT COUNT(*) FROM tasks WHERE title LIKE 'ZZ-ISOL task % {$uniq}'")->fetchColumn();
    $left += (int)$conn->query("SELECT COUNT(*) FROM domains WHERE domain_name LIKE 'zz-isol-%-{$uniq}.invalid'")->fetchColumn();
    $left += (int)$conn->query("SELECT COUNT(*) FROM knowledge_articles WHERE title LIKE 'ZZ-ISOL%{$uniq}%'")->fetchColumn();
    try { $left += (int)$conn->query("SELECT COUNT(*) FROM cmdb_objects WHERE name LIKE 'ZZ-ISOL CI % {$uniq}'")->fetchColumn(); } catch (Throwable $e) { /* class-less installs */ }
} catch (Throwable $e) { /* count itself failed — report below */ }
ok('nothing survived the run (rolled back)', $left === 0, "{$left} test rows left");

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail ? 1 : 0);
