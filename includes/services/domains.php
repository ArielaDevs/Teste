<?php
/**
 * DomainsService — the write rules for the Domains module (#154), ONCE.
 *
 * Shared by the UI endpoints (api/domains/*.php), the REST API
 * (api/v1/resources/domains.php) and the scheduled run (cron/domains.php). Each
 * caller passes an ActorContext + canonical input; this layer validates, writes,
 * records history and fires events, and returns ids or throws ServiceError. It
 * never emits HTTP.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * COMPANIES
 *
 * A domain is scoped data: it belongs to exactly one company, NULL = the
 * Default company. Every by-id method starts with loadForActor(), which 404s a
 * domain in a company the caller cannot reach — never 403, so a company's
 * domain list cannot be probed by guessing ids. The same name may be held by
 * two companies (an MSP can manage example.com for one client and example.net
 * for another, and nothing stops two clients sharing a name in their records),
 * so the duplicate check is per company.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * HISTORY
 *
 * Every field that changes gets a domain_audit row, whoever changed it: a
 * person (analyst_id set), a registry lookup (source 'lookup'), a nightly check
 * (source 'check') or change detection (source 'monitor'). The auth code is the
 * exception that proves the rule: its CHANGE is recorded, its value never is.
 */

require_once __DIR__ . '/../service_context.php';
require_once __DIR__ . '/../tenancy.php';
require_once __DIR__ . '/../encryption.php';
require_once __DIR__ . '/../domains/settings.php';
require_once __DIR__ . '/../domains/names.php';
require_once __DIR__ . '/../domains/lookup.php';
require_once __DIR__ . '/../domains/checks.php';
require_once __DIR__ . '/../domains/monitor.php';
require_once dirname(__DIR__, 2) . '/workflow/includes/engine.php';

class DomainsService
{
    /**
     * The fields a person (or an API key) may set, and how each is validated.
     * domain_name and auth_code are handled separately.
     */
    public static function fieldMap(): array
    {
        return [
            'status_id'             => ['type' => 'lookup', 'table' => 'domain_statuses'],
            'purpose'               => ['type' => 'enum',   'values' => domainPurposes()],
            'registrar_supplier_id' => ['type' => 'lookup', 'table' => 'suppliers'],
            'registrar_account_id'  => ['type' => 'account'],
            'registrar_name'        => ['type' => 'string', 'max' => 255],
            'registration_date'     => ['type' => 'date'],
            'expiry_date'           => ['type' => 'date'],
            'last_renewed_date'     => ['type' => 'date'],
            'registry_updated_date' => ['type' => 'date'],
            'renewal_mode'          => ['type' => 'enum',   'values' => domainRenewalModes()],
            'transfer_lock'         => ['type' => 'tribool'],
            'registry_lock'         => ['type' => 'tribool'],
            'dnssec'                => ['type' => 'tribool'],
            'registrant_name'       => ['type' => 'string', 'max' => 255],
            'owner_analyst_id'      => ['type' => 'analyst'],
            'tech_contact_id'       => ['type' => 'lookup', 'table' => 'contacts'],
            'nameservers'           => ['type' => 'lines'],
            'dns_provider'          => ['type' => 'string', 'max' => 255],
            'hosting_provider'      => ['type' => 'string', 'max' => 255],
            'ssl_hosts'             => ['type' => 'ssl_hosts'],
            'dkim_selectors'        => ['type' => 'string', 'max' => 255],
            'cost'                  => ['type' => 'money'],
            'currency'              => ['type' => 'currency'],
            'billing_years'         => ['type' => 'int',    'min' => 1, 'max' => 10],
            'cost_centre'           => ['type' => 'string', 'max' => 100],
            'contract_id'           => ['type' => 'lookup', 'table' => 'contracts'],
            'tags'                  => ['type' => 'tags'],
            'notes'                 => ['type' => 'text'],
            'monitoring_enabled'    => ['type' => 'bool'],
        ];
    }

    /** Fields the bulk editor may set on many domains at once. */
    public static function bulkFields(): array
    {
        return ['status_id', 'purpose', 'owner_analyst_id', 'renewal_mode', 'registrar_supplier_id',
                'registrar_account_id', 'monitoring_enabled', 'cost_centre', 'tags'];
    }

    // ======================================================================
    //  Create / update / delete
    // ======================================================================

    /**
     * Create (no id) or update (id present) a domain. Returns ['id', 'created'].
     *
     * @param ?int $tenantId the company a NEW domain belongs to (the adapter
     *        resolves it: the analyst's active company, or the API key's). Ignored
     *        on update — moving a domain between companies is not an edit.
     */
    public static function saveDomain(PDO $conn, ActorContext $ctx, array $in, ?int $tenantId = null): array
    {
        if (!empty($in['id'])) {
            return ['id' => self::updateDomain($conn, $ctx, (int)$in['id'], $in), 'created' => false];
        }
        return ['id' => self::createDomain($conn, $ctx, $in, $tenantId), 'created' => true];
    }

    public static function createDomain(PDO $conn, ActorContext $ctx, array $in, ?int $tenantId, string $source = 'app'): int
    {
        $n = domainNormalise((string)($in['domain_name'] ?? ''));
        if (!$n['ok']) {
            throw new ServiceError('validation', empty($in['domain_name']) ? 'missing_field' : 'invalid_field', $n['error']);
        }
        $store = self::storeTenant($conn, $tenantId);
        if ($ctx->companyScope !== null) {
            $effective = $store === null ? getDefaultTenantId($conn) : $store;
            if (!in_array($effective, $ctx->companyScope, true)) {
                throw new ServiceError('forbidden', 'forbidden', 'You cannot add domains for that company.');
            }
        }
        $dup = $conn->prepare("SELECT id FROM domains WHERE domain_name = ? AND tenant_id <=> ?");
        $dup->execute([$n['name'], $store]);
        if (($existing = $dup->fetchColumn()) !== false) {
            throw new ServiceError('conflict', 'conflict', "{$n['name']} is already in the register (id {$existing}).");
        }

        $cols = ['domain_name', 'display_name', 'tenant_id', 'created_by'];
        $vals = [$n['name'], $n['display'], $store, $ctx->actorId > 0 ? $ctx->actorId : null];
        $row  = ['tenant_id' => $store];
        foreach (self::fieldMap() as $field => $def) {
            if (!array_key_exists($field, $in)) continue;
            $v = self::validateField($conn, $field, $in[$field], $def, $row, $n['name']);
            $cols[] = $field;
            $vals[] = $v;
        }
        // A new domain starts in the first "alerts on" status unless told otherwise.
        if (!in_array('status_id', $cols, true)) {
            $sid = $conn->query("SELECT id FROM domain_statuses WHERE is_active = 1 ORDER BY display_order, id LIMIT 1")->fetchColumn();
            if ($sid) { $cols[] = 'status_id'; $vals[] = (int)$sid; }
        }
        $ph = implode(', ', array_fill(0, count($cols), '?'));
        $conn->prepare("INSERT INTO domains (" . implode(', ', $cols) . ", created_datetime, updated_datetime)
                        VALUES ($ph, UTC_TIMESTAMP(), UTC_TIMESTAMP())")->execute($vals);
        $id = (int)$conn->lastInsertId();

        if (!empty($in['auth_code'])) {
            $conn->prepare("UPDATE domains SET auth_code = ? WHERE id = ?")->execute([encryptValue((string)$in['auth_code']), $id]);
        }

        self::audit($conn, $id, $ctx->actorId, 'domain_created', null, $n['name'], $source === 'app' ? ($ctx->source === 'api' ? 'api' : 'app') : $source);
        self::afterDateChange($conn);
        self::dispatchCrud($conn, 'created', $id);
        return $id;
    }

    public static function updateDomain(PDO $conn, ActorContext $ctx, int $id, array $in, string $source = ''): int
    {
        $cur = self::loadForActor($conn, $ctx, $id);
        $source = $source ?: ($ctx->source === 'api' ? 'api' : 'app');
        $fields = array_diff_key($in, ['id' => true]);
        if (!$fields) throw new ServiceError('validation', 'missing_field', 'No fields to update.');

        $sets = []; $args = []; $changes = [];

        if (array_key_exists('domain_name', $in)) {
            $n = domainNormalise((string)$in['domain_name']);
            if (!$n['ok']) throw new ServiceError('validation', 'invalid_field', $n['error']);
            if ($n['name'] !== $cur['domain_name']) {
                $dup = $conn->prepare("SELECT id FROM domains WHERE domain_name = ? AND tenant_id <=> ? AND id <> ?");
                $dup->execute([$n['name'], $cur['tenant_id'], $id]);
                if ($dup->fetchColumn() !== false) throw new ServiceError('conflict', 'conflict', "{$n['name']} is already in the register.");
                $sets[] = 'domain_name = ?'; $args[] = $n['name'];
                $sets[] = 'display_name = ?'; $args[] = $n['display'];
                // A different name is a different registration: what change
                // detection knew about the old one means nothing for the new one.
                $sets[] = 'watch_baseline = NULL';
                $changes[] = ['domain_name', $cur['domain_name'], $n['name']];
            }
        }

        foreach (self::fieldMap() as $field => $def) {
            if (!array_key_exists($field, $in)) continue;
            $v = self::validateField($conn, $field, $in[$field], $def, $cur, $cur['domain_name']);
            if (self::same($cur[$field], $v)) continue;
            $sets[] = "$field = ?"; $args[] = $v;
            $changes[] = [$field, $cur[$field], $v];
        }

        if (array_key_exists('auth_code', $in)) {
            // Only reachable when the adapter has already checked Cap::DOMAINS_AUTH_CODES.
            $new = trim((string)$in['auth_code']);
            $sets[] = 'auth_code = ?'; $args[] = $new === '' ? null : encryptValue($new);
            self::audit($conn, $id, $ctx->actorId, 'auth_code', null, $new === '' ? '(cleared)' : '(changed)', $source);
        }

        if (!$sets) return $id;
        $args[] = $id;
        $conn->prepare("UPDATE domains SET " . implode(', ', $sets) . ", updated_datetime = UTC_TIMESTAMP() WHERE id = ?")->execute($args);

        foreach ($changes as [$f, $o, $nv]) {
            self::audit($conn, $id, $ctx->actorId, $f, self::auditDisplay($conn, $f, $o), self::auditDisplay($conn, $f, $nv), $source);
        }
        if (array_intersect(array_column($changes, 0), ['expiry_date', 'domain_name', 'status_id'])) self::afterDateChange($conn);
        if ($changes) self::dispatchCrud($conn, 'updated', $id);
        return $id;
    }

    /** Delete a domain and everything that hangs off it. Returns the id. */
    public static function deleteDomain(PDO $conn, ActorContext $ctx, int $id): int
    {
        $row = self::loadForActor($conn, $ctx, $id);
        $conn->beginTransaction();
        try {
            // Children by hand: an upgraded install whose FKs failed to add has
            // no cascade to rely on (see the Checklists house-style review §3).
            foreach (['domain_audit', 'domain_alerts_sent', 'domain_lookalikes', 'domain_certificates'] as $t) {
                $conn->prepare("DELETE FROM `$t` WHERE domain_id = ?")->execute([$id]);
            }
            $conn->prepare("DELETE FROM domains WHERE id = ?")->execute([$id]);
            $conn->commit();
        } catch (Throwable $e) {
            if ($conn->inTransaction()) $conn->rollBack();
            throw $e;
        }
        try {
            if (!function_exists('documentsDetachParent') && is_file(__DIR__ . '/../documents.php')) require_once __DIR__ . '/../documents.php';
            if (function_exists('documentsDetachParent')) documentsDetachParent($conn, 'domain', $id);
        } catch (Throwable $e) { error_log('domains: detach documents: ' . $e->getMessage()); }
        self::afterDateChange($conn);
        WorkflowEngine::dispatch('domain.deleted', ['domain' => self::eventPayload($row)]);
        return $id;
    }

    /**
     * Add many domains from a pasted list (one per line, or separated by commas
     * or spaces). Every name goes through the same create path as a single add.
     *
     * @return array{added:array<int,array{id:int,name:string}>, skipped:array<int,array{input:string,reason:string}>}
     */
    public static function bulkAdd(PDO $conn, ActorContext $ctx, string $text, array $defaults, ?int $tenantId, string $source = 'app'): array
    {
        $added = []; $skipped = []; $seen = [];
        $parts = preg_split('/[\r\n,;\t ]+/', $text);
        if (count(array_filter($parts)) > 1000) {
            throw new ServiceError('validation', 'invalid_field', 'Add at most 1,000 domains at a time.');
        }
        foreach ($parts as $raw) {
            $raw = trim($raw);
            if ($raw === '') continue;
            $n = domainNormalise($raw);
            if (!$n['ok']) { $skipped[] = ['input' => $raw, 'reason' => $n['error']]; continue; }
            if (isset($seen[$n['name']])) { $skipped[] = ['input' => $raw, 'reason' => 'Listed twice.']; continue; }
            $seen[$n['name']] = true;
            try {
                $id = self::createDomain($conn, $ctx, array_merge($defaults, ['domain_name' => $n['name']]), $tenantId, $source);
                $added[] = ['id' => $id, 'name' => $n['name']];
            } catch (ServiceError $e) {
                $skipped[] = ['input' => $raw, 'reason' => $e->getMessage()];
            }
        }
        return ['added' => $added, 'skipped' => $skipped];
    }

    /**
     * Set the same fields on many domains. Each goes through updateDomain(), so
     * each gets its own history rows and scope check; a domain the caller cannot
     * reach is reported, not silently skipped.
     */
    public static function bulkUpdate(PDO $conn, ActorContext $ctx, array $ids, array $fields): array
    {
        $allowed = array_intersect_key($fields, array_flip(self::bulkFields()));
        if (!$allowed) throw new ServiceError('validation', 'missing_field', 'Choose at least one field to change.');
        $done = 0; $failed = [];
        foreach (array_unique(array_map('intval', $ids)) as $id) {
            if ($id <= 0) continue;
            try { self::updateDomain($conn, $ctx, $id, $allowed); $done++; }
            catch (ServiceError $e) { $failed[] = ['id' => $id, 'reason' => $e->getMessage()]; }
        }
        return ['updated' => $done, 'failed' => $failed];
    }

    // ======================================================================
    //  Registry lookups
    // ======================================================================

    /** Look a domain up now and apply the answer. For the "Refresh" button and the API. */
    public static function refreshLookup(PDO $conn, ActorContext $ctx, int $id): array
    {
        $row = self::loadForActor($conn, $ctx, $id);
        $r = domainLookup($conn, $row['domain_name']);
        if (!$r['ok']) {
            self::recordLookupError($conn, $id, (string)$r['error']);
            return ['ok' => false, 'error' => $r['error'], 'changes' => []];
        }
        $changes = self::applyLookup($conn, $id, $r, $ctx->actorId > 0 ? $ctx->actorId : null);
        return ['ok' => true, 'source' => $r['source'], 'changes' => $changes, 'expiry_published' => $r['expiry_published']];
    }

    /**
     * Write a lookup result onto a domain, under the operator's overwrite rule.
     *
     * 'always' — the registry is the record for the facts it holds.
     * 'blanks' — it only fills fields nobody has typed into.
     *
     * Registry statuses are never typed by people, so they always follow the
     * registry. A typed expiry date on a registry that publishes none (.de) is
     * never blanked by that registry's silence: a null answer is "not published",
     * not "no date".
     *
     * @return array<int,array{field:string,old:?string,new:?string}>
     */
    public static function applyLookup(PDO $conn, int $id, array $r, ?int $analystId = null): array
    {
        $cur = self::loadRow($conn, $id);
        $overwrite = domainSetting($conn, 'domain_lookup_overwrite') !== 'blanks';

        $map = [
            'registrar_name'        => $r['registrar'],
            'registration_date'     => $r['registration_date'],
            'expiry_date'           => $r['expiry_date'],
            'registry_updated_date' => $r['updated_date'],
            'last_renewed_date'     => $r['last_renewed_date'],
            'nameservers'           => $r['nameservers'] ? implode("\n", $r['nameservers']) : null,
            'dnssec'                => $r['dnssec'] === null ? null : (int)$r['dnssec'],
            'transfer_lock'         => $r['transfer_lock'] === null ? null : (int)$r['transfer_lock'],
            'registry_lock'         => $r['registry_lock'] === null ? null : (int)$r['registry_lock'],
        ];
        $sets = []; $args = []; $changes = [];
        foreach ($map as $f => $v) {
            if ($v === null || $v === '') continue;                 // silence is not an answer
            $isBlank = $cur[$f] === null || $cur[$f] === '';
            if (!$overwrite && !$isBlank) continue;
            if (self::same($cur[$f], $v)) continue;
            $sets[] = "$f = ?"; $args[] = $v;
            $changes[] = ['field' => $f, 'old' => $cur[$f], 'new' => $v];
        }
        // Registrant only ever fills a blank — the registry's copy is usually
        // redacted, and a person's entry is the better record.
        if (!empty($r['registrant']) && ($cur['registrant_name'] === null || $cur['registrant_name'] === '')) {
            $sets[] = 'registrant_name = ?'; $args[] = $r['registrant'];
            $changes[] = ['field' => 'registrant_name', 'old' => null, 'new' => $r['registrant']];
        }
        // A registry where transfer locks do not exist (.uk): clear a stale
        // "off" rather than keep flagging it — silence here IS the answer.
        if (!empty($r['transfer_lock_na']) && $r['transfer_lock'] === null && $cur['transfer_lock'] !== null) {
            $sets[] = 'transfer_lock = NULL';
            $changes[] = ['field' => 'transfer_lock', 'old' => $cur['transfer_lock'], 'new' => null];
        }
        $statuses = implode(', ', $r['statuses']);
        if ($statuses !== (string)$cur['registry_statuses']) {
            $sets[] = 'registry_statuses = ?'; $args[] = $statuses !== '' ? mb_substr($statuses, 0, 500) : null;
            $changes[] = ['field' => 'registry_statuses', 'old' => $cur['registry_statuses'], 'new' => $statuses];
        }
        // The registrar, matched to a Contracts supplier by name when nobody
        // has chosen one — so the supplier's page lists the domains it holds.
        if (empty($cur['registrar_supplier_id']) && !empty($r['registrar'])) {
            $sid = self::matchSupplier($conn, $r['registrar']);
            if ($sid) {
                $sets[] = 'registrar_supplier_id = ?'; $args[] = $sid;
                $changes[] = ['field' => 'registrar_supplier_id', 'old' => null, 'new' => $sid];
            }
        }
        $sets[] = 'lookup_source = ?';            $args[] = $r['source'];
        $sets[] = 'last_lookup_datetime = UTC_TIMESTAMP()';
        $sets[] = 'last_lookup_error = NULL';
        $args[] = $id;
        $conn->prepare("UPDATE domains SET " . implode(', ', $sets) . " WHERE id = ?")->execute($args);

        foreach ($changes as $c) {
            self::audit($conn, $id, $analystId, $c['field'], self::auditDisplay($conn, $c['field'], $c['old']), self::auditDisplay($conn, $c['field'], $c['new']), 'lookup');
        }
        if (array_intersect(array_column($changes, 'field'), ['expiry_date'])) self::afterDateChange($conn);
        return $changes;
    }

    public static function recordLookupError(PDO $conn, int $id, string $error): void
    {
        $conn->prepare("UPDATE domains SET last_lookup_datetime = UTC_TIMESTAMP(), last_lookup_error = ? WHERE id = ?")
             ->execute([mb_substr($error, 0, 255), $id]);
    }

    // ======================================================================
    //  Checks + change detection
    // ======================================================================

    /**
     * Run the health/security checks for one domain, store the result, and
     * compare against the last run. Returns the check result plus 'changes'.
     *
     * @param ?ActorContext $ctx null when the scheduled run calls it
     */
    public static function runChecks(PDO $conn, ?ActorContext $ctx, int $id): array
    {
        $row = $ctx ? self::loadForActor($conn, $ctx, $id) : self::loadRow($conn, $id);
        $res = domainRunChecks($row, domainSettings($conn));

        $old = $row['watch_baseline'] ? json_decode($row['watch_baseline'], true) : null;
        $new = domainBaseline($row, $res['snapshot']);
        $changes = domainBaselineDiff($old, $new);
        $baseline = domainBaselineMerge($old, $new);

        $conn->prepare(
            "UPDATE domains SET check_results = ?, security_score = ?, security_grade = ?, ssl_expiry_date = ?, ssl_issuer = ?,
                    watch_baseline = ?, last_check_datetime = UTC_TIMESTAMP() WHERE id = ?"
        )->execute([
            json_encode(['findings' => $res['findings'], 'certificates' => $res['certificates'], 'at' => gmdate('Y-m-d H:i:s')]),
            $res['score'], $res['grade'], $res['ssl_expiry_date'], $res['ssl_issuer'] ? mb_substr($res['ssl_issuer'], 0, 255) : null,
            json_encode($baseline), $id,
        ]);

        if ($row['security_grade'] !== null && $row['security_grade'] !== $res['grade']) {
            self::audit($conn, $id, null, 'security_grade', $row['security_grade'], $res['grade'], 'check');
        }
        // Registry-side fields already got a history row from the lookup that
        // changed them; only what DNS showed is recorded here.
        $dnsFieldNames = ['dns_ns' => 'dns_nameservers', 'mx' => 'dns_mx', 'spf' => 'dns_spf', 'dmarc' => 'dns_dmarc'];
        foreach ($changes as $c) {
            if (isset($dnsFieldNames[$c['field']])) {
                self::audit($conn, $id, null, $dnsFieldNames[$c['field']], $c['old'], $c['new'], 'monitor');
            }
        }
        if ($changes) {
            $serious = array_values(array_filter($changes, 'domainChangeIsSerious'));
            WorkflowEngine::dispatch('domain.changed', [
                'domain'  => self::eventPayload($row),
                'changes' => $changes,
                'serious' => $serious ? 1 : 0,
                'summary' => implode('; ', array_map(fn($c) => "{$c['field']}: {$c['old']} → {$c['new']}", $changes)),
            ]);
            // A possible hijack cannot wait for tomorrow's digest.
            if ($serious) {
                try {
                    require_once __DIR__ . '/../domains/alerts.php';
                    domainAlertOnChange($conn, $row, $serious);
                } catch (Throwable $e) {
                    error_log('domains change alert: ' . $e->getMessage());
                }
            }
        }
        $res['changes'] = $changes;
        return $res;
    }

    // ======================================================================
    //  Auth codes
    // ======================================================================

    /**
     * The decrypted auth code, for somebody the adapter has already checked
     * holds Cap::DOMAINS_AUTH_CODES. Recorded in the domain's history unless
     * the operator switched that off.
     */
    public static function revealAuthCode(PDO $conn, ActorContext $ctx, int $id): ?string
    {
        $row = self::loadForActor($conn, $ctx, $id);
        if ($row['auth_code'] === null || $row['auth_code'] === '') return null;
        $plain = decryptValue($row['auth_code']);
        if (domainSetting($conn, 'domain_auth_code_audit') === '1') {
            self::audit($conn, $id, $ctx->actorId, 'auth_code_viewed', null, $ctx->actorName ?: '(viewed)', $ctx->source === 'api' ? 'api' : 'app');
        }
        return $plain;
    }

    // ======================================================================
    //  Statuses (a settings list)
    // ======================================================================

    public static function saveStatus(PDO $conn, array $in): array
    {
        $name = trim((string)($in['name'] ?? ''));
        if ($name === '') throw new ServiceError('validation', 'missing_field', 'Enter a name.');
        if (mb_strlen($name) > 100) throw new ServiceError('validation', 'invalid_field', 'A name can be at most 100 characters.');
        $colour = trim((string)($in['colour'] ?? ''));
        if ($colour !== '' && !preg_match('/^#[0-9a-fA-F]{6}$/', $colour)) throw new ServiceError('validation', 'invalid_field', 'Choose a colour.');
        $vals = [$name, $colour ?: null, !empty($in['alerts_enabled']) ? 1 : 0, (!isset($in['is_active']) || $in['is_active']) ? 1 : 0, (int)($in['display_order'] ?? 0)];

        $id = (int)($in['id'] ?? 0);
        $dup = $conn->prepare("SELECT id FROM domain_statuses WHERE LOWER(name) = LOWER(?) AND id <> ?");
        $dup->execute([$name, $id]);
        if ($dup->fetchColumn()) throw new ServiceError('conflict', 'conflict', 'There is already a status with that name.');

        if ($id > 0) {
            $conn->prepare("UPDATE domain_statuses SET name = ?, colour = ?, alerts_enabled = ?, is_active = ?, display_order = ? WHERE id = ?")
                 ->execute([...$vals, $id]);
            return ['id' => $id, 'created' => false];
        }
        $conn->prepare("INSERT INTO domain_statuses (name, colour, alerts_enabled, is_active, display_order, created_datetime) VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP())")
             ->execute($vals);
        return ['id' => (int)$conn->lastInsertId(), 'created' => true];
    }

    public static function deleteStatus(PDO $conn, int $id): void
    {
        $inUse = $conn->prepare("SELECT COUNT(*) FROM domains WHERE status_id = ?");
        $inUse->execute([$id]);
        if ((int)$inUse->fetchColumn() > 0) {
            throw new ServiceError('conflict', 'in_use', 'Domains still use this status. Move them to another status first, or switch this one off instead.');
        }
        $conn->prepare("DELETE FROM domain_statuses WHERE id = ?")->execute([$id]);
    }

    // ======================================================================
    //  Registrar accounts
    // ======================================================================

    public static function saveRegistrarAccount(PDO $conn, ActorContext $ctx, array $in, ?int $tenantId): array
    {
        $name = trim((string)($in['account_name'] ?? ''));
        if ($name === '') throw new ServiceError('validation', 'missing_field', 'Give the account a name.');
        $supplier = self::lookup($conn, 'suppliers', $in['supplier_id'] ?? null, 'registrar');
        $owner = null;
        if (!empty($in['owner_analyst_id'])) { $owner = (int)$in['owner_analyst_id']; self::requireAnalyst($conn, $owner); }
        $url = trim((string)($in['login_url'] ?? ''));
        if ($url !== '' && !preg_match('#^https?://#i', $url)) $url = 'https://' . $url;
        if ($url !== '' && !filter_var($url, FILTER_VALIDATE_URL)) throw new ServiceError('validation', 'invalid_field', 'The login address is not a web address.');
        $vals = [$supplier, mb_substr($name, 0, 150), self::str($in['account_reference'] ?? null, 150), $url ?: null, $owner,
                 self::str($in['two_factor_holder'] ?? null, 255), self::str($in['notes'] ?? null, 5000)];

        $id = (int)($in['id'] ?? 0);
        if ($id > 0) {
            $row = self::loadAccountForActor($conn, $ctx, $id);
            $conn->prepare("UPDATE domain_registrar_accounts SET supplier_id = ?, account_name = ?, account_reference = ?, login_url = ?,
                            owner_analyst_id = ?, two_factor_holder = ?, notes = ? WHERE id = ?")->execute([...$vals, (int)$row['id']]);
            return ['id' => $id, 'created' => false];
        }
        $store = self::storeTenant($conn, $tenantId);
        if ($ctx->companyScope !== null && !in_array($store ?? getDefaultTenantId($conn), $ctx->companyScope, true)) {
            throw new ServiceError('forbidden', 'forbidden', 'You cannot add accounts for that company.');
        }
        $conn->prepare("INSERT INTO domain_registrar_accounts (supplier_id, account_name, account_reference, login_url, owner_analyst_id,
                        two_factor_holder, notes, tenant_id, created_by, created_datetime) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())")
             ->execute([...$vals, $store, $ctx->actorId > 0 ? $ctx->actorId : null]);
        return ['id' => (int)$conn->lastInsertId(), 'created' => true];
    }

    public static function deleteRegistrarAccount(PDO $conn, ActorContext $ctx, int $id): void
    {
        self::loadAccountForActor($conn, $ctx, $id);
        $conn->prepare("UPDATE domains SET registrar_account_id = NULL WHERE registrar_account_id = ?")->execute([$id]);
        $conn->prepare("DELETE FROM domain_registrar_accounts WHERE id = ?")->execute([$id]);
    }

    // ======================================================================
    //  Loading + scope
    // ======================================================================

    public static function loadRow(PDO $conn, int $id): array
    {
        $st = $conn->prepare("SELECT * FROM domains WHERE id = ?");
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new ServiceError('not_found', 'not_found', 'Domain not found.');
        return $row;
    }

    /** Load a domain the caller may touch — out of scope reads as not found. */
    public static function loadForActor(PDO $conn, ActorContext $ctx, int $id): array
    {
        $row = self::loadRow($conn, $id);
        self::assertScope($conn, $ctx, $row);
        return $row;
    }

    private static function loadAccountForActor(PDO $conn, ActorContext $ctx, int $id): array
    {
        $st = $conn->prepare("SELECT * FROM domain_registrar_accounts WHERE id = ?");
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new ServiceError('not_found', 'not_found', 'Registrar account not found.');
        self::assertScope($conn, $ctx, $row);
        return $row;
    }

    private static function assertScope(PDO $conn, ActorContext $ctx, array $row): void
    {
        if ($ctx->companyScope === null || !isMultiTenant($conn)) return;
        $tid = ($row['tenant_id'] === null) ? getDefaultTenantId($conn) : (int)$row['tenant_id'];
        if (!in_array($tid, $ctx->companyScope, true)) {
            throw new ServiceError('not_found', 'not_found', 'Domain not found.');
        }
    }

    /** The Default company is stored as NULL, the way every scoped table does. */
    private static function storeTenant(PDO $conn, ?int $tenantId): ?int
    {
        if ($tenantId === null || $tenantId <= 0) return null;
        return $tenantId === getDefaultTenantId($conn) ? null : $tenantId;
    }

    // ======================================================================
    //  Validation
    // ======================================================================

    private static function validateField(PDO $conn, string $field, $v, array $def, array $row, string $domainName)
    {
        $blank = $v === null || (is_string($v) && trim($v) === '');
        switch ($def['type']) {
            case 'lookup':
                return self::lookup($conn, $def['table'], $v, str_replace('_', ' ', preg_replace('/_id$/', '', $field)));
            case 'account':
                if ($blank) return null;
                $st = $conn->prepare("SELECT tenant_id FROM domain_registrar_accounts WHERE id = ?");
                $st->execute([(int)$v]);
                $acc = $st->fetch(PDO::FETCH_ASSOC);
                if (!$acc) throw new ServiceError('validation', 'invalid_field', "Unknown registrar account id: " . (int)$v);
                // An account belongs to one company; a domain may only use its own
                // company's accounts — otherwise the link itself leaks.
                if (isMultiTenant($conn) && (($acc['tenant_id'] ?? null) <=> ($row['tenant_id'] ?? null)) !== 0) {
                    throw new ServiceError('validation', 'invalid_field', 'That registrar account belongs to a different company.');
                }
                return (int)$v;
            case 'analyst':
                if ($blank) return null;
                self::requireAnalyst($conn, (int)$v);
                return (int)$v;
            case 'enum':
                $s = strtolower(trim((string)$v));
                if (!in_array($s, $def['values'], true)) {
                    throw new ServiceError('validation', 'invalid_field', "'$field' must be one of: " . implode(', ', $def['values']) . '.');
                }
                return $s;
            case 'date':
                if ($blank) return null;
                $d = DateTimeImmutable::createFromFormat('Y-m-d', (string)$v);
                if (!$d || $d->format('Y-m-d') !== (string)$v) throw new ServiceError('validation', 'invalid_field', "'$field' must be a date in YYYY-MM-DD format.");
                return (string)$v;
            case 'tribool':
                if ($blank) return null;
                return in_array($v, [1, '1', true, 'true', 'yes', 'on'], true) ? 1 : 0;
            case 'bool':
                return in_array($v, [1, '1', true, 'true', 'yes', 'on'], true) ? 1 : 0;
            case 'string':
                if ($blank) return null;
                $s = trim((string)$v);
                if (mb_strlen($s) > $def['max']) throw new ServiceError('validation', 'invalid_field', "'$field' can be at most {$def['max']} characters.");
                return $s;
            case 'text':
                if ($blank) return null;
                return mb_substr(trim((string)$v), 0, 20000);
            case 'lines':
                if ($blank) return null;
                $lines = array_values(array_unique(array_filter(array_map(fn($x) => strtolower(rtrim(trim($x), '.')), preg_split('/[\r\n,;\s]+/', (string)$v)))));
                sort($lines);
                return $lines ? implode("\n", $lines) : null;
            case 'ssl_hosts':
                if ($blank) return null;
                $hosts = domainParseSslHosts((string)$v, $domainName);
                return $hosts ? mb_substr(implode(', ', $hosts), 0, 500) : null;
            case 'money':
                if ($blank) return null;
                if (!is_numeric($v) || (float)$v < 0) throw new ServiceError('validation', 'invalid_field', "'$field' must be a number.");
                return (string)round((float)$v, 2);
            case 'currency':
                if ($blank) return null;
                $c = strtoupper(trim((string)$v));
                if (!preg_match('/^[A-Z]{3}$/', $c)) throw new ServiceError('validation', 'invalid_field', "'currency' must be a 3-letter code, e.g. GBP.");
                return $c;
            case 'int':
                if ($blank) return $def['min'];
                if (!is_numeric($v) || (int)$v < $def['min'] || (int)$v > $def['max']) {
                    throw new ServiceError('validation', 'invalid_field', "'$field' must be a whole number from {$def['min']} to {$def['max']}.");
                }
                return (int)$v;
            case 'tags':
                if ($blank) return null;
                $tags = [];
                foreach (preg_split('/[,;]+/', (string)$v) as $t) {
                    $t = trim($t);
                    if ($t !== '') $tags[mb_strtolower($t)] ??= $t;   // the first spelling typed wins
                }
                return $tags ? mb_substr(implode(', ', array_values($tags)), 0, 500) : null;
        }
        return $v;
    }

    private static function lookup(PDO $conn, string $table, $value, string $label): ?int
    {
        if ($value === null || $value === '' || $value === 0 || $value === '0') return null;
        $id = (int)$value;
        $st = $conn->prepare("SELECT id FROM `$table` WHERE id = ?");
        $st->execute([$id]);
        if (!$st->fetchColumn()) throw new ServiceError('validation', 'invalid_field', "Unknown {$label} id: {$id}");
        return $id;
    }

    private static function requireAnalyst(PDO $conn, int $id): void
    {
        $st = $conn->prepare("SELECT id FROM analysts WHERE id = ? AND is_active = 1");
        $st->execute([$id]);
        if (!$st->fetchColumn()) throw new ServiceError('validation', 'invalid_field', "Unknown or inactive analyst id: {$id}");
    }

    private static function str($v, int $max): ?string
    {
        $s = trim((string)$v);
        return $s === '' ? null : mb_substr($s, 0, $max);
    }

    private static function same($a, $b): bool
    {
        if (($a === null || $a === '') && ($b === null || $b === '')) return true;
        return (string)$a === (string)$b;
    }

    /** A supplier whose legal or trading name matches the registry's registrar name. */
    private static function matchSupplier(PDO $conn, string $registrar): ?int
    {
        $clean = fn($s) => trim(preg_replace('/[,.]|\b(inc|ltd|limited|llc|gmbh|plc|corp|corporation|s\.?a\.?|b\.?v\.?)\b/i', '', mb_strtolower($s)));
        $want = $clean($registrar);
        if ($want === '') return null;
        try {
            $rows = $conn->query("SELECT id, legal_name, trading_name FROM suppliers WHERE is_active = 1")->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) { return null; }
        foreach ($rows as $r) {
            foreach ([$r['legal_name'], $r['trading_name']] as $n) {
                if ($n && $clean($n) === $want) return (int)$r['id'];
            }
        }
        return null;
    }

    // ======================================================================
    //  History, events, side effects
    // ======================================================================

    public static function audit(PDO $conn, int $domainId, ?int $analystId, string $field, $old, $new, string $source = 'app'): void
    {
        try {
            $conn->prepare("INSERT INTO domain_audit (domain_id, analyst_id, field_name, old_value, new_value, source, created_datetime)
                            VALUES (?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())")
                 ->execute([$domainId, ($analystId && $analystId > 0) ? $analystId : null, $field,
                            $old === null ? null : mb_substr((string)$old, 0, 1000),
                            $new === null ? null : mb_substr((string)$new, 0, 1000), $source]);
        } catch (Throwable $e) {
            error_log('domains audit: ' . $e->getMessage());
        }
    }

    /** Ids stored as names, so the history reads as English a year later. */
    private static function auditDisplay(PDO $conn, string $field, $v): ?string
    {
        if ($v === null || $v === '') return null;
        $q = [
            'status_id'             => "SELECT name FROM domain_statuses WHERE id = ?",
            'registrar_supplier_id' => "SELECT COALESCE(NULLIF(trading_name, ''), legal_name) FROM suppliers WHERE id = ?",
            'registrar_account_id'  => "SELECT account_name FROM domain_registrar_accounts WHERE id = ?",
            'owner_analyst_id'      => "SELECT full_name FROM analysts WHERE id = ?",
            'tech_contact_id'       => "SELECT CONCAT(first_name, ' ', surname) FROM contacts WHERE id = ?",
            'contract_id'           => "SELECT CONCAT(contract_number, ' ', title) FROM contracts WHERE id = ?",
        ][$field] ?? null;
        if ($q) {
            try { $st = $conn->prepare($q); $st->execute([(int)$v]); $n = $st->fetchColumn(); if ($n) return (string)$n; }
            catch (Throwable $e) {}
        }
        if (in_array($field, ['transfer_lock', 'registry_lock', 'dnssec', 'monitoring_enabled'], true)) return (int)$v ? 'Yes' : 'No';
        return (string)$v;
    }

    /** The payload every domain.* event carries. */
    public static function eventPayload(array $row): array
    {
        return [
            'id'               => (int)$row['id'],
            'name'             => $row['domain_name'],
            'expiry_date'      => $row['expiry_date'],
            'status_id'        => $row['status_id'] !== null ? (int)$row['status_id'] : null,
            'purpose'          => $row['purpose'],
            'owner_analyst_id' => $row['owner_analyst_id'] !== null ? (int)$row['owner_analyst_id'] : null,
            'registrar'        => $row['registrar_name'],
            'company_id'       => $row['tenant_id'] !== null ? (int)$row['tenant_id'] : null,
            'security_grade'   => $row['security_grade'],
        ];
    }

    private static function dispatchCrud(PDO $conn, string $verb, int $id): void
    {
        try {
            $row = self::loadRow($conn, $id);
            WorkflowEngine::dispatch('domain.' . $verb, ['domain' => self::eventPayload($row)]);
        } catch (Throwable $e) {
            error_log('domains dispatch: ' . $e->getMessage());
        }
    }

    /** Expiry dates feed the calendar; keep it in step. Never fails the write. */
    private static function afterDateChange(PDO $conn): void
    {
        try {
            require_once __DIR__ . '/../domains/calendar.php';
            domainSyncExpiryCalendar($conn);
        } catch (Throwable $e) {
            error_log('domains calendar sync: ' . $e->getMessage());
        }
    }
}
