<?php
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/contract_party.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}
requireModuleAccessJson('contracts');

try {
    $conn = connectToDatabase();

    $stats = [];

    // Counts include only the contracts this analyst may see: a customer contract
    // for a company they cannot access is not counted either (#153).
    [$vis, $visParams] = contractVisibilitySql($conn, (int)$_SESSION['analyst_id'], 'c');
    $count = function (string $where) use ($conn, $vis, $visParams) {
        $s = $conn->prepare("SELECT COUNT(*) FROM contracts c WHERE $where$vis");
        $s->execute($visParams);
        return (int)$s->fetchColumn();
    };

    $stats['contracts'] = $count('1=1');
    $stats['active_contracts'] = $count('c.is_active = 1 AND c.contract_end >= DATE(UTC_TIMESTAMP())');
    $stats['customer_contracts'] = contractPartyReady($conn) ? $count("c.party_type = 'customer'") : 0;

    $stmt = $conn->query("SELECT COUNT(*) FROM suppliers");
    $stats['suppliers'] = (int)$stmt->fetchColumn();

    $stmt = $conn->query("SELECT COUNT(*) FROM contacts");
    $stats['contacts'] = (int)$stmt->fetchColumn();

    // Contracts expiring within 90 days
    $stats['expiring_soon'] = $count('c.is_active = 1 AND c.contract_end BETWEEN DATE(UTC_TIMESTAMP()) AND DATE_ADD(DATE(UTC_TIMESTAMP()), INTERVAL 90 DAY)');

    echo json_encode(['success' => true, 'stats' => $stats]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
