<?php
/**
 * API Endpoint: Get servers list from database
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}
requireModuleAccessJson('assets');

try {
    $conn = connectToDatabase();

    $sql = "SELECT id, vm_id, name, power_state, memory_gb, num_cpu, ip_address, hard_disk_size_gb, host, cluster, guest_os, raw_data, DATE_FORMAT(last_synced, '%Y-%m-%d %H:%i:%s') as last_synced FROM servers ORDER BY name";
    $stmt = $conn->prepare($sql);
    $stmt->execute();
    $servers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($servers as &$row) {
        $row['source'] = 'vcenter';
        $row['source_name'] = 'vCenter';
    }
    unset($row);

    // Proxmox VMs and containers, synced by cron/proxmox_sync.php or the Sync button,
    // shaped like the vCenter rows so the table, filters and totals treat them the same.
    // Nodes are hypervisors, not guests, so they are not listed here.
    $pxStmt = $conn->prepare(
        "SELECT v.vmid, v.vm_type, v.connection_id, c.name AS conn_name, v.name, v.status, v.vcpus, v.memory_mb,
                v.disk_gb, v.ip_addresses, v.node_name, v.cluster_name, v.os_type, v.details_json,
                DATE_FORMAT(v.last_seen_datetime, '%Y-%m-%d %H:%i:%s') AS last_seen
         FROM proxmox_vms v JOIN proxmox_connections c ON c.id = v.connection_id
         ORDER BY v.name"
    );
    $pxStmt->execute();
    foreach ($pxStmt->fetchAll(PDO::FETCH_ASSOC) as $v) {
        $servers[] = [
            'id'                  => 'px-' . $v['connection_id'] . '-' . $v['vmid'],
            'vm_id'               => 'proxmox-' . $v['connection_id'] . '-' . $v['vmid'],
            'name'                => (string)$v['name'],
            'power_state'         => $v['status'] === 'running' ? 'active' : 'offline',
            'memory_gb'           => $v['memory_mb'] !== null ? round($v['memory_mb'] / 1024, 2) : 0,
            'num_cpu'             => (int)$v['vcpus'],
            'ip_address'          => $v['ip_addresses'] ? explode(', ', $v['ip_addresses'])[0] : '',
            'hard_disk_size_gb'   => $v['disk_gb'] !== null ? (float)$v['disk_gb'] : 0,
            'host'                => (string)$v['node_name'],
            'cluster'             => (string)$v['cluster_name'],
            'guest_os'            => (string)($v['os_type'] ?? ''),
            'raw_data'            => $v['details_json'],
            'last_synced'         => $v['last_seen'],
            'source'              => 'proxmox',
            'source_name'         => 'Proxmox · ' . $v['conn_name'],
        ];
    }
    usort($servers, function ($a, $b) { return strcasecmp((string)$a['name'], (string)$b['name']); });

    // Build summary stats - separate ESXi hosts from VMs
    $esxiHosts = [];
    $vmServers = [];
    foreach ($servers as $s) {
        if ($s['guest_os'] === 'VMware ESXi') {
            $esxiHosts[] = $s;
        } else {
            $vmServers[] = $s;
        }
    }

    $totalVMs = count($vmServers);
    $activeVMs = 0;
    $offlineVMs = 0;
    $totalMemoryGB = 0;
    $totalCPU = 0;
    $totalDiskGB = 0;
    $clusters = [];

    foreach ($vmServers as $s) {
        if ($s['power_state'] === 'active') {
            $activeVMs++;
        } else {
            $offlineVMs++;
        }
        $totalMemoryGB += (float)$s['memory_gb'];
        $totalCPU += (int)$s['num_cpu'];
        $totalDiskGB += (float)$s['hard_disk_size_gb'];
        if (!empty($s['cluster']) && !in_array($s['cluster'], $clusters)) {
            $clusters[] = $s['cluster'];
        }
    }

    $lastSynced = null;
    if ($totalVMs > 0) {
        $lastSynced = $servers[0]['last_synced'];
    }

    echo json_encode([
        'success' => true,
        'servers' => $servers,
        'summary' => [
            'total_vms' => $totalVMs,
            'active_vms' => $activeVMs,
            'offline_vms' => $offlineVMs,
            'total_memory_gb' => round($totalMemoryGB, 1),
            'total_cpu' => $totalCPU,
            'total_disk_gb' => round($totalDiskGB, 1),
            'host_count' => count($esxiHosts),
            'cluster_count' => count($clusters),
            'last_synced' => $lastSynced
        ]
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
