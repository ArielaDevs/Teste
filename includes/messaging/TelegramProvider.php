<?php
/**
 * TelegramProvider — chat via the Telegram Bot API.
 *
 * Simpler than WhatsApp in two ways that matter here: there is no 24h service
 * window (a bot may message any chat that has ever messaged it, at any time —
 * sendTemplate() below is therefore just sendMessage() with the template body
 * rendered), and there is no separate business-verification step — a bot token
 * from @BotFather is immediately live.
 *
 * Credentials JSON (messaging_channels.credentials, encrypted at rest):
 *   { "bot_token": "123456789:AA...-the-BotFather-token" }
 *
 * Inbound: Telegram POSTs JSON (the Update object) to the webhook URL set via
 * setWebhook. Authenticity is the secret token Telegram echoes back on every
 * call once configured — X-Telegram-Bot-Api-Secret-Token — which is stored in
 * messaging_channels.verify_token (the same column Meta uses for its own
 * verify token; each provider reads it however its own handshake needs).
 *
 * The identifier used everywhere else in this codebase ('from') is the chat id
 * — a bare integer, stringified — because that is what sendMessage() needs
 * back to reply. It is NOT a phone number, so normaliseChannelIdentifier() has
 * a 'telegram' branch that leaves it alone rather than running the WhatsApp
 * digit-stripping rule over it.
 */

require_once __DIR__ . '/MessagingProvider.php';

class TelegramProvider extends MessagingProvider
{
    private const API_BASE = 'https://api.telegram.org/bot';
    private const FILE_BASE = 'https://api.telegram.org/file/bot';

    public function verifyWebhook(string $rawBody, array $headers, array $params, string $url): bool
    {
        $presented = $headers['x-telegram-bot-api-secret-token'] ?? '';
        $expected  = (string) ($this->channel['verify_token'] ?? '');
        // No secret configured means the channel was never finished setting up —
        // refuse rather than accept an unauthenticated inbound message, exactly
        // like Meta refuses without an app_secret.
        if ($expected === '' || $presented === '') {
            return false;
        }
        return hash_equals($expected, $presented);
    }

    public function parseInbound(string $rawBody, array $params): array
    {
        $payload = json_decode($rawBody, true);
        if (!is_array($payload)) {
            return [];
        }
        // A press on one of the CSAT rating buttons (see sendRatingRequest()).
        // Only ours — csat:<response id>:<1-5> — are read; anything else is ignored.
        if (is_array($payload['callback_query'] ?? null)) {
            return $this->parseRatingPress($payload['callback_query']);
        }
        // A bot also receives edited_message, channel_post, etc.
        // Only plain messages (new or edited) become tickets/replies today.
        $message = $payload['message'] ?? ($payload['edited_message'] ?? null);
        if (!is_array($message)) {
            return [];
        }

        $chat = $message['chat'] ?? [];
        $chatId = (string) ($chat['id'] ?? '');
        $updateId = $payload['update_id'] ?? null;
        $messageId = $message['message_id'] ?? null;
        if ($chatId === '' || $messageId === null) {
            return [];
        }

        $from = $message['from'] ?? [];
        $name = trim(trim((string) ($from['first_name'] ?? '')) . ' ' . trim((string) ($from['last_name'] ?? '')));
        if ($name === '' && !empty($from['username'])) {
            $name = '@' . $from['username'];
        }

        $entry = [
            // Bare chat id, e.g. "987654321". Left as-is by
            // normaliseChannelIdentifier() for channel_type 'telegram'.
            'from'            => $chatId,
            'to'              => (string) ($this->channel['channel_ref'] ?? ''),
            'body'            => trim((string) ($message['text'] ?? ($message['caption'] ?? ''))),
            'profile_name'    => $name,
            // Message ids are only unique per (bot, chat), so the dedupe key
            // carries all three. 🔴 PR #159 used chat + message id only - and a
            // private chat id is the person's user id, the same on every bot,
            // while each bot numbers its own chat from 1. So the first message
            // a person sent to a SECOND bot matched the first one they sent to
            // the first bot, and ingest.php dropped it as a "duplicate".
            'provider_msg_id' => 'tg:' . (int) ($this->channel['id'] ?? 0) . ':' . $chatId . ':' . $messageId,
            'media'           => $this->extractMedia($message),
            'timestamp'       => isset($message['date']) ? (int) $message['date'] : null,
            // Telegram's own IETF language tag for this user's client (e.g.
            // "uk", "en", "pt-BR") — their OS/app setting, not something they
            // typed. ingest.php uses it to pick which language the bot's own
            // prompts/replies are sent in. Empty when Telegram doesn't report
            // one (some clients omit it).
            'language_code'   => trim((string) ($from['language_code'] ?? '')),
        ];

        // A tap on the "Share phone number" button (see requestContact() below)
        // arrives as its own message shape — a `contact` object, normally with
        // no text at all. Surface it so ingest.php's identity gate can act on
        // it; only trust a contact that is the SENDER's own (Telegram lets a
        // user forward someone else's saved contact card too, which must not
        // be treated as proof of the sender's own number).
        $contact = $message['contact'] ?? null;
        if (is_array($contact) && !empty($contact['phone_number'])
            && isset($contact['user_id'], $from['id'])
            && (int) $contact['user_id'] === (int) $from['id']) {
            $entry['contact'] = [
                'phone' => '+' . ltrim((string) $contact['phone_number'], '+'),
            ];
        } elseif (is_array($contact) && $entry['body'] === '') {
            // Somebody ELSE's contact card (a colleague's, say). Never identity -
            // see above - but it IS what the customer sent, so keep it as text
            // rather than letting ingest store "[empty message]" and lose it.
            $who = trim(trim((string) ($contact['first_name'] ?? '')) . ' ' . trim((string) ($contact['last_name'] ?? '')));
            $num = trim((string) ($contact['phone_number'] ?? ''));
            $entry['body'] = '[Contact card: ' . ($who !== '' ? $who : 'no name')
                           . ($num !== '' ? ', +' . ltrim($num, '+') : '') . ']';
        }

        return [$entry];
    }

    /** Pull the largest/only file out of whichever media field is present. */
    private function extractMedia(array $message): array
    {
        if (!empty($message['photo']) && is_array($message['photo'])) {
            // photo is an array of sizes, smallest to largest — take the last.
            $sizes = $message['photo'];
            $largest = end($sizes);
            return [[
                'id'           => $largest['file_id'] ?? '',
                'content_type' => 'image/jpeg',
                'filename'     => '',
            ]];
        }
        foreach (['document', 'video', 'audio', 'voice', 'video_note', 'sticker'] as $type) {
            if (!empty($message[$type]) && is_array($message[$type])) {
                $m = $message[$type];
                $defaultMime = ($type === 'voice') ? 'audio/ogg' : 'application/octet-stream';
                return [[
                    'id'           => $m['file_id'] ?? '',
                    'content_type' => $m['mime_type'] ?? $defaultMime,
                    'filename'     => $m['file_name'] ?? '',
                ]];
            }
        }
        return [];
    }

    public function sendMessage(string $to, string $body): string
    {
        $token = $this->channel['credentials']['bot_token'] ?? '';
        if ($token === '') {
            throw new Exception('Telegram channel is missing its bot token.');
        }
        if ($to === '') {
            throw new Exception('No chat id to send to.');
        }

        $payload = json_encode([
            'chat_id' => $to,
            'text'    => $body,
        ]);

        [$code, $resp] = $this->httpRequest(self::API_BASE . $token . '/sendMessage', [
            'method'  => 'POST',
            'headers' => ['Content-Type: application/json'],
            'body'    => $payload,
        ]);

        $json = json_decode($resp, true);
        if ($code < 200 || $code >= 300 || empty($json['ok'])) {
            $msg = $json['description'] ?? ('HTTP ' . $code);
            throw new Exception('Telegram rejected the message: ' . $msg);
        }
        return (string) ($json['result']['message_id'] ?? '');
    }

    /**
     * Ask the chat to share their phone number, via Telegram's native
     * "Share phone number" button (a reply keyboard with request_contact,
     * not an inline button — that's what makes Telegram hand back a verified
     * contact object rather than free-typed, unverifiable text).
     *
     * One-time keyboard: it disappears from their client after one tap, so it
     * doesn't linger once the identity gate (ingest.php) has what it needs.
     */
    /** A rating press: one normalised entry carrying 'csat' for ingest.php. */
    private function parseRatingPress(array $cb): array
    {
        $data = (string)($cb['data'] ?? '');
        if (!preg_match('/^csat:(\d+):([1-5])$/', $data, $m)) {
            return [];
        }
        $chatId = (string)($cb['message']['chat']['id'] ?? '');
        $callbackId = (string)($cb['id'] ?? '');
        if ($chatId === '' || $callbackId === '') {
            return [];
        }
        return [[
            'from'            => $chatId,
            'to'              => (string)($this->channel['channel_ref'] ?? ''),
            'body'            => '',
            'profile_name'    => '',
            'provider_msg_id' => 'tgcb:' . $callbackId,
            'media'           => [],
            'timestamp'       => null,
            'language_code'   => trim((string)($cb['from']['language_code'] ?? '')),
            'csat'            => [
                'response_id' => (int)$m[1],
                'rating'      => (int)$m[2],
                'callback_id' => $callbackId,
            ],
        ]];
    }

    /** Show the 1–5 buttons under the rating question. */
    public function sendRatingRequest(string $to, string $text, int $responseId): string
    {
        $token = $this->channel['credentials']['bot_token'] ?? '';
        if ($token === '') {
            throw new Exception('Telegram channel is missing its bot token.');
        }
        $row = [];
        for ($n = 1; $n <= 5; $n++) {
            $row[] = ['text' => (string)$n, 'callback_data' => 'csat:' . $responseId . ':' . $n];
        }
        $payload = json_encode([
            'chat_id'      => $to,
            'text'         => $text,
            'reply_markup' => ['inline_keyboard' => [$row]],
        ]);
        [$code, $resp] = $this->httpRequest(self::API_BASE . $token . '/sendMessage', [
            'method'  => 'POST',
            'headers' => ['Content-Type: application/json'],
            'body'    => $payload,
        ]);
        $json = json_decode($resp, true);
        if ($code < 200 || $code >= 300 || empty($json['ok'])) {
            throw new Exception('Telegram rejected the rating request: ' . ($json['description'] ?? ('HTTP ' . $code)));
        }
        return (string)($json['result']['message_id'] ?? '');
    }

    /**
     * Stop the button's loading spinner and show a short toast. Best-effort: a
     * failure here must never undo a rating that has already been saved.
     */
    public function answerCallbackQuery(string $callbackQueryId, string $text): void
    {
        $token = $this->channel['credentials']['bot_token'] ?? '';
        if ($token === '' || $callbackQueryId === '') {
            return;
        }
        try {
            $this->httpRequest(self::API_BASE . $token . '/answerCallbackQuery', [
                'method'  => 'POST',
                'headers' => ['Content-Type: application/json'],
                'body'    => json_encode(['callback_query_id' => $callbackQueryId, 'text' => $text]),
            ]);
        } catch (Exception $e) {
            error_log('Telegram answerCallbackQuery failed: ' . $e->getMessage());
        }
    }

    public function requestContact(string $chatId, string $promptText, string $buttonText = 'Share phone number'): string
    {
        $token = $this->channel['credentials']['bot_token'] ?? '';
        if ($token === '') {
            throw new Exception('Telegram channel is missing its bot token.');
        }

        $payload = json_encode([
            'chat_id'      => $chatId,
            'text'         => $promptText,
            'reply_markup' => [
                'keyboard'          => [[
                    ['text' => $buttonText, 'request_contact' => true],
                ]],
                'resize_keyboard'   => true,
                'one_time_keyboard' => true,
            ],
        ]);

        [$code, $resp] = $this->httpRequest(self::API_BASE . $token . '/sendMessage', [
            'method'  => 'POST',
            'headers' => ['Content-Type: application/json'],
            'body'    => $payload,
        ]);

        $json = json_decode($resp, true);
        if ($code < 200 || $code >= 300 || empty($json['ok'])) {
            throw new Exception('Telegram rejected the contact request: ' . ($json['description'] ?? ('HTTP ' . $code)));
        }
        return (string) ($json['result']['message_id'] ?? '');
    }

    /**
     * Telegram has no provider-hosted, pre-approved template system (there is
     * no 24h window to work around), so a "template" here is just this app's
     * own stored body with {{n}} placeholders rendered and sent as a normal
     * message.
     */
    public function sendTemplate(string $to, array $template, array $vars): string
    {
        $body = messagingRenderTemplate((string) ($template['body'] ?? ''), $vars);
        if (trim($body) === '') {
            throw new Exception('This template has no body to send.');
        }
        return $this->sendMessage($to, $body);
    }

    public function downloadMedia(array $item): array
    {
        $token = $this->channel['credentials']['bot_token'] ?? '';
        $fileId = $item['id'] ?? '';
        if ($token === '') {
            throw new Exception('Telegram channel is missing its bot token.');
        }
        if ($fileId === '') {
            throw new Exception('Media item has no file id.');
        }

        // Step 1: resolve the file id to a file_path (short-lived).
        [$c1, $r1] = $this->httpRequest(self::API_BASE . $token . '/getFile?file_id=' . urlencode($fileId), [
            'method' => 'GET',
        ]);
        $meta = json_decode($r1, true);
        if ($c1 < 200 || $c1 >= 300 || empty($meta['ok']) || empty($meta['result']['file_path'])) {
            throw new Exception('Telegram file lookup failed: ' . ($meta['description'] ?? ('HTTP ' . $c1)));
        }
        $filePath = $meta['result']['file_path'];

        // Step 2: download the bytes — no auth header needed, the token is in the URL.
        [$c2, $body] = $this->httpRequest(self::FILE_BASE . $token . '/' . $filePath, [
            'method' => 'GET',
            'follow' => true,
        ]);
        if ($c2 < 200 || $c2 >= 300 || $body === '') {
            throw new Exception('Telegram file download failed (HTTP ' . $c2 . ').');
        }

        $mime = $item['content_type'] ?: 'application/octet-stream';
        $filename = ($item['filename'] ?? '') !== '' ? $item['filename'] : (basename($filePath) ?: ('media.' . messagingExtForMime($mime)));
        return ['data' => $body, 'content_type' => $mime, 'filename' => $filename];
    }

    /**
     * Send a local file to a chat. Photos go through sendPhoto (Telegram
     * renders them inline, recompressing — exactly what an analyst expects
     * "attach a picture" to look like in the chat); everything else goes
     * through sendDocument (sent byte-for-byte, no recompression, no
     * dimension limits beyond the flat 50MB bot API cap).
     *
     * Uploaded as multipart/form-data through the shared httpMultipart().
     */
    public function sendMedia(string $to, string $filePath, string $mimeType, string $caption = '', string $publicUrl = '', string $filename = ''): string
    {
        $token = $this->channel['credentials']['bot_token'] ?? '';
        if ($token === '') {
            throw new Exception('Telegram channel is missing its bot token.');
        }
        if (!is_file($filePath)) {
            throw new Exception('Attachment file not found.');
        }

        $isPhoto = (strpos($mimeType, 'image/') === 0) && $mimeType !== 'image/svg+xml';
        $method  = $isPhoto ? 'sendPhoto' : 'sendDocument';
        $field   = $isPhoto ? 'photo' : 'document';

        $fields = [
            'chat_id' => $to,
            $field    => new CURLFile($filePath, $mimeType, $this->outboundFilename($filePath, $filename)),
        ];
        if ($caption !== '') {
            // Telegram's own caption limit; longer is silently rejected rather
            // than truncated, so trim here where the person can still see why.
            $fields['caption'] = mb_strimwidth($caption, 0, 1024, '');
        }

        [$code, $resp] = $this->httpMultipart(self::API_BASE . $token . '/' . $method, $fields);

        $json = json_decode($resp, true);
        if ($code < 200 || $code >= 300 || empty($json['ok'])) {
            throw new Exception('Telegram rejected the attachment: ' . ($json['description'] ?? ('HTTP ' . $code)));
        }
        return (string) ($json['result']['message_id'] ?? '');
    }

    /**
     * Tell Telegram where to deliver this bot's messages, and the secret it must
     * send back with each one (verifyWebhook() checks it).
     *
     * Added at merge time to replace a copy-paste `curl …/setWebhook` command in
     * the settings dialog. That command put the bot token in the admin's browser
     * and shell history, and asked an IT manager to run a terminal command. Here
     * the token never leaves the server.
     *
     * drop_pending_updates is deliberately NOT set: messages sent while the bot
     * was unregistered should still arrive once it is.
     *
     * @return string Telegram's own description, e.g. "Webhook was set"
     */
    public function setWebhook(string $url, string $secret): string
    {
        $token = $this->channel['credentials']['bot_token'] ?? '';
        if ($token === '') {
            throw new Exception('Telegram channel is missing its bot token.');
        }
        if (stripos($url, 'https://') !== 0) {
            throw new Exception('Telegram only delivers to an https:// address. Set this install\'s public URL (Settings → Messaging) to an https address first.');
        }
        [$code, $resp] = $this->httpRequest(self::API_BASE . $token . '/setWebhook', [
            'method'  => 'POST',
            'headers' => ['Content-Type: application/json'],
            'body'    => json_encode([
                'url'             => $url,
                'secret_token'    => $secret,
                'allowed_updates' => ['message', 'edited_message'],   // all parseInbound() reads
            ]),
        ]);
        $json = json_decode($resp, true);
        if ($code === 401 || $code === 404) {
            throw new Exception('Authentication failed — check the Bot token.');
        }
        if ($code < 200 || $code >= 300 || empty($json['ok'])) {
            throw new Exception('Telegram refused the webhook: ' . ($json['description'] ?? ('HTTP ' . $code)));
        }
        return (string) ($json['description'] ?? 'Webhook was set');
    }

    public function testConnection(): string
    {
        $token = $this->channel['credentials']['bot_token'] ?? '';
        if ($token === '') {
            throw new Exception('Missing Bot token.');
        }

        [$code, $resp] = $this->httpRequest(self::API_BASE . $token . '/getMe', ['method' => 'GET']);
        $json = json_decode($resp, true);

        if ($code === 401 || $code === 404) {
            throw new Exception('Authentication failed — check the Bot token.');
        }
        if ($code < 200 || $code >= 300 || empty($json['ok'])) {
            throw new Exception($json['description'] ?? ('Telegram returned HTTP ' . $code));
        }

        $username = $json['result']['username'] ?? '';
        return 'Connected to Telegram bot ' . ($username !== '' ? "@$username." : '.');
    }
}
