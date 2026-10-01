<?php
/**
 * API: Tasks — Toggle subtask status between To Do and Done
 * POST — JSON body with {id}
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/tenancy.php';
require_once '../../includes/services/checklists.php';   // completion gate (discussion #138)

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}
requireModuleAccessJson('tasks');

$input = json_decode(file_get_contents('php://input'), true);
$id = isset($input['id']) ? (int)$input['id'] : 0;

if (!$id) {
    echo json_encode(['success' => false, 'error' => 'Missing subtask ID']);
    exit;
}

try {
    $conn = connectToDatabase();

    // 🔒 Company scope. A subtask is addressed by its own id here, so it needs its
    // own gate — this is precisely the "child collection" the developer guide warns
    // gets forgotten. A subtask always carries its parent's company.
    if (!analystCanAccessTask($conn, (int) $_SESSION['analyst_id'], $id)) {
        echo json_encode(['success' => false, 'error' => 'Subtask not found']);
        exit;
    }

    // Get current status (joined to lookup) and parent
    $stmt = $conn->prepare(
        "SELECT ts.name AS status, ts.is_closed AS status_is_closed, t.parent_task_id
         FROM tasks t
         LEFT JOIN task_statuses ts ON ts.id = t.status_id
         WHERE t.id = ?"
    );
    $stmt->execute([$id]);
    $task = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$task) {
        echo json_encode(['success' => false, 'error' => 'Subtask not found']);
        exit;
    }

    // Toggle: closed -> the default OPEN status, anything else -> a CLOSED one.
    //
    // Chosen by the is_closed FLAG, never by name. This used to ask for the
    // status literally called 'To Do' or 'Done', which is the same fault as
    // GH #79: those are display names, listed under Tasks > Settings > Statuses
    // precisely so they can be renamed. On any installation that had renamed
    // them — a German site calling them 'Zu erledigen' and 'Erledigt', say —
    // the lookup matched nothing and a subtask could never be completed at all.
    //
    // Reopening prefers the status marked as the default (that is what the
    // default is for) and otherwise takes the first open one in display order.
    // Completing takes the first closed one in display order, which puts 'Done'
    // ahead of 'Cancelled' — completing a subtask must not cancel it.
    $newStatusStmt = $conn->prepare(
        $task['status_is_closed']
            ? "SELECT id, name, is_closed FROM task_statuses
               WHERE is_closed = 0 AND is_active = 1
               ORDER BY is_default DESC, display_order ASC LIMIT 1"
            : "SELECT id, name, is_closed FROM task_statuses
               WHERE is_closed = 1 AND is_active = 1
               ORDER BY display_order ASC LIMIT 1"
    );
    $newStatusStmt->execute();
    $newStatusRow = $newStatusStmt->fetch(PDO::FETCH_ASSOC);
    if (!$newStatusRow) {
        // Reachable only if an administrator has deactivated every status of
        // one kind, so the message names the real problem rather than a word.
        echo json_encode([
            'success' => false,
            'error'   => $task['status_is_closed']
                ? 'No active open status is configured for tasks.'
                : 'No active completed status is configured for tasks.',
        ]);
        exit;
    }
    $newStatusId = (int)$newStatusRow['id'];

    // completed_datetime in UTC, like every other datetime in the app. This used
    // to write date('Y-m-d H:i:s') — the SERVER's local clock — into a column
    // sitting beside updated_datetime = UTC_TIMESTAMP(). On any server not set
    // to UTC the two disagreed, and api/tasks/reorder.php already wrote the same
    // column with UTC_TIMESTAMP(), so the value depended on which route closed it.
    $completedSql = $newStatusRow['is_closed'] ? 'UTC_TIMESTAMP()' : 'NULL';

    // 🔴 Checklist gate (discussion #138), before the write: ticking a subtask
    // done is completing it, and a subtask can carry its own checklist.
    $completing = !empty($newStatusRow['is_closed']) && empty($task['status_is_closed']);
    if ($completing) {
        try {
            ChecklistsService::assertTaskCompletionAllowed($conn, $id);
        } catch (ServiceError $se) {
            echo json_encode(['success' => false, 'error' => $se->getMessage(), 'code' => $se->errorCode]);
            exit;
        }
    }

    $stmt = $conn->prepare(
        "UPDATE tasks SET status_id = ?, completed_datetime = {$completedSql},
                          updated_datetime = UTC_TIMESTAMP()
         WHERE id = ?"
    );
    $stmt->execute([$newStatusId, $id]);
    $newStatus = $newStatusRow['name'];
    if ($completing) {
        ChecklistsService::recordTaskCompletionOverride($conn, ActorContext::fromSession($conn), $id);
    }

    // Update parent's updated_datetime
    if ($task['parent_task_id']) {
        $stmt = $conn->prepare("UPDATE tasks SET updated_datetime = UTC_TIMESTAMP() WHERE id = ?");
        $stmt->execute([$task['parent_task_id']]);
    }

    echo json_encode(['success' => true, 'new_status' => $newStatus]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
