<?php
/**
 * TeamsProvider — Microsoft Teams one-to-one chats with the FreeITSM bot, via
 * the Bot Framework.
 *
 * Credentials JSON (messaging_channels.credentials, encrypted at rest):
 *   { "app_id": "<Azure Bot App ID>", "app_secret": "...", "tenant_id": "<AAD tenant>" }
 * The tenant is required for a single-tenant app registration: token requests
 * to the wrong tenant fail with AADSTS700016.
 *
 * Inbound: Bot Framework POSTs an Activity to the webhook with
 *   Authorization: Bearer <JWT>. verifyWebhook() checks the RS256 signature
 *   against Bot Framework's published keys, the issuer, and that the audience
 *   is this channel's app id, so nobody can post fake messages to the webhook.
 *
 * Outbound: POST {serviceUrl}v3/conversations/{conversationId}/activities with
 *   an Azure AD client-credentials token for the Bot Framework scope.
 *
 * Reply address: the conversation id is the customer's chat (stored as the
 * sender, like a Telegram chat id). The serviceUrl is region-specific and is
 * taken from the latest inbound activity; ingest.php keeps it in
 * messaging_channels.channel_ref.
 *
 * Limits: one-to-one chats only (group chats and channels are ignored, as
 * Telegram groups are); images only for analyst attachments, because Teams
 * needs a public https URL for the file (media.php); no read receipts.
 */

require_once __DIR__ . '/MessagingProvider.php';

class TeamsProvider extends MessagingProvider
{
    private const BOT_OPENID = 'https://login.botframework.com/v1/.well-known/openidconfiguration';
    private const BOT_ISSUER = 'https://api.botframework.com';
    private const DEFAULT_SERVICE_URL = 'https://smba.trafficmanager.net/teams/';
    private const JWKS_CACHE_SECONDS = 86400;

    /** Token cache for this request only — never written to disk. */
    private static $tokenCache = [];

    public function verifyWebhook(string $rawBody, array $headers, array $params, string $url): bool
    {
        $appId = (string)($this->channel['credentials']['app_id'] ?? '');
        $auth  = (string)($headers['authorization'] ?? '');
        if ($appId === '' || stripos($auth, 'Bearer ') !== 0) {
            return false;
        }
        $parts = explode('.', substr($auth, 7));
        if (count($parts) !== 3) {
            return false;
        }
        [$h64, $p64, $s64] = $parts;
        $header = json_decode(self::b64url($h64), true);
        $claims = json_decode(self::b64url($p64), true);
        if (!is_array($header) || !is_array($claims) || ($header['alg'] ?? '') !== 'RS256') {
            return false;
        }
        $pem = $this->signingKeyPem((string)($header['kid'] ?? ''));
        if ($pem === null || openssl_verify("$h64.$p64", self::b64url($s64), $pem, OPENSSL_ALGO_SHA256) !== 1) {
            return false;
        }
        $now = time();
        if (($claims['iss'] ?? '') !== self::BOT_ISSUER || ($claims['aud'] ?? '') !== $appId) {
            return false;
        }
        if (!isset($claims['exp']) || (int)$claims['exp'] < $now - 300) {
            return false;
        }
        if (isset($claims['nbf']) && (int)$claims['nbf'] > $now + 300) {
            return false;
        }
        return true;
    }

    public function parseInbound(string $rawBody, array $params): array
    {
        $a = json_decode($rawBody, true);
        if (!is_array($a) || ($a['type'] ?? '') !== 'message') {
            return [];
        }
        // One-to-one chats only. A group chat or channel has one conversation shared
        // by many people, so it would merge them into one requester.
        $convType = (string)($a['conversation']['conversationType'] ?? 'personal');
        if ($convType !== 'personal') {
            return [];
        }
        $convId     = (string)($a['conversation']['id'] ?? '');
        $activityId = (string)($a['id'] ?? '');
        $serviceUrl = (string)($a['serviceUrl'] ?? '');
        if ($convId === '' || $activityId === '') {
            return [];
        }

        // Adaptive card button presses arrive with empty text and their payload in value.
        $text = (string)($a['text'] ?? '');
        if (is_array($a['value'] ?? null) && isset($a['value']['text'])) {
            $text = (string)$a['value']['text'];
        }
        $text = trim(html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        $media = [];
        foreach (($a['attachments'] ?? []) as $att) {
            $type = (string)($att['contentType'] ?? '');
            $url  = (string)($att['contentUrl'] ?? '');
            if ($url !== '' && strpos($type, 'image/') === 0) {
                $media[] = ['url' => $url, 'content_type' => $type, 'filename' => (string)($att['name'] ?? '')];
            }
        }

        return [[
            'from'            => $convId,
            'to'              => $serviceUrl,   // ingest.php stores this as the channel's serviceUrl
            'body'            => $text,
            'profile_name'    => trim((string)($a['from']['name'] ?? '')),
            'provider_msg_id' => 'teams:' . $activityId,
            'media'           => $media,
            'timestamp'       => isset($a['timestamp']) ? (int)strtotime((string)$a['timestamp']) : null,
            'language_code'   => '',
        ]];
    }

    public function sendMessage(string $to, string $body): string
    {
        if ($to === '') {
            throw new Exception('No Teams conversation to send to.');
        }
        return $this->postActivity($to, ['type' => 'message', 'text' => $body]);
    }

    /**
     * Teams shows an image sent as an attachment with contentUrl. That URL must be
     * a public https address — messagingOutboundMediaUrl() provides one, which is
     * why images are the only kind this sends. Other files are refused with a
     * plain message, not sent as a broken attachment.
     */
    public function sendMedia(string $to, string $filePath, string $mimeType, string $caption = '', string $publicUrl = '', string $filename = ''): string
    {
        if (strpos($mimeType, 'image/') !== 0) {
            throw new Exception('Teams can only receive images sent from FreeITSM for now.');
        }
        if ($publicUrl === '' || strpos($publicUrl, 'https://') !== 0) {
            throw new Exception('Teams needs a public https address for the image, and this install does not have one configured.');
        }
        return $this->postActivity($to, [
            'type'        => 'message',
            'text'        => $caption,
            'attachments' => [[
                'contentType' => $mimeType,
                'contentUrl'  => $publicUrl,
                'name'        => $this->outboundFilename($filePath, $filename),
            ]],
        ]);
    }

    /** Inbound images: fetch with the bot's token — Teams does not serve them anonymously. */
    public function downloadMedia(array $item): array
    {
        $url = (string)($item['url'] ?? '');
        if ($url === '') {
            throw new Exception('Media item has no URL.');
        }
        [$code, $body] = $this->httpRequest($url, [
            'method'  => 'GET',
            'headers' => ['Authorization: Bearer ' . $this->accessToken()],
            'follow'  => true,
        ]);
        if ($code < 200 || $code >= 300 || $body === '') {
            throw new Exception('Teams media download failed (HTTP ' . $code . ').');
        }
        $mime = (string)($item['content_type'] ?: 'application/octet-stream');
        $name = (string)($item['filename'] ?? '') !== '' ? $item['filename'] : ('image.' . messagingExtForMime($mime));
        return ['data' => $body, 'content_type' => $mime, 'filename' => $name];
    }

    public function testConnection(): string
    {
        $this->accessToken(); // throws with a readable message if the credentials are wrong
        return 'Connected to Microsoft Teams bot app ' . $this->appId() . '.';
    }

    // ── internals ──────────────────────────────────────────────────────────────

    private function appId(): string
    {
        return (string)($this->channel['credentials']['app_id'] ?? '');
    }

    private function serviceUrl(): string
    {
        $stored = trim((string)($this->channel['channel_ref'] ?? ''));
        return $stored !== '' && strpos($stored, 'https://') === 0 ? $stored : self::DEFAULT_SERVICE_URL;
    }

    /** Azure AD client-credentials token for the Bot Framework scope. */
    private function accessToken(): string
    {
        $appId  = $this->appId();
        $secret = (string)($this->channel['credentials']['app_secret'] ?? '');
        $tenant = (string)($this->channel['credentials']['tenant_id'] ?? '');
        if ($appId === '' || $secret === '' || $tenant === '') {
            throw new Exception('Teams channel is missing its App ID, App secret or Tenant ID.');
        }
        $key = $appId . '|' . $tenant;
        if (isset(self::$tokenCache[$key])) {
            return self::$tokenCache[$key];
        }
        [$code, $resp] = $this->httpRequest('https://login.microsoftonline.com/' . rawurlencode($tenant) . '/oauth2/v2.0/token', [
            'method'  => 'POST',
            'headers' => ['Content-Type: application/x-www-form-urlencoded'],
            'body'    => http_build_query([
                'grant_type'    => 'client_credentials',
                'client_id'     => $appId,
                'client_secret' => $secret,
                'scope'         => 'https://api.botframework.com/.default',
            ]),
        ]);
        $json = json_decode($resp, true);
        if ($code < 200 || $code >= 300 || empty($json['access_token'])) {
            throw new Exception('Microsoft rejected the Teams credentials: ' . ($json['error_description'] ?? ('HTTP ' . $code)));
        }
        return self::$tokenCache[$key] = (string)$json['access_token'];
    }

    private function postActivity(string $conversationId, array $activity): string
    {
        $url = rtrim($this->serviceUrl(), '/') . '/v3/conversations/' . rawurlencode($conversationId) . '/activities';
        [$code, $resp] = $this->httpRequest($url, [
            'method'  => 'POST',
            'headers' => ['Content-Type: application/json', 'Authorization: Bearer ' . $this->accessToken()],
            'body'    => json_encode($activity),
        ]);
        $json = json_decode($resp, true);
        if ($code < 200 || $code >= 300) {
            throw new Exception('Teams rejected the message: ' . ($json['error']['message'] ?? ('HTTP ' . $code)));
        }
        return (string)($json['id'] ?? '');
    }

    /** The PEM public key for a Bot Framework signing key id, or null if unknown. */
    private function signingKeyPem(string $kid): ?string
    {
        if ($kid === '') {
            return null;
        }
        foreach ($this->botFrameworkKeys() as $k) {
            if (($k['kid'] ?? '') === $kid && ($k['kty'] ?? '') === 'RSA' && !empty($k['n']) && !empty($k['e'])) {
                return self::rsaPem(self::b64url((string)$k['n']), self::b64url((string)$k['e']));
            }
        }
        return null;
    }

    /**
     * Bot Framework's published signing keys, cached for a day. The key set is
     * public and rotates rarely, so a cache file holds nothing secret.
     */
    private function botFrameworkKeys(): array
    {
        $cache = sys_get_temp_dir() . '/freeitsm_botfw_jwks.json';
        if (is_file($cache) && (time() - filemtime($cache)) < self::JWKS_CACHE_SECONDS) {
            $cached = json_decode((string)file_get_contents($cache), true);
            if (is_array($cached)) {
                return $cached;
            }
        }
        [$c1, $r1] = $this->httpRequest(self::BOT_OPENID, ['method' => 'GET']);
        $cfg = json_decode($r1, true);
        $jwks = (string)($cfg['jwks_uri'] ?? '');
        // Only follow the key location Microsoft publishes, never one an attacker names.
        if ($c1 !== 200 || strpos($jwks, 'https://login.botframework.com/') !== 0) {
            return [];
        }
        [$c2, $r2] = $this->httpRequest($jwks, ['method' => 'GET']);
        $set = json_decode($r2, true);
        $keys = ($c2 === 200 && is_array($set['keys'] ?? null)) ? $set['keys'] : [];
        if ($keys) {
            @file_put_contents($cache, json_encode($keys));
        }
        return $keys;
    }

    /** Build an RSA public key PEM from a JWK modulus and exponent (raw bytes). */
    private static function rsaPem(string $n, string $e): string
    {
        $rsaKey = self::derSeq(self::derInt($n) . self::derInt($e));
        $algo   = self::derSeq("\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01" . "\x05\x00"); // rsaEncryption, NULL
        $bits   = "\x03" . self::derLen(strlen($rsaKey) + 1) . "\x00" . $rsaKey;
        return "-----BEGIN PUBLIC KEY-----\n"
            . chunk_split(base64_encode(self::derSeq($algo . $bits)), 64, "\n")
            . "-----END PUBLIC KEY-----\n";
    }

    private static function derInt(string $bytes): string
    {
        if ($bytes === '') {
            $bytes = "\0";
        }
        if (ord($bytes[0]) & 0x80) {
            $bytes = "\0" . $bytes;   // keep it positive
        }
        return "\x02" . self::derLen(strlen($bytes)) . $bytes;
    }

    private static function derSeq(string $content): string
    {
        return "\x30" . self::derLen(strlen($content)) . $content;
    }

    private static function derLen(int $len): string
    {
        if ($len < 128) {
            return chr($len);
        }
        $hex = ltrim(pack('N', $len), "\0");
        return chr(0x80 | strlen($hex)) . $hex;
    }

    /** base64url → raw bytes (JWT segments use it, without padding). */
    private static function b64url(string $s): string
    {
        return (string)base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4));
    }
}
