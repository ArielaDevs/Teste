<?php
/**
 * API: this analyst's email signatures, their profile details and the merge codes.
 *
 * ⚠️ SCOPED TO THE SESSION, NEVER TO A PARAMETER. There is no analyst_id input and
 * there must never be one: a signature is one person's own text, and an endpoint that
 * accepted an id would hand anybody else's to whoever asked. That is also why there is
 * no capability check — this is not administration, it is your own account, and every
 * analyst is entitled to exactly their own row.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/signatures.php';
require_once '../../includes/sso_identity.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

try {
    $conn      = connectToDatabase();
    $analystId = (int)$_SESSION['analyst_id'];

    $stmt = $conn->prepare("SELECT full_name, email, job_title, department, phone, mobile
                              FROM analysts WHERE id = ?");
    $stmt->execute([$analystId]);
    $profile = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    // True when an OIDC provider rewrites these on every sign-in (#155), so the
    // page shows them read-only. save_profile.php refuses the save as well.
    $profile['sso_synced'] = ssoAnalystProfileLocked($conn, $analystId);

    // Rendered alongside the raw body so the editor can show a live preview without
    // reimplementing the substitution in the browser — the same reason the template
    // simulator calls the real matcher rather than copying it.
    $signatures = signaturesForAnalyst($conn, $analystId);
    foreach ($signatures as &$sig) {
        $sig['rendered'] = renderSignature($conn, $sig['body'], $analystId);
    }
    unset($sig);

    echo json_encode([
        'success'     => true,
        'signatures'  => $signatures,
        'profile'     => $profile,
        'merge_codes' => signatureMergeCodes(),
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
