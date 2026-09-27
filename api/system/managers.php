<?php
/**
 * API: System -> Managers (discussion #62, step 3).
 *
 *   GET  ?action=settings                       the settings, and whether managers can run
 *   POST {action:'save', settings:{...}}        save the settings
 *   GET  ?action=overview&search=&page=N        every manager, a page at a time
 *
 * Administrators only. The rules themselves live in includes/managers.php; this
 * endpoint only reads and writes the `managers_*` settings and reports on them.
 *
 * ⚠️ The overview is PAGED and searched on the server. On a large organisation
 * "every manager" is every line manager in the directory - thousands - and each
 * row's "can see N people" is several queries. Never "load everyone".
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/admin_api_guard.php';   // System admins only
require_once '../../includes/functions.php';
require_once '../../includes/managers.php';

header('Content-Type: application/json');

const MANAGERS_OVERVIEW_PAGE = 25;

/** Everything a settings value may be. Anything else is refused, not coerced. */
function managersSettingRules(): array
{
    return [
        'enabled'         => ['0', '1'],
        'directory'       => ['0', '1'],
        'directory_depth' => ['direct', 'all'],
        'leavers'         => ['0', '1'],
        'confidential'    => ['none', 'stub', 'all'],
        'can_reply'       => ['0', '1'],
        'can_close'       => ['0', '1'],
        'edit_by'         => ['admins', 'people_editors'],
    ];
}

/** Can the rules run at all on this install - and if not, why not. */
function managersStatus(PDO $conn): array
{
    $missing = [];
    if (!ticketSensitivityReady($conn)) $missing[] = 'sensitivity';
    if (!ticketViewsReady($conn))       $missing[] = 'views';
    try { $conn->query("SELECT grant_type FROM manager_grants LIMIT 0"); }
    catch (Throwable $e) { $missing[] = 'manager_grants'; }
    return ['needs_verify' => (bool)$missing, 'active' => managersActive($conn)];
}

try {
    $conn = connectToDatabase();
    $method = $_SERVER['REQUEST_METHOD'];
    $action = $method === 'POST'
        ? ((json_decode(file_get_contents('php://input'), true) ?: [])['action'] ?? '')
        : ($_GET['action'] ?? '');

    if ($method === 'GET' && $action === 'settings') {
        echo json_encode(['success' => true, 'settings' => managersSettings($conn), 'status' => managersStatus($conn)]);
        exit;
    }

    if ($method === 'POST' && $action === 'save') {
        $in = (json_decode(file_get_contents('php://input'), true) ?: [])['settings'] ?? [];
        $rules = managersSettingRules();
        $write = $conn->prepare(
            "INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)"
        );
        foreach ($in as $k => $v) {
            if (!isset($rules[$k])) continue;                       // unknown keys ignored
            $v = (string)$v;
            if (!in_array($v, $rules[$k], true)) {
                echo json_encode(['success' => false, 'error' => "Invalid value for $k"]);
                exit;
            }
            $write->execute(['managers_' . $k, $v]);
        }
        managersResetMemo();
        echo json_encode(['success' => true, 'settings' => managersSettings($conn), 'status' => managersStatus($conn)]);
        exit;
    }

    if ($method === 'GET' && $action === 'overview') {
        $status = managersStatus($conn);
        if ($status['needs_verify']) {
            echo json_encode(['success' => true, 'managers' => [], 'total' => 0, 'status' => $status]);
            exit;
        }
        $settings = managersSettings($conn);
        $search   = trim((string)($_GET['search'] ?? ''));
        $page     = max(1, (int)($_GET['page'] ?? 1));

        // Who is a manager: anyone with a line, plus - when the directory counts -
        // anyone somebody reports to.
        $sources = "SELECT manager_user_id AS id FROM manager_grants WHERE is_exclusion = 0";
        if ($settings['directory'] === '1') {
            $sources .= " UNION SELECT manager_id FROM users WHERE manager_id IS NOT NULL";
        }
        $where = ''; $args = [];
        if ($search !== '') {
            $where = " AND (u.display_name LIKE ? OR u.email LIKE ?)";
            $args = ['%' . $search . '%', '%' . $search . '%'];
        }
        $countSt = $conn->prepare("SELECT COUNT(*) FROM users u JOIN ($sources) m ON m.id = u.id WHERE 1=1 $where");
        $countSt->execute($args);
        $total = (int)$countSt->fetchColumn();

        $offset = ($page - 1) * MANAGERS_OVERVIEW_PAGE;
        $st = $conn->prepare(
            "SELECT u.id, COALESCE(NULLIF(u.display_name, ''), u.email) AS name, u.email, u.is_active,
                    u.tenant_id, tn.name AS company,
                    (SELECT COUNT(*) FROM manager_grants g WHERE g.manager_user_id = u.id AND g.is_exclusion = 0) AS line_count,
                    (SELECT COUNT(*) FROM manager_grants g WHERE g.manager_user_id = u.id AND g.is_exclusion = 1) AS exclusion_count,
                    (SELECT COUNT(*) FROM users r WHERE r.manager_id = u.id) AS direct_reports
               FROM users u
               JOIN ($sources) m ON m.id = u.id
          LEFT JOIN tenants tn ON tn.id = u.tenant_id
              WHERE 1=1 $where
           ORDER BY name
              LIMIT " . MANAGERS_OVERVIEW_PAGE . " OFFSET " . $offset
        );
        $st->execute($args);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);

        $deptLines = $conn->prepare("SELECT target_value FROM manager_grants WHERE manager_user_id = ? AND grant_type = 'department'");
        foreach ($rows as &$r) {
            $id = (int)$r['id'];
            // As if managers were ON, so an admin can check before switching.
            $r['can_see'] = count(managerTeamPreviewIds($conn, $id));
            $warn = [];
            if ((int)$r['is_active'] !== 1) $warn[] = ['kind' => 'left'];
            // A department line that matches nobody: most often a renamed
            // department, because the names are free text.
            $deptLines->execute([$id]);
            foreach ($deptLines->fetchAll(PDO::FETCH_COLUMN) as $dept) {
                // Judged within what this manager could ever reach - their company
                // - through the SAME rule the team uses (managerReachSql).
                [$reach, $reachArgs] = managerReachSql($conn, ['id' => $id, 'tenant_id' => $r['tenant_id']]);
                $n = managerGrantMembers($conn, ['grant_type' => 'department', 'target_id' => 0, 'target_value' => $dept],
                                         $id, $reach, $reachArgs);
                if (!$n) $warn[] = ['kind' => 'empty_department', 'name' => $dept];
            }
            $r['warnings'] = $warn;
            $r['is_active'] = (int)$r['is_active'] === 1;
        }
        unset($r);

        echo json_encode([
            'success' => true, 'managers' => $rows, 'total' => $total,
            'page' => $page, 'per_page' => MANAGERS_OVERVIEW_PAGE, 'status' => $status,
        ]);
        exit;
    }

    echo json_encode(['success' => false, 'error' => 'Unknown request']);
} catch (Exception $e) {
    error_log('[managers] ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Could not complete the request']);
}
