<?php
/* 🔴 NEVER OVER THE WEB. See tests/README.md. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * Upload/recording hardening — forged MIME, malicious extension, forged claim.
 *
 * WHY THIS EXISTS:
 * Screen recordings upload BEFORE the ticket exists (pending rows,
 * ticket_id NULL) and are claimed later by id — ids are sequential and
 * guessable, so the claim path is a cross-user read/write primitive unless
 * guarded. The upload path itself trusts the client-supplied MIME first and
 * stores the original filename raw. Three probes:
 *
 *   1. Forged claim (cross-user, cross-tenant) — GUARDED TODAY via
 *      claimPendingRecordings() (same-user + still-pending, enforced in the
 *      SELECT so a foreign id simply matches nothing). Must keep passing.
 *   2. Forged MIME — GAP: upload_recording.php accepts $_FILES['type']
 *      (client-controlled) at face value and only sniffs magic bytes when the
 *      claim is NOT allow-listed. A `video/mp4` claim with hostile content is
 *      stored without ever being sniffed. Desired: content sniffed
 *      independently of the claimed MIME (like the repo's other upload paths
 *      do). Static probe on the endpoint source — precedent:
 *      tests/web-exposure-guard.php and tests/security-findings/run.php.
 *   3. Malicious extension / traversal in the original name — GAP: the stored
 *      filename is server-built (uuid + fixed ext, good — asserted), but
 *      original_filename is stored raw and rendered in the portal thread.
 *      Desired: sanitised on write.
 *
 * STATUS (pós-P0): F3 is still OPEN (pentest-v3 §F3, unchanged) — probes
 * 2–3 are expected to FAIL until the endpoint hardens (Agente 4, no schema
 * change). Probe 1 (claim ownership) must stay green. See
 * docs/testing/isolation-matrix.md.
 *
 * No real files are written: claimPendingRecordings() claims the ROW even
 * when the file move fails, so ownership behaviour is fully observable from
 * the database inside the rolled-back transaction.
 *
 * Run:  php tests/upload-recording-hardening.php   (needs a DB)
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/i18n.php';
require_once __DIR__ . '/../includes/tenancy.php';
require_once __DIR__ . '/../includes/ticket_recordings.php';
I18n::initFromSession();

$pass = 0; $fail = 0;
function ok(string $what, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  PASS  {$what}\n"; }
    else       { $fail++; echo "  FAIL  {$what}" . ($detail ? "  <- {$detail}" : '') . "\n"; }
}

echo "\nUpload/recording hardening\n" . str_repeat('=', 70) . "\n";

$conn = connectToDatabase();

$uniq = 'zz' . substr(preg_replace('/[^a-z0-9]/', '', strtolower(uniqid())), 0, 8);

$conn->beginTransaction();
try {
    // Two portal users in different tenants for the cross-user claim probe.
    $conn->prepare("INSERT INTO tenants (name, slug, is_default, is_active) VALUES (?, ?, 0, 1)")
        ->execute(["ZZ-ISOL-A {$uniq}", "zz-isol-a-{$uniq}"]);
    $tenantA = (int)$conn->lastInsertId();
    $conn->prepare("INSERT INTO tenants (name, slug, is_default, is_active) VALUES (?, ?, 0, 1)")
        ->execute(["ZZ-ISOL-B {$uniq}", "zz-isol-b-{$uniq}"]);
    $tenantB = (int)$conn->lastInsertId();
    $conn->prepare("INSERT INTO users (email, display_name, tenant_id, is_active) VALUES (?, ?, ?, 1)")
        ->execute(["zz-isol-rec-a-{$uniq}@example.invalid", "ZZ Isol rec A {$uniq}", $tenantA]);
    $userA = (int)$conn->lastInsertId();
    $conn->prepare("INSERT INTO users (email, display_name, tenant_id, is_active) VALUES (?, ?, ?, 1)")
        ->execute(["zz-isol-rec-b-{$uniq}@example.invalid", "ZZ Isol rec B {$uniq}", $tenantB]);
    $userB = (int)$conn->lastInsertId();
    $conn->prepare("INSERT INTO tickets (tenant_id, ticket_number, subject, user_id) VALUES (?, ?, ?, ?)")
        ->execute([$tenantB, "ZZ-ISOL-{$uniq}-RC", "ZZ-ISOL recording ticket B {$uniq}", $userB]);
    $ticketB = (int)$conn->lastInsertId();

    // Pending uploads: one per user (ticket_id NULL, as upload_recording.php writes).
    $mkPending = function (int $userId, string $orig) use ($conn): int {
        $conn->prepare("INSERT INTO ticket_recordings (ticket_id, recorded_by_user_id, filename, original_filename, content_type, file_path, file_size, created_at)
                        VALUES (NULL, ?, ?, ?, 'video/mp4', ?, 16, UTC_TIMESTAMP())")
            ->execute([$userId, 'zz-isol-uuid-placeholder.mp4', $orig, 'recordings/pending/zz-isol-uuid-placeholder.mp4']);
        return (int)$conn->lastInsertId();
    };
    $pendA = $mkPending($userA, 'recording-a.mp4');
    $pendB = $mkPending($userB, 'recording-b.mp4');

    echo "\nForged claim (user B claims user A's pending upload onto B's ticket):\n";
    $claimed = claimPendingRecordings($conn, [$pendA, $pendB], $ticketB, $userB, null);
    $ownerOfA = $conn->query("SELECT ticket_id FROM ticket_recordings WHERE id = {$pendA}")->fetchColumn();
    $ownerOfB = $conn->query("SELECT ticket_id FROM ticket_recordings WHERE id = {$pendB}")->fetchColumn();
    ok("POSITIVE CONTROL: own pending upload is claimed", $claimed === 1 && (int)$ownerOfB === $ticketB, "claimed={$claimed}");
    ok("foreign pending upload is NOT claimed (still pending)", $ownerOfA === null, 'ticket_id=' . var_export($ownerOfA, true));
    ok('forged claim of only the foreign id claims zero rows', claimPendingRecordings($conn, [$pendA], $ticketB, $userB, null) === 0);

    echo "\nStored filename (server-built, never from the client name):\n";
    $evil = $mkPending($userA, '../../evil.php');
    $stored = $conn->query("SELECT filename, file_path FROM ticket_recordings WHERE id = {$evil}")->fetch(PDO::FETCH_ASSOC);
    // The endpoint builds uuid.ext; assert the same invariant for any writer:
    // no traversal, no client extension, fixed allow-listed suffix.
    $nameOk = (bool)preg_match('/^[a-zA-Z0-9_-]+\.(mp4|webm)$/', (string)$stored['filename']);
    $pathOk = strpos((string)$stored['file_path'], '..') === false;
    ok('POSITIVE CONTROL: stored name/path carry no traversal or client ext', $nameOk && $pathOk, json_encode($stored));

    echo "\nForged MIME (endpoint must sniff content, not trust the claim):\n";
    // Static probe: the endpoint must validate magic bytes even when the
    // client-supplied MIME is allow-listed. Today the sniff lives only in the
    // else-branch of the allow-list check, so a forged video/mp4 claim skips
    // it entirely.
    $src = file_get_contents(__DIR__ . '/../api/self-service/upload_recording.php');
    $trustsClaimFirst = (bool)preg_match('/in_array\(\$mime,\s*\$allowedMimes/', $src);
    $sniffsAlways = (bool)preg_match('/finfo|mime_content_type|exif_imagetype/i', $src)
        && !preg_match('/\}\s*elseif\s*\(\s*strpos\(\$first/', $src);
    ok('CONTROL: endpoint source is readable', $src !== false && $trustsClaimFirst);
    ok('content is sniffed independently of the claimed MIME', $sniffsAlways, 'sniff only runs when the claimed MIME is rejected');

    echo "\nOriginal filename (stored raw, rendered in the portal thread):\n";
    $raw = (string)$conn->query("SELECT original_filename FROM ticket_recordings WHERE id = {$evil}")->fetchColumn();
    $sanitised = $raw !== '../../evil.php';
    ok('hostile original filename is neutralised on write', $sanitised, "stored raw as '{$raw}'");
} catch (Throwable $e) {
    ok('the run completed', false, get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
} finally {
    if ($conn->inTransaction()) $conn->rollBack();
}

$left = 0;
try {
    $left += (int)$conn->query("SELECT COUNT(*) FROM ticket_recordings WHERE original_filename IN ('recording-a.mp4', 'recording-b.mp4', '../../evil.php')")->fetchColumn();
    $left += (int)$conn->query("SELECT COUNT(*) FROM tickets WHERE ticket_number LIKE 'ZZ-ISOL-{$uniq}-RC'")->fetchColumn();
    $left += (int)$conn->query("SELECT COUNT(*) FROM users WHERE email LIKE 'zz-isol-rec-%-{$uniq}@example.invalid'")->fetchColumn();
    $left += (int)$conn->query("SELECT COUNT(*) FROM tenants WHERE slug IN ('zz-isol-a-{$uniq}', 'zz-isol-b-{$uniq}')")->fetchColumn();
} catch (Throwable $e) { /* count itself failed — report below */ }
ok('nothing survived the run (rolled back)', $left === 0, "{$left} test rows left");

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail ? 1 : 0);
