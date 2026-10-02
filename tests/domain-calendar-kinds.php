<?php
/**
 * Domains on the Calendar (3.0.0): two kinds, two settings, one rule from the
 * wiki's Warranty-and-Lease-Alerts-Developer-Guide -
 *
 *   - each kind clears and rebuilds only its own `source` marker, so switching
 *     certificates off never removes domain renewals (or anything typed);
 *   - the category is remembered by id: rename "Certificate renewals" and the
 *     next sync files into the renamed one instead of making a second.
 *
 * Everything runs in a transaction that is always rolled back.
 *
 * Run: php tests/domain-calendar-kinds.php
 */

$root = dirname(__DIR__);
require_once "$root/config.php";
require_once "$root/includes/functions.php";
require_once "$root/includes/domains/calendar.php";

$pass = 0; $fail = 0;
function ok(string $label, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; printf("  PASS %-66s %s\n", $label, $detail); }
    else       { $fail++; printf("  FAIL %-66s %s\n", $label, $detail); }
}

$conn = connectToDatabase();
$one = fn(string $sql, array $a = []) => (function () use ($conn, $sql, $a) { $s = $conn->prepare($sql); $s->execute($a); return $s->fetchColumn(); })();
$count = fn(string $src) => (int)$one("SELECT COUNT(*) FROM calendar_events WHERE source = ?", [$src]);
$set = function (string $k, string $v) use ($conn) { domainSettingWrite($conn, $k, $v); domainSettings($conn, true); };

$conn->beginTransaction();
try {
    // Make sure at least one live domain has a certificate date to draw.
    $did = (int)$one("SELECT d.id FROM domains d LEFT JOIN domain_statuses s ON s.id = d.status_id WHERE (s.id IS NULL OR s.alerts_enabled = 1) ORDER BY d.id LIMIT 1");
    $conn->prepare("UPDATE domains SET ssl_expiry_date = ? WHERE id = ?")->execute([gmdate('Y-m-d', strtotime('+40 days')), $did]);
    $manual = $conn->prepare("INSERT INTO calendar_events (title, start_datetime, end_datetime, all_day, created_by) VALUES ('typed by hand - test', UTC_TIMESTAMP(), UTC_TIMESTAMP(), 1, 1)");
    $manual->execute();
    $manualId = (int)$conn->lastInsertId();

    echo "\n1. Two kinds, two settings\n";
    $set('domain_expiry_surface', 'both');
    $set('domain_cert_surface', 'both');
    $r = domainSyncExpiryCalendar($conn);
    ok('sync succeeded', !empty($r['success']), json_encode($r));
    $renewals = $count('domain_expiry');
    ok('domain renewals drawn', $renewals > 0, "$renewals");
    ok('certificate renewals drawn', $count('domain_cert') > 0, (string)$count('domain_cert'));
    $catId = (int)$one("SELECT setting_value FROM system_settings WHERE setting_key = 'domain_cert_category_id'");
    ok('the certificate category is remembered by id', $catId > 0 && (int)$one("SELECT COUNT(*) FROM calendar_events WHERE source = 'domain_cert' AND category_id = ?", [$catId]) === $count('domain_cert'));
    ok('...and is not the renewals category', $catId !== (int)$one("SELECT setting_value FROM system_settings WHERE setting_key = 'domain_expiry_category_id'"));

    echo "\n2. Renaming the category does not make a second one\n";
    $conn->prepare("UPDATE calendar_categories SET name = 'Certificados SSL' WHERE id = ?")->execute([$catId]);
    $before = (int)$one("SELECT COUNT(*) FROM calendar_categories");
    domainSyncExpiryCalendar($conn);
    ok('no new category after a rename', (int)$one("SELECT COUNT(*) FROM calendar_categories") === $before);
    ok('entries filed under the renamed one', (int)$one("SELECT COUNT(*) FROM calendar_events WHERE source = 'domain_cert' AND category_id = ?", [$catId]) === $count('domain_cert') && $count('domain_cert') > 0);
    ok('no category with the old name was created', (int)$one("SELECT COUNT(*) FROM calendar_categories WHERE name = 'Certificate renewals'") === 0);

    echo "\n3. A rename from before ids were remembered is adopted\n";
    $conn->prepare("DELETE FROM system_settings WHERE setting_key = 'domain_cert_category_id'")->execute();
    domainSyncExpiryCalendar($conn);
    ok('adopted from where its entries already are', (int)$one("SELECT setting_value FROM system_settings WHERE setting_key = 'domain_cert_category_id'") === $catId);
    ok('...still no second category', (int)$one("SELECT COUNT(*) FROM calendar_categories") === $before);

    echo "\n4. Switching one kind off touches nothing else\n";
    $set('domain_cert_surface', 'dashboard');
    domainSyncExpiryCalendar($conn);
    ok('certificate entries removed', $count('domain_cert') === 0);
    ok('domain renewals untouched', $count('domain_expiry') === $renewals);
    ok('the entry typed by hand untouched', (int)$one("SELECT COUNT(*) FROM calendar_events WHERE id = ?", [$manualId]) === 1);
    $set('domain_cert_surface', 'calendar');
    $set('domain_expiry_surface', 'off');
    domainSyncExpiryCalendar($conn);
    ok('and the other way: renewals off, certificates stay', $count('domain_expiry') === 0 && $count('domain_cert') > 0);
} finally {
    $conn->rollBack();
}

echo "\n" . str_repeat('-', 78) . "\n";
printf("  %d passed, %d failed\n\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
