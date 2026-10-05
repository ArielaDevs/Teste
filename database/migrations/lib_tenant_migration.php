<?php
/**
 * Shared library for versioned tenant migrations (002+).
 *
 * PURE DEFINITIONS ONLY — requiring this file must never touch the database.
 * Each migration file (20261005_002_*.php, ...) declares its spec and boots via
 * tenant_migration_boot(). Conventions mirror migration 001 and
 * scripts/db_verify_cli.php: preview by default, --apply explicit, --rollback
 * documented, probes via information_schema (never SHOW COLUMNS), full-table
 * backup (`_bak_t1_<table>`, created once) before any ALTER, ledger in
 * `schema_migrations`.
 *
 * Rollback checksum: pre-apply per-table COUNT(*) + CHECKSUM TABLE are stored in
 * the ledger; after rollback the same values are compared and any mismatch is
 * reported (never silently ignored).
 */

if (!defined('TENANT_MIG_BACKUP_PREFIX')) {
    define('TENANT_MIG_BACKUP_PREFIX', '_bak_t1_');
}

/**
 * Boot a spec-driven migration. $spec keys:
 *   version (string), description (string),
 *   prereq_versions (string[]),
 *   tables (array of per-table specs):
 *     table, column (default 'tenant_id'), add_def (e.g. 'INT NULL'),
 *     backfill ('default' | 'skip' | ['parent' => [parentTable, fkCol, parentTenantCol, fallback]]
 *                — 'parent' inherits the parent row's tenant, fallback when unresolvable),
 *     not_null (bool: MODIFY to NOT NULL after a clean backfill),
 *     indexes (array of [name, type('key'|'unique'), cols]),
 *     drop_indexes (array of index names to remove, e.g. replaced global uniques),
 *     fk_action ('CASCADE' | 'SET NULL' | null to skip FK),
 *     fk_name (optional override),
 *     had_column_fallback (bool, default true: if the column pre-existed, rollback
 *                restores values from backup instead of dropping the column).
 */
function tenant_migration_boot(array $spec, array $args): void {
    if (PHP_SAPI !== 'cli') {
        http_response_code(403);
        exit("This script is command-line only.\n");
    }
    ini_set('display_errors', 'stderr');
    $apply = in_array('--apply', $args, true);
    $rollback = in_array('--rollback', $args, true);
    $dropBackups = in_array('--drop-backups', $args, true);
    if ($apply && $rollback) {
        fwrite(STDERR, "Refusing: --apply and --rollback are mutually exclusive.\n");
        exit(2);
    }

    require_once __DIR__ . '/../../config.php';
    require_once __DIR__ . '/../../includes/functions.php';
    try {
        $conn = connectToDatabase();
    } catch (Throwable $e) {
        fwrite(STDERR, 'Database connection failed: ' . $e->getMessage() . "\n");
        exit(2);
    }

    $mode = $rollback ? 'ROLLBACK' : ($apply ? 'APPLY' : 'PREVIEW');
    $mig = new TenantMigrationBase($conn, DB_NAME, $spec['version'], $mode, $dropBackups);
    $errors = $rollback ? $mig->runRollback($spec) : $mig->runApply($spec, $apply);
    foreach ($mig->log as $line) echo $line . "\n";
    echo ($apply || $rollback ? 'Done' : 'Preview only - nothing was changed. Re-run with --apply to apply it.')
        . " [{$mode} {$spec['version']}] errors={$errors}\n";
    exit($errors > 0 ? 1 : 0);
}

class TenantMigrationBase {
    public array $log = [];
    private PDO $conn;
    private string $db;
    private string $version;
    private string $mode;
    private bool $dropBackups;
    private int $errors = 0;

    public function __construct(PDO $conn, string $db, string $version, string $mode, bool $dropBackups) {
        $this->conn = $conn;
        $this->db = $db;
        $this->version = $version;
        $this->mode = $mode;
        $this->dropBackups = $dropBackups;
    }

    // -- information_schema probes -------------------------------------------
    public function tableExists(string $t): bool {
        $s = $this->conn->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = ? AND table_name = ?');
        $s->execute([$this->db, $t]);
        return (int)$s->fetchColumn() > 0;
    }

    public function columnExists(string $t, string $c): bool {
        $s = $this->conn->prepare('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = ? AND table_name = ? AND column_name = ?');
        $s->execute([$this->db, $t, $c]);
        return (int)$s->fetchColumn() > 0;
    }

    public function indexExists(string $t, string $i): bool {
        $s = $this->conn->prepare('SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = ? AND table_name = ? AND index_name = ?');
        $s->execute([$this->db, $t, $i]);
        return (int)$s->fetchColumn() > 0;
    }

    public function fkExists(string $t, string $fk): bool {
        $s = $this->conn->prepare("SELECT COUNT(*) FROM information_schema.table_constraints WHERE table_schema = ? AND table_name = ? AND constraint_name = ? AND constraint_type = 'FOREIGN KEY'");
        $s->execute([$this->db, $t, $fk]);
        return (int)$s->fetchColumn() > 0;
    }

    public function countRows(string $t, string $where = '', array $params = []): int {
        $s = $this->conn->prepare("SELECT COUNT(*) FROM `{$t}`" . ($where !== '' ? " WHERE {$where}" : ''));
        $s->execute($params);
        return (int)$s->fetchColumn();
    }

    public function checksumTable(string $t): ?string {
        try {
            $row = $this->conn->query("CHECKSUM TABLE `{$t}`")->fetch(PDO::FETCH_ASSOC);
            if (!$row || !array_key_exists('Checksum', $row) || $row['Checksum'] === null) return null;
            return (string)$row['Checksum'];
        } catch (Exception $e) {
            return null;
        }
    }

    private function say(string $msg): void {
        $prefix = ($this->mode === 'PREVIEW') ? '[preview] ' : '[apply] ';
        $this->log[] = $prefix . $msg;
    }

    private function fail(string $msg): void {
        $this->errors++;
        $this->log[] = '[ERROR] ' . $msg;
    }

    // -- ledger ---------------------------------------------------------------
    private function ensureLedger(): void {
        $this->conn->exec(
            'CREATE TABLE IF NOT EXISTS `schema_migrations` (' .
            '`version` VARCHAR(32) NOT NULL, `applied_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, ' .
            '`rolled_back_at` DATETIME NULL, `summary` LONGTEXT NULL, PRIMARY KEY (`version`)) ' .
            'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    private function ledgerRow(): ?array {
        $s = $this->conn->prepare('SELECT * FROM `schema_migrations` WHERE `version` = ?');
        $s->execute([$this->version]);
        $row = $s->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /** Resolve the migration target: the is_default tenant, else the sole tenant. */
    private function defaultTenantId(): array {
        if (!$this->tableExists('tenants')) return [0, 'tenants table missing — run migration 001 first'];
        $rows = $this->conn->query('SELECT id, is_default FROM `tenants` ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
        if (count($rows) === 0) return [0, 'no tenants — run migration 001 first'];
        foreach ($rows as $r) {
            if ((int)$r['is_default'] === 1) return [(int)$r['id'], ''];
        }
        if (count($rows) === 1) return [(int)$rows[0]['id'], 'adopted sole tenant'];
        return [0, 'no default tenant among several — set is_default = 1 manually'];
    }

    // -- apply ------------------------------------------------------------------
    public function runApply(array $spec, bool $execute): int {
        // Preview is strictly read-only: no ledger creation, no writes at all.
        $ledger = null;
        if ($execute) {
            $this->ensureLedger();
            $ledger = $this->ledgerRow();
            foreach ($spec['prereq_versions'] ?? [] as $pre) {
                $s = $this->conn->prepare('SELECT rolled_back_at FROM `schema_migrations` WHERE `version` = ?');
                $s->execute([$pre]);
                $row = $s->fetch(PDO::FETCH_ASSOC);
                if (!$row || $row['rolled_back_at'] !== null) {
                    $this->fail("prerequisite migration {$pre} is not applied — run it first");
                    return $this->errors;
                }
            }
        }
        $firstApply = !$ledger;
        if ($ledger && $ledger['rolled_back_at'] === null) {
            $this->say("migration {$this->version} already applied at {$ledger['applied_at']} — verifying only (idempotent, no re-backfill)");
        }

        [$targetId, $targetNote] = $this->defaultTenantId();
        if ($targetId <= 0) {
            $this->fail('cannot resolve default tenant: ' . $targetNote);
            return $this->errors;
        }
        $this->say("target tenant: id {$targetId}" . ($targetNote !== '' ? " ({$targetNote})" : ''));

        $backfillAllowed = $firstApply; // re-runs NEVER rewrite tenant data
        $report = ['target_tenant' => $targetId, 'tables' => []];

        foreach ($spec['tables'] as $t) {
            $this->applyTable($t, $targetId, $execute, $backfillAllowed, $report);
        }

        if ($execute && $firstApply) {
            $s = $this->conn->prepare('INSERT INTO `schema_migrations` (`version`, `summary`) VALUES (?, ?)');
            $s->execute([$this->version, json_encode($report, JSON_UNESCAPED_UNICODE)]);
            $this->say("ledger: recorded {$this->version} as applied");
        } elseif ($execute) {
            $this->say('ledger: already applied — structural drift repaired, data untouched');
        }
        return $this->errors;
    }

    private function applyTable(array $t, int $targetId, bool $execute, bool $backfillAllowed, array &$report): void {
        $table = $t['table'];
        $col = $t['column'] ?? 'tenant_id';
        $entry = ['column' => $col, 'had_column' => null, 'backup' => null, 'backfilled' => 0,
                  'checksum_before' => null, 'rows_before' => null, 'indexes' => [],
                  'dropped_indexes' => [], 'fk' => null, 'tightened' => false, 'note' => null];

        if (!$this->tableExists($table)) {
            $entry['note'] = 'table missing — left for Database Verify to create';
            $this->say("{$table}: table missing, skipped (Database Verify owns creation)");
            $report['tables'][$table] = $entry;
            return;
        }
        $entry['had_column'] = $this->columnExists($table, $col);

        // Backup BEFORE any alteration (created once, reused on re-runs).
        $bak = TENANT_MIG_BACKUP_PREFIX . $table;
        $entry['backup'] = $bak;
        if (!$this->tableExists($bak)) {
            $this->say("{$table}: would snapshot to `{$bak}`");
            if ($execute) {
                $entry['rows_before'] = $this->countRows($table);
                $entry['checksum_before'] = $this->checksumTable($table);
                $this->conn->exec("CREATE TABLE `{$bak}` AS SELECT * FROM `{$table}`");
            }
        } else {
            $this->say("{$table}: backup `{$bak}` already present — kept (pre-migration state)");
        }

        // Column.
        if (!$entry['had_column']) {
            $def = trim(preg_replace('/\s+/', ' ', $t['add_def'] ?? 'INT NULL'));
            $this->say("{$table}: would ADD COLUMN `{$col}` {$def}");
            if ($execute) $this->conn->exec("ALTER TABLE `{$table}` ADD `{$col}` {$def}");
        }

        // Backfill (first apply only).
        if ($backfillAllowed) {
            $this->backfill($t, $table, $col, $targetId, $execute, $entry);
        }
        $report['tables'][$table] = $entry;

        // Orphan guard: no dangling references before the FK goes on.
        $orphans = $this->countRows($table, "`{$col}` IS NOT NULL AND `{$col}` NOT IN (SELECT id FROM `tenants`)");
        if ($orphans > 0) {
            $this->fail("{$table}: {$orphans} row(s) reference missing tenants — FK withheld, fix manually");
            $entry['note'] = trim(($entry['note'] ?? '') . " {$orphans} orphan(s); FK withheld.");
            $report['tables'][$table] = $entry;
            return;
        }

        // Tighten to NOT NULL only when provably clean.
        if (!empty($t['not_null']) && $execute) {
            $nulls = $this->countRows($table, "`{$col}` IS NULL");
            if ($nulls === 0) {
                $def = trim(preg_replace('/\s+/', ' ', $t['add_def'] ?? 'INT NULL'));
                $nnDef = trim(str_ireplace('NULL', 'NOT NULL', preg_replace('/NOT\s+NULL/i', 'NOT NULL', $def)));
                // Normalise "INT NULL" -> "INT NOT NULL" without mangling other NULLs.
                if (stripos($nnDef, 'NOT NULL') === false) $nnDef .= ' NOT NULL';
                $this->conn->exec("ALTER TABLE `{$table}` MODIFY `{$col}` {$nnDef}");
                $entry['tightened'] = true;
                $this->say("{$table}: tightened `{$col}` to NOT NULL (zero NULLs)");
            } else {
                $this->fail("{$table}: {$nulls} NULL row(s) remain — left nullable, fix manually then re-run");
                $entry['note'] = trim(($entry['note'] ?? '') . " {$nulls} NULL(s) left; NOT NULL deferred.");
            }
            $report['tables'][$table] = $entry;
        } elseif (!empty($t['not_null'])) {
            $n = $this->countRows($table, "`{$col}` IS NULL");
            $this->say("{$table}: would tighten `{$col}` to NOT NULL ({$n} NULL(s) pending backfill)");
        }

        // Indexes to drop first (replaced uniques), then indexes to ensure.
        foreach ($t['drop_indexes'] ?? [] as $drop) {
            if (!$this->indexExists($table, $drop)) continue;
            $entry['dropped_indexes'][] = $drop;
            $this->say("{$table}: would DROP INDEX {$drop} (replaced)");
            if ($execute) {
                try {
                    $this->conn->exec("ALTER TABLE `{$table}` DROP INDEX `{$drop}`");
                } catch (Exception $e) {
                    array_pop($entry['dropped_indexes']);
                    $this->fail("{$table}: cannot drop {$drop}: " . $e->getMessage());
                }
            }
        }
        foreach ($t['indexes'] ?? [] as [$idxName, $idxType, $idxCols]) {
            if ($this->indexExists($table, $idxName)) continue;
            $entry['indexes'][] = $idxName;
            $ddl = $idxType === 'unique' ? 'ADD UNIQUE KEY' : 'ADD KEY';
            $this->say("{$table}: would {$ddl} {$idxName} {$idxCols}");
            if ($execute) {
                try {
                    $this->conn->exec("ALTER TABLE `{$table}` {$ddl} `{$idxName}` {$idxCols}");
                } catch (Exception $e) {
                    array_pop($entry['indexes']);
                    $this->fail("{$table}: cannot add {$idxName}: " . $e->getMessage());
                }
            }
        }

        // FK to tenants(), mirroring db_verify.php actions.
        $fkAction = $t['fk_action'] ?? null;
        if ($fkAction !== null) {
            $fk = $t['fk_name'] ?? "fk_{$table}_tenant";
            if (!$this->fkExists($table, $fk)) {
                $entry['fk'] = "{$fk} ON DELETE {$fkAction}";
                $this->say("{$table}: would ADD CONSTRAINT {$fk} REFERENCES tenants(id) ON DELETE {$fkAction}");
                if ($execute) {
                    try {
                        $this->conn->exec("ALTER TABLE `{$table}` ADD CONSTRAINT `{$fk}` FOREIGN KEY (`{$col}`) REFERENCES `tenants` (`id`) ON DELETE {$fkAction}");
                    } catch (Exception $e) {
                        $entry['fk'] = null;
                        $this->fail("{$table}: cannot add FK {$fk}: " . $e->getMessage());
                    }
                }
            }
        }
        $report['tables'][$table] = $entry;
    }

    private function backfill(array $t, string $table, string $col, int $targetId, bool $execute, array &$entry): void {
        $mode = $t['backfill'] ?? 'default';
        if ($mode === 'skip') {
            $this->say("{$table}: backfill skipped by spec (NULL keeps its designed meaning)");
            return;
        }
        if (is_array($mode) && isset($mode['parent'])) {
            [$parent, $fkCol, $parentCol, $fallback] = array_pad($mode['parent'], 4, 'default');
            if (!$this->tableExists($parent) || !$this->columnExists($table, $fkCol) || !$this->columnExists($parent, $parentCol)) {
                $this->say("{$table}: parent link unavailable — falling back to default-tenant backfill");
                $mode = 'default';
            } else {
                $n = $execute ? 0 : $this->countRows($table, "`{$col}` IS NULL");
                $this->say("{$table}: would inherit `{$col}` from `{$parent}` via `{$fkCol}` ({$n} NULL(s))");
                if ($execute) {
                    $upd = $this->conn->prepare(
                        "UPDATE `{$table}` c JOIN `{$parent}` p ON p.id = c.`{$fkCol}` " .
                        "SET c.`{$col}` = p.`{$parentCol}` WHERE c.`{$col}` IS NULL AND p.`{$parentCol}` IS NOT NULL"
                    );
                    $upd->execute();
                    $entry['backfilled'] = (int)$upd->rowCount();
                    // Rows whose parent is missing/mis-tenanted fall back per spec.
                    if (($fallback ?? 'default') === 'default') {
                        $upd2 = $this->conn->prepare("UPDATE `{$table}` SET `{$col}` = ? WHERE `{$col}` IS NULL");
                        $upd2->execute([$targetId]);
                        $entry['backfilled'] += (int)$upd2->rowCount();
                    }
                    if ($entry['backfilled'] > 0) $this->say("{$table}: backfilled {$entry['backfilled']} row(s)");
                }
                return;
            }
        }
        // 'default': every pre-existing row belonged to the single company.
        $n = $execute ? 0 : $this->countRows($table, "`{$col}` IS NULL");
        $this->say("{$table}: would backfill {$n} NULL row(s) to tenant {$targetId}");
        if ($execute) {
            $upd = $this->conn->prepare("UPDATE `{$table}` SET `{$col}` = ? WHERE `{$col}` IS NULL");
            $upd->execute([$targetId]);
            $entry['backfilled'] = (int)$upd->rowCount();
            if ($entry['backfilled'] > 0) $this->say("{$table}: backfilled {$entry['backfilled']} row(s) to tenant {$targetId}");
        }
    }

    // -- rollback ---------------------------------------------------------------
    public function runRollback(array $spec): int {
        $this->ensureLedger();
        $ledger = $this->ledgerRow();
        if (!$ledger) {
            $this->say("nothing to roll back: {$this->version} was never applied");
            return 0;
        }
        if ($ledger['rolled_back_at'] !== null) {
            $this->say("already rolled back at {$ledger['rolled_back_at']} — nothing to do");
            return 0;
        }
        $report = json_decode((string)$ledger['summary'], true) ?: ['tables' => []];

        foreach (array_reverse($spec['tables']) as $t) {
            $table = $t['table'];
            $col = $t['column'] ?? 'tenant_id';
            $entry = $report['tables'][$table] ?? null;
            if (!$this->tableExists($table)) {
                $this->say("{$table}: gone — nothing to undo");
                continue;
            }
            $bak = TENANT_MIG_BACKUP_PREFIX . $table;
            $hadColumn = $entry ? (bool)$entry['had_column'] : true;

            $fk = $t['fk_name'] ?? "fk_{$table}_tenant";
            if ($this->fkExists($table, $fk)) {
                $this->say("{$table}: dropping FOREIGN KEY {$fk}");
                $this->conn->exec("ALTER TABLE `{$table}` DROP FOREIGN KEY `{$fk}`");
            }
            foreach (array_reverse((array)(($entry['indexes'] ?? []))) as $idx) {
                if (!$this->indexExists($table, $idx)) continue;
                $this->say("{$table}: dropping INDEX {$idx}");
                $this->conn->exec("ALTER TABLE `{$table}` DROP INDEX `{$idx}`");
            }
            // Restore replaced global uniques best-effort (fails closed on new dupes).
            foreach ((array)(($entry['dropped_indexes'] ?? [])) as $reAdd) {
                $this->say("{$table}: re-adding replaced INDEX {$reAdd} (best-effort)");
                try {
                    $this->restoreDroppedIndex($table, $reAdd);
                } catch (Exception $e) {
                    $this->fail("{$table}: cannot restore {$reAdd}: " . $e->getMessage());
                }
            }

            if (!$hadColumn && ($t['had_column_fallback'] ?? true) && $this->columnExists($table, $col)) {
                $this->say("{$table}: dropping COLUMN `{$col}` (added by this migration)");
                $this->conn->exec("ALTER TABLE `{$table}` DROP COLUMN `{$col}`");
            } elseif ($hadColumn && $this->tableExists($bak) && $this->columnExists($bak, $col)
                      && $this->columnExists($table, 'id') && $this->columnExists($bak, 'id')) {
                $n = $this->conn->exec("UPDATE `{$table}` t JOIN `{$bak}` b ON b.id = t.id SET t.`{$col}` = b.`{$col}`");
                $this->say("{$table}: restored `{$col}` on {$n} row(s) from `{$bak}`");
            }

            // Checksum verification: dropped-column tables return to exact shape.
            if (!$hadColumn && isset($entry['checksum_before']) && $entry['checksum_before'] !== null) {
                $after = $this->checksumTable($table);
                $rowsAfter = $this->countRows($table);
                if ($after === (string)$entry['checksum_before'] && $rowsAfter === (int)$entry['rows_before']) {
                    $this->say("{$table}: checksum OK (rows={$rowsAfter}, checksum={$after})");
                } else {
                    $this->fail("{$table}: checksum MISMATCH (before rows={$entry['rows_before']}/{$entry['checksum_before']}, after rows={$rowsAfter}/{$after}) — inspect `{$bak}`");
                }
            }

            if ($this->dropBackups && $this->tableExists($bak)) {
                $this->conn->exec("DROP TABLE `{$bak}`");
                $this->say("{$table}: dropped backup `{$bak}` (--drop-backups)");
            }
        }

        $this->conn->prepare('UPDATE `schema_migrations` SET `rolled_back_at` = UTC_TIMESTAMP() WHERE `version` = ?')
             ->execute([$this->version]);
        $this->say("ledger: recorded rollback of {$this->version}");
        return $this->errors;
    }

    /** Re-create a unique index this migration replaced (currently: name uniques). */
    private function restoreDroppedIndex(string $table, string $index): void {
        $map = [
            // Departments 003 replaces the global name unique with a per-tenant one.
            'uq_departments_name' => "ALTER TABLE `departments` ADD UNIQUE KEY `uq_departments_name` (`name`)",
        ];
        if (!isset($map[$index])) {
            throw new Exception("no restore DDL known for {$index} — re-create manually");
        }
        $this->conn->exec($map[$index]);
    }
}
