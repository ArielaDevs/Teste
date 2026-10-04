<?php
/**
 * Contracts: choosing a contract's CUSTOMER (#153).
 *   GET                       {multi_company, companies: [{id, name}]} - the companies you can access
 *   GET ?q=jane[&tenant_id=4] {people: [{id, name, email, company}]}  - people in those companies
 *
 * Its own endpoint rather than api/tickets/get_users.php: that one needs Tickets or
 * Assets, and an analyst who only does contracts should still be able to name a
 * customer. It never returns a person from a company the analyst cannot access.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/tenancy.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}
requireModuleAccessJson('contracts');

try {
    $conn = connectToDatabase();
    $aid = (int)$_SESSION['analyst_id'];
    $multi = isMultiTenant($conn);
    $ids = $multi ? array_values(array_unique(array_map('intval', getAccessibleTenantIds($conn, $aid)))) : null;

    if (!isset($_GET['q'])) {
        $companies = [];
        if ($multi) {
            foreach (getAllTenants($conn, true) as $t) {
                if (in_array((int)$t['id'], $ids, true)) $companies[] = ['id' => (int)$t['id'], 'name' => $t['name']];
            }
        }
        echo json_encode(['success' => true, 'multi_company' => $multi, 'companies' => $companies]);
        exit;
    }

    $q = trim((string)$_GET['q']);
    if (mb_strlen($q) < 2) { echo json_encode(['success' => true, 'people' => []]); exit; }
    $like = '%' . $q . '%';
    $sql = "SELECT u.id, COALESCE(NULLIF(u.display_name, ''), u.email) AS name, u.email, t.name AS company
              FROM users u LEFT JOIN tenants t ON t.id = u.tenant_id
             WHERE u.is_active = 1 AND (u.display_name LIKE ? OR u.email LIKE ? OR u.preferred_name LIKE ?)";
    $params = [$like, $like, $like];

    if ($multi) {
        $want = isset($_GET['tenant_id']) && (int)$_GET['tenant_id'] > 0 ? (int)$_GET['tenant_id'] : null;
        $scope = $want !== null ? array_values(array_intersect($ids, [$want])) : $ids;
        if (!$scope) { echo json_encode(['success' => true, 'people' => []]); exit; }
        $in = implode(',', array_fill(0, count($scope), '?'));
        $nullOk = in_array(getDefaultTenantId($conn), $scope, true) ? ' OR u.tenant_id IS NULL' : '';
        $sql .= " AND (u.tenant_id IN ($in)$nullOk)";
        $params = array_merge($params, $scope);
    }
    $sql .= " ORDER BY name LIMIT 25";
    $s = $conn->prepare($sql);
    $s->execute($params);
    $people = array_map(fn($r) => ['id' => (int)$r['id'], 'name' => $r['name'], 'email' => $r['email'], 'company' => $r['company']], $s->fetchAll(PDO::FETCH_ASSOC));
    echo json_encode(['success' => true, 'people' => $people]);
} catch (Throwable $e) {
    error_log('contracts customer_lookup: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'The list could not be loaded.']);
}
