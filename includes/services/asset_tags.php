<?php
/**
 * AssetTagsService — central service layer for asset tag auto-generation,
 * sequence allocation, formatting, and collision avoidance.
 *
 * Adheres strictly to FreeITSM Service Layer standards:
 *  - method(PDO $conn, ...): NO superglobals ($_SESSION, $_POST).
 *  - No transport: Never echo, header, or exit. Returns data or throws ServiceError.
 *  - Monotonicity: Allocates sequence numbers atomically with SELECT ... FOR UPDATE.
 *  - Monotonic skip: If a generated tag already exists in the company scope, advances
 *    to the next available number without clashing.
 *  - Monotonic non-reuse: Once minted, a sequence counter only moves forward; deleting
 *    or retiring an asset does not cause numbers to be re-issued.
 */

require_once __DIR__ . '/../service_context.php';
require_once __DIR__ . '/../tenancy.php';
require_once __DIR__ . '/../tenant_settings.php';
require_once __DIR__ . '/../asset_labels.php';

class AssetTagsService
{
    /** Setting keys */
    const KEY_AUTOGEN_ENABLED = 'asset_tag_autogen_enabled';
    const KEY_PREFIX          = 'asset_tag_prefix';
    const KEY_SUFFIX          = 'asset_tag_suffix';
    const KEY_PADDING         = 'asset_tag_padding';
    const KEY_INITIAL_NUMBER  = 'asset_tag_initial_number';

    /**
     * Map tenantId to the integer key used in `asset_tag_sequences`.
     * 0 represents the Default / System company, avoiding MySQL NULL uniqueness traps.
     */
    public static function sequenceTenantKey(PDO $conn, ?int $tenantId): int
    {
        if ($tenantId === null) {
            return 0;
        }
        $defaultId = getDefaultTenantId($conn);
        return ($defaultId !== null && $tenantId === $defaultId) ? 0 : $tenantId;
    }

    /**
     * Is auto-generation enabled for this company scope?
     */
    public static function isAutogenEnabled(PDO $conn, ?int $tenantId): bool
    {
        return tenantSettingOn($conn, $tenantId, self::KEY_AUTOGEN_ENABLED, false);
    }

    /**
     * Get auto-generation configuration for this company scope.
     *
     * @return array{
     *   enabled: bool,
     *   prefix: string,
     *   suffix: string,
     *   padding: int,
     *   initial_number: int,
     *   next_number: int,
     *   preview: string
     * }
     */
    public static function getAutogenConfig(PDO $conn, ?int $tenantId): array
    {
        $enabled = self::isAutogenEnabled($conn, $tenantId);
        $prefix  = (string) tenantSetting($conn, $tenantId, self::KEY_PREFIX, 'AST-');
        $suffix  = (string) tenantSetting($conn, $tenantId, self::KEY_SUFFIX, '');
        $padding = max(1, min(12, (int) tenantSetting($conn, $tenantId, self::KEY_PADDING, '5')));
        $initNum = max(1, (int) tenantSetting($conn, $tenantId, self::KEY_INITIAL_NUMBER, '1'));

        $seqKey = self::sequenceTenantKey($conn, $tenantId);
        $stmt = $conn->prepare("SELECT next_number FROM asset_tag_sequences WHERE tenant_id = ?");
        $stmt->execute([$seqKey]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $nextNumber = $row ? (int)$row['next_number'] : $initNum;

        $preview = self::formatTag($prefix, $nextNumber, $padding, $suffix);

        return [
            'enabled'        => $enabled,
            'prefix'         => $prefix,
            'suffix'         => $suffix,
            'padding'        => $padding,
            'initial_number' => $initNum,
            'next_number'    => $nextNumber,
            'preview'        => $preview,
        ];
    }

    /**
     * Format an asset tag from its components: [prefix][padded number][suffix].
     */
    public static function formatTag(string $prefix, int $number, int $padding = 5, string $suffix = ''): string
    {
        $pad = max(1, min(12, $padding));
        return $prefix . str_pad((string)$number, $pad, '0', STR_PAD_LEFT) . $suffix;
    }

    /**
     * Atomically generate and allocate the next asset tag for a company.
     *
     * Guaranteed:
     *  - Concurrency safety: Row lock (SELECT ... FOR UPDATE) on the sequence row.
     *  - Monotonicity: next_number strictly advances forward.
     *  - Conflict avoidance: If an asset already has this tag, skips forward monotonically.
     *  - Monotonic non-reuse: Counter remains incremented even if created asset is retired/removed.
     *
     * @param PDO $conn
     * @param ?int $tenantId Target company (null = Default company)
     * @return string The minted unique asset tag
     * @throws ServiceError If tag cannot be generated
     */
    public static function generateNextAssetTag(PDO $conn, ?int $tenantId): string
    {
        $seqKey = self::sequenceTenantKey($conn, $tenantId);
        $config = self::getAutogenConfig($conn, $tenantId);
        $prefix = $config['prefix'];
        $suffix = $config['suffix'];
        $padding = $config['padding'];

        $storeTenant = ($tenantId !== null && $tenantId === getDefaultTenantId($conn)) ? null : $tenantId;

        // Atomically lock sequence counter in an isolated or active transaction
        $inTx = $conn->inTransaction();
        if (!$inTx) {
            $conn->beginTransaction();
        }

        try {
            // Atomically initialize sequence row if it does not exist yet to guarantee
            // SELECT ... FOR UPDATE always has an existing row to lock under first-use concurrency.
            $initNumber = max(1, $config['initial_number']);
            $insInit = $conn->prepare(
                "INSERT IGNORE INTO asset_tag_sequences (tenant_id, next_number, updated_datetime) VALUES (?, ?, UTC_TIMESTAMP())"
            );
            $insInit->execute([$seqKey, $initNumber]);

            $stmt = $conn->prepare("SELECT id, next_number FROM asset_tag_sequences WHERE tenant_id = ? FOR UPDATE");
            $stmt->execute([$seqKey]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $seqId = (int)$row['id'];
            $currNumber = max(1, (int)$row['next_number']);

            // Safe conflict loop: probe within company scope to leapfrog any legacy collisions
            $chk = $conn->prepare("SELECT id FROM assets WHERE tenant_id <=> ? AND asset_tag = ? LIMIT 1");
            $allocatedTag = '';
            $attempts = 0;
            $maxAttempts = 1000;

            while ($attempts < $maxAttempts) {
                $candidate = self::formatTag($prefix, $currNumber, $padding, $suffix);
                $chk->execute([$storeTenant, $candidate]);
                if (!$chk->fetchColumn()) {
                    $allocatedTag = $candidate;
                    break;
                }
                $currNumber++;
                $attempts++;
            }

            if ($allocatedTag === '') {
                throw new ServiceError('conflict', 'sequence_exhausted', 'Could not find an available asset tag sequence slot.');
            }

            // Advance sequence counter monotonically to next number
            $nextSeq = $currNumber + 1;
            $upd = $conn->prepare("UPDATE asset_tag_sequences SET next_number = ?, updated_datetime = UTC_TIMESTAMP() WHERE id = ?");
            $upd->execute([$nextSeq, $seqId]);

            if (!$inTx) {
                $conn->commit();
            }

            return $allocatedTag;
        } catch (Throwable $e) {
            if (!$inTx && $conn->inTransaction()) {
                $conn->rollBack();
            }
            if ($e instanceof ServiceError) {
                throw $e;
            }
            throw new ServiceError('server_error', 'tag_generation_failed', 'Failed to generate asset tag: ' . $e->getMessage());
        }
    }

    /**
     * Explicitly update the next sequence number for a company (Admin configuration).
     */
    public static function setNextSequenceNumber(PDO $conn, ?int $tenantId, int $nextNumber): void
    {
        $seqKey = self::sequenceTenantKey($conn, $tenantId);
        $next = max(1, $nextNumber);

        // Atomically initialize row if missing, or conditionally advance forward if next is greater.
        // Uses MySQL GREATEST() to guarantee next_number can only move forward monotonically, preventing
        // concurrent race conditions where a lower out-of-order administrative adjustment overwrites a higher one.
        $stmt = $conn->prepare(
            "INSERT INTO asset_tag_sequences (tenant_id, next_number, updated_datetime)
             VALUES (?, ?, UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE
                 next_number = GREATEST(next_number, VALUES(next_number)),
                 updated_datetime = UTC_TIMESTAMP()"
        );
        $stmt->execute([$seqKey, $next]);
    }
}