<?php
/**
 * API Endpoint: test one Proxmox server, or sync it now.
 *   POST {id, action:'test'|'sync'}
 * A sync runs in the request and returns its summary. The scheduled run is
 * cron/proxmox_sync.php, which does the same for servers that are due.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/rbac.php';
require_once '../../includes/encryption.php';
require_once '../../includes/proxmox_sync.php';

header('Content-Type: application/json');
set_time_limit(600);

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}
requireCapabilityJson(Cap::ASSETS_PROXMOX);

try {
    $in = json_decode(file_get_contents('php://input'), true);
    $id = (int)($in['id'] ?? 0);
    $action = (string)($in['action'] ?? 'sync');
    if ($id <= 0) {
        throw new Exception('Server ID is required');
    }
    $conn = connectToDatabase();

    if ($action === 'test') {
        $c = proxmoxLoadConnection($conn, $id);
        echo json_encode(['success' => true, 'message' => proxmoxTestConnection($c)]);
        exit;
    }
    if ($action !== 'sync') {
        throw new Exception('Unknown action');
    }
    $summary = proxmoxSyncConnection($conn, $id);
    echo json_encode(['success' => $summary['status'] !== 'error', 'summary' => $summary,
        'error' => $summary['status'] === 'error' ? $summary['message'] : null]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
