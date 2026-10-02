<?php
/**
 * GET ?domain_id=&q= — people who could be this domain's customer (#153): active
 * users in the domain's own company, matching q. A domain the analyst cannot
 * see reads as not found, so the search never reaches into another company.
 */
require_once __DIR__ . '/../../includes/domains/api_bootstrap.php';
require_once __DIR__ . '/../../includes/domains/customer.php';

domainApiRun(function () use ($conn, $analystId) {
    $id = (int)($_GET['domain_id'] ?? 0);
    if ($id <= 0 || !analystCanAccessDomain($conn, $analystId, $id)) domainApiFail('Domain not found.');
    $q = trim((string)($_GET['q'] ?? ''));
    if (mb_strlen($q) < 2) domainApiOk(['people' => []]);
    $st = $conn->prepare("SELECT tenant_id FROM domains WHERE id = ?");
    $st->execute([$id]);
    $tenant = $st->fetchColumn();
    domainApiOk(['people' => domainCustomerSearch($conn, $tenant === null || $tenant === false ? null : (int)$tenant, $q)]);
});
