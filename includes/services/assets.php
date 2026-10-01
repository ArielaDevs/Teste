<?php
/**
 * AssetsService — the shared write rules for assets: create, per-field update
 * (with audit trail + warranty-calendar sync), and user assignment / removal
 * (with the custody trail).
 *
 * Shared by the UI endpoints (api/assets/update_asset_field.php,
 * assign_asset_user.php, unassign_asset_user.php) and the REST API
 * (api/v1/resources/assets.php). Each adapter distils its caller into an
 * ActorContext + canonical input; this layer validates + writes and returns the
 * affected id(s) / a small result array, or throws ServiceError. It never emits
 * HTTP.
 *
 * Canonical behaviour = the API resource's, so the API stays byte-identical
 * while the UI's looser writes converge to it:
 *   - an unknown asset id is a not_found (the UI used to UPDATE 0 rows yet still
 *     write a history entry for a ghost);
 *   - lookup ids / dates are validated (422) rather than written blindly;
 *   - a no-op field write records NO history row (the UI logged one every time);
 *   - assignments require the requester to exist (422).
 *
 * Two UI-only behaviours are preserved as optional input, defaulting to the
 * API's behaviour so the API bytes don't move:
 *   - assignUser() accepts `previous_user_id` — on a re-assign the UI records the
 *     outgoing holder as the audit's old_value (the API always logs null);
 *   - unassignUser() accepts $skipAudit — the UI suppresses the intermediate
 *     history row when a re-assign removes the previous holder before adding the
 *     new one (the assign call then logs the "A -> B" transition).
 *
 * Assets are install-wide (no tenant_id), so companyScope is not consulted.
 */

require_once __DIR__ . '/../service_context.php';
require_once __DIR__ . '/../tenancy.php';
require_once __DIR__ . '/../users.php';            // userDirectoryOwnedFields()
require_once dirname(__DIR__, 2) . '/workflow/includes/engine.php';

class AssetsService
{
    /**
     * Multi-tenancy gate: refuse to touch an asset outside the actor's companies.
     * companyScope null = all companies (single-company install or an all-access
     * actor) → no gate. Framed as not-found so it never reveals another company's
     * asset. $row must include tenant_id.
     */
    private static function assertScope(PDO $conn, ActorContext $ctx, array $row): void
    {
        if ($ctx->companyScope === null) {
            return;
        }
        $tid = ($row['tenant_id'] === null) ? getDefaultTenantId($conn) : (int)$row['tenant_id'];
        if (!in_array($tid, $ctx->companyScope, true)) {
            throw new ServiceError('not_found', 'not_found', 'Asset not found.');
        }
    }

    /**
     * The editable columns, their audit field keys (the SAME stable keys the UI
     * history view localises via t('asset-management.field.<key>')), and how to
     * validate/resolve each. 'lookup'/'supplier' fields audit display NAMES.
     */
    public static function fieldMap(): array
    {
        return [
            'asset_type_id'    => ['audit' => 'type',            'kind' => 'lookup', 'table' => 'asset_types',        'label' => 'asset type'],
            'asset_status_id'  => ['audit' => 'status',          'kind' => 'lookup', 'table' => 'asset_status_types', 'label' => 'asset status'],
            'location_id'      => ['audit' => 'location',        'kind' => 'lookup', 'table' => 'asset_locations',    'label' => 'location'],
            'supplier_id'      => ['audit' => 'supplier',        'kind' => 'supplier'],
            'purchase_date'    => ['audit' => 'purchase_date',   'kind' => 'date'],
            'purchase_cost'    => ['audit' => 'purchase_cost',   'kind' => 'decimal'],
            'order_number'     => ['audit' => 'order_number',    'kind' => 'string', 'max' => 100],
            'warranty_expiry'  => ['audit' => 'warranty_expiry', 'kind' => 'date'],
            'lease_expiry'     => ['audit' => 'lease_expiry',    'kind' => 'date'],
            'hostname'         => ['audit' => 'hostname',         'kind' => 'string', 'max' => 50],
            'manufacturer'     => ['audit' => 'manufacturer',     'kind' => 'string', 'max' => 50],
            'model'            => ['audit' => 'model',            'kind' => 'string', 'max' => 50],
            'service_tag'      => ['audit' => 'service_tag',      'kind' => 'string', 'max' => 50],
            'memory'           => ['audit' => 'memory',           'kind' => 'int'],
            'operating_system' => ['audit' => 'operating_system', 'kind' => 'string', 'max' => 50],
            'feature_release'  => ['audit' => 'feature_release',  'kind' => 'string', 'max' => 10],
            'build_number'     => ['audit' => 'build_number',     'kind' => 'string', 'max' => 50],
            'cpu_name'         => ['audit' => 'cpu_name',         'kind' => 'string', 'max' => 250],
            'speed'            => ['audit' => 'speed',            'kind' => 'int'],
            'bios_version'     => ['audit' => 'bios_version',     'kind' => 'string', 'max' => 20],
            'gpu_name'         => ['audit' => 'gpu_name',         'kind' => 'string', 'max' => 250],
            'tpm_version'      => ['audit' => 'tpm_version',      'kind' => 'string', 'max' => 50],
            'bitlocker_status' => ['audit' => 'bitlocker_status', 'kind' => 'string', 'max' => 20],
            'domain'           => ['audit' => 'domain',           'kind' => 'string', 'max' => 100],
            'logged_in_user'   => ['audit' => 'logged_in_user',   'kind' => 'string', 'max' => 100],
        ];
    }

    // ======================================================================
    //  Writes
    // ======================================================================

    /**
     * Create an asset (identified by its unique hostname). Returns the new id.
     * $creationNote is the audit new_value for the 'asset_created' row (the API
     * records the acting key, the UI records the analyst — see
     * api/assets/create_asset.php, added in #1132 because a television cannot
     * run the inventory agent).
     */
    public static function createAsset(PDO $conn, ActorContext $ctx, array $in, string $creationNote, ?int $tenantId = null): int
    {
        $hostname = trim((string)($in['hostname'] ?? ''));
        if ($hostname === '') {
            throw new ServiceError('validation', 'missing_field', "'hostname' is required.");
        }
        if (mb_strlen($hostname) > 50) {
            throw new ServiceError('validation', 'invalid_field', "'hostname' must be at most 50 characters.");
        }

        // Multi-tenancy: normalise the Default company to NULL so API-created and
        // agent-created assets store the same thing (every read treats NULL as the
        // Default company).
        $storeTenant = ($tenantId !== null && $tenantId === getDefaultTenantId($conn)) ? null : $tenantId;

        // hostname is the identity every ingest path upserts on — a duplicate
        // would split an asset's records, so refuse rather than silently fork.
        // Scoped to the target company (NULL-safe) so two companies may each hold
        // a "LAPTOP-01".
        $dup = $conn->prepare("SELECT id FROM assets WHERE hostname = ? AND tenant_id <=> ?");
        $dup->execute([$hostname, $storeTenant]);
        $existingId = $dup->fetchColumn();
        if ($existingId !== false) {
            throw new ServiceError('conflict', 'conflict', "An asset with this hostname already exists (id {$existingId}). Use PATCH /assets/{$existingId} to update it.");
        }

        $map = self::fieldMap();
        unset($map['hostname']); // handled above
        $columns = ['hostname'];
        $values  = [$hostname];
        foreach ($map as $field => $def) {
            if (!array_key_exists($field, $in)) {
                continue;
            }
            $columns[] = $field;
            $values[]  = self::validateField($conn, $field, $in[$field], $def);
        }
        $columns[] = 'tenant_id';
        $values[]  = $storeTenant;

        // Asset tag auto-generation or manual assignment.
        // Left blank + enabled -> mint sequentially; explicitly provided -> validate uniqueness.
        require_once __DIR__ . '/asset_tags.php';
        $assignedTag = null;
        if (array_key_exists('asset_tag', $in) && trim((string)$in['asset_tag']) !== '') {
            $manualTag = trim((string)$in['asset_tag']);
            if (mb_strlen($manualTag) > 64) {
                throw new ServiceError('validation', 'invalid_field', "'asset_tag' must be at most 64 characters.");
            }
            require_once __DIR__ . '/../asset_labels.php';
            if (!assetTagAvailable($conn, $storeTenant, $manualTag)) {
                throw new ServiceError('conflict', 'conflict', "Asset tag '{$manualTag}' is already in use by another asset in this company.");
            }
            $assignedTag = $manualTag;
        } elseif (AssetTagsService::isAutogenEnabled($conn, $storeTenant)) {
            $assignedTag = AssetTagsService::generateNextAssetTag($conn, $storeTenant);
        }

        if ($assignedTag !== null) {
            $columns[] = 'asset_tag';
            $values[]  = $assignedTag;
        }

        // 🔑 first_seen ONLY. `last_seen` means "when did an agent last report
        // this machine", and nothing has ever reported a television, a SIM card
        // or a meeting-room monitor — the very things this path exists to add.
        //
        // It used to stamp both, which was invisible while last_seen was shown
        // nowhere. Now that the asset screen and the asset table both show it
        // (#1578), a hand-added television would read "21 days ago" in amber, as
        // though it had stopped reporting, and would sit in the Watchtower "not
        // seen" count alongside machines that genuinely have. NULL is what makes
        // the screen able to say **Never reported** instead, and it is the
        // truthful answer rather than a convenient one.
        //
        // first_seen stays: when the record was made is a real fact about it.
        $placeholders = implode(', ', array_fill(0, count($columns), '?'));
        $sql = "INSERT INTO assets (" . implode(', ', $columns) . ", first_seen)
                VALUES ($placeholders, UTC_TIMESTAMP())";
        $conn->prepare($sql)->execute($values);
        $assetId = (int)$conn->lastInsertId();

        self::auditWrite($conn, $assetId, $ctx->actorId, 'asset_created', null, $creationNote);

        if ((array_key_exists('warranty_expiry', $in) && $in['warranty_expiry'])
            || (array_key_exists('lease_expiry', $in) && $in['lease_expiry'])) {
            self::syncWarranty($conn);
        }
        return $assetId;
    }

    /**
     * Apply a partial set of field updates to an asset. Writes one audit row per
     * changed field (no-ops are skipped) and re-syncs the warranty calendar when
     * warranty_expiry moves. Returns void; the adapter reloads for its response.
     */
    public static function updateFields(PDO $conn, ActorContext $ctx, int $assetId, array $in): void
    {
        $current = self::loadRow($conn, $assetId);   // 404 if gone
        self::assertScope($conn, $ctx, $current);    // 404 if in another company
        if (!$in) {
            throw new ServiceError('validation', 'missing_field', 'No fields to update.');
        }

        $map = self::fieldMap();
        $updates = [];
        $args    = [];
        $audits  = [];   // [fieldKey, oldDisplay, newDisplay]
        $warrantyChanged = false;

        foreach ($in as $field => $rawValue) {
            if (!isset($map[$field])) {
                continue; // unknown fields ignored, like the internal endpoints
            }
            $def = $map[$field];
            $newValue = self::validateField($conn, $field, $rawValue, $def);

            if ($field === 'hostname') {
                if ($newValue === null) {
                    throw new ServiceError('validation', 'invalid_field', "'hostname' cannot be blank.");
                }
                // Scoped to this asset's own company (NULL-safe) — a matching
                // hostname in another company is not a clash.
                $dup = $conn->prepare("SELECT id FROM assets WHERE hostname = ? AND id != ? AND tenant_id <=> ?");
                $dup->execute([$newValue, $assetId, $current['tenant_id']]);
                if ($dup->fetchColumn()) {
                    throw new ServiceError('conflict', 'conflict', 'Another asset already uses this hostname.');
                }
            }

            // Normalise the current value the same way for change detection.
            $oldValue = $current[$field];
            if (in_array($def['kind'], ['lookup', 'supplier', 'int'], true) && $oldValue !== null) {
                $oldValue = (int)$oldValue;
            }
            $comparableNew = ($def['kind'] === 'decimal' && $newValue !== null) ? (float)$newValue : $newValue;
            $comparableOld = ($def['kind'] === 'decimal' && $oldValue !== null) ? (float)$oldValue : $oldValue;
            if ($comparableNew === $comparableOld || (string)$comparableNew === (string)$comparableOld && $comparableNew !== null && $comparableOld !== null) {
                continue; // no actual change
            }

            $updates[] = "$field = ?";
            $args[]    = $newValue;
            $audits[]  = [
                $def['audit'],
                self::auditDisplay($conn, $field, $oldValue, $def),
                self::auditDisplay($conn, $field, $newValue, $def),
            ];
            // Either date drives the same calendar pass, so either one changing
            // is a reason to run it.
            if ($field === 'warranty_expiry' || $field === 'lease_expiry') {
                $warrantyChanged = true;
            }
        }

        if (!$updates) {
            return; // idempotent — nothing to write
        }

        $args[] = $assetId;
        $conn->prepare('UPDATE assets SET ' . implode(', ', $updates) . ' WHERE id = ?')->execute($args);

        foreach ($audits as [$fieldKey, $old, $new]) {
            self::auditWrite($conn, $assetId, $ctx->actorId, $fieldKey, $old, $new);
        }

        if ($warrantyChanged) {
            self::syncWarranty($conn);
        }
    }

    /**
     * Assign an ANALYST to an asset — the desk's own kit.
     *
     * 🔑 Why this exists at all. Assets could only be held by a REQUESTER, and
     * analysts are not requesters: on a real install five of seven analysts had
     * no `users` row, so most of the desk could not be recorded as holding
     * anything. Asked for in the 2.5.0 request list.
     *
     * 🔴 EXACTLY ONE HOLDER COLUMN IS SET. A row names a requester or an
     * analyst, never both. The database cannot express that (a CHECK across two
     * columns is not portable to every MySQL version supported here), so it is
     * enforced here and nowhere else — which is precisely why assignment must
     * go through this service rather than an INSERT somewhere convenient.
     *
     * $in: analyst_id | analyst_email, plus optional notes,
     * expected_return_date, previous_analyst_id.
     */
    public static function assignAnalyst(PDO $conn, ActorContext $ctx, int $assetId, array $in): array
    {
        self::loadRow($conn, $assetId);   // 404 if gone
        $actorId = $ctx->actorId;

        if (isset($in['analyst_id']) && $in['analyst_id'] !== '') {
            $a = $conn->prepare("SELECT id, full_name FROM analysts WHERE id = ? AND is_active = 1");
            $a->execute([(int)$in['analyst_id']]);
        } elseif (isset($in['analyst_email']) && trim((string)$in['analyst_email']) !== '') {
            $a = $conn->prepare("SELECT id, full_name FROM analysts WHERE email = ? AND is_active = 1");
            $a->execute([strtolower(trim((string)$in['analyst_email']))]);
        } else {
            throw new ServiceError('validation', 'missing_field', "Provide 'analyst_id' or 'analyst_email'.");
        }
        $row = $a->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            // Inactive is named separately from unknown: "there is no such
            // analyst" sends somebody looking for a typo that is not there.
            throw new ServiceError('validation', 'invalid_field', 'Unknown or inactive analyst.');
        }
        $analystId   = (int)$row['id'];
        $analystName = $row['full_name'];

        $notes = trim((string)($in['notes'] ?? '')) ?: null;
        $expectedReturn = self::parseDate($in['expected_return_date'] ?? null, 'expected_return_date');

        $check = $conn->prepare("SELECT id FROM users_assets WHERE asset_id = ? AND analyst_id = ?");
        $check->execute([$assetId, $analystId]);
        if ($check->fetchColumn()) {
            throw new ServiceError('conflict', 'conflict', 'This analyst is already assigned to this asset.');
        }

        // user_id is left NULL. That column was NOT NULL until this feature; see
        // api/system/db_verify.php, which relaxes it on an existing install.
        $conn->prepare(
            "INSERT INTO users_assets (asset_id, analyst_id, assigned_by_analyst_id, notes, expected_return_date, assigned_datetime)
             VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP())"
        )->execute([$assetId, $analystId, $actorId, $notes, $expectedReturn]);

        // Custody trail, best-effort as elsewhere. asset_checkout_log.user_id
        // names a requester, so an analyst handover records the NAME and leaves
        // the id NULL rather than writing an analyst id into a requester column.
        try {
            $conn->prepare(
                "INSERT INTO asset_checkout_log (asset_id, user_id, user_name, action, expected_return_date, analyst_id, notes, action_datetime)
                 VALUES (?, NULL, ?, 'checkout', ?, ?, ?, UTC_TIMESTAMP())"
            )->execute([$assetId, $analystName, $expectedReturn, $actorId, $notes]);
        } catch (Exception $clogEx) { /* custody log not critical */ }

        $oldName = null;
        if (!empty($in['previous_analyst_id'])) {
            $prev = $conn->prepare("SELECT full_name FROM analysts WHERE id = ?");
            $prev->execute([(int)$in['previous_analyst_id']]);
            $prevRow = $prev->fetch(PDO::FETCH_ASSOC);
            $oldName = $prevRow ? $prevRow['full_name'] : (string)$in['previous_analyst_id'];
        }
        self::auditWrite($conn, $assetId, $actorId, 'assigned_analyst', $oldName, $analystName);

        self::dispatch('asset.assigned', $conn, $assetId, 0, $analystName);

        return [
            'asset_id'             => $assetId,
            'analyst_id'           => $analystId,
            'name'                 => $analystName,
            'expected_return_date' => $expectedReturn,
            'notes'                => $notes,
        ];
    }

    /**
     * Assign a requester to an asset. $in: user_id | user_email, plus optional
     * notes, expected_return_date, previous_user_id (UI re-assign old_value).
     * Returns [asset_id, user_id, name, expected_return_date, notes].
     */
    public static function assignUser(PDO $conn, ActorContext $ctx, int $assetId, array $in): array
    {
        self::loadRow($conn, $assetId);   // 404 if gone
        $actorId = $ctx->actorId;

        // Accept user_id or user_email (must be an existing requester).
        if (isset($in['user_id']) && $in['user_id'] !== '') {
            $u = $conn->prepare("SELECT id, display_name FROM users WHERE id = ?");
            $u->execute([(int)$in['user_id']]);
        } elseif (isset($in['user_email']) && trim((string)$in['user_email']) !== '') {
            $u = $conn->prepare("SELECT id, display_name FROM users WHERE email = ?");
            $u->execute([strtolower(trim((string)$in['user_email']))]);
        } else {
            throw new ServiceError('validation', 'missing_field', "Provide 'user_id' or 'user_email'.");
        }
        $userRow = $u->fetch(PDO::FETCH_ASSOC);
        if (!$userRow) {
            throw new ServiceError('validation', 'invalid_field', 'Unknown requester. Create them first with POST /users.');
        }
        $userId   = (int)$userRow['id'];
        $userName = $userRow['display_name'];

        $notes = trim((string)($in['notes'] ?? '')) ?: null;
        $expectedReturn = self::parseDate($in['expected_return_date'] ?? null, 'expected_return_date');

        $check = $conn->prepare("SELECT id FROM users_assets WHERE asset_id = ? AND user_id = ?");
        $check->execute([$assetId, $userId]);
        if ($check->fetchColumn()) {
            throw new ServiceError('conflict', 'conflict', 'This user is already assigned to this asset.');
        }

        $conn->prepare(
            "INSERT INTO users_assets (asset_id, user_id, assigned_by_analyst_id, notes, expected_return_date, assigned_datetime)
             VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP())"
        )->execute([$assetId, $userId, $actorId, $notes, $expectedReturn]);

        // Custody trail (best-effort, like the UI).
        try {
            $conn->prepare(
                "INSERT INTO asset_checkout_log (asset_id, user_id, user_name, action, expected_return_date, analyst_id, notes, action_datetime)
                 VALUES (?, ?, ?, 'checkout', ?, ?, ?, UTC_TIMESTAMP())"
            )->execute([$assetId, $userId, $userName, $expectedReturn, $actorId, $notes]);
        } catch (Exception $clogEx) { /* custody log not critical */ }

        // On a UI re-assign the outgoing holder is the audit's old_value.
        $oldName = null;
        if (!empty($in['previous_user_id'])) {
            $prev = $conn->prepare("SELECT display_name FROM users WHERE id = ?");
            $prev->execute([(int)$in['previous_user_id']]);
            $prevRow = $prev->fetch(PDO::FETCH_ASSOC);
            $oldName = $prevRow ? $prevRow['display_name'] : (string)$in['previous_user_id'];
        }
        self::auditWrite($conn, $assetId, $actorId, 'assigned_user', $oldName, $userName);

        self::dispatch('asset.assigned', $conn, $assetId, $userId, $userName);

        return [
            'asset_id'             => $assetId,
            'user_id'              => $userId,
            'name'                 => $userName,
            'expected_return_date' => $expectedReturn,
            'notes'                => $notes,
        ];
    }

    /**
     * Remove a requester from an asset. $skipAudit suppresses the history row
     * (the UI's re-assign removes the previous holder silently, then the assign
     * logs the transition). Returns [asset_id, user_id].
     */
    public static function unassignUser(PDO $conn, ActorContext $ctx, int $assetId, int $userId, bool $skipAudit = false): array
    {
        self::loadRow($conn, $assetId);   // 404 if gone
        $actorId = $ctx->actorId;

        // Snapshot holder + due-back before removal, for the custody trail + audit.
        // Filtered on user_id, so this only ever sees a requester assignment —
        // an analyst one is removed by unassignAnalyst() below.
        $snap = $conn->prepare(
            "SELECT u.display_name, ua.expected_return_date
             FROM users_assets ua INNER JOIN users u ON u.id = ua.user_id
             WHERE ua.asset_id = ? AND ua.user_id = ?"
        );
        $snap->execute([$assetId, $userId]);
        $row = $snap->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new ServiceError('not_found', 'not_found', 'Assignment not found.');
        }

        $conn->prepare("DELETE FROM users_assets WHERE asset_id = ? AND user_id = ?")->execute([$assetId, $userId]);

        try {
            $conn->prepare(
                "INSERT INTO asset_checkout_log (asset_id, user_id, user_name, action, expected_return_date, analyst_id, action_datetime)
                 VALUES (?, ?, ?, 'checkin', ?, ?, UTC_TIMESTAMP())"
            )->execute([$assetId, $userId, $row['display_name'], $row['expected_return_date'], $actorId]);
        } catch (Exception $clogEx) { /* custody log not critical */ }

        if (!$skipAudit) {
            self::auditWrite($conn, $assetId, $actorId, 'assigned_user', $row['display_name'], null);
        }

        self::dispatch('asset.unassigned', $conn, $assetId, $userId, $row['display_name']);

        return ['asset_id' => $assetId, 'user_id' => $userId];
    }

    /**
     * Remove an analyst from an asset — the mirror of unassignUser().
     *
     * Separate for the same reason assignAnalyst() is: it matches on a different
     * column, and a shared function would be an `if` at every line.
     */
    public static function unassignAnalyst(PDO $conn, ActorContext $ctx, int $assetId, int $analystId, bool $skipAudit = false): array
    {
        self::loadRow($conn, $assetId);   // 404 if gone
        $actorId = $ctx->actorId;

        $snap = $conn->prepare(
            "SELECT a.full_name, ua.expected_return_date
             FROM users_assets ua INNER JOIN analysts a ON a.id = ua.analyst_id
             WHERE ua.asset_id = ? AND ua.analyst_id = ?"
        );
        $snap->execute([$assetId, $analystId]);
        $row = $snap->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new ServiceError('not_found', 'not_found', 'Assignment not found.');
        }

        $conn->prepare("DELETE FROM users_assets WHERE asset_id = ? AND analyst_id = ?")->execute([$assetId, $analystId]);

        // As in assignAnalyst: the custody log's user_id names a REQUESTER, so an
        // analyst handover records the name and leaves the id NULL rather than
        // writing an analyst id into a requester column, where it would later be
        // read back as whichever requester happened to share that number.
        try {
            $conn->prepare(
                "INSERT INTO asset_checkout_log (asset_id, user_id, user_name, action, expected_return_date, analyst_id, action_datetime)
                 VALUES (?, NULL, ?, 'checkin', ?, ?, UTC_TIMESTAMP())"
            )->execute([$assetId, $row['full_name'], $row['expected_return_date'], $actorId]);
        } catch (Exception $clogEx) { /* custody log not critical */ }

        if (!$skipAudit) {
            self::auditWrite($conn, $assetId, $actorId, 'assigned_analyst', $row['full_name'], null);
        }

        self::dispatch('asset.unassigned', $conn, $assetId, 0, $row['full_name']);

        return ['asset_id' => $assetId, 'analyst_id' => $analystId];
    }

    /** Fire an asset.* workflow event (best-effort; the engine swallows its own errors). */
    private static function dispatch(string $event, PDO $conn, int $assetId, int $userId, ?string $userName): void
    {
        try {
            $hostname = $conn->query("SELECT hostname FROM assets WHERE id = " . (int)$assetId)->fetchColumn();
            WorkflowEngine::dispatch($event, [
                'asset' => ['id' => $assetId, 'hostname' => $hostname !== false ? $hostname : null],
                'user'  => ['id' => $userId, 'name' => $userName],
            ]);
        } catch (Exception $wfEx) {
            error_log('Workflow dispatch error in asset service (' . $event . '): ' . $wfEx->getMessage());
        }
    }

    /**
     * Move an asset to another company (2.6.0, from a customer's request list).
     *
     * The asset twin of TasksService::moveTaskToCompany, gated the same way: the
     * actor must reach the asset where it is AND the company it is going to,
     * checked against BOTH companyScope (what this caller may touch) and
     * analystCanAccessTenant (what the person may reach).
     *
     * An asset carries more company-owned things than a task, and they split
     * into two kinds on purpose:
     *
     *  CLEARED, and reported: its LOCATION, TYPE and STATUS when they are the old
     *  company's own. They simply do not exist in the new company, so keeping them
     *  would leave the asset wearing a value its own company cannot see or pick.
     *  A shared location, and a global type or status, move with it untouched.
     *
     *  REFUSED, with the reason: a HOSTNAME or ASSET TAG the new company already
     *  uses (both are unique per company, and agent ingest matches on hostname),
     *  and a HOLDER who is a person in another company. Those need a decision
     *  from the person, not a guess made on their behalf.
     *
     * An analyst holder moves with it: analysts are not company-scoped.
     *
     * @return array{moved:bool, from:string, to:string, cleared:string[]}
     *         `cleared` lists the audit keys (location/type/status) that were
     *         emptied, so the UI can say what changed.
     */
    public static function moveToCompany(PDO $conn, ActorContext $ctx, int $assetId, int $targetTenantId): array
    {
        require_once __DIR__ . '/../asset_locations.php';

        $row = self::loadRow($conn, $assetId);
        self::assertScope($conn, $ctx, $row);                    // 404 if out of scope

        if (!isMultiTenant($conn)) {
            throw new ServiceError('validation', 'invalid_field',
                'This install has only one company, so there is nowhere to move an asset to.');
        }
        // One message for "no access" and "no such company", so probing ids
        // cannot reveal which companies exist.
        if (($ctx->companyScope !== null && !in_array($targetTenantId, $ctx->companyScope, true))
                || !analystCanAccessTenant($conn, $ctx->actorId, $targetTenantId)) {
            throw new ServiceError('validation', 'invalid_field', 'You do not have access to that company.');
        }
        $target = getTenantById($conn, $targetTenantId);
        if (!$target) {
            throw new ServiceError('validation', 'invalid_field', 'You do not have access to that company.');
        }

        // NULL means the Default company, so resolve it before comparing, or a
        // Default asset "moved" into Default would write a nonsense audit line.
        $defaultId   = getDefaultTenantId($conn);
        $oldTenantId = ($row['tenant_id'] === null) ? $defaultId : (int)$row['tenant_id'];
        if ($oldTenantId === $targetTenantId) {
            return ['moved' => false, 'from' => $target['name'], 'to' => $target['name'], 'cleared' => []];
        }
        $oldName     = getTenantById($conn, $oldTenantId)['name'] ?? 'Unknown';
        $storeTenant = ($targetTenantId === $defaultId) ? null : $targetTenantId;

        // --- refusals --------------------------------------------------------
        $dup = $conn->prepare("SELECT id FROM assets WHERE hostname = ? AND id <> ? AND tenant_id <=> ?");
        $dup->execute([$row['hostname'], $assetId, $storeTenant]);
        if ($dup->fetchColumn() !== false) {
            throw new ServiceError('conflict', 'conflict',
                "{$target['name']} already has an asset called {$row['hostname']}. Rename one of them first.");
        }
        if (!empty($row['asset_tag'])) {
            $tag = $conn->prepare("SELECT id FROM assets WHERE asset_tag = ? AND id <> ? AND tenant_id <=> ?");
            $tag->execute([$row['asset_tag'], $assetId, $storeTenant]);
            if ($tag->fetchColumn() !== false) {
                throw new ServiceError('conflict', 'conflict',
                    "{$target['name']} already uses the asset tag {$row['asset_tag']}. Change one of them first.");
            }
        }
        // A person holding it who is not in the new company. NULL = Default.
        $h = $conn->prepare(
            "SELECT COALESCE(NULLIF(TRIM(u.display_name), ''), u.username) AS name
               FROM users_assets ua JOIN users u ON u.id = ua.user_id
              WHERE ua.asset_id = ? AND COALESCE(u.tenant_id, ?) <> ?"
        );
        $h->execute([$assetId, $defaultId, $targetTenantId]);
        $holders = $h->fetchAll(PDO::FETCH_COLUMN);
        if ($holders) {
            throw new ServiceError('validation', 'invalid_field',
                'This asset is held by ' . implode(', ', $holders) . ", who is not in {$target['name']}. Unassign it first.");
        }

        // --- what does not exist in the new company -------------------------
        $clear = [];   // column => audit key
        if (!empty($row['location_id'])) {
            [$lSql, $lArgs] = assetLocationScope($conn, $targetTenantId, '');
            $lc = $conn->prepare("SELECT id FROM asset_locations WHERE id = ?" . $lSql);
            $lc->execute(array_merge([(int)$row['location_id']], $lArgs));
            if (!$lc->fetchColumn()) { $clear['location_id'] = 'location'; }
        }
        foreach ([['asset_type_id', 'asset_types', 'asset_type', 'type'],
                  ['asset_status_id', 'asset_status_types', 'asset_status_type', 'status']] as [$col, $table, $entity, $key]) {
            if (empty($row[$col])) continue;
            $ids = array_map(fn($r) => (int)$r['id'],
                             getTenantConfigRows($conn, $table, $entity, $targetTenantId, 'id'));
            if (!in_array((int)$row[$col], $ids, true)) { $clear[$col] = $key; }
        }

        $map = self::fieldMap();
        $ownTransaction = !$conn->inTransaction();
        if ($ownTransaction) { $conn->beginTransaction(); }
        try {
            $sets = ['tenant_id = ?'];
            $args = [$storeTenant];
            foreach (array_keys($clear) as $col) { $sets[] = "$col = NULL"; }
            $args[] = $assetId;
            $conn->prepare("UPDATE assets SET " . implode(', ', $sets) . " WHERE id = ?")->execute($args);

            self::auditWrite($conn, $assetId, $ctx->actorId, 'company', $oldName, $target['name']);
            foreach ($clear as $col => $key) {
                self::auditWrite($conn, $assetId, $ctx->actorId, $key,
                                 self::auditDisplay($conn, $col, $row[$col], $map[$col]), null);
            }
            if ($ownTransaction) { $conn->commit(); }
        } catch (Throwable $t) {
            if ($ownTransaction && $conn->inTransaction()) { $conn->rollBack(); }
            throw $t;
        }

        return ['moved' => true, 'from' => $oldName, 'to' => $target['name'], 'cleared' => array_values($clear)];
    }

    // ======================================================================
    //  Internals
    // ======================================================================

    /** Load the base asset row for write guards + change detection; 404 if unknown. */
    private static function loadRow(PDO $conn, int $assetId): array
    {
        $stmt = $conn->prepare("SELECT * FROM assets WHERE id = ?");
        $stmt->execute([$assetId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new ServiceError('not_found', 'not_found', 'Asset not found.');
        }
        return $row;
    }

    private static function auditWrite(PDO $conn, int $assetId, int $analystId, string $fieldKey, ?string $old, ?string $new): void
    {
        $realAnalystId = ($analystId > 0) ? $analystId : null;
        $conn->prepare(
            "INSERT INTO asset_history (asset_id, analyst_id, field_name, old_value, new_value, created_datetime) VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP())"
        )->execute([$assetId, $realAnalystId, $fieldKey, $old, $new]);
    }
    private static function parseDate($value, string $field): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $d = DateTimeImmutable::createFromFormat('Y-m-d', (string)$value);
        if (!$d || $d->format('Y-m-d') !== (string)$value) {
            throw new ServiceError('validation', 'invalid_field', "'{$field}' must be a date in YYYY-MM-DD format.");
        }
        return (string)$value;
    }

    /** Validate one incoming field value per its map entry. Returns the DB-ready value. */
    private static function validateField(PDO $conn, string $field, $value, array $def)
    {
        if ($value === '' || $value === null) {
            return null;
        }
        switch ($def['kind']) {
            case 'lookup':
                $stmt = $conn->prepare("SELECT id FROM {$def['table']} WHERE id = ?");
                $stmt->execute([(int)$value]);
                if (!$stmt->fetchColumn()) {
                    throw new ServiceError('validation', 'invalid_field', "Unknown {$def['label']} id: {$value}");
                }
                return (int)$value;
            case 'supplier':
                $stmt = $conn->prepare("SELECT id FROM suppliers WHERE id = ?");
                $stmt->execute([(int)$value]);
                if (!$stmt->fetchColumn()) {
                    throw new ServiceError('validation', 'invalid_field', "Unknown supplier id: {$value}");
                }
                return (int)$value;
            case 'date':
                return self::parseDate($value, $field);
            case 'int':
                if (!is_numeric($value)) {
                    throw new ServiceError('validation', 'invalid_field', "'{$field}' must be a number.");
                }
                return (int)$value;
            case 'decimal':
                if (!is_numeric($value)) {
                    throw new ServiceError('validation', 'invalid_field', "'{$field}' must be a number.");
                }
                return (string)round((float)$value, 2);
            default: // string
                $v = trim((string)$value);
                if (isset($def['max']) && mb_strlen($v) > $def['max']) {
                    throw new ServiceError('validation', 'invalid_field', "'{$field}' must be at most {$def['max']} characters.");
                }
                return $v === '' ? null : $v;
        }
    }

    /** Resolve a lookup id to its display name for the audit trail. */
    private static function auditDisplay(PDO $conn, string $field, $value, array $def): ?string
    {
        if ($value === null) {
            return null;
        }
        if ($def['kind'] === 'lookup') {
            $stmt = $conn->prepare("SELECT name FROM {$def['table']} WHERE id = ?");
            $stmt->execute([(int)$value]);
            $name = $stmt->fetchColumn();
            return $name !== false ? $name : (string)$value;
        }
        if ($def['kind'] === 'supplier') {
            $stmt = $conn->prepare("SELECT COALESCE(NULLIF(TRIM(trading_name), ''), legal_name) FROM suppliers WHERE id = ?");
            $stmt->execute([(int)$value]);
            $name = $stmt->fetchColumn();
            return $name !== false ? $name : (string)$value;
        }
        return (string)$value;
    }

    /**
     * Re-sync the asset expiry calendar (best-effort; same hook the UI + API
     * used). One pass writes BOTH warranty and lease entries, which is why
     * there is no lease equivalent of this method.
     */
    private static function syncWarranty(PDO $conn): void
    {
        require_once __DIR__ . '/../asset_warranty_calendar.php';
        try { syncAssetWarrantyCalendar($conn); } catch (Exception $syncEx) { /* non-critical */ }
    }

    // ======================================================================
    //  Who holds what (discussion #56)
    // ======================================================================

    /**
     * Everyone who currently holds at least one asset, with how many.
     *
     * The list is driven by `users_assets`, not by `users`: the question being
     * answered is "who has kit", so somebody with nothing does not belong on the
     * list at all. Search is applied here rather than client-side because an
     * install with thousands of requesters should not ship them all to a browser.
     *
     * ⚠️ INNER JOIN to users on purpose. users_assets has no foreign key, and
     * older installs carry rows pointing at requesters that no longer exist —
     * Ed's own dev database has nine. A LEFT JOIN would list them as blank people
     * holding real equipment, which reads as data loss rather than as stale rows.
     */
    public static function usersHoldingAssets(PDO $conn, ActorContext $ctx, string $search = '', int $limit = 200): array
    {
        [$tenantSql, $tenantArgs] = activeTenantFilter($conn, $ctx->actorId, 'a');

        $where = '';
        $args  = [];
        $search = trim($search);
        if ($search !== '') {
            $where = " AND (u.display_name LIKE ? OR u.email LIKE ?)";
            $args[] = '%' . $search . '%';
            $args[] = '%' . $search . '%';
        }

        $limit = max(1, min($limit, 500));
        $sql = "SELECT u.id, u.email, u.display_name,
                       COUNT(ua.id)            AS asset_count,
                       MAX(ua.assigned_datetime) AS latest_assignment
                  FROM users_assets ua
                  JOIN users  u ON u.id = ua.user_id
                  JOIN assets a ON a.id = ua.asset_id
                 WHERE 1=1 $tenantSql $where
                 GROUP BY u.id, u.email, u.display_name
                 ORDER BY (u.display_name IS NULL OR u.display_name = ''), u.display_name, u.email
                 LIMIT $limit";

        $stmt = $conn->prepare($sql);
        $stmt->execute(array_merge($tenantArgs, $args));
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as &$r) {
            $r['id']          = (int)$r['id'];
            $r['asset_count'] = (int)$r['asset_count'];
            $r['name']        = self::personName($r);
        }
        return $rows;
    }

    /**
     * Everyone, whether or not they hold anything.
     *
     * The companion to usersHoldingAssets(), and a different question: that one
     * answers "who has equipment", this one answers "who is there". You cannot
     * assign a laptop to somebody the list will not show you, so a people
     * directory that only lists current holders is unusable for the one job it
     * most needs to do — issuing kit to a new starter.
     *
     * $scope:
     *   'current'  — people who are still here. The default: a leaver in a
     *                picker is how equipment gets issued to somebody who left.
     *   'leavers'  — only those marked as having left.
     *   'everyone' — genuinely everyone, both of the above.
     *   'holding'  — anybody holding equipment, INCLUDING leavers. A leaver who
     *                still has a laptop is the single most actionable row on
     *                this screen, so a filter about equipment must not hide them
     *                behind a filter about employment.
     *
     * ⚠️ 'everyone' means everyone. It was originally the default and excluded
     * leavers, which is a contradiction — a filter that says Everyone and hides
     * people teaches you not to trust the others either.
     *
     * Tenancy is applied to the PERSON (users.tenant_id), not to their assets —
     * otherwise somebody who holds nothing would fall outside the filter and
     * silently vanish from the directory.
     */
    public static function people(PDO $conn, ActorContext $ctx, string $search = '', string $scope = 'current', int $limit = 500, string $source = ''): array
    {
        [$tenantSql, $tenantArgs] = activeTenantFilter($conn, $ctx->actorId, 'u');

        // 🔴 The manager's NAME needs its own scope, and it belongs in the JOIN.
        //
        // `manager_id` is not tenant-scoped — nothing stops a person in one
        // company reporting to somebody in another, and until the same release
        // as this comment `save_user.php` would accept exactly that from any
        // analyst. So `LEFT JOIN users m` scoped only by `u` handed an analyst
        // who can see company A the display name of somebody in company B.
        //
        // ⚠️ In the JOIN's ON clause, never the WHERE. A LEFT JOIN with the
        // condition in WHERE stops being a LEFT JOIN: the row is dropped
        // entirely, so a person whose manager is out of scope would vanish from
        // the list rather than simply showing no manager. Same lesson as the
        // Watchtower scoping fix.
        [$mgrTenantSql, $mgrTenantArgs] = activeTenantFilter($conn, $ctx->actorId, 'm');

        $where = '';
        $args  = [];
        $search = trim($search);
        if ($search !== '') {
            $where .= " AND (u.display_name LIKE ? OR u.email LIKE ? OR u.username LIKE ?
                             OR u.department LIKE ? OR u.job_title LIKE ? OR u.employee_id LIKE ?)";
            for ($i = 0; $i < 6; $i++) $args[] = '%' . $search . '%';
        }

        // 'everyone' and 'holding' apply no employment filter at all — see the
        // docblock. Only 'current' and 'leavers' narrow by is_active.
        if ($scope === 'leavers')      $where .= " AND u.is_active = 0";
        elseif ($scope === 'current')  $where .= " AND u.is_active = 1";
        if ($scope === 'holding')      $where .= " AND EXISTS (SELECT 1 FROM users_assets ua WHERE ua.user_id = u.id)";

        // Source: only people added here, or one directory / address book.
        [$srcSql, $srcArgs] = userSourceFilter($source);
        $where .= $srcSql;
        $args = array_merge($args, $srcArgs);

        $limit = max(1, min($limit, 1000));
        $sql = "SELECT u.id, u.email, u.username, u.display_name, u.preferred_name,
                       u.job_title, u.department, u.office, u.phone, u.mobile,
                       u.employee_id, u.manager_id, u.is_active, u.is_managed,
                       u.directory_username, u.last_seen_in_source, u.deactivated_datetime,
                       u.tenant_id, ap.protocol AS managed_protocol, ap.carddav_write_back AS managed_write_back,
                       ap.id AS source_id, ap.display_name AS source_name,
                       m.display_name AS manager_name,
                       (SELECT COUNT(*) FROM users_assets ua2 WHERE ua2.user_id = u.id) AS asset_count
                  FROM users u
             LEFT JOIN users m ON m.id = u.manager_id $mgrTenantSql
             LEFT JOIN auth_providers ap ON ap.id = u.auth_provider_id
                 WHERE 1=1 $tenantSql $where
                 ORDER BY (u.display_name IS NULL OR u.display_name = ''), u.display_name, u.email
                 LIMIT $limit";

        // ⚠️ Order matters: the manager scope sits in the JOIN, which precedes
        // the WHERE, so its placeholders bind FIRST.
        $stmt = $conn->prepare($sql);
        $stmt->execute(array_merge($mgrTenantArgs, $tenantArgs, $args));
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as &$r) {
            $r['id']          = (int)$r['id'];
            $r['asset_count'] = (int)$r['asset_count'];
            $r['is_active']   = (int)$r['is_active'] === 1;
            $r['is_managed']  = (int)$r['is_managed'] === 1;
            // Resolved server-side so no screen retypes it. See the same note in
            // api/tickets/get_users.php: an address book owns five of the seven
            // person fields, LDAP owns all seven, and the list that used to be a
            // JavaScript literal in this module's own screen was wrong for the
            // first the moment CardDAV shipped.
            $r['managed_fields'] = $r['is_managed']
                ? array_values(userDirectoryOwnedFields(
                      $r['managed_protocol'] ?? null,
                      (int)($r['managed_write_back'] ?? 0) === 1
                  ))
                : [];
            $r['manager_id']  = $r['manager_id'] !== null ? (int)$r['manager_id'] : null;
            $r['name']        = self::personName($r);
        }
        return $rows;
    }

    /**
     * One person, and everything currently assigned to them.
     *
     * Returns ['user' => …, 'assets' => […]] or null when the person does not
     * exist. An existing person holding nothing returns an empty asset list
     * rather than null — "Ada has no equipment" is a real and useful answer,
     * particularly during offboarding.
     */
    public static function assetsForUser(PDO $conn, ActorContext $ctx, int $userId): ?array
    {
        // The whole person, not just the name. The screen used to take these from
        // the row it already had in the list, which silently produced a detail
        // panel with no details whenever the person was not IN that list — after
        // a search, or when following a link to somebody outside the current
        // filter. Reading them here means the panel is complete however you
        // arrived at it.
        // Same scope on the manager's name as the list above, for the same
        // reason — and in the ON clause, so a manager out of scope means "no
        // manager shown" rather than "this person does not exist".
        [$mgrTenantSql, $mgrTenantArgs] = activeTenantFilter($conn, $ctx->actorId, 'm');
        $u = $conn->prepare(
            "SELECT u.id, u.email, u.username, u.display_name, u.preferred_name,
                    u.job_title, u.department, u.office, u.phone, u.mobile,
                    u.employee_id, u.manager_id, u.is_active, u.is_managed,
                    u.directory_username, u.deactivated_datetime,
                    ap.protocol AS managed_protocol, ap.carddav_write_back AS managed_write_back,
                    ap.id AS source_id, ap.display_name AS source_name,
                    m.display_name AS manager_name
               FROM users u
          LEFT JOIN users m ON m.id = u.manager_id $mgrTenantSql
          LEFT JOIN auth_providers ap ON ap.id = u.auth_provider_id
              WHERE u.id = ?"
        );
        $u->execute(array_merge($mgrTenantArgs, [$userId]));
        $user = $u->fetch(PDO::FETCH_ASSOC);
        if (!$user) {
            return null;
        }
        $user['id']         = (int)$user['id'];
        $user['name']       = self::personName($user);
        $user['is_active']  = (int)$user['is_active'] === 1;
        $user['is_managed'] = (int)$user['is_managed'] === 1;
        $user['managed_fields'] = $user['is_managed']
            ? array_values(userDirectoryOwnedFields(
                  $user['managed_protocol'] ?? null,
                  (int)($user['managed_write_back'] ?? 0) === 1
              ))
            : [];
        $user['manager_id'] = $user['manager_id'] !== null ? (int)$user['manager_id'] : null;

        // Who reports to this person. The relationship is stored once, pointing
        // upwards, so the only way to answer "who does she manage" is to look for
        // everybody pointing at her. Leavers are included and flagged rather than
        // hidden: a manager whose reports have all left is worth seeing, and so is
        // a leaver who still has people pointed at them.
        $r = $conn->prepare(
            "SELECT id, display_name, email, is_active
               FROM users
              WHERE manager_id = ?
              ORDER BY (display_name IS NULL OR display_name = ''), display_name, email"
        );
        $r->execute([$userId]);
        $user['reports'] = array_map(static function (array $row): array {
            return [
                'id'        => (int)$row['id'],
                'name'      => self::personName($row),
                'is_active' => (int)$row['is_active'] === 1,
            ];
        }, $r->fetchAll(PDO::FETCH_ASSOC));

        [$tenantSql, $tenantArgs] = activeTenantFilter($conn, $ctx->actorId, 'a');

        $sql = "SELECT a.id, a.hostname, a.manufacturer, a.model, a.service_tag, a.asset_tag,
                       a.operating_system, a.purchase_date, a.warranty_expiry,
                       at.name  AS asset_type,
                       ast.name AS asset_status,
                       loc.name AS location,
                       ua.assigned_datetime, ua.expected_return_date, ua.notes,
                       an.full_name AS assigned_by
                  FROM users_assets ua
                  JOIN assets a          ON a.id  = ua.asset_id
                  LEFT JOIN asset_types  at  ON at.id  = a.asset_type_id
                  LEFT JOIN asset_status_types ast ON ast.id = a.asset_status_id
                  LEFT JOIN asset_locations loc ON loc.id = a.location_id
                  LEFT JOIN analysts     an  ON an.id  = ua.assigned_by_analyst_id
                 WHERE ua.user_id = ? $tenantSql
                 ORDER BY at.name, a.hostname";

        $stmt = $conn->prepare($sql);
        $stmt->execute(array_merge([$userId], $tenantArgs));
        $assets = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($assets as &$a) {
            $a['id'] = (int)$a['id'];
        }
        unset($a);

        // Custom field values, so a handover document can show a monitor's size
        // or a headset's part number alongside the built-in columns.
        //
        // Attached HERE rather than in each of the three handover callers
        // (print, email, preview) — one batched query, and they cannot drift
        // apart. Keyed `custom` so nothing existing changes shape.
        //
        // 🔑 A field the asset has not got stays ABSENT, so the document can
        // print a dash for "not recorded" rather than an empty cell that might
        // mean "no".
        if ($assets) {
            require_once __DIR__ . '/asset_fields.php';
            if (AssetFieldsService::schemaReady($conn)) {
                $defs = AssetFieldsService::fieldsForSets(
                    $conn,
                    $conn->query("SELECT id FROM asset_field_sets WHERE is_deleted = 0")->fetchAll(PDO::FETCH_COLUMN)
                );
                if ($defs) {
                    $vals = AssetFieldsService::readForAssets(
                        $conn, array_map(static fn($x) => (int)$x['id'], $assets), $defs
                    );
                    foreach ($assets as &$a) {
                        $a['custom'] = $vals[(int)$a['id']] ?? [];
                    }
                    unset($a);
                }
            }
        }

        return ['user' => $user, 'assets' => $assets];
    }

    /** Best available human name for a requester row, falling back to the email. */
    private static function personName(array $row): string
    {
        foreach (['display_name', 'preferred_name', 'email'] as $k) {
            if (!empty($row[$k])) {
                return (string)$row[$k];
            }
        }
        return '#' . ($row['id'] ?? '?');
    }

    /**
     * Single authoritative default list of known generic / non-unique service tags and BIOS serial placeholders.
     */
    public const DEFAULT_IGNORED_SERVICE_TAGS = [
        'TO BE FILLED BY O.E.M.',
        'DEFAULT STRING',
        'NONE',
        'SYSTEM SERIAL NUMBER',
        'NOT SPECIFIED',
        '123456789'
    ];

    /**
     * Load the map of ignored/blacklisted service tags from system_settings.
     *
     * @param PDO $conn Database connection
     * @return array<string, bool> Lowercased lookup map [trimmed_tag => true]
     */
    public static function getIgnoredServiceTags(PDO $conn): array {
        $ignoredMap = [];
        foreach (self::DEFAULT_IGNORED_SERVICE_TAGS as $tag) {
            $ignoredMap[mb_strtolower(trim($tag))] = true;
        }

        try {
            $stmt = $conn->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'asset_reconciliation_ignored_serials' LIMIT 1");
            $stmt->execute();
            $val = $stmt->fetchColumn();
            if ($val !== false && is_string($val) && trim($val) !== '') {
                $lines = preg_split('/\r\n|\r|\n/', $val);
                if (is_array($lines)) {
                    $ignoredMap = [];
                    foreach ($lines as $line) {
                        $trimmed = mb_strtolower(trim($line));
                        if ($trimmed !== '') {
                            $ignoredMap[$trimmed] = true;
                        }
                    }
                }
            }
        } catch (Throwable $e) {
            // Fallback to defaults
        }

        return $ignoredMap;
    }

    /**
     * Test whether a service tag or serial number is usable for stable device reconciliation.
     *
     * Rejects empty/whitespace strings and configured placeholder / generic values.
     *
     * @param string|null $tag Raw service tag
     * @param array<string, bool>|null $ignoredMap Optional pre-loaded ignored map
     * @return bool
     */
    public static function isUsableServiceTag(?string $tag, ?array $ignoredMap = null): bool {
        if ($tag === null) {
            return false;
        }
        $trimmed = trim($tag);
        if ($trimmed === '') {
            return false;
        }
        $lower = mb_strtolower($trimmed);
        if ($ignoredMap !== null) {
            return !isset($ignoredMap[$lower]);
        }
        foreach (self::DEFAULT_IGNORED_SERVICE_TAGS as $defaultTag) {
            if ($lower === mb_strtolower(trim($defaultTag))) {
                return false;
            }
        }
        return true;
    }

    /**
     * Pure resolution: Identifies which existing asset matches the given device identifiers.
     *
     * Reconciliation Hierarchy:
     *   Tier 1: Explicit authoritative connector link (e.g. intune_devices.asset_id)
     *   Tier 2: Clean, non-generic hardware serial_number / service_tag with ambiguity guard
     *   Tier 3: Hostname match (scoped to company/tenant)
     *   Tier 4: Genuine new device (returns asset_id = null)
     *
     * @param PDO $conn Database connection
     * @param array $identifiers ['asset_id' => int|null, 'service_tag' => string|null, 'hostname' => string|null]
     * @param int|null $tenantId Scoped tenant ID (or null for default company)
     * @param bool $isExplicitLink True if $identifiers['asset_id'] comes from an authoritative link
     * @param array|null $ignoredTags Pre-loaded ignored service tags map
     * @return array ['asset_id' => int|null, 'matched_by' => string, 'ambiguous' => bool]
     */
    public static function resolveAssetIdentity(
        PDO $conn,
        array $identifiers,
        ?int $tenantId = null,
        bool $isExplicitLink = false,
        ?array $ignoredTags = null
    ): array {
        $rawAssetId = !empty($identifiers['asset_id']) ? (int)$identifiers['asset_id'] : null;
        $serviceTag = isset($identifiers['service_tag']) ? trim((string)$identifiers['service_tag']) : '';
        $hostname   = isset($identifiers['hostname']) ? trim((string)$identifiers['hostname']) : '';

        // ---------------------------------------------------------------------
        // Tier 1: Authoritative explicit connector link
        // ---------------------------------------------------------------------
        if ($isExplicitLink && $rawAssetId !== null && $rawAssetId > 0) {
            // Must strictly belong to the scoped company/tenant context
            $stmt = $conn->prepare("SELECT id FROM assets WHERE id = ? AND tenant_id <=> ? LIMIT 1");
            $stmt->execute([$rawAssetId, $tenantId]);
            $existingId = $stmt->fetchColumn();
            if ($existingId) {
                return [
                    'asset_id'   => (int)$existingId,
                    'matched_by' => 'explicit_link',
                    'ambiguous'  => false,
                ];
            }
            // If the explicit link points to an asset belonging to another company (or non-existent),
            // reject it as authoritative and fall through safely to Tier 2 / Tier 3 within $tenantId.
        }

        if ($ignoredTags === null) {
            $ignoredTags = self::getIgnoredServiceTags($conn);
        }

        // ---------------------------------------------------------------------
        // Tier 2: Clean hardware serial number / service tag with ambiguity guard
        // ---------------------------------------------------------------------
        if ($serviceTag !== '' && self::isUsableServiceTag($serviceTag, $ignoredTags)) {
            $stmt = $conn->prepare("SELECT id FROM assets WHERE service_tag = ? AND tenant_id <=> ?");
            $stmt->execute([$serviceTag, $tenantId]);
            $matchingIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

            if (count($matchingIds) === 1) {
                return [
                    'asset_id'   => (int)$matchingIds[0],
                    'matched_by' => 'service_tag',
                    'ambiguous'  => false,
                ];
            }
            if (count($matchingIds) > 1) {
                // Ambiguity guard: Multiple existing assets share this serial number.
                // Do not guess blindly; fall through to Tier 3.
                if ($hostname !== '') {
                    $hostStmt = $conn->prepare("SELECT id FROM assets WHERE hostname = ? AND tenant_id <=> ? LIMIT 1");
                    $hostStmt->execute([$hostname, $tenantId]);
                    $hostId = $hostStmt->fetchColumn();
                    if ($hostId) {
                        return [
                            'asset_id'   => (int)$hostId,
                            'matched_by' => 'hostname',
                            'ambiguous'  => true,
                        ];
                    }
                }
                return [
                    'asset_id'   => null,
                    'matched_by' => 'none',
                    'ambiguous'  => true,
                ];
            }
        }

        // ---------------------------------------------------------------------
        // Tier 3: Hostname match
        // ---------------------------------------------------------------------
        if ($hostname !== '') {
            $stmt = $conn->prepare("SELECT id FROM assets WHERE hostname = ? AND tenant_id <=> ? LIMIT 1");
            $stmt->execute([$hostname, $tenantId]);
            $hostId = $stmt->fetchColumn();
            if ($hostId) {
                return [
                    'asset_id'   => (int)$hostId,
                    'matched_by' => 'hostname',
                    'ambiguous'  => false,
                ];
            }
        }

        // ---------------------------------------------------------------------
        // Tier 4: Genuine new device
        // ---------------------------------------------------------------------
        return [
            'asset_id'   => null,
            'matched_by' => 'none',
            'ambiguous'  => false,
        ];
    }

    /**
     * Updates an asset's hostname with collision protection and an atomic audit log.
     *
     * If another active asset in the same tenant already claims $newHostname, the update is skipped
     * and collision is flagged.
     *
     * @param PDO $conn Database connection
     * @param int $assetId Target asset ID
     * @param string $newHostname New hostname to apply
     * @param int|null $tenantId Scoped tenant ID
     * @param string $sourceLabel Integration label (e.g. 'Intune', 'system-info')
     * @return array ['updated' => bool, 'conflict' => bool]
     */
    public static function updateAssetHostname(
        PDO $conn,
        int $assetId,
        string $newHostname,
        ?int $tenantId,
        string $sourceLabel = 'system'
    ): array {
        $newHostname = trim($newHostname);
        if ($newHostname === '') {
            return ['updated' => false, 'conflict' => false];
        }

        // Fetch current asset details
        $stmt = $conn->prepare("SELECT hostname, tenant_id FROM assets WHERE id = ? LIMIT 1");
        $stmt->execute([$assetId]);
        $curr = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$curr) {
            return ['updated' => false, 'conflict' => false];
        }

        $oldHostname = (string)($curr['hostname'] ?? '');
        if (strcasecmp($oldHostname, $newHostname) === 0) {
            return ['updated' => false, 'conflict' => false]; // No change needed
        }

        $effectiveTenantId = $curr['tenant_id'] !== null ? (int)$curr['tenant_id'] : $tenantId;

        // Collision Guard: Ensure newHostname is not already taken by another asset in the same company
        $collisionStmt = $conn->prepare("SELECT id FROM assets WHERE hostname = ? AND tenant_id <=> ? AND id != ? LIMIT 1");
        $collisionStmt->execute([$newHostname, $effectiveTenantId, $assetId]);
        if ($collisionStmt->fetchColumn()) {
            return ['updated' => false, 'conflict' => true];
        }

        // Atomic update and audit trail
        $inExistingTransaction = $conn->inTransaction();
        if (!$inExistingTransaction) {
            $conn->beginTransaction();
        }

        try {
            $upd = $conn->prepare("UPDATE assets SET hostname = ?, last_seen = UTC_TIMESTAMP() WHERE id = ?");
            $upd->execute([$newHostname, $assetId]);

            $hist = $conn->prepare("INSERT INTO asset_history (asset_id, analyst_id, field_name, old_value, new_value, created_datetime) VALUES (?, NULL, 'hostname', ?, ?, UTC_TIMESTAMP())");
            $hist->execute([$assetId, $oldHostname, $newHostname]);

            if (!$inExistingTransaction) {
                $conn->commit();
            }
            return ['updated' => true, 'conflict' => false];
        } catch (Throwable $e) {
            if (!$inExistingTransaction && $conn->inTransaction()) {
                $conn->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Reconciles an incoming device payload against the asset register and applies rename mutations.
     *
     * @param PDO $conn Database connection
     * @param array $identifiers ['asset_id' => int|null, 'service_tag' => string|null, 'hostname' => string|null]
     * @param int|null $tenantId Scoped tenant ID (or null for default company)
     * @param string $sourceLabel Ingest source description
     * @param bool $isExplicitLink True if $identifiers['asset_id'] is an authoritative link
     * @param array|null $ignoredTags Pre-loaded ignored service tags map
     * @return array Result containing asset_id, matched_by, ambiguous flag, hostname_updated flag, and hostname_conflict flag
     */
    public static function reconcileAsset(
        PDO $conn,
        array $identifiers,
        ?int $tenantId = null,
        string $sourceLabel = 'system',
        bool $isExplicitLink = false,
        ?array $ignoredTags = null
    ): array {
        $resolution = self::resolveAssetIdentity($conn, $identifiers, $tenantId, $isExplicitLink, $ignoredTags);
        $assetId    = $resolution['asset_id'];
        $matchedBy  = $resolution['matched_by'];
        $hostnameUpdated  = false;
        $hostnameConflict = false;

        $newHostname = isset($identifiers['hostname']) ? trim((string)$identifiers['hostname']) : '';

        // If matched by a stable identifier (Tier 1 or Tier 2), check for a hostname rename
        if ($assetId !== null && ($matchedBy === 'explicit_link' || $matchedBy === 'service_tag') && $newHostname !== '') {
            $mutation = self::updateAssetHostname($conn, $assetId, $newHostname, $tenantId, $sourceLabel);
            $hostnameUpdated  = $mutation['updated'];
            $hostnameConflict = $mutation['conflict'];
        }

        return [
            'asset_id'          => $assetId,
            'matched_by'        => $matchedBy,
            'ambiguous'         => $resolution['ambiguous'],
            'hostname_updated'  => $hostnameUpdated,
            'hostname_conflict' => $hostnameConflict,
        ];
    }
}
