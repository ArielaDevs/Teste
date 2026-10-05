<?php
/* 🔴 NEVER OVER THE WEB. This test writes probe rows to real tables (a probe
   tenant, SLA calendar, department and report pack, all prefixed ZZ_SPRINT1_)
   and deletes them in its closing lines. Served over HTTP it becomes an
   unauthenticated write endpoint. See tests/README.md. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * Sprint 1 tenant-isolation proof — the gaps docs/multitenancy-gaps.md asserts,
 * executed rather than grepped.
 *
 * Each check asserts "this surface IS isolated". On a pre-Sprint-2 codebase the
 * isolation checks FAIL (proving the leak) while the positive controls PASS
 * (proving the checker itself works). After Sprint 2 the same file must go
 * fully green with no edits — that is the acceptance criterion.
 *
 *   php tests/tenant-isolation/run.php
 *
 * Behaviour on a single-company install: sections A/B/D run as pure
 * static+schema assertions. Section C needs two tenants, so it creates a probe
 * tenant B alongside Default, runs the unscoped SELECTs the APIs perform
 * today, and deletes everything it made — the install returns to N=1. Probe
 * rows are prefixed ZZ_SPRINT1_ so a half-finished run is trivial to spot and
 * safe to delete by hand.
 *
 * ⚠️ Every "it leaks" assertion is paired with a POSITIVE CONTROL on a surface
 * that is already isolated (tickets). A checker that fails everything would
 * otherwise look like a codebase that leaks everything.
 */

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/tenancy.php';

$APP = dirname(__DIR__, 2);

$pass = $fail = $skip = 0;

function check(string $what, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    if ($ok) { $pass++; echo "  [PASS] $what\n"; }
    else { $fail++; echo "  [FAIL] $what" . ($detail !== '' ? " — $detail" : '') . "\n"; }
}
function skipped(string $what, string $why): void
{
    global $skip; $skip++;
    echo "  [SKIP] $what — $why\n";
}
function heading(string $t): void { echo "\n$t\n" . str_repeat('-', strlen($t)) . "\n"; }

/** File source with block/line comments stripped, so doc comments quoting old code don't false-positive. */
function code(string $path): string
{
    $s = @file_get_contents($path);
    if (!is_string($s)) return '';
    $s = preg_replace('~/\*.*?\*/~s', '', $s);
    $out = [];
    foreach (explode("\n", $s) as $line) {
        $t = ltrim($line);
        if ($t === '' || str_starts_with($t, '//') || str_starts_with($t, '#') || str_starts_with($t, '*')) continue;
        $out[] = $line;
    }
    return implode("\n", $out);
}

function mentionsTenantScope(string $src): bool
{
    foreach (['tenant_id', 'TenantFilter', 'analystCanAccess', 'getTenantConfigRows', 'getAccessibleTenantIds'] as $needle) {
        if (strpos($src, $needle) !== false) return true;
    }
    return false;
}

$conn = null;
try { $conn = connectToDatabase(); } catch (Exception $e) { /* reported per-section */ }

echo "FreeITSM — Sprint 1 tenant-isolation proof\n";
echo "Expectation on pre-Sprint-2 code: isolation checks FAIL (gap proven), controls PASS.\n";

// ── A. Schema: the Sprint 2 tables carry no tenant_id ─────────────────────────
heading('A  Sprint 2 tables lack tenant_id (gap matrix docs/multitenancy-gaps.md)');

if ($conn === null) {
    skipped('schema checks', 'no database connection');
} else {
    foreach (['departments', 'sla_calendars', 'sla_notification_rules', 'notifications', 'report_packs'] as $table) {
        $has = tenancyColumnExists($conn, $table, 'tenant_id');
        check("$table is tenant-scoped", $has === true, $has ? '' : 'no tenant_id column — cross-tenant read/delete by id is reachable');
    }
    // POSITIVE CONTROLS — these are isolated today, so the column check itself is trusted.
    check('CONTROL: tickets is tenant-scoped', tenancyColumnExists($conn, 'tickets', 'tenant_id') === true);
    check('CONTROL: ticket_reply_templates is tenant-scoped', tenancyColumnExists($conn, 'ticket_reply_templates', 'tenant_id') === true);
}

// ── B. Static: the endpoints that act by id apply no tenant scope ─────────────
heading('B  By-id endpoints apply no tenant scope');

$scopedById = [
    'SLA calendar delete (api/tickets/delete_sla_calendar.php)' => 'api/tickets/delete_sla_calendar.php',
    'SLA calendar read (api/tickets/get_sla_calendar.php)' => 'api/tickets/get_sla_calendar.php',
    'SLA notification rule delete (api/tickets/delete_sla_notification_rule.php)' => 'api/tickets/delete_sla_notification_rule.php',
    'department delete (api/tickets/delete_department.php)' => 'api/tickets/delete_department.php',
    'department list (api/tickets/get_departments.php)' => 'api/tickets/get_departments.php',
    'report pack get (api/reporting/packs/get.php)' => 'api/reporting/packs/get.php',
];
foreach ($scopedById as $what => $rel) {
    $src = code("$APP/$rel");
    if ($src === '') { skipped("$what is tenant-gated", "file not readable: $rel"); continue; }
    check("$what is tenant-gated", mentionsTenantScope($src) === true, 'no tenant_id / TenantFilter / analystCanAccess reference in executable code');
}
// The by-id write gate checks ownership but not company: a shared template of
// company B is writable by a capable analyst of company A. The file mentions
// tenant on the LIST path (getTenantConfigRows), so the check targets the gate
// function body specifically.
$gateSrc = code("$APP/includes/reply_templates.php");
$gateBody = '';
if ($gateSrc !== '' && preg_match('/function replyTemplateWriteScope.*?^}/ms', $gateSrc, $m)) {
    $gateBody = $m[0];
}
check(
    'reply template write gate (replyTemplateWriteScope) is tenant-gated',
    $gateBody !== '' && mentionsTenantScope($gateBody) === true,
    'gate selects analyst_id only — shared templates are writable cross-tenant'
);
// POSITIVE CONTROL — the ticket thread endpoint gates on the ticket's company.
$ticketThread = code("$APP/api/tickets/get_ticket_thread.php");
check(
    'CONTROL: ticket thread endpoint gates on company',
    $ticketThread !== '' && strpos($ticketThread, 'analystCanAccessTicket(') !== false,
    'control itself broken — do not trust the failures above'
);

// ── C. Behavioural: the unscoped SELECTs return another company\'s row ────────
heading('C  Unscoped SELECTs return another company\'s row (probe rows, cleaned up)');

if ($conn === null) {
    skipped('behavioural cross-tenant reads', 'no database connection');
} else {
    $probe = 'ZZ_SPRINT1_' . bin2hex(random_bytes(3));
    $made = ['tenant' => null, 'sla' => null, 'dept' => null, 'pack' => null];
    $multiBefore = isMultiTenant($conn);
    try {
        // Probe tenant B. On an N=1 install this wakes multi-tenancy for the
        // duration of the test; cleanup below returns the install to N=1.
        $st = $conn->prepare("INSERT INTO tenants (name, slug, is_default, is_active) VALUES (?, ?, 0, 1)");
        $st->execute([$probe . '_tenantB', strtolower($probe) . '-b']);
        $made['tenant'] = (int) $conn->lastInsertId();

        // F12 probe stamping (Agente 2, pós-002/003/004): calendars/departments
        // são NOT NULL tenant-scoped — sem carimbo o setup quebra (erro 1364).
        // Packs carimbados com o tenant do probe (NULL = pessoal por desenho
        // 004). Probes tenancyColumnExists mantêm compat pré-migração.
        // Padrão igual às fixtures sla/departments já carimbadas.
        if (tenancyColumnExists($conn, 'sla_calendars', 'tenant_id')) {
            $st = $conn->prepare("INSERT INTO sla_calendars (name, timezone, is_default, is_active, tenant_id) VALUES (?, 'Europe/London', 0, 1, ?)");
            $st->execute([$probe . '_calendar', $made['tenant']]);
        } else {
            $st = $conn->prepare("INSERT INTO sla_calendars (name, timezone, is_default, is_active) VALUES (?, 'Europe/London', 0, 1)");
            $st->execute([$probe . '_calendar']);
        }
        $made['sla'] = (int) $conn->lastInsertId();

        if (tenancyColumnExists($conn, 'departments', 'tenant_id')) {
            $st = $conn->prepare("INSERT INTO departments (name, description, is_active, display_order, tenant_id) VALUES (?, ?, 1, 0, ?)");
            $st->execute([$probe . '_dept', 'sprint 1 probe', $made['tenant']]);
        } else {
            $st = $conn->prepare("INSERT INTO departments (name, description, is_active, display_order) VALUES (?, ?, 1, 0)");
            $st->execute([$probe . '_dept', 'sprint 1 probe']);
        }
        $made['dept'] = (int) $conn->lastInsertId();

        if (tenancyColumnExists($conn, 'report_packs', 'tenant_id')) {
            $st = $conn->prepare("INSERT INTO report_packs (name, description, design, tenant_id) VALUES (?, ?, '{}', ?)");
            $st->execute([$probe . '_pack', 'sprint 1 probe', $made['tenant']]);
        } else {
            $st = $conn->prepare("INSERT INTO report_packs (name, description, design) VALUES (?, ?, '{}')");
            $st->execute([$probe . '_pack', 'sprint 1 probe']);
        }
        $made['pack'] = (int) $conn->lastInsertId();

        // The exact unscoped shapes the APIs use today (see docs matrix §2).
        $st = $conn->prepare("SELECT id FROM sla_calendars WHERE id = ?");
        $st->execute([$made['sla']]);
        $leakSla = $st->fetchColumn() !== false;

        $st = $conn->prepare("SELECT id FROM departments WHERE id = ?");
        $st->execute([$made['dept']]);
        $leakDept = $st->fetchColumn() !== false;

        $st = $conn->prepare("SELECT id FROM report_packs WHERE id = ?");
        $st->execute([$made['pack']]);
        $leakPack = $st->fetchColumn() !== false;

        check('SLA calendar by id is unreachable cross-tenant', $leakSla === false, $leakSla ? 'unscoped SELECT returned the probe row' : '');
        check('department by id is unreachable cross-tenant', $leakDept === false, $leakDept ? 'unscoped SELECT returned the probe row' : '');
        check('report pack by id is unreachable cross-tenant', $leakPack === false, $leakPack ? 'unscoped SELECT returned the probe row' : '');

        // POSITIVE CONTROL — the ticket gate denies an analyst with no access.
        $ghost = 2147483600;
        if (isMultiTenant($conn)) {
            check('CONTROL: unknown ticket id is denied', analystCanAccessTicket($conn, 1, $ghost) === false);
        } else {
            skipped('CONTROL: unknown ticket id is denied', 'single-company install — guards short-circuit to true by design');
        }
    } catch (Exception $e) {
        check('probe setup ran', false, $e->getMessage());
    } finally {
        // Cleanup in reverse order; tenant last so FK-holding rows go first
        // (none of the probe tables carry tenant FKs yet — that is the gap —
        // but ordering keeps this correct once Sprint 2 adds them).
        foreach (['pack' => 'report_packs', 'dept' => 'departments', 'sla' => 'sla_calendars'] as $key => $table) {
            if (!empty($made[$key])) {
                try { $conn->prepare("DELETE FROM `$table` WHERE id = ?")->execute([$made[$key]]); } catch (Exception $e) { /* report below */ }
            }
        }
        if (!empty($made['tenant'])) {
            try { $conn->prepare("DELETE FROM tenants WHERE id = ?")->execute([$made['tenant']]); } catch (Exception $e) { /* report below */ }
        }
        $leftovers = 0;
        foreach (['tenants' => 'name', 'sla_calendars' => 'name', 'departments' => 'name', 'report_packs' => 'name'] as $table => $col) {
            try {
                $st = $conn->prepare("SELECT COUNT(*) FROM `$table` WHERE `$col` LIKE ?");
                $st->execute([$probe . '%']);
                $leftovers += (int) $st->fetchColumn();
            } catch (Exception $e) { /* table shape changed mid-test — surface, don't hide */ }
        }
        check('probe rows fully cleaned up', $leftovers === 0, $leftovers ? "$leftovers probe row(s) left — delete WHERE name LIKE '$probe%'" : '');
        if (!$multiBefore) {
            check('install returned to single-tenant', isMultiTenant($conn) === false, 'probe tenant was not removed');
        }
    }
}

// ── D. Notifications router carries no tenant scope ──────────────────────────
heading('D  Notifications router carries no tenant scope');

$router = code("$APP/includes/notifications_router.php");
if ($router === '') {
    skipped('notifications router is tenant-scoped', 'includes/notifications_router.php not readable');
} else {
    check(
        'notifications router is tenant-scoped',
        mentionsTenantScope($router) === true,
        'no tenant_id / TenantFilter / analystCanAccess reference in executable code'
    );
}

echo "\n" . str_repeat('-', 60) . "\n";
echo "  {$pass} passed, {$fail} failed" . ($skip ? ", {$skip} skipped" : '') . "\n";
echo "  Pre-Sprint-2 expectation: failures in A/B/C/D are the proven gaps.\n";
exit($fail > 0 ? 1 : 0);
