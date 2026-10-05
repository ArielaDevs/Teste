<?php
/**
 * Verify tenant consistency — read-only pre/post-migration audit.
 *
 * Run BEFORE any tenant migration (and after, to prove zero orphans):
 *   php scripts/verify-tenant-consistency.php
 *   php scripts/verify-tenant-consistency.php --json   machine-readable report
 *
 * CHECKS
 * ------
 *  1. Orphans: rows whose tenant column points at a non-existent tenant.
 *  2. Cross-table drift: child.tenant != parent.tenant through the FK that links
 *     them (denormalised tenant_id copies must agree with their parent).
 *  3. Would-block: duplicate keys that would abort a planned UNIQUE index
 *     (e.g. two sla_calendars named alike that would share the default tenant).
 *  4. NULL inventory: per-table NULL counts with the designed meaning of NULL
 *     (scoped = Default-owned, shared, global/personal — see
 *     docs/migrations/multitenant-null-semantics.md).
 *
 * READ-ONLY: executes SELECT (and CHECKSUM-free probes) only. Exit 0 = clean,
 * 1 = inconsistencies found, 2 = boot/environment error. Probes use
 * information_schema, never SHOW COLUMNS, so behaviour is identical across
 * MySQL/MariaDB versions.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script is command-line only.\n");
}
ini_set('display_errors', 'stderr');

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';

$asJson = in_array('--json', array_slice($argv, 1), true);

try {
    $conn = connectToDatabase();
} catch (Throwable $e) {
    fwrite(STDERR, 'Database connection failed: ' . $e->getMessage() . "\n");
    exit(2);
}
$dbName = DB_NAME;

/** [childTable, childFkCol, parentTable, parentTenantCol] — validated at runtime. */
const TENANT_PARENT_MAP = [
    ['sla_calendar_hours', 'calendar_id', 'sla_calendars', 'tenant_id'],
    ['sla_calendar_holidays', 'calendar_id', 'sla_calendars', 'tenant_id'],
    ['sla_notification_rules', 'department_id', 'departments', 'tenant_id'],
    ['sla_notifications_sent', 'ticket_id', 'tickets', 'tenant_id'],
    ['report_pack_shares', 'pack_id', 'report_packs', 'tenant_id'],
    ['department_teams', 'department_id', 'departments', 'tenant_id'],
    ['tickets', 'department_id', 'departments', 'tenant_id'],
    ['knowledge_gap_tickets', 'ticket_id', 'tickets', 'tenant_id'],
];

/** Tables where NULL tenant_id is designed (not drift). Anything else = scoped. */
function tenantNullMeaning(string $table): string {
    static $map = [
        'knowledge_articles' => 'shared (visible to every company)',
        'knowledge_folders' => 'shared (visible to every company)',
        'ticket_types' => 'global default (per-company hide via tenant_config_hidden)',
        'ticket_origins' => 'global default (per-company hide via tenant_config_hidden)',
        'ticket_categories' => 'global default (per-company hide via tenant_config_hidden)',
        'ticket_resolution_codes' => 'global default (per-company hide via tenant_config_hidden)',
        'asset_types' => 'global default',
        'asset_status_types' => 'global default',
        'asset_locations' => 'global default (NULL = head office)',
        'ticket_reply_templates' => 'global default (NULL = shared team template)',
        'checklist_templates' => 'global default',
        'apikeys' => 'unpinned key (not bound to a company)',
        'auth_providers' => 'global provider (analyst login lists only NULL)',
        'target_mailboxes' => 'shared intake (routed per sender)',
        'messaging_channels' => 'shared intake (routed per sender phone)',
        'messaging_templates' => 'shared template',
        'sla_notification_rules' => 'global rule (fires for every company)',
        'report_packs' => 'personal pack (owner-only draft)',
    ];
    return $map[$table] ?? 'scoped (Default-owned — NULL means unmigrated or triage)';
}

function infoExists(PDO $conn, string $dbName, string $kind, string $table, string $name): bool {
    if ($kind === 'table') {
        $s = $conn->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = ? AND table_name = ?');
        $s->execute([$dbName, $table]);
    } else {
        $s = $conn->prepare('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = ? AND table_name = ? AND column_name = ?');
        $s->execute([$dbName, $table, $name]);
    }
    return (int)$s->fetchColumn() > 0;
}

$report = ['orphans' => [], 'drift' => [], 'would_block' => [], 'nulls' => []];
$issueCount = 0;

// Tenant tables = schema source of truth intersected with the live database.
$schema = require __DIR__ . '/../includes/db_verify_schema.php';
$tenantTables = [];
foreach ($schema as $table => $cols) {
    if ($table === 'tenants') continue;
    foreach (['tenant_id', 'customer_tenant_id'] as $c) {
        if (array_key_exists($c, $cols) && infoExists($conn, $dbName, 'table', $table, '')
            && infoExists($conn, $dbName, 'column', $table, $c)) {
            $tenantTables[$table] = $c;
        }
    }
}
$report['tables_scanned'] = count($tenantTables);

$tenantsLive = infoExists($conn, $dbName, 'table', 'tenants', '');
if (!$tenantsLive) {
    $report['fatal'] = 'tenants table missing — run migration 001 first; orphan/drift checks need it';
    outputReport($report, $asJson, 2);
}

// 1. Orphans + 4. NULL inventory.
foreach ($tenantTables as $table => $col) {
    $orphans = (int)$conn->query(
        "SELECT COUNT(*) FROM `{$table}` WHERE `{$col}` IS NOT NULL AND `{$col}` NOT IN (SELECT id FROM `tenants`)"
    )->fetchColumn();
    if ($orphans > 0) {
        $sample = $conn->query(
            "SELECT id, `{$col}` FROM `{$table}` WHERE `{$col}` IS NOT NULL AND `{$col}` NOT IN (SELECT id FROM `tenants`) LIMIT 5"
        )->fetchAll(PDO::FETCH_ASSOC);
        $report['orphans'][] = ['table' => $table, 'column' => $col, 'count' => $orphans, 'sample' => $sample];
        $issueCount++;
    }
    $nulls = (int)$conn->query("SELECT COUNT(*) FROM `{$table}` WHERE `{$col}` IS NULL")->fetchColumn();
    $total = (int)$conn->query("SELECT COUNT(*) FROM `{$table}`")->fetchColumn();
    $report['nulls'][] = ['table' => $table, 'column' => $col, 'nulls' => $nulls, 'total' => $total,
                           'meaning' => tenantNullMeaning($table)];
}

// 2. Cross-table drift (probed: every named table/column must exist).
foreach (TENANT_PARENT_MAP as [$child, $fk, $parent, $parentCol]) {
    if (!isset($tenantTables[$child]) || !infoExists($conn, $dbName, 'column', $child, $fk)
        || !infoExists($conn, $dbName, 'table', $parent, '') || !infoExists($conn, $dbName, 'column', $parent, $parentCol)) {
        continue; // pre-migration shape — nothing to compare yet
    }
    $childCol = $tenantTables[$child];
    // Different tenant (NULLs excluded: triage/global rows have no company to agree with).
    $n = (int)$conn->query(
        "SELECT COUNT(*) FROM `{$child}` c JOIN `{$parent}` p ON p.id = c.`{$fk}` " .
        "WHERE c.`{$childCol}` IS NOT NULL AND p.`{$parentCol}` IS NOT NULL AND c.`{$childCol}` <> p.`{$parentCol}`"
    )->fetchColumn();
    if ($n > 0) {
        $report['drift'][] = ['child' => $child, 'parent' => $parent, 'via' => $fk, 'mismatches' => $n];
        $issueCount++;
    }
    // Child points at a missing parent row (would break inheritance backfill).
    $missing = (int)$conn->query(
        "SELECT COUNT(*) FROM `{$child}` c LEFT JOIN `{$parent}` p ON p.id = c.`{$fk}` " .
        "WHERE c.`{$fk}` IS NOT NULL AND p.id IS NULL"
    )->fetchColumn();
    if ($missing > 0) {
        $report['drift'][] = ['child' => $child, 'parent' => $parent, 'via' => $fk,
                              'missing_parents' => $missing];
        $issueCount++;
    }
}

// 3. Would-block: planned UNIQUE (tenant_id, name) on calendars/departments.
// After default-backfill every NULL-tenant row shares one tenant, so any repeated
// name among them aborts the index build.
foreach (['sla_calendars', 'departments'] as $t) {
    if (!infoExists($conn, $dbName, 'table', $t, '') || !infoExists($conn, $dbName, 'column', $t, 'name')) continue;
    $hasTenant = infoExists($conn, $dbName, 'column', $t, 'tenant_id');
    $tenantExpr = $hasTenant ? "COALESCE(`tenant_id`, 0)" : '0';
    $dupes = $conn->query(
        "SELECT `name`, COUNT(*) n FROM `{$t}` GROUP BY {$tenantExpr}, `name` HAVING n > 1 LIMIT 5"
    )->fetchAll(PDO::FETCH_ASSOC);
    if (!empty($dupes)) {
        $report['would_block'][] = ['table' => $t, 'index' => 'UNIQUE(`tenant_id`,`name`)', 'duplicates' => $dupes];
        $issueCount++;
    }
}

function outputReport(array $report, bool $asJson, int $exit): void {
    if ($asJson) {
        echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
        exit($exit);
    }
    echo 'Tenant consistency report (' . ($report['tables_scanned'] ?? 0) . " tenant tables scanned)\n";
    echo str_repeat('=', 70) . "\n";
    if (!empty($report['fatal'])) {
        echo 'FATAL: ' . $report['fatal'] . "\n";
        exit($exit);
    }
    foreach ($report['orphans'] as $o) {
        echo "[ORPHAN] {$o['table']}.{$o['column']}: {$o['count']} row(s) point at missing tenants\n";
    }
    foreach ($report['drift'] as $d) {
        if (isset($d['mismatches'])) {
            echo "[DRIFT] {$d['child']} vs {$d['parent']} via {$d['via']}: {$d['mismatches']} tenant mismatch(es)\n";
        } else {
            echo "[DRIFT] {$d['child']} vs {$d['parent']} via {$d['via']}: {$d['missing_parents']} row(s) with missing parent\n";
        }
    }
    foreach ($report['would_block'] as $w) {
        echo "[WOULD-BLOCK] {$w['table']} {$w['index']}: duplicate names " . json_encode($w['duplicates']) . "\n";
    }
    echo "--- NULL inventory (designed meaning per docs/migrations/multitenant-null-semantics.md) ---\n";
    foreach ($report['nulls'] as $r) {
        echo sprintf("  %-28s %-18s nulls=%d/%d :: %s\n", $r['table'], $r['column'], $r['nulls'], $r['total'], $r['meaning']);
    }
    echo str_repeat('=', 70) . "\n";
}

$exit = $issueCount > 0 ? 1 : 0;
if (!$asJson) {
    outputReport($report, false, $exit);
    echo ($exit === 0 ? 'CLEAN: no orphans, no drift, no blockers.' : "FOUND {$issueCount} issue group(s) — fix before migrating.") . "\n";
    exit($exit);
}
outputReport($report, true, $exit);
