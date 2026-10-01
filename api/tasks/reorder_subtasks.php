<?php
/**
 * API: Tasks - put a task's subtasks in a new order (dragged in the task window)
 * POST - JSON body {parent_id, ids: [subtask id, ...]} in the new order.
 *
 * The order is stored as each subtask's board_position, which get.php and the
 * board's subtask list already sort by. Subtasks never sit on the board as cards
 * of their own, so the column means nothing else for them.
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
requireModuleAccessJson('tasks');

$input    = json_decode(file_get_contents('php://input'), true) ?: [];
$parentId = isset($input['parent_id']) ? (int)$input['parent_id'] : 0;
$ids      = array_values(array_filter(array_map('intval', (array)($input['ids'] ?? []))));

if (!$parentId || !$ids) {
    echo json_encode(['success' => false, 'error' => 'Missing parent_id or ids']);
    exit;
}

try {
    $conn = connectToDatabase();

    // 🔒 The parent is the gate. Every UPDATE below is also pinned to
    // parent_task_id, so an id belonging to some other task - another
    // company's, or just another parent's - matches no row and changes nothing.
    if (!analystCanAccessTask($conn, (int)$_SESSION['analyst_id'], $parentId)) {
        echo json_encode(['success' => false, 'error' => 'Task not found']);
        exit;
    }

    $conn->beginTransaction();
    $stmt = $conn->prepare("UPDATE tasks SET board_position = ? WHERE id = ? AND parent_task_id = ?");
    foreach ($ids as $pos => $id) {
        $stmt->execute([$pos, $id, $parentId]);
    }
    $conn->commit();
    echo json_encode(['success' => true]);
} catch (Exception $e) {
    if (isset($conn) && $conn->inTransaction()) {
        $conn->rollBack();
    }
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
