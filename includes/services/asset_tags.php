<?php
/**
 * AssetTagsService — the ONE place an asset tag is generated, checked or
 * changed (PR #164, built on Sandy's original).
 *
 * An asset tag is the human number printed on a label: "AST-00042". It is not
 * the asset's identity — the opaque QR token is (see includes/asset_labels.php
 * and the QR labels developer guide) — so a tag may be edited, with history,
 * and a printed label still scans to the right asset.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * THE SHAPE IS TICKET NUMBERING'S, ON PURPOSE
 *
 * A tag is described by ONE format string with tokens, exactly as a ticket
 * number is (includes/ticket_numbering.php): `AST-{#####}`, `{COMPANY}-LT-{####}`,
 * `IT{YY}-{######}`. The renderer IS TicketNumbering::render(), so the tokens,
 * the "{###} is a minimum width, never a limit" rule and {COMPANY}'s code all
 * behave identically in both places, and an administrator who has set up one
 * recognises the other.
 *
 * The counter is claimed the same way too — one atomic upsert, LAST_INSERT_ID,
 * no read-then-write — and a counter that has fallen behind the tags already in
 * use is jumped forward with a doubling stride rather than crawled one number at
 * a time. Called inside the asset's own transaction, the claim rolls back with a
 * failed asset, so a failed create never burns a number (Sandy's requirement,
 * kept).
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * ONE LOCK FOR EVERY WRITE OF A TAG
 *
 * Uniqueness is per company and checked in code, because a UNIQUE
 * (tenant_id, asset_tag) index cannot hold for the Default company (MySQL treats
 * NULLs as distinct — see assetTagAvailable()). A check-then-write needs
 * serialising, so every path that writes a tag — typed in on create, generated,
 * or changed later on the asset page — runs inside withLock(), a MySQL named
 * lock per company. Two paths with two different locks would let a typed
 * AST-00005 and a generated AST-00005 both through.
 */

require_once __DIR__ . '/../service_context.php';
require_once __DIR__ . '/../tenancy.php';
require_once __DIR__ . '/../tenant_settings.php';
require_once __DIR__ . '/../asset_labels.php';
require_once __DIR__ . '/../ticket_numbering.php';

class AssetTagsService
{
    /**
     * Every setting, with the default that reproduces today's behaviour: off,
     * so an upgrade changes nothing until somebody switches it on. Enabled,
     * format and start may be set per company; scope is install-wide.
     */
    const DEFAULTS = [
        'asset_tag_autogen_enabled' => '0',
        'asset_tag_format'          => 'AST-{#####}',
        'asset_tag_start'           => '1',
        // per_company: each company counts on its own (tags are unique per
        // company anyway). global: one run of numbers across the install.
        'asset_tag_scope'           => 'per_company',
    ];

    /** Tokens a tag format may use. {TYPE} is a ticket type, so it is not one. */
    const TOKENS = ['{#}', '{YYYY}', '{YY}', '{MM}', '{DD}', '{COMPANY}'];

    /** @var array|null test / preview override, bypassing the stored settings */
    private static $override = null;

    /** Run with a specific configuration — used by the tests. */
    public static function withSettings(?array $settings): void
    {
        self::$override = $settings;
    }

    /** Drop the override and the settings cache. */
    public static function forget(): void
    {
        self::$override = null;
        tenantSettingForget();
    }

    // ====================================================================
    //  Settings
    // ====================================================================

    /** The effective configuration for one company (null = Default). */
    public static function config(PDO $conn, ?int $tenantId): array
    {
        if (self::$override !== null) {
            return array_merge(self::DEFAULTS, self::$override);
        }
        $cfg = [];
        foreach (self::DEFAULTS as $key => $default) {
            $cfg[$key] = $key === 'asset_tag_scope'
                ? (string)tenantSetting($conn, null, $key, $default)
                : (string)tenantSetting($conn, $tenantId, $key, $default);
        }
        return $cfg;
    }

    public static function isAutogenEnabled(PDO $conn, ?int $tenantId): bool
    {
        return (self::config($conn, $tenantId)['asset_tag_autogen_enabled'] ?? '0') === '1';
    }

    /**
     * What is wrong with a format, in words an administrator can act on. Empty
     * means it is usable.
     */
    public static function validateFormat(string $format): array
    {
        $problems = [];
        if (trim($format) === '') {
            return ['The format cannot be empty.'];
        }
        if (preg_match_all('/\{#+\}/', $format) !== 1) {
            // None: every asset gets the same tag. Two: nobody can say which is the number.
            $problems[] = 'The format needs exactly one number - for example {#####} where the digits should go.';
        }
        $rest = preg_replace('/\{#+\}/', '', $format);
        foreach (['{YYYY}', '{YY}', '{MM}', '{DD}', '{COMPANY}'] as $token) {
            $rest = str_replace($token, '', $rest);
        }
        if (strpos($rest, '{') !== false || strpos($rest, '}') !== false) {
            $problems[] = 'Only these tokens can be used: {#####}, {YYYY}, {YY}, {MM}, {DD} and {COMPANY}.';
        }
        if (!preg_match('/^[A-Za-z0-9_\-\.\/]*$/', $rest)) {
            // The tag is printed, typed into searches and read out on the phone.
            $problems[] = 'Use letters, numbers, hyphens, underscores, dots and slashes only.';
        }
        if (mb_strlen(TicketNumbering::render(str_replace('{COMPANY}', 'ACMECO', $format), 999999)) > 64) {
            $problems[] = 'That format is too long - an asset tag can be at most 64 characters.';
        }
        return $problems;
    }

    /** A few example tags for a proposed configuration. Writes nothing. */
    public static function preview(array $cfg, int $start, int $count = 3): array
    {
        // A preview has no company, so {COMPANY} gets a stand-in: an empty one
        // would show "-00001" and look like a broken token.
        $format = str_replace('{COMPANY}', 'ACME', $cfg['asset_tag_format'] ?? self::DEFAULTS['asset_tag_format']);
        $out = [];
        for ($i = 0; $i < $count; $i++) {
            $out[] = TicketNumbering::render($format, max(1, $start) + $i);
        }
        return $out;
    }

    // ====================================================================
    //  Companies and counters
    // ====================================================================

    /** Assets store the Default company as NULL; so does every check here. */
    public static function storeTenant(PDO $conn, ?int $tenantId): ?int
    {
        if ($tenantId === null) return null;
        return $tenantId === getDefaultTenantId($conn) ? null : $tenantId;
    }

    /** Which counter a company draws from. */
    public static function counterKey(PDO $conn, array $cfg, ?int $tenantId): string
    {
        if (($cfg['asset_tag_scope'] ?? 'per_company') === 'global') {
            return 'asset';
        }
        return 'asset:co' . (int)(self::storeTenant($conn, $tenantId) ?? 0);
    }

    /** The number the next generated tag will carry. Reads only. */
    public static function nextNumber(PDO $conn, ?int $tenantId): int
    {
        $cfg = self::config($conn, $tenantId);
        try {
            $st = $conn->prepare("SELECT next_value FROM asset_tag_counters WHERE counter_key = ?");
            $st->execute([self::counterKey($conn, $cfg, $tenantId)]);
            $last = $st->fetchColumn();
        } catch (Throwable $e) {
            $last = false;   // table not created yet (before Database Verification)
        }
        // The column holds the LAST number issued, as ticket_number_counters does.
        return $last === false ? max(1, (int)$cfg['asset_tag_start']) : (int)$last + 1;
    }

    /** Make the next generated tag at least $next. Only ever forward. */
    public static function setNextNumber(PDO $conn, ?int $tenantId, int $next): void
    {
        $cfg = self::config($conn, $tenantId);
        self::windCounterTo($conn, self::counterKey($conn, $cfg, $tenantId), max(1, $next) - 1);
    }

    private static function windCounterTo(PDO $conn, string $key, int $lastIssued): void
    {
        $conn->prepare(
            "INSERT INTO asset_tag_counters (counter_key, next_value) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE next_value = GREATEST(next_value, VALUES(next_value))"
        )->execute([$key, $lastIssued]);
    }

    /** Claim the next number. Same statement, and the same rowCount() trap, as TicketNumbering::claimNext(). */
    private static function claimNext(PDO $conn, string $key, int $start): int
    {
        $stmt = $conn->prepare(
            "INSERT INTO asset_tag_counters (counter_key, next_value) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE next_value = LAST_INSERT_ID(next_value + 1)"
        );
        $stmt->execute([$key, $start]);
        // 1 = a fresh INSERT (the table has no AUTO_INCREMENT, so lastInsertId()
        // would be 0); 2 = the UPDATE ran and LAST_INSERT_ID() holds the number.
        if ($stmt->rowCount() === 1) {
            return $start;
        }
        return (int)$conn->lastInsertId();
    }

    // ====================================================================
    //  Making and checking a tag
    // ====================================================================

    /**
     * The next generated tag for a company. Call it inside withLock() and inside
     * the transaction that writes the asset, so a failed write gives the number
     * back. Uniqueness is proven against the assets, never assumed from the
     * counter: a counter can be behind (a restored backup, tags typed by hand).
     */
    public static function generate(PDO $conn, ?int $tenantId): string
    {
        $cfg   = self::config($conn, $tenantId);
        $key   = self::counterKey($conn, $cfg, $tenantId);
        $start = max(1, (int)$cfg['asset_tag_start']);
        $store = self::storeTenant($conn, $tenantId);
        $renderTenant = $store ?? getDefaultTenantId($conn);

        $step = 1;
        for ($attempt = 0; $attempt < 40; $attempt++) {
            $seq = self::claimNext($conn, $key, $start);
            $tag = TicketNumbering::render($cfg['asset_tag_format'], $seq, $conn, null, null, $renderTenant);
            if (assetTagAvailable($conn, $store, $tag)) {
                return $tag;
            }
            // Behind the tags already in use: jump, doubling each time, so a
            // counter hundreds behind is cleared in a handful of tries. The
            // counter holds the LAST number issued, so winding it to
            // $seq + $step - 1 makes the first retry the very next number -
            // stickers are printed in runs, and a needless gap reads as a
            // missing asset.
            self::windCounterTo($conn, $key, $seq + $step - 1);
            $step = min($step * 2, 65536);
        }
        throw new ServiceError('conflict', 'tag_generation_failed',
            'Could not find an unused asset tag. The counter looks far behind the tags already in use - check Assets -> Settings -> Asset tags.');
    }

    /**
     * A tag typed by hand that has the generated SHAPE and a number ahead of the
     * counter winds the counter past it, so the generator never has to skip it
     * later. Anything else is left alone.
     */
    public static function noteManualTag(PDO $conn, ?int $tenantId, string $tag): void
    {
        $cfg = self::config($conn, $tenantId);
        $store = self::storeTenant($conn, $tenantId);
        $rendered = TicketNumbering::render($cfg['asset_tag_format'], 0, $conn, null, null, $store ?? getDefaultTenantId($conn));
        // Turn the rendered shape back into a pattern: the number becomes \d+.

        if (!preg_match('/\{(#+)\}/', $cfg['asset_tag_format'], $m)) return;
        $zeroes = str_repeat('0', strlen($m[1]));
        $pos = strpos($rendered, $zeroes);
        if ($pos === false) return;
        $pattern = '/^' . preg_quote(substr($rendered, 0, $pos), '/') . '(\d+)' . preg_quote(substr($rendered, $pos + strlen($zeroes)), '/') . '$/i';
        if (!preg_match($pattern, $tag, $hit)) return;
        $number = (int)$hit[1];
        if ($number >= self::nextNumber($conn, $tenantId)) {
            self::windCounterTo($conn, self::counterKey($conn, $cfg, $tenantId), $number);
        }
    }

    /**
     * Run $fn holding the per-company tag lock. Fails closed: a lock that cannot
     * be had is an error, never "carry on unguarded".
     *
     * @template T
     * @param callable():T $fn
     * @return T
     */
    public static function withLock(PDO $conn, ?int $tenantId, callable $fn)
    {
        $name = 'freeitsm_asset_tag_co' . (int)(self::storeTenant($conn, $tenantId) ?? 0);
        $st = $conn->prepare("SELECT GET_LOCK(?, 10)");
        $st->execute([$name]);
        if ((int)$st->fetchColumn() !== 1) {
            throw new ServiceError('conflict', 'tag_lock_failed', 'Asset tags are busy - please try again.');
        }
        try {
            return $fn();
        } finally {
            $conn->prepare("SELECT RELEASE_LOCK(?)")->execute([$name]);
        }
    }

    /** The plain message for a tag that is taken, naming where. */
    public static function clashMessage(PDO $conn, ?int $storeTenant, string $tag, ?int $exceptAssetId = null): string
    {
        $sql = "SELECT hostname FROM assets WHERE tenant_id <=> ? AND asset_tag = ?";
        $args = [$storeTenant, $tag];
        if ($exceptAssetId !== null) { $sql .= " AND id <> ?"; $args[] = $exceptAssetId; }
        $find = $conn->prepare($sql . " LIMIT 1");
        $find->execute($args);
        $clash = $find->fetchColumn();
        // "Already in use" without saying where has somebody hunting through 4,000 assets.
        return $clash ? "That tag is already on {$clash}." : 'That tag is already in use in this company.';
    }

    /**
     * Create an asset that may carry a tag: typed in ($manualTag), generated
     * (when switched on for the company and nothing was typed), or none. Takes
     * the lock when a tag is involved, and runs $insert inside a transaction -
     * the caller's, if it already has one - so the counter claim, the insert and
     * whatever $insert audits succeed or fail together.
     *
     * @param callable(?string):int $insert writes the asset with the given tag, returns its id
     * @return array{id:int, tag:?string}
     */
    public static function createWithTag(PDO $conn, ?int $tenantId, ?string $manualTag, callable $insert): array
    {
        $manualTag = $manualTag !== null ? trim($manualTag) : '';
        if (mb_strlen($manualTag) > 64) {
            throw new ServiceError('validation', 'invalid_field', "'asset_tag' must be at most 64 characters.");
        }
        $store = self::storeTenant($conn, $tenantId);
        $auto  = $manualTag === '' && self::isAutogenEnabled($conn, $store);

        $run = function () use ($conn, $tenantId, $store, $manualTag, $auto, $insert): array {
            $ownsTx = !$conn->inTransaction();
            if ($ownsTx) $conn->beginTransaction();
            try {
                $tag = null;
                if ($manualTag !== '') {
                    if (!assetTagAvailable($conn, $store, $manualTag)) {
                        throw new ServiceError('conflict', 'conflict', self::clashMessage($conn, $store, $manualTag));
                    }
                    $tag = $manualTag;
                    self::noteManualTag($conn, $tenantId, $manualTag);
                } elseif ($auto) {
                    $tag = self::generate($conn, $tenantId);
                }
                $id = $insert($tag);
                if ($ownsTx) $conn->commit();
                return ['id' => $id, 'tag' => $tag];
            } catch (Throwable $e) {
                if ($ownsTx && $conn->inTransaction()) $conn->rollBack();
                throw $e;
            }
        };
        // No tag at all: nothing to serialise.
        return ($manualTag !== '' || $auto) ? self::withLock($conn, $tenantId, $run) : $run();
    }

    /**
     * Change an existing asset's tag (the box on the asset page). Blank clears
     * it. Recorded in the asset's history. The caller has already checked the
     * analyst may see the asset.
     *
     * @return array{asset_tag:string, unchanged:bool}
     */
    public static function assign(PDO $conn, ActorContext $ctx, int $assetId, string $tag): array
    {
        $tag = trim($tag);
        if (mb_strlen($tag) > 64) {
            throw new ServiceError('validation', 'invalid_field', 'An asset tag can be at most 64 characters.');
        }
        $st = $conn->prepare("SELECT tenant_id, asset_tag FROM assets WHERE id = ?");
        $st->execute([$assetId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new ServiceError('not_found', 'not_found', 'Asset not found.');
        $store = $row['tenant_id'] === null ? null : (int)$row['tenant_id'];
        $old = (string)($row['asset_tag'] ?? '');
        if ($old === $tag) {
            return ['asset_tag' => $tag, 'unchanged' => true];
        }

        return self::withLock($conn, $store, function () use ($conn, $ctx, $assetId, $store, $tag, $old) {
            if ($tag !== '' && !assetTagAvailable($conn, $store, $tag, $assetId)) {
                throw new ServiceError('conflict', 'conflict', self::clashMessage($conn, $store, $tag, $assetId));
            }
            $conn->prepare("UPDATE assets SET asset_tag = ? WHERE id = ?")->execute([$tag === '' ? null : $tag, $assetId]);
            if ($tag !== '') self::noteManualTag($conn, $store, $tag);
            try {
                $conn->prepare(
                    "INSERT INTO asset_history (asset_id, analyst_id, field_name, old_value, new_value, created_datetime)
                     VALUES (?, ?, 'Asset tag', ?, ?, UTC_TIMESTAMP())"
                )->execute([$assetId, $ctx->actorId > 0 ? $ctx->actorId : null, $old === '' ? null : $old, $tag === '' ? null : $tag]);
            } catch (Throwable $e) { /* history is best-effort, as it always was here */ }
            return ['asset_tag' => $tag, 'unchanged' => false];
        });
    }
}
