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
/**
 * The messaging channel row a ticket's customer is talking on, or null when the
 * ticket's latest contact was email/portal (those keep the emailed survey).
 */
function csatTicketChannel(PDO $conn, int $ticketId): ?array {
    require_once __DIR__ . '/messaging/messaging.php';
    $stmt = $conn->prepare(
        "SELECT channel_id FROM emails
         WHERE ticket_id = ? AND channel <> 'email' AND channel_id IS NOT NULL
         ORDER BY id DESC LIMIT 1"
    );
    $stmt->execute([$ticketId]);
    $cid = $stmt->fetchColumn();
    if (!$cid) {
        return null;
    }
    $channel = loadMessagingChannel($conn, (int)$cid);
    return ($channel && !empty($channel['is_active'])) ? $channel : null;
}

/**
 * Where to send the rating request on this channel: the customer's own
 * address, which is the Slack thread for Slack and the sender for everything else
 * (the same rule send_message.php uses).
 */
function csatChannelRecipient(PDO $conn, int $ticketId, array $channel): string {
    $stmt = $conn->prepare(
        "SELECT from_address, to_recipients FROM emails
         WHERE ticket_id = ? AND channel = ? AND direction = 'Inbound'
         ORDER BY id DESC LIMIT 1"
    );
    $stmt->execute([$ticketId, $channel['channel_type'] ?? 'whatsapp']);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return '';
    }
    return ($channel['channel_type'] ?? '') === 'slack'
        ? trim((string)($row['to_recipients'] ?? ''))
        : (string)$row['from_address'];
}

/**
 * The language to write a customer-facing rating message in. Telegram has the
 * customer's own language_code (stored on the identity link); other channels do
 * not report one, so they get English.
 */
function csatChannelLocale(PDO $conn, array $channel, string $recipient): string {
    if (($channel['channel_type'] ?? '') !== 'telegram') {
        return 'en';
    }
    $stmt = $conn->prepare("SELECT locale FROM messaging_identity_links WHERE channel_type = 'telegram' AND external_id = ?");
    $stmt->execute([$recipient]);
    $locale = (string)($stmt->fetchColumn() ?: '');
    return $locale !== '' ? $locale : 'en';
}

/**
 * Ask a messaging customer for a 1–5 rating, on their channel rather than by
 * email. Creates the ticket_csat_responses row first so a button press or a
 * typed digit always has a row to land on. Returns the new response id.
 */
function csatSendInChannel(PDO $conn, int $ticketId, array $channel, ?int $analystId): int {
    require_once __DIR__ . '/messaging/messaging.php';
    require_once __DIR__ . '/i18n.php';

    $recipient = csatChannelRecipient($conn, $ticketId, $channel);
    if ($recipient === '') {
        throw new Exception('This ticket has no customer address on its channel to send the rating request to.');
    }

    $ins = $conn->prepare(
        "INSERT INTO ticket_csat_responses (ticket_id, token, sent_datetime, analyst_id, created_at)
         VALUES (?, ?, UTC_TIMESTAMP(), ?, UTC_TIMESTAMP())"
    );
    $ins->execute([$ticketId, csatGenerateToken(), $analystId]);
    $responseId = (int)$conn->lastInsertId();

    $locale = csatChannelLocale($conn, $channel, $recipient);
    $text   = I18n::tFor($locale, 'tickets.csat_channel.prompt');
    messagingProvider($channel)->sendRatingRequest($recipient, $text, $responseId);

    return $responseId;
}

/** The unanswered in-channel rating request a customer's digit reply belongs to, or null. */
function csatPendingRequestForChat(PDO $conn, int $channelId, string $chatId): ?int {
    $stmt = $conn->prepare(
        "SELECT r.id FROM ticket_csat_responses r
         JOIN emails e ON e.ticket_id = r.ticket_id
         WHERE e.channel_id = ? AND e.from_address = ? AND e.direction = 'Inbound'
           AND r.sent_datetime IS NOT NULL AND r.rating IS NULL
         ORDER BY r.id DESC LIMIT 1"
    );
    $stmt->execute([$channelId, $chatId]);
    $id = $stmt->fetchColumn();
    return $id ? (int)$id : null;
}

/** Does this response belong to this customer on this channel? Guards button presses. */
function csatResponseBelongsToChat(PDO $conn, int $responseId, int $channelId, string $chatId): bool {
    $stmt = $conn->prepare(
        "SELECT 1 FROM ticket_csat_responses r
         JOIN emails e ON e.ticket_id = r.ticket_id
         WHERE r.id = ? AND e.channel_id = ? AND e.from_address = ? AND e.direction = 'Inbound'
         LIMIT 1"
    );
    $stmt->execute([$responseId, $channelId, $chatId]);
    return (bool)$stmt->fetchColumn();
}

/** Record a 1–5 rating once. Returns false if it was already answered or out of range. */
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

    // A messaging customer is asked on the channel they are already using — the
    // email template and mailbox checks below are for email tickets only.
    $channel = csatTicketChannel($conn, $ticketId);
    if ($channel) {
        return ['result' => CSAT_RESULT_SENT, 'response_id' => csatSendInChannel($conn, $ticketId, $channel, $analystId)];
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
