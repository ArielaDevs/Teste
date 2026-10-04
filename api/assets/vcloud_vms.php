<?php
/**
 * API Endpoint: the VMs and edge gateways synced from one vCloud Director server.
 *   GET ?connection_id=N
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/rbac.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}
requireCapabilityJson(Cap::ASSETS_VCLOUD);

try {
    $id = (int)($_GET['connection_id'] ?? 0);
    if ($id <= 0) {
        throw new Exception('Server ID is required');
    }
    $conn = connectToDatabase();
    $vms = $conn->prepare(
        "SELECT vm_uuid, name, org_vdc, vapp_name, status, vcpus, memory_mb, disk_gb, ip_addresses, mac_addresses, last_seen_datetime
         FROM vcloud_vms WHERE connection_id = ? ORDER BY org_vdc, name"
    );
    $vms->execute([$id]);
    $edges = $conn->prepare(
        "SELECT gateway_id, name, org_vdc, status, uplink_ips, last_seen_datetime FROM vcloud_edge_gateways WHERE connection_id = ? ORDER BY name"
    );
    $edges->execute([$id]);
    echo json_encode(['success' => true, 'vms' => $vms->fetchAll(PDO::FETCH_ASSOC), 'edges' => $edges->fetchAll(PDO::FETCH_ASSOC)]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
