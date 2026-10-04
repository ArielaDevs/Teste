<?php
/* 🔴 NEVER OVER THE WEB. See tests/README.md. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * Domains joined to CMDB, Service Status, Tickets, Knowledge and Contracts
 * (3.0.0) - the link rules in includes/domains/links.php.
 *
 * Read-only in effect: everything runs inside a transaction that is always
 * rolled back. Uses real records of each kind from the install.
 *
 * Run: php tests/domain-links.php
 */

$root = dirname(__DIR__);
require_once "$root/config.php";
require_once "$root/includes/functions.php";
require_once "$root/includes/domains/links.php";

$pass = 0; $fail = 0; $skip = 0;
function ok(string $label, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; printf("  PASS %-66s %s\n", $label, $detail); }
    else       { $fail++; printf("  FAIL %-66s %s\n", $label, $detail); }
}
function skip(string $label): void { global $skip; $skip++; printf("  SKIP %s\n", $label); }

$conn = connectToDatabase();
if (!domainLinksReady($conn)) { echo "Run Database Verification first.\n"; exit(1); }
$admin = 1;
$default = (int)getDefaultTenantId($conn);
$multi = isMultiTenant($conn);
$one = fn(string $sql, array $a = []) => (function () use ($conn, $sql, $a) { $s = $conn->prepare($sql); $s->execute($a); return $s->fetchColumn(); })();

$domain = (int)$one("SELECT id FROM domains ORDER BY id LIMIT 1");
if (!$domain) { echo "No domains on this install.\n"; exit(1); }
$dTenant = (int)($one("SELECT COALESCE(tenant_id, ?) FROM domains WHERE id = ?", [$default, $domain]));

$linkCount = "SELECT (SELECT COUNT(*) FROM domain_cmdb_objects) + (SELECT COUNT(*) FROM domain_status_services) + (SELECT COUNT(*) FROM ticket_domains) + (SELECT COUNT(*) FROM domain_knowledge_articles)";
$linksBefore = (int)$one($linkCount);
$conn->beginTransaction();
try {
    echo "\n1. Each kind: add, list both ways, search, remove\n";
    $targets = [
        'cmdb'    => (int)$one("SELECT id FROM cmdb_objects WHERE COALESCE(tenant_id, ?) = ? ORDER BY id LIMIT 1", [$default, $dTenant]),
        'ticket'  => (int)$one("SELECT id FROM tickets WHERE deleted_datetime IS NULL AND COALESCE(tenant_id, ?) = ? ORDER BY id DESC LIMIT 1", [$default, $dTenant]),
        'service' => (int)$one("SELECT id FROM status_services ORDER BY id LIMIT 1"),
        'article' => (int)$one("SELECT id FROM knowledge_articles WHERE (is_archived = 0 OR is_archived IS NULL) AND is_published = 1 ORDER BY id LIMIT 1"),
    ];
    foreach ($targets as $kind => $tid) {
        if (!$tid) { skip("$kind: no record of this kind in the domain's company"); continue; }
        $added = domainLinkAdd($conn, $admin, $domain, $kind, $tid);
        ok("$kind: added", $added);
        ok("$kind: adding again is a no-op, not an error", domainLinkAdd($conn, $admin, $domain, $kind, $tid) === false);
        $ids = array_column(domainLinks($conn, $admin, $domain)[$kind] ?? [], 'id');
        ok("$kind: listed on the domain", in_array($tid, $ids, true));
        ok("$kind: the domain listed on the other side", in_array($domain, array_column(domainsLinkedTo($conn, $admin, $kind, $tid), 'id'), true));
        ok("$kind: no longer offered by the domain's search", !in_array($tid, array_column(domainLinkSearch($conn, $admin, $domain, $kind, ''), 'id'), true));
        ok("$kind: no longer offered by the other side's picker", !in_array($domain, array_column(domainLinkPickDomains($conn, $admin, $kind, $tid, ''), 'id'), true));
        domainLinkRemove($conn, $admin, $domain, $kind, $tid);
        ok("$kind: removed", !in_array($tid, array_column(domainLinks($conn, $admin, $domain)[$kind] ?? [], 'id'), true));
        ok("$kind: offered by the domain's search again (positive control)", in_array($tid, array_column(domainLinkSearch($conn, $admin, $domain, $kind, ''), 'id'), true)
            || count(domainLinkSearch($conn, $admin, $domain, $kind, '')) === 20);
    }

    echo "\n2. A link never crosses companies\n";
    if (!$multi) {
        skip('single-company install');
    } else {
        foreach (['cmdb' => 'cmdb_objects', 'ticket' => 'tickets'] as $kind => $table) {
            $extra = $kind === 'ticket' ? ' AND deleted_datetime IS NULL' : '';
            $other = (int)$one("SELECT id FROM $table WHERE COALESCE(tenant_id, ?) <> ?$extra ORDER BY id LIMIT 1", [$default, $dTenant]);
            if (!$other) { skip("$kind: nothing in another company"); continue; }
            $refused = false;
            try { domainLinkAdd($conn, $admin, $domain, $kind, $other); } catch (ServiceError $e) { $refused = true; }
            ok("$kind in another company: refused", $refused);
            ok("$kind in another company: never offered by the search",
               !in_array($other, array_column(domainLinkSearch($conn, $admin, $domain, $kind, ''), 'id'), true));
        }
    }

    echo "\n3. Bad input\n";
    $refused = false;
    try { domainLinkAdd($conn, $admin, $domain, 'nonsense', 1); } catch (ServiceError $e) { $refused = true; }
    ok('an unknown kind is refused', $refused);
    $refused = false;
    try { domainLinkAdd($conn, $admin, 999999999, 'service', $targets['service'] ?: 1); } catch (ServiceError $e) { $refused = true; }
    ok('a domain that does not exist is refused', $refused);
    $refused = false;
    try { domainLinkAdd($conn, $admin, $domain, 'cmdb', 999999999); } catch (ServiceError $e) { $refused = true; }
    ok('a record that does not exist is refused', $refused);

    echo "\n4. Contracts (domains.contract_id)\n";
    $contract = (int)$one("SELECT id FROM contracts ORDER BY id LIMIT 1");
    if (!$contract) {
        skip('no contracts');
    } else {
        $conn->prepare("UPDATE domains SET contract_id = ? WHERE id = ?")->execute([$contract, $domain]);
        ok('a domain under a contract is listed on it', in_array($domain, array_column(domainsForContract($conn, $admin, $contract), 'id'), true));
        $row = array_values(array_filter(domainsForContract($conn, $admin, $contract), fn($r) => $r['id'] === $domain))[0] ?? [];
        ok('each row carries what the contract page shows', array_key_exists('days_left', $row) && array_key_exists('cost', $row) && isset($row['url']));
    }
} finally {
    $conn->rollBack();
}
$left = (int)$one($linkCount) - $linksBefore;
ok('rolled back: the same links as before (real ones kept, none added)', $left === 0, "$left");

echo "\n" . str_repeat('-', 78) . "\n";
printf("  %d passed, %d failed, %d skipped\n\n", $pass, $fail, $skip);
exit($fail === 0 ? 0 : 1);
