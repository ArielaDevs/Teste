<?php
/**
 * GET — the lists the Domains forms draw from: statuses, analysts, registrars,
 * registrar accounts (scoped), purposes, renewal modes, companies, and — for
 * analysts who can open Contracts — contracts and contacts.
 */
require_once __DIR__ . '/../../includes/domains/api_bootstrap.php';
require_once __DIR__ . '/../../includes/domains/read.php';

domainApiRun(function () use ($conn, $analystId) {
    domainApiOk(['lookups' => domainApiLookups($conn, $analystId), 'multi_company' => isMultiTenant($conn)]);
});
