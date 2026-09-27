<?php
/**
 * API: Feature Bingo (System -> Feature Bingo).
 *
 *   GET                                               every card with its state, and the totals
 *   POST {action:'dismiss'|'restore', card_id}        "Not for us" on one card, or undo it
 *   POST {action:'dismiss_module'|'restore_module', module}   ... on every card in a module
 *
 * Administrators only. The cards are code (includes/feature_bingo/cards/); the
 * only thing stored is which ones are "Not for us".
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/admin_api_guard.php';
require_once '../../includes/functions.php';
require_once '../../includes/feature_bingo.php';

header('Content-Type: application/json');

try {
    $conn = connectToDatabase();

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $r = featureBingoEvaluate($conn);
        echo json_encode([
            'success'    => true,
            'cards'      => $r['cards'],
            'lit'        => $r['lit'],
            'total'      => $r['total'],
            'dismissed'  => $r['dismissed'],
            'modules'    => featureBingoModules(),
            'categories' => featureBingoCategories(),
            'tiers'      => FEATURE_BINGO_TIERS,
            'ready'      => featureBingoReady($conn),
        ]);
        exit;
    }

    $in = json_decode(file_get_contents('php://input'), true) ?: [];
    $action = (string)($in['action'] ?? '');
    if (!featureBingoReady($conn)) throw new Exception('Run System -> Database Verification first.');

    // Only real card ids - never whatever the request says.
    $ids = [];
    $cards = featureBingoCards();
    if ($action === 'dismiss' || $action === 'restore') {
        foreach ($cards as $c) if ($c['id'] === (string)($in['card_id'] ?? '')) $ids[] = $c['id'];
    } elseif ($action === 'dismiss_module' || $action === 'restore_module') {
        foreach ($cards as $c) if ($c['module'] === (string)($in['module'] ?? '')) $ids[] = $c['id'];
    } else {
        throw new Exception('Unknown action');
    }
    if (!$ids) throw new Exception('No such card');

    if (strpos($action, 'dismiss') === 0) {
        $st = $conn->prepare("INSERT IGNORE INTO feature_bingo_dismissed (card_id, dismissed_by_analyst_id, dismissed_datetime) VALUES (?, ?, UTC_TIMESTAMP())");
        foreach ($ids as $id) $st->execute([$id, (int)$_SESSION['analyst_id']]);
    } else {
        $in2 = implode(',', array_fill(0, count($ids), '?'));
        $conn->prepare("DELETE FROM feature_bingo_dismissed WHERE card_id IN ($in2)")->execute($ids);
    }
    echo json_encode(['success' => true, 'changed' => count($ids)]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
