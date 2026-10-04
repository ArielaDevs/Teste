<?php
/**
 * API: List the email domains registered to a company (tenant).
 * GET ?tenant_id=N — returns [{ id, domain }] for shared-intake routing.
 *
 * "Company" is the user-facing word for a tenant; the table/code stays `tenant`.
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

    $stmt = $conn->prepare("SELECT id, domain FROM tenant_domains WHERE tenant_id = ? ORDER BY domain");
    $stmt->execute([$tenantId]);
    $domains = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $domains[] = ['id' => (int)$row['id'], 'domain' => $row['domain']];
    }

    echo json_encode(['success' => true, 'domains' => $domains]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
