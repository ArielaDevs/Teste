<?php
/**
 * Registrar accounts — the logins at registrars that hold the domains.
 *
 * GET              the accounts in the analyst's company (or all they can see),
 *                  with how many domains each holds
 * POST save        {id?, supplier_id, account_name, account_reference, login_url,
 *                   owner_analyst_id, two_factor_holder, notes, company_id?}
 * POST delete      {id} — its domains are unlinked, never deleted
 *
 * Operational data, not settings: anyone in the module maintains them, the way
 * anyone in Contracts maintains suppliers. No password is ever stored.
 */
require_once __DIR__ . '/../../includes/domains/api_bootstrap.php';

domainApiRun(function () use ($conn, $ctx, $analystId) {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        [$tSql, $tArgs] = activeTenantReadFilter($conn, $analystId, 'acc');
        $st = $conn->prepare(
            "SELECT acc.id, acc.tenant_id, tn.name AS company_name, acc.supplier_id,
                    COALESCE(NULLIF(s.trading_name, ''), s.legal_name) AS supplier_name,
                    acc.account_name, acc.account_reference, acc.login_url, acc.owner_analyst_id,
                    a.full_name AS owner_name, acc.two_factor_holder, acc.notes, acc.created_datetime,
                    (SELECT COUNT(*) FROM domains d WHERE d.registrar_account_id = acc.id) AS domain_count,
                    (SELECT MIN(d.expiry_date) FROM domains d WHERE d.registrar_account_id = acc.id AND d.expiry_date >= UTC_DATE()) AS next_expiry
               FROM domain_registrar_accounts acc
          LEFT JOIN suppliers s ON s.id = acc.supplier_id
          LEFT JOIN analysts a ON a.id = acc.owner_analyst_id
          LEFT JOIN tenants tn ON tn.id = acc.tenant_id
              WHERE 1=1 $tSql
           ORDER BY supplier_name, acc.account_name"
        );
        $st->execute($tArgs);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$r) foreach (['id', 'tenant_id', 'supplier_id', 'owner_analyst_id', 'domain_count'] as $k) $r[$k] = $r[$k] === null ? null : (int)$r[$k];
        domainApiOk(['accounts' => $rows]);
    }
    $in = domainApiBody();
    if (($in['action'] ?? '') === 'delete') {
        DomainsService::deleteRegistrarAccount($conn, $ctx, (int)($in['id'] ?? 0));
        domainApiOk();
    }
    $tenant = empty($in['id']) ? domainApiTenantForCreate($conn, $analystId, $in['company_id'] ?? null) : null;
    $res = DomainsService::saveRegistrarAccount($conn, $ctx, $in, $tenant);
    domainApiOk(['id' => $res['id']]);
});
