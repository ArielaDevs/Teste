<?php
/**
 * Domains — expiry dates as all-day Calendar entries. Two kinds:
 *
 *   domain_expiry  the registration       "Domain renewals"       domain_expiry_surface
 *   domain_cert    the live certificate   "Certificate renewals"  domain_cert_surface
 *
 * The same machinery as asset warranties and leases, reused rather than copied
 * (see Warranty-and-Lease-Alerts-Developer-Guide on the wiki):
 *
 *  - 🔑 EACH KIND HAS ITS OWN `source` MARKER AND ITS OWN SETTING. Sync clears
 *    and rebuilds one marker at a time, so switching certificates off removes
 *    only certificate entries; registrations, warranties and anything typed by
 *    hand are untouched.
 *
 *  - 🔑 THE CATEGORY KNOWS WHAT IT IS. awcAdoptExistingCategory /
 *    awcEnsureCategory remember it by id (`<source>_category_id`), so an
 *    operator can rename "Certificate renewals" to their own language or
 *    wording - the only translation a stored row can have - and the next sync
 *    files into the renamed one instead of making a second. Adoption runs
 *    BEFORE the delete, because the entries about to be cleared are the only
 *    record of which category they were in.
 *
 *  - Clearing happens even when the kind is switched off: that is what makes
 *    switching it off actually remove the entries.
 *
 * Only domains whose status still wants alerts are drawn: a domain deliberately
 * "Letting lapse" should not keep shouting from the calendar.
 *
 * ⚠️ Calendar entries have no company. On a multi-company install every
 * analyst who can open the Calendar sees these, as they already see contract
 * and warranty dates — the Domains help page says so.
 */

require_once __DIR__ . '/../asset_warranty_calendar.php';
require_once __DIR__ . '/settings.php';

/** Both kinds. The name is kept: every caller already asks for "the calendar". */
function domainSyncExpiryCalendar(PDO $conn): array
{
    try {
        if (!awcColumnExists($conn, 'calendar_events', 'source') || !awcColumnExists($conn, 'domains', 'expiry_date')) {
            return ['success' => false, 'error' => 'Schema not ready'];
        }
        $live = "(s.id IS NULL OR s.alerts_enabled = 1)";
        $n = domainSyncCalendarKind($conn, 'domain_expiry', domainSetting($conn, 'domain_expiry_surface'),
            'Domain renewals', '#4d7c0f', 'expiry_date', 'Domain expires: ',
            'Auto-generated from the Domains register. Change the expiry date on the domain (or renew it and refresh its lookup) to move this.',
            $live);
        // A separate column check: an install that has the expiry column but not
        // the certificate one must keep its renewals (the lease_expiry lesson).
        $c = awcColumnExists($conn, 'domains', 'ssl_expiry_date')
            ? domainSyncCalendarKind($conn, 'domain_cert', domainSetting($conn, 'domain_cert_surface'),
                'Certificate renewals', '#0e7490', 'ssl_expiry_date', 'Certificate expires: ',
                'Auto-generated from the Domains register: the soonest-expiring certificate its checks found. Renewing the certificate and running Check now moves this.',
                $live)
            : 0;
        return ['success' => true, 'synced' => $n, 'synced_certificates' => $c];
    } catch (Throwable $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

/**
 * One kind: adopt its category, clear its entries, and - if its surface says
 * calendar - draw one all-day entry per domain with a date in $column.
 * The column name is interpolated, never bound; it comes only from this file.
 */
function domainSyncCalendarKind(PDO $conn, string $source, string $surface, string $categoryName, string $colour,
                                string $column, string $prefix, string $description, string $live): int
{
    awcAdoptExistingCategory($conn, $source);                       // BEFORE the delete — see its comment
    $conn->prepare("DELETE FROM calendar_events WHERE source = ?")->execute([$source]);
    if (!in_array($surface, ['calendar', 'both'], true)) return 0;

    $cat = awcEnsureCategory($conn, $source, $categoryName, $colour);
    $rows = $conn->query(
        "SELECT d.id, d.domain_name, d.display_name, d.`$column` AS on_date
           FROM domains d
      LEFT JOIN domain_statuses s ON s.id = d.status_id
          WHERE d.`$column` IS NOT NULL AND $live"
    )->fetchAll(PDO::FETCH_ASSOC);

    $ins = $conn->prepare(
        "INSERT INTO calendar_events (title, description, category_id, start_datetime, end_datetime, all_day, created_by, source)
         VALUES (?, ?, ?, ?, ?, 1, 0, ?)"
    );
    $n = 0;
    foreach ($rows as $r) {
        $dt = substr($r['on_date'], 0, 10) . ' 00:00:00';
        $ins->execute([$prefix . ($r['display_name'] ?: $r['domain_name']), $description, $cat, $dt, $dt, $source]);
        $n++;
    }
    return $n;
}
