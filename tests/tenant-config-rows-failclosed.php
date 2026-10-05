<?php
/* 🔴 NEVER OVER THE WEB. See tests/README.md. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * Tenant-config-rows fail-closed — transient PDO error must deny, not widen.
 *
 * WHY THIS EXISTS:
 * getTenantConfigRows() (includes/tenancy.php) is the single primitive behind
 * every per-company config list (ticket types, reply templates, categories…).
 * Its catch block answers ANY exception — lock-wait timeout, deadlock,
 * dropped connection — with the full unfiltered table ("behave exactly as
 * today"). On a multi-company install under load, a transient database blip
 * therefore widens every config list to every company: company B's private
 * reply templates, hidden-type overrides and all, served to company A. The
 * codebase already owns the correct vocabulary — tenancyDegradeAllowed(),
 * which forgives ONLY missing-table/column (42S02/42S22) and denies everything
 * else — but this function does not use it.
 *
 * HOW A TRANSIENT IS SIMULATED WITHOUT touchING THE REAL DATABASE:
 * a PDO subclass whose prepare() throws a 1205 lock-timeout PDOException
 * while query() keeps answering the tenancy pre-checks and the fallback
 * SELECT exactly as a live server would (table EXISTS, so the fallback finds
 * rows). If the function ever stops failing open, this probe passes with no
 * changes. Precedent for driving guards with a fake: the function under test
 * type-hints PDO, and both fakes below ARE PDOs (PDOStatement subclass with
 * its own constructor — the documented way to instantiate one).
 *
 * STATUS (pós-P0/F4): the catch routes through tenancyDegradeAllowed() —
 * transient returns [], missing-schema still falls back. The 1205 probe must
 * PASS; a failure is a REGRESSION — reopen with Agente 1. See
 * docs/testing/isolation-matrix.md.
 *
 * Run:  php tests/tenant-config-rows-failclosed.php   (needs a DB with 2+ tenants)
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

/** A PDOStatement we can actually instantiate (own public constructor). */
class ZZIsolFakeStmt extends PDOStatement {
    private array $zzRows;
    private mixed $zzCol;
    private int $zzPos = 0;
    public function __construct(array $rows = [], mixed $col = null) {
        $this->zzRows = $rows;
        $this->zzCol = $col;
    }
    public function execute(?array $params = null): bool { return true; }
    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array { return $this->zzRows; }
    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed {
        return $this->zzRows[$this->zzPos++] ?? false;
    }
    public function fetchColumn(int $column = 0): mixed {
        if ($this->zzCol !== null) return $this->zzCol;
        $row = $this->zzRows[0] ?? false;
        if (is_array($row)) return reset($row);
        return $row;
    }
}

/**
 * A "live server having a bad second": tenancy pre-checks answer normally,
 * the config SELECT throws a transient 1205, and the fallback SELECT finds
 * the table exactly as it would in production.
 */
class ZZIsolTransientConn extends PDO {
    public function __construct() { /* skip parent: no real connection */ }
    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false {
        if (stripos($query, 'COUNT(*) FROM tenants') !== false) return new ZZIsolFakeStmt([], 2);
        if (stripos($query, 'FROM tenants') !== false) return new ZZIsolFakeStmt([['1' => 1]]);
        // The fallback SELECT: table exists, so rows come back (the fail-open).
        return new ZZIsolFakeStmt([
            ['id' => 9001, 'name' => 'ZZ-ISOL global template'],
            ['id' => 9002, 'name' => 'ZZ-ISOL tenant-B private template'],
        ]);
    }
    public function prepare(string $query, array $options = []): PDOStatement|false {
        $e = new PDOException('Simulated transient outage: Lock wait timeout exceeded', 1205);
        $e->errorInfo = ['HY000', 1205, 'Lock wait timeout exceeded; try restarting transaction'];
        throw $e;
    }
}

/** Same server on a good second: everything answers. */
class ZZIsolHealthyConn extends PDO {
    public function __construct() { /* skip parent: no real connection */ }
    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false {
        if (stripos($query, 'COUNT(*) FROM tenants') !== false) return new ZZIsolFakeStmt([], 2);
        if (stripos($query, 'FROM tenants') !== false) return new ZZIsolFakeStmt([['1' => 1]]);
        return new ZZIsolFakeStmt([]);
    }
    public function prepare(string $query, array $options = []): PDOStatement|false {
        return new ZZIsolFakeStmt([
            ['id' => 9001, 'name' => 'ZZ-ISOL global template'],
        ]);
    }
}

echo "\nTenant-config-rows fail-closed\n" . str_repeat('=', 70) . "\n";

$conn = connectToDatabase();
if (!isMultiTenant($conn)) { echo "  SKIP  needs two or more companies\n"; exit(2); }
ok('CONTROL: install is multi-tenant (guard under test is engaged)', true);

echo "\nThe fail-closed vocabulary already exists (controls):\n";
$missingTable = new PDOException('Table missing', 1146);
$missingTable->errorInfo = ['42S02', 1146, "Table 'x.y' doesn't exist"];
$unknownCol = new PDOException('Unknown column', 1054);
$unknownCol->errorInfo = ['42S22', 1054, "Unknown column 'tenant_id'"];
$lockTimeout = new PDOException('Lock wait timeout exceeded', 1205);
$lockTimeout->errorInfo = ['HY000', 1205, 'Lock wait timeout exceeded'];
$deadlock = new PDOException('Deadlock found', 1213);
$deadlock->errorInfo = ['40001', 1213, 'Deadlock found when trying to get lock'];
$goneAway = new PDOException('MySQL server has gone away', 2006);
$goneAway->errorInfo = ['HY000', 2006, 'MySQL server has gone away'];
ok('POSITIVE CONTROL: missing table degrades (part-migrated install keeps working)', tenancyDegradeAllowed($missingTable));
ok('POSITIVE CONTROL: unknown column degrades', tenancyDegradeAllowed($unknownCol));
ok('lock-wait timeout denies', tenancyDegradeAllowed($lockTimeout) === false);
ok('deadlock denies', tenancyDegradeAllowed($deadlock) === false);
ok('dropped connection denies', tenancyDegradeAllowed($goneAway) === false);

echo "\nHealthy server (positive control for the probe itself):\n";
$healthy = (new ZZIsolHealthyConn());
$rows = getTenantConfigRows($healthy, 'ticket_reply_templates', 'reply_template', 7, 'id, name', '', 'name');
ok('scoped list resolves on a healthy server', $rows === [['id' => 9001, 'name' => 'ZZ-ISOL global template']], json_encode($rows));

echo "\nTransient outage mid-query (the gap):\n";
// The first SELECT throws 1205; the function falls back to the unfiltered
// table, which (table exists) returns BOTH rows — company B's private
// template included. Desired: [] (deny on transient, like every guard that
// already uses tenancyDegradeAllowed()).
$blip = (new ZZIsolTransientConn());
$leaked = getTenantConfigRows($blip, 'ticket_reply_templates', 'reply_template', 7, 'id, name', '', 'name');
ok('transient error returns zero config rows (fail closed)', $leaked === [], json_encode($leaked));

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail ? 1 : 0);
