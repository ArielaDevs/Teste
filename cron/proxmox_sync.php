<?php
/**
 * Proxmox VE sync — scheduled entry point (CLI only).
 *
 *   php cron/proxmox_sync.php
 *
 * Syncs every active Proxmox server whose interval has passed since its last
 * sync. Run it every few minutes (for example from the host's cron, or a
 * container's loop); each server then runs on its own interval. A server that
 * fails does not stop the others.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Run from the command line.\n");
}

set_time_limit(0);
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/proxmox_sync.php';

$conn = connectToDatabase();
$due = $conn->query(
    "SELECT id, name FROM proxmox_connections
     WHERE is_active = 1
       AND (last_sync_datetime IS NULL
            OR last_sync_datetime <= UTC_TIMESTAMP() - INTERVAL sync_interval_minutes MINUTE)
     ORDER BY id"
)->fetchAll(PDO::FETCH_ASSOC);

foreach ($due as $server) {
    try {
        $s = proxmoxSyncConnection($conn, (int)$server['id']);
        echo date('c') . ' ' . $server['name'] . ': ' . $s['status'] . ' - ' . $s['message'] . "\n";
    } catch (Exception $e) {
        // proxmoxSyncConnection records its own failures; this only catches setup errors.
        echo date('c') . ' ' . $server['name'] . ': error - ' . $e->getMessage() . "\n";
    }
}
echo date('c') . ' done, ' . count($due) . " server(s) due\n";
