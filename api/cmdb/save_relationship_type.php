<?php
/**
 * API: Create or update a CMDB relationship type.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/rbac.php';
require_once '../../includes/cmdb_impact.php';   // impact direction vocabulary

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

requireModuleAccessJson('cmdb');
requireCapabilityJson(Cap::CMDB_RELATIONSHIP_TYPES);   // settings tab — see docs/design/rbac.md

try {
    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    $id = isset($data['id']) && $data['id'] !== '' ? (int)$data['id'] : null;
    $verb = trim((string)($data['verb'] ?? ''));
    $inverse = trim((string)($data['inverse_verb'] ?? ''));
    $description = trim((string)($data['description'] ?? ''));
    $displayOrder = isset($data['display_order']) ? (int)$data['display_order'] : 0;
    $isActive = !empty($data['is_active']) ? 1 : 0;

    // Whether a failure travels along this relationship, and which way. Anything
    // unrecognised falls back to 'none' rather than being stored — an unknown
    // value would silently drop the edge from the blast radius with no clue why.
    $impactDirection = (string)($data['impact_direction'] ?? CMDB_IMPACT_NONE);
    if (!in_array($impactDirection, cmdbImpactDirections(), true)) {
        $impactDirection = CMDB_IMPACT_NONE;
    }

    if ($verb === '') throw new Exception('Verb is required');
    if ($inverse === '') throw new Exception('Inverse verb is required');
    if (mb_strlen($verb) > 100) throw new Exception('Verb too long (max 100 chars)');
    if (mb_strlen($inverse) > 100) throw new Exception('Inverse verb too long (max 100 chars)');

    $conn = connectToDatabase();

    // Refuse duplicate verbs - any OTHER type with this verb. On create there is no
    // id yet, and 0 matches no row, so every existing type is compared (GH #163:
    // this used to paste $id into the SQL, and a null id became "( IS NULL OR …",
    // so adding a relationship type failed with a syntax error every time).
    $check = $conn->prepare("SELECT id FROM cmdb_relationship_types WHERE verb = ? AND id <> ?");
    $check->execute([$verb, $id ?? 0]);
    if ($check->fetch()) {
        throw new Exception('Another relationship type already uses that verb');
    }

    if ($id === null) {
        $stmt = $conn->prepare(
            "INSERT INTO cmdb_relationship_types (verb, inverse_verb, description, impact_direction, display_order, is_active, created_datetime)
             VALUES (?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())"
        );
        $stmt->execute([$verb, $inverse, $description ?: null, $impactDirection, $displayOrder, $isActive]);
        $newId = (int)$conn->lastInsertId();
        wf_emit('cmdb_relationship_type', 'created', $newId, $verb);
        echo json_encode(['success' => true, 'id' => $newId]);
    } else {
        $stmt = $conn->prepare(
            "UPDATE cmdb_relationship_types
                SET verb = ?, inverse_verb = ?, description = ?, impact_direction = ?, display_order = ?, is_active = ?
              WHERE id = ?"
        );
        $stmt->execute([$verb, $inverse, $description ?: null, $impactDirection, $displayOrder, $isActive, $id]);
        wf_emit('cmdb_relationship_type', 'updated', $id, $verb);
        echo json_encode(['success' => true, 'id' => $id]);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
