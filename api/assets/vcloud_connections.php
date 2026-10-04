<?php
/**
 * API Endpoint: VMware Cloud Director servers — list, add/edit, delete.
 *   GET                        → every server (no passwords)
 *   POST {action:'save', ...}  → create (no id) or update (id); a blank password keeps the stored one
 *   POST {action:'delete', id} → remove a server and its synced VMs and edge gateways
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/rbac.php';
require_once '../../includes/encryption.php';
require_once '../../includes/vcloud_sync.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}
requireCapabilityJson(Cap::ASSETS_VCLOUD);

try {
    $conn = connectToDatabase();

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $rows = $conn->query("SELECT * FROM vcloud_connections ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['success' => true, 'connections' => array_map('vcdConnectionOut', $rows)]);
        exit;
    }

    $in = json_decode(file_get_contents('php://input'), true);
    if (!is_array($in)) {
        throw new Exception('Invalid request data');
    }
    $action = (string)($in['action'] ?? '');

    if ($action === 'delete') {
        $id = (int)($in['id'] ?? 0);
        if ($id <= 0) {
            throw new Exception('Server ID is required');
        }
        // The foreign keys cascade, but Database Verification adds them to an
        // existing install only when it runs - so say it here as well.
        $conn->beginTransaction();
        $conn->prepare("DELETE FROM vcloud_vms WHERE connection_id = ?")->execute([$id]);
        $conn->prepare("DELETE FROM vcloud_edge_gateways WHERE connection_id = ?")->execute([$id]);
        $conn->prepare("DELETE FROM vcloud_connections WHERE id = ?")->execute([$id]);
        $conn->commit();
        echo json_encode(['success' => true, 'message' => 'Server removed']);
        exit;
    }
    if ($action !== 'save') {
        throw new Exception('Unknown action');
    }

    $id = vcdSaveConnection($conn, $in);
    echo json_encode(['success' => true, 'id' => $id, 'message' => 'Server saved']);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
