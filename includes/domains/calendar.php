<?php
/**
 * Domains — expiry dates as all-day Calendar entries.
 *
 * The same machinery as asset warranties and software renewals, reused rather
 * than copied: awcAdoptExistingCategory / awcEnsureCategory remember the
 * category by id, so an operator can rename "Domain renewals" (the only
 * translation a stored row can have) without the next sync making a second one.
 *
 * Clear-and-rebuild on every sync, under its own `source` marker, so switching
 * the surface off removes the entries rather than leaving the last set behind,
 * and no other kind of generated entry is touched.
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

function domainSyncExpiryCalendar(PDO $conn): array
{
    try {
        if (!awcColumnExists($conn, 'calendar_events', 'source') || !awcColumnExists($conn, 'domains', 'expiry_date')) {
            return ['success' => false, 'error' => 'Schema not ready'];
        }
        $source = 'domain_expiry';
        awcAdoptExistingCategory($conn, $source);                       // BEFORE the delete — see its comment
        $conn->prepare("DELETE FROM calendar_events WHERE source = ?")->execute([$source]);

        $surface = domainSetting($conn, 'domain_expiry_surface');
        if (!in_array($surface, ['calendar', 'both'], true)) return ['success' => true, 'synced' => 0];

        $cat = awcEnsureCategory($conn, $source, 'Domain renewals', '#4d7c0f');
        $rows = $conn->query(
            "SELECT d.id, d.domain_name, d.display_name, d.expiry_date
               FROM domains d
          LEFT JOIN domain_statuses s ON s.id = d.status_id
              WHERE d.expiry_date IS NOT NULL
                AND (s.id IS NULL OR s.alerts_enabled = 1)"
        )->fetchAll(PDO::FETCH_ASSOC);

        $ins = $conn->prepare(
            "INSERT INTO calendar_events (title, description, category_id, start_datetime, end_datetime, all_day, created_by, source)
             VALUES (?, ?, ?, ?, ?, 1, 0, ?)"
        );
        $n = 0;
        foreach ($rows as $r) {
            $dt = substr($r['expiry_date'], 0, 10) . ' 00:00:00';
            $ins->execute([
                'Domain expires: ' . ($r['display_name'] ?: $r['domain_name']),
                'Auto-generated from the Domains register. Change the expiry date on the domain (or renew it and refresh its lookup) to move this.',
                $cat, $dt, $dt, $source,
            ]);
            $n++;
        }
        return ['success' => true, 'synced' => $n];
    } catch (Throwable $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}
