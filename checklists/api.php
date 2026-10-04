<?php
session_start(['read_and_close' => false]);
require_once '../config.php';
require_once '../includes/functions.php';
requireModuleAccessJson('checklists');

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

$conn = connectToDatabase();

$analystId = (int)$_SESSION['analyst_id'];
$action = $_GET['action'] ?? $_POST['action'] ?? '';

if ($action === 'get') {
    $id = (int)($_GET['id'] ?? 0);
    $stmt = $conn->prepare("SELECT * FROM checklist_templates WHERE id = ?");
    $stmt->execute([$id]);
    $template = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$template) {
        echo json_encode(['success' => false, 'error' => 'Template not found']);
        exit;
    }
    $itemStmt = $conn->prepare("SELECT * FROM checklist_template_items WHERE template_id = ? ORDER BY sort_order ASC, id ASC");
    $itemStmt->execute([$id]);
    $template['items'] = $itemStmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['success' => true, 'template' => $template]);
    exit;
}

if ($action === 'save') {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) { $input = $_POST; }

    $id = !empty($input['id']) ? (int)$input['id'] : 0;
    $title = trim($input['title'] ?? '');
    $category = trim($input['category'] ?? 'General');
    $scope = in_array($input['scope'] ?? '', ['ticket', 'task', 'both']) ? $input['scope'] : 'both';
    $closureMode = (($input['closure_mode'] ?? '') === 'block') ? 'block' : 'warn';
    $description = trim($input['description'] ?? '');
        $keywords = trim($input['keywords'] ?? '');
    $items = is_array($input['items'] ?? null) ? $input['items'] : [];

    if ($title === '') {
        echo json_encode(['success' => false, 'error' => 'Title is required']);
        exit;
    }

    if ($id > 0) {
        $stmt = $conn->prepare("UPDATE checklist_templates SET title = ?, category = ?, scope = ?, closure_mode = ?, description = ?, keywords = ? WHERE id = ?");
        $stmt->execute([$title, $category, $scope, $closureMode, $description, $keywords, $id]);
    } else {
        $stmt = $conn->prepare("INSERT INTO checklist_templates (title, category, scope, closure_mode, description, keywords, created_by_id) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$title, $category, $scope, $closureMode, $description, $keywords, $analystId]);
        $id = (int)$conn->lastInsertId();
    }

    // Auto-create category in checklist_categories
    if ($category !== '') {
        $catStmt = $conn->prepare("INSERT IGNORE INTO checklist_categories (name) VALUES (?)");
        $catStmt->execute([$category]);
    }

    // 🔴 The steps INHERIT the template's is_demo flag.
    //
    // Editing a demo template rewrites its steps, and a freshly inserted row
    // defaults to is_demo = 0 while the parent stays 1. Demo removal is
    // `DELETE FROM <table> WHERE is_demo = 1` per table, so the template would
    // go and its steps would be left behind pointing at nothing — which is
    // exactly what happened to the seeded "Firewall rule change" the first time
    // somebody edited it. Parent and children must agree or removal orphans.
    $isDemo = (int)$conn->query("SELECT is_demo FROM checklist_templates WHERE id = " . (int)$id)->fetchColumn();

    $del = $conn->prepare("DELETE FROM checklist_template_items WHERE template_id = ?");
    $del->execute([$id]);

    $ins = $conn->prepare("INSERT INTO checklist_template_items (template_id, title, suggested_role, is_mandatory, requires_input, input_placeholder, sort_order, is_demo) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    foreach ($items as $idx => $it) {
        $itTitle = is_string($it) ? trim($it) : trim($it['title'] ?? '');
        if ($itTitle === '') continue;
        $isMand = !empty($it['is_mandatory']) ? 1 : 0;
        $role = is_array($it) ? trim($it['suggested_role'] ?? '') : '';
        $reqInput = !empty($it['requires_input']) ? 1 : 0;
        $placeholder = is_array($it) ? trim($it['input_placeholder'] ?? '') : '';
        $ins->execute([$id, $itTitle, $role, $isMand, $reqInput, $placeholder, $idx + 1, $isDemo]);
    }

    echo json_encode(['success' => true, 'id' => $id]);
    exit;
}

if ($action === 'delete') {
    // POST only: a delete must never be reachable from a link (S4).
    $id = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' ? (int)($_POST['id'] ?? 0) : 0;
    if ($id > 0) {
        // 🔴 Children first, explicitly - there is no FK on template_id, and a
        // Database-Verification-grown install would not have one even if there were.
        // Deleting the template alone stranded its steps permanently.
        $conn->beginTransaction();
        try {
            $conn->prepare("DELETE FROM checklist_template_items WHERE template_id = ?")->execute([$id]);
            $conn->prepare("DELETE FROM checklist_templates WHERE id = ?")->execute([$id]);
            $conn->commit();
        } catch (Throwable $e) {
            $conn->rollBack();
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
            exit;
        }
        echo json_encode(['success' => true]);
        exit;
    }
    echo json_encode(['success' => false, 'error' => 'Invalid ID']);
    exit;
}

echo json_encode(['success' => false, 'error' => 'Invalid action']);
