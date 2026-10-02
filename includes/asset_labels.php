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

/** Token length in bytes (hex-encoded to 20 chars). Short enough to keep the QR
 *  coarse, long enough that guessing is pointless. */
const ASSET_TOKEN_BYTES = 10;

/** Setting keys for physical label & QR configuration */
const KEY_LABEL_TITLE           = 'asset_label_title';
const KEY_LABEL_FIELDS          = 'asset_label_fields';
const KEY_LABEL_SUBTITLE_FIELD   = 'asset_label_subtitle_field'; // Legacy fallback
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
 */
function assetEnsureToken(PDO $conn, int $assetId): ?string {
    if (!assetLabelsSchemaReady($conn) || $assetId <= 0) return null;
    $stmt = $conn->prepare("SELECT qr_token FROM assets WHERE id = ?");
    $stmt->execute([$assetId]);
    $existing = $stmt->fetchColumn();
    if ($existing === false) return null;
    if (!empty($existing)) return (string)$existing;

    for ($attempt = 0; $attempt < 5; $attempt++) {
        $token = bin2hex(random_bytes(ASSET_TOKEN_BYTES));
        try {
            $upd = $conn->prepare("UPDATE assets SET qr_token = ? WHERE id = ? AND (qr_token IS NULL OR qr_token = '')");
            $upd->execute([$token, $assetId]);
            if ($upd->rowCount() > 0) return $token;

            $stmt->execute([$assetId]);
            $now = $stmt->fetchColumn();
            if (!empty($now)) return (string)$now;
        } catch (Exception $e) { /* Unique violation: retry */ }
    }
    return null;
}

/** Resolve a scanned token to an asset id. Returns null for unknown tokens. */
function assetIdForToken(PDO $conn, string $token): ?int {
    if (!assetLabelsSchemaReady($conn)) return null;
    $token = trim($token);
    if ($token === '' || !preg_match('/^[a-f0-9]{8,64}$/i', $token)) return null;
    $stmt = $conn->prepare("SELECT id FROM assets WHERE qr_token = ? LIMIT 1");
    $stmt->execute([$token]);
    $id = $stmt->fetchColumn();
    return $id === false ? null : (int)$id;
}

/**
 * Is this asset tag free within its company?
 */
function assetTagAvailable(PDO $conn, ?int $tenantId, string $tag, ?int $exceptAssetId = null): bool {
    if (!assetLabelsSchemaReady($conn)) return true;
    $tag = trim($tag);
    if ($tag === '') return true;
    $sql = "SELECT COUNT(*) FROM assets WHERE tenant_id <=> ? AND asset_tag = ?";
    $args = [$tenantId, $tag];
    if ($exceptAssetId !== null) { $sql .= " AND id <> ?"; $args[] = $exceptAssetId; }
    $stmt = $conn->prepare($sql);
    $stmt->execute($args);
    return (int)$stmt->fetchColumn() === 0;
}

/**
 * The URL a label's QR encodes.
 */
function assetLabelUrl(string $token, ?PDO $conn = null): string {
    if ($conn === null) {
        $conn = connectToDatabase();
    }
    return publicAbsoluteUrl($conn, 'a/' . $token);
}

/**
 * The install's public base URL.
 */
function assetPublicBaseUrl(?PDO $conn = null): string {
    if ($conn === null) {
        $conn = connectToDatabase();
    }
    return publicBaseUrl($conn);
}

/**
 * Catalogue of built-in standard fields printable on asset labels.
 */
function assetLabelStandardFields(): array {
    return [
        'asset_tag'   => 'Asset Tag',
        'hostname'    => 'Hostname',
        'service_tag' => 'Serial / Service Tag',
        'manufacturer'=> 'Manufacturer',
        'model'       => 'Model',
        'company'     => 'Company / Client',
        'location'    => 'Location',
        'asset_type'  => 'Asset Type',
    ];
}

/**
 * Complete catalogue of printable fields (Standard + Custom Fields) for a context.
 *
 * Custom fields are dynamically discovered from `asset_fields` table (is_deleted = 0)
 * and assigned keys prefixed with `cf_` (e.g. `cf_far_id`).
 */
function assetLabelAvailableFields(PDO $conn, ?int $tenantId = null): array {
    $fields = assetLabelStandardFields();
    try {
        $sql = "SELECT field_key, label FROM asset_fields WHERE is_deleted = 0";
        $args = [];
        if ($tenantId !== null && $tenantId > 0 && isMultiTenant($conn)) {
            $sql .= " AND (tenant_id IS NULL OR tenant_id = ?)";
            $args[] = $tenantId;
        }
        $sql .= " ORDER BY label ASC";
        $stmt = $conn->prepare($sql);
        $stmt->execute($args);
        while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $key = 'cf_' . $r['field_key'];
            $fields[$key] = $r['label'] . ' (Custom Field)';
        }
    } catch (Exception $e) { /* table absent fallback */ }
    return $fields;
}

/**
 * Retrieve physical label and QR customisation settings for a company/tenant context.
 *
 * @param PDO $conn
 * @param ?int $tenantId
 * @return array{
 *   title: string,
 *   fields: array<string>,
 *   subtitle_field: string,
 *   footer: string,
 *   logo_enabled: bool,
 *   logo_path: string,
 *   show_field_labels: bool
 * }
 */

/**
 * Concise display labels for physical printed labels.
 */
function assetLabelPrintFields(PDO $conn, ?int $tenantId = null): array {
    $standard = [
        'asset_tag'    => 'Asset Tag',
        'hostname'     => 'Hostname',
        'service_tag'  => 'Serial',
        'manufacturer' => 'Manufacturer',
        'model'        => 'Model',
        'company'      => 'Company',
        'location'     => 'Location',
        'asset_type'   => 'Type',
    ];

    $available = assetLabelAvailableFields($conn, $tenantId);
    $printFields = [];

    foreach ($available as $key => $rawLabel) {
        if (isset($standard[$key])) {
            $printFields[$key] = $standard[$key];
        } else {
            $clean = preg_replace('/\s*\([^)]*Custom Field[^)]*\)/i', '', $rawLabel);
            $printFields[$key] = trim($clean);
        }
    }

    return $printFields;
}

function assetLabelSettings(PDO $conn, ?int $tenantId = null): array {
    $title        = (string)tenantSetting($conn, $tenantId, KEY_LABEL_TITLE, '');
    $rawFields    = (string)tenantSetting($conn, $tenantId, KEY_LABEL_FIELDS, '');
    $legacySub    = (string)tenantSetting($conn, $tenantId, KEY_LABEL_SUBTITLE_FIELD, '');
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
    } elseif ($legacySub !== '' && $legacySub !== 'none') {
        $fields = ['asset_tag', $legacySub];
    } else {
        $fields = ['asset_tag', 'hostname']; // Backward-compatibility default
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
        'subtitle_field'     => $validFields[1] ?? 'hostname',
        'footer'             => $footer,
        'logo_enabled'       => $logoEnabled,
        'logo_path'          => $logoPath,
        'custom_logo_path'   => $customLogoPath,
                'show_field_labels'  => $showFieldLabels,
    ];
}
