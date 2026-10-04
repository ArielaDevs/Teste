<?php
/**
 * API Endpoint: the VMs, containers and nodes synced from one Proxmox server.
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
requireCapabilityJson(Cap::ASSETS_PROXMOX);

try {
    $id = (int)($_GET['connection_id'] ?? 0);
    if ($id <= 0) {
        throw new Exception('Server ID is required');
    }
    $conn = connectToDatabase();
    $nodes = $conn->prepare("SELECT node_name, cluster_name, status, cpu_cores, memory_mb, last_seen_datetime FROM proxmox_nodes WHERE connection_id = ? ORDER BY node_name");
    $nodes->execute([$id]);
    $vms = $conn->prepare(
        "SELECT vmid, vm_type, node_name, cluster_name, name, status, vcpus, memory_mb, disk_gb, os_type,
                ip_addresses, mac_addresses, last_seen_datetime
         FROM proxmox_vms WHERE connection_id = ? ORDER BY node_name, vmid"
    );
    $vms->execute([$id]);
    echo json_encode(['success' => true, 'nodes' => $nodes->fetchAll(PDO::FETCH_ASSOC), 'vms' => $vms->fetchAll(PDO::FETCH_ASSOC)]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
