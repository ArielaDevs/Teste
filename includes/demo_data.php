<?php
/**
 * Demo data — the one place that knows what a demo module is made of.
 *
 * Two endpoints need the same answer to "which tables does module X put rows
 * into?": the importer, to clear its own previous rows before inserting fresh
 * ones, and the demo data page, to say whether a module has been imported.
 * They used to answer it separately, and the second answer was wrong — it
 * asked "does this table have ANY rows?", so an installation with real tickets
 * or real assets was reported as already having the demo data. Same family of
 * fault as the importer's own: nothing distinguished demo rows from real ones.
 *
 * The distinguishing mark is now the `is_demo` column, set on every row the
 * importer inserts and present on all of the tables listed here.
 */

/** Modules with a database/demo-data/{module}.json file. Order is display order. */
const DEMO_MODULES = [
    'core', 'tickets', 'assets', 'knowledge', 'changes', 'calendar', 'checks',
    'checklists',
    'contracts', 'domains', 'services', 'software', 'forms', 'software-assets',
    'dashboards', 'tasks', 'process-mapper', 'cmdb', 'lms', 'workflow',
    'network-mapper',
];

function demoDataPath(string $module): string {
    return __DIR__ . "/../database/demo-data/{$module}.json";
}

/**
 * The tables a parsed demo module actually INSERTS into.
 *
 * A table listed only with `_skip_insert` records is excluded: those records
 * match an existing row to resolve a reference (the admin analyst, the CMDB
 * icons) and the importer never creates or removes them. Getting this wrong in
 * the clearing direction is what makes an importer destructive, so it is
 * deliberately the narrow reading.
 */
function demoTablesWithInserts(array $demoData): array {
    $tables = [];
    foreach (['tier1', 'tier2', 'tier3', 'tier4', 'tier5'] as $tierKey) {
        if (!isset($demoData[$tierKey])) continue;
        foreach ($demoData[$tierKey] as $tableName => $records) {
            foreach ($records as $record) {
                if (empty($record['_skip_insert'])) {
                    if (!in_array($tableName, $tables, true)) $tables[] = $tableName;
                    break;
                }
            }
        }
    }
    return $tables;
}

/** As above, but reads the module's JSON from disk. Returns [] if unreadable. */
function demoModuleTables(string $module): array {
    $path = demoDataPath($module);
    if (!file_exists($path)) return [];
    $data = json_decode(file_get_contents($path), true);
    return is_array($data) ? demoTablesWithInserts($data) : [];
}

/**
 * How many demo rows a module currently has in the database.
 *
 * Counts only rows marked is_demo = 1, so real data is never mistaken for
 * demo data. A missing table or column counts as zero rather than throwing —
 * a module whose feature was never set up simply has no demo rows.
 */
function demoRowCount(PDO $conn, string $module): int {
    $total = 0;
    foreach (demoModuleTables($module) as $table) {
        try {
            $total += (int)$conn->query("SELECT COUNT(*) FROM `$table` WHERE is_demo = 1")->fetchColumn();
        } catch (Exception $e) {
            // table or column absent on this installation — nothing to count
        }
    }
    return $total;
}

/**
 * Which other demo modules are holding a table down.
 *
 * Clearing a module's rows runs with foreign key checks ON (turning them off is
 * what orphaned the SSO links in #1296), so a demo row another module still
 * points at cannot be deleted — re-importing Core while the Tickets demo data
 * exists fails, because demo tickets reference demo requesters.
 *
 * Refusing is correct; refusing without saying what is in the way is not. This
 * finds the demo tables that reference $table and still hold demo rows, and
 * names the modules they belong to so the message can tell the administrator
 * what to deal with first.
 */
function demoBlockingModules(PDO $conn, string $table, string $exceptModule): array {
    $blocking = [];
    try {
        $stmt = $conn->prepare(
            "SELECT DISTINCT TABLE_NAME FROM information_schema.KEY_COLUMN_USAGE
              WHERE TABLE_SCHEMA = DATABASE() AND REFERENCED_TABLE_NAME = ?"
        );
        $stmt->execute([$table]);
        $children = $stmt->fetchAll(PDO::FETCH_COLUMN);
    } catch (Exception $e) {
        return [];
    }

    foreach (DEMO_MODULES as $module) {
        if ($module === $exceptModule) continue;
        foreach (demoModuleTables($module) as $moduleTable) {
            if (!in_array($moduleTable, $children, true)) continue;
            try {
                $n = (int)$conn->query("SELECT COUNT(*) FROM `$moduleTable` WHERE is_demo = 1")->fetchColumn();
            } catch (Exception $e) {
                continue;
            }
            if ($n > 0 && !in_array($module, $blocking, true)) $blocking[] = $module;
        }
    }
    return $blocking;
}

/**
 * Explain a unique-key collision during an import in terms of its actual cause.
 *
 * On an installation whose demo data predates the is_demo mark, the importer
 * cannot recognise the old sample rows, so it does not remove them, and then
 * collides with them: `Duplicate entry 'jsmith' for key analysts.uq_...`. That
 * message is true and useless — it names a row the administrator never created
 * and says nothing about what to do. It is also the likeliest failure on
 * exactly the installations most at risk, so it is worth spelling out.
 */
function demoDuplicateExplanation(PDO $conn, string $module, string $table, string $dbMessage): string {
    $value = '';
    if (preg_match("/Duplicate entry '([^']*)'/", $dbMessage, $m)) {
        $value = " (\"{$m[1]}\")";
    }

    if (demoHasUntaggedImport($conn)) {
        return "This installation already has demo data that was imported before demo rows were "
             . "marked as such, so it cannot be recognised or replaced automatically. The "
             . ucfirst($module) . " import stopped because one of its records already exists in "
             . "`$table`{$value}. Remove the old sample records by hand first, or import into a "
             . "fresh installation. Nothing has been changed.";
    }

    return "The " . ucfirst($module) . " demo data could not be added: a record in `$table`{$value} "
         . "already exists and is not marked as demo data, so the importer will not touch it. "
         . "Nothing has been changed.";
}

/**
 * Does this installation hold demo data from BEFORE the rows were tagged?
 *
 * Installations that imported demo data prior to #1297 have is_demo = 0 on all
 * of it, so the importer cannot recognise it and a re-import would lay a second
 * copy alongside the first rather than replacing it. There is no way to tag it
 * retrospectively — nothing recorded which rows came from where — so the honest
 * move is to detect the case and say so.
 *
 * The probe is the demo analyst 'jsmith', which is what the demo data page used
 * as its "core has been imported" test before this existed. Present but not
 * tagged means an untagged import.
 */
function demoHasUntaggedImport(PDO $conn): bool {
    try {
        $stmt = $conn->prepare("SELECT COUNT(*) FROM analysts WHERE username = 'jsmith' AND is_demo = 0");
        $stmt->execute();
        return (int)$stmt->fetchColumn() > 0;
    } catch (Exception $e) {
        return false;
    }
}
