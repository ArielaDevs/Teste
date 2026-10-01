<?php
/**
 * Public, unauthenticated media server for ONE outbound attachment — the
 * companion to webhook.php, but outbound: Twilio's WhatsApp API sends media
 * by URL rather than accepting an upload, so it has to fetch the bytes from
 * somewhere reachable on the internet. This is that somewhere.
 *
 *   GET /api/messaging/media.php?id=<email_attachments.id>&exp=<unix ts>&token=<hmac>
 *
 * Deliberately narrow, not a general file host:
 *   - the token is an HMAC over (id, exp) using the app's own encryption key
 *     (messagingOutboundMediaToken()) — nobody can mint one without server-side
 *     code, and it is bound to exactly one attachment
 *   - exp is checked server-side; an expired link 403s even with a
 *     correctly-computed token for the (id, exp) pair it was issued for
 *   - only serves a row this app itself created as an OUTBOUND channel
 *     attachment (email_attachments joined to an Outbound, non-email emails
 *     row) — an inbound attachment, or an ordinary email attachment, is not
 *     reachable through this endpoint even with a forged token, because the
 *     query itself excludes them
 */
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/messaging/messaging.php';

function mediaFail(int $code): void
{
    http_response_code($code);
    header('Content-Type: text/plain');
    echo 'Not found';
    exit;
}

$id    = (int) ($_GET['id'] ?? 0);
$exp   = (int) ($_GET['exp'] ?? 0);
$token = (string) ($_GET['token'] ?? '');

if ($id <= 0 || $exp <= 0 || $token === '') {
    mediaFail(400);
}
if (time() > $exp) {
    mediaFail(410); // Gone — expired, not merely missing
}

$expected = messagingOutboundMediaToken($id, $exp);
if (!hash_equals($expected, $token)) {
    mediaFail(403);
}

try {
    $conn = connectToDatabase();
} catch (Exception $e) {
    mediaFail(500);
}

// ⚠️ The Outbound + channel<>'email' filter is load-bearing, not cosmetic —
// see the file header. It is what stops a guessed-but-otherwise-valid-shaped
// request (or a token somehow computed offline) from reaching an inbound
// attachment or an ordinary email attachment.
$stmt = $conn->prepare(
    "SELECT ea.file_path, ea.content_type, ea.filename
     FROM email_attachments ea
     JOIN emails e ON e.id = ea.email_id
     WHERE ea.id = ? AND e.direction = 'Outbound' AND e.channel <> 'email'"
);
$stmt->execute([$id]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$row) {
    mediaFail(404);
}

$fullPath = dirname(dirname(__DIR__)) . '/tickets/attachments/' . ltrim((string) $row['file_path'], '/\\');
$real = realpath($fullPath);
$attachmentsRoot = realpath(dirname(dirname(__DIR__)) . '/tickets/attachments');
// ⚠️ Confirms the resolved path is still INSIDE the attachments root. file_path
// is built by this app, never taken from the request, so this is defence in
// depth rather than the primary guard — but it costs nothing and a future
// change to how file_path is formed should not be able to reopen a traversal.
if ($real === false || $attachmentsRoot === false || strpos($real, $attachmentsRoot) !== 0 || !is_file($real)) {
    mediaFail(404);
}

header('Content-Type: ' . ($row['content_type'] ?: 'application/octet-stream'));
header('Content-Length: ' . filesize($real));
header('Content-Disposition: inline; filename="' . addslashes((string) $row['filename']) . '"');
// This URL is single-purpose and expires on its own (checked above) — caching
// it anywhere beyond the fetch that's about to happen serves no one.
header('Cache-Control: no-store');
readfile($real);
