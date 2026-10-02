<?php
/**
 * Report Packs: the values of the header and footer fields for these criteria -
 * {date_from}, {date_to}, {today}, {company} - formatted the viewer's way.
 * POST {criteria}
 */
session_start(['read_and_close' => true]);
require_once __DIR__ . '/../../../includes/report_packs/api_common.php';
if (!isset($_SESSION['analyst_id'])) rpFail('Not authenticated', 401);
requireModuleAccessJson('reporting');

try {
    $conn = connectToDatabase();
    rpInitLocale($conn);
    $aid = (int)$_SESSION['analyst_id'];
    $cr  = rpInput()['criteria'] ?? [];
    $cr  = is_array($cr) ? $cr : [];
    $range  = rpResolveRange(is_array($cr['range'] ?? null) ? $cr['range'] : []);
    $tenant = $cr['tenant'] ?? 'active';

    $companyError = null;
    try {
        rpTenantClause($conn, $aid, $tenant, 'x.tenant_id');   // refuses a company the viewer cannot see
        $company = rpTenantLabel($conn, $aid, $tenant);
    } catch (RuntimeException $e) {
        $company = '';
        $companyError = $e->getMessage();
    }
    rpOut(['success' => true, 'fields' => [
        'date_from' => rpFmtDate($range['from_date']),
        'date_to'   => rpFmtDate($range['to_date']),
        'today'     => rpFmtDate((new DateTimeImmutable('now', new DateTimeZone($range['tz'])))->format('Y-m-d')),
        'company'   => $company,
    ], 'range' => $range, 'company_error' => $companyError]);
} catch (Throwable $e) {
    error_log('report packs context: ' . $e->getMessage());
    rpFail(t('reporting.packs.err.load'), 500);
}
