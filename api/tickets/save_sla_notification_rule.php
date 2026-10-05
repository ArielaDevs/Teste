<?php
/**
 * API: Create or update an SLA breach notification rule.
 *
 * POST JSON body:
 *   id (optional — update if present, else create)
 *   department_id (int|null — null = global default rule)
 *   trigger_type ('warning' | 'breach')
 *   target_type ('response' | 'resolution' | 'both')
 *   notify_assignee, notify_department_teams (bool)
 *   notify_analyst_id (int|null)
 *   notify_emails (string — comma/semicolon/newline separated; normalised here)
 *   is_active (bool, default true)
 *
 * Enforces:
 *   - At least one recipient (otherwise the rule would silently no-op)
 *   - One rule per (department_id, trigger_type, target_type) — refuse overlap
 *   - target_type 'both' refuses to coexist with explicit 'response'/'resolution'
 *     for the same dept+trigger (the 'both' rule would shadow them)
 *   - Email format validation
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/rbac.php';
require_once '../../includes/tenancy.php';
header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}
requireModuleAccessJson('tickets');
requireCapabilityJson(Cap::TICKETS_SLA);   // settings tab — see docs/design/rbac.md

try {
    $data = json_decode(file_get_contents('php://input'), true) ?: [];

    $id            = !empty($data['id']) ? (int)$data['id'] : null;
    $departmentId  = isset($data['department_id']) && $data['department_id'] !== '' && $data['department_id'] !== null
                        ? (int)$data['department_id'] : null;
    $trigger       = $data['trigger_type'] ?? '';
    $target        = $data['target_type'] ?? 'both';
    $notifyAssign  = !empty($data['notify_assignee']) ? 1 : 0;
    $notifyTeams   = !empty($data['notify_department_teams']) ? 1 : 0;
    $notifyAnalyst = !empty($data['notify_analyst_id']) ? (int)$data['notify_analyst_id'] : null;
    $rawEmails     = trim((string)($data['notify_emails'] ?? ''));
    $isActive      = isset($data['is_active']) ? (!empty($data['is_active']) ? 1 : 0) : 1;

    if (!in_array($trigger, ['warning', 'breach'], true)) {
        throw new Exception('trigger_type must be warning or breach');
    }
    if (!in_array($target, ['response', 'resolution', 'both'], true)) {
        throw new Exception('target_type must be response, resolution, or both');
    }

    // Normalise the email list — split on , ; or whitespace, trim, dedupe, validate
    $emails = [];
    if ($rawEmails !== '') {
        $parts = preg_split('/[,;\s]+/', $rawEmails) ?: [];
        foreach ($parts as $p) {
            $p = trim($p);
            if ($p === '') continue;
            if (!filter_var($p, FILTER_VALIDATE_EMAIL)) {
                throw new Exception("Not a valid email address: $p");
            }
            $emails[strtolower($p)] = $p;
        }
    }
    $emailsCsv = $emails ? implode(',', array_values($emails)) : null;

    if (!$notifyAssign && !$notifyTeams && !$notifyAnalyst && !$emailsCsv) {
        throw new Exception('Pick at least one recipient — assignee, department teams, a specific analyst, or one or more email addresses.');
    }

    $conn = connectToDatabase();
    $analystId = (int)$_SESSION['analyst_id'];

    // F7: the rule being overwritten must be one the analyst may reach, and
    // the department it is filed under must be reachable too — otherwise a
    // rule for company B could be retargeted at (or read through) company A.
    // Same 403 answer as "not found" so the gate creates no existence oracle.
    if ($id && !analystCanAccessSlaNotificationRule($conn, $analystId, $id)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Rule not found']);
        exit;
    }
    if ($departmentId !== null && !analystCanAccessDepartment($conn, $analystId, $departmentId)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Rule not found']);
        exit;
    }

    // Company scope for the write. New rules land in the analyst's active
    // company (server-derived, never from the client). An UPDATE never moves
    // a rule between companies — it keeps its existing tenant, so an
    // all-access analyst editing company B's rule while working in A does not
    // silently refile it. Skipped entirely pre-migration so the endpoint keeps
    // working before Database Verify adds the column.
    $ruleTenantSupported = tenancyColumnExists($conn, 'sla_notification_rules', 'tenant_id');
    $ruleTenantId = null;
    if ($ruleTenantSupported && !isMultiTenant($conn)) {
        // Dormant install: stamp the Default company so a later second tenant
        // wakes with every existing row correctly attributed.
        $ruleTenantId = getDefaultTenantId($conn);
    }
    if ($ruleTenantSupported && isMultiTenant($conn)) {
        if ($id) {
            $tStmt = $conn->prepare("SELECT tenant_id FROM sla_notification_rules WHERE id = ?");
            $tStmt->execute([$id]);
            $tRow = $tStmt->fetch(PDO::FETCH_ASSOC);
            $ruleTenantId = ($tRow && $tRow['tenant_id'] !== null) ? (int)$tRow['tenant_id'] : null;
        } else {
            $ruleTenantId = getActiveTenantId($conn, $analystId);
        }
        // A GLOBAL (NULL-tenant) rule fires for every company: writing one
        // takes reach over every company, never just the settings capability.
        if ($ruleTenantId === null && !analystHasAllTenantAccess($conn, $analystId)) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Rule not found']);
            exit;
        }
        if ($ruleTenantId !== null && !analystCanAccessTenant($conn, $analystId, $ruleTenantId)) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Rule not found']);
            exit;
        }
    }

    // Conflict detection: refuse to create overlapping rules for the same
    // dept + trigger within the same company scope. NULL dept matches NULL
    // dept; 'both' shadows the single targets and vice-versa. Scoped to the
    // rule's own tenant (NULL matches NULL) so company A's rules never block
    // company B's, and global rules never collide with company ones.
    $conflictSql = "SELECT id, target_type FROM sla_notification_rules
                     WHERE trigger_type = ?
                       AND ((? IS NULL AND department_id IS NULL) OR department_id = ?)";
    $params = [$trigger, $departmentId, $departmentId];
    if ($ruleTenantSupported && isMultiTenant($conn)) {
        $conflictSql .= " AND ((? IS NULL AND tenant_id IS NULL) OR tenant_id = ?)";
        $params[] = $ruleTenantId;
        $params[] = $ruleTenantId;
    }
    if ($id) {
        $conflictSql .= " AND id <> ?";
        $params[] = $id;
    }
    $stmt = $conn->prepare($conflictSql);
    $stmt->execute($params);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $existing) {
        $existingTarget = $existing['target_type'];
        if ($existingTarget === $target
            || $existingTarget === 'both'
            || $target === 'both') {
            $scope = $departmentId ? "this department" : "the default rule";
            throw new Exception("A '$trigger' rule already exists for $scope (target: $existingTarget). Edit that rule or pick a different combination.");
        }
    }

    if ($id) {
        $sql = "UPDATE sla_notification_rules
                   SET department_id = ?, trigger_type = ?, target_type = ?,
                       notify_assignee = ?, notify_department_teams = ?,
                       notify_analyst_id = ?, notify_emails = ?, is_active = ?
                 WHERE id = ?";
        $conn->prepare($sql)->execute([
            $departmentId, $trigger, $target,
            $notifyAssign, $notifyTeams,
            $notifyAnalyst, $emailsCsv, $isActive,
            $id,
        ]);
    } else {
        if ($ruleTenantSupported) {
            $sql = "INSERT INTO sla_notification_rules
                        (department_id, tenant_id, trigger_type, target_type,
                         notify_assignee, notify_department_teams,
                         notify_analyst_id, notify_emails, is_active)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
            $conn->prepare($sql)->execute([
                $departmentId, $ruleTenantId, $trigger, $target,
                $notifyAssign, $notifyTeams,
                $notifyAnalyst, $emailsCsv, $isActive,
            ]);
        } else {
            $sql = "INSERT INTO sla_notification_rules
                        (department_id, trigger_type, target_type,
                         notify_assignee, notify_department_teams,
                         notify_analyst_id, notify_emails, is_active)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
            $conn->prepare($sql)->execute([
                $departmentId, $trigger, $target,
                $notifyAssign, $notifyTeams,
                $notifyAnalyst, $emailsCsv, $isActive,
            ]);
        }
        $id = (int)$conn->lastInsertId();
    }

    echo json_encode(['success' => true, 'id' => $id]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
