<?php
/**
 * Managers in the self-service portal (discussion #62, step 3).
 *
 * A MANAGER is a portal user who may read the tickets raised by other portal
 * users - "the Head of Logistics sees Logistics' tickets". This file is the ONE
 * place that decides who that is; every portal endpoint asks
 * portalTicketAccess() and nothing else, so an attachment link, a document or a
 * recording can never be a way round a rule the ticket page keeps.
 *
 * WHERE MANAGEMENT COMES FROM
 * ---------------------------
 *  1. The directory: users.manager_id, filled from AD's manager attribute by
 *     directory sync. Someone's line manager can see their tickets - switchable
 *     (managers_directory), direct reports only or the whole chain below.
 *  2. Management lines set up in FreeITSM (`manager_grants`), any mix of:
 *        everyone | a person | a people group | a department name | their reports
 *     plus EXCLUSIONS (a person, a group, a department). An exclusion always
 *     wins, whatever granted the access - including the directory - because
 *     "everyone in Sales except the person raising a grievance about me" is the
 *     case the exclusion exists for.
 *
 * THE RULES (agreed with Ed)
 * --------------------------
 *  - OFF until switched on (managers_enabled), and FAIL CLOSED: before Database
 *    Verification has added the sensitivity and view columns, nobody is a
 *    manager, because a confidential ticket could not be told apart.
 *  - Same company only, both ways: the person must be in the manager's company
 *    - where NO company means the Default company, the documented convention
 *    for every row a company owns (Multi-Tenancy-Isolation). Somebody who has
 *    never set up companies, or never filed these people, must not have to do
 *    anything extra to make managers work (Ed) -
 *    AND so must the ticket (a ticket can be moved to another company).
 *  - A manager who has left sees nothing. Whether managers keep seeing the
 *    tickets of people who have left is a setting, on by default - an open
 *    ticket still needs oversight after its requester goes (Ed).
 *  - Confidential tickets: nothing (default), a stub, or everything
 *    (managers_confidential).
 *  - What a manager may DO - reply, close - is a setting; viewing is the floor.
 *  - Department names are free text (users.department), matched ignoring case
 *    and surrounding spaces. A renamed department therefore loses its line;
 *    System -> Managers warns about lines that match nobody.
 */

require_once __DIR__ . '/tenancy.php';            // isMultiTenant(), getDefaultTenantId()
require_once __DIR__ . '/ticket_sensitivity.php'; // ticketSensitivityReady()
require_once __DIR__ . '/ticket_views.php';       // ticketViewsReady()

const MANAGER_GRANT_TYPES     = ['everyone', 'user', 'group', 'department', 'reports'];
const MANAGER_EXCLUSION_TYPES = ['user', 'group', 'department'];

/**
 * Per-request cache. Not `static` inside each function, so managersResetMemo()
 * can clear it - a test that changes a setting halfway needs that, and so will
 * any screen that saves settings and then reads the result back.
 */
$GLOBALS['managers_memo'] = $GLOBALS['managers_memo'] ?? [];
function managersResetMemo(): void { $GLOBALS['managers_memo'] = []; }

/** The settings, with their defaults. */
function managersSettings(PDO $conn): array
{
    $memo = &$GLOBALS['managers_memo']['settings'];
    if ($memo !== null) return $memo;
    $s = [
        'enabled'           => '0',       // master switch
        'directory'         => '1',       // AD manager = manager
        'directory_depth'   => 'direct',  // 'direct' | 'all'
        'leavers'           => '1',       // keep seeing tickets of people who have left
        'confidential'      => 'none',    // 'none' | 'stub' | 'all'
        'can_reply'         => '0',
        'can_close'         => '0',
        'edit_by'           => 'admins',  // who may set up lines: 'admins' | 'people_editors'
    ];
    try {
        foreach ($conn->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key LIKE 'managers\\_%'") as $r) {
            $k = substr($r['setting_key'], strlen('managers_'));
            if (array_key_exists($k, $s)) $s[$k] = (string)$r['setting_value'];
        }
    } catch (Throwable $e) { /* defaults */ }
    if (!in_array($s['directory_depth'], ['direct', 'all'], true)) $s['directory_depth'] = 'direct';
    if (!in_array($s['confidential'], ['none', 'stub', 'all'], true)) $s['confidential'] = 'none';
    if (!in_array($s['edit_by'], ['admins', 'people_editors'], true)) $s['edit_by'] = 'admins';
    return $memo = $s;
}

/**
 * Can managers work at all on this install? Needs the switch on AND every table
 * the rules depend on - fail closed otherwise.
 */
function managersActive(PDO $conn): bool
{
    $memo = &$GLOBALS['managers_memo']['active'];
    if ($memo !== null) return $memo;
    if (managersSettings($conn)['enabled'] !== '1') return $memo = false;
    if (!ticketSensitivityReady($conn) || !ticketViewsReady($conn)) return $memo = false;
    try {
        $conn->query("SELECT grant_type FROM manager_grants LIMIT 0");
    } catch (Throwable $e) {
        return $memo = false;
    }
    return $memo = true;
}

/** The manager's own row, or null if they cannot be a manager (missing, or left). */
function managerRow(PDO $conn, int $managerUserId): ?array
{
    $st = $conn->prepare("SELECT id, tenant_id, is_active FROM users WHERE id = ?");
    $st->execute([$managerUserId]);
    $m = $st->fetch(PDO::FETCH_ASSOC);
    if (!$m || (int)$m['is_active'] !== 1) return null;   // a manager who has left sees nothing
    return $m;
}

/**
 * The people a manager may see the tickets of, as user ids. Memoised per
 * request. Empty when managers are off, the manager has left, or - on a
 * multi-company install - the manager belongs to no company.
 *
 * ("No company" is read as the Default company, not as nowhere - see the header.)
 *
 * @return int[]
 */
function managerTeamUserIds(PDO $conn, int $managerUserId): array
{
    $memo = &$GLOBALS['managers_memo']['team'];
    if (isset($memo[$managerUserId])) return $memo[$managerUserId];
    if (!managersActive($conn)) return $memo[$managerUserId] = [];
    $m = managerRow($conn, $managerUserId);
    if (!$m) return $memo[$managerUserId] = [];

    $settings = managersSettings($conn);
    $multi    = isMultiTenant($conn);

    // Everyone a line could ever reach: same company, not the manager, and
    // (unless the setting keeps them) not people who have left.
    $base = "u.id <> ?";
    $baseArgs = [$managerUserId];
    if ($multi) {
        // The manager's company, with no company meaning Default - and for the
        // Default company, people with no company count as its own.
        $mt = managerCompany($conn, $m['tenant_id']);
        if ($mt === getDefaultTenantId($conn)) { $base .= " AND (u.tenant_id = ? OR u.tenant_id IS NULL)"; }
        else                                     { $base .= " AND u.tenant_id = ?"; }
        $baseArgs[] = $mt;
    }
    if ($settings['leavers'] !== '1') { $base .= " AND u.is_active = 1"; }

    $grants = $conn->prepare("SELECT grant_type, target_id, target_value, is_exclusion FROM manager_grants WHERE manager_user_id = ?");
    $grants->execute([$managerUserId]);
    $include = []; $exclude = [];
    foreach ($grants->fetchAll(PDO::FETCH_ASSOC) as $g) {
        if ((int)$g['is_exclusion'] === 1) $exclude[] = $g; else $include[] = $g;
    }

    $ids = [];
    $add = function (array $more) use (&$ids) { foreach ($more as $i) $ids[(int)$i] = true; };

    foreach ($include as $g) {
        $add(managerGrantMembers($conn, $g, $managerUserId, $base, $baseArgs));
    }
    // The directory's reporting line, when switched on.
    if ($settings['directory'] === '1') {
        $add(managerGrantMembers($conn,
            ['grant_type' => 'reports', 'target_id' => 0, 'target_value' => $settings['directory_depth'] === 'all' ? 'all' : ''],
            $managerUserId, $base, $baseArgs));
    }
    // Exclusions last, and over everything - the directory included.
    foreach ($exclude as $g) {
        foreach (managerGrantMembers($conn, $g, $managerUserId, $base, $baseArgs) as $i) unset($ids[(int)$i]);
    }

    $out = array_keys($ids);
    sort($out);
    return $memo[$managerUserId] = $out;
}

/**
 * The people one line refers to, within the base (company, not-self, leavers).
 * Used for both grants and exclusions.
 *
 * @return int[]
 */
function managerGrantMembers(PDO $conn, array $g, int $managerUserId, string $base, array $baseArgs): array
{
    $type = (string)$g['grant_type'];
    $col  = fn(string $sql, array $args) => array_map('intval', (function () use ($conn, $sql, $args) {
        $st = $conn->prepare($sql); $st->execute($args); return $st->fetchAll(PDO::FETCH_COLUMN);
    })());

    switch ($type) {
        case 'everyone':
            return $col("SELECT u.id FROM users u WHERE $base", $baseArgs);
        case 'user':
            return $col("SELECT u.id FROM users u WHERE $base AND u.id = ?", array_merge($baseArgs, [(int)$g['target_id']]));
        case 'group':
            // The same membership rule Knowledge and Training use: the group is
            // active and the membership has not expired.
            return $col(
                "SELECT u.id FROM users u
                  WHERE $base AND u.id IN (
                        SELECT m.member_id FROM knowledge_user_group_members m
                          JOIN knowledge_user_groups g ON g.id = m.group_id AND g.is_active = 1
                         WHERE m.group_id = ? AND m.member_type = 'user'
                           AND (m.expires_at IS NULL OR m.expires_at > UTC_TIMESTAMP()))",
                array_merge($baseArgs, [(int)$g['target_id']]));
        case 'department':
            $name = trim((string)$g['target_value']);
            if ($name === '') return [];
            return $col("SELECT u.id FROM users u WHERE $base AND LOWER(TRIM(u.department)) = LOWER(?)",
                        array_merge($baseArgs, [$name]));
        case 'reports':
            // Down the reporting line. manager_id points UP, so walk it level by
            // level; bounded, and a visited set, so a loop the sync guard missed
            // cannot spin (the database cannot express "no cycles").
            $all   = ((string)$g['target_value']) === 'all';
            $found = [];
            $level = [$managerUserId];
            for ($depth = 0; $depth < 25 && $level; $depth++) {
                $in = implode(',', array_fill(0, count($level), '?'));
                $next = $col("SELECT u.id FROM users u WHERE $base AND u.manager_id IN ($in)", array_merge($baseArgs, $level));
                $level = [];
                foreach ($next as $i) {
                    if (!isset($found[$i])) { $found[$i] = true; $level[] = $i; }
                }
                if (!$all) break;
            }
            return array_keys($found);
    }
    return [];
}

/**
 * The company a row belongs to, where NO company (NULL) means the Default
 * company - the convention every company-owned row follows. A ticket nobody has
 * routed and a person nobody has filed are both Default's.
 */
function managerCompany(PDO $conn, $tenantId): int
{
    return ($tenantId === null || $tenantId === '') ? getDefaultTenantId($conn) : (int)$tenantId;
}

/** Does this portal user manage anybody? Decides whether "Team tickets" is shown. */
function managerHasTeam(PDO $conn, int $userId): bool
{
    return managerTeamUserIds($conn, $userId) !== [];
}

/**
 * THE question every portal endpoint asks: may this portal user see this ticket,
 * and as whom?
 *
 * @return array|null null = no (and the caller answers "not found", never
 *         "forbidden"). Otherwise:
 *         role      'requester' | 'manager'
 *         stub      true = a manager sees only that a confidential ticket exists
 *         can_reply / can_close
 *         ticket    the row (id, user_id, tenant_id, sensitivity)
 */
function portalTicketAccess(PDO $conn, int $userId, int $ticketId): ?array
{
    if ($userId <= 0 || $ticketId <= 0) return null;
    $sensCol = ticketSensitivityReady($conn) ? 't.sensitivity' : "'normal' AS sensitivity";
    $st = $conn->prepare("SELECT t.id, t.user_id, t.tenant_id, $sensCol FROM tickets t WHERE t.id = ? AND t.deleted_datetime IS NULL");
    $st->execute([$ticketId]);
    $t = $st->fetch(PDO::FETCH_ASSOC);
    if (!$t) return null;

    // The person who raised it: exactly as before managers existed.
    if ((int)$t['user_id'] === $userId) {
        return ['role' => 'requester', 'stub' => false, 'can_reply' => true, 'can_close' => true, 'ticket' => $t];
    }

    // Otherwise only ever as a manager.
    $team = managerTeamUserIds($conn, $userId);   // [] when off, not ready, or they have left
    if (!$team || !in_array((int)$t['user_id'], $team, true)) return null;

    // The TICKET must be in the manager's company too - it can be moved.
    if (isMultiTenant($conn)) {
        $m = managerRow($conn, $userId);
        if (!$m || managerCompany($conn, $t['tenant_id']) !== managerCompany($conn, $m['tenant_id'])) return null;
    }

    $s = managersSettings($conn);
    $stub = false;
    if (ticketSensitivityNormalise($t['sensitivity']) === 'confidential') {
        if ($s['confidential'] === 'none') return null;
        $stub = $s['confidential'] === 'stub';
    }
    return [
        'role'      => 'manager',
        'stub'      => $stub,
        // A stub is a stub: nothing to reply to or close.
        'can_reply' => !$stub && $s['can_reply'] === '1',
        'can_close' => !$stub && $s['can_close'] === '1',
        'ticket'    => $t,
    ];
}
