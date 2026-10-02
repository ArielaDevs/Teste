<?php
/**
 * Contracts → Settings → Customer contracts: who may see a customer contract (#153).
 *   GET                  {value: 'company'|'all', multi_company}
 *   POST {value}         save it
 *
 * Its own sensitive capability: choosing 'all' lets one customer's analysts read
 * another customer's contracts and values. See includes/contract_party.php.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/rbac.php';
require_once '../../includes/contract_party.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}
requireModuleAccessJson('contracts');
requireCapabilityJson(Cap::CONTRACTS_CUSTOMER_VISIBILITY);

try {
    $conn = connectToDatabase();
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $in = json_decode(file_get_contents('php://input'), true) ?: [];
        $value = ($in['value'] ?? '') === 'all' ? 'all' : 'company';
        $conn->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?)
                        ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")
             ->execute([CONTRACT_CUSTOMER_VISIBILITY_KEY, $value]);
        if (function_exists('logSystemEvent')) {
            logSystemEvent($conn, 'settings_change', ['setting' => CONTRACT_CUSTOMER_VISIBILITY_KEY, 'value' => $value]);
        }
        echo json_encode(['success' => true, 'value' => $value]);
        exit;
    }
    echo json_encode(['success' => true, 'value' => contractCustomerVisibility($conn), 'multi_company' => isMultiTenant($conn)]);
} catch (Throwable $e) {
    error_log('customer_visibility: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'The setting could not be saved.']);
}
