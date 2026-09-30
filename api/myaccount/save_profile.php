<?php
/**
 * API: this analyst's own profile details — the ones a signature merges.
 * POST { job_title, department, phone, mobile }
 *
 * Name and email are deliberately NOT editable here. They identify the account, are
 * used to sign in and to address mail, and on an install using LDAP or SSO they come
 * from the directory — letting somebody edit their own would either be overwritten on
 * the next sync or quietly diverge from it.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/sso_identity.php';
require_once '../../includes/i18n.php';
I18n::initFromSession();

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

try {
    $data = json_decode(file_get_contents('php://input'), true);

    $clean = function ($v, $max) {
        $v = trim((string)$v);
        return $v === '' ? null : mb_substr($v, 0, $max);
    };

    $conn = connectToDatabase();

    // Enforced here, not only by greying the fields out: an OIDC provider set
    // to sync 'always' would put these back at the next sign-in (#155).
    if (ssoAnalystProfileLocked($conn, (int)$_SESSION['analyst_id'])) {
        echo json_encode(['success' => false, 'error' => t('system.preferences.details_managed_note')]);
        exit;
    }
    $stmt = $conn->prepare("UPDATE analysts
                               SET job_title = ?, department = ?, phone = ?, mobile = ?,
                                   last_modified_datetime = UTC_TIMESTAMP()
                             WHERE id = ?");
    $stmt->execute([
        $clean($data['job_title']  ?? '', 100),
        $clean($data['department'] ?? '', 100),
        $clean($data['phone']      ?? '', 50),
        $clean($data['mobile']     ?? '', 50),
        (int)$_SESSION['analyst_id'],
    ]);

    echo json_encode(['success' => true]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
