<?php
/**
 * LMS API: the CANDIDATE's side of a competency test. No session - the link's
 * token (sent in the body, never in a URL this endpoint logs) is the
 * credential, and it is matched by its SHA-256 hash.
 *
 *   POST {t, action: start}            starts the clock (once)
 *   POST {t, action: save, q, a}       records one answer while the clock runs
 *   POST {t, action: submit}           closes the sitting and scores it
 *
 * The server owns the clock: nothing the browser says about time is believed.
 * Exempt from the CSRF token check (includes/csrf.php) for the same reason the
 * web chat is: it carries its own credential and no session.
 */
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/lms/competency_tests.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

function ctpOut(array $a): void { echo json_encode(['success' => true] + $a); exit; }
function ctpFail(string $code, int $http = 400): void { http_response_code($http); echo json_encode(['success' => false, 'error' => $code]); exit; }

if ($_SERVER['REQUEST_METHOD'] !== 'POST') ctpFail('method', 405);

try {
    $in   = json_decode(file_get_contents('php://input'), true) ?: [];
    $conn = connectToDatabase();
    $s    = lmsCtFindSitting($conn, (string)($in['t'] ?? ''));
    // One answer for "no such link" and "cancelled": which links exist is not
    // something a guesser gets to learn.
    if (!$s || (int)$s['is_cancelled']) ctpFail('invalid', 404);

    $state  = lmsCtState($s);
    $action = (string)($in['action'] ?? '');

    if ($state === 'timeup') { lmsCtFinalise($conn, $s, 'time_up'); ctpFail('finished'); }
    if ($state === 'submitted') ctpFail('finished');
    if ($state === 'expired')   ctpFail('expired');

    if ($action === 'start') {
        if ($state === 'ready') {
            $conn->prepare("UPDATE lms_ct_sittings SET started_datetime = UTC_TIMESTAMP() WHERE id = ? AND started_datetime IS NULL")->execute([(int)$s['id']]);
        }
        ctpOut([]);
    }

    if ($state !== 'in_progress') ctpFail('not_started');

    if ($action === 'save') {
        $snap = json_decode((string)$s['snapshot_json'], true) ?: ['questions' => []];
        $q = (int)($in['q'] ?? -1);
        $a = (int)($in['a'] ?? -1);
        if (!isset($snap['questions'][$q]['answers'][$a])) ctpFail('bad_answer');
        // Read-modify-write in one statement's worth of time: JSON_SET keeps two
        // quick clicks on different questions from overwriting each other.
        $conn->prepare("UPDATE lms_ct_sittings
                        SET responses_json = JSON_SET(COALESCE(responses_json, '{}'), ?, CAST(? AS UNSIGNED))
                        WHERE id = ? AND submitted_datetime IS NULL")
             ->execute(['$."' . $q . '"', $a, (int)$s['id']]);
        ctpOut(['remaining' => max(0, lmsCtDeadline($s) - time())]);
    }

    if ($action === 'submit') {
        $fresh = $conn->prepare("SELECT * FROM lms_ct_sittings WHERE id = ?");
        $fresh->execute([(int)$s['id']]);
        lmsCtFinalise($conn, $fresh->fetch(PDO::FETCH_ASSOC), 'submitted');
        ctpOut([]);
    }

    ctpFail('unknown');
} catch (Throwable $e) {
    error_log('api/lms/test_public.php: ' . $e->getMessage());
    ctpFail('server', 500);
}
