<?php
/**
 * Sign-in method set at team level (GH #41).
 *
 * A team can carry a default sign-in method; an analyst set to "Follow team"
 * takes it. An analyst with their own method set is never touched.
 *
 * ── WHY THE RESULT IS WRITTEN INTO analysts.auth_provider_id ────────────────
 * Every place that ENFORCES sign-in - the email-first router, the password
 * form, the OIDC callback, the LDAP bind - reads analysts.auth_provider_id and
 * nothing else. That is the strict-isolation rule: one account, exactly one
 * way in. Working the team default out at sign-in time instead would mean
 * teaching all of them about teams, and a mistake in any one would be a way
 * round the rule. So the team's method is COPIED into that column whenever
 * something that could change it happens, and the enforcement code is not
 * changed at all.
 *
 * The cost is that every place that can change the answer has to call
 * analystSignInApply*() below:
 *   - an analyst's own method or follow flag saved   api/tickets/save_analyst.php
 *   - an analyst's teams saved                        api/tickets/save_analyst_teams.php
 *   - a team's members saved                          api/tickets/save_team_analysts.php
 *   - a team's method or active flag saved            api/tickets/save_team.php
 *   - a team deleted                                  api/tickets/delete_team.php
 *   - a provider deleted                              api/system/delete_sso_provider.php
 *
 * ── WHEN THE TEAMS DISAGREE ─────────────────────────────────────────────────
 * Nothing is guessed. If an analyst's teams set two different methods, their
 * current method is KEPT and the disagreement is reported, so an administrator
 * chooses on System -> Analysts. The same when none of their teams sets one.
 * A priority order between teams was the alternative, and was rejected: it is
 * invisible, and someone would one day move a person between teams and change
 * how they sign in without knowing.
 *
 * Only ACTIVE teams count, and a team pointing at a provider that no longer
 * exists counts as not set (Database Verification adds columns without foreign
 * keys, so a deleted provider can leave a dangling id behind).
 */

/**
 * Have the columns this needs been added yet?
 *
 * ⚠️ New code reaches an install before System -> Database Verification runs.
 * api/tickets/get_analysts.php feeds assignee lists all over the application, so
 * selecting a column that is not there yet would break far more than this
 * feature. Every caller checks this first and carries on without it.
 */
function analystSignInReady(PDO $conn): bool
{
    static $ready = null;
    if ($ready === null) {
        try {
            $conn->query("SELECT auth_follow_team FROM analysts LIMIT 0");
            $conn->query("SELECT auth_method, auth_provider_id FROM teams LIMIT 0");
            $ready = true;
        } catch (Throwable $e) {
            $ready = false;
        }
    }
    return $ready;
}

/**
 * The sign-in methods set by analysts' active teams, grouped by analyst.
 *
 * @param int|null $analystId one analyst, or null for everyone (System -> Analysts)
 * @return array<int, array<int, array{team_id:int, team_name:string, key:int, label:?string}>>
 *         analyst_id => their teams' methods; key 0 = local password, otherwise
 *         the auth_providers.id, and label is that provider's name.
 */
function analystSignInTeamMethods(PDO $conn, ?int $analystId = null): array
{
    $sql = "SELECT at.analyst_id, t.id, t.name, t.auth_method, t.auth_provider_id, p.display_name
              FROM analyst_teams at
              JOIN teams t ON t.id = at.team_id
         LEFT JOIN auth_providers p ON p.id = t.auth_provider_id
             WHERE t.is_active = 1 AND t.auth_method IS NOT NULL"
         . ($analystId !== null ? " AND at.analyst_id = ?" : "")
         . " ORDER BY t.display_order, t.name";
    $stmt = $conn->prepare($sql);
    $stmt->execute($analystId !== null ? [$analystId] : []);

    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        if ($r['auth_method'] === 'local') {
            $key = 0; $label = null;
        } elseif ($r['auth_method'] === 'provider' && $r['display_name'] !== null) {
            $key = (int)$r['auth_provider_id']; $label = $r['display_name'];
        } else {
            continue; // 'provider' whose provider is gone: counts as not set
        }
        $out[(int)$r['analyst_id']][] = ['team_id' => (int)$r['id'], 'team_name' => $r['name'], 'key' => $key, 'label' => $label];
    }
    return $out;
}

/**
 * Turn one analyst's team methods into a verdict.
 *
 * @return array{status:string, key:?int, teams:array}
 *         status 'none'     - no active team of theirs sets a method
 *                'agreed'   - one method; key is it (0 = local password)
 *                'conflict' - two or more different methods; key is null
 */
function analystSignInVerdict(array $teams): array
{
    $keys = array_values(array_unique(array_column($teams, 'key')));
    if (!$keys) {
        return ['status' => 'none', 'key' => null, 'teams' => $teams];
    }
    if (count($keys) > 1) {
        return ['status' => 'conflict', 'key' => null, 'teams' => $teams];
    }
    return ['status' => 'agreed', 'key' => $keys[0], 'teams' => $teams];
}

/** What one analyst's teams say, without changing anything. */
function analystSignInFromTeams(PDO $conn, int $analystId): array
{
    return analystSignInVerdict(analystSignInTeamMethods($conn, $analystId)[$analystId] ?? []);
}

/**
 * Bring one analyst's stored method in line with their teams, if they follow
 * their team. Returns what happened, for the caller to report.
 *
 * @return string 'own' (not following) | 'none' | 'conflict' | 'unchanged' | 'changed'
 */
function analystSignInApply(PDO $conn, int $analystId): string
{
    if (!analystSignInReady($conn)) {
        return 'own';
    }
    $stmt = $conn->prepare("SELECT auth_follow_team, auth_provider_id FROM analysts WHERE id = ?");
    $stmt->execute([$analystId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row || (int)$row['auth_follow_team'] !== 1) {
        return 'own';
    }

    $from = analystSignInFromTeams($conn, $analystId);
    if ($from['status'] !== 'agreed') {
        return $from['status']; // keep what they have - see the header
    }

    $current = $row['auth_provider_id'] !== null ? (int)$row['auth_provider_id'] : 0;
    if ($current === $from['key']) {
        return 'unchanged';
    }
    $conn->prepare("UPDATE analysts SET auth_provider_id = ?, last_modified_datetime = UTC_TIMESTAMP() WHERE id = ?")
         ->execute([$from['key'] === 0 ? null : $from['key'], $analystId]);
    return 'changed';
}

/**
 * Apply to several analysts and count the outcomes.
 *
 * @param int[] $analystIds
 * @return array{changed:int, conflict:int}
 */
function analystSignInApplyMany(PDO $conn, array $analystIds): array
{
    $counts = ['changed' => 0, 'conflict' => 0];
    foreach (array_unique(array_map('intval', $analystIds)) as $id) {
        if ($id <= 0) continue;
        $r = analystSignInApply($conn, $id);
        if (isset($counts[$r])) $counts[$r]++;
    }
    return $counts;
}

/** The members of a team, for the callers that change the team itself. */
function analystSignInTeamMemberIds(PDO $conn, int $teamId): array
{
    $stmt = $conn->prepare("SELECT analyst_id FROM analyst_teams WHERE team_id = ?");
    $stmt->execute([$teamId]);
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

/** Every analyst who follows their team - used when a provider is deleted. */
function analystSignInFollowerIds(PDO $conn): array
{
    if (!analystSignInReady($conn)) {
        return [];
    }
    return array_map('intval', $conn->query("SELECT id FROM analysts WHERE auth_follow_team = 1")->fetchAll(PDO::FETCH_COLUMN));
}
