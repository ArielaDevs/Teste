<?php
/**
 * API Endpoint: Save analysts assigned to a team (team-keyed side of the
 * analyst_teams many-to-many). The analyst-keyed mirror is
 * save_analyst_teams.php; both write the same table, from whichever side the
 * admin happens to be editing. Used by System → Teams.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/admin_api_guard.php'; // System admins only (issue #34)
require_once '../../includes/functions.php';
require_once '../../includes/analyst_signin.php';   // Follow team (GH #41)

header('Content-Type: application/json');

// Check if user is logged in
if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

// Get POST data
$input = json_decode(file_get_contents('php://input'), true);

$teamId = $input['team_id'] ?? null;
$analystIds = $input['analyst_ids'] ?? [];

if (!$teamId) {
    echo json_encode(['success' => false, 'error' => 'Team ID is required']);
    exit;
}

try {
    $conn = connectToDatabase();

    // Everyone who was a member before, as well as everyone who is one now: someone
    // taken OUT of the team can change sign-in method too (GH #41).
    $before = analystSignInTeamMemberIds($conn, (int)$teamId);

    // Start transaction
    $conn->beginTransaction();

    // Delete existing analyst assignments for this team
    $deleteSql = "DELETE FROM analyst_teams WHERE team_id = ?";
    $deleteStmt = $conn->prepare($deleteSql);
    $deleteStmt->execute([$teamId]);

    // Insert new analyst assignments
    if (!empty($analystIds)) {
        $insertSql = "INSERT INTO analyst_teams (analyst_id, team_id) VALUES (?, ?)";
        $insertStmt = $conn->prepare($insertSql);

        foreach ($analystIds as $analystId) {
            $insertStmt->execute([$analystId, $teamId]);
        }
    }

    $conn->commit();

    $signin = analystSignInApplyMany($conn, array_merge($before, array_map('intval', $analystIds)));

    echo json_encode([
        'success' => true,
        'message' => 'Team analysts updated successfully',
        'signin'  => $signin
    ]);

} catch (Exception $e) {
    if (isset($conn)) {
        $conn->rollBack();
    }
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}

?>
