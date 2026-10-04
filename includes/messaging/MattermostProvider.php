<?php
/**
 * MattermostProvider — a Mattermost support channel, via an Outgoing Webhook
 * (inbound) and the REST API v4 with a bot account (outbound). Contributed by
 * turbay-a in PR #166; see the Teams and Mattermost developer guide for what
 * changed at merge.
 *
 * Credentials JSON (messaging_channels.credentials, encrypted at rest):
 *   { "server_url": "https://mattermost.example.com", "bot_token": "..." }
 * messaging_channels.channel_ref  = the support channel's id (26 chars)
 * messaging_channels.verify_token = the outgoing webhook token (encrypted)
 *
 * ── THE SHAPE: SLACK'S ───────────────────────────────────────────────────────
 * A customer posts in the support channel; the Outgoing Webhook forwards it.
 * FreeITSM answers IN THE THREAD of that post, exactly as SlackProvider does,
 * so the customer's next reply - also in the channel - comes back through the
 * same webhook.
 *
 * TRAP: never answer a Mattermost customer by direct message.
 *   Outgoing webhooks only fire for posts in public channels. A DM reply looks
 *   friendlier, but the customer answers the DM, and that answer never reaches
 *   FreeITSM - the conversation silently stops. (The branch first did this.)
 *
 * Reply address (stored as to_recipients, see messagingReplyAddress()):
 *   "<channelId>:<postId>" - the post to thread under. The webhook does not say
 *   whether a post is itself a reply, so sendMessage() asks the API for the
 *   post's root before threading (Mattermost refuses a root_id that is a reply).
 *
 * Limits: one support channel per configuration; replies are visible to
 * everyone in that channel (as Slack's are); inbound files are not downloaded,
 * because the outgoing webhook does not carry them.
 */

require_once __DIR__ . '/MessagingProvider.php';

class MattermostProvider extends MessagingProvider
{
    /** Per request only. */
    private static $botIdCache = [];
    private static $rootCache  = [];

    public function verifyWebhook(string $rawBody, array $headers, array $params, string $url): bool
    {
        $expected = (string)($this->channel['verify_token'] ?? '');
        if ($expected === '') {
            return false;   // the webhook was never finished - refuse, as Telegram does
        }
        // A CSAT button press carries its own signature instead of the webhook
        // token (Mattermost sends the action's context back). See sendRatingRequest().
        $json = json_decode($rawBody, true);
        if (is_array($json) && is_array($json['context'] ?? null) && isset($json['context']['csat_response_id'])) {
            // TRAP: compare secrets with hash_equals(), never === (timing).
            return hash_equals($this->csatSignature($json['context']), (string)($json['context']['sig'] ?? ''));
        }
        $presented = $this->webhookField($rawBody, $params, 'token');
        return $presented !== '' && hash_equals($expected, $presented);
    }

    public function parseInbound(string $rawBody, array $params): array
    {
        // A CSAT button press: the context we attached to the button, sent back by Mattermost.
        $json = json_decode($rawBody, true);
        if (is_array($json) && is_array($json['context'] ?? null) && isset($json['context']['csat_response_id'])) {
            $c = $json['context'];
            $userId = (string)($json['user_id'] ?? '');
            $postId = (string)($json['post_id'] ?? '');
            $chanId = (string)($json['channel_id'] ?? '');
            if ($userId === '' || $postId === '') {
                return [];
            }
            return [[
                'from'            => $userId,
                'to'              => $chanId . ':' . $postId,   // thank them in the same thread
                'body'            => '',
                'profile_name'    => '',
                'provider_msg_id' => 'mmact:' . $postId . ':' . $userId,
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
        if ($configured === '' || $channelId !== $configured || $postId === '' || $userId === '') {
            return [];
        }
        // TRAP: drop the bot's own posts. Our replies are posted in the same
        //   channel, so the webhook hands them straight back; without this every
        //   analyst reply would arrive as a new message from "the customer".
        if ($userId === $this->botIdOrEmpty()) {
            return [];
        }

        return [[
            'from'            => $userId,                       // one requester per Mattermost user
            'to'              => $channelId . ':' . $postId,    // the reply address: thread under this post
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
        return $this->postInThread($to, ['message' => $body]);
    }

    /**
     * The rating question with five buttons, in the customer's thread. Each
     * button calls this install's webhook with its context, signed with the
     * webhook token, so a press cannot be forged.
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
        return $this->postInThread($to, [
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
        require_once __DIR__ . '/../functions.php';
        return messagingWebhookUrl(connectToDatabase(), (int)$this->channel['id']);
    }

    /** Upload the file to the support channel, then post it in the customer's thread. */
    public function sendMedia(string $to, string $filePath, string $mimeType, string $caption = '', string $publicUrl = '', string $filename = ''): string
    {
        if (!is_file($filePath)) {
            throw new Exception('Attachment file not found.');
        }
        [$channelId] = $this->splitAddress($to);
        [$code, $resp] = $this->httpMultipart(
            $this->apiUrl('files') . '?channel_id=' . rawurlencode($channelId),
            ['files' => new CURLFile($filePath, $mimeType, $this->outboundFilename($filePath, $filename))],
            [$this->authHeader()]
        );
        $json = json_decode((string)$resp, true);
        $fileId = (string)($json['file_infos'][0]['id'] ?? '');
        if ($code < 200 || $code >= 300 || $fileId === '') {
            throw new Exception('Mattermost rejected the file: ' . ($json['message'] ?? ('HTTP ' . $code)));
        }
        return $this->postInThread($to, ['message' => $caption, 'file_ids' => [$fileId]]);
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

    /** "<channelId>:<postId>" -> [channelId, postId]. */
    private function splitAddress(string $to): array
    {
        $parts = explode(':', $to, 2);
        $channelId = $parts[0] ?? '';
        $postId    = $parts[1] ?? '';
        if (!preg_match('/^[a-z0-9]{26}$/', $channelId) || !preg_match('/^[a-z0-9]{26}$/', $postId)) {
            throw new Exception('No Mattermost thread to reply into.');
        }
        return [$channelId, $postId];
    }

    /** Post into the thread the address names, under that thread's root. */
    private function postInThread(string $to, array $fields): string
    {
        [$channelId, $postId] = $this->splitAddress($to);
        $post = $this->api('POST', 'posts', ['channel_id' => $channelId, 'root_id' => $this->rootOf($postId)] + $fields);
        return (string)($post['id'] ?? '');
    }

    /**
     * The root of the thread a post is in. TRAP: Mattermost refuses a root_id that
     * is itself a reply, and the outgoing webhook never says which a post is - so
     * ask, rather than threading under whatever post came in last.
     */
    private function rootOf(string $postId): string
    {
        if (!isset(self::$rootCache[$postId])) {
            $post = $this->api('GET', 'posts/' . rawurlencode($postId));
            $root = (string)($post['root_id'] ?? '');
            self::$rootCache[$postId] = $root !== '' ? $root : $postId;
        }
        return self::$rootCache[$postId];
    }

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
        $json = json_decode((string)$resp, true);
        if ($code < 200 || $code >= 300) {
            throw new Exception('Mattermost rejected the request: ' . ($json['message'] ?? ('HTTP ' . $code)));
        }
        return is_array($json) ? $json : [];
    }

    /**
     * Who a Mattermost user is: ['name' => …, 'email' => …], either may be ''.
     * Used to file their tickets under the person FreeITSM already knows -
     * see messagingResolveRequesterByEmail() in ingest.php. Never throws.
     *
     * TRAP: the email is returned ONLY when Mattermost says it is verified.
     *   A server can allow sign-up without proving the address, and an
     *   unverified email is just text anyone typed: someone registering as
     *   ceo@yourcompany.com would have their chats filed under the real CEO.
     *   An empty email (the server hides addresses from this bot) is normal -
     *   the person simply gets a named contact instead.
     */
    public function lookupUser(string $userId): array
    {
        try {
            $u = $this->api('GET', 'users/' . rawurlencode($userId));
            $name = trim(trim((string)($u['first_name'] ?? '')) . ' ' . trim((string)($u['last_name'] ?? '')));
            if ($name === '') {
                $name = (string)($u['username'] ?? '');
            }
            $email = !empty($u['email_verified']) ? trim((string)($u['email'] ?? '')) : '';
            return ['name' => $name, 'email' => $email];
        } catch (Throwable $e) {
            error_log('Mattermost user lookup failed for ' . $userId . ': ' . $e->getMessage());
            return ['name' => '', 'email' => ''];
        }
    }

    /** The bot's own user id, or '' if Mattermost cannot be asked right now (then nothing is dropped). */
    private function botIdOrEmpty(): string
    {
        $key = (string)($this->channel['id'] ?? '');
        if (!array_key_exists($key, self::$botIdCache)) {
            try {
                self::$botIdCache[$key] = (string)($this->api('GET', 'users/me')['id'] ?? '');
            } catch (Throwable $e) {
                self::$botIdCache[$key] = '';
            }
        }
        return self::$botIdCache[$key];
    }

    /** Test hook: set the bot id without asking a server. */
    public static function setBotIdForTest(int $channelId, string $botId): void
    {
        self::$botIdCache[(string)$channelId] = $botId;
    }
}
