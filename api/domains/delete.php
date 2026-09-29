<?php
/**
 * POST {id} or {ids:[…]} — delete one or several domains, with their history,
 * alert ledger, look-alikes and certificates. Each id is scope-checked by the
 * service; one the analyst cannot reach reads as not found.
 */
require_once __DIR__ . '/../../includes/domains/api_bootstrap.php';

domainApiRun(function () use ($conn, $ctx) {
    $in = domainApiBody();
    $ids = !empty($in['ids']) && is_array($in['ids']) ? $in['ids'] : [$in['id'] ?? 0];
    $deleted = 0; $failed = [];
    foreach (array_unique(array_map('intval', $ids)) as $id) {
        if ($id <= 0) continue;
        try { DomainsService::deleteDomain($conn, $ctx, $id); $deleted++; }
        catch (ServiceError $e) { $failed[] = ['id' => $id, 'reason' => $e->getMessage()]; }
    }
    if (!$deleted && $failed) domainApiFail($failed[0]['reason']);
    domainApiOk(['deleted' => $deleted, 'failed' => $failed]);
});
