<?php
/**
 * Domains — the CUSTOMER a domain is looked after for (#153).
 *
 * A domain already belongs to a company (tenant_id), so the customer is just a
 * person: someone from the users table, in the domain's own company. There is
 * deliberately no second company column — the domain's company IS the
 * customer's company.
 *
 * Every read and write checks domainCustomerReady() first, so an install that
 * has not run Database Verification since upgrading keeps working without the
 * column instead of failing every domain query.
 */

require_once __DIR__ . '/../tenancy.php';

/** Has Database Verification added domains.customer_user_id yet? */
function domainCustomerReady(PDO $conn): bool
{
    static $ok = null;
    if ($ok !== null) return $ok;
    try { $conn->query("SELECT customer_user_id FROM domains LIMIT 0"); return $ok = true; }
    catch (Throwable $e) { return $ok = false; }
}

/**
 * May $userId be the customer of a domain in company $tenantId? An active user
 * in that company; on a single-company install, any active user. A user with no
 * company counts as the Default company's, as everywhere else.
 */
function domainCustomerPersonOk(PDO $conn, int $userId, ?int $tenantId): bool
{
    $st = $conn->prepare("SELECT tenant_id FROM users WHERE id = ? AND is_active = 1");
    $st->execute([$userId]);
    $u = $st->fetch(PDO::FETCH_ASSOC);
    if (!$u) return false;
    if (!isMultiTenant($conn)) return true;
    $default = getDefaultTenantId($conn);
    $userCo  = $u['tenant_id'] === null ? $default : (int)$u['tenant_id'];
    $domCo   = $tenantId === null ? $default : $tenantId;
    return $userCo === $domCo;
}

/**
 * People who could be the customer of a domain in company $tenantId, matching $q.
 * @return array<int,array{id:int,name:string,email:?string}>
 */
function domainCustomerSearch(PDO $conn, ?int $tenantId, string $q, int $limit = 25): array
{
    $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
    $sql = "SELECT u.id, COALESCE(NULLIF(u.display_name, ''), u.email) AS name, u.email
              FROM users u
             WHERE u.is_active = 1 AND (u.display_name LIKE ? OR u.email LIKE ? OR u.preferred_name LIKE ?)";
    $args = [$like, $like, $like];
    if (isMultiTenant($conn)) {
        $default = getDefaultTenantId($conn);
        $co = $tenantId === null ? $default : $tenantId;
        $sql .= $co === $default ? " AND (u.tenant_id = ? OR u.tenant_id IS NULL)" : " AND u.tenant_id = ?";
        $args[] = $co;
    }
    $st = $conn->prepare($sql . " ORDER BY name LIMIT " . (int)$limit);
    $st->execute($args);
    return array_map(fn($r) => ['id' => (int)$r['id'], 'name' => $r['name'], 'email' => $r['email']], $st->fetchAll(PDO::FETCH_ASSOC));
}
