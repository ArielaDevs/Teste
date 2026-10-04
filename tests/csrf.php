<?php
/* 🔴 NEVER OVER THE WEB. See tests/README.md. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * Cross-site request forgery protection (S4) - the origin check and the token,
 * through the real web server.
 *
 * Every POST here goes to the contract save with an empty body: past the CSRF
 * checks it is refused for a missing contract number, so a pass writes nothing.
 * "Reached" means the endpoint answered for its own reason; "refused" means the
 * CSRF layer stopped it first.
 *
 * Needs the app served at http://localhost/<folder>/ (override with FREEITSM_URL).
 * Forged sessions are written to session.save_path and removed at the end.
 *
 * Run:  php tests/csrf.php
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/csrf.php';

$pass = 0; $fail = 0;
function ok(string $what, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  PASS  {$what}\n"; }
    else       { $fail++; echo "  FAIL  {$what}" . ($detail ? "  <- {$detail}" : '') . "\n"; }
}

// Not BASE_URL: from the command line it is not the web path. The folder name is.
$base = rtrim(getenv('FREEITSM_URL') ?: 'http://localhost/' . basename(dirname(__DIR__)) . '/', '/') . '/';
$dir  = rtrim(ini_get('session.save_path') ?: 'c:/wamp64/tmp', '/\\');
$tok  = bin2hex(random_bytes(32));
$sids = ['signed' => 'csrftest' . bin2hex(random_bytes(4)), 'anon' => 'csrfanon' . bin2hex(random_bytes(4))];
file_put_contents("$dir/sess_{$sids['signed']}", 'analyst_id|i:1;analyst_name|s:13:"Administrator";is_admin|i:1;csrf_token|s:64:"' . $tok . '";');
file_put_contents("$dir/sess_{$sids['anon']}", 'csrf_token|s:64:"' . $tok . '";');

function req(string $url, array $o = []): array {
    global $sids;
    $ch = curl_init($url);
    $h = $o['headers'] ?? [];
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_HEADER => false]);
    if (isset($o['session'])) curl_setopt($ch, CURLOPT_COOKIE, 'PHPSESSID=' . $sids[$o['session']]);
    if (($o['method'] ?? 'POST') === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $o['body'] ?? '{}');
        if (!isset($o['form'])) $h[] = 'Content-Type: application/json';
    }
    curl_setopt($ch, CURLOPT_HTTPHEADER, $h);
    $body = (string)curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    return [$code, $body];
}
$refused = fn(array $r, string $why) => $r[0] === 403 && strpos($r[1], '"csrf_' . $why . '"') !== false;
// "Reached" = the endpoint itself answered: a real status, not a 404 or a CSRF refusal.
$reached = fn(array $r) => in_array($r[0], [200, 401], true) && strpos($r[1], 'csrf_') === false;

echo "\nCSRF protection (S4)\n" . str_repeat('=', 70) . "\n";
[$probe] = req($base . 'favicon.svg', ['method' => 'GET']);
if ($probe !== 200) {
    echo "  SKIP  the app is not answering at {$base} (set FREEITSM_URL)\n";
    foreach ($sids as $x) @unlink("$dir/sess_$x");
    exit(0);
}
$save = $base . 'api/contracts/save_contract.php';
$T = 'X-CSRF-Token: ' . $tok;

echo "\nThe token, for a signed-in session:\n";
ok('no token is refused', $refused(req($save, ['session' => 'signed']), 'token'));
ok('a wrong token is refused', $refused(req($save, ['session' => 'signed', 'headers' => ['X-CSRF-Token: ' . str_repeat('0', 64)]]), 'token'));
ok('POSITIVE CONTROL: the right token in the header is let through', $reached(req($save, ['session' => 'signed', 'headers' => [$T, 'Sec-Fetch-Site: same-origin']])));
ok('...and as a form field', $reached(req($save, ['session' => 'signed', 'form' => 1, 'body' => '_csrf_token=' . $tok])));

echo "\nThe origin:\n";
ok('cross-site (Sec-Fetch-Site) is refused even with the token', $refused(req($save, ['session' => 'signed', 'headers' => [$T, 'Sec-Fetch-Site: cross-site']]), 'origin'));
ok('another Origin is refused even with the token', $refused(req($save, ['session' => 'signed', 'headers' => [$T, 'Origin: http://evil.example']]), 'origin'));
ok('a sibling subdomain (same-site) is refused', $refused(req($save, ['session' => 'signed', 'headers' => [$T, 'Sec-Fetch-Site: same-site', 'Origin: http://evil.localhost']]), 'origin'));
ok('Origin: null is refused', $refused(req($save, ['session' => 'signed', 'headers' => [$T, 'Origin: null']]), 'origin'));
$selfHost = parse_url($base, PHP_URL_HOST) . (parse_url($base, PHP_URL_PORT) ? ':' . parse_url($base, PHP_URL_PORT) : '');
ok('POSITIVE CONTROL: this host as Origin is let through', $reached(req($save, ['session' => 'signed', 'headers' => [$T, 'Origin: http://' . $selfHost]])));

echo "\nWho is not checked:\n";
ok('no session cookie: nothing to forge, not checked', $reached(req($save, ['headers' => ['Origin: http://evil.example']])));
ok('signed out: the origin is checked...', $refused(req($base . 'api/self-service/login.php', ['session' => 'anon', 'headers' => ['Sec-Fetch-Site: cross-site']]), 'origin'));
ok('...but no token is needed', $reached(req($base . 'api/self-service/login.php', ['session' => 'anon', 'headers' => ['Sec-Fetch-Site: same-origin']])));
ok('the REST API is exempt (API key, CORS by design)', $reached(req($base . 'api/v1/contracts', ['session' => 'signed', 'headers' => ['Sec-Fetch-Site: cross-site']])));
ok('GET is not checked', $reached(req($base . 'api/people/list.php', ['session' => 'signed', 'method' => 'GET', 'headers' => ['Sec-Fetch-Site: cross-site']])));

echo "\nGETs that write must carry the token:\n";
$stream = $base . 'api/rfp-builder/generate_section.php?section_id=0';
ok('an RFP Builder stream without the token is refused', $refused(req($stream, ['session' => 'signed', 'method' => 'GET']), 'token'));
ok('POSITIVE CONTROL: with ?_csrf= it is let through', $reached(req($stream . '&_csrf=' . $tok, ['session' => 'signed', 'method' => 'GET'])));

echo "\nPages get the token:\n";
[$c, $html] = req($base . 'people/help.php', ['session' => 'signed', 'method' => 'GET']);
ok('a page carries the meta tag with this session\'s token', strpos($html, '<meta name="csrf-token" content="' . $tok . '">') !== false);
ok('...and loads csrf.js before anything else in <head>', (bool)preg_match('/<head[^>]*><meta name="csrf-token"[^>]*><script src="[^"]*csrf\.js/i', $html));

echo "\nThe exempt list points at real files:\n";
foreach (CSRF_EXEMPT_PATHS as $p) {
    ok("exists: $p", file_exists(__DIR__ . '/../' . rtrim($p, '/')));
}

foreach ($sids as $s) @unlink("$dir/sess_$s");
echo "\n{$pass} passed, {$fail} failed\n";
exit($fail ? 1 : 0);
