<?php
/**
 * vCloud Director sync — scheduled entry point (CLI only).
 *
 *   php cron/vcloud_sync.php
 *
 * Syncs every active vCloud Director server whose interval has passed since its last sync.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Run from the command line.\n");
}

set_time_limit(0);
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/vcloud_sync.php';

$conn = connectToDatabase();
$due = $conn->query(
    "SELECT id, name FROM vcloud_connections
     WHERE is_active = 1
       AND (last_sync_datetime IS NULL
            OR last_sync_datetime <= UTC_TIMESTAMP() - INTERVAL sync_interval_minutes MINUTE)
     ORDER BY id"
)->fetchAll(PDO::FETCH_ASSOC);

foreach ($due as $server) {
    try {
        $s = vcdSyncConnection($conn, (int)$server['id']);
        echo date('c') . ' ' . $server['name'] . ': ' . $s['status'] . ' - ' . $s['message'] . "\n";
    } catch (Exception $e) {
        echo date('c') . ' ' . $server['name'] . ': error - ' . $e->getMessage() . "\n";
    }
}
echo date('c') . ' done, ' . count($due) . " server(s) due\n";
