<?php
/**
 * Report Packs: what the designer can offer this analyst - the toolbox, every
 * handler's option schema, the companies they can report on and the date presets.
 * GET
 */
session_start(['read_and_close' => true]);
require_once __DIR__ . '/../../../includes/report_packs/api_common.php';
if (!isset($_SESSION['analyst_id'])) rpFail('Not authenticated', 401);
requireModuleAccessJson('reporting');

try {
    $conn = connectToDatabase();
    rpInitLocale($conn);
    $aid = (int)$_SESSION['analyst_id'];

    $companies = [];
    if (isMultiTenant($conn)) {
        $ids = getAccessibleTenantIds($conn, $aid);
        foreach (getAllTenants($conn) as $t) {
            if (in_array((int)$t['id'], $ids, true)) $companies[] = ['id' => (int)$t['id'], 'name' => $t['name']];
        }
    }
    $presets = [];
    foreach (RP_RANGE_PRESETS as $p) $presets[] = ['value' => $p, 'label' => t('reporting.packs.range.' . $p)];

    rpOut([
        'success'       => true,
        'catalogue'     => rpCatalogue($conn, $aid),
        'companies'     => $companies,
        'multi_company' => isMultiTenant($conn),
        'presets'       => $presets,
    ]);
} catch (Throwable $e) {
    error_log('report packs catalogue: ' . $e->getMessage());
    rpFail(t('reporting.packs.err.load'), 500);
}
