<?php
/* 🔴 NEVER OVER THE WEB. See tests/README.md. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * Microsoft Teams and Mattermost channels, and ratings asked in a chat (PR #166).
 *
 * Mostly the protections added at merge - each is a TRAP comment in the code:
 *   Teams       the token's serviceurl claim must match the activity; the
 *               serviceUrl must be Microsoft's; only our own tenant; a bad key,
 *               audience or expiry is refused; the reply address carries the
 *               conversation's own serviceUrl.
 *   Mattermost  the webhook token and a CSAT button's signature (hash_equals),
 *               posts from other channels and from the bot itself are dropped,
 *               the reply address is channel:post for threading.
 *   Ratings     a digit is only a rating while a request IN THIS CHAT is
 *               waiting, within 7 days, before the customer writes anything else.
 *
 * The Teams checks sign their own tokens with a key made here (TeamsProvider::
 * $testKeys) - nothing is sent to Microsoft or Mattermost. The rating checks
 * write inside ONE transaction that is always rolled back; rows are ZZMSG-.
 *
 * Run: php tests/messaging-teams-mattermost.php
 */

$root = dirname(__DIR__);
require_once "$root/config.php";
require_once "$root/includes/functions.php";
require_once "$root/includes/messaging/messaging.php";
require_once "$root/includes/csat.php";
require_once "$root/includes/messaging/ingest.php";

use Firebase\JWT\JWT;

$pass = 0; $fail = 0;
function ok(string $label, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; printf("  PASS %-70s\n", $label); }
    else       { $fail++; printf("  FAIL %-70s %s\n", $label, $detail); }
}
$b64 = fn(string $bin) => rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');

echo "\nTeams, Mattermost and ratings in a chat\n" . str_repeat('=', 72) . "\n";

// ---------------------------------------------------------------- Teams
echo "\nTeams - who may post to the webhook\n";
// PHP on Windows cannot make a key without being told where openssl.cnf is.
$sslCfg = ['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA];
foreach ([getenv('OPENSSL_CONF') ?: '', dirname(PHP_BINARY) . '/extras/ssl/openssl.cnf'] as $cnf) {
    if ($cnf !== '' && is_file($cnf)) { $sslCfg['config'] = $cnf; break; }
}
$key   = openssl_pkey_new($sslCfg);
$other = openssl_pkey_new($sslCfg);
if (!$key || !$other) { echo "  SKIP  this PHP cannot make an RSA key (no openssl.cnf) - the Teams token checks need one\n"; goto mattermost; }
$det  = openssl_pkey_get_details($key);
openssl_pkey_export($key, $privPem, null, $sslCfg);
openssl_pkey_export($other, $otherPem, null, $sslCfg);
TeamsProvider::$testKeys = [['kty' => 'RSA', 'kid' => 'zzk1', 'use' => 'sig', 'n' => $b64($det['rsa']['n']), 'e' => $b64($det['rsa']['e'])]];

$appId  = '11111111-2222-3333-4444-555555555555';
$tenant = 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee';
$svc    = 'https://smba.trafficmanager.net/emea/';
$teams  = new TeamsProvider(['id' => 9001, 'channel_type' => 'teams', 'credentials' => ['app_id' => $appId, 'tenant_id' => $tenant, 'app_secret' => 'x']]);
$activity = fn(?string $serviceUrl = null, ?string $tid = null) => json_encode([
    'type' => 'message', 'id' => 'act1', 'serviceUrl' => $serviceUrl ?? $svc, 'text' => 'Printer is down',
    'from' => ['name' => 'Jo'], 'conversation' => ['id' => 'a:zzconv1', 'conversationType' => 'personal', 'tenantId' => $tid ?? $tenant],
]);
$token = function (array $over = [], ?string $pem = null) use ($privPem, $appId, $svc): string {
    $now = time();
    return JWT::encode(array_merge(['iss' => 'https://api.botframework.com', 'aud' => $appId, 'serviceurl' => $svc,
                                    'iat' => $now, 'nbf' => $now - 5, 'exp' => $now + 600], $over), $pem ?? $privPem, 'RS256', 'zzk1');
};
$verify = fn(string $jwt, string $body) => $teams->verifyWebhook($body, ['authorization' => 'Bearer ' . $jwt], [], '');

ok('a correctly signed activity from our tenant is accepted', $verify($token(), $activity()));
ok('no Authorization header is refused', !$teams->verifyWebhook($activity(), [], [], ''));
ok('a token signed with somebody else\'s key is refused', !$verify($token([], $otherPem), $activity()));
ok('another bot\'s audience is refused', !$verify($token(['aud' => 'not-us']), $activity()));
ok('another issuer is refused', !$verify($token(['iss' => 'https://evil.example']), $activity()));
ok('an expired token is refused', !$verify($token(['exp' => time() - 3600, 'nbf' => time() - 7200]), $activity()));
ok('TRAP: a serviceUrl in the body that the token did not vouch for is refused', !$verify($token(), $activity('https://smba.trafficmanager.net/amer/')));
ok('TRAP: a non-Microsoft serviceUrl is refused even when the token names it', !$verify($token(['serviceurl' => 'https://evil.example/']), $activity('https://evil.example/')));
ok('TRAP: a person from another Microsoft 365 tenant is refused', !$verify($token(), $activity(null, 'ffffffff-0000-0000-0000-000000000000')));

echo "\nTeams - what an activity becomes\n";
$m = $teams->parseInbound($activity(), []);
ok('a personal chat becomes one message from the conversation', count($m) === 1 && $m[0]['from'] === 'a:zzconv1' && $m[0]['body'] === 'Printer is down');
ok('TRAP: its reply address carries THIS conversation\'s serviceUrl', ($m[0]['to'] ?? '') === $svc . '|a:zzconv1', $m[0]['to'] ?? '');
ok('the address round-trips through messagingReplyAddress()', messagingReplyAddress('teams', ['from_address' => 'a:zzconv1', 'to_recipients' => $m[0]['to']]) === $svc . '|a:zzconv1');
$group = json_decode($activity(), true); $group['conversation']['conversationType'] = 'groupChat';
ok('a group chat is ignored', $teams->parseInbound(json_encode($group), []) === []);
$press = json_decode($activity(), true); $press['text'] = ''; $press['value'] = ['csat' => ['r' => 42, 'v' => 4]];
$p = $teams->parseInbound(json_encode($press), []);
ok('a rating button press is read as a rating', ($p[0]['csat']['response_id'] ?? 0) === 42 && ($p[0]['csat']['rating'] ?? 0) === 4);
TeamsProvider::$testKeys = null;

// ---------------------------------------------------------------- Telegram
echo "\nTelegram - rating buttons\n";
$tg = new TelegramProvider(['id' => 0, 'channel_ref' => '', 'credentials' => ['bot_token' => 'x']]);
$press = ['update_id' => 1, 'callback_query' => ['id' => 'cb1', 'data' => 'csat:42:5',
          'from' => ['id' => 7, 'language_code' => 'en'], 'message' => ['message_id' => 99, 'text' => 'How would you rate us?', 'chat' => ['id' => 7]]]];
$p = $tg->parseInbound(json_encode($press), []);
ok('a rating button press is read as a rating', ($p[0]['csat']['response_id'] ?? 0) === 42 && ($p[0]['csat']['rating'] ?? 0) === 5);
ok('...carrying the question it answered, so the buttons can be replaced', ($p[0]['csat']['message_id'] ?? '') === '99' && ($p[0]['csat']['message_text'] ?? '') === 'How would you rate us?');
ok('TRAP: the webhook asks Telegram for button presses, or they never arrive',
   in_array('callback_query', TelegramProvider::TELEGRAM_UPDATE_TYPES, true));

// ---------------------------------------------------------------- Mattermost
mattermost:
echo "\nMattermost\n";
$chan = 'zzsupportchannel0000000000';
$bot  = 'zzbotuserid000000000000000';
$mmRow = ['id' => 9002, 'channel_type' => 'mattermost', 'channel_ref' => $chan, 'verify_token' => 'zzwebhooktoken123',
          'credentials' => ['server_url' => 'https://mm.example.invalid', 'bot_token' => 'x']];
$mm = new MattermostProvider($mmRow);
MattermostProvider::setBotIdForTest(9002, $bot);
$post = fn(string $user, ?string $channel = null) => ['token' => 'zzwebhooktoken123', 'channel_id' => $channel ?? $chan,
    'post_id' => 'zzpostid000000000000000000', 'user_id' => $user, 'user_name' => 'jo', 'text' => 'VPN broken'];
ok('the right webhook token is accepted', $mm->verifyWebhook('', [], $post('zzcustomer0000000000000000'), ''));
ok('a wrong token is refused', !$mm->verifyWebhook('', [], ['token' => 'nope'] + $post('zzcustomer0000000000000000'), ''));
ok('no token configured refuses everything', !(new MattermostProvider(['verify_token' => ''] + $mmRow))->verifyWebhook('', [], $post('x'), ''));
$m = $mm->parseInbound('', $post('zzcustomer0000000000000000'));
ok('a post in the support channel becomes a message', count($m) === 1 && $m[0]['body'] === 'VPN broken');
ok('its reply address is channel:post, to thread under', ($m[0]['to'] ?? '') === $chan . ':zzpostid000000000000000000');
ok('a post in another channel is ignored', $mm->parseInbound('', $post('zzcustomer0000000000000000', 'zzotherchannel000000000000')) === []);
ok('TRAP: the bot\'s own post (our reply, handed back) is ignored', $mm->parseInbound('', $post($bot)) === []);
$sig = hash_hmac('sha256', '42:5', 'zzwebhooktoken123');
$good = json_encode(['user_id' => 'zzcustomer0000000000000000', 'post_id' => 'zzratingpost00000000000000', 'channel_id' => $chan,
                     'context' => ['csat_response_id' => 42, 'csat_rating' => 5, 'sig' => $sig]]);
$forged = json_encode(['user_id' => 'u', 'post_id' => 'p', 'context' => ['csat_response_id' => 42, 'csat_rating' => 1, 'sig' => $sig]]);
ok('a correctly signed rating press is accepted', $mm->verifyWebhook($good, [], [], ''));
ok('a press whose rating was changed is refused', !$mm->verifyWebhook($forged, [], [], ''));

echo "\nReply addresses\n";
$row = ['from_address' => 'FROM', 'to_recipients' => 'TO'];
ok('phone-like channels answer the sender', messagingReplyAddress('whatsapp', $row) === 'FROM' && messagingReplyAddress('telegram', $row) === 'FROM');
ok('threaded channels answer into the conversation', messagingReplyAddress('slack', $row) === 'TO' && messagingReplyAddress('teams', $row) === 'TO' && messagingReplyAddress('mattermost', $row) === 'TO');

// ---------------------------------------------------------------- ratings
echo "\nRatings asked in a chat\n";
$conn = connectToDatabase();
$ticket  = (int)$conn->query("SELECT id FROM tickets WHERE deleted_datetime IS NULL ORDER BY id DESC LIMIT 1")->fetchColumn();
$channel = (int)$conn->query("SELECT id FROM messaging_channels ORDER BY id LIMIT 1")->fetchColumn();
if (!$ticket || !$channel) {
    echo "  SKIP  needs a ticket and a messaging channel\n";
} else {
    $conn->beginTransaction();
    try {
        $ask = function (string $from, string $sentAgo = '0 MINUTE') use ($conn, $ticket, $channel): int {
            $conn->prepare("INSERT INTO ticket_csat_responses (ticket_id, token, sent_datetime, created_at, channel_id, channel_from)
                            VALUES (?, ?, UTC_TIMESTAMP() - INTERVAL $sentAgo, UTC_TIMESTAMP(), ?, ?)")
                 ->execute([$ticket, 'zzmsg' . bin2hex(random_bytes(8)), $channel, $from]);
            return (int)$conn->lastInsertId();
        };
        $r1 = $ask('ZZMSG-chat-1', '1 MINUTE');
        ok('a digit just after the request answers it', csatPendingRequestForChat($conn, $channel, 'ZZMSG-chat-1') === $r1);
        ok('another chat\'s digit does not', csatPendingRequestForChat($conn, $channel, 'ZZMSG-chat-2') === null);
        ok('a press for this chat\'s request belongs to it', csatResponseBelongsToChat($conn, $r1, $channel, 'ZZMSG-chat-1'));
        ok('a press replayed from another chat does not', !csatResponseBelongsToChat($conn, $r1, $channel, 'ZZMSG-chat-2'));
        ok('the rating is recorded once...', csatRecordRating($conn, $r1, 4));
        ok('...and a second press changes nothing', !csatRecordRating($conn, $r1, 1) && (int)$conn->query("SELECT rating FROM ticket_csat_responses WHERE id = $r1")->fetchColumn() === 4);
        ok('...and a later digit is a message again', csatPendingRequestForChat($conn, $channel, 'ZZMSG-chat-1') === null);

        $r2 = $ask('ZZMSG-chat-3', '10 MINUTE');
        $conn->prepare("INSERT INTO emails (ticket_id, channel, channel_id, from_address, direction, received_datetime, subject)
                        VALUES (?, 'telegram', ?, 'ZZMSG-chat-3', 'Inbound', UTC_TIMESTAMP() - INTERVAL 2 MINUTE, 'ZZMSG')")
             ->execute([$ticket, $channel]);
        ok('TRAP: once the customer has written since, "3" is a message, not a rating', csatPendingRequestForChat($conn, $channel, 'ZZMSG-chat-3') === null);
        $ask('ZZMSG-chat-4', '8 DAY');
        ok('TRAP: a request more than a week old no longer catches digits', csatPendingRequestForChat($conn, $channel, 'ZZMSG-chat-4') === null);
        $conn->prepare("INSERT INTO ticket_csat_responses (ticket_id, token, sent_datetime, created_at) VALUES (?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())")
             ->execute([$ticket, 'zzmsg' . bin2hex(random_bytes(8))]);
        ok('an emailed survey is never answered from a chat', csatPendingRequestForChat($conn, $channel, '') === null);

        // A Telegram press through the real ingest path. The test transport
        // stands in for Telegram and records what would have been sent.
        $conn->prepare("INSERT INTO messaging_channels (name, channel_type, provider, phone_number, credentials, is_active) VALUES ('ZZMSG TG', 'telegram', 'telegram', '', NULL, 1)")->execute();
        $tgCh = loadMessagingChannel($conn, (int)$conn->lastInsertId());
        $tgCh['credentials'] = ['bot_token' => 'zz-test'];
        $sent = [];
        MessagingProvider::$testTransport = function (string $url, array $opts) use (&$sent): array {
            $sent[] = [substr($url, strrpos($url, '/') + 1), json_decode($opts['body'] ?? '', true)];
            return [200, '{"ok":true,"result":{}}'];
        };
        $conn->prepare("INSERT INTO ticket_csat_responses (ticket_id, token, sent_datetime, created_at, channel_id, channel_from)
                        VALUES (?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP(), ?, '990000777')")
             ->execute([$ticket, 'zzmsg' . bin2hex(random_bytes(8)), $tgCh['id']]);
        $rTg = (int)$conn->lastInsertId();
        $press = fn(int $n, string $cb) => ['from' => '990000777', 'to' => '', 'body' => '', 'profile_name' => '', 'provider_msg_id' => 'tgcb:' . $cb,
            'media' => [], 'timestamp' => null, 'language_code' => 'en',
            'csat' => ['response_id' => $rTg, 'rating' => $n, 'callback_id' => $cb, 'message_id' => '55', 'message_text' => 'How would you rate us?']];
        ingestInboundMessage($conn, $tgCh, $press(4, 'zzcb1'));
        $edit = array_values(array_filter($sent, fn($s) => $s[0] === 'editMessageText'))[0][1] ?? [];
        ok('a Telegram press is recorded', csatStoredRating($conn, $rTg) === 4);
        ok('...the buttons are replaced by the answer, under the question',
           ($edit['message_id'] ?? 0) === 55 && str_starts_with($edit['text'] ?? '', "How would you rate us?\n\n") && str_contains($edit['text'] ?? '', '4') && !isset($edit['reply_markup']),
           json_encode($edit));
        $sent = [];
        ingestInboundMessage($conn, $tgCh, $press(1, 'zzcb2'));
        $edit = array_values(array_filter($sent, fn($s) => $s[0] === 'editMessageText'))[0][1] ?? [];
        ok('...and a second press on an old copy shows the rating that stands', csatStoredRating($conn, $rTg) === 4 && str_contains($edit['text'] ?? '', '4') && !str_contains($edit['text'] ?? '', ' 1 '), json_encode($edit));
        MessagingProvider::$testTransport = null;

        // ---- who raised it: Teams and Mattermost people matched by email (after PR #166)
        $conn->prepare("INSERT INTO users (email, display_name, created_at) VALUES ('zzmsg.known@example.test', 'ZZMSG Known Person', UTC_TIMESTAMP())")->execute();
        $knownId = (int)$conn->lastInsertId();
        $mmUser = ['email' => 'zzmsg.known@example.test', 'email_verified' => true, 'first_name' => 'Known', 'last_name' => 'Person'];
        MessagingProvider::$testTransport = function (string $url, array $opts) use (&$mmUser): array {
            if (str_contains($url, '/api/v4/users/')) return [200, json_encode($mmUser)];
            if (str_contains($url, 'login.microsoftonline.com')) return [200, '{"access_token":"zz"}'];
            if (str_contains($url, '/members/')) return [200, json_encode(['id' => '29:zz', 'name' => 'Known Person', 'email' => 'zzmsg.known@example.test'])];
            return [200, '{}'];
        };
        $mk = function (string $type, array $creds) use ($conn): array {
            $conn->prepare("INSERT INTO messaging_channels (name, channel_type, provider, phone_number, credentials, is_active) VALUES (?, ?, ?, '', NULL, 1)")
                 ->execute(['ZZMSG ' . $type, $type, $type]);
            $ch = loadMessagingChannel($conn, (int)$conn->lastInsertId());
            $ch['credentials'] = $creds;
            return $ch;
        };
        $mmCh = $mk('mattermost', ['server_url' => 'https://mm.test', 'bot_token' => 'zz']);
        $say = fn(string $from, string $to, string $id, array $extra = []) => $extra + ['from' => $from, 'to' => $to, 'body' => 'ZZMSG printer is down',
            'profile_name' => 'kp', 'provider_msg_id' => $id, 'media' => [], 'timestamp' => null, 'language_code' => ''];
        $ticketUser = fn(array $r) => (int)$conn->query("SELECT user_id FROM tickets WHERE id = " . (int)$r['ticket_id'])->fetchColumn();
        $r = ingestInboundMessage($conn, $mmCh, $say('zzmmuser000000000000000001', 'zzchan:zzpost1', 'mm:zz1'));
        ok('Mattermost: a verified email we know files the ticket under that person', $ticketUser($r) === $knownId);
        $mmUser['email_verified'] = false;
        $r = ingestInboundMessage($conn, $mmCh, $say('zzmmuser000000000000000002', 'zzchan:zzpost2', 'mm:zz2'));
        ok('TRAP: Mattermost: an UNverified email is not trusted - a contact of its own instead', $ticketUser($r) !== $knownId && $ticketUser($r) > 0);
        ok('...named with the real name from Mattermost', $conn->query("SELECT display_name FROM users WHERE id = " . $ticketUser($r))->fetchColumn() === 'Known Person');
        $tmCh = $mk('teams', ['app_id' => 'zz', 'app_secret' => 'zz', 'tenant_id' => 'zz']);
        $r = ingestInboundMessage($conn, $tmCh, $say('a:zzconvknown', 'https://smba.trafficmanager.net/emea/|a:zzconvknown', 'teams:zz3', ['sender_id' => '29:zz']));
        ok('Teams: the member\'s email files the ticket under that person', $ticketUser($r) === $knownId);
        MessagingProvider::$testTransport = function (): array { return [500, '']; };
        $r = ingestInboundMessage($conn, $mmCh, $say('zzmmuser000000000000000003', 'zzchan:zzpost3', 'mm:zz4'));
        ok('a failed lookup still raises the ticket, with a contact', !empty($r['ticket_id']) && $ticketUser($r) > 0 && $ticketUser($r) !== $knownId);
        MessagingProvider::$testTransport = null;

        $conn->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES ('csat_in_channel', '0')
                        ON DUPLICATE KEY UPDATE setting_value = '0'")->execute();
        ok('with "ask in their chat" off, a chat ticket gets the email survey', csatTicketChannel($conn, $ticket) === null);
    } catch (Throwable $e) {
        ok('the run completed', false, get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
    } finally {
        MessagingProvider::$testTransport = null;
        if ($conn->inTransaction()) $conn->rollBack();
    }
    $left = (int)$conn->query("SELECT COUNT(*) FROM ticket_csat_responses WHERE token LIKE 'zzmsg%'")->fetchColumn()
          + (int)$conn->query("SELECT COUNT(*) FROM messaging_channels WHERE name LIKE 'ZZMSG%'")->fetchColumn()
          + (int)$conn->query("SELECT COUNT(*) FROM emails WHERE subject = 'ZZMSG'")->fetchColumn();
    ok('nothing survived the run (rolled back)', $left === 0, "$left rows left");
}

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail ? 1 : 0);
