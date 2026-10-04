<?php
/**
 * TeamsProvider — Microsoft Teams one-to-one chats with the FreeITSM bot, via
 * the Bot Framework. Contributed by turbay-a in PR #166; see the Teams and
 * Mattermost developer guide for what changed at merge.
 *
 * Credentials JSON (messaging_channels.credentials, encrypted at rest):
 *   { "app_id": "<Azure Bot App ID>", "app_secret": "...", "tenant_id": "<AAD tenant>" }
 * The tenant is required for a single-tenant app registration: token requests
 * to the wrong tenant fail with AADSTS700016.
 *
 * ── INBOUND ──────────────────────────────────────────────────────────────────
 * Bot Framework POSTs an Activity with `Authorization: Bearer <JWT>`.
 * verifyWebhook() checks, in this order:
 *   1. the RS256 signature, against Bot Framework's published keys - with the
 *      vendored firebase/php-jwt, the same library SSO uses (includes/oidc.php),
 *      not a second hand-written RSA/DER decoder;
 *   2. issuer, audience (this channel's App ID) and expiry (5 min leeway);
 *   3. the token's `serviceurl` claim equals the activity's serviceUrl.
 *      TRAP: never trust a serviceUrl the token does not vouch for. Replies are
 *      POSTed to it WITH THE BOT'S ACCESS TOKEN, so an activity that could name
 *      its own serviceUrl could collect that token. Bot Framework requires
 *      this check; the branch first skipped it;
 *   4. the serviceUrl is a Microsoft Bot Framework host (belt and braces for 3);
 *   5. the activity comes from THIS channel's Microsoft 365 tenant, so a person
 *      in another organisation cannot open tickets by finding the bot.
 *
 * ── THE REPLY ADDRESS ────────────────────────────────────────────────────────
 * The serviceUrl is PER CONVERSATION (Teams routes by the user's region), so it
 * travels with the conversation, not the channel: parseInbound() sets
 *   to = "<serviceUrl>|<conversationId>"
 * ingest stores that as the row's to_recipients and messagingReplyAddress()
 * hands it back to sendMessage().
 *
 * TRAP: never store the serviceUrl on the channel. The branch first kept the
 *   latest one in messaging_channels.channel_ref, which answers one user through
 *   another user's region.
 *
 * Outbound: POST {serviceUrl}v3/conversations/{conversationId}/activities with
 * an Azure AD client-credentials token for the Bot Framework scope.
 *
 * Limits: one-to-one chats only (group chats and channels are ignored, as
 * Telegram groups are); images only for analyst attachments, because Teams
 * needs a public https URL for the file (media.php); no read receipts.
 */

require_once __DIR__ . '/MessagingProvider.php';
require_once __DIR__ . '/../vendor/firebase-jwt/src/JWT.php';
require_once __DIR__ . '/../vendor/firebase-jwt/src/JWK.php';
require_once __DIR__ . '/../vendor/firebase-jwt/src/Key.php';
require_once __DIR__ . '/../vendor/firebase-jwt/src/JWTExceptionWithPayloadInterface.php';
require_once __DIR__ . '/../vendor/firebase-jwt/src/BeforeValidException.php';
require_once __DIR__ . '/../vendor/firebase-jwt/src/ExpiredException.php';
require_once __DIR__ . '/../vendor/firebase-jwt/src/SignatureInvalidException.php';

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;

class TeamsProvider extends MessagingProvider
{
    private const BOT_OPENID = 'https://login.botframework.com/v1/.well-known/openidconfiguration';
    private const BOT_ISSUER = 'https://api.botframework.com';
    private const DEFAULT_SERVICE_URL = 'https://smba.trafficmanager.net/teams/';
    private const JWKS_CACHE_SECONDS = 86400;

    /**
     * Hosts Bot Framework answers on (public cloud and the US government clouds),
     * and where Teams serves the images a customer sends. A serviceUrl or an
     * attachment URL outside these never receives the bot's token.
     */
    private const SERVICE_HOSTS = ['smba.trafficmanager.net', '.botframework.com', '.teams.microsoft.com',
                                   '.botframework.us', '.botframework.azure.us', '.teams.microsoft.us'];
    private const MEDIA_HOSTS   = ['smba.trafficmanager.net', '.botframework.com', '.teams.microsoft.com',
                                   '.asm.skype.com', '.botframework.us', '.teams.microsoft.us'];

    /** Token cache for this request only — never written to disk. */
    private static $tokenCache = [];

    /** Test hook: a key set to verify against instead of Bot Framework's (tests/messaging-teams-mattermost.php). */
    public static $testKeys = null;

    public function verifyWebhook(string $rawBody, array $headers, array $params, string $url): bool
    {
        $appId = (string)($this->channel['credentials']['app_id'] ?? '');
        $auth  = (string)($headers['authorization'] ?? '');
        if ($appId === '' || stripos($auth, 'Bearer ') !== 0) {
            return false;
        }
        $keys = self::$testKeys ?? $this->botFrameworkKeys();
        if (!$keys) {
            return false;
        }
        $leeway = JWT::$leeway;
        JWT::$leeway = 300;
        try {
            $claims = (array)JWT::decode(trim(substr($auth, 7)), JWK::parseKeySet(['keys' => $keys], 'RS256'));
        } catch (Throwable $e) {
            return false;   // bad signature, unknown key, expired, not yet valid
        } finally {
            JWT::$leeway = $leeway;
        }
        if (($claims['iss'] ?? '') !== self::BOT_ISSUER || ($claims['aud'] ?? '') !== $appId) {
            return false;
        }

        $activity   = json_decode($rawBody, true);
        $serviceUrl = is_array($activity) ? (string)($activity['serviceUrl'] ?? '') : '';
        if ($serviceUrl === '' || !self::hostAllowed($serviceUrl, self::SERVICE_HOSTS)) {
            return false;
        }
        if (rtrim((string)($claims['serviceurl'] ?? ''), '/') !== rtrim($serviceUrl, '/')) {
            return false;
        }
        $tenant = strtolower((string)($this->channel['credentials']['tenant_id'] ?? ''));
        $from   = strtolower((string)($activity['conversation']['tenantId'] ?? ($activity['channelData']['tenant']['id'] ?? '')));
        return $tenant !== '' && $from === $tenant;
    }

    /** An https URL whose host is one of $hosts (exact, or a suffix starting with a dot). */
    private static function hostAllowed(string $url, array $hosts): bool
    {
        $p = parse_url($url);
        if (($p['scheme'] ?? '') !== 'https' || empty($p['host'])) {
            return false;
        }
        $host = strtolower($p['host']);
        foreach ($hosts as $h) {
            if ($h[0] === '.' ? substr($host, -strlen($h)) === $h : $host === $h) {
                return true;
            }
        }
        return false;
    }

    /** "<serviceUrl>|<conversationId>" -> [serviceUrl, conversationId]. A bare id uses the default region. */
    private static function splitAddress(string $to): array
    {
        $bar = strrpos($to, '|');
        if ($bar === false) {
            return [self::DEFAULT_SERVICE_URL, $to];
        }
        return [substr($to, 0, $bar), substr($to, $bar + 1)];
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
        if ($convId === '' || $activityId === '' || !self::hostAllowed($serviceUrl, self::SERVICE_HOSTS)) {
            return [];
        }

        // A press on a CSAT rating button (see sendRatingRequest()). Only ours are read.
        $csatValue = $a['value']['csat'] ?? null;
        if (is_array($csatValue) && isset($csatValue['r'], $csatValue['v'])) {
            $r = (int)$csatValue['r'];
            $v = (int)$csatValue['v'];
            if ($r > 0 && $v >= 1 && $v <= 5) {
                return [[
                    'from'            => $convId,
                    'to'              => $serviceUrl . '|' . $convId,
                    'body'            => '',
                    'profile_name'    => trim((string)($a['from']['name'] ?? '')),
                    'provider_msg_id' => 'teams:' . $activityId,
                    'media'           => [],
                    'timestamp'       => null,
                    'language_code'   => '',
                    'csat'            => ['response_id' => $r, 'rating' => $v, 'callback_id' => ''],
                ]];
            }
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
            'to'              => $serviceUrl . '|' . $convId,   // the reply address - see the file header
            'body'            => $text,
            'profile_name'    => trim((string)($a['from']['name'] ?? '')),
            'provider_msg_id' => 'teams:' . $activityId,
            'media'           => $media,
            'timestamp'       => isset($a['timestamp']) ? (int)strtotime((string)$a['timestamp']) : null,
            'language_code'   => '',
        ]];
    }

    /**
     * The rating question as an Adaptive Card with five buttons, 1 to 5. A press
     * comes back as a message whose value carries csat.r (response id) and csat.v (rating).
     */
    public function sendRatingRequest(string $to, string $text, int $responseId): string
    {
        $actions = [];
        for ($n = 1; $n <= 5; $n++) {
            $actions[] = [
                'type'  => 'Action.Submit',
                'title' => (string)$n,
                'data'  => ['csat' => ['r' => $responseId, 'v' => $n]],
            ];
        }
        return $this->postActivity($to, [
            'type'        => 'message',
            'attachments' => [[
                'contentType' => 'application/vnd.microsoft.card.adaptive',
                'content'     => [
                    '$schema' => 'http://adaptivecards.io/schemas/adaptive-card.json',
                    'type'    => 'AdaptiveCard',
                    'version' => '1.4',
                    'body'    => [['type' => 'TextBlock', 'text' => $text, 'wrap' => true]],
                    'actions' => $actions,
                ],
            ]],
        ]);
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
        // The bot's token goes with this request, so only to a Microsoft host,
        // and never on to wherever a redirect points (the SSO review's rule).
        if (!self::hostAllowed($url, self::MEDIA_HOSTS)) {
            throw new Exception('Teams image is not on a Microsoft host; not fetched.');
        }
        [$code, $body] = $this->httpRequest($url, [
            'method'  => 'GET',
            'headers' => ['Authorization: Bearer ' . $this->accessToken()],
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

    private function postActivity(string $to, array $activity): string
    {
        [$serviceUrl, $conversationId] = self::splitAddress($to);
        if ($conversationId === '' || !self::hostAllowed($serviceUrl, self::SERVICE_HOSTS)) {
            throw new Exception('No Teams conversation to send to.');
        }
        $url = rtrim($serviceUrl, '/') . '/v3/conversations/' . rawurlencode($conversationId) . '/activities';
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
        $cfg = json_decode((string)$r1, true);
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

}
