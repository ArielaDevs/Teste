<?php
/**
 * API Endpoint: Search the shared suppliers registry (for the Asset Settings
 * Suppliers tab). Returns every supplier with its supplies_assets flag so the
 * tab can toggle which ones are available to pick on an asset.
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

$search = trim($_GET['search'] ?? '');

try {
    $conn = connectToDatabase();
    $sql = "SELECT id,
                   COALESCE(NULLIF(TRIM(trading_name), ''), legal_name) AS name,
                   legal_name, trading_name, supplies_assets, is_active
            FROM suppliers";
    $params = [];
    if ($search !== '') {
        $sql .= " WHERE legal_name LIKE ? OR trading_name LIKE ?";
        $params = ['%' . $search . '%', '%' . $search . '%'];
    }
    $sql .= " ORDER BY name";
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $suppliers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($suppliers as &$s) {
        $s['id'] = (int)$s['id'];
        $s['supplies_assets'] = (int)$s['supplies_assets'];
        $s['is_active'] = (int)$s['is_active'];
    }
    echo json_encode(['success' => true, 'suppliers' => $suppliers]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
