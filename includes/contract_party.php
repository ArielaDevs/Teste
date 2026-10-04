<?php
/**
 * Who a contract is WITH - a supplier (we buy) or a customer (we sell) - and who
 * may see a customer contract (#153).
 *
 * FreeITSM was built for an IT manager who buys: every contract pointed at a
 * supplier. A contract may now instead be with a CUSTOMER - a company you serve
 * (customer_tenant_id), a person from Users (customer_user_id), or both. Every
 * existing contract is party_type 'supplier' and nothing about it changes.
 *
 * VISIBILITY
 * ----------
 * Supplier contracts are your own procurement and stay visible to everyone with
 * Contracts, as before. A customer contract belongs to that customer, so by
 * default only analysts who can see the customer's company see it - the same rule
 * as the customer's tickets and assets. The setting contracts_customer_visibility
 * ('company' default, or 'all') lets an install open them to everyone.
 *
 * The customer's company is customer_tenant_id, or failing that the customer
 * person's own company. A customer contract with neither (a person from no
 * company) belongs to the Default company, as a NULL tenant does everywhere.
 *
 * ⚠️ EVERY place that reads `contracts` must apply contractVisibilitySql() (lists,
 * search) or contractCanView() (one record) - see the developer guide. A read that
 * forgets it shows one customer's contract and value to another customer's analyst.
 */

require_once __DIR__ . '/tenancy.php';

const CONTRACT_PARTY_SUPPLIER = 'supplier';
const CONTRACT_PARTY_CUSTOMER = 'customer';
const CONTRACT_CUSTOMER_VISIBILITY_KEY = 'contracts_customer_visibility';

/** 'company' (default) or 'all'. */
function contractCustomerVisibility(PDO $conn, bool $reload = false): string
{
    static $v = null;
    if ($v !== null && !$reload) return $v;
    try {
        $s = $conn->prepare("SELECT setting_value FROM system_settings WHERE setting_key = ?");
        $s->execute([CONTRACT_CUSTOMER_VISIBILITY_KEY]);
        $v = $s->fetchColumn() === 'all' ? 'all' : 'company';
    } catch (Throwable $e) {
        $v = 'company';
    }
    return $v;
}

/** True once Database Verification has added the party columns. */
function contractPartyReady(PDO $conn): bool
{
    static $ok = null;
    if ($ok !== null) return $ok;
    try { $conn->query("SELECT party_type FROM contracts LIMIT 0"); return $ok = true; }
    catch (Throwable $e) { return $ok = false; }
}

/**
 * The SQL expression for a contract's customer company, for alias $c.
 * Uses the customer person's company when no company was chosen.
 */
function contractCustomerTenantExpr(string $c = 'c'): string
{
    return "COALESCE($c.customer_tenant_id, (SELECT cu_t.tenant_id FROM users cu_t WHERE cu_t.id = $c.customer_user_id))";
}

/**
 * " AND (...)" hiding the customer contracts this analyst may not see, for a query
 * over `contracts` aliased $c. Returns ['', []] when nothing needs hiding.
 * @return array{0:string,1:array}
 */
function contractVisibilitySql(PDO $conn, int $analystId, string $c = 'c'): array
{
    if (!contractPartyReady($conn) || !isMultiTenant($conn) || contractCustomerVisibility($conn) === 'all') {
        return ['', []];
    }
    return contractVisibilitySqlForScope($conn, getAccessibleTenantIds($conn, $analystId), $c);
}

/**
 * The same rule for an explicit company list, in the API's null-means-all
 * convention (ActorContext::$companyScope).
 * @return array{0:string,1:array}
 */
function contractVisibilitySqlForScope(PDO $conn, ?array $scope, string $c = 'c'): array
{
    if ($scope === null || !contractPartyReady($conn) || !isMultiTenant($conn) || contractCustomerVisibility($conn) === 'all') {
        return ['', []];
    }
    $ids = array_values(array_unique(array_map('intval', $scope)));
    $expr = contractCustomerTenantExpr($c);
    if (!$ids) {
        // Fail closed: an analyst with no companies sees no customer contracts.
        return [" AND $c.party_type <> 'customer'", []];
    }
    $in = implode(',', array_fill(0, count($ids), '?'));
    $nullOk = in_array(getDefaultTenantId($conn), $ids, true) ? " OR $expr IS NULL" : '';
    return [" AND ($c.party_type <> 'customer' OR $expr IN ($in)$nullOk)", $ids];
}

/** May this analyst see contract $id? (Existence is the caller's business.) */
function contractCanView(PDO $conn, int $analystId, int $contractId): bool
{
    [$sql, $params] = contractVisibilitySql($conn, $analystId, 'c');
    $s = $conn->prepare("SELECT 1 FROM contracts c WHERE c.id = ?$sql");
    $s->execute(array_merge([$contractId], $params));
    return (bool)$s->fetchColumn();
}

/**
 * Joins and columns that name a contract's customer, for alias $c:
 *   customer_company_name, customer_person_name, customer_person_email
 * Empty strings before Database Verification, so callers need no second branch.
 * @return array{0:string,1:string} [select columns (with leading comma), joins]
 */
function contractPartySql(PDO $conn, string $c = 'c'): array
{
    if (!contractPartyReady($conn)) {
        return [", 'supplier' AS party_type, NULL AS customer_tenant_id, NULL AS customer_user_id,
                   NULL AS customer_company_name, NULL AS customer_person_name, NULL AS customer_person_email", ''];
    }
    return [
        ", $c.party_type, $c.customer_tenant_id, $c.customer_user_id,
           cpt.name AS customer_company_name,
           COALESCE(NULLIF(cpu.display_name, ''), cpu.email) AS customer_person_name,
           cpu.email AS customer_person_email",
        "LEFT JOIN tenants cpt ON cpt.id = $c.customer_tenant_id
         LEFT JOIN users cpu ON cpu.id = $c.customer_user_id",
    ];
}

/**
 * One line naming who a contract is with, for lists, previews and payloads:
 * the supplier's name, or "Company · Person" / "Company" / "Person".
 */
function contractPartyLabel(array $row): string
{
    if (($row['party_type'] ?? 'supplier') === CONTRACT_PARTY_CUSTOMER) {
        $parts = array_filter([trim((string)($row['customer_company_name'] ?? '')), trim((string)($row['customer_person_name'] ?? ''))], 'strlen');
        return implode(' · ', $parts);
    }
    $trading = trim((string)($row['supplier_trading_name'] ?? ''));
    return $trading !== '' ? $trading : trim((string)($row['supplier_name'] ?? ''));
}

/**
 * Check and normalise the party fields of a contract being saved. Throws
 * InvalidArgumentException with a message fit for the analyst.
 *
 * A supplier contract keeps supplier_id and clears the customer; a customer
 * contract keeps the customer (at least one of company or person) and clears the
 * supplier, so a contract never points both ways. The analyst must be able to see
 * the customer company and the person's company - otherwise they could put a
 * contract on a customer they cannot see, or learn a person exists by id.
 *
 * @return array{party_type:string,supplier_id:?int,customer_tenant_id:?int,customer_user_id:?int}
 */
function contractNormaliseParty(PDO $conn, int $analystId, array $data): array
{
    $type = $data['party_type'] ?? null;
    if ($type === null || $type === '') $type = CONTRACT_PARTY_SUPPLIER;
    if ($type !== CONTRACT_PARTY_SUPPLIER && $type !== CONTRACT_PARTY_CUSTOMER) {
        // A typo through the API must not quietly become a supplier contract.
        throw new InvalidArgumentException(function_exists('t') ? t('contracts.party.err_type') : "party_type must be 'supplier' or 'customer'.");
    }
    $pos = fn($v) => (is_numeric($v) && (int)$v > 0) ? (int)$v : null;

    if ($type === CONTRACT_PARTY_SUPPLIER) {
        return ['party_type' => $type, 'supplier_id' => $pos($data['supplier_id'] ?? null),
                'customer_tenant_id' => null, 'customer_user_id' => null];
    }

    $tenant = $pos($data['customer_tenant_id'] ?? null);
    $user   = $pos($data['customer_user_id'] ?? null);
    if ($tenant === null && $user === null) {
        throw new InvalidArgumentException(function_exists('t') ? t('contracts.party.err_customer_required') : 'Choose the customer: a company, a person, or both.');
    }
    $multi = isMultiTenant($conn);
    if ($tenant !== null) {
        $s = $conn->prepare("SELECT 1 FROM tenants WHERE id = ?");
        $s->execute([$tenant]);
        if (!$s->fetchColumn() || ($multi && !analystCanAccessTenant($conn, $analystId, $tenant))) {
            throw new InvalidArgumentException(function_exists('t') ? t('contracts.party.err_company') : 'That company is not one you can access.');
        }
    }
    if ($user !== null) {
        $s = $conn->prepare("SELECT tenant_id FROM users WHERE id = ?");
        $s->execute([$user]);
        $row = $s->fetch(PDO::FETCH_ASSOC);
        $ut = $row ? ($row['tenant_id'] !== null ? (int)$row['tenant_id'] : getDefaultTenantId($conn)) : null;
        if (!$row || ($multi && !analystCanAccessTenant($conn, $analystId, $ut))) {
            throw new InvalidArgumentException(function_exists('t') ? t('contracts.party.err_person') : 'That person is not one you can see.');
        }
        if ($tenant !== null && $multi && $ut !== $tenant) {
            throw new InvalidArgumentException(function_exists('t') ? t('contracts.party.err_person_company') : 'That person is not in the company you chose.');
        }
    }
    return ['party_type' => $type, 'supplier_id' => null, 'customer_tenant_id' => $tenant, 'customer_user_id' => $user];
}
