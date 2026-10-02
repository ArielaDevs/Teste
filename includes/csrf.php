<?php
/**
 * Cross-site request forgery protection (S4) - the token, the origin check, and
 * how the token reaches every page.
 *
 * WHAT THIS STOPS. Another website making a signed-in person's browser send a
 * request to FreeITSM - "add this analyst", "change this setting" - with the
 * login cookie attached. The session cookie is already SameSite=Lax, which stops
 * the classic cross-site form post in current browsers. This adds two more layers,
 * because Lax is not the whole answer:
 *   - "same-site" is wider than "same-origin": helpdesk.example.com and
 *     www.example.com are the same SITE, so a page on a sibling subdomain (an MSP
 *     hosting customer sites under its own domain) gets the cookie sent;
 *   - older browsers, and security reviews, expect a token.
 *
 * TWO CHECKS, BOTH CENTRAL (csrfEnforce(), run from request_guard.php on every
 * request that loads functions.php):
 *   1. ORIGIN. A state-changing request that carries the session cookie must say
 *      it came from this origin. Browsers label every request (Sec-Fetch-Site, or
 *      Origin); a cross-site or same-site-but-other-origin request is refused. A
 *      request with neither header is not from a browser, so there is no victim
 *      cookie to abuse and it passes.
 *   2. TOKEN. A state-changing request from a SIGNED-IN session must carry the
 *      session's token - as the X-CSRF-Token header (fetch, XHR), a `_csrf_token`
 *      form field (plain forms), or `_csrf` in the query string (sendBeacon and
 *      EventSource, which cannot set headers).
 *
 * 🔑 NOBODY HAS TO REMEMBER ANYTHING. Endpoints are protected by being reached,
 * not by opting in, and pages get the token and assets/js/csrf.js injected into
 * their <head> by csrfStartPageInjection(). csrf.js adds the token to every
 * same-origin request the page makes, whoever wrote the call.
 *
 * NOT CHECKED: GET/HEAD/OPTIONS (they must not change anything - see
 * csrfRequireToken() for the few that do), the CLI, and callers that are
 * deliberately cross-origin and never use the login cookie (CSRF_EXEMPT_PATHS).
 */

const CSRF_SESSION_KEY = 'csrf_token';
const CSRF_HEADER      = 'HTTP_X_CSRF_TOKEN';
const CSRF_FIELD       = '_csrf_token';
const CSRF_QUERY       = '_csrf';

/**
 * Callers that are cross-origin BY DESIGN and authenticate some other way (an API
 * key, a provider signature, a widget key). Paths relative to the app root; a
 * trailing slash means "everything below".
 */
const CSRF_EXEMPT_PATHS = [
    'api/v1/',                         // REST API: API key, CORS * by design
    'api/external/',                   // inventory agents: API key
    'api/messaging/webhook.php',       // Telegram, Slack, WhatsApp, Twilio: signed
    'api/calendar/graph_notify.php',   // Microsoft Graph change notifications
    'api/webchat/start.php',           // the chat widget on customers' websites
    'api/webchat/send.php',
    'api/webchat/escalate.php',
    'api/webchat/config.php',
    'api/webchat/poll.php',
    'cron/',                           // scheduled jobs over HTTP: ?token=
];

/** The request's path relative to the app root, e.g. "api/tickets/save.php". */
function csrfRequestPath(): string
{
    $script = str_replace('\\', '/', (string)($_SERVER['SCRIPT_FILENAME'] ?? ''));
    $root   = str_replace('\\', '/', dirname(__DIR__)) . '/';
    if ($script !== '' && strncmp($script, $root, strlen($root)) === 0) {
        return substr($script, strlen($root));
    }
    return ltrim((string)parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH), '/');
}

function csrfIsExempt(string $path): bool
{
    foreach (CSRF_EXEMPT_PATHS as $p) {
        if (substr($p, -1) === '/' ? strncmp($path, $p, strlen($p)) === 0 : $path === $p) return true;
    }
    return false;
}

function csrfIsStateChanging(): bool
{
    $m = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    return !in_array($m, ['GET', 'HEAD', 'OPTIONS'], true);
}

/** Did this request arrive with a session cookie at all? Without one there is nothing to forge. */
function csrfHasSessionCookie(): bool
{
    return isset($_COOKIE[session_name()]) && $_COOKIE[session_name()] !== '';
}

/** Is the session signed in - an analyst or a portal user? */
function csrfSessionIsAuthenticated(): bool
{
    return !empty($_SESSION['analyst_id']) || !empty($_SESSION['ss_user_id']);
}

/**
 * The session's token, created if missing. Works whether the session is open,
 * or was opened read_and_close (then it is reopened just long enough to save).
 * Returns '' when there is no session to keep it in.
 */
function csrfToken(): string
{
    if (!empty($_SESSION[CSRF_SESSION_KEY]) && is_string($_SESSION[CSRF_SESSION_KEY])) {
        return $_SESSION[CSRF_SESSION_KEY];
    }
    if (!isset($_SESSION) || PHP_SAPI === 'cli') return '';
    $token = bin2hex(random_bytes(32));
    if (session_status() === PHP_SESSION_ACTIVE) {
        $_SESSION[CSRF_SESSION_KEY] = $token;
        return $token;
    }
    // read_and_close: $_SESSION is populated but the file is shut. Reopen to save.
    if (session_id() !== '' && !headers_sent()) {
        @session_start();
        $_SESSION[CSRF_SESSION_KEY] = $token;
        session_write_close();
        return $token;
    }
    return '';
}

/** A new token - at sign-in, so a token seen before login is worthless after it. */
function csrfRotate(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        $_SESSION[CSRF_SESSION_KEY] = bin2hex(random_bytes(32));
    }
}

/** The token the request carried, from the header, a form field, or the query string. */
function csrfProvidedToken(): string
{
    foreach ([$_SERVER[CSRF_HEADER] ?? null, $_POST[CSRF_FIELD] ?? null, $_GET[CSRF_QUERY] ?? null] as $v) {
        if (is_string($v) && $v !== '') return $v;
    }
    return '';
}

/**
 * This app's own host[:port], as the browser addresses it: the Host header, and -
 * only when asked, because it costs a query - the configured public address (for a
 * proxy that rewrites Host).
 */
function csrfOwnHosts(bool $withConfigured = false): array
{
    $hosts = [];
    $h = strtolower(trim((string)($_SERVER['HTTP_HOST'] ?? '')));
    if ($h !== '') $hosts[] = $h;
    if ($withConfigured && function_exists('connectToDatabase')) {
        require_once __DIR__ . '/public_url.php';
        try {
            $u = publicBaseUrlSetting(connectToDatabase());
            $ph = $u !== '' ? strtolower((string)parse_url($u, PHP_URL_HOST)) : '';
            $pp = $u !== '' ? parse_url($u, PHP_URL_PORT) : null;
            if ($ph !== '') $hosts[] = $pp ? "$ph:$pp" : $ph;
        } catch (Throwable $e) {}
    }
    return array_values(array_unique($hosts));
}

/**
 * Did this request come from this app's own pages? true / false, or null when
 * the browser did not say (not a browser, or a very old one).
 *
 * The scheme is deliberately NOT compared: behind a TLS-terminating proxy without
 * TRUST_PROXY_HTTPS, PHP sees http while the browser says https. Host and port
 * decide; an attacker cannot make a victim's browser claim this host.
 */
function csrfSameOrigin(): ?bool
{
    $site = strtolower((string)($_SERVER['HTTP_SEC_FETCH_SITE'] ?? ''));
    if ($site === 'same-origin' || $site === 'none') return true;     // 'none' = typed, bookmark
    if ($site === 'cross-site') return false;
    // 'same-site' (a sibling subdomain) - fall through to the Origin, which can
    // still prove it is this host (some proxies make Sec-Fetch-Site unreliable).

    $origin = (string)($_SERVER['HTTP_ORIGIN'] ?? '');
    if ($origin === '' || $origin === 'null') {
        // Origin: null comes from sandboxed frames and some redirects - never trust it.
        if ($origin === 'null' || $site === 'same-site') return false;
        $ref = (string)($_SERVER['HTTP_REFERER'] ?? '');
        if ($ref === '') return null;
        $origin = $ref;
    }
    $host = strtolower((string)parse_url($origin, PHP_URL_HOST));
    $port = parse_url($origin, PHP_URL_PORT);
    if ($host === '') return false;
    $candidates = [$host . ($port ? ":$port" : '')];
    if (!$port) {   // a default port may or may not appear in Host
        $candidates[] = "$host:80";
        $candidates[] = "$host:443";
    }
    return (bool)array_intersect($candidates, csrfOwnHosts())
        || (bool)array_intersect($candidates, csrfOwnHosts(true));
}

/** Refuse, in the shape the caller expects: JSON for the API, a short page for a form post. */
function csrfRefuse(string $why): void
{
    http_response_code(403);
    header('X-CSRF-Refused: ' . $why);
    $wantsJson = strpos(csrfRequestPath(), 'api/') === 0
        || stripos((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json') !== false
        || stripos((string)($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json') !== false;
    $msg = $why === 'origin'
        ? 'This request came from another website, so it was refused.'
        : 'Your page is out of date or your session changed. Reload the page and try again.';
    if ($wantsJson) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => $msg, 'code' => 'csrf_' . $why]);
    } else {
        header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><meta charset="utf-8"><title>Request refused</title><p style="font:16px system-ui;margin:40px">'
           . htmlspecialchars($msg) . ' <a href="javascript:history.back()">Back</a></p>';
    }
    exit;
}

/**
 * The central check. Runs on include of request_guard.php (so on every request
 * that loads functions.php), after the endpoint has started its session.
 */
function csrfEnforce(): void
{
    if (PHP_SAPI === 'cli' || !csrfIsStateChanging()) return;
    if (csrfIsExempt(csrfRequestPath())) return;
    if (!csrfHasSessionCookie()) return;                 // no cookie, nothing to forge

    if (csrfSameOrigin() === false) csrfRefuse('origin');

    if (!isset($_SESSION) || !csrfSessionIsAuthenticated()) return;   // signed-out forms: origin only
    $expected = (string)($_SESSION[CSRF_SESSION_KEY] ?? '');
    $given = csrfProvidedToken();
    if ($expected === '' || $given === '' || !hash_equals($expected, $given)) csrfRefuse('token');
}

/**
 * For the rare GET that changes something and is called by the app itself (an
 * EventSource stream that writes, for instance): require the token explicitly.
 */
function csrfRequireToken(): void
{
    if (PHP_SAPI === 'cli') return;
    if (csrfSameOrigin() === false) csrfRefuse('origin');
    $expected = (string)($_SESSION[CSRF_SESSION_KEY] ?? '');
    $given = csrfProvidedToken();
    if ($expected === '' || $given === '' || !hash_equals($expected, $given)) csrfRefuse('token');
}

/** The tags every page needs: the token and the script that sends it. */
function csrfHeadTags(): string
{
    $token = csrfToken();
    if ($token === '') return '';
    $base = defined('BASE_URL') ? BASE_URL : '/';
    $v = @filemtime(dirname(__DIR__) . '/assets/js/csrf.js') ?: 1;
    return '<meta name="csrf-token" content="' . htmlspecialchars($token, ENT_QUOTES) . '">'
         . '<script src="' . htmlspecialchars($base, ENT_QUOTES) . 'assets/js/csrf.js?v=' . $v . '"></script>';
}

/**
 * Put csrfHeadTags() into every HTML page without editing each one: the page's
 * output is buffered and the tags are inserted straight after its <head>. Pages
 * only - an API response, a file, a stream and anything that is not HTML pass
 * through untouched.
 */
function csrfStartPageInjection(): void
{
    if (PHP_SAPI === 'cli') return;
    $path = csrfRequestPath();
    if (strpos($path, 'api/') === 0 || strpos($path, 'cron/') === 0) return;
    if (!isset($_SESSION)) return;   // no session, nothing to protect
    $tags = csrfHeadTags();          // create the token NOW, while headers can still be sent
    if ($tags === '') return;
    ob_start(function (string $html) use ($tags) {
        foreach (headers_list() as $h) {
            if (stripos($h, 'content-type:') === 0 && stripos($h, 'text/html') === false) return $html;
        }
        if (strpos($html, 'name="csrf-token"') !== false) return $html;   // already there
        $n = 0;
        $out = preg_replace_callback('/<head\b[^>]*>/i', fn($m) => $m[0] . $tags, $html, 1, $n);
        return ($n && $out !== null) ? $out : $html;
    });
}
