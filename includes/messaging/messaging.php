<?php
/**
 * Messaging channels — shared entry point.
 *
 * Require this one file from any endpoint that deals with WhatsApp/chat channels;
 * it pulls in the provider classes and exposes the helpers below:
 *
 *   messagingProvider($channel)        → a MessagingProvider for a (decrypted) row
 *   loadMessagingChannel($conn, $id)   → a channel row with credentials decrypted
 *   normaliseChannelIdentifier($raw, $type) → a sender id, normalised per channel
 *                                        (phone as '+<digits>'; Slack as 'U…')
 *   channelWindowOpen($lastInboundAt)  → is the 24h service window still open?
 *
 * The credentials column is an encrypted JSON blob; loadMessagingChannel decrypts
 * it and decodes it into $channel['credentials'] (an array). Providers read their
 * own keys out of that array (shapes documented on each provider class).
 */

require_once __DIR__ . '/MessagingProvider.php';
require_once __DIR__ . '/TwilioProvider.php';
require_once __DIR__ . '/MetaCloudProvider.php';
require_once __DIR__ . '/SlackProvider.php';
require_once __DIR__ . '/FreeitsmProvider.php';
require_once __DIR__ . '/TelegramProvider.php';
require_once __DIR__ . '/../encryption.php';

/** The 24h provider service window, in seconds. */
if (!defined('MESSAGING_WINDOW_SECONDS')) {
    define('MESSAGING_WINDOW_SECONDS', 24 * 60 * 60);
}

/**
 * Build the right provider for a channel row (credentials already decrypted into
 * an array by loadMessagingChannel). Throws on an unknown provider.
 */
function messagingProvider(array $channel): MessagingProvider
{
    switch ($channel['provider'] ?? 'twilio') {
        case 'twilio':
            return new TwilioProvider($channel);
        case 'meta':
            return new MetaCloudProvider($channel);
        case 'freeitsm':
            return new FreeitsmProvider($channel);
        case 'slack':
            return new SlackProvider($channel);
        case 'telegram':
            return new TelegramProvider($channel);
        default:
            throw new Exception('Unknown messaging provider: ' . ($channel['provider'] ?? '?'));
    }
}

/**
 * Load a messaging_channels row with its credentials decrypted + JSON-decoded.
 * Returns null if not found. Safe to call before db_verify has run the table
 * (returns null rather than throwing).
 */
function loadMessagingChannel(PDO $conn, $channelId): ?array
{
    try {
        $stmt = $conn->prepare("SELECT * FROM messaging_channels WHERE id = ?");
        $stmt->execute([(int) $channelId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return null;
    }
    if (!$row) {
        return null;
    }
    $row['credentials'] = messagingDecodeCredentials($row['credentials'] ?? null);
    // verify_token / relay_secret are secrets, stored encrypted at rest. decryptValue
    // returns the value unchanged if it lacks the ENC: prefix, so pre-encryption or
    // empty rows still work (migration-safe).
    foreach (['verify_token', 'relay_secret'] as $secretCol) {
        if (isset($row[$secretCol]) && $row[$secretCol] !== null && $row[$secretCol] !== '') {
            try { $row[$secretCol] = decryptValue($row[$secretCol]); } catch (Exception $e) { /* leave as-is */ }
        }
    }
    $row['is_active'] = (bool) ($row['is_active'] ?? 1);
    return $row;
}

/** Decrypt + JSON-decode a stored credentials blob into an array (never throws). */
function messagingDecodeCredentials($stored): array
{
    if ($stored === null || $stored === '') {
        return [];
    }
    try {
        $plain = decryptValue($stored);
    } catch (Exception $e) {
        return [];
    }
    $decoded = json_decode((string) $plain, true);
    return is_array($decoded) ? $decoded : [];
}

/**
 * The public base URL (scheme://host[:port]) used to build webhook URLs. Uses the
 * admin-set 'messaging_public_base_url' system setting when present, otherwise
 * derives it from the current request (honouring ngrok/tunnel proxy headers).
 */
function messagingPublicBaseUrl(PDO $conn): string
{
    try {
        $st = $conn->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'messaging_public_base_url'");
        $st->execute();
        $configured = trim((string) ($st->fetchColumn() ?: ''));
        if ($configured !== '') {
            return rtrim($configured, '/');
        }
    } catch (Exception $e) { /* table missing → derive from request */ }

    // ⚠️ Deliberately NOT requestIsHttps() (GH #152): this is the address shown
    // for the provider to call, and api/messaging/webhook.php rebuilds it the same
    // way to check the provider's signature - the two must agree. See there.
    $proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') ? 'https' : 'http';
    $host = $_SERVER['HTTP_X_FORWARDED_HOST'] ?? ($_SERVER['HTTP_HOST'] ?? 'localhost');
    return $proto . '://' . $host;
}

/**
 * The full public webhook URL for a channel — what gets pasted into the provider.
 * The app root is derived from SCRIPT_NAME so it works under any sub-path.
 */
function messagingWebhookUrl(PDO $conn, int $channelId): string
{
    $base = rtrim(messagingPublicBaseUrl($conn), '/');
    $root = preg_replace('#/api/messaging/.*$#', '', $_SERVER['SCRIPT_NAME'] ?? '');

    // ⚠️ The configured public base URL may ALREADY include the app's sub-path.
    // Nothing stops an admin entering "https://example.com/freeitsm-app", and it
    // is the natural thing to type — but the app root is derived separately from
    // SCRIPT_NAME, so appending it produced ".../freeitsm-app/freeitsm-app/...".
    //
    // That URL is not merely cosmetic: it is what gets pasted into Slack or Meta
    // as the request URL, and a 404 there fails their verification with an error
    // that says nothing about a duplicated path. Found while setting up Slack on
    // an install whose base URL had the sub-path in it; the same fault has always
    // applied to WhatsApp.
    if ($root !== '' && substr($base, -strlen($root)) === $root) {
        $root = '';
    }

    return $base . $root . '/api/messaging/webhook.php?channel=' . $channelId;
}

/**
 * A short-lived, unguessable URL to one outbound attachment's bytes — for a
 * provider (Twilio) that fetches media itself rather than accepting an
 * upload. NOT a general-purpose public file host: the token is bound to this
 * ONE attachment id and expires quickly (checked in media.php), so it only
 * stays reachable for the brief window between sending the message and the
 * provider fetching it — not for as long as the ticket exists.
 */
function messagingOutboundMediaUrl(PDO $conn, int $attachmentId): string
{
    $base = rtrim(messagingPublicBaseUrl($conn), '/');
    $root = preg_replace('#/api/messaging/.*$#', '', $_SERVER['SCRIPT_NAME'] ?? '');
    if ($root !== '' && substr($base, -strlen($root)) === $root) {
        $root = '';
    }

    // Comfortably longer than any provider takes to fetch (usually seconds),
    // short enough that a URL sitting in a proxy/access log doesn't stay a
    // live attack surface indefinitely.
    $expires = time() + 3600;
    $token = messagingOutboundMediaToken($attachmentId, $expires);

    return $base . $root . '/api/messaging/media.php?id=' . $attachmentId . '&exp=' . $expires . '&token=' . $token;
}

/**
 * HMAC binding one attachment id to one expiry — see messagingOutboundMediaUrl()
 * and media.php.
 *
 * Keyed with a key DERIVED from the install's encryption key, not the encryption
 * key itself: one key, one job. The AES key protects every stored secret, and a
 * signing scheme should never be the thing that has to be reasoned about when
 * asking what that key is exposed to. The label versions the scheme, so changing
 * it later invalidates old links rather than silently accepting them.
 *
 * Throws if there is no encryption key (getEncryptionKey()); callers that face
 * the internet catch that and answer 404 rather than a fatal.
 */
function messagingOutboundMediaToken(int $attachmentId, int $expires): string
{
    require_once __DIR__ . '/../encryption.php';
    $signingKey = hash_hmac('sha256', 'freeitsm/messaging-outbound-media/v1', getEncryptionKey(), true);
    return hash_hmac('sha256', $attachmentId . ':' . $expires, $signingKey);
}

/** The category Meta's Media upload endpoint wants for a mime type ('image'|'video'|'audio'|'document'). */
function messagingMediaCategory(string $mime): string
{
    $mime = strtolower(trim(explode(';', $mime)[0]));
    if (strpos($mime, 'image/') === 0) return 'image';
    if (strpos($mime, 'video/') === 0) return 'video';
    if (strpos($mime, 'audio/') === 0) return 'audio';
    return 'document';
}

/**
 * Normalise a sender identifier for storage and matching.
 *
 * ⚠️ This USED to be phone-only, and silently destroyed anything that wasn't a
 * phone number: it stripped every non-digit, so a Slack user id like
 * "U08ABCDEF" became "+08". Every Slack user whose id contained the same digits
 * would then collapse onto ONE requester and thread into each other's tickets —
 * a data leak, not a cosmetic bug. Hence the channel-type argument.
 *
 * The phone behaviour is unchanged and must stay that way: whatsapp is the
 * default so every existing caller keeps its exact previous result.
 */
function normaliseChannelIdentifier(string $raw, string $channelType = 'whatsapp'): string
{
    $s = trim($raw);

    if ($channelType === 'slack') {
        // A Slack user id: 'U' or 'W' (Enterprise Grid), then uppercase
        // alphanumerics. Case-normalised because Slack is consistent about
        // upper-case but a hand-typed value in settings might not be.
        $s = strtoupper($s);
        return preg_match('/^[UW][A-Z0-9]{2,}$/', $s) ? $s : '';
    }

    if ($channelType === 'telegram') {
        // A Telegram chat id — a bare (optionally negative, for group chats)
        // integer. NOT a phone number: running it through the digit-stripping
        // rule below would silently drop a leading '-' and collapse a group
        // chat id onto a different, positive one.
        return preg_match('/^-?\d+$/', $s) ? $s : '';
    }

    // --- phone identifiers (whatsapp, and the default for anything else) ---
    if (stripos($s, 'whatsapp:') === 0) {
        $s = substr($s, strlen('whatsapp:'));
    }
    $digits = preg_replace('/\D+/', '', $s);
    if ($digits === '') {
        return '';
    }
    return '+' . $digits;
}

/**
 * Map a raw client-reported language tag (e.g. Telegram's message.from.
 * language_code — the user's own OS/app setting, "uk", "en", "pt-BR", never
 * something they typed) onto one of this install's I18n::SUPPORTED_LOCALES
 * keys. Unrecognised or empty input falls back to English — I18n::tFor()
 * would do that anyway, but resolving it here keeps the fallback visible to
 * the caller (e.g. for deciding whether to bother storing it).
 */
function messagingNormaliseLocale(string $raw): string
{
    require_once __DIR__ . '/../i18n.php';
    $lower = strtolower(trim($raw));
    if ($lower === '') {
        return 'en';
    }
    // Our one locale whose key isn't a bare lowercase tag.
    if ($lower === 'pt' || strpos($lower, 'pt-br') === 0) {
        return 'pt-BR';
    }
    // Telegram (and browsers) may send the old generic "no" for Norwegian;
    // bokmål is the majority written standard, so that's the sensible default
    // — a reader who actually wants nynorsk can still switch in Settings.
    if ($lower === 'no') {
        return 'nb';
    }
    $base = strtok($lower, '-_'); // "en-US" -> "en", but "pt-BR" is handled above first
    return array_key_exists($base, I18n::SUPPORTED_LOCALES) ? $base : 'en';
}

/**
 * A sensible file extension for a media MIME type (for naming downloaded media).
 */
function messagingExtForMime(string $mime): string
{
    $map = [
        'image/jpeg' => 'jpg', 'image/jpg' => 'jpg', 'image/png' => 'png',
        'image/gif' => 'gif', 'image/webp' => 'webp', 'image/heic' => 'heic',
        'application/pdf' => 'pdf',
        'audio/ogg' => 'ogg', 'audio/mpeg' => 'mp3', 'audio/mp4' => 'm4a', 'audio/amr' => 'amr', 'audio/aac' => 'aac',
        'video/mp4' => 'mp4', 'video/3gpp' => '3gp',
        'application/msword' => 'doc',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
        'application/vnd.ms-excel' => 'xls',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
        'text/plain' => 'txt', 'text/vcard' => 'vcf',
    ];
    $mime = strtolower(trim(explode(';', $mime)[0]));
    if (isset($map[$mime])) {
        return $map[$mime];
    }
    // Fallback: the subtype, stripped to something filesystem-safe.
    $sub = strpos($mime, '/') !== false ? substr($mime, strpos($mime, '/') + 1) : $mime;
    $sub = preg_replace('/[^a-z0-9]+/', '', $sub);
    return $sub !== '' ? substr($sub, 0, 8) : 'bin';
}

/**
 * How many distinct {{n}} placeholders a template body uses (the highest index).
 * e.g. "Hi {{1}}, ref {{2}}" → 2.
 */
function messagingTemplateVarCount(string $body): int
{
    if (!preg_match_all('/\{\{\s*(\d+)\s*\}\}/', $body, $m)) {
        return 0;
    }
    return max(array_map('intval', $m[1]));
}

/**
 * Substitute {{1}}, {{2}}, … in a template body with the given ordered values, for
 * storing/showing what was actually sent. Missing values are left blank.
 */
function messagingRenderTemplate(string $body, array $vars): string
{
    $vals = array_values($vars);
    return preg_replace_callback('/\{\{\s*(\d+)\s*\}\}/', function ($m) use ($vals) {
        $idx = (int) $m[1] - 1;
        return $idx >= 0 && isset($vals[$idx]) ? (string) $vals[$idx] : '';
    }, $body);
}

/**
 * Build a plain-text transcript of a channel conversation (oldest first), capped
 * to keep token usage predictable. Used by the AI summary / suggested-reply
 * endpoints. Non-channel (email) rows are excluded.
 */
function messagingBuildTranscript(PDO $conn, int $ticketId): string
{
    $stmt = $conn->prepare(
        "SELECT direction, from_name, from_address, body_content
         FROM emails
         WHERE ticket_id = ? AND channel <> 'email'
         ORDER BY received_datetime ASC, id ASC
         LIMIT 50"
    );
    $stmt->execute([$ticketId]);
    $lines = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $who = ($row['direction'] === 'Outbound')
            ? 'Analyst'
            : ('Customer (' . ($row['from_name'] ?: $row['from_address']) . ')');
        $text = trim(strip_tags((string) $row['body_content']));
        if ($text !== '') {
            $lines[] = "$who: $text";
        }
    }
    return implode("\n", $lines);
}

/**
 * Is the 24h service window still open, given the last inbound timestamp
 * (a 'Y-m-d H:i:s' UTC string, or null)? Outside the window, free-text replies
 * are blocked by the provider and only template messages are allowed.
 */
/**
 * Does this channel type have a provider reply window at all?
 *
 * Only WhatsApp does: it is a WhatsApp Business rule, not a general one. Web chat
 * is self-hosted, Slack lets you answer a thread from last year, and a Telegram
 * bot may message any chat that has ever messaged it.
 *
 * 🔴 The ONE place this list lives. It used to be written out by hand in
 * send_message.php and get_ticket_thread.php (with a comment begging the two to
 * agree), and PR #159 added a third copy in send_attachment.php. If the composer
 * and the API disagree, the composer greys out a reply the API would accept, or
 * offers one it will refuse, and it reads as a broken integration.
 */
function channelHasServiceWindow(string $channelType): bool
{
    return !in_array($channelType, ['webchat', 'slack', 'telegram'], true);
}

function channelWindowOpen(?string $lastInboundAt): bool
{
    if (!$lastInboundAt) {
        return false;
    }
    $ts = strtotime($lastInboundAt . ' UTC');
    if ($ts === false) {
        return false;
    }
    return (time() - $ts) < MESSAGING_WINDOW_SECONDS;
}

/**
 * Authorise a request that acts on ONE messaging channel.
 *
 * ⚠️ Two doors lead to these endpoints and they are gated differently:
 *
 *   Tickets → Settings → Messaging / Web chat  — RBAC, Cap::TICKETS_MESSAGING
 *   System  → Integrations → Slack             — administrators only
 *
 * So an admin without the Tickets messaging capability must be able to
 * administer a SLACK channel and nothing else. Widening the capability instead
 * would hand them a WhatsApp channel's credentials, which is precisely what that
 * capability exists to withhold.
 *
 * Returns true when the caller may act on this channel by virtue of being an
 * admin working on Slack; false means "fall through to the normal capability
 * check", which then produces the standard error.
 */
function messagingAdminMayAdministerChannel(PDO $conn, int $channelId): bool
{
    if ($channelId <= 0 || !sessionIsAdmin()) {
        return false;
    }
    try {
        $stmt = $conn->prepare("SELECT provider FROM messaging_channels WHERE id = ?");
        $stmt->execute([$channelId]);
        return ((string) $stmt->fetchColumn()) === 'slack';
    } catch (Exception $e) {
        return false;
    }
}
