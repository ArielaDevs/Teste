<?php
/**
 * API Endpoint: Get all teams
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';

header('Content-Type: application/json');

// Check if user is logged in
if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}
requireAnyModuleAccessJson(['tickets', 'morning-checks', 'system']);

try {
    $conn = connectToDatabase();

    $sql = "SELECT id, name, description, display_order, is_active, can_access_all_modules, created_datetime, updated_datetime
            FROM teams
            ORDER BY display_order, name";
    $stmt = $conn->prepare($sql);
    $stmt->execute();
    $teams = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Convert bit fields to boolean
    foreach ($teams as &$team) {
        $team['is_active'] = (bool)$team['is_active'];
    }
    unset($team);

    // Follow team (GH #41): each team's sign-in method, and how many of its
    // members who follow their team are stuck because their teams disagree.
    // Read separately so the list still loads before Database Verification.
    require_once '../../includes/analyst_signin.php';
    if (analystSignInReady($conn)) {
        $auth = [];
        foreach ($conn->query("SELECT t.id, t.auth_method, t.auth_provider_id, p.display_name
                                 FROM teams t LEFT JOIN auth_providers p ON p.id = t.auth_provider_id") as $r) {
            $auth[(int)$r['id']] = $r;
        }
        $followers = array_flip(analystSignInFollowerIds($conn));
        $conflicts = [];
        foreach (analystSignInTeamMethods($conn) as $aid => $methods) {
            if (!isset($followers[$aid]) || analystSignInVerdict($methods)['status'] !== 'conflict') continue;
            foreach ($methods as $m) {
                $conflicts[$m['team_id']] = ($conflicts[$m['team_id']] ?? 0) + 1;
            }
        }
        foreach ($teams as &$team) {
            $a = $auth[(int)$team['id']] ?? null;
            $method = $a['auth_method'] ?? null;
            if ($method === 'provider' && ($a['display_name'] ?? null) === null) {
                $method = null; // its provider has gone
            }
            $team['auth_method']        = $method;
            $team['auth_provider_id']   = $method === 'provider' ? (int)$a['auth_provider_id'] : null;
            $team['auth_provider_name'] = $method === 'provider' ? $a['display_name'] : null;
            $team['signin_conflicts']   = $conflicts[(int)$team['id']] ?? 0;
        }
        unset($team);
    }

    echo json_encode([
        'success' => true,
        'teams' => $teams
    ]);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}

?>
