<?php
/**
 * API: Change password for self-service user
 * POST - Verifies current password and updates to new one
 */
// ⚠️ A PLAIN session_start(), deliberately — NOT ['read_and_close' => true].
// This endpoint rotates the session id at the end (see below), and read_and_close
// closes the session immediately: session_status() drops back to PHP_SESSION_NONE,
// so sessionPromoteToAuthenticated() hits its `!== PHP_SESSION_ACTIVE` early return
// and does nothing at all. Silently — that branch has no log line, and
// session_regenerate_id() would have returned false anyway. The whole point of
// rotating here is that a portal user changing their password to evict a session
// thief actually evicts them; with the session closed it evicted nobody.
// api/myaccount/change_password.php, the analyst twin, always did this correctly.
// Reported by Erlend Volden, who proved the premise in a container rather than
// reading it — and the shipped test passed throughout, because it searched the
// source for the call rather than checking the session id changed.
session_start();
require_once '../../config.php';
require_once '../../includes/functions.php';

header('Content-Type: application/json');

if (!isset($_SESSION['ss_user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$currentPassword = $input['current_password'] ?? '';
$newPassword = $input['new_password'] ?? '';
$confirmPassword = $input['confirm_password'] ?? '';

if (empty($currentPassword) || empty($newPassword) || empty($confirmPassword)) {
    echo json_encode(['success' => false, 'error' => 'All fields are required']);
    exit;
}

if ($newPassword !== $confirmPassword) {
    echo json_encode(['success' => false, 'error' => 'New passwords do not match']);
    exit;
}

if (strlen($newPassword) < 8) {
    echo json_encode(['success' => false, 'error' => 'Password must be at least 8 characters']);
    exit;
}

try {
    $conn = connectToDatabase();
    $userId = $_SESSION['ss_user_id'];

    $stmt = $conn->prepare("SELECT password_hash FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user || !password_verify($currentPassword, $user['password_hash'])) {
        echo json_encode(['success' => false, 'error' => 'Current password is incorrect']);
        exit;
    }

    $newHash = password_hash($newPassword, PASSWORD_BCRYPT);

    $updateStmt = $conn->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
    $updateStmt->execute([$newHash, $userId]);

    // Rotate the session id: a stolen session must not outlive the password change
    // made to end it. See includes/session_security.php.
    sessionPromoteToAuthenticated(false);   // same person: keep the CSRF token

    echo json_encode(['success' => true, 'message' => 'Password changed successfully']);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => 'Failed to change password']);
}
