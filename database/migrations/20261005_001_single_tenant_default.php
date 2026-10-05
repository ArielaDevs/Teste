<?php
/**
 * Versioned migration 20261005_001: single-tenant -> "Default" tenant (id 1).
 *
 * WHAT IT DOES
 * ------------
 *  1. Detects a single-tenant install: the `tenants` table is missing/empty, or
 *     none of the mapped tables carries a tenant column yet.
 *  2. Ensures the `tenants` table exists and creates the "Default" tenant with
 *     the fixed id 1 (is_default = 1, is_active = 1). The id is explicit so every
 *     backfilled row points at a stable, well-known company.
 *  3. Adds the tenant column (`tenant_id`, or `customer_tenant_id` on contracts)
 *     to every mapped table that lacks it, backfills pre-existing rows to the
 *     Default tenant, and ensures the project's composite/tenant indexes plus the
 *     referential-integrity FOREIGN KEYs.
 *  4. Backs every touched table up BEFORE altering it.
 *  5. Is idempotent (safe to re-run; re-runs change nothing) and reversible
 *     (see ROLLBACK below).
 *
 * "MAPPED TABLES" = the single source of truth, not a hardcoded list: every table
 * in includes/db_verify_schema.php that defines a tenant column, with index
 * definitions taken from includes/db_verify_indexes.php. This keeps the migration
 * consistent with Database Verify (api/system/db_verify.php) by construction.
 *
 * BACKFILL POLICY (read before changing)
 * --------------------------------------
 * At migration time on a genuine single-tenant install, every existing row belongs
 * to the one company, so backfilling NULL -> 1 is correct. Re-runs NEVER backfill
 * again (the ledger records the first apply), so later NULLs keep their designed
 * meaning: triage tickets, shared-intake mailboxes/channels, global config lists.
 *
 * One deliberate exception, applied only on the FIRST apply: GLOBAL config tables
 * keep NULL, because NULL there means "shared with every company, including ones
 * created later" — backfilling them would silently privatise shared data the day a
 * second company is added:
 *   - auth_providers  (analyst login lists only tenant_id IS NULL providers)
 *   - knowledge_articles (NULL = shared with every company)
 *   - ticket_types / ticket_origins / ticket_categories / ticket_resolution_codes,
 *     asset_types / asset_status_types / asset_locations,
 *     ticket_reply_templates, checklist_templates (NULL = global default; per-company
 *     hiding goes through tenant_config_hidden)
 *   - apikeys (NULL = unpinned key)
 * Pass --include-global to backfill those as well (not recommended).
 *
 * FK POLICY: mirrors api/system/db_verify.php — child tables whose tenant column is
 * NOT NULL use ON DELETE CASCADE; nullable scoped data uses ON DELETE SET NULL;
 * config lists use ON DELETE CASCADE (see fkActionFor()).
 *
 * BACKUP
 * ------
 * Before touching a table, the migration creates `_bak_t1_<table>` as a full
 * snapshot (CREATE TABLE ... AS SELECT *). The snapshot is created ONCE — re-runs
 * reuse it, so it always reflects the pre-migration state. For an off-database
 * backup, run first (example):
 *   mysqldump -h <host> -u <user> -p <dbname> > freeitsm_pre_tenant_mig.sql
 *
 * USAGE (same convention as scripts/db_verify_cli.php: preview is the default)
 * -----
 *   php database/migrations/20261005_001_single_tenant_default.php            preview, changes nothing
 *   php database/migrations/20261005_001_single_tenant_default.php --apply    execute
 *   php database/migrations/20261005_001_single_tenant_default.php --apply --include-global
 *   php database/migrations/20261005_001_single_tenant_default.php --rollback           undo data + schema changes (keeps backups)
 *   php database/migrations/20261005_001_single_tenant_default.php --rollback --drop-backups
 *
 * ROLLBACK (documented, tested order)
 * -----------------------------------
 *  1. Reads the apply ledger (table `schema_migrations`, version 20261005_001).
 *  2. Per table, in reverse order: drops the FK/index this migration added, then
 *     either restores tenant values from `_bak_t1_<table>` (column pre-existed) or
 *     drops the column (migration added it).
 *  3. Deletes the Default tenant ONLY if this migration created it AND no row in
 *     any mapped table references it anymore; otherwise it is kept and reported.
 *  4. Backup tables are kept unless --drop-backups is given.
 *  5. Marks the ledger row rolled_back_at (audit trail, never deleted).
 *
 * IDEMPOTENCY: every step probes information_schema first (column/index/FK
 * existence, ledger state). MySQL DDL auto-commits, so each table is handled as
 * backup -> alter -> backfill -> index -> FK, and a failure is recorded while the
 * remaining tables still run. Exit code 0 = ok/nothing to do, 1 = errors, 2 = boot.
 */

const TENANT_MIG_VERSION = '20261005_001';
const TENANT_MIG_BACKUP_PREFIX = '_bak_t1_';
const TENANT_MIG_DEFAULT_ID = 1;
const TENANT_MIG_DEFAULT_NAME = 'Default';

/** Tables where NULL tenant_id is load-bearing shared/global state (see header). */
function tenantMigGlobalTables(): array {
    return [
        'auth_providers',
        'knowledge_articles',
        'ticket_types',
        'ticket_origins',
        'ticket_categories',
        'ticket_resolution_codes',
        'asset_types',
        'asset_status_types',
        'asset_locations',
        'ticket_reply_templates',
        'checklist_templates',
        'apikeys',
    ];
}

/**
 * Mapped tables: every schema table defining a tenant column, except `tenants`
 * itself. Returns [table => tenantColumn]. Pure function (no DB) so it can be
 * unit-tested by requiring this file from a harness.
 */
function tenantMigMappedTables(array $schema): array {
    $out = [];
    foreach ($schema as $table => $columns) {
        if ($table === 'tenants') continue;
        if (array_key_exists('tenant_id', $columns)) {
            $out[$table] = 'tenant_id';
        } elseif (array_key_exists('customer_tenant_id', $columns)) {
            $out[$table] = 'customer_tenant_id';
        }
    }
    return $out;
}

/** Tenant-related index definitions from the project's generated index map. */
function tenantMigIndexesFor(array $indexMap, string $table, string $column): array {
    $out = [];
    foreach ($indexMap as $entry) {
        if (!is_array($entry) || count($entry) < 4) continue;
        [$t, $name, $type, $cols] = $entry;
        if ($t !== $table) continue;
        if (stripos($cols, '`' . $column . '`') === false && stripos($cols, $column) === false) continue;
        $out[] = ['name' => $name, 'type' => strtolower($type), 'cols' => $cols];
    }
    return $out;
}

/** FK ON DELETE action, mirroring api/system/db_verify.php. */
function tenantMigFkAction(string $table, string $columnDef): string {
    static $cascade = [
        'tenant_domains', 'tenant_sender_addresses', 'tenant_channel_senders',
        'tenant_settings', 'tenant_config_hidden', 'analyst_tenant_access',
        'team_tenant_access', 'ticket_types', 'ticket_origins', 'ticket_categories',
        'ticket_resolution_codes', 'asset_types', 'asset_status_types',
        'asset_locations', 'asset_fields', 'asset_field_sets', 'asset_import_profiles',
        'cost_centres', 'auth_providers',
    ];
    if (in_array($table, $cascade, true)) return 'CASCADE';
    if (stripos($columnDef, 'NOT NULL') !== false) return 'CASCADE';
    return 'SET NULL';
}

function tenantMigFkName(string $table, string $column): string {
    return $column === 'tenant_id' ? "fk_{$table}_tenant" : "fk_{$table}_{$column}";
}

// ---------------------------------------------------------------------------
// Boot (only when executed directly, not when required by a test harness).
// ---------------------------------------------------------------------------
if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    tenantMigMain(array_slice($argv, 1));
}

function tenantMigMain(array $args): void {
    ini_set('display_errors', 'stderr');
    $apply = in_array('--apply', $args, true);
    $rollback = in_array('--rollback', $args, true);
    $dropBackups = in_array('--drop-backups', $args, true);
    $includeGlobal = in_array('--include-global', $args, true);

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
    $dbName = DB_NAME;
    $mode = $rollback ? 'ROLLBACK' : ($apply ? 'APPLY' : 'PREVIEW');

    $schema = require __DIR__ . '/../../includes/db_verify_schema.php';
    $indexMap = require __DIR__ . '/../../includes/db_verify_indexes.php';
    $mapped = tenantMigMappedTables($schema);

    $db = new TenantMigration($conn, $dbName, $mode, $includeGlobal, $dropBackups);
    $errors = $rollback ? $db->runRollback($mapped) : $db->runApply($mapped, $apply);

    foreach ($db->log as $line) echo $line . "\n";
    echo ($apply || $rollback ? 'Done' : 'Preview only - nothing was changed. Re-run with --apply to apply it.')
        . " [{$mode}] errors={$errors}\n";
    exit($errors > 0 ? 1 : 0);
}

class TenantMigration {
    public array $log = [];
    private PDO $conn;
    private string $db;
    private string $mode;
    private bool $includeGlobal;
    private bool $dropBackups;
    private int $errors = 0;

    public function __construct(PDO $conn, string $db, string $mode, bool $includeGlobal, bool $dropBackups) {
        $this->conn = $conn;
        $this->db = $db;
        $this->mode = $mode;
        $this->includeGlobal = $includeGlobal;
        $this->dropBackups = $dropBackups;
    }

    // -- information_schema probes (idempotency primitives) ------------------
    private function tableExists(string $t): bool {
        $s = $this->conn->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = ? AND table_name = ?');
        $s->execute([$this->db, $t]);
        return (int)$s->fetchColumn() > 0;
    }

    private function columnExists(string $t, string $c): bool {
        $s = $this->conn->prepare('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = ? AND table_name = ? AND column_name = ?');
        $s->execute([$this->db, $t, $c]);
        return (int)$s->fetchColumn() > 0;
    }

    private function indexExists(string $t, string $i): bool {
        $s = $this->conn->prepare('SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = ? AND table_name = ? AND index_name = ?');
        $s->execute([$this->db, $t, $i]);
        return (int)$s->fetchColumn() > 0;
    }

    private function fkExists(string $t, string $fk): bool {
        $s = $this->conn->prepare("SELECT COUNT(*) FROM information_schema.table_constraints WHERE table_schema = ? AND table_name = ? AND constraint_name = ? AND constraint_type = 'FOREIGN KEY'");
        $s->execute([$this->db, $t, $fk]);
        return (int)$s->fetchColumn() > 0;
    }

    private function countRows(string $t, string $where = '', array $params = []): int {
        $rows = $this->conn->prepare("SELECT COUNT(*) FROM `{$t}`" . ($where !== '' ? " WHERE {$where}" : ''));
        $rows->execute($params);
        return (int)$rows->fetchColumn();
    }

    private function say(string $msg): void {
        $this->log[] = ($this->mode === 'APPLY' || $this->mode === 'ROLLBACK' ? '[apply] ' : '[preview] ') . $msg;
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
        $s->execute([TENANT_MIG_VERSION]);
        $row = $s->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    // -- step 1: single-tenant detection --------------------------------------
    private function detectSingleTenant(array $mapped): array {
        if (!$this->tableExists('tenants')) {
            return [true, 'tenants table is missing (pre-multi-tenancy install)'];
        }
        $withCol = 0;
        foreach ($mapped as $table => $col) {
            if ($this->tableExists($table) && $this->columnExists($table, $col)) $withCol++;
        }
        if ($withCol === 0) {
            return [true, 'no mapped table carries a tenant column yet'];
        }
        try {
            $n = (int)$this->conn->query('SELECT COUNT(*) FROM `tenants`')->fetchColumn();
        } catch (Exception $e) {
            return [true, 'tenants table is unreadable'];
        }
        if ($n <= 1) {
            return [true, "only {$n} tenant(s) registered" . ($withCol < count($mapped) ? ' and migration is incomplete' : '')];
        }
        return [false, "{$n} tenants registered — multi-tenant install"];
    }

    // -- step 2: default tenant ------------------------------------------------
    private function ensureTenantsTable(array $schema): void {
        if ($this->tableExists('tenants')) return;
        $cols = [];
        foreach ($schema['tenants'] as $name => $def) $cols[] = "`{$name}` {$def}";
        $cols[] = 'PRIMARY KEY (`id`)';
        $sql = 'CREATE TABLE `tenants` (' . implode(', ', $cols) . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
        $this->conn->exec($sql);
        $this->say('created missing `tenants` table');
    }

    /** Returns [tenantId, createdByMigration]. */
    private function ensureDefaultTenant(): array {
        $rows = $this->conn->query('SELECT id, is_default FROM `tenants` ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
        if (count($rows) === 0) {
            $this->conn->exec(
                "INSERT INTO `tenants` (id, name, is_default, is_active, created_datetime) VALUES (" .
                TENANT_MIG_DEFAULT_ID . ", '" . TENANT_MIG_DEFAULT_NAME . "', 1, 1, UTC_TIMESTAMP())"
            );
            $this->say('created tenant "default" with id 1');
            return [TENANT_MIG_DEFAULT_ID, true];
        }
        foreach ($rows as $r) {
            if ((int)$r['is_default'] === 1) {
                $this->say('default tenant already present (id ' . (int)$r['id'] . ')');
                return [(int)$r['id'], false];
            }
        }
        if (count($rows) === 1) {
            $this->say('adopted sole tenant id ' . (int)$rows[0]['id'] . ' as migration target');
            return [(int)$rows[0]['id'], false];
        }
        $this->fail('no default tenant and several tenants exist — refusing to pick one; set is_default = 1 manually');
        return [0, false];
    }

    // -- step 3: per-table migration -------------------------------------------
    public function runApply(array $mapped, bool $execute): int {
        // Preview is strictly read-only: the ledger table is only created on --apply.
        $ledger = null;
        if ($execute) {
            $this->ensureLedger();
            $ledger = $this->ledgerRow();
        }
        if ($ledger && $ledger['rolled_back_at'] === null) {
            $this->say('migration ' . TENANT_MIG_VERSION . ' already applied at ' . $ledger['applied_at'] . ' — verifying only (idempotent, no re-backfill)');
        }

        [$single, $reason] = $this->detectSingleTenant($mapped);
        $this->say('single-tenant detection: ' . ($single ? 'YES' : 'NO') . " ({$reason})");

        $schema = require __DIR__ . '/../../includes/db_verify_schema.php';
        $indexMap = require __DIR__ . '/../../includes/db_verify_indexes.php';
        $firstApply = !$ledger;
        $backfillAllowed = $firstApply && $single;

        if (!$backfillAllowed && $firstApply && !$single) {
            $this->say('multi-tenant install: structural changes only, existing rows are NOT reassigned');
        }

        if ($execute && $firstApply) {
            $this->ensureTenantsTable($schema);
        } elseif (!$this->tableExists('tenants')) {
            $this->say('preview: would create `tenants` table + default tenant id 1');
        }
        [$targetId, $targetCreated] = ($execute) ? $this->ensureDefaultTenant() : $this->previewTenantTarget();
        if ($targetId <= 0) return $this->errors;

        $report = ['target_tenant' => $targetId, 'target_created' => $execute && $targetCreated,
                   'single_tenant' => $single, 'include_global' => $this->includeGlobal, 'tables' => []];

        foreach ($mapped as $table => $col) {
            $entry = ['column' => $col, 'had_column' => null, 'backup' => null,
                      'backfilled' => 0, 'skipped_global' => false, 'indexes' => [], 'fk' => null, 'note' => null];
            if (!$this->tableExists($table)) {
                $entry['note'] = 'table missing — left for Database Verify to create';
                $this->say("{$table}: table missing, skipped (Database Verify owns creation)");
                $report['tables'][$table] = $entry;
                continue;
            }
            $colDef = $schema[$table][$col] ?? 'INT NULL';
            $entry['had_column'] = $this->columnExists($table, $col);

            // 4. backup BEFORE any alteration (created once, reused on re-runs).
            $bak = TENANT_MIG_BACKUP_PREFIX . $table;
            $entry['backup'] = $bak;
            if (!$this->tableExists($bak)) {
                $this->say("{$table}: would snapshot to `{$bak}`" . ($execute ? '' : ' (backup first)'));
                if ($execute) $this->conn->exec("CREATE TABLE `{$bak}` AS SELECT * FROM `{$table}`");
            } else {
                $this->say("{$table}: backup `{$bak}` already present — kept (pre-migration state)");
            }

            // Column.
            if (!$entry['had_column']) {
                $alterDef = trim(preg_replace('/\s+/', ' ', str_ireplace('AUTO_INCREMENT', '', $colDef)));
                if (stripos($alterDef, 'NOT NULL') !== false && stripos($alterDef, 'DEFAULT') === false
                    && $this->countRows($table) > 0) {
                    // A populated table cannot gain a NOT NULL column with no default.
                    $alterDef = str_ireplace('NOT NULL', 'NULL', $alterDef);
                    $entry['note'] = 'added as NULL (populated table); administrator review needed before tightening';
                }
                $this->say("{$table}: would ADD COLUMN `{$col}` {$alterDef}");
                if ($execute) $this->conn->exec("ALTER TABLE `{$table}` ADD `{$col}` {$alterDef}");
            }

            // 2. backfill pre-existing rows — first apply on a single-tenant install only.
            $isGlobal = in_array($table, tenantMigGlobalTables(), true);
            if ($backfillAllowed && $execute) {
                if ($isGlobal && !$this->includeGlobal) {
                    $entry['skipped_global'] = true;
                    $this->say("{$table}: kept NULL (global/shared by design; --include-global overrides)");
                } else {
                    $upd = $this->conn->prepare("UPDATE `{$table}` SET `{$col}` = ? WHERE `{$col}` IS NULL");
                    $upd->execute([$targetId]);
                    $entry['backfilled'] = $upd->rowCount();
                    if ($entry['backfilled'] > 0) $this->say("{$table}: backfilled {$entry['backfilled']} row(s) to tenant {$targetId}");
                }
            } elseif ($backfillAllowed && !$execute) {
                $n = $this->countRows($table, "`{$col}` IS NULL");
                $this->say("{$table}: would backfill {$n} NULL row(s) to tenant {$targetId}" . ($isGlobal ? ' (skipped: global table)' : ''));
            }

            // Referential integrity: no dangling references before the FK goes on.
            $orphans = $this->countRows($table, "`{$col}` IS NOT NULL AND `{$col}` NOT IN (SELECT id FROM `tenants`)");
            if ($orphans > 0) {
                $this->fail("{$table}: {$orphans} row(s) reference missing tenants — FK withheld, fix manually");
                $entry['note'] = ($entry['note'] ?? '') . " {$orphans} orphan(s); FK withheld.";
                $report['tables'][$table] = $entry;
                continue;
            }

            // 3b. indexes: project-defined tenant (often composite) indexes first,
            // then a generic composite fallback for mapped tables lacking one.
            $wanted = tenantMigIndexesFor($indexMap, $table, $col);
            if (empty($wanted)) {
                $idCol = $this->columnExists($table, 'id') ? ', `id`' : '';
                $wanted[] = ['name' => "ix_{$table}_tenant_scope", 'type' => 'key', 'cols' => "(`{$col}`{$idCol})"];
            }
            foreach ($wanted as $idx) {
                if ($this->indexExists($table, $idx['name'])) continue;
                $entry['indexes'][] = $idx['name'];
                $ddl = $idx['type'] === 'unique' ? 'ADD UNIQUE KEY' : ($idx['type'] === 'fulltext' ? 'ADD FULLTEXT KEY' : 'ADD KEY');
                $this->say("{$table}: would {$ddl} {$idx['name']} {$idx['cols']}");
                if ($execute) {
                    try {
                        $this->conn->exec("ALTER TABLE `{$table}` {$ddl} `{$idx['name']}` {$idx['cols']}");
                    } catch (Exception $e) {
                        // A duplicate unique key means legacy data violates the new rule:
                        // report, do not force. (E.g. two global config rows with one
                        // about to be backfilled — rerun with the data fixed.)
                        array_pop($entry['indexes']);
                        $this->fail("{$table}: cannot add {$idx['name']}: " . $e->getMessage());
                    }
                }
            }

            // 3c. FK to tenants(), idempotent, action mirrors db_verify.php.
            $fk = tenantMigFkName($table, $col);
            if (!$this->fkExists($table, $fk)) {
                $action = tenantMigFkAction($table, $colDef);
                $entry['fk'] = "{$fk} ON DELETE {$action}";
                $this->say("{$table}: would ADD CONSTRAINT {$fk} REFERENCES tenants(id) ON DELETE {$action}");
                if ($execute) {
                    try {
                        $this->conn->exec("ALTER TABLE `{$table}` ADD CONSTRAINT `{$fk}` FOREIGN KEY (`{$col}`) REFERENCES `tenants` (`id`) ON DELETE {$action}");
                    } catch (Exception $e) {
                        $entry['fk'] = null;
                        $this->fail("{$table}: cannot add FK {$fk}: " . $e->getMessage());
                    }
                }
            }
            $report['tables'][$table] = $entry;
        }

        if ($execute && $firstApply) {
            $s = $this->conn->prepare('INSERT INTO `schema_migrations` (`version`, `summary`) VALUES (?, ?)');
            $s->execute([TENANT_MIG_VERSION, json_encode($report, JSON_UNESCAPED_UNICODE)]);
            $this->say('ledger: recorded ' . TENANT_MIG_VERSION . ' as applied');
        } elseif ($execute) {
            $this->say('ledger: already applied — structural drift repaired, data untouched');
        }
        return $this->errors;
    }

    private function previewTenantTarget(): array {
        try {
            $rows = $this->conn->query('SELECT id, is_default FROM `tenants` ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            $this->say('preview: would create tenant "default" with id 1');
            return [TENANT_MIG_DEFAULT_ID, true];
        }
        if (count($rows) === 0) {
            $this->say('preview: would create tenant "default" with id 1');
            return [TENANT_MIG_DEFAULT_ID, true];
        }
        foreach ($rows as $r) {
            if ((int)$r['is_default'] === 1) return [(int)$r['id'], false];
        }
        if (count($rows) === 1) return [(int)$rows[0]['id'], false];
        return [0, false];
    }

    // -- step 5: documented rollback --------------------------------------------
    public function runRollback(array $mapped): int {
        $this->ensureLedger();
        $ledger = $this->ledgerRow();
        if (!$ledger) {
            $this->say('nothing to roll back: ' . TENANT_MIG_VERSION . ' was never applied');
            return 0;
        }
        if ($ledger['rolled_back_at'] !== null) {
            $this->say('already rolled back at ' . $ledger['rolled_back_at'] . ' — nothing to do');
            return 0;
        }
        $report = json_decode((string)$ledger['summary'], true) ?: ['tables' => []];
        $targetId = (int)($report['target_tenant'] ?? TENANT_MIG_DEFAULT_ID);
        $targetCreated = !empty($report['target_created']);

        foreach (array_reverse($mapped, true) as $table => $col) {
            $entry = $report['tables'][$table] ?? null;
            if (!$this->tableExists($table)) {
                $this->say("{$table}: gone — nothing to undo");
                continue;
            }
            $bak = TENANT_MIG_BACKUP_PREFIX . $table;
            $hadColumn = $entry ? (bool)$entry['had_column'] : true;

            // Drop what this migration added (FK first, then indexes).
            $fk = tenantMigFkName($table, $col);
            if ($this->fkExists($table, $fk)) {
                $this->say("{$table}: would DROP FOREIGN KEY {$fk}");
                $this->conn->exec("ALTER TABLE `{$table}` DROP FOREIGN KEY `{$fk}`");
            }
            foreach (array_reverse((array)($entry['indexes'] ?? [])) as $idx) {
                if (!$this->indexExists($table, $idx)) continue;
                $this->say("{$table}: would DROP INDEX {$idx}");
                $this->conn->exec("ALTER TABLE `{$table}` DROP INDEX `{$idx}`");
            }

            if (!$hadColumn && $this->columnExists($table, $col)) {
                $this->say("{$table}: would DROP COLUMN `{$col}` (added by this migration)");
                $this->conn->exec("ALTER TABLE `{$table}` DROP COLUMN `{$col}`");
            } elseif ($hadColumn && $this->tableExists($bak) && $this->columnExists($bak, $col)) {
                // Restore pre-migration values via the primary key where possible.
                if ($this->columnExists($table, 'id') && $this->columnExists($bak, 'id')) {
                    $n = $this->conn->exec(
                        "UPDATE `{$table}` t JOIN `{$bak}` b ON b.id = t.id SET t.`{$col}` = b.`{$col}`"
                    );
                    $this->say("{$table}: restored `{$col}` on {$n} row(s) from `{$bak}`");
                } else {
                    $this->say("{$table}: no `id` to join on — values left as-is, see `{$bak}`");
                }
            }

            if ($this->dropBackups && $this->tableExists($bak)) {
                $this->conn->exec("DROP TABLE `{$bak}`");
                $this->say("{$table}: dropped backup `{$bak}` (--drop-backups)");
            }
        }

        // Remove the Default tenant only if we created it and nothing points at it.
        if ($targetCreated) {
            $refs = 0;
            foreach ($mapped as $table => $col) {
                if ($this->tableExists($table) && $this->columnExists($table, $col)) {
                    $refs += $this->countRows($table, "`{$col}` = ?", [$targetId]);
                }
            }
            if ($refs === 0 && $this->countRows('tenants', 'id = ?', [$targetId]) > 0) {
                $this->conn->exec("DELETE FROM `tenants` WHERE id = " . (int)$targetId);
                $this->say("deleted tenant id {$targetId} (created by this migration, now unreferenced)");
            } else {
                $this->say("kept tenant id {$targetId} ({$refs} row(s) still reference it)");
            }
        }

        $this->conn->prepare('UPDATE `schema_migrations` SET `rolled_back_at` = UTC_TIMESTAMP() WHERE `version` = ?')
             ->execute([TENANT_MIG_VERSION]);
        $this->say('ledger: recorded rollback of ' . TENANT_MIG_VERSION);
        return $this->errors;
    }
}
