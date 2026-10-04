<?php
/**
 * MattermostProvider — a Mattermost support channel, via an Outgoing Webhook
 * (inbound) and the REST API v4 with a bot account (outbound).
 *
 * Credentials JSON (messaging_channels.credentials, encrypted at rest):
 *   { "server_url": "https://mattermost.example.com", "bot_token": "..." }
 * messaging_channels.channel_ref  = the support channel's id (26 chars)
 * messaging_channels.verify_token = the outgoing webhook token (encrypted)
 *
 * Inbound: an Outgoing Webhook on the support channel POSTs every new post there,
 *   with `token`, `channel_id`, `post_id`, `user_id`, `user_name`, `text`.
 *   verifyWebhook() compares `token` against the stored webhook token. Only
 *   posts from the configured channel are accepted.
 *
 * Outbound: the bot replies to the customer in a direct message. Mattermost
 *   needs a direct channel between the bot and the customer to post there, so
 *   it is created (or found) with /channels/direct. Files are uploaded first
 *   and attached to the post, so any file type works, not only images.
 *
 * Limits: one support channel per configuration. Replies come as a direct
 * message, not in the channel thread, so the customer sees them in their DMs.
 * Inbound files are not downloaded: the outgoing webhook does not carry them.
 */

require_once __DIR__ . '/MessagingProvider.php';

class MattermostProvider extends MessagingProvider
{
    /** Direct-channel ids are per request only; they change never, but cheap to re-fetch. */
    private static $dmCache = [];
    private static $botIdCache = [];

    public function verifyWebhook(string $rawBody, array $headers, array $params, string $url): bool
    {
        $expected = (string)($this->channel['verify_token'] ?? '');
        // A CSAT button press carries its own signature instead of the webhook token
        // (Mattermost sends the action context back as JSON). See sendRatingRequest().
        $json = json_decode($rawBody, true);
        if (is_array($json) && is_array($json['context'] ?? null) && isset($json['context']['csat_response_id'])) {
            return $expected !== '' && $this->csatSignature($json['context']) === (string)($json['context']['sig'] ?? '');
        }
        $presented = $this->webhookField($rawBody, $params, 'token');
        // No token configured means the webhook was never finished — refuse, as Telegram does.
        if ($expected === '' || $presented === '') {
            return false;
        }
        return hash_equals($expected, $presented);
    }

    public function parseInbound(string $rawBody, array $params): array
    {
        // A CSAT button press: the context we attached to the button, sent back by Mattermost.
        $json = json_decode($rawBody, true);
        if (is_array($json) && is_array($json['context'] ?? null) && isset($json['context']['csat_response_id'])) {
            $c = $json['context'];
            $userId = (string)($json['user_id'] ?? '');
            $postId = (string)($json['post_id'] ?? '');
            if ($userId === '' || $postId === '') {
                return [];
            }
            return [[
                'from'            => $userId,
                'to'              => (string)($json['channel_id'] ?? ''),
                'body'            => '',
                'profile_name'    => '',
                'provider_msg_id' => 'mmact:' . $postId,
                'media'           => [],
                'timestamp'       => null,
                'language_code'   => '',
                'csat'            => [
                    'response_id' => (int)$c['csat_response_id'],
                    'rating'      => (int)($c['csat_rating'] ?? 0),
                    'callback_id' => '',
                ],
            ]];
        }

        $channelId = $this->webhookField($rawBody, $params, 'channel_id');
        $postId    = $this->webhookField($rawBody, $params, 'post_id');
        $userId    = $this->webhookField($rawBody, $params, 'user_id');
        $userName  = $this->webhookField($rawBody, $params, 'user_name');
        $text      = $this->webhookField($rawBody, $params, 'text');

        // Only the support channel this configuration is for.
        $configured = (string)($this->channel['channel_ref'] ?? '');
        if ($configured === '' || $channelId !== $configured) {
            return [];
        }
        if ($postId === '' || $userId === '') {
            return [];
        }

        return [[
            'from'            => $userId,        // one requester per Mattermost user
            'to'              => $channelId,
            'body'            => trim($text),
            'profile_name'    => $userName,
            'provider_msg_id' => 'mm:' . $postId,
            'media'           => [],
            'timestamp'       => null,
            'language_code'   => '',
        ]];
    }

    public function sendMessage(string $to, string $body): string
    {
        return $this->postToCustomer($to, ['message' => $body]);
    }

    /**
     * The rating question as a direct message with five buttons. Each button calls
     * this install's webhook with its context. The context is signed with the
     * webhook token, so a button press cannot be forged.
     */
    public function sendRatingRequest(string $to, string $text, int $responseId): string
    {
        $url = $this->buttonCallbackUrl();
        $actions = [];
        for ($n = 1; $n <= 5; $n++) {
            $context = ['csat_response_id' => $responseId, 'csat_rating' => $n];
            $context['sig'] = $this->csatSignature($context);
            $actions[] = [
                'id'          => 'csat' . $responseId . 'x' . $n,
                'name'        => (string)$n,
                'integration' => ['url' => $url, 'context' => $context],
            ];
        }
        return $this->postToCustomer($to, [
            'message' => $text,
            'props'   => ['attachments' => [['text' => '', 'actions' => $actions]]],
        ]);
    }

    /** HMAC over the response id and rating, keyed by the webhook token. */
    private function csatSignature(array $context): string
    {
        $key = (string)($this->channel['verify_token'] ?? '');
        return hash_hmac('sha256', ((int)($context['csat_response_id'] ?? 0)) . ':' . ((int)($context['csat_rating'] ?? 0)), $key);
    }

    /** This channel's webhook URL, the one Mattermost calls back for button presses. */
    private function buttonCallbackUrl(): string
    {
        require_once __DIR__ . '/../../includes/functions.php';
        $conn = connectToDatabase();
        return messagingWebhookUrl($conn, (int)$this->channel['id']);
    }

    /** Upload the file, then post it to the customer with the file attached. */
    public function sendMedia(string $to, string $filePath, string $mimeType, string $caption = '', string $publicUrl = '', string $filename = ''): string
    {
        if (!is_file($filePath)) {
            throw new Exception('Attachment file not found.');
        }
        $dm = $this->directChannelFor($to);
        $name = $this->outboundFilename($filePath, $filename);

        [$code, $resp] = $this->httpMultipart(
            $this->apiUrl('files') . '?channel_id=' . rawurlencode($dm),
            ['files' => new CURLFile($filePath, $mimeType, $name)],
            [$this->authHeader()]
        );
        $json = json_decode($resp, true);
        $fileId = (string)($json['file_infos'][0]['id'] ?? '');
        if ($code < 200 || $code >= 300 || $fileId === '') {
            throw new Exception('Mattermost rejected the file: ' . ($json['message'] ?? ('HTTP ' . $code)));
        }
        return $this->postToCustomer($to, ['message' => $caption, 'file_ids' => [$fileId]], $dm);
    }

    public function testConnection(): string
    {
        $me = $this->api('GET', 'users/me');
        $name = (string)($me['username'] ?? '');
        if ($name === '') {
            throw new Exception('Mattermost did not return a bot user.');
        }
        return 'Connected to Mattermost as @' . $name . '.';
    }

    // ── internals ──────────────────────────────────────────────────────────────

    /** A field from the webhook body: form-encoded (Mattermost's default) or JSON. */
    private function webhookField(string $rawBody, array $params, string $key): string
    {
        if (array_key_exists($key, $params)) {
            return trim((string)$params[$key]);
        }
        $json = json_decode($rawBody, true);
        return is_array($json) && isset($json[$key]) ? trim((string)$json[$key]) : '';
    }

    private function serverUrl(): string
    {
        $url = rtrim(trim((string)($this->channel['credentials']['server_url'] ?? '')), '/');
        if ($url === '' || strpos($url, 'https://') !== 0 && strpos($url, 'http://') !== 0) {
            throw new Exception('Mattermost channel is missing its server address (https://…).');
        }
        return $url;
    }

    private function apiUrl(string $path): string
    {
        return $this->serverUrl() . '/api/v4/' . ltrim($path, '/');
    }

    private function authHeader(): string
    {
        $token = (string)($this->channel['credentials']['bot_token'] ?? '');
        if ($token === '') {
            throw new Exception('Mattermost channel is missing its bot access token.');
        }
        return 'Authorization: Bearer ' . $token;
    }

    /** A JSON REST call. Throws with Mattermost's own message on failure. */
    private function api(string $method, string $path, $body = null): array
    {
        $headers = [$this->authHeader()];
        $opts = ['method' => $method, 'headers' => $headers];
        if ($body !== null) {
            $headers[] = 'Content-Type: application/json';
            $opts['headers'] = $headers;
            $opts['body'] = json_encode($body);
        }
        [$code, $resp] = $this->httpRequest($this->apiUrl($path), $opts);
        $json = json_decode($resp, true);
        if ($code < 200 || $code >= 300) {
            throw new Exception('Mattermost rejected the request: ' . ($json['message'] ?? ('HTTP ' . $code)));
        }
        return is_array($json) ? $json : [];
    }

    private function botId(): string
    {
        $key = $this->serverUrl();
        if (!isset(self::$botIdCache[$key])) {
            self::$botIdCache[$key] = (string)($this->api('GET', 'users/me')['id'] ?? '');
        }
        return self::$botIdCache[$key];
    }

    /** The bot↔customer direct channel, created if it does not exist yet. */
    private function directChannelFor(string $userId): string
    {
        $key = $this->serverUrl() . '|' . $userId;
        if (!isset(self::$dmCache[$key])) {
            $dm = $this->api('POST', 'channels/direct', [$this->botId(), $userId]);
            self::$dmCache[$key] = (string)($dm['id'] ?? '');
        }
        if (self::$dmCache[$key] === '') {
            throw new Exception('Mattermost did not open a direct channel with this customer.');
        }
        return self::$dmCache[$key];
    }

    private function postToCustomer(string $userId, array $fields, ?string $dm = null): string
    {
        if ($userId === '') {
            throw new Exception('No Mattermost user to send to.');
        }
        $channelId = $dm ?? $this->directChannelFor($userId);
        $post = $this->api('POST', 'posts', ['channel_id' => $channelId] + $fields);
        return (string)($post['id'] ?? '');
    }
}
