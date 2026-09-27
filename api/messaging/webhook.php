<?php
/**
 * Public inbound webhook for messaging channels (WhatsApp via Twilio / Meta Cloud).
 *
 *   POST /api/messaging/webhook.php?channel=<id>
 *
 * This is the channel equivalent of the email importer, but inverted: instead of
 * us polling a mailbox, the provider PUSHES messages here. There is no session —
 * the request comes from Twilio/Meta, so authenticity is established by the
 * provider's own signature (direct mode) or a shared relay secret (relay mode).
 *
 * Two ingress modes (messaging_channels.ingress_mode), per the configurable design:
 *   - 'direct' : the provider hits this URL directly (install exposes HTTPS, e.g.
 *                via ngrok in dev). Verified by the provider signature.
 *   - 'relay'  : a hosted relay forwards the verbatim request. Verified by the
 *                X-FreeITSM-Relay-Secret header matching the channel's relay_secret.
 *                (The relay itself is Phase 2; this endpoint already accepts it.)
 *
 * Always responds 200 quickly on success so the provider doesn't retry; auth
 * failures return 403 and unknown channels 404.
 */

require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/messaging/messaging.php';
require_once '../../includes/messaging/ingest.php';

/** Request headers with lower-cased keys (getallheaders fallback for non-Apache). */
function webhookHeaders(): array
{
    $out = [];
    if (function_exists('getallheaders')) {
        foreach (getallheaders() as $k => $v) {
            $out[strtolower($k)] = $v;
        }
        return $out;
    }
    foreach ($_SERVER as $k => $v) {
        if (strpos($k, 'HTTP_') === 0) {
            $name = strtolower(str_replace('_', '-', substr($k, 5)));
            $out[$name] = $v;
        }
    }
    return $out;
}

/** Reconstruct the exact public URL the provider called (honours ngrok proxy headers). */
function webhookFullUrl(): string
{
    $proto = 'http';
    // ⚠️ Deliberately NOT requestIsHttps() (GH #152): this must rebuild the
    // EXACT address the provider called, because the provider signs it. Behind a
    // TLS-terminating proxy without TRUST_PROXY_HTTPS the stricter rule says
    // http, the signature check fails, and inbound messages stop. Trusting the
    // header here costs nothing: a forged request still lacks the secret.
    if ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')) {
        $proto = 'https';
    }
    $host = $_SERVER['HTTP_X_FORWARDED_HOST'] ?? ($_SERVER['HTTP_HOST'] ?? 'localhost');
    $uri  = $_SERVER['REQUEST_URI'] ?? '';
    return $proto . '://' . $host . $uri;
}

function webhookFail(int $code, string $msg): void
{
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => $msg]);
    exit;
}

$channelId = isset($_GET['channel']) ? (int) $_GET['channel'] : 0;
if ($channelId <= 0) {
    webhookFail(400, 'Missing channel');
}

// Liveness echo for the reachability self-test (FreeITSM → its own public URL).
// Unauthenticated and side-effect-free: it only confirms this script is reachable
// at this URL by echoing the caller's nonce. No message processing happens here.
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' && isset($_GET['ping'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'pong' => (string) $_GET['ping']]);
    exit;
}

try {
    $conn = connectToDatabase();
} catch (Exception $e) {
    webhookFail(500, 'Database unavailable');
}

$channel = loadMessagingChannel($conn, $channelId);
if (!$channel) {
    webhookFail(404, 'Unknown channel');
}
if (empty($channel['is_active'])) {
    webhookFail(403, 'Channel inactive');
}

try {
    $provider = messagingProvider($channel);
} catch (Exception $e) {
    webhookFail(500, $e->getMessage());
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// Meta Cloud GET subscription handshake (echo hub.challenge).
if ($method === 'GET') {
    $challenge = $provider->verifyChallenge($_GET);
    if ($challenge !== null) {
        header('Content-Type: text/plain');
        echo $challenge;
        exit;
    }
    webhookFail(403, 'Verification failed');
}

if ($method !== 'POST') {
    webhookFail(405, 'Method not allowed');
}

$rawBody = file_get_contents('php://input');
$headers = webhookHeaders();
$params  = $_POST;

// ── Slack's one-time endpoint check ──────────────────────────────────────────
// Handled BEFORE authentication, and only while this channel has no signing
// secret. Slack tests the request URL at the moment the app is created, which is
// also the moment the secret first exists — so demanding a signature here is not
// strict, it is a deadlock: the URL can never be verified, so the secret can
// never be collected. See SlackProvider::verifyUrlChallenge().
//
// ⚠️ This exempts the HANDSHAKE only. A real message is an `event_callback` and
// falls through to the signature check below like everything else, so no ticket
// can be injected through this gap.
if ($provider instanceof SlackProvider && !$provider->hasSigningSecret()) {
    $rawBodyEarly = file_get_contents('php://input');
    $challenge = $provider->verifyUrlChallenge($rawBodyEarly);
    if ($challenge !== null) {
        error_log('Slack URL verification answered unsigned for channel ' . $channelId
                  . ' — expected once, during setup, before the signing secret is saved.');
        header('Content-Type: text/plain');
        echo $challenge;
        exit;
    }
}

// Authenticate the request per ingress mode.
$ingress = $channel['ingress_mode'] ?? 'direct';
if ($ingress === 'relay') {
    $presented = $headers['x-freeitsm-relay-secret'] ?? '';
    $expected  = (string) ($channel['relay_secret'] ?? '');
    if ($expected === '' || !hash_equals($expected, $presented)) {
        webhookFail(403, 'Relay authentication failed');
    }
} else {
    if (!$provider->verifyWebhook($rawBody, $headers, $params, webhookFullUrl())) {
        webhookFail(403, 'Signature verification failed');
    }
}

// Slack's endpoint check. Unlike Meta's it is a POSTed JSON body rather than a
// GET, so it lands here rather than in the handshake branch above.
//
// ⚠️ Deliberately AFTER authentication. Slack signs this request like any other,
// so echoing the challenge to an unverified caller would let anyone on the
// internet confirm that this URL is a FreeITSM install — and, worse, complete
// someone else's app setup against it.
if ($provider instanceof SlackProvider) {
    $challenge = $provider->verifyUrlChallenge($rawBody);
    if ($challenge !== null) {
        header('Content-Type: text/plain');
        echo $challenge;
        exit;
    }
}

// Parse → ingest. Never let one bad message 500 the whole webhook (the provider
// would retry the batch); log and carry on.
$results = ['created' => 0, 'appended' => 0, 'duplicate' => 0, 'errors' => 0];
try {
    $messages = $provider->parseInbound($rawBody, $params);
} catch (Exception $e) {
    error_log('messaging webhook parse error (channel ' . $channelId . '): ' . $e->getMessage());
    $messages = [];
}

foreach ($messages as $msg) {
    try {
        $r = ingestInboundMessage($conn, $channel, $msg);
        $key = $r['status'] ?? 'errors';
        if (isset($results[$key])) {
            $results[$key]++;
        }
    } catch (Exception $e) {
        $results['errors']++;
        error_log('messaging ingest error (channel ' . $channelId . '): ' . $e->getMessage());
    }
}

http_response_code(200);
header('Content-Type: application/json');
echo json_encode(['success' => true, 'results' => $results]);
