<?php
/**
 * Report Packs: what a pack is ABOUT - its date range and its company.
 *
 * Every block in a pack reads the same criteria, so "September" means the same
 * thirty days on the doughnut, the uptime list and the incident table. They are
 * set once on the pack (Data tab in the designer) and resolved here, on the server,
 * for whoever is looking - never trusted as SQL from the browser.
 *
 * ⚠️ A range is in the VIEWER'S timezone. "Last month" for an analyst in Santo
 * Domingo is 1 September 00:00 to 1 October 00:00 THERE, which is 04:00 UTC. The
 * database stores UTC (#126), so the bounds are converted before any query, and a
 * day near midnight lands in the month the reader would expect.
 */

require_once __DIR__ . '/../tenancy.php';
require_once __DIR__ . '/../timezone.php';

/** Presets offered in the designer. 'custom' takes from/to as Y-m-d. */
const RP_RANGE_PRESETS = [
    'today', 'yesterday', 'this_week', 'last_week', 'this_month', 'last_month',
    'this_quarter', 'last_quarter', 'this_year', 'last_year',
    'last_7_days', 'last_30_days', 'last_90_days', 'last_12_months', 'custom',
];

/**
 * Resolve a pack's range into local dates (for display) and UTC bounds (for SQL).
 * The end is EXCLUSIVE: [from, to). A custom range is inclusive of both days the
 * reader picked, so its end is the day after.
 *
 * @return array{preset:string,from_date:string,to_date:string,from_utc:string,to_utc:string,days:int,tz:string}
 */
function rpResolveRange(array $range, ?string $tz = null): array
{
    $tz = $tz ?: (class_exists('Tz') ? Tz::current() : 'UTC');
    try { $zone = new DateTimeZone($tz); } catch (Exception $e) { $zone = new DateTimeZone('UTC'); $tz = 'UTC'; }

    $preset = in_array($range['preset'] ?? '', RP_RANGE_PRESETS, true) ? $range['preset'] : 'last_month';
    $today  = new DateTimeImmutable('today', $zone);

    switch ($preset) {
        case 'today':        $from = $today;                                   $to = $today->modify('+1 day'); break;
        case 'yesterday':    $from = $today->modify('-1 day');                 $to = $today; break;
        case 'this_week':    $from = $today->modify('monday this week');       $to = $from->modify('+7 days'); break;
        case 'last_week':    $from = $today->modify('monday this week')->modify('-7 days'); $to = $from->modify('+7 days'); break;
        case 'this_month':   $from = $today->modify('first day of this month'); $to = $from->modify('+1 month'); break;
        case 'last_month':   $from = $today->modify('first day of last month'); $to = $from->modify('+1 month'); break;
        case 'this_quarter':
        case 'last_quarter':
            $q = intdiv((int)$today->format('n') - 1, 3);
            $from = $today->setDate((int)$today->format('Y'), $q * 3 + 1, 1);
            if ($preset === 'last_quarter') { $from = $from->modify('-3 months'); }
            $to = $from->modify('+3 months');
            break;
        case 'this_year':    $from = $today->setDate((int)$today->format('Y'), 1, 1);     $to = $from->modify('+1 year'); break;
        case 'last_year':    $from = $today->setDate((int)$today->format('Y') - 1, 1, 1); $to = $from->modify('+1 year'); break;
        case 'last_7_days':  $from = $today->modify('-6 days');   $to = $today->modify('+1 day'); break;
        case 'last_30_days': $from = $today->modify('-29 days');  $to = $today->modify('+1 day'); break;
        case 'last_90_days': $from = $today->modify('-89 days');  $to = $today->modify('+1 day'); break;
        case 'last_12_months': $from = $today->modify('first day of this month')->modify('-11 months'); $to = $today->modify('+1 day'); break;
        case 'custom':
        default:
            $f = rpParseDate($range['from'] ?? '', $zone) ?? $today->modify('first day of last month');
            $t = rpParseDate($range['to'] ?? '', $zone)   ?? $f->modify('last day of this month');
            if ($t < $f) { [$f, $t] = [$t, $f]; }
            // A report is not a data export: cap it so a typo cannot ask for 400 years.
            if ($f->diff($t)->days > 3660) { $t = $f->modify('+3660 days'); }
            $from = $f; $to = $t->modify('+1 day');
            break;
    }

    $utc = new DateTimeZone('UTC');
    return [
        'preset'    => $preset,
        'from_date' => $from->format('Y-m-d'),
        'to_date'   => $to->modify('-1 day')->format('Y-m-d'),   // inclusive, for display
        'from_utc'  => $from->setTimezone($utc)->format('Y-m-d H:i:s'),
        'to_utc'    => $to->setTimezone($utc)->format('Y-m-d H:i:s'),
        'days'      => max(1, (int)$from->diff($to)->days),
        'tz'        => $tz,
    ];
}

function rpParseDate($s, DateTimeZone $zone): ?DateTimeImmutable
{
    if (!is_string($s) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) return null;
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', $s, $zone);
    return $d ?: null;
}

/**
 * The company a pack reports on, as a WHERE fragment for `$qualified`.
 *
 *   'active'  - whatever company the viewer is working in right now (the default,
 *               and what every dashboard does)
 *   'all'     - every company the viewer may see
 *   <id>      - one company, which the VIEWER must be able to access
 *
 * A shared pack is read with the viewer's rights, not the author's: if the author
 * chose a company the viewer cannot see, the viewer gets an error, never the data.
 *
 * @return array{0:string,1:array} [" AND ...", params], or ['', []] at N=1
 * @throws RuntimeException when the viewer may not see the chosen company
 */
function rpTenantClause(PDO $conn, int $analystId, $tenant, string $qualified): array
{
    if (!isMultiTenant($conn)) {
        return ['', []];
    }
    if ($tenant === 'all') {
        return allAccessibleTenantsFilter($conn, $analystId, $qualified);
    }
    if ($tenant === null || $tenant === '' || $tenant === 'active') {
        $id = getActiveTenantId($conn, $analystId);
    } else {
        $id = (int)$tenant;
        if ($id <= 0 || !analystCanAccessTenant($conn, $analystId, $id)) {
            throw new RuntimeException('This report is set to a company you do not have access to.');
        }
    }
    // NULL-company rows belong to the Default company, same rule as every list.
    if ($id === getDefaultTenantId($conn)) {
        return [" AND ($qualified = ? OR $qualified IS NULL)", [$id]];
    }
    return [" AND $qualified = ?", [$id]];
}

/** The company's name for the header field {{company}}. */
function rpTenantLabel(PDO $conn, int $analystId, $tenant): string
{
    if (!isMultiTenant($conn)) return '';
    if ($tenant === 'all') return 'All companies';
    $id = ($tenant === null || $tenant === '' || $tenant === 'active') ? getActiveTenantId($conn, $analystId) : (int)$tenant;
    foreach (getAllTenants($conn) as $t) {
        if ((int)$t['id'] === $id) return (string)$t['name'];
    }
    return '';
}
