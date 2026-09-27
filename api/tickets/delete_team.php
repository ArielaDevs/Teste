<?php
/**
 * API Endpoint: Delete a team
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

$id = $input['id'] ?? null;

if (!$id) {
    echo json_encode(['success' => false, 'error' => 'Team ID is required']);
    exit;
}

try {
    $conn = connectToDatabase();

    // Its members, read before they go: losing a team can change the sign-in
    // method of anyone who follows their team (GH #41).
    $members = analystSignInTeamMemberIds($conn, (int)$id);

    // Delete the team (foreign key constraints will cascade delete related records)
    $sql = "DELETE FROM teams WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->execute([$id]);

    if ($stmt->rowCount() > 0) {
        // Leftover analyst_teams rows (no cascade on a verify-built table) are
        // harmless here: the team lookup joins teams, so they are simply not found.
        $signin = analystSignInApplyMany($conn, $members);
        echo json_encode([
            'success' => true,
            'message' => 'Team deleted successfully',
            'signin'  => $signin
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'error' => 'Team not found'
        ]);
    }

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}

?>
