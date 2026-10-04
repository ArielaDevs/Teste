<?php
/**
 * CSAT Survey Helpers
 *
 * Creates a ticket_csat_responses row with a tokenised URL and sends the
 * survey email via the existing template engine. The token is HMAC-derived
 * so a leaked database row can't be replayed against a different install
 * (the per-install secret lives in system_settings.csat_token_secret).
 *
 * Called from:
 *   - api/tickets/assign_ticket.php when a ticket transitions into a closed
 *     status and the global csat_mode is 'auto'
 *   - api/tickets/request_csat.php when an analyst clicks the manual
 *     "Request feedback" button (regardless of mode, as long as mode != 'off')
 */

require_once __DIR__ . '/template_email.php';
require_once __DIR__ . '/encryption.php';   // csat_token_secret is stored encrypted
require_once __DIR__ . '/session_security.php'; // requestScheme() — proxy-aware (GH #152)

/**
 * Read a system_settings key with a default fallback.
 */
function csatGetSetting(PDO $conn, string $key, string $default = ''): string {
    static $cache = [];
    if (isset($cache[$key])) return $cache[$key];
    $stmt = $conn->prepare("SELECT setting_value FROM system_settings WHERE setting_key = ?");
    $stmt->execute([$key]);
    $val = $stmt->fetchColumn();
    // csat_token_secret is a signing key, so it is encrypted at rest. Decrypt on the
    // way out; decryptValue() passes a plaintext value through untouched, so tokens
    // issued before the change still verify against the same secret.
    if ($val !== false && $val !== null && isEncryptedSettingKey($key)) {
        $val = decryptValue($val);
    }
    $cache[$key] = ($val === false || $val === null) ? $default : (string)$val;
    return $cache[$key];
}

/**
 * Generate a token unique to (ticket, response_row). 32-byte random base ensures
 * it's not guessable from ticket id alone; we then HMAC it with the install
 * secret so even a database leak of `token` alone isn't enough — though for the
 * survey URL we just use the random portion since the response row IS the
 * authoritative store. Keeping HMAC available as a verification step in case we
 * ever want to surface the link in places where the DB isn't queried.
 */
function csatGenerateToken(): string {
    return bin2hex(random_bytes(24)); // 48 hex chars — fits in VARCHAR(64)
}

/**
 * Build the full public URL the user clicks. Uses the host the request came
 * in on so multi-domain installs (test.example.com vs example.com) work without
 * config changes.
 */
function csatBuildUrl(string $token): string {
    $scheme = requestScheme();
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    // Find the install root — config.php sits at the repo root, so we walk
    // up from this file's directory to derive it relative to DOCUMENT_ROOT.
    $docRoot = rtrim(str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT'] ?? ''), '/');
    $appRoot = rtrim(str_replace('\\', '/', realpath(__DIR__ . '/..')), '/');
    $appPath = $docRoot && strpos($appRoot, $docRoot) === 0
        ? substr($appRoot, strlen($docRoot))
        : '';
    // Canonical survey URL since the root-folder tidy (the old /csat.php
    // form still 301s here, so links in already-sent emails keep working).
    return $scheme . '://' . $host . $appPath . '/csat?token=' . urlencode($token);
}

/**
 * Result codes returned by sendCsatSurvey() so the caller can give an honest
 * UI message rather than silently lying. The DB row + email send are only
 * performed when the result will be 'sent' — every other case is a precondition
 * failure that should NOT leave an orphan row behind.
 */
const CSAT_RESULT_SENT          = 'sent';
const CSAT_RESULT_OFF           = 'off';            // csat_mode = off
const CSAT_RESULT_ALREADY_SENT  = 'already_sent';   // one-per-ticket guard tripped
const CSAT_RESULT_NO_TEMPLATE   = 'no_template';    // no active csat_request template
const CSAT_RESULT_NO_MAILBOX    = 'no_mailbox';     // ticket has no mailbox to send from

// ===========================================================================
//  Asking in the customer's own chat (PR #166, built on turbay-a's original)
// ===========================================================================
//
// A customer who wrote in on WhatsApp, Telegram, Slack, Teams or Mattermost is
// asked for their rating in that chat - buttons where the channel has them, a
// "reply 1 to 5" message where it does not - instead of by email.
//
// TRAP: an upgrade must not change who is asked, or how.
//   `csat_in_channel` is OFF after an upgrade (db_verify seeds '0'; a new
//   install's freeitsm.sql seeds '1'). An install that has emailed every survey
//   for months must not start messaging its chat customers the day it upgrades.
//
// TRAP: match a chat reply only to a request sent IN THAT CHAT.
//   channel_id + channel_from record where a request went. Never find "the
//   pending survey" through the emails table alone - that also finds emailed
//   surveys and other chats' requests.
//
// TRAP: no orphan rows. The row exists only to give the buttons an id; if the
//   send fails it is deleted and the email survey is tried instead. With
//   one-survey-per-ticket on, a stray row would mean the customer is never asked.
//
// TRAP: WhatsApp outside its 24-hour window cannot take a free-form message -
//   csatTicketChannel() checks channelWindowOpen() and falls back to email.

/** How long a chat rating request is listened for. A digit a week later is a message, not a rating. */
const CSAT_CHANNEL_REPLY_WINDOW_DAYS = 7;

/**
 * The chat a ticket's customer is talking on, ready to ask in - or null, which
 * means "use the email survey": the setting is off, the ticket's latest contact
 * was not a chat, the channel has gone, or (WhatsApp) the 24-hour window has
 * closed and a free-form message cannot be sent.
 *
 * @return array{channel:array, from:string, address:string}|null
 */
function csatTicketChannel(PDO $conn, int $ticketId): ?array {
    if (csatGetSetting($conn, 'csat_in_channel', '0') !== '1') {
        return null;
    }
    require_once __DIR__ . '/messaging/messaging.php';
    $stmt = $conn->prepare(
        "SELECT channel, channel_id, from_address, to_recipients, received_datetime
         FROM emails
         WHERE ticket_id = ? AND channel <> 'email' AND channel_id IS NOT NULL AND direction = 'Inbound'
         ORDER BY received_datetime DESC, id DESC LIMIT 1"
    );
    $stmt->execute([$ticketId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return null;
    }
    $channel = loadMessagingChannel($conn, (int)$row['channel_id']);
    if (!$channel || empty($channel['is_active'])) {
        return null;
    }
    $type = (string)($channel['channel_type'] ?? $row['channel']);
    if (channelHasServiceWindow($type) && !channelWindowOpen($row['received_datetime'])) {
        return null;   // WhatsApp outside 24h: only a pre-approved template may be sent
    }
    $address = messagingReplyAddress($type, $row);
    if ($address === '' || (string)$row['from_address'] === '') {
        return null;
    }
    return ['channel' => $channel, 'from' => (string)$row['from_address'], 'address' => $address];
}

/**
 * The language to write a customer-facing rating message in. Telegram has the
 * customer's own language_code (stored on the identity link); other channels do
 * not report one, so they get English.
 */
function csatChannelLocale(PDO $conn, array $channel, string $from): string {
    if (($channel['channel_type'] ?? '') !== 'telegram') {
        return 'en';
    }
    try {
        $stmt = $conn->prepare("SELECT locale FROM messaging_identity_links WHERE channel_type = 'telegram' AND external_id = ?");
        $stmt->execute([$from]);
        $locale = (string)($stmt->fetchColumn() ?: '');
    } catch (Throwable $e) {
        $locale = '';
    }
    return $locale !== '' ? $locale : 'en';
}

/**
 * Ask a chat customer for a 1-5 rating in their chat. Returns the response id,
 * or throws - having removed its own row - so the caller can fall back to email.
 */
function csatSendInChannel(PDO $conn, int $ticketId, array $target, ?int $analystId): int {
    require_once __DIR__ . '/messaging/messaging.php';
    require_once __DIR__ . '/i18n.php';

    $channel = $target['channel'];
    $conn->prepare(
        "INSERT INTO ticket_csat_responses (ticket_id, token, sent_datetime, analyst_id, created_at, channel_id, channel_from)
         VALUES (?, ?, UTC_TIMESTAMP(), ?, UTC_TIMESTAMP(), ?, ?)"
    )->execute([$ticketId, csatGenerateToken(), $analystId, (int)$channel['id'], $target['from']]);
    $responseId = (int)$conn->lastInsertId();

    try {
        $text = I18n::tFor(csatChannelLocale($conn, $channel, $target['from']), 'tickets.csat_channel.prompt');
        messagingProvider($channel)->sendRatingRequest($target['address'], $text, $responseId);
    } catch (Throwable $e) {
        // Nothing orphaned: the request never reached them, so neither does the row.
        $conn->prepare("DELETE FROM ticket_csat_responses WHERE id = ?")->execute([$responseId]);
        throw $e;
    }
    return $responseId;
}

/**
 * The rating request a customer's bare digit answers, or null - in which case
 * the digit is an ordinary message. All of these must hold:
 *   - the request was sent IN THIS CHAT (channel_id + channel_from);
 *   - it is unanswered and at most CSAT_CHANNEL_REPLY_WINDOW_DAYS old;
 *   - the customer has written nothing else in this chat since it was sent.
 *
 * TRAP: a bare digit is usually NOT a rating. "Which floor?" "3" is an answer
 *   to the analyst, and a "2" next month starts a new conversation - loosen any
 *   of the three conditions and real messages vanish into a survey, with no
 *   error anywhere. tests/messaging-teams-mattermost.php checks each one.
 */
function csatPendingRequestForChat(PDO $conn, int $channelId, string $from): ?int {
    try {
        $stmt = $conn->prepare(
            "SELECT r.id FROM ticket_csat_responses r
             WHERE r.channel_id = ? AND r.channel_from = ?
               AND r.rating IS NULL AND r.sent_datetime IS NOT NULL
               AND r.sent_datetime >= UTC_TIMESTAMP() - INTERVAL " . (int)CSAT_CHANNEL_REPLY_WINDOW_DAYS . " DAY
               AND NOT EXISTS (
                   SELECT 1 FROM emails e
                   WHERE e.channel_id = r.channel_id AND e.from_address = r.channel_from
                     AND e.direction = 'Inbound' AND e.received_datetime > r.sent_datetime
               )
             ORDER BY r.id DESC LIMIT 1"
        );
        $stmt->execute([$channelId, $from]);
        $id = $stmt->fetchColumn();
    } catch (Throwable $e) {
        return null;   // before Database Verification: no chat surveys exist yet
    }
    return $id ? (int)$id : null;
}

/** Was this request sent to this customer on this channel? Guards a button press against a forged or replayed id. */
function csatResponseBelongsToChat(PDO $conn, int $responseId, int $channelId, string $from): bool {
    try {
        $stmt = $conn->prepare("SELECT 1 FROM ticket_csat_responses WHERE id = ? AND channel_id = ? AND channel_from = ?");
        $stmt->execute([$responseId, $channelId, $from]);
        return (bool)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

/** The rating a survey holds, or null while it is unanswered. */
function csatStoredRating(PDO $conn, int $responseId): ?int {
    try {
        $stmt = $conn->prepare("SELECT rating FROM ticket_csat_responses WHERE id = ?");
        $stmt->execute([$responseId]);
        $r = $stmt->fetchColumn();
        return ($r === false || $r === null) ? null : (int)$r;
    } catch (Throwable $e) {
        return null;
    }
}

/** Record a 1-5 rating once. Returns false if it was already answered or out of range. */
function csatRecordRating(PDO $conn, int $responseId, int $rating): bool {
    if ($rating < 1 || $rating > 5) {
        return false;
    }
    $upd = $conn->prepare(
        "UPDATE ticket_csat_responses SET rating = ?, responded_datetime = UTC_TIMESTAMP()
         WHERE id = ? AND rating IS NULL"
    );
    $upd->execute([$rating, $responseId]);
    return $upd->rowCount() > 0;
}

/**
 * Create a CSAT response row and send the survey email. Returns an array:
 *   ['result' => <CSAT_RESULT_*>, 'response_id' => int|null]
 *
 * Pass $force = true to bypass the one-per-ticket guard when the analyst
 * deliberately re-requests feedback.
 *
 * Pre-flight checks (mode, template existence, mailbox availability) all run
 * BEFORE the row is inserted, so failed sends don't leave orphan tokens behind.
 */
function sendCsatSurvey(PDO $conn, int $ticketId, ?int $analystId, bool $force = false): array {
    $mode = csatGetSetting($conn, 'csat_mode', 'off');
    if ($mode === 'off') {
        return ['result' => CSAT_RESULT_OFF, 'response_id' => null];
    }

    // Skip if a response row already exists and one-per-ticket is on (unless forced)
    if (!$force && csatGetSetting($conn, 'csat_one_per_ticket', '1') === '1') {
        $existing = $conn->prepare("SELECT id FROM ticket_csat_responses WHERE ticket_id = ? LIMIT 1");
        $existing->execute([$ticketId]);
        if ($existing->fetchColumn()) {
            return ['result' => CSAT_RESULT_ALREADY_SENT, 'response_id' => null];
        }
    }

    // A chat customer is asked in their chat when the install has chosen that
    // (csat_in_channel) and the chat can take a message now. If the send fails,
    // csatSendInChannel() has removed its row and we fall through to email, so
    // a customer who also has an email address is still asked (PR #166).
    $target = csatTicketChannel($conn, $ticketId);
    if ($target) {
        try {
            return ['result' => CSAT_RESULT_SENT, 'response_id' => csatSendInChannel($conn, $ticketId, $target, $analystId)];
        } catch (Throwable $e) {
            error_log('CSAT in-chat request failed for ticket ' . $ticketId . ', trying email: ' . $e->getMessage());
        }
    }

    // Pre-flight: an active csat_request template must exist. Without it, the
    // template engine silently no-ops and we'd save a useless row with a useless token.
    $tplCheck = $conn->prepare("SELECT id FROM ticket_email_templates WHERE event_trigger = 'csat_request' AND is_active = 1 LIMIT 1");
    $tplCheck->execute();
    if (!$tplCheck->fetchColumn()) {
        return ['result' => CSAT_RESULT_NO_TEMPLATE, 'response_id' => null];
    }

    // Pre-flight: a mailbox must be reachable from this ticket. Manual / portal
    // tickets that came in without an email won't have one — survey would no-op.
    require_once __DIR__ . '/template_email.php';
    $mailbox = templateGetMailboxForTicket($conn, $ticketId);
    if (!$mailbox) {
        return ['result' => CSAT_RESULT_NO_MAILBOX, 'response_id' => null];
    }

    // All checks passed — now we can durably create the row and dispatch the email
    $token = csatGenerateToken();
    $insert = $conn->prepare(
        "INSERT INTO ticket_csat_responses (ticket_id, token, sent_datetime, analyst_id, created_at)
         VALUES (?, ?, UTC_TIMESTAMP(), ?, UTC_TIMESTAMP())"
    );
    $insert->execute([$ticketId, $token, $analystId]);
    $responseId = (int)$conn->lastInsertId();

    sendTemplateEmail($conn, $ticketId, 'csat_request', [
        'csat_link' => csatBuildUrl($token),
    ]);

    return ['result' => CSAT_RESULT_SENT, 'response_id' => $responseId];
}
