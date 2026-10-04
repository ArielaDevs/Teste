<?php
/**
 * CostCentresService - the rules for cost centres (GH #160, stage 1), ONCE.
 *
 * Shared by System -> Cost Centres (api/system/cost_centres.php), its spreadsheet
 * import, and the REST API (api/v1/resources/cost_centres.php). Each caller
 * passes an ActorContext; this layer validates, writes and returns, or throws
 * ServiceError. It never emits HTTP.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * WHAT A COST CENTRE IS HERE
 *
 * A code, a name, an optional description, an optional parent (the hierarchy)
 * and active/inactive. Always ONE company's: two companies can both have 0010.
 *
 * 🔑 THE CODE IS TEXT. "0010" and "N1414" are both real codes. It is trimmed and
 * nothing else - never cast, never padded, never upper-cased. It is unique per
 * company IGNORING CASE (the column's collation does that, and so does every
 * comparison in here), so n1414 and N1414 are the same cost centre, not two.
 *
 * 🔑 THE CODE IS THE KEY FOR SYNCING. Most organisations keep the real list in
 * their accounting/ERP system and copy it in. sync() matches on code, so the
 * same file or API call can be sent every night: new codes are added, changed
 * ones updated, and nothing is duplicated.
 *
 * 🔑 INACTIVE MEANS "NOT FOR NEW ASSIGNMENTS". Whatever is already charged to an
 * inactive cost centre keeps it (stage 2). Retiring, not deleting, is the normal
 * path; delete is for a mistake, and is refused while it has children.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * COMPANIES
 *
 * Every by-id method starts with load(), which 404s a cost centre in a company
 * the caller cannot reach - never 403, so ids cannot be probed. A parent must
 * be in the same company, and can never be the cost centre itself or anything
 * below it (that would be a loop, and a loop has no top).
 */

require_once __DIR__ . '/../service_context.php';
require_once __DIR__ . '/../tenancy.php';

class CostCentresService
{
    const CODE_MAX = 50;
    const NAME_MAX = 150;
    const DESC_MAX = 500;

    /** Has Database Verification created the table yet? */
    public static function ready(PDO $conn): bool
    {
        try {
            $conn->query("SELECT 1 FROM cost_centres LIMIT 0");
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    /** The companies this caller may manage cost centres for: [[id, name, is_default], ...]. */
    public static function companiesFor(PDO $conn, ActorContext $actor): array
    {
        $out = [];
        foreach (getAllTenants($conn) as $t) {
            if (self::canReach($actor, (int)$t['id'])) {
                $out[] = ['id' => (int)$t['id'], 'name' => $t['name'], 'is_default' => (bool)(int)($t['is_default'] ?? 0)];
            }
        }
        // getAllTenants() is empty before multi-tenancy was ever migrated.
        if (!$out) $out[] = ['id' => getDefaultTenantId($conn), 'name' => 'Default', 'is_default' => true];
        return $out;
    }

    private static function canReach(ActorContext $actor, int $tenantId): bool
    {
        return $actor->companyScope === null || in_array($tenantId, array_map('intval', $actor->companyScope), true);
    }

    /** The company exists and this caller may use it - or a ServiceError. */
    public static function assertCompany(PDO $conn, ActorContext $actor, int $tenantId): void
    {
        $known = tenancyTablesReady($conn) ? getTenantById($conn, $tenantId) !== null : $tenantId === getDefaultTenantId($conn);
        if (!$known) throw new ServiceError('validation', 'invalid_field', "Unknown company id: {$tenantId}");
        if (!self::canReach($actor, $tenantId)) throw new ServiceError('forbidden', 'forbidden', 'You cannot manage cost centres for that company.');
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  Reads
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Every cost centre of one company, active and inactive, ordered by code,
     * each with its parent's code and how many children it has.
     */
    public static function listForCompany(PDO $conn, ActorContext $actor, int $tenantId): array
    {
        self::assertCompany($conn, $actor, $tenantId);
        $st = $conn->prepare(
            "SELECT c.*, p.code AS parent_code, p.name AS parent_name,
                    (SELECT COUNT(*) FROM cost_centres k WHERE k.parent_id = c.id) AS child_count
               FROM cost_centres c
          LEFT JOIN cost_centres p ON p.id = c.parent_id
              WHERE c.tenant_id = ?
           ORDER BY c.code"
        );
        $st->execute([$tenantId]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    /** One cost centre, or not_found (also when it is in a company the caller cannot reach). */
    public static function load(PDO $conn, ActorContext $actor, int $id): array
    {
        $st = $conn->prepare(
            "SELECT c.*, p.code AS parent_code, p.name AS parent_name, t.name AS company_name,
                    (SELECT COUNT(*) FROM cost_centres k WHERE k.parent_id = c.id) AS child_count
               FROM cost_centres c
          LEFT JOIN cost_centres p ON p.id = c.parent_id
          LEFT JOIN tenants t ON t.id = c.tenant_id
              WHERE c.id = ?"
        );
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row || !self::canReach($actor, (int)$row['tenant_id'])) {
            throw new ServiceError('not_found', 'not_found', 'Cost centre not found.');
        }
        return $row;
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  Writes, one at a time (the screen; POST/PATCH in the API)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Add one. $in: code, name (required); description, parent_id OR parent_code,
     * is_active (optional).
     */
    public static function create(PDO $conn, ActorContext $actor, int $tenantId, array $in): int
    {
        self::assertCompany($conn, $actor, $tenantId);
        $code = self::cleanCode($in['code'] ?? null);
        $name = self::cleanName($in['name'] ?? null);
        $desc = self::cleanDescription($in['description'] ?? null);
        $active = array_key_exists('is_active', $in) ? self::cleanBool($in['is_active'], 'is_active') : 1;
        self::assertCodeFree($conn, $tenantId, $code, null);
        $parentId = self::resolveParentInput($conn, $tenantId, $in, null);

        $st = $conn->prepare("INSERT INTO cost_centres (tenant_id, code, name, description, parent_id, is_active) VALUES (?, ?, ?, ?, ?, ?)");
        try {
            $st->execute([$tenantId, $code, $name, $desc, $parentId, $active]);
        } catch (PDOException $e) {
            if (($e->errorInfo[1] ?? 0) == 1062) self::codeTaken($code);   // lost a race to the same code
            throw $e;
        }
        return (int)$conn->lastInsertId();
    }

    /** Change one. Only the keys present in $in are touched (PATCH semantics). */
    public static function update(PDO $conn, ActorContext $actor, int $id, array $in): void
    {
        $row = self::load($conn, $actor, $id);
        $tenantId = (int)$row['tenant_id'];
        $set = [];
        if (array_key_exists('code', $in)) {
            $code = self::cleanCode($in['code']);
            self::assertCodeFree($conn, $tenantId, $code, $id);
            $set['code'] = $code;
        }
        if (array_key_exists('name', $in))        $set['name'] = self::cleanName($in['name']);
        if (array_key_exists('description', $in)) $set['description'] = self::cleanDescription($in['description']);
        if (array_key_exists('is_active', $in))   $set['is_active'] = self::cleanBool($in['is_active'], 'is_active');
        if (array_key_exists('parent_id', $in) || array_key_exists('parent_code', $in)) {
            $set['parent_id'] = self::resolveParentInput($conn, $tenantId, $in, $id);
        }
        if (!$set) return;

        $cols = implode(', ', array_map(fn($c) => "$c = ?", array_keys($set)));
        $st = $conn->prepare("UPDATE cost_centres SET $cols WHERE id = ?");
        try {
            $st->execute(array_merge(array_values($set), [$id]));
        } catch (PDOException $e) {
            if (($e->errorInfo[1] ?? 0) == 1062) self::codeTaken((string)$set['code']);
            throw $e;
        }
    }

    /** Delete one - refused while anything sits below it. Make it inactive instead. */
    public static function delete(PDO $conn, ActorContext $actor, int $id): void
    {
        $row = self::load($conn, $actor, $id);
        if ((int)$row['child_count'] > 0) {
            throw new ServiceError('conflict', 'conflict',
                "Cost centre {$row['code']} has " . (int)$row['child_count'] . " cost centre(s) below it. Move or delete those first, or make it inactive instead.");
        }
        $conn->prepare("DELETE FROM cost_centres WHERE id = ?")->execute([$id]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  Sync - the spreadsheet import and POST /cost-centres/sync
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Bring a company's list into line with an incoming one, matched on CODE.
     *
     * $rows: [['line' => n, 'code' => ..., 'name' => ..., 'description' => ...,
     *          'parent_code' => ..., 'is_active' => bool|string], ...]
     * A key that is ABSENT means "leave that field as it is" - so an API caller
     * can send only codes and names. A key present but empty means empty:
     * parent_code '' = top level, description '' = none.
     *
     * $opts: dry_run (bool)            work it all out, write nothing
     *        deactivate_missing (bool) make active cost centres that are NOT in
     *                                  $rows inactive - for a full list from the
     *                                  finance system. Never deletes.
     *
     * 🔑 ALL OR NOTHING. If any row has a problem, nothing is written and every
     * problem is reported, with its line. A half-applied nightly sync is worse
     * than none: the next one cannot tell what the last one did.
     *
     * @return array{applied: bool, dry_run: bool, counts: array, errors: array, rows: array}
     */
    public static function sync(PDO $conn, ActorContext $actor, int $tenantId, array $rows, array $opts = []): array
    {
        self::assertCompany($conn, $actor, $tenantId);
        $dryRun = !empty($opts['dry_run']);
        $deactivateMissing = !empty($opts['deactivate_missing']);

        // What is there now, keyed by lower-cased code.
        $existing = [];
        $byId = [];
        foreach (self::listForCompany($conn, $actor, $tenantId) as $r) {
            $existing[mb_strtolower($r['code'])] = $r;
            $byId[(int)$r['id']] = $r;
        }

        $errors = [];
        $incoming = [];     // lower(code) => normalised row
        foreach (array_values($rows) as $i => $r) {
            $line = (int)($r['line'] ?? ($i + 1));
            $err = function (string $msg, string $code = '') use (&$errors, $line) {
                $errors[] = ['line' => $line, 'code' => $code, 'message' => $msg];
            };
            try {
                $code = self::cleanCode($r['code'] ?? null);
            } catch (ServiceError $e) {
                $err($e->getMessage());
                continue;
            }
            $key = mb_strtolower($code);
            if (isset($incoming[$key])) {
                $err("Code {$code} appears more than once (also line {$incoming[$key]['line']}). Codes are matched ignoring capitals, so N1414 and n1414 are the same.", $code);
                continue;
            }
            $was = $existing[$key] ?? null;
            $n = ['line' => $line, 'code' => $code, 'was' => $was];
            try {
                if (array_key_exists('name', $r) && trim((string)$r['name']) !== '') {
                    $n['name'] = self::cleanName($r['name']);
                } elseif (!$was) {
                    throw new ServiceError('validation', 'missing_field', "Code {$code} is new, so it needs a name.");
                }
                if (array_key_exists('description', $r)) $n['description'] = self::cleanDescription($r['description']);
                if (array_key_exists('is_active', $r) && !($r['is_active'] === '' || $r['is_active'] === null)) {
                    $n['is_active'] = self::cleanBool($r['is_active'], 'active');
                }
                if (array_key_exists('parent_code', $r)) {
                    $pc = trim((string)$r['parent_code']);
                    if ($pc !== '' && mb_strtolower($pc) === $key) throw new ServiceError('validation', 'invalid_field', "Code {$code} cannot be its own parent.");
                    $n['parent_code'] = $pc;
                }
            } catch (ServiceError $e) {
                $err($e->getMessage(), $code);
                continue;
            }
            $incoming[$key] = $n;
        }

        // Every parent must exist once the sync is done: already there, or in
        // this same list. Then the finished tree must have no loops.
        $parentOf = [];   // lower(code) => lower(parent code) | null, for the FINISHED state
        foreach ($existing as $k => $r) {
            $parentOf[$k] = $r['parent_id'] !== null && isset($byId[(int)$r['parent_id']]) ? mb_strtolower($byId[(int)$r['parent_id']]['code']) : null;
        }
        foreach ($incoming as $k => $n) {
            if (!array_key_exists('parent_code', $n)) {
                if (!isset($parentOf[$k])) $parentOf[$k] = null;
                continue;
            }
            if ($n['parent_code'] === '') { $parentOf[$k] = null; continue; }
            $pk = mb_strtolower($n['parent_code']);
            if (!isset($incoming[$pk]) && !isset($existing[$pk])) {
                $errors[] = ['line' => $n['line'], 'code' => $n['code'], 'message' => "Parent {$n['parent_code']} of {$n['code']} is neither in this list nor already a cost centre for this company."];
                $parentOf[$k] = null;
                continue;
            }
            $parentOf[$k] = $pk;
        }
        foreach ($incoming as $k => $n) {
            $seen = [$k => true];
            for ($p = $parentOf[$k] ?? null, $hops = 0; $p !== null && $hops < 10000; $p = $parentOf[$p] ?? null, $hops++) {
                if (isset($seen[$p])) {
                    $errors[] = ['line' => $n['line'], 'code' => $n['code'], 'message' => "Code {$n['code']} would end up below itself - the parents go round in a loop."];
                    break;
                }
                $seen[$p] = true;
            }
        }

        // What each row will do.
        $plan = [];
        $counts = ['create' => 0, 'update' => 0, 'unchanged' => 0, 'deactivate' => 0];
        foreach ($incoming as $k => $n) {
            $was = $n['was'];
            if (!$was) {
                $plan[] = ['line' => $n['line'], 'code' => $n['code'], 'name' => $n['name'], 'action' => 'create', 'changes' => []];
                $counts['create']++;
                continue;
            }
            $changes = [];
            if ($was['code'] !== $n['code']) $changes[] = 'code';
            if (isset($n['name']) && $was['name'] !== $n['name']) $changes[] = 'name';
            if (array_key_exists('description', $n) && (string)$was['description'] !== (string)$n['description']) $changes[] = 'description';
            if (isset($n['is_active']) && (int)$was['is_active'] !== $n['is_active']) $changes[] = 'is_active';
            if (array_key_exists('parent_code', $n)) {
                $wasParent = $was['parent_id'] !== null && isset($byId[(int)$was['parent_id']]) ? mb_strtolower($byId[(int)$was['parent_id']]['code']) : null;
                if ($wasParent !== ($parentOf[$k] ?? null)) $changes[] = 'parent';
            }
            $action = $changes ? 'update' : 'unchanged';
            $plan[] = ['line' => $n['line'], 'code' => $n['code'], 'name' => $n['name'] ?? $was['name'], 'action' => $action, 'changes' => $changes];
            $counts[$action]++;
        }
        $toDeactivate = [];
        if ($deactivateMissing) {
            foreach ($existing as $k => $r) {
                if (!isset($incoming[$k]) && (int)$r['is_active'] === 1) {
                    $toDeactivate[] = (int)$r['id'];
                    $plan[] = ['line' => null, 'code' => $r['code'], 'name' => $r['name'], 'action' => 'deactivate', 'changes' => ['is_active']];
                    $counts['deactivate']++;
                }
            }
        }

        $report = ['applied' => false, 'dry_run' => $dryRun, 'counts' => $counts, 'errors' => $errors, 'rows' => $plan];
        if ($errors || $dryRun) return $report;

        // Join a caller's transaction rather than fail on a nested one (the
        // test runs inside one it rolls back).
        $own = !$conn->inTransaction();
        if ($own) $conn->beginTransaction();
        try {
            $ids = [];   // lower(code) => id, for every cost centre once written
            foreach ($existing as $k => $r) $ids[$k] = (int)$r['id'];

            $ins = $conn->prepare("INSERT INTO cost_centres (tenant_id, code, name, description, is_active) VALUES (?, ?, ?, ?, ?)");
            foreach ($incoming as $k => $n) {
                if ($n['was']) {
                    $set = ['code' => $n['code']];
                    if (isset($n['name']))                        $set['name'] = $n['name'];
                    if (array_key_exists('description', $n))      $set['description'] = $n['description'];
                    if (isset($n['is_active']))                   $set['is_active'] = $n['is_active'];
                    $cols = implode(', ', array_map(fn($c) => "$c = ?", array_keys($set)));
                    $conn->prepare("UPDATE cost_centres SET $cols WHERE id = ?")->execute(array_merge(array_values($set), [(int)$n['was']['id']]));
                } else {
                    $ins->execute([$tenantId, $n['code'], $n['name'], $n['description'] ?? null, $n['is_active'] ?? 1]);
                    $ids[$k] = (int)$conn->lastInsertId();
                }
            }
            // Parents second, once every code in the list has an id.
            $par = $conn->prepare("UPDATE cost_centres SET parent_id = ? WHERE id = ?");
            foreach ($incoming as $k => $n) {
                if (!array_key_exists('parent_code', $n)) continue;
                $pk = $parentOf[$k] ?? null;
                $par->execute([$pk === null ? null : $ids[$pk], $ids[$k]]);
            }
            if ($toDeactivate) {
                $conn->prepare("UPDATE cost_centres SET is_active = 0 WHERE tenant_id = ? AND id IN (" . implode(',', array_fill(0, count($toDeactivate), '?')) . ")")
                     ->execute(array_merge([$tenantId], $toDeactivate));
            }
            if ($own) $conn->commit();
        } catch (Throwable $e) {
            if ($own) $conn->rollBack();
            throw $e;
        }
        $report['applied'] = true;
        return $report;
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  Field rules
    // ─────────────────────────────────────────────────────────────────────────

    /** Trimmed, never otherwise changed: "0010" stays "0010". */
    public static function cleanCode($v): string
    {
        $v = trim((string)$v);
        if ($v === '') throw new ServiceError('validation', 'missing_field', 'A code is required.');
        if (preg_match('/[\x00-\x1F\x7F]/', $v)) throw new ServiceError('validation', 'invalid_field', 'The code contains a line break or other control character.');
        if (mb_strlen($v) > self::CODE_MAX) throw new ServiceError('validation', 'invalid_field', "Code {$v} is longer than " . self::CODE_MAX . " characters.");
        return $v;
    }

    private static function cleanName($v): string
    {
        $v = trim(preg_replace('/[\x00-\x1F\x7F]+/', ' ', (string)$v));
        if ($v === '') throw new ServiceError('validation', 'missing_field', 'A name is required.');
        if (mb_strlen($v) > self::NAME_MAX) throw new ServiceError('validation', 'invalid_field', 'The name is longer than ' . self::NAME_MAX . ' characters.');
        return $v;
    }

    private static function cleanDescription($v): ?string
    {
        $v = trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]+/', ' ', (string)$v));
        if ($v === '') return null;
        if (mb_strlen($v) > self::DESC_MAX) throw new ServiceError('validation', 'invalid_field', 'The description is longer than ' . self::DESC_MAX . ' characters.');
        return $v;
    }

    /** true/false, 1/0, yes/no, y/n, active/inactive - what a spreadsheet or an ERP export says. */
    public static function cleanBool($v, string $field): int
    {
        if (is_bool($v)) return $v ? 1 : 0;
        if (is_int($v) && ($v === 0 || $v === 1)) return $v;
        $s = mb_strtolower(trim((string)$v));
        // ja/nein/aktiv/inaktiv too: the import already reads German headings.
        if (in_array($s, ['1', 'true', 'yes', 'y', 'active', 'on', 'ja', 'j', 'aktiv'], true)) return 1;
        if (in_array($s, ['0', 'false', 'no', 'n', 'inactive', 'off', 'nein', 'inaktiv'], true)) return 0;
        throw new ServiceError('validation', 'invalid_field', "'{$v}' is not a valid value for {$field} - use Yes or No.");
    }

    private static function codeTaken(string $code): void
    {
        throw new ServiceError('conflict', 'conflict', "Code {$code} is already used by another cost centre in this company. Codes are matched ignoring capitals.");
    }

    private static function assertCodeFree(PDO $conn, int $tenantId, string $code, ?int $exceptId): void
    {
        // The column's _ci collation makes = ignore case, matching the unique key.
        $st = $conn->prepare("SELECT id FROM cost_centres WHERE tenant_id = ? AND code = ?" . ($exceptId ? " AND id <> ?" : ''));
        $st->execute($exceptId ? [$tenantId, $code, $exceptId] : [$tenantId, $code]);
        if ($st->fetchColumn() !== false) self::codeTaken($code);
    }

    /**
     * parent_id or parent_code from the input -> a parent id or null, checked:
     * same company, not itself, not below itself.
     */
    private static function resolveParentInput(PDO $conn, int $tenantId, array $in, ?int $selfId): ?int
    {
        if (array_key_exists('parent_id', $in)) {
            $v = $in['parent_id'];
            if ($v === null || $v === '' || (string)$v === '0') return null;
            if (!is_numeric($v)) throw new ServiceError('validation', 'invalid_field', 'parent_id must be a number.');
            $st = $conn->prepare("SELECT id, tenant_id FROM cost_centres WHERE id = ?");
            $st->execute([(int)$v]);
        } elseif (array_key_exists('parent_code', $in)) {
            $v = trim((string)$in['parent_code']);
            if ($v === '') return null;
            $st = $conn->prepare("SELECT id, tenant_id FROM cost_centres WHERE tenant_id = ? AND code = ?");
            $st->execute([$tenantId, $v]);
        } else {
            return null;
        }
        $p = $st->fetch(PDO::FETCH_ASSOC);
        if (!$p || (int)$p['tenant_id'] !== $tenantId) {
            throw new ServiceError('validation', 'invalid_field', 'The parent cost centre was not found in this company.');
        }
        $parentId = (int)$p['id'];
        if ($selfId !== null) {
            if ($parentId === $selfId) throw new ServiceError('validation', 'invalid_field', 'A cost centre cannot be its own parent.');
            // Walk up from the new parent; meeting ourselves means a loop.
            $up = $conn->prepare("SELECT parent_id FROM cost_centres WHERE id = ?");
            for ($at = $parentId, $hops = 0; $at !== null && $hops < 10000; $hops++) {
                $up->execute([$at]);
                $next = $up->fetchColumn();
                $at = ($next === false || $next === null) ? null : (int)$next;
                if ($at === $selfId) throw new ServiceError('validation', 'invalid_field', 'That parent is below this cost centre, which would make a loop.');
            }
        }
        return $parentId;
    }
}
