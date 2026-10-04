<?php
/**
 * API Endpoint: Get all email templates
 * GET: Returns all templates ordered by display_order, id
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/template_email.php';   // templateRulesByTemplate() (#80)

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}
requireModuleAccessJson('tickets');

try {
    $conn = connectToDatabase();

    $sql = "SELECT id, name, event_trigger, subject_template, body_template,
                   is_active, display_order, created_datetime, updated_datetime
            FROM ticket_email_templates
            ORDER BY display_order ASC, id ASC";
    $stmt = $conn->prepare($sql);
    $stmt->execute();
    $templates = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Sender rules (#80). Attached to every template so the settings screen can show
    // each one's scope in the list, work out whether a catch-all exists, and edit the
    // rules without a second request per row.
    $rules = templateRulesByTemplate($conn, array_column($templates, 'id'));
    foreach ($templates as &$tpl) {
        $tpl['rules'] = $rules[(int)$tpl['id']] ?? [];
        // 🔑 The screen does NOT keep its own copy of this map. It comes from
        // templateEventAudience() in includes/template_email.php - the same
        // function the sender uses to pick the address - so a column reading
        // "Requester" while the mail goes to an analyst is not reachable.
        $tpl['audience'] = templateEventAudience((string)$tpl['event_trigger']);
    }
    unset($tpl);

    echo json_encode(['success' => true, 'templates' => $templates]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
