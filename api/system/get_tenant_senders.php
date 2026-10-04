<?php
/**
 * API: List the specific sender addresses mapped to a company (tenant).
 * GET ?tenant_id=N — returns [{ id, email }] for shared-intake routing.
 *
 * The address-level twin of get_tenant_domains.php. "Company" is the
 * user-facing word for a tenant; the table/code stays `tenant`.
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
requireModuleAccessJson('system');

$tenantId = isset($_GET['tenant_id']) ? (int)$_GET['tenant_id'] : 0;
if ($tenantId <= 0) {
    echo json_encode(['success' => false, 'error' => 'Missing company id']);
    exit;
}

try {
    $conn = connectToDatabase();

    $stmt = $conn->prepare("SELECT id, email FROM tenant_sender_addresses WHERE tenant_id = ? ORDER BY email");
    $stmt->execute([$tenantId]);
    $senders = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $senders[] = ['id' => (int)$row['id'], 'email' => $row['email']];
    }

    echo json_encode(['success' => true, 'senders' => $senders]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
