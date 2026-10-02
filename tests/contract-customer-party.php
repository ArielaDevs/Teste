<?php
/* 🔴 NEVER OVER THE WEB. See tests/README.md. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * Contracts with a CUSTOMER, and a domain's customer person (#153).
 *
 * A contract is with a supplier (you buy) or a customer (you sell). A customer
 * contract belongs to its customer's company, and by default an analyst who
 * cannot see that company cannot see the contract - anywhere. This file checks
 * the rule where it is applied, with positive controls so a check that hides
 * EVERYTHING cannot pass as one that hides the right thing.
 *
 * ⚠️ It needs a restriction to test, and on most installs nobody is restricted.
 * Everything runs inside a transaction that is ALWAYS rolled back: the analyst's
 * all-companies flag is cleared and one company granted, contracts and settings
 * are written, and none of it survives the run.
 *
 * Run:  php tests/contract-customer-party.php
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/i18n.php';
require_once __DIR__ . '/../includes/tenancy.php';
require_once __DIR__ . '/../includes/services/contracts.php';
require_once __DIR__ . '/../includes/services/domains.php';
require_once __DIR__ . '/../includes/contract_party.php';
require_once __DIR__ . '/../includes/record_preview.php';
require_once __DIR__ . '/../includes/documents.php';
require_once __DIR__ . '/../includes/domains/customer.php';
I18n::initFromSession();

$pass = 0; $fail = 0;
function ok(string $what, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  PASS  {$what}\n"; }
    else       { $fail++; echo "  FAIL  {$what}" . ($detail ? "  <- {$detail}" : '') . "\n"; }
}
function serviceError(callable $fn): ?string {
    try { $fn(); return null; } catch (ServiceError $e) { return $e->kind . ': ' . $e->getMessage(); }
}

echo "\nContracts with a customer (#153)\n" . str_repeat('=', 70) . "\n";

$conn = connectToDatabase();
if (!contractPartyReady($conn) || !domainCustomerReady($conn)) { echo "  SKIP  run Database Verification first\n"; exit(0); }
if (!isMultiTenant($conn)) { echo "  SKIP  needs two or more companies\n"; exit(0); }

$tenants = array_map('intval', $conn->query("SELECT id FROM tenants WHERE is_active = 1 ORDER BY id")->fetchAll(PDO::FETCH_COLUMN));
$default = getDefaultTenantId($conn);
$others  = array_values(array_diff($tenants, [$default]));
if (count($others) < 2) { echo "  SKIP  needs two companies besides Default\n"; exit(0); }
[$mine, $theirs] = $others;

// ⚠️ Not an admin, and chosen BEFORE anything asks about their companies:
// getAccessibleTenantIds() caches per analyst for the life of the process.
// And one who can open Contracts, or the positive controls would fail for the wrong reason.
$r = 0;
foreach ($conn->query("SELECT id FROM analysts WHERE is_active = 1 AND (is_admin = 0 OR is_admin IS NULL) ORDER BY id")->fetchAll(PDO::FETCH_COLUMN) as $cand) {
    if (analystCanAccessModule($conn, (int)$cand, 'contracts')) { $r = (int)$cand; break; }
}
$admin = 1;
if (!$r) { echo "  SKIP  needs a non-admin analyst\n"; exit(0); }

$conn->beginTransaction();
try {
    $conn->prepare("UPDATE analysts SET can_access_all_tenants = 0 WHERE id = ?")->execute([$r]);
    $conn->prepare("DELETE FROM analyst_tenant_access WHERE analyst_id = ?")->execute([$r]);
    $conn->prepare("DELETE FROM analyst_teams WHERE analyst_id = ?")->execute([$r]);
    $conn->prepare("INSERT INTO analyst_tenant_access (analyst_id, tenant_id) VALUES (?, ?)")->execute([$r, $mine]);
    $conn->prepare("DELETE FROM system_settings WHERE setting_key = ?")->execute([CONTRACT_CUSTOMER_VISIBILITY_KEY]);
    contractCustomerVisibility($conn, true);

    ok("analyst {$r} is limited to company {$mine}", getAccessibleTenantIds($conn, $r) === [$mine],
       json_encode(getAccessibleTenantIds($conn, $r)));

    $adminCtx = new ActorContext(actorId: $admin, companyScope: null, source: 'ui');
    $rCtx     = new ActorContext(actorId: $r, companyScope: [$mine], source: 'ui');

    // People to be customers: one in each company, one with no company (= Default).
    $mkUser = function (?int $tenant, string $name) use ($conn): int {
        $conn->prepare("INSERT INTO users (email, display_name, tenant_id, is_active) VALUES (?, ?, ?, 1)")
             ->execute([strtolower(str_replace(' ', '.', $name)) . '.153test@example.invalid', $name, $tenant]);
        return (int)$conn->lastInsertId();
    };
    $uMine   = $mkUser($mine, 'Test Mine Person');
    $uTheirs = $mkUser($theirs, 'Test Theirs Person');
    $uNone   = $mkUser(null, 'Test Default Person');

    $save = fn(ActorContext $c, array $in) => ContractsService::saveContract($conn, $c, $in + ['contract_number' => 'T153-' . bin2hex(random_bytes(3)), 'title' => 'Customer party test'])['id'];

    // ── 1. Choosing a customer ───────────────────────────────────────────────
    echo "\nChoosing a customer:\n";
    ok('a customer contract needs a company or a person',
       ($e = serviceError(fn() => $save($adminCtx, ['party_type' => 'customer']))) !== null && str_starts_with($e, 'validation'), (string)$e);
    ok('a person must be in the company chosen',
       ($e = serviceError(fn() => $save($adminCtx, ['party_type' => 'customer', 'customer_tenant_id' => $mine, 'customer_user_id' => $uTheirs]))) !== null, (string)$e);
    ok('an analyst cannot name a company they cannot see',
       ($e = serviceError(fn() => $save($rCtx, ['party_type' => 'customer', 'customer_tenant_id' => $theirs]))) !== null, (string)$e);
    ok('...nor a person in one',
       ($e = serviceError(fn() => $save($rCtx, ['party_type' => 'customer', 'customer_user_id' => $uTheirs]))) !== null, (string)$e);
    ok('an unknown party type is refused',
       serviceError(fn() => $save($adminCtx, ['party_type' => 'vendor', 'customer_tenant_id' => $mine])) !== null);

    $cTheirs   = $save($adminCtx, ['party_type' => 'customer', 'customer_tenant_id' => $theirs, 'customer_user_id' => $uTheirs, 'contract_end' => gmdate('Y-m-d', strtotime('+10 days'))]);
    $cMine     = $save($rCtx,     ['party_type' => 'customer', 'customer_tenant_id' => $mine, 'customer_user_id' => $uMine]);
    $cPerson   = $save($adminCtx, ['party_type' => 'customer', 'customer_user_id' => $uTheirs]);   // company from the person
    $cDefault  = $save($adminCtx, ['party_type' => 'customer', 'customer_user_id' => $uNone]);     // no company = Default
    $cSupplier = $save($adminCtx, ['party_type' => 'supplier']);
    ok('POSITIVE CONTROL: the restricted analyst can add one for their own company', $cMine > 0);

    $row = $conn->query("SELECT party_type, supplier_id, customer_tenant_id, customer_user_id FROM contracts WHERE id = $cMine")->fetch(PDO::FETCH_ASSOC);
    ok('it is stored as a customer contract, with no supplier',
       $row['party_type'] === 'customer' && $row['supplier_id'] === null && (int)$row['customer_tenant_id'] === $mine && (int)$row['customer_user_id'] === $uMine, json_encode($row));

    ContractsService::saveContract($conn, $adminCtx, ['id' => $cTheirs, 'contract_number' => 'T153-keep', 'title' => 'Renamed only']);
    $row = $conn->query("SELECT party_type, customer_tenant_id FROM contracts WHERE id = $cTheirs")->fetch(PDO::FETCH_ASSOC);
    ok('an edit that does not mention the customer keeps it', $row['party_type'] === 'customer' && (int)$row['customer_tenant_id'] === $theirs, json_encode($row));

    // ── 2. Who sees what ─────────────────────────────────────────────────────
    echo "\nWho sees which contract (default setting: by company):\n";
    $sees = fn(int $a, int $id) => contractCanView($conn, $a, $id);
    ok('admin sees the other company\'s customer contract (POSITIVE CONTROL)', $sees($admin, $cTheirs));
    ok('restricted analyst does NOT see it', !$sees($r, $cTheirs));
    ok('restricted analyst does NOT see one whose company comes from the person', !$sees($r, $cPerson));
    ok('restricted analyst does NOT see one for a Default-company person', !$sees($r, $cDefault));
    ok('restricted analyst sees their own company\'s (POSITIVE CONTROL)', $sees($r, $cMine));
    ok('restricted analyst sees a supplier contract (POSITIVE CONTROL)', $sees($r, $cSupplier));

    [$vis, $args] = contractVisibilitySql($conn, $r, 'c');
    $ids = $conn->prepare("SELECT c.id FROM contracts c WHERE c.id IN (?, ?, ?, ?, ?)$vis");
    $ids->execute(array_merge([$cTheirs, $cMine, $cPerson, $cDefault, $cSupplier], $args));
    $got = array_map('intval', $ids->fetchAll(PDO::FETCH_COLUMN)); sort($got);
    $want = [$cMine, $cSupplier]; sort($want);
    ok('the list filter returns exactly their own customer contract and the supplier one', $got === $want, json_encode($got));

    echo "\nEverywhere else a contract can be read:\n";
    ok('preview: hidden', recordPreview($conn, $r, 'contract', $cTheirs) === null);
    ok('preview: POSITIVE CONTROL', recordPreview($conn, $r, 'contract', $cMine) !== null);
    $def = documentEntityDef('contract');
    ok('documents: hidden', !($def['can'])($conn, $r, $cTheirs));
    ok('documents: POSITIVE CONTROL', ($def['can'])($conn, $r, $cMine));
    require_once __DIR__ . '/../includes/contract_report.php';
    ok('equipment report: hidden', contractReportLoad($conn, $cTheirs, $r) === null);
    ok('equipment report: POSITIVE CONTROL', contractReportLoad($conn, $cMine, $r) !== null);
    require_once __DIR__ . '/../includes/contract_assets.php';
    $asset = (int)$conn->query("SELECT id FROM assets ORDER BY id LIMIT 1")->fetchColumn();
    if ($asset) {
        $linkErr = null;
        try { contractAssetLink($conn, $r, $cTheirs, $asset, null); } catch (RuntimeException $e) { $linkErr = $e->getMessage(); }
        ok('an asset cannot be linked to a hidden contract', $linkErr !== null);
    }
    ok('update of a hidden contract is "not found"', str_starts_with((string)serviceError(fn() => ContractsService::saveContract($conn, $rCtx, ['id' => $cTheirs, 'title' => 'x'])), 'not_found'));
    ok('delete of a hidden contract is "not found"', str_starts_with((string)serviceError(fn() => ContractsService::deleteContract($conn, $rCtx, $cTheirs)), 'not_found'));
    ok('POSITIVE CONTROL: they can update their own', serviceError(fn() => ContractsService::saveContract($conn, $rCtx, ['id' => $cMine, 'title' => 'Mine renamed'])) === null);

    require_once __DIR__ . '/../api/v1/lib/response.php';
    require_once __DIR__ . '/../api/v1/resources/contracts.php';
    [$apiVis, $apiArgs] = apiContractVisibility($conn, ['company_scope' => [$mine]]);
    $s = $conn->prepare(apiContractSelect($conn) . " WHERE c.id = ?$apiVis");
    $s->execute(array_merge([$cTheirs], $apiArgs));
    ok('API: a key scoped to one company does not get the other\'s', $s->fetch() === false);
    $s = $conn->prepare(apiContractSelect($conn) . " WHERE c.id = ?$apiVis");
    $s->execute(array_merge([$cMine], $apiArgs));
    $one = $s->fetch(PDO::FETCH_ASSOC);
    $ser = $one ? apiSerializeContract($one) : null;
    ok('API: POSITIVE CONTROL, and it serialises the customer',
       $ser && $ser['party_type'] === 'customer' && $ser['customer']['company']['id'] === $mine && $ser['customer']['person']['id'] === $uMine, json_encode($ser['customer'] ?? null));

    require_once __DIR__ . '/../includes/watchtower_queries.php';
    $wtAdmin = getWatchtowerData($conn, $admin)['contracts']['expiring_30d'] ?? null;
    $wtR     = getWatchtowerData($conn, $r)['contracts']['expiring_30d'] ?? null;
    ok('Watchtower: the hidden contract ending in 10 days is not counted for them', $wtAdmin !== null && $wtR === $wtAdmin - 1, "admin {$wtAdmin}, analyst {$wtR}");

    // ── 3. The setting ───────────────────────────────────────────────────────
    echo "\nSetting \"everyone with access to Contracts\":\n";
    $conn->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (?, 'all')")->execute([CONTRACT_CUSTOMER_VISIBILITY_KEY]);
    contractCustomerVisibility($conn, true);
    ok('now the restricted analyst sees the other company\'s', $sees($r, $cTheirs));
    $conn->prepare("DELETE FROM system_settings WHERE setting_key = ?")->execute([CONTRACT_CUSTOMER_VISIBILITY_KEY]);
    contractCustomerVisibility($conn, true);
    ok('and back to hidden once the setting goes', !$sees($r, $cTheirs));

    // ── 4. A domain's customer person ────────────────────────────────────────
    echo "\nA domain's customer person:\n";
    ok('a person in the domain\'s company is allowed', domainCustomerPersonOk($conn, $uMine, $mine));
    ok('a person in another company is not', !domainCustomerPersonOk($conn, $uTheirs, $mine));
    ok('a no-company person fits a Default-company domain', domainCustomerPersonOk($conn, $uNone, null) && domainCustomerPersonOk($conn, $uNone, $default));
    ok('...and no other', !domainCustomerPersonOk($conn, $uNone, $mine));
    $found = array_column(domainCustomerSearch($conn, $mine, 'Test '), 'id');
    ok('the picker only offers people in the domain\'s company', in_array($uMine, $found, true) && !in_array($uTheirs, $found, true) && !in_array($uNone, $found, true), json_encode($found));

    $dom = DomainsService::createDomain($conn, $adminCtx, ['domain_name' => 't153-' . bin2hex(random_bytes(3)) . '.invalid'], $mine);
    ok('a domain cannot be given someone from another company',
       ($e = serviceError(fn() => DomainsService::updateDomain($conn, $adminCtx, $dom, ['customer_user_id' => $uTheirs]))) !== null && str_starts_with($e, 'validation'), (string)$e);
    ok('POSITIVE CONTROL: someone from its own company is saved',
       serviceError(fn() => DomainsService::updateDomain($conn, $adminCtx, $dom, ['customer_user_id' => $uMine])) === null
       && (int)$conn->query("SELECT customer_user_id FROM domains WHERE id = $dom")->fetchColumn() === $uMine);
    ok('a domain cannot be linked to a contract its editor cannot see',
       serviceError(fn() => DomainsService::updateDomain($conn, $rCtx, $dom, ['contract_id' => $cTheirs])) !== null);
    ok('POSITIVE CONTROL: it can be linked to one they can',
       serviceError(fn() => DomainsService::updateDomain($conn, $rCtx, $dom, ['contract_id' => $cMine])) === null);
} catch (Throwable $e) {
    ok('the run completed', false, get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
} finally {
    if ($conn->inTransaction()) $conn->rollBack();
}

$left = (int)$conn->query("SELECT COUNT(*) FROM users WHERE email LIKE '%.153test@example.invalid'")->fetchColumn();
ok('nothing survived the run (rolled back)', $left === 0, "{$left} test users left");

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail ? 1 : 0);
