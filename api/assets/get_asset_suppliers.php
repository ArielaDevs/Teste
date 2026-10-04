<?php
/**
 * API Endpoint: Suppliers available for assets (dropdown source).
 *
 * Returns only suppliers flagged supplies_assets (and active) from the shared
 * suppliers registry, labelled by trading name (falling back to legal name).
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
    $sql = "SELECT id, COALESCE(NULLIF(TRIM(trading_name), ''), legal_name) AS name
            FROM suppliers
            WHERE supplies_assets = 1 AND is_active = 1
            ORDER BY name";
    $suppliers = $conn->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    foreach ($suppliers as &$s) { $s['id'] = (int)$s['id']; }
    echo json_encode(['success' => true, 'suppliers' => $suppliers]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
