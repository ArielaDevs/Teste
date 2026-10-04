<?php
/**
 * QR asset labels — the tag people read, and the token machines read.
 *
 * TWO IDENTIFIERS, ON PURPOSE
 * ---------------------------
 * `asset_tag` is the number printed in human-readable text on the label
 * ("LT0001"). It belongs to the company that owns the asset, so two companies
 * on one install may each legitimately run their own LT0001 — the same reason
 * hostname uniqueness is per-company here.
 *
 * `qr_token` is what the QR code actually encodes, as a URL. It is opaque and
 * install-wide unique, which is what makes the tag collision above a non-issue:
 * whatever the label says, the scan resolves to exactly one asset.
 *
 * WHY NOT ENCODE THE ID
 * ---------------------
 * `…/a/4711` invites somebody to try 4712. The token isn't a secret — the scan
 * page requires a login and enforces company scope like every other asset read —
 * but there is no reason to hand out an enumerable index of the estate to
 * anyone who photographs one label.
 *
 * WHY THE URL IS SHORT
 * --------------------
 * `/a/<token>` rather than `/asset-management/scan.php?token=<token>`: every
 * character is another QR module, and these get printed at 15mm square on the
 * side of a laptop that then spends three years being knocked about. Fewer
 * characters is a bigger, more forgiving code.
 */

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/public_url.php';
require_once __DIR__ . '/tenant_settings.php';
require_once __DIR__ . '/branding.php';
require_once __DIR__ . '/i18n.php';

/** Token length in bytes (hex-encoded to 20 chars). Short enough to keep the QR
 *  coarse, long enough that guessing is pointless. */
const ASSET_TOKEN_BYTES = 10;

/** Setting keys for physical label & QR configuration */
const KEY_LABEL_TITLE           = 'asset_label_title';
const KEY_LABEL_FIELDS          = 'asset_label_fields';
const KEY_LABEL_FOOTER          = 'asset_label_footer';
const KEY_LABEL_LOGO_ENABLED    = 'asset_label_logo_enabled';
const KEY_LABEL_LOGO_PATH       = 'asset_label_logo_path';
const KEY_LABEL_SHOW_FIELD_LABELS = 'asset_label_show_field_labels';

/** Does this database have the label columns yet? Cached per request. */
function assetLabelsSchemaReady(PDO $conn): bool {
    static $ready = null;
    if ($ready !== null) return $ready;
    try {
        $cols = $conn->query("SHOW COLUMNS FROM `assets` LIKE 'qr_token'")->fetch(PDO::FETCH_ASSOC);
        $ready = (bool)$cols;
    } catch (Exception $e) {
        $ready = false;
    }
    return $ready;
}

/**
 * The token for an asset, minting one if it has never been labelled.
 *
 * Minting on demand rather than at creation keeps the column empty for the
 * thousands of auto-discovered assets nobody will ever print a label for, and
 * means an asset's token comes into existence at the moment it acquires meaning.
 */
function assetEnsureToken(PDO $conn, int $assetId): ?string {
    if (!assetLabelsSchemaReady($conn) || $assetId <= 0) return null;

    $stmt = $conn->prepare("SELECT qr_token FROM assets WHERE id = ?");
    $stmt->execute([$assetId]);
    $existing = $stmt->fetchColumn();
    if ($existing === false) return null;              // no such asset
    if (!empty($existing)) return (string)$existing;

    // Retry on the (astronomically unlikely) collision rather than trusting luck;
    // the unique index is the real guard and this just avoids a hard failure.
    for ($attempt = 0; $attempt < 5; $attempt++) {
        $token = bin2hex(random_bytes(ASSET_TOKEN_BYTES));
        try {
            $upd = $conn->prepare("UPDATE assets SET qr_token = ? WHERE id = ? AND (qr_token IS NULL OR qr_token = '')");
            $upd->execute([$token, $assetId]);
            if ($upd->rowCount() > 0) return $token;
            // Somebody else minted one first — use theirs.
            $stmt->execute([$assetId]);
            $now = $stmt->fetchColumn();
            if (!empty($now)) return (string)$now;
        } catch (PDOException $e) {
            // Only a unique-index collision is worth another go (SQLSTATE 23000 /
            // MySQL 1062); anything else is a real fault and must surface (PR #164).
            if ($e->getCode() === '23000' || ($e->errorInfo[1] ?? null) === 1062) {
                continue;
            }
            throw $e;
        }
    }
    return null;
}

/** Resolve a scanned token to an asset id. Returns null for unknown tokens. */
function assetIdForToken(PDO $conn, string $token): ?int {
    if (!assetLabelsSchemaReady($conn)) return null;
    $token = trim($token);
    // Cheap shape check first: the column is indexed, but there is no reason to
    // send junk from a mis-scan to the database.
    if ($token === '' || !preg_match('/^[a-f0-9]{8,64}$/i', $token)) return null;
    $stmt = $conn->prepare("SELECT id FROM assets WHERE qr_token = ? LIMIT 1");
    $stmt->execute([$token]);
    $id = $stmt->fetchColumn();
    return $id === false ? null : (int)$id;
}

/**
 * Is this asset tag free within its company?
 *
 * Application-level because a UNIQUE (tenant_id, asset_tag) index would NOT
 * hold for the Default company: MySQL treats NULLs as distinct in a unique
 * index, so two NULL-tenant assets could both be LT0001 while the index looked
 * like it was guarding them. Same reason hostname is checked here rather than
 * by the schema. `<=>` is the null-safe equality operator, so the comparison
 * behaves for the Default company as well as a named one.
 *
 * A check in code needs serialising against a concurrent write - every caller
 * that WRITES a tag goes through AssetTagsService::withLock() (PR #164).
 */
function assetTagAvailable(PDO $conn, ?int $tenantId, string $tag, ?int $exceptAssetId = null): bool {
    if (!assetLabelsSchemaReady($conn)) return true;
    $tag = trim($tag);
    if ($tag === '') return true;                       // blank is always allowed
    $sql = "SELECT COUNT(*) FROM assets WHERE tenant_id <=> ? AND asset_tag = ?";
    $args = [$tenantId, $tag];
    if ($exceptAssetId !== null) { $sql .= " AND id <> ?"; $args[] = $exceptAssetId; }
    $stmt = $conn->prepare($sql);
    $stmt->execute($args);
    return (int)$stmt->fetchColumn() === 0;
}

/**
 * The URL a label's QR encodes.
 *
 * Absolute, because the code is scanned by a phone that has no idea what the
 * app's base path is. Built on publicBaseUrl() - the install's ONE answer to
 * "how does the outside world reach this install?" - so a configured public
 * address wins over the current request: a label is printed once and lives on a
 * laptop for years, and deriving it from whichever hostname the printing
 * analyst happened to be using would bake that in permanently.
 *
 * This used to reuse the messaging-only setting, flagged at the time as wanting
 * a generic key; publicBaseUrl() reads `public_base_url` and still falls back to
 * `messaging_public_base_url`, so labels printed before PR #164 encode the same
 * address. It also copes with a configured address that already carries the
 * app's folder (publicUrlWithAppPath()), which would otherwise print
 * …/freeitsm-app/freeitsm-app/a/<token> onto physical labels.
 */
function assetLabelUrl(string $token, ?PDO $conn = null): string {
    return publicAbsoluteUrl($conn ?? connectToDatabase(), 'a/' . $token);
}

/** The install's public base, including any sub-folder - see assetLabelUrl(). */
function assetPublicBaseUrl(?PDO $conn = null): string {
    return publicBaseUrl($conn ?? connectToDatabase());
}

/**
 * Canonical commercial A4 label sheet stock specifications.
 *
 * @return array<string, array{dims: string, w: float, h: float, cols: int, qr: int}>
 */
function assetLabelSheetSpecs(): array {
    return [
        '65' => ['dims' => '38.1 × 21.2 mm', 'w' => 38.1, 'h' => 21.2, 'cols' => 5, 'qr' => 16],
        '40' => ['dims' => '45.7 × 25.4 mm', 'w' => 45.7, 'h' => 25.4, 'cols' => 4, 'qr' => 19],
        '24' => ['dims' => '63.5 × 33.9 mm', 'w' => 63.5, 'h' => 33.9, 'cols' => 3, 'qr' => 25],
        '12' => ['dims' => '63.5 × 72 mm',   'w' => 63.5, 'h' => 72.0, 'cols' => 3, 'qr' => 38],
    ];
}

/**
 * Determine QR code error-correction level based on logo presence.
 *
 * Invariants:
 * - No logo: standard Level M (15% redundancy) for maximum module clarity
 * - With logo: high Level H (30% redundancy) to guarantee reliable scanning with 22% center overlay
 */
function assetLabelQrEcLevel(bool $hasLogo): string {
    return $hasLogo ? 'H' : 'M';
}

/** The built-in fields a label can print, in the order the picker offers them. */
const ASSET_LABEL_STANDARD_FIELDS = ['asset_tag', 'hostname', 'service_tag', 'manufacturer', 'model', 'company', 'location', 'asset_type'];

/** Custom asset fields a label can print: key ('cf_' + field_key) => its own label. */
function assetLabelCustomFields(PDO $conn, ?int $tenantId = null): array {
    $out = [];
    try {
        $sql = "SELECT field_key, label FROM asset_fields WHERE is_deleted = 0";
        $args = [];
        if ($tenantId !== null && $tenantId > 0 && isMultiTenant($conn)) {
            $sql .= " AND (tenant_id IS NULL OR tenant_id = ?)";
            $args[] = $tenantId;
        }
        $stmt = $conn->prepare($sql . " ORDER BY label ASC");
        $stmt->execute($args);
        while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $out['cf_' . $r['field_key']] = (string)$r['label'];
        }
    } catch (Exception $e) { /* custom fields not installed yet */ }
    return $out;
}

/**
 * Every field a label can print, with the name the SETTINGS picker shows -
 * built-in fields in the reader's language, custom fields marked as such.
 */
function assetLabelAvailableFields(PDO $conn, ?int $tenantId = null): array {
    $fields = [];
    foreach (ASSET_LABEL_STANDARD_FIELDS as $key) {
        $fields[$key] = t('asset-management.labels.field.' . $key);
    }
    foreach (assetLabelCustomFields($conn, $tenantId) as $key => $label) {
        $fields[$key] = t('asset-management.labels.field.custom', ['label' => $label]);
    }
    return $fields;
}

/**
 * The short names printed ON the label beside each value - space on a 38mm
 * sticker is tight, so "Serial" rather than "Serial / service tag".
 */
function assetLabelPrintFields(PDO $conn, ?int $tenantId = null): array {
    $fields = [];
    foreach (ASSET_LABEL_STANDARD_FIELDS as $key) {
        $fields[$key] = t('asset-management.labels.field_short.' . $key);
    }
    return $fields + assetLabelCustomFields($conn, $tenantId);
}

/**
 * The label settings for a company: header, footer, which fields in which
 * order, whether to print their names, and the logo inside the QR.
 *
 * @return array{title:string, fields:string[], footer:string, logo_enabled:bool,
 *               logo_path:string, custom_logo_path:string, show_field_labels:bool}
 */
function assetLabelSettings(PDO $conn, ?int $tenantId = null): array {
    $title        = (string)tenantSetting($conn, $tenantId, KEY_LABEL_TITLE, '');
    $rawFields    = (string)tenantSetting($conn, $tenantId, KEY_LABEL_FIELDS, '');
    $footer       = (string)tenantSetting($conn, $tenantId, KEY_LABEL_FOOTER, '');
    $logoEnabled      = (string)tenantSetting($conn, $tenantId, KEY_LABEL_LOGO_ENABLED, '0') === '1';
    $customLogoPath   = (string)tenantSetting($conn, $tenantId, KEY_LABEL_LOGO_PATH, '');
    $logoPath         = ($logoEnabled && $customLogoPath !== '' && brandingPathIsSafe($customLogoPath) && file_exists(__DIR__ . '/../' . $customLogoPath)) ? $customLogoPath : '';
    $showFieldLabels = (string)tenantSetting($conn, $tenantId, KEY_LABEL_SHOW_FIELD_LABELS, '0') === '1';

    $available = assetLabelAvailableFields($conn, $tenantId);

    // Parse configured ordered fields list
    $fields = [];
    if ($rawFields !== '') {
        $decoded = json_decode($rawFields, true);
        if (is_array($decoded)) {
            $fields = $decoded;
        } else {
            $fields = array_map('trim', explode(',', $rawFields));
        }
    } else {
        $fields = ['asset_tag', 'hostname']; // Default standard fields
    }

    // Filter to valid known fields
    $validFields = [];
    foreach ($fields as $f) {
        $f = trim((string)$f);
        if ($f !== '' && isset($available[$f]) && !in_array($f, $validFields, true)) {
            $validFields[] = $f;
        }
    }

    // Mandatory Rule: Asset Tag MUST be present in selected fields
    if (!in_array('asset_tag', $validFields, true)) {
        array_unshift($validFields, 'asset_tag');
    }

    return [
        'title'              => $title,
        'fields'             => $validFields,
        'footer'             => $footer,
        'logo_enabled'       => $logoEnabled,
        'logo_path'          => $logoPath,
        'custom_logo_path'   => $customLogoPath,
        'show_field_labels'  => $showFieldLabels,
    ];
}
