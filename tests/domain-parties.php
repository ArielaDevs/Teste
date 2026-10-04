<?php
/* 🔴 NEVER OVER THE WEB. See tests/README.md. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * A domain's customer and technical contact (GH #162) - the rules in
 * DomainsService::normaliseParties().
 *
 * One customer: a person, OR a supplier (optionally with one of its contacts).
 * One technical contact: a supplier contact, OR an analyst. Setting one kind
 * clears the other; naming both is refused; a contact brings its supplier.
 *
 * Everything runs inside a transaction that is ALWAYS rolled back.
 *
 * Run: php tests/domain-parties.php
 */

$root = dirname(__DIR__);
require_once "$root/config.php";
require_once "$root/includes/functions.php";
require_once "$root/includes/services/domains.php";

$pass = 0; $fail = 0;
function ok(string $label, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; printf("  PASS %-66s %s\n", $label, $detail); }
    else       { $fail++; printf("  FAIL %-66s %s\n", $label, $detail); }
}

$conn = connectToDatabase();
if (!domainPartiesReady($conn)) { echo "Run Database Verification first (#162 columns).\n"; exit(1); }
$admin = (int)$conn->query("SELECT id FROM analysts WHERE is_active = 1 AND is_admin = 1 ORDER BY id LIMIT 1")->fetchColumn();
$ctx = new ActorContext(actorId: $admin, companyScope: null, source: 'ui');
$default = getDefaultTenantId($conn);

$conn->beginTransaction();
try {
    $conn->prepare("INSERT INTO suppliers (legal_name, is_active) VALUES ('DP Supplier A', 1), ('DP Supplier B', 1)")->execute();
    $supA = (int)$conn->lastInsertId(); $supB = $supA + 1;
    $conn->prepare("INSERT INTO contacts (supplier_id, first_name, surname, email, is_active) VALUES (?, 'DP', 'Alpha', 'dp.alpha.dptest@example.invalid', 1)")->execute([$supA]);
    $conA = (int)$conn->lastInsertId();
    $conn->prepare("INSERT INTO users (email, display_name, tenant_id, is_active) VALUES ('dp.person.dptest@example.invalid', 'DP Person', ?, 1)")->execute([$default]);
    $user = (int)$conn->lastInsertId();
    $id = DomainsService::createDomain($conn, $ctx, ['domain_name' => 'dp-parties.dptest.example'], $default);

    $row = function () use ($conn, $id): array {
        $s = $conn->prepare("SELECT tech_contact_id, tech_analyst_id, customer_user_id, customer_supplier_id, customer_contact_id FROM domains WHERE id = ?");
        $s->execute([$id]);
        return array_map(fn($v) => $v === null ? null : (int)$v, $s->fetch(PDO::FETCH_ASSOC));
    };
    $refused = function (array $in) use ($conn, $ctx, $id): bool {
        try { DomainsService::updateDomain($conn, $ctx, $id, $in); return false; }
        catch (ServiceError $e) { return true; }
    };

    echo "\nTechnical contact\n";
    DomainsService::updateDomain($conn, $ctx, $id, ['tech_analyst_id' => $admin]);
    ok('an analyst can be the technical contact', $row()['tech_analyst_id'] === $admin);
    DomainsService::updateDomain($conn, $ctx, $id, ['tech_contact_id' => $conA]);
    ok('setting a supplier contact clears the analyst', $row()['tech_contact_id'] === $conA && $row()['tech_analyst_id'] === null);
    ok('naming both kinds is refused', $refused(['tech_contact_id' => $conA, 'tech_analyst_id' => $admin]));
    ok('...and changes nothing', $row()['tech_contact_id'] === $conA);

    echo "\nCustomer\n";
    DomainsService::updateDomain($conn, $ctx, $id, ['customer_contact_id' => $conA]);
    ok('a supplier contact brings its supplier', $row()['customer_contact_id'] === $conA && $row()['customer_supplier_id'] === $supA);
    ok('a contact with a different supplier is refused', $refused(['customer_contact_id' => $conA, 'customer_supplier_id' => $supB]));
    DomainsService::updateDomain($conn, $ctx, $id, ['customer_supplier_id' => $supB]);
    ok('a different supplier drops the old supplier\'s contact', $row()['customer_supplier_id'] === $supB && $row()['customer_contact_id'] === null);
    ok('a person and a supplier together are refused', $refused(['customer_user_id' => $user, 'customer_supplier_id' => $supA]));
    DomainsService::updateDomain($conn, $ctx, $id, ['customer_user_id' => $user]);
    ok('a person clears the supplier', $row()['customer_user_id'] === $user && $row()['customer_supplier_id'] === null);
    DomainsService::updateDomain($conn, $ctx, $id, ['customer_supplier_id' => $supA, 'customer_user_id' => null, 'customer_contact_id' => null]);
    ok('the edit dialog\'s shape (one kind set, the rest null) works', $row()['customer_supplier_id'] === $supA && $row()['customer_user_id'] === null);
    DomainsService::updateDomain($conn, $ctx, $id, ['customer_contact_id' => $conA]);
    DomainsService::updateDomain($conn, $ctx, $id, ['customer_supplier_id' => null]);
    ok('clearing the supplier clears its contact', $row()['customer_supplier_id'] === null && $row()['customer_contact_id'] === null);
    DomainsService::updateDomain($conn, $ctx, $id, ['customer_user_id' => $user]);
    DomainsService::updateDomain($conn, $ctx, $id, ['customer_user_id' => null]);
    ok('clearing the person leaves nothing behind', $row()['customer_user_id'] === null && $row()['customer_supplier_id'] === null);

    echo "\nUnrelated edits\n";
    DomainsService::updateDomain($conn, $ctx, $id, ['tech_analyst_id' => $admin, 'customer_contact_id' => $conA]);
    DomainsService::updateDomain($conn, $ctx, $id, ['notes' => 'unrelated']);
    $r = $row();
    ok('an edit that names neither leaves both alone (positive control)', $r['tech_analyst_id'] === $admin && $r['customer_contact_id'] === $conA, json_encode($r));

    $hist = $conn->prepare("SELECT COUNT(*) FROM domain_audit WHERE domain_id = ? AND field_name IN ('tech_analyst_id', 'customer_supplier_id', 'customer_contact_id')");
    $hist->execute([$id]);
    ok('the new fields are written to the history', (int)$hist->fetchColumn() > 0);
} catch (Throwable $e) {
    ok('the run completed', false, get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
} finally {
    if ($conn->inTransaction()) $conn->rollBack();
}

$left = (int)$conn->query("SELECT COUNT(*) FROM domains WHERE domain_name LIKE '%.dptest.example'")->fetchColumn()
      + (int)$conn->query("SELECT COUNT(*) FROM contacts WHERE email LIKE '%.dptest@example.invalid'")->fetchColumn()
      + (int)$conn->query("SELECT COUNT(*) FROM users WHERE email LIKE '%.dptest@example.invalid'")->fetchColumn();
ok('nothing survived the run (rolled back)', $left === 0, "{$left} test rows left");

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail ? 1 : 0);
