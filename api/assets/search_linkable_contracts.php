<?php
/**
 * API: Contracts an asset could be added to (discussion #106).
 * GET ?asset_id=&q=
 *
 * The mirror of api/contracts/search_contract_assets.php, for linking from the
 * equipment's side. Somebody standing on a new handset should not have to go and
 * find the agreement first.
 *
 * ⚠️ Gated on BOTH modules. Assets, because it is the asset's page; contracts,
 * because it lists contract numbers and titles. An analyst with equipment access
 * and no contracts access must not be able to read the contract register through
 * a search box on an asset.
 *
 * No tenancy filter on the contracts themselves — contracts carry no tenant_id.
 * The ASSET is checked, which is the only half of the pair that belongs to a
 * company at all. See includes/contract_assets.php.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/contract_assets.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}
requireModuleAccessJson('assets');

const LINKABLE_CONTRACT_LIMIT = 25;

try {
    $assetId = (int)($_GET['asset_id'] ?? 0);
    if ($assetId <= 0) {
        throw new Exception('asset_id is required');
    }

    $conn      = connectToDatabase();
    $analystId = (int)$_SESSION['analyst_id'];

    if (!analystCanAccessModule($conn, $analystId, 'contracts')) {
        throw new Exception('You do not have access to the Contracts module');
    }
    if (!contractAssetCanReach($conn, $analystId, $assetId)) {
        throw new Exception('No such asset');
    }

    $q   = trim((string)($_GET['q'] ?? ''));
    require_once '../../includes/contract_party.php';
    [$partyCols, $partyJoins] = contractPartySql($conn, 'c');
    [$vis, $visParams] = contractVisibilitySql($conn, $analystId, 'c');   // #153
    $sql =
        "SELECT c.id AS contract_id, c.contract_number, c.title,
                c.contract_end, c.notice_date,
                s.legal_name AS supplier_name, s.trading_name AS supplier_trading_name
                $partyCols
           FROM contracts c
      LEFT JOIN suppliers s ON s.id = c.supplier_id
                $partyJoins
          WHERE c.is_active = 1
            AND c.id NOT IN (SELECT contract_id FROM contract_assets WHERE asset_id = ?)$vis";
    $params = array_merge([$assetId], $visParams);

    if ($q !== '') {
        // Supplier (or customer) is searchable as well as number and title,
        // because "the Vodafone one" is how people actually refer to a contract
        // they have not opened in a year.
        if (contractPartyReady($conn)) {
            $sql .= " AND (c.contract_number LIKE ? OR c.title LIKE ?
                           OR s.legal_name LIKE ? OR s.trading_name LIKE ? OR cpt.name LIKE ? OR cpu.display_name LIKE ?)";
            $params = array_merge($params, array_fill(0, 6, '%' . $q . '%'));
        } else {
            $sql .= " AND (c.contract_number LIKE ? OR c.title LIKE ?
                           OR s.legal_name LIKE ? OR s.trading_name LIKE ?)";
            $params = array_merge($params, array_fill(0, 4, '%' . $q . '%'));
        }
    }

    // Soonest to end first: a contract you are about to lose is the one you are
    // most likely to be attaching equipment to.
    $sql .= " ORDER BY c.contract_end IS NULL, c.contract_end, c.title LIMIT " . LINKABLE_CONTRACT_LIMIT;

    $stmt = $conn->prepare($sql);
    $stmt->execute($params);

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) {
        $r['contract_id'] = (int)$r['contract_id'];
    }

    echo json_encode(['success' => true, 'contracts' => $rows]);
} catch (Throwable $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
