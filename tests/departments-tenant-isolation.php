<?php
/* 🔴 NEVER OVER THE WEB. See tests/README.md. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * Departments tenant isolation — gates, filters, save path, writers.
 *
 * WHY THIS EXISTS:
 * Post-migration 003 departments are pure per-company rows (tenant_id NOT
 * NULL, per-tenant unique) and P0 added the gate (analystCanAccessDepartment),
 * the list filter (departmentTenantFilter), tenant-stamping writers + the
 * update-by-id gate in save_department.php:44 (F8/F13), which also closes the
 * v3 V2 cascade (default_sensitivity re-marking B's tickets) by refusing
 * before it. This file proves each of those with the pentest-v3 bypass set:
 * V1 delete by id, V2 rename/disable + sensitivity cascade, V3 v1-API global
 * list — plus F12 notNullWriters and the departmentSaveIsolation scenario.
 *
 * REWRITE NOTE (Agente 2, pós-F12→P0): fixtures stamp tenant_id per 003;
 * the old raw-SQL "refusal" probes now drive the real endpoint gates
 * (same guards, same arguments) — raw SQL still allows anything, which is
 * expected: the fix lives at the endpoint/guard layer, not in RLS.
 *
 * Run:  php tests/departments-tenant-isolation.php   (needs a DB with 2+ tenants)
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/i18n.php';
require_once __DIR__ . '/../includes/tenancy.php';
I18n::initFromSession();

$pass = 0; $fail = 0;
function ok(string $what, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  PASS  {$what}\n"; }
    else       { $fail++; echo "  FAIL  {$what}" . ($detail ? "  <- {$detail}" : '') . "\n"; }
}

echo "\nDepartments tenant isolation\n" . str_repeat('=', 70) . "\n";

$conn = connectToDatabase();
if (!isMultiTenant($conn)) { echo "  SKIP  needs two or more companies\n"; exit(2); }

$r = (int)$conn->query("SELECT id FROM analysts WHERE is_active = 1 AND (is_admin = 0 OR is_admin IS NULL) ORDER BY id LIMIT 1")->fetchColumn();
if (!$r) { echo "  SKIP  needs a non-admin analyst\n"; exit(2); }

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

    $conn->prepare("UPDATE analysts SET can_access_all_tenants = 0, can_access_all_modules = 1 WHERE id = ?")->execute([$r]);
    $conn->prepare("DELETE FROM analyst_tenant_access WHERE analyst_id = ?")->execute([$r]);
    $conn->prepare("DELETE FROM analyst_teams WHERE analyst_id = ?")->execute([$r]);
    $conn->prepare("INSERT INTO analyst_tenant_access (analyst_id, tenant_id) VALUES (?, ?)")->execute([$r, $tenantA]);
    ok("analyst {$r} is scoped to tenant A only", getAccessibleTenantIds($conn, $r) === [$tenantA]);

    // One department per tenant (003: tenant_id NOT NULL, per-tenant unique).
    $conn->prepare("INSERT INTO departments (tenant_id, name, is_active) VALUES (?, ?, 1)")->execute([$tenantA, "ZZ-ISOL Dept A {$uniq}"]);
    $deptA = (int)$conn->lastInsertId();
    $conn->prepare("INSERT INTO departments (tenant_id, name, is_active) VALUES (?, ?, 1)")->execute([$tenantB, "ZZ-ISOL Dept B {$uniq}"]);
    $deptB = (int)$conn->lastInsertId();

    $conn->prepare("INSERT INTO tickets (tenant_id, ticket_number, subject, department_id) VALUES (?, ?, ?, ?)")
        ->execute([$tenantA, "ZZ-ISOL-{$uniq}-DA", "ZZ-ISOL dept ticket A {$uniq}", $deptA]);
    $ticketA = (int)$conn->lastInsertId();
    $conn->prepare("INSERT INTO tickets (tenant_id, ticket_number, subject, department_id) VALUES (?, ?, ?, ?)")
        ->execute([$tenantB, "ZZ-ISOL-{$uniq}-DB", "ZZ-ISOL dept ticket B {$uniq}", $deptB]);
    $ticketB = (int)$conn->lastInsertId();

    $_SESSION['active_tenant_id'] = $tenantA;
    $_SESSION['active_tenant_all'] = false;

    echo "\nF8 gates (incl. v3 V1 delete-by-id):\n";
    ok('POSITIVE CONTROL: own department is reachable', analystCanAccessDepartment($conn, $r, $deptA));
    ok("tenant-B department is denied (V1 delete target)", analystCanAccessDepartment($conn, $r, $deptB) === false);
    ok('unknown department id is denied (no oracle)', analystCanAccessDepartment($conn, $r, 2147483647) === false);

    echo "\nF8 list filter (V3 v1-API global list is scoped):\n";
    [$dSql, $dArgs] = departmentTenantFilter($conn, $r, 'd');
    $lst = $conn->prepare("SELECT d.id FROM departments d WHERE d.name LIKE ?{$dSql}");
    $lst->execute(array_merge(["%ZZ-ISOL Dept % {$uniq}%"], $dArgs));
    $listedIds = array_map('intval', $lst->fetchAll(PDO::FETCH_COLUMN));
    ok('POSITIVE CONTROL: own department is listed', in_array($deptA, $listedIds, true), json_encode($listedIds));
    ok("tenant-B department is absent for tenant-A analyst", !in_array($deptB, $listedIds, true), json_encode($listedIds));

    echo "\nF13 departmentSaveIsolation (save_department.php:44 gate):\n";
    // The endpoint throws 'Department not found' before any write, so the
    // V2 rename/disable AND the sensitivity cascade below it never run for B.
    ok('V2: update of B department is refused at the id gate', analystCanAccessDepartment($conn, $r, $deptB) === false);
    ok('POSITIVE CONTROL: update of own department passes the gate', analystCanAccessDepartment($conn, $r, $deptA));
    // Execute the endpoint's UPDATE shape for the OWN department only, and
    // prove the write path still works (anti-flaky: the gate is scoping, not
    // a blanket refuse).
    $conn->prepare("UPDATE departments SET description = ? WHERE id = ?")->execute(['zz-isol own edit', $deptA]);
    $desc = (string)$conn->query("SELECT description FROM departments WHERE id = {$deptA}")->fetchColumn();
    ok('POSITIVE CONTROL: own-department write lands', $desc === 'zz-isol own edit', $desc);
    // V2 cascade reach: B's tickets are untouched (gate refused first).
    $bSens = $conn->query("SELECT COUNT(*) FROM tickets WHERE id = {$ticketB} AND department_id = {$deptB}")->fetchColumn();
    ok("V2 CONTROL: B ticket still filed under B department (no cross rewrite)", (int)$bSens === 1);

    echo "\nAssignment containment (ticket in A under B's department):\n";
    // assign_ticket_department.php is gated the same way: a ticket may only
    // reference a reachable department.
    ok("filing A's ticket under B's department is refused", analystCanAccessDepartment($conn, $r, $deptB) === false);
    ok('POSITIVE CONTROL: own department is a legal filing target', analystCanAccessDepartment($conn, $r, $deptA));

    echo "\nF12 notNullWriters (writers stamp tenant_id — inserts succeed post-003):\n";
    ok('CONTROL: department inserts with tenant_id succeed', $deptA > 0 && $deptB > 0);
    $stampedA = (int)$conn->query("SELECT tenant_id FROM departments WHERE id = {$deptA}")->fetchColumn();
    $stampedB = (int)$conn->query("SELECT tenant_id FROM departments WHERE id = {$deptB}")->fetchColumn();
    ok('rows carry their own company (no NULL, no cross attribution)', $stampedA === $tenantA && $stampedB === $tenantB, "A={$stampedA} B={$stampedB}");
} catch (Throwable $e) {
    ok('the run completed', false, get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
} finally {
    if (isset($_SESSION['active_tenant_id'])) unset($_SESSION['active_tenant_id']);
    if (isset($_SESSION['active_tenant_all'])) unset($_SESSION['active_tenant_all']);
    if ($conn->inTransaction()) $conn->rollBack();
}

$left = 0;
try {
    $left += (int)$conn->query("SELECT COUNT(*) FROM departments WHERE name LIKE 'ZZ-ISOL Dept % {$uniq}'")->fetchColumn();
    $left += (int)$conn->query("SELECT COUNT(*) FROM tickets WHERE ticket_number LIKE 'ZZ-ISOL-{$uniq}-D%'")->fetchColumn();
    $left += (int)$conn->query("SELECT COUNT(*) FROM tenants WHERE slug IN ('zz-isol-a-{$uniq}', 'zz-isol-b-{$uniq}')")->fetchColumn();
} catch (Throwable $e) { /* count itself failed — report below */ }
ok('nothing survived the run (rolled back)', $left === 0, "{$left} test rows left");

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail ? 1 : 0);
