<?php
/**
 * API Endpoint: Save (create/update) a team
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

if (!$input) {
    echo json_encode(['success' => false, 'error' => 'Invalid request data']);
    exit;
}

$id = $input['id'] ?? null;
$name = trim($input['name'] ?? '');
$description = trim($input['description'] ?? '');
$displayOrder = intval($input['display_order'] ?? 0);
$isActive = isset($input['is_active']) ? ($input['is_active'] ? 1 : 0) : 1;
// Module access (issue #30): whether this team grants all modules to its members.
// Only applied when the form sends it, so other callers never clobber it.
$hasAllModules = array_key_exists('can_access_all_modules', $input);
$allModules = !empty($input['can_access_all_modules']) ? 1 : 0;
// Default sign-in method for members set to "Follow team" (GH #41). Sent as
// '' (not set), 'local', or a provider id. Only applied when sent, like the above.
$hasAuth = array_key_exists('auth_method', $input);
$authIn  = (string)($input['auth_method'] ?? '');

// Validate
if (empty($name)) {
    echo json_encode(['success' => false, 'error' => 'Team name is required']);
    exit;
}

try {
    $conn = connectToDatabase();

    if ($hasAuth && !analystSignInReady($conn)) {
        if ($authIn !== '') {
            echo json_encode(['success' => false, 'error' => 'Run System → Database Verification before setting a sign-in method.']);
            exit;
        }
        $hasAuth = false; // "Not set" is what an unverified install already has
    }

    if ($id) {
        // Update existing team
        $sql = "UPDATE teams SET name = ?, description = ?, display_order = ?, is_active = ?, updated_datetime = UTC_TIMESTAMP() WHERE id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->execute([$name, $description, $displayOrder, $isActive, $id]);
        $message = 'Team updated successfully';
    } else {
        // Create new team
        $sql = "INSERT INTO teams (name, description, display_order, is_active) VALUES (?, ?, ?, ?)";
        $stmt = $conn->prepare($sql);
        $stmt->execute([$name, $description, $displayOrder, $isActive]);

        $id = $conn->lastInsertId();
        $message = 'Team created successfully';
    }

    // Persist the all-modules flag when the form provided it.
    if ($hasAllModules) {
        try { $conn->prepare("UPDATE teams SET can_access_all_modules = ? WHERE id = ?")->execute([$allModules, $id]); } catch (Exception $e) {}
    }

    // The sign-in method. A provider id must name a provider that exists: a
    // dangling one would read as "not set" everywhere, which is not what was chosen.
    if ($hasAuth) {
        $method = null; $providerId = null;
        if ($authIn === 'local') {
            $method = 'local';
        } elseif ($authIn !== '') {
            $chk = $conn->prepare("SELECT id FROM auth_providers WHERE id = ?");
            $chk->execute([(int)$authIn]);
            if (!$chk->fetchColumn()) {
                echo json_encode(['success' => false, 'error' => 'That sign-in method no longer exists.']);
                exit;
            }
            $method = 'provider'; $providerId = (int)$authIn;
        }
        $conn->prepare("UPDATE teams SET auth_method = ?, auth_provider_id = ? WHERE id = ?")->execute([$method, $providerId, $id]);
    }

    // The method, or the team being switched off, can move its members.
    $signin = analystSignInApplyMany($conn, analystSignInTeamMemberIds($conn, (int)$id));

    echo json_encode([
        'success' => true,
        'message' => $message,
        'id' => $id,
        'signin' => $signin
    ]);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}

?>
