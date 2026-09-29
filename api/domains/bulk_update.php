<?php
/**
 * POST {ids:[…], fields:{status_id?, purpose?, owner_analyst_id?, renewal_mode?,
 *        registrar_supplier_id?, registrar_account_id?, monitoring_enabled?,
 *        cost_centre?, tags?}} — set the same fields on many domains.
 * Each goes through the service one at a time, with its own history rows.
 */
require_once __DIR__ . '/../../includes/domains/api_bootstrap.php';

domainApiRun(function () use ($conn, $ctx) {
    $in = domainApiBody();
    if (empty($in['ids']) || !is_array($in['ids'])) domainApiFail('Choose at least one domain.');
    if (count($in['ids']) > 1000) domainApiFail('Change at most 1,000 domains at a time.');
    domainApiOk(DomainsService::bulkUpdate($conn, $ctx, $in['ids'], (array)($in['fields'] ?? [])));
});
