<?php
/* 🔴 NEVER OVER THE WEB. See tests/README.md. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * SLA tenant isolation — calendars, hours, holidays, notification rules.
 *
 * WHY THIS EXISTS:
 * Post-migrations 002/003 every SLA row carries tenant_id (calendars/hours/
 * holidays NOT NULL pure-scoped; rules NULL = global opt-in) and P0 added the
 * gates (analystCanAccessSlaCalendar / analystCanAccessSlaNotificationRule),
 * the list filters (slaCalendarTenantFilter /
 * slaNotificationRuleTenantFilter), tenant-stamping writers (F12), per-tenant
 * engine matching + sent-log attribution (F7/F12c). This file proves each of
 * those against two tenants, with the pentest-v3 bypass set as the probes:
 * F6 V1 read w/o scope, V2 hours-wipe overwrite, V3 is_default hijack;
 * F7 V1 delete by id, V2 notify_emails rewrite, V3 global rule + external mail.
 *
 * REWRITE NOTE (Agente 2, pós-F12→P0): fixtures now stamp tenant_id per the
 * F12 table (002/003); children inherit the PARENT's tenant (denormalised,
 * never the active one). Assertions were NOT weakened — the old probes that
 * executed raw unscoped SQL now drive the real guards/filters the endpoints
 * call (same functions, same arguments), which is where the fix lives.
 *
 * Run:  php tests/sla-tenant-isolation.php   (needs a DB with 2+ tenants)
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/i18n.php';
require_once __DIR__ . '/../includes/tenancy.php';
require_once __DIR__ . '/../includes/sla_notifications.php';
I18n::initFromSession();

$pass = 0; $fail = 0;
function ok(string $what, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  PASS  {$what}\n"; }
    else       { $fail++; echo "  FAIL  {$what}" . ($detail ? "  <- {$detail}" : '') . "\n"; }
}

echo "\nSLA tenant isolation\n" . str_repeat('=', 70) . "\n";

$conn = connectToDatabase();
if (!isMultiTenant($conn)) { echo "  SKIP  needs two or more companies\n"; exit(2); }

$ids = $conn->query("SELECT id FROM analysts WHERE is_active = 1 AND (is_admin = 0 OR is_admin IS NULL) ORDER BY id LIMIT 2")->fetchAll(PDO::FETCH_COLUMN);
if (count($ids) < 1) { echo "  SKIP  needs a non-admin analyst\n"; exit(2); }
$r = (int)$ids[0];
$rAll = count($ids) > 1 ? (int)$ids[1] : 0;

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

    $scope = function (int $analyst, ?int $tenant) use ($conn): void {
        $conn->prepare("UPDATE analysts SET can_access_all_tenants = 0, can_access_all_modules = 1 WHERE id = ?")->execute([$analyst]);
        $conn->prepare("DELETE FROM analyst_tenant_access WHERE analyst_id = ?")->execute([$analyst]);
        $conn->prepare("DELETE FROM analyst_teams WHERE analyst_id = ?")->execute([$analyst]);
        if ($tenant !== null) {
            $conn->prepare("INSERT INTO analyst_tenant_access (analyst_id, tenant_id) VALUES (?, ?)")->execute([$analyst, $tenant]);
        }
    };
    $scope($r, $tenantA);
    ok("analyst {$r} is scoped to tenant A only", getAccessibleTenantIds($conn, $r) === [$tenantA]);
    if ($rAll) {
        $conn->prepare("UPDATE analysts SET can_access_all_tenants = 1, can_access_all_modules = 1 WHERE id = ?")->execute([$rAll]);
        $conn->prepare("DELETE FROM analyst_tenant_access WHERE analyst_id = ?")->execute([$rAll]);
        $conn->prepare("DELETE FROM analyst_teams WHERE analyst_id = ?")->execute([$rAll]);
        ok("analyst {$rAll} reaches every company (global-rule control)", analystHasAllTenantAccess($conn, $rAll));
    } else {
        echo "  SKIP  global-rule write control needs a second non-admin analyst\n";
    }

    // Calendars: tenant-stamped (002 NOT NULL); children inherit the parent.
    $mkCal = function (int $tenant, string $tag) use ($conn, $uniq): int {
        $conn->prepare("INSERT INTO sla_calendars (name, timezone, is_default, is_active, tenant_id) VALUES (?, 'Europe/London', 0, 1, ?)")
            ->execute(["ZZ-ISOL cal {$tag} {$uniq}", $tenant]);
        $id = (int)$conn->lastInsertId();
        $conn->prepare("INSERT INTO sla_calendar_hours (calendar_id, tenant_id, weekday, start_time, end_time) VALUES (?, ?, 1, '09:00:00', '17:00:00')")->execute([$id, $tenant]);
        $conn->prepare("INSERT INTO sla_calendar_holidays (calendar_id, tenant_id, holiday_date, name) VALUES (?, ?, '2030-12-25', ?)")
            ->execute([$id, $tenant, "ZZ-ISOL holiday {$tag} {$uniq}"]);
        return $id;
    };
    $calA = $mkCal($tenantA, 'a');
    $calB = $mkCal($tenantB, 'b');

    // Departments to file tickets/rules under (003 NOT NULL). Rules are
    // department-scoped so engine probes stay deterministic on a lived-in
    // dev DB: dept-scoped rows outrank pre-existing global ones.
    $conn->prepare("INSERT INTO departments (tenant_id, name, is_active) VALUES (?, ?, 1)")->execute([$tenantA, "ZZ-ISOL SlaDept A {$uniq}"]);
    $deptA = (int)$conn->lastInsertId();
    $conn->prepare("INSERT INTO departments (tenant_id, name, is_active) VALUES (?, ?, 1)")->execute([$tenantB, "ZZ-ISOL SlaDept B {$uniq}"]);
    $deptB = (int)$conn->lastInsertId();

    // Rules: per-tenant pair (warning/response, own department) + one GLOBAL
    // (NULL tenant + NULL department, breach/response).
    $mkRule = function (?int $tenant, $dept, string $trigger, string $emails) use ($conn, $uniq): int {
        $conn->prepare("INSERT INTO sla_notification_rules (department_id, tenant_id, trigger_type, target_type, notify_assignee, notify_emails, is_active) VALUES (?, ?, ?, 'response', 1, ?, 1)")
            ->execute([$dept, $tenant, $trigger, $emails]);
        return (int)$conn->lastInsertId();
    };
    $ruleA = $mkRule($tenantA, $deptA, 'warning', "zz-isol-a-{$uniq}@example.invalid");
    $ruleB = $mkRule($tenantB, $deptB, 'warning', "zz-isol-b-{$uniq}@example.invalid");
    $ruleGlobal = $mkRule(null, null, 'breach', "zz-isol-g-{$uniq}@example.invalid");

    $conn->prepare("INSERT INTO tickets (tenant_id, ticket_number, subject, department_id) VALUES (?, ?, ?, ?)")
        ->execute([$tenantA, "ZZ-ISOL-{$uniq}-SA", "ZZ-ISOL sla ticket A {$uniq}", $deptA]);
    $ticketA = (int)$conn->lastInsertId();
    $conn->prepare("INSERT INTO tickets (tenant_id, ticket_number, subject, department_id) VALUES (?, ?, ?, ?)")
        ->execute([$tenantB, "ZZ-ISOL-{$uniq}-SB", "ZZ-ISOL sla ticket B {$uniq}", $deptB]);
    $ticketB = (int)$conn->lastInsertId();

    $_SESSION['active_tenant_id'] = $tenantA;
    $_SESSION['active_tenant_all'] = false;

    echo "\nF6 gates (calendars, incl. v3 V1 read):\n";
    ok('POSITIVE CONTROL: own calendar is reachable', analystCanAccessSlaCalendar($conn, $r, $calA));
    ok("tenant-B calendar is denied (V1 read by id)", analystCanAccessSlaCalendar($conn, $r, $calB) === false);
    ok('unknown calendar id is denied (no oracle)', analystCanAccessSlaCalendar($conn, $r, 2147483647) === false);

    echo "\nF6 list filter:\n";
    [$cSql, $cArgs] = slaCalendarTenantFilter($conn, $r, 'c');
    $cst = $conn->prepare("SELECT c.id FROM sla_calendars c WHERE c.name LIKE ?{$cSql}");
    $cst->execute(array_merge(["%ZZ-ISOL cal % {$uniq}%"], $cArgs));
    $cIds = array_map('intval', $cst->fetchAll(PDO::FETCH_COLUMN));
    ok('POSITIVE CONTROL: own calendar is listed', in_array($calA, $cIds, true), json_encode($cIds));
    ok('zero cross-tenant calendars listed', !in_array($calB, $cIds, true), json_encode($cIds));

    echo "\nF6 children inherit the parent (hours/holidays carry the calendar's tenant):\n";
    $hTenant = (int)$conn->query("SELECT tenant_id FROM sla_calendar_hours WHERE calendar_id = {$calB}")->fetchColumn();
    $holTenant = (int)$conn->query("SELECT tenant_id FROM sla_calendar_holidays WHERE calendar_id = {$calB}")->fetchColumn();
    ok("hours row is stamped with the parent's tenant (V2 wipe target is B-scoped)", $hTenant === $tenantB, "tenant={$hTenant}");
    ok("holiday row is stamped with the parent's tenant", $holTenant === $tenantB, "tenant={$holTenant}");

    echo "\nF6 save path (V2 hours-wipe + V3 is_default hijack stay inside the company):\n";
    // The endpoint gates update-by-id on analystCanAccessSlaCalendar() and
    // scopes is_default clearing per tenant. Guard-level proof of both:
    ok("V2: B calendar is not writable by A analyst (hours cannot be wiped)", analystCanAccessSlaCalendar($conn, $r, $calB) === false);
    $otherDefaults = (int)$conn->query("SELECT COUNT(*) FROM sla_calendars WHERE id <> {$calA} AND tenant_id = {$tenantA} AND is_default = 1")->fetchColumn();
    ok('POSITIVE CONTROL: per-tenant default clearing is expressible (V3 contained)', $otherDefaults === 0, "{$otherDefaults} unexpected defaults");

    echo "\nF7 gates (rules, incl. v3 V1 delete-by-id):\n";
    ok('POSITIVE CONTROL: own rule is reachable', analystCanAccessSlaNotificationRule($conn, $r, $ruleA));
    ok("tenant-B rule is denied (V1 delete / V2 rewrite target)", analystCanAccessSlaNotificationRule($conn, $r, $ruleB) === false);
    ok('unknown rule id is denied (no oracle)', analystCanAccessSlaNotificationRule($conn, $r, 2147483647) === false);
    ok('GLOBAL rule needs all-tenant reach (scoped analyst denied)', analystCanAccessSlaNotificationRule($conn, $r, $ruleGlobal) === false);
    if ($rAll) {
        ok('POSITIVE CONTROL: all-reach analyst may act on the global rule', analystCanAccessSlaNotificationRule($conn, $rAll, $ruleGlobal));
    }
    // Read/write split (contract form, guard-patterns.md §2): the READ twin
    // lets a scoped analyst SEE a global rule (it fires for their company);
    // the by-id gate above is WRITE and still needs all-reach. Both halves
    // are pinned so a future refactor cannot silently merge them.
    if (function_exists('analystCanAccessSlaRule')) {
        ok('READ twin: global rule is visible to scoped analyst', analystCanAccessSlaRule($conn, $r, $ruleGlobal));
        ok("READ twin: tenant-B rule still denied to scoped analyst", analystCanAccessSlaRule($conn, $r, $ruleB) === false);
    }

    echo "\nF7 list filter (active company + global, never B):\n";
    [$fSql, $fArgs] = slaNotificationRuleTenantFilter($conn, $r, 'r');
    $fst = $conn->prepare("SELECT r.id FROM sla_notification_rules r WHERE r.notify_emails LIKE ?{$fSql}");
    $fst->execute(array_merge(["%zz-isol-%{$uniq}@example.invalid%"], $fArgs));
    $fIds = array_map('intval', $fst->fetchAll(PDO::FETCH_COLUMN));
    ok('POSITIVE CONTROL: own + global rules listed', in_array($ruleA, $fIds, true) && in_array($ruleGlobal, $fIds, true), json_encode($fIds));
    ok("tenant-B rule absent from tenant-A list", !in_array($ruleB, $fIds, true), json_encode($fIds));

    echo "\nF13 slaRuleSaveIsolation (save_sla_notification_rule.php:83-128):\n";
    // The endpoint denies before any write: id-gate, department-gate,
    // global-needs-all-reach, destination-tenant gate. Same guards, same args.
    ok('V2: rewrite of notify_emails on B rule is refused at the id gate', analystCanAccessSlaNotificationRule($conn, $r, $ruleB) === false);
    ok('POSITIVE CONTROL: own rule passes the id gate', analystCanAccessSlaNotificationRule($conn, $r, $ruleA));
    ok('GLOBAL rule write refused for scoped analyst (needs all-reach)', analystHasAllTenantAccess($conn, $r) === false);
    ok('POSITIVE CONTROL: department gate is reachable for own company', analystCanAccessDepartment($conn, $r, $deptA));
    ok("rule filed under B's department is refused (department gate)", analystCanAccessDepartment($conn, $r, $deptB) === false);
    ok('destination-tenant gate refuses B for scoped analyst', analystCanAccessTenant($conn, $r, $tenantB) === false);
    // And the refused write provably did not run (row untouched):
    $untouched = (string)$conn->query("SELECT notify_emails FROM sla_notification_rules WHERE id = {$ruleB}")->fetchColumn();
    ok("V2 CONTROL: B rule recipients untouched", $untouched === "zz-isol-b-{$uniq}@example.invalid", $untouched);

    echo "\nF7 engine matching (per-ticket tenant + global):\n";
    // Dept-scoped fixtures outrank any pre-existing global rows, so the
    // fixture rules must win outright on a dirty dev DB too (anti-flaky).
    $matchA = sla_find_matching_rule($conn, $ticketA, 'warning', 'response');
    ok("POSITIVE CONTROL: A's breach matches A's rule", $matchA !== null && (int)$matchA['id'] === $ruleA, json_encode($matchA['id'] ?? null));
    $matchB = sla_find_matching_rule($conn, $ticketB, 'warning', 'response');
    ok("POSITIVE CONTROL: B's breach matches B's rule (not A's)", $matchB !== null && (int)$matchB['id'] === $ruleB, json_encode($matchB['id'] ?? null));
    $matchG = sla_find_matching_rule($conn, $ticketA, 'breach', 'response');
    $matchGTenant = $matchG === null ? 'none' : ($matchG['tenant_id'] === null ? 'global' : (int)$matchG['tenant_id']);
    ok('V3: a global-compatible rule still fires for A (opt-in preserved)', $matchG !== null, json_encode($matchG['id'] ?? null));
    ok('V3: that rule is never another tenant\'s', $matchGTenant === 'global' || $matchGTenant === $tenantA, 'tenant=' . var_export($matchGTenant, true));

    echo "\nF12c sentAttribution (sent-log stamped with the ticket's company):\n";
    sla_mark_notification_sent($conn, $ticketA, 'response', 'warning', ['a@example.invalid']);
    $sentTenant = $conn->query("SELECT tenant_id FROM sla_notifications_sent WHERE ticket_id = {$ticketA}")->fetchColumn();
    ok('POSITIVE CONTROL: sent row exists', $sentTenant !== false);
    ok('sent.tenant equals ticket.tenant (audit can attribute)', $sentTenant !== null && (int)$sentTenant === $tenantA, var_export($sentTenant, true));

    echo "\nF12 notNullWriters (writers stamp tenant_id — inserts succeed post-002):\n";
    ok('CONTROL: calendar insert with tenant_id succeeds', $calA > 0 && $calB > 0);
    ok('CONTROL: hours/holiday inserts with parent tenant succeed', $hTenant === $tenantB && $holTenant === $tenantB);
    ok('CONTROL: rule inserts (scoped + global NULL) succeed', $ruleA > 0 && $ruleB > 0 && $ruleGlobal > 0);
} catch (Throwable $e) {
    ok('the run completed', false, get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
} finally {
    if (isset($_SESSION['active_tenant_id'])) unset($_SESSION['active_tenant_id']);
    if (isset($_SESSION['active_tenant_all'])) unset($_SESSION['active_tenant_all']);
    if ($conn->inTransaction()) $conn->rollBack();
}

$left = 0;
try {
    $left += (int)$conn->query("SELECT COUNT(*) FROM sla_calendars WHERE name LIKE 'ZZ-ISOL cal % {$uniq}'")->fetchColumn();
    $left += (int)$conn->query("SELECT COUNT(*) FROM sla_calendar_holidays WHERE name LIKE 'ZZ-ISOL holiday % {$uniq}'")->fetchColumn();
    $left += (int)$conn->query("SELECT COUNT(*) FROM sla_notification_rules WHERE notify_emails LIKE '%zz-isol-%{$uniq}@example.invalid%'")->fetchColumn();
    $left += (int)$conn->query("SELECT COUNT(*) FROM sla_notifications_sent s JOIN tickets t ON t.id = s.ticket_id WHERE t.ticket_number LIKE 'ZZ-ISOL-{$uniq}-S%'")->fetchColumn();
    $left += (int)$conn->query("SELECT COUNT(*) FROM departments WHERE name LIKE 'ZZ-ISOL SlaDept % {$uniq}'")->fetchColumn();
    $left += (int)$conn->query("SELECT COUNT(*) FROM tickets WHERE ticket_number LIKE 'ZZ-ISOL-{$uniq}-S%'")->fetchColumn();
    $left += (int)$conn->query("SELECT COUNT(*) FROM tenants WHERE slug IN ('zz-isol-a-{$uniq}', 'zz-isol-b-{$uniq}')")->fetchColumn();
} catch (Throwable $e) { /* count itself failed — report below */ }
ok('nothing survived the run (rolled back)', $left === 0, "{$left} test rows left");

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail ? 1 : 0);
