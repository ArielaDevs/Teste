<?php
/**
 * API: one manager's management lines - the full-screen Manager access page
 * (includes/manager_access_page.php), discussion #62 step 3.
 *
 *   GET  ?action=get&manager=N                              lines, exclusions, who they can see
 *   GET  ?action=search&manager=N&kind=user|group|department&q=&page=P
 *   POST {action:'add', manager, grant_type, target_id, target_value, is_exclusion}
 *   POST {action:'remove', manager, line_id}
 *
 * WHO MAY
 * -------
 * Reached from Tickets -> Users AND Assets -> Users, so either module lets you
 * in, and the manager must be somebody this analyst can reach - a refusal reads
 * exactly like an id that does not exist. READING is open to those people;
 * CHANGING is System -> Managers' "who may set up management lines"
 * (managerLinesEditable), because a line lets one person read another's tickets.
 *
 * ⚠️ Every search is PAGED on the server and runs inside managerReachSql() -
 * the manager's own company, not themselves, leavers per the setting. So the
 * picker can only ever offer somebody the line could actually reach, and "123
 * people" beside a department is the number the manager would really get. The
 * write path re-checks a picked person against the same rule: a scoped list is
 * not a check, an id in a JSON body is any integer.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/tenancy.php';
require_once '../../includes/managers.php';

header('Content-Type: application/json');
requireAnyModuleAccessJson(['tickets', 'assets']);

const MANAGER_ACCESS_PAGE = 25;      // search results per page
const MANAGER_ACCESS_PREVIEW = 200;  // names listed under "Who they can see"

/** Refuse, in the one shape every refusal takes here. */
function maFail(string $error): void
{
    echo json_encode(['success' => false, 'error' => $error]);
    exit;
}

/** The manager, if this analyst may see them. */
function maManager(PDO $conn, int $id): ?array
{
    if ($id <= 0 || !analystCanAccessUser($conn, (int)$_SESSION['analyst_id'], $id)) return null;
    $st = $conn->prepare(
        "SELECT u.id, COALESCE(NULLIF(u.display_name, ''), NULLIF(u.email, ''), u.username) AS name,
                u.email, u.job_title, u.department, u.is_active, u.tenant_id, tn.name AS company
           FROM users u LEFT JOIN tenants tn ON tn.id = u.tenant_id
          WHERE u.id = ?"
    );
    $st->execute([$id]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

/** Are the tables there? Before DB Verify there is nothing to show or change. */
function maReady(PDO $conn): bool
{
    try { $conn->query("SELECT grant_type FROM manager_grants LIMIT 0"); return true; }
    catch (Throwable $e) { return false; }
}

/** Everything the page shows for one manager. */
function maState(PDO $conn, array $m): array
{
    $settings = managersSettings($conn);
    $out = [
        'manager'  => [
            'id' => (int)$m['id'], 'name' => $m['name'], 'email' => $m['email'],
            'job_title' => $m['job_title'], 'department' => $m['department'],
            'company' => isMultiTenant($conn) ? ($m['company'] ?? null) : null,
            'is_active' => (int)$m['is_active'] === 1,
        ],
        'settings' => [
            'enabled'         => $settings['enabled'] === '1',
            'directory'       => $settings['directory'] === '1',
            'directory_depth' => $settings['directory_depth'],
            'leavers'         => $settings['leavers'] === '1',
        ],
        'can_edit'     => managerLinesEditable($conn),
        'is_admin'     => sessionIsAdmin(),
        'needs_verify' => !maReady($conn),
        'lines'        => [],
        'reports'      => 0,
        'team_total'   => 0,
        'team'         => [],
    ];
    if ($out['needs_verify']) { $out['can_edit'] = false; return $out; }

    [$base, $baseArgs] = managerReachSql($conn, $m);

    $st = $conn->prepare(
        "SELECT g.id, g.grant_type, g.target_id, g.target_value, g.is_exclusion, g.created_datetime,
                COALESCE(NULLIF(tu.display_name, ''), NULLIF(tu.email, ''), tu.username) AS user_name,
                kg.name AS group_name, a.full_name AS added_by
           FROM manager_grants g
      LEFT JOIN users tu ON g.grant_type = 'user' AND tu.id = g.target_id
      LEFT JOIN knowledge_user_groups kg ON g.grant_type = 'group' AND kg.id = g.target_id
      LEFT JOIN analysts a ON a.id = g.created_by_analyst_id
          WHERE g.manager_user_id = ?
       ORDER BY g.is_exclusion, g.grant_type, g.id"
    );
    $st->execute([(int)$m['id']]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $g) {
        $label = null;
        if ($g['grant_type'] === 'user')       $label = $g['user_name'];
        elseif ($g['grant_type'] === 'group')  $label = $g['group_name'];
        elseif ($g['grant_type'] === 'department') $label = $g['target_value'];
        $out['lines'][] = [
            'id'           => (int)$g['id'],
            'grant_type'   => $g['grant_type'],
            'target_id'    => (int)$g['target_id'],
            'target_value' => $g['target_value'],
            'is_exclusion' => (int)$g['is_exclusion'] === 1,
            'label'        => $label,              // null = the person or group is gone
            'members'      => count(managerGrantMembers($conn, $g, (int)$m['id'], $base, $baseArgs)),
            'added_by'     => $g['added_by'],
            'added'        => $g['created_datetime'],
        ];
    }

    // What the Manager field alone gives them, for the "from the directory" row.
    $out['reports'] = count(managerGrantMembers($conn,
        ['grant_type' => 'reports', 'target_id' => 0, 'target_value' => $settings['directory_depth'] === 'all' ? 'all' : ''],
        (int)$m['id'], $base, $baseArgs));

    // As if managers were on - the whole point is to check before switching.
    $ids = managerTeamPreviewIds($conn, (int)$m['id']);
    $out['team_total'] = count($ids);
    if ($ids) {
        $slice = array_slice($ids, 0, 5000);   // bounded IN list; the page lists 200 anyway
        $in = implode(',', array_fill(0, count($slice), '?'));
        $st = $conn->prepare(
            "SELECT u.id, COALESCE(NULLIF(u.display_name, ''), NULLIF(u.email, ''), u.username) AS name,
                    u.department, u.is_active
               FROM users u WHERE u.id IN ($in)
           ORDER BY name LIMIT " . MANAGER_ACCESS_PREVIEW
        );
        $st->execute($slice);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $p) {
            $out['team'][] = ['id' => (int)$p['id'], 'name' => $p['name'], 'department' => $p['department'],
                              'is_active' => (int)$p['is_active'] === 1];
        }
    }
    return $out;
}

try {
    $conn = connectToDatabase();
    $isPost = $_SERVER['REQUEST_METHOD'] === 'POST';
    $in = $isPost ? (json_decode(file_get_contents('php://input'), true) ?: []) : $_GET;
    $action = (string)($in['action'] ?? '');

    $m = maManager($conn, (int)($in['manager'] ?? 0));
    if (!$m) maFail('Person not found');

    if (!$isPost && $action === 'get') {
        echo json_encode(['success' => true] + maState($conn, $m));
        exit;
    }

    if (!maReady($conn)) maFail('Run System -> Database Verification first');
    [$base, $baseArgs] = managerReachSql($conn, $m);

    // Why can this manager see this person? Every reason, so the page can explain
    // it and offer the way to stop each one. Worked out as if managers were on,
    // like the rest of the page.
    if (!$isPost && $action === 'why') {
        $pid = (int)($in['person'] ?? 0);
        $pst = $conn->prepare(
            "SELECT u.id, COALESCE(NULLIF(u.display_name, ''), NULLIF(u.email, ''), u.username) AS name,
                    u.department, u.job_title, u.is_active
               FROM users u WHERE $base AND u.id = ?"
        );
        $pst->execute(array_merge($baseArgs, [$pid]));
        $p = $pst->fetch(PDO::FETCH_ASSOC);
        if (!$p) maFail('Person not found');

        $settings = managersSettings($conn);
        $onTeam = in_array($pid, managerTeamPreviewIds($conn, (int)$m['id']), true);

        // The reporting line from the person up to the manager, as names -
        // walked the same bounded way the engine walks down it.
        $chain = function () use ($conn, $pid, $m): array {
            $up = $conn->prepare("SELECT manager_id, COALESCE(NULLIF(display_name, ''), NULLIF(email, ''), username) AS name FROM users WHERE id = ?");
            $path = []; $seen = [];
            $up->execute([$pid]);
            $cur = (int)($up->fetch(PDO::FETCH_ASSOC)['manager_id'] ?? 0);
            for ($i = 0; $i < 25 && $cur && !isset($seen[$cur]); $i++) {
                if ($cur === (int)$m['id']) return $path;      // names in between; [] = reports directly
                $seen[$cur] = true;
                $up->execute([$cur]);
                $r = $up->fetch(PDO::FETCH_ASSOC);
                if (!$r) break;
                $path[] = $r['name'];
                $cur = (int)$r['manager_id'];
            }
            return ['__none__'];
        };

        $reasons = [];
        if ($settings['directory'] === '1') {
            $c = $chain();
            if ($c !== ['__none__'] && ($settings['directory_depth'] === 'all' || !$c)) {
                $reasons[] = ['kind' => 'directory', 'via' => $c];
            }
        }
        $g = $conn->prepare(
            "SELECT g.id, g.grant_type, g.target_id, g.target_value, kg.name AS group_name
               FROM manager_grants g
          LEFT JOIN knowledge_user_groups kg ON g.grant_type = 'group' AND kg.id = g.target_id
              WHERE g.manager_user_id = ? AND g.is_exclusion = 0"
        );
        $g->execute([(int)$m['id']]);
        foreach ($g->fetchAll(PDO::FETCH_ASSOC) as $line) {
            if (!in_array($pid, managerGrantMembers($conn, $line, (int)$m['id'], $base, $baseArgs), true)) continue;
            $r = ['kind' => $line['grant_type'], 'line_id' => (int)$line['id'],
                  'name' => $line['grant_type'] === 'group' ? $line['group_name'] : $line['target_value']];
            if ($line['grant_type'] === 'reports') { $c = $chain(); $r['via'] = $c === ['__none__'] ? [] : $c; $r['all'] = $line['target_value'] === 'all'; }
            $reasons[] = $r;
        }

        echo json_encode([
            'success'  => true,
            'person'   => ['id' => (int)$p['id'], 'name' => $p['name'], 'department' => $p['department'],
                           'job_title' => $p['job_title'], 'is_active' => (int)$p['is_active'] === 1],
            'on_team'  => $onTeam,
            'reasons'  => $onTeam ? $reasons : [],
            'settings' => ['enabled' => $settings['enabled'] === '1', 'confidential' => $settings['confidential'],
                           'can_reply' => $settings['can_reply'] === '1', 'can_close' => $settings['can_close'] === '1'],
        ]);
        exit;
    }

    if (!$isPost && $action === 'search') {
        $kind = (string)($in['kind'] ?? 'user');
        $q    = trim((string)($in['q'] ?? ''));
        $like = '%' . $q . '%';
        $page = max(1, (int)($in['page'] ?? 1));
        $lim  = " LIMIT " . MANAGER_ACCESS_PAGE . " OFFSET " . (($page - 1) * MANAGER_ACCESS_PAGE);

        if ($kind === 'user') {
            $where = "$base AND (u.display_name LIKE ? OR u.email LIKE ? OR u.username LIKE ? OR u.department LIKE ? OR u.job_title LIKE ?)";
            $args  = array_merge($baseArgs, [$like, $like, $like, $like, $like]);
            $c = $conn->prepare("SELECT COUNT(*) FROM users u WHERE $where");
            $c->execute($args);
            $st = $conn->prepare(
                "SELECT u.id, COALESCE(NULLIF(u.display_name, ''), NULLIF(u.email, ''), u.username) AS name,
                        u.email, u.department, u.job_title, u.is_active
                   FROM users u WHERE $where ORDER BY name $lim"
            );
            $st->execute($args);
            $rows = array_map(fn($r) => [
                'id' => (int)$r['id'], 'name' => $r['name'], 'email' => $r['email'],
                'department' => $r['department'], 'job_title' => $r['job_title'],
                'is_active' => (int)$r['is_active'] === 1,
            ], $st->fetchAll(PDO::FETCH_ASSOC));
        } elseif ($kind === 'group') {
            // The same membership rule the engine uses (managerGrantMembers).
            $members = "SELECT COUNT(*) FROM knowledge_user_group_members gm JOIN users u ON u.id = gm.member_id
                         WHERE gm.group_id = g.id AND gm.member_type = 'user'
                           AND (gm.expires_at IS NULL OR gm.expires_at > UTC_TIMESTAMP()) AND $base";
            $c = $conn->prepare("SELECT COUNT(*) FROM knowledge_user_groups g WHERE g.is_active = 1 AND g.name LIKE ?");
            $c->execute([$like]);
            $st = $conn->prepare(
                "SELECT g.id, g.name, g.description, ($members) AS members
                   FROM knowledge_user_groups g WHERE g.is_active = 1 AND g.name LIKE ? ORDER BY g.name $lim"
            );
            $st->execute(array_merge($baseArgs, [$like]));
            $rows = array_map(fn($r) => [
                'id' => (int)$r['id'], 'name' => $r['name'], 'description' => $r['description'], 'members' => (int)$r['members'],
            ], $st->fetchAll(PDO::FETCH_ASSOC));
        } elseif ($kind === 'department') {
            // Free text, so grouped the way the engine matches: ignoring case and
            // surrounding spaces. One row per department as the line would see it.
            $where = "$base AND u.department IS NOT NULL AND TRIM(u.department) <> '' AND u.department LIKE ?";
            $args  = array_merge($baseArgs, [$like]);
            $c = $conn->prepare("SELECT COUNT(*) FROM (SELECT 1 FROM users u WHERE $where GROUP BY LOWER(TRIM(u.department))) x");
            $c->execute($args);
            $st = $conn->prepare(
                "SELECT MIN(TRIM(u.department)) AS name, COUNT(*) AS members
                   FROM users u WHERE $where GROUP BY LOWER(TRIM(u.department)) ORDER BY name $lim"
            );
            $st->execute($args);
            $rows = array_map(fn($r) => ['name' => $r['name'], 'members' => (int)$r['members']], $st->fetchAll(PDO::FETCH_ASSOC));
        } else {
            maFail('Unknown search');
        }
        echo json_encode(['success' => true, 'kind' => $kind, 'rows' => $rows, 'total' => (int)$c->fetchColumn(),
                          'page' => $page, 'per_page' => MANAGER_ACCESS_PAGE]);
        exit;
    }

    if ($isPost && ($action === 'add' || $action === 'remove')) {
        if (!managerLinesEditable($conn)) maFail('Only administrators can change management lines');

        if ($action === 'remove') {
            $del = $conn->prepare("DELETE FROM manager_grants WHERE id = ? AND manager_user_id = ?");
            $del->execute([(int)($in['line_id'] ?? 0), (int)$m['id']]);
            managersResetMemo();
            echo json_encode(['success' => true] + maState($conn, $m));
            exit;
        }

        $type  = (string)($in['grant_type'] ?? '');
        $excl  = !empty($in['is_exclusion']) ? 1 : 0;
        $tid   = 0;
        $value = '';
        if (!in_array($type, $excl ? MANAGER_EXCLUSION_TYPES : MANAGER_GRANT_TYPES, true)) maFail('Unknown kind of line');

        if ($type === 'user') {
            // Re-checked against the SAME reach the picker used.
            $tid = (int)($in['target_id'] ?? 0);
            $ok = $conn->prepare("SELECT COUNT(*) FROM users u WHERE $base AND u.id = ?");
            $ok->execute(array_merge($baseArgs, [$tid]));
            if (!(int)$ok->fetchColumn()) maFail('That person cannot be reached from this manager');
        } elseif ($type === 'group') {
            $tid = (int)($in['target_id'] ?? 0);
            $ok = $conn->prepare("SELECT COUNT(*) FROM knowledge_user_groups WHERE id = ? AND is_active = 1");
            $ok->execute([$tid]);
            if (!(int)$ok->fetchColumn()) maFail('That group does not exist');
        } elseif ($type === 'department') {
            $value = trim((string)($in['target_value'] ?? ''));
            if ($value === '' || mb_strlen($value) > 150) maFail('Choose a department');
        } elseif ($type === 'reports') {
            $value = ((string)($in['target_value'] ?? '')) === 'all' ? 'all' : '';
            // One reporting-line line at a time: direct OR the whole chain.
            $conn->prepare("DELETE FROM manager_grants WHERE manager_user_id = ? AND grant_type = 'reports'")
                 ->execute([(int)$m['id']]);
        }

        // Adding the same line twice is a no-op, not a duplicate.
        $dup = $conn->prepare(
            "SELECT COUNT(*) FROM manager_grants WHERE manager_user_id = ? AND grant_type = ? AND target_id = ?
                AND LOWER(TRIM(target_value)) = LOWER(?) AND is_exclusion = ?"
        );
        $dup->execute([(int)$m['id'], $type, $tid, $value, $excl]);
        if (!(int)$dup->fetchColumn()) {
            $conn->prepare(
                "INSERT INTO manager_grants (manager_user_id, grant_type, target_id, target_value, is_exclusion, created_by_analyst_id, created_datetime)
                 VALUES (?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())"
            )->execute([(int)$m['id'], $type, $tid, $value, $excl, (int)$_SESSION['analyst_id']]);
        }
        managersResetMemo();
        echo json_encode(['success' => true] + maState($conn, $m));
        exit;
    }

    maFail('Unknown request');
} catch (Exception $e) {
    error_log('[manager_access] ' . $e->getMessage());
    maFail('Could not complete the request');
}
