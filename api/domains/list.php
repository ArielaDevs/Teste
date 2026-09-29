<?php
/**
 * GET — the domain register, scoped to the analyst's company (or every company
 * they can see, in the "All companies" view). Optional filters: q, status_id,
 * purpose, owner_analyst_id, registrar_supplier_id, tag, expiring_days.
 * Also returns the lookup lists the register's filters and bulk editor use.
 */
require_once __DIR__ . '/../../includes/domains/api_bootstrap.php';
require_once __DIR__ . '/../../includes/domains/read.php';

domainApiRun(function () use ($conn, $analystId) {
    $rows = domainListRows($conn, $analystId, $_GET);

    $lookups = [];
    if (!empty($_GET['with_lookups'])) {
        $lookups = domainApiLookups($conn, $analystId);
    }
    domainApiOk(['domains' => $rows, 'lookups' => $lookups, 'multi_company' => isMultiTenant($conn)]);
});
