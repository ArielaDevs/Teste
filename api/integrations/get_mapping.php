<?php
/**
 * The mapping for one connection, plus the local lists it maps FROM (companies,
 * departments, ticket types, priorities) so the screen can label every row.
 *
 * The local lists are read here rather than on the settings page itself because
 * the page is shared across providers and only needs them when the mapping
 * screen is opened.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/admin_api_guard.php';
require_once '../../includes/encryption.php';
require_once '../../includes/tenancy.php';
require_once '../../includes/integrations/integrations.php';

header('Content-Type: application/json');

$conn = connectToDatabase();

if (!integrationsSchemaReady($conn)) {
    echo json_encode(['success' => false, 'error' => 'Run Database Verification first.']);
    exit;
}

$connectionId = (int)($_GET['connection_id'] ?? 0);
if ($connectionId <= 0) {
    echo json_encode(['success' => false, 'error' => 'No connection given.']);
    exit;
}

/** A lookup list, tolerant of a table that is not there on every install. */
function mappingList(PDO $conn, string $sql): array
{
    try {
        return $conn->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return [];
    }
}

try {
    // F8: the departments list follows the connection's company when pinned,
    // else the analyst's reachable companies. Dormant/un-migrated installs
    // list everything, as before. (The mapping SAVE path needs the same rule
    // before this is watertight — flagged for re-audit.)
    // NOTE: mappingList() below takes raw SQL (no bound params), so the ids
    // are inlined as (int) casts — every value here is server-derived (the
    // connection row, the analyst's grant list), never client input.
    $mapDeptScope = '';
    if (isMultiTenant($conn) && tenancyColumnExists($conn, 'departments', 'tenant_id')) {
        $connTenant = null;
        try {
            $cStmt = $conn->prepare("SELECT tenant_id FROM integration_connections WHERE id = ?");
            $cStmt->execute([$connectionId]);
            $cTenant = $cStmt->fetchColumn();
            if ($cTenant !== false && $cTenant !== null) $connTenant = (int)$cTenant;
        } catch (Exception $e) { $connTenant = null; }
        if ($connTenant !== null) {
            $mapDeptScope = " AND tenant_id = " . ((int)$connTenant);
        } else {
            $allowedIds = array_values(array_unique(array_map('intval', getAccessibleTenantIds($conn, (int)$_SESSION['analyst_id']))));
            if ($allowedIds === []) {
                $mapDeptScope = " AND 1 = 0";
            } else {
                $mapDeptScope = " AND tenant_id IN (" . implode(',', $allowedIds) . ")";
            }
        }
    }
    echo json_encode([
        'success' => true,
        // False when the install has not run Database Verification since this
        // release: the screen says so rather than silently saving nothing.
        'schema_ready' => integrationsMapSchemaReady($conn),
        'maps'         => integrationsLoadMaps($conn, $connectionId),
        // ⚠️ Ordered the same way each list is ordered on its own settings page
        // (display_order, then name). An admin matching this screen against
        // Tickets → Settings should see the same things in the same order.
        'local'        => [
            'companies'    => mappingList($conn, "SELECT id, name FROM tenants WHERE is_active = 1 ORDER BY name"),
            'departments'  => mappingList($conn, "SELECT id, name FROM departments WHERE is_active = 1 $mapDeptScope ORDER BY display_order, name"),
            // ⚠️ ticket_types is the only one of these that is COMPANY-SCOPED
            // (tenant_id NULL = available to every company). Both kinds are
            // offered — a company-specific type is a perfectly good thing to
            // route on — but the tenant name comes back so the screen can label
            // it, otherwise two companies with a similarly named type are
            // indistinguishable. Globals first, matching get_ticket_types.php.
            'ticket_types' => mappingList($conn,
                "SELECT tt.id, tt.name, tt.tenant_id, t.name AS tenant_name
                   FROM ticket_types tt
              LEFT JOIN tenants t ON t.id = tt.tenant_id
                  WHERE tt.is_active = 1
               ORDER BY tt.tenant_id IS NOT NULL, tt.display_order, tt.name"),
            'priorities'   => mappingList($conn, "SELECT id, name FROM ticket_priorities WHERE is_active = 1 ORDER BY display_order, name"),
        ],
    ]);
} catch (Exception $e) {
    error_log('get_mapping: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Could not load the mapping.']);
}
