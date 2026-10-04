<?php
/* 🔴 NEVER OVER THE WEB. See tests/README.md. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * Asset labels (PR #164) - the configurable fields, their names, the QR error
 * correction for a logo, the sheet sizes and the URL a label encodes.
 *
 * Based on Sandy's original suite. READ-ONLY: it changes no setting and no row
 * - a test that rewrote the install's public address would leave every emailed
 * link broken if it crashed half-way.
 *
 * Run: php tests/asset-labels.php
 */

$root = dirname(__DIR__);
require_once "$root/config.php";
require_once "$root/includes/functions.php";
require_once "$root/includes/asset_labels.php";
I18n::setLocale('en');

$pass = 0; $fail = 0;
function ok(string $label, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; printf("  PASS %-70s\n", $label); }
    else       { $fail++; printf("  FAIL %-70s %s\n", $label, $detail); }
}

$conn = connectToDatabase();
echo "\nAsset labels\n" . str_repeat('=', 72) . "\n";

echo "\nFields\n";
$available = assetLabelAvailableFields($conn);
foreach (ASSET_LABEL_STANDARD_FIELDS as $key) {
    ok("'$key' is offered, with a translated name", isset($available[$key]) && $available[$key] !== 'asset-management.labels.field.' . $key, $available[$key] ?? '(missing)');
}
$print = assetLabelPrintFields($conn);
ok('printed names are the short ones', ($print['service_tag'] ?? '') === 'Serial', $print['service_tag'] ?? '');
$custom = assetLabelCustomFields($conn);
if ($custom) {
    $k = array_key_first($custom);
    ok('a custom field is marked as one in the picker', strpos($available[$k], $custom[$k]) === 0 && $available[$k] !== $custom[$k], $available[$k]);
    ok('...and printed under its own name, with no marker', $print[$k] === $custom[$k], $print[$k]);
} else {
    echo "  SKIP  custom-field names: no custom asset fields on this install\n";
}

echo "\nSettings\n";
$s = assetLabelSettings($conn);
ok('the asset tag is always printed, first', ($s['fields'][0] ?? null) === 'asset_tag', json_encode($s['fields']));
ok('only fields that exist survive', array_diff($s['fields'], array_keys($available)) === []);

echo "\nQR and sheets\n";
ok('no logo: error correction M', assetLabelQrEcLevel(false) === 'M');
ok('with a logo: error correction H (survives the logo covering the middle)', assetLabelQrEcLevel(true) === 'H');
ok('the four sheet sizes are unchanged', array_map('strval', array_keys(assetLabelSheetSpecs())) === ['65', '40', '24', '12']);

echo "\nThe URL a label encodes\n";
$url = assetLabelUrl('abc123', $conn);
ok('is built on publicBaseUrl() - the install\'s one public address', $url === publicBaseUrl($conn) . '/a/abc123', $url);
ok('...and is absolute, for a phone that knows nothing of the app', preg_match('#^https?://#', $url) === 1 || publicBaseUrlSetting($conn) === '', $url);
$app = rtrim(BASE_URL, '/');
ok('never repeats the app folder', $app === '' || strpos($url, $app . $app) === false, $url);
ok('a mis-scanned token is rejected before the database is asked', assetIdForToken($conn, "nope'; --") === null);

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail ? 1 : 0);
