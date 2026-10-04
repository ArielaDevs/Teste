<?php
/**
 * API: list changes that can be linked to a problem (the fix), excluding any already
 * linked. Optional ?q search on title/id. Used by the "Link change" picker.
 * Changes aren't tenant-scoped, so no company filter here (mirrors change list.php).
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/tenancy.php';

header('Content-Type: application/json');
if (!isset($_SESSION['analyst_id'])) { echo json_encode(['success' => false, 'error' => 'Not authenticated']); exit; }
requireModuleAccessJson('problems');

try {
    $conn = connectToDatabase();
    $analystId = (int) $_SESSION['analyst_id'];
    $problemId = (int) ($_GET['problem_id'] ?? 0);
    if ($problemId <= 0) throw new Exception('Problem ID is required');
    if (!analystCanAccessProblem($conn, $analystId, $problemId)) throw new Exception('Problem not found');

    // Exclude changes already linked to this problem.
    $where = " WHERE NOT EXISTS (SELECT 1 FROM change_relations cr WHERE cr.change_id = c.id AND cr.related_type = 'problem' AND cr.related_id = ?)";
    $params = [$problemId];

    if (isset($_GET['q']) && trim($_GET['q']) !== '') {
        $where .= " AND (c.title LIKE ? OR CAST(c.id AS CHAR) LIKE ?)";
        $q = '%' . trim($_GET['q']) . '%'; $params[] = $q; $params[] = $q;
    }

    $sql = "SELECT c.id, c.title, c.created_datetime,
                   cs.name AS status, cp.name AS priority
            FROM changes c
            LEFT JOIN change_statuses cs ON cs.id = c.status_id
            LEFT JOIN change_priorities cp ON cp.id = c.priority_id
            $where
            ORDER BY c.created_datetime DESC
            LIMIT 200";
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    echo json_encode(['success' => true, 'changes' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
