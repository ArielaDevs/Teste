<?php
/**
 * API Endpoint: sync every active Proxmox VE and VMware Cloud Director server
 * now - the Servers page's Sync button, after it has run vCenter.
 *   POST → { success, results: [{source, name, status, message}] }
 *
 * Added when PR #167 was merged. The PR's button fetched the two server lists
 * and called each server's sync from the browser. Those endpoints need the
 * settings permissions (assets.proxmox, assets.vcloud), so for anyone who only
 * uses the Servers page the lists came back 403 and both sources were silently
 * skipped - the button said "synced" and synced nothing new.
 *
 * vCenter settled this question already: its sync (get_vcenter.php) runs on
 * module access, because the Servers page is operational and syncing changes
 * no configuration. This follows it. Configuring servers, testing them and
 * "Show VMs" still need the settings permissions.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/encryption.php';
require_once '../../includes/proxmox_sync.php';
require_once '../../includes/vcloud_sync.php';

header('Content-Type: application/json');
set_time_limit(900);

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}
requireModuleAccessJson('assets');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'POST only']);
    exit;
}

try {
    $conn = connectToDatabase();
    $results = [];
    $sources = [
        ['proxmox', 'proxmox_connections', 'proxmoxSyncConnection'],
        ['vcloud',  'vcloud_connections',  'vcdSyncConnection'],
    ];
    foreach ($sources as [$source, $table, $sync]) {
        $rows = $conn->query("SELECT id, name FROM {$table} WHERE is_active = 1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            try {
                $s = $sync($conn, (int)$row['id']);
                $results[] = ['source' => $source, 'name' => $row['name'], 'status' => $s['status'], 'message' => $s['message']];
            } catch (Exception $e) {
                $results[] = ['source' => $source, 'name' => $row['name'], 'status' => 'error', 'message' => $e->getMessage()];
            }
        }
    }
    echo json_encode(['success' => true, 'results' => $results]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
