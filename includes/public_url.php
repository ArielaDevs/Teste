<?php
/**
 * Where is this installation reachable from, seen from outside?
 *
 * WHY THIS EXISTS AT ALL
 * ----------------------
 * BASE_URL answers "where does the app live under the web root" and nothing more.
 * It is a path — `/freeitsm-app/` — which is the right and sufficient answer for
 * every link on a page FreeITSM itself renders, because the browser already knows
 * the host.
 *
 * It is the wrong answer for a link FreeITSM *sends somewhere else*: an email, a
 * Jira issue, a Slack message. There the reader's browser resolves the address
 * against whatever host they happen to be on, and a bare path 404s.
 *
 * THE CASE THIS IS REALLY FOR: THERE IS NO REQUEST
 * ------------------------------------------------
 * The obvious fix — read the host off the current request — works for the analyst
 * who clicked something, and fails for everything unattended. Mail collected by
 * cron, an SLA breach notification, a workflow escalation: no request exists, so
 * $_SERVER['HTTP_HOST'] is simply absent. **Unattended is the normal case for
 * outbound mail, not the exception**, which is why an explicitly configured
 * address has to come first and cannot merely be a fallback.
 *
 * WHY IT MOVED HERE
 * -----------------
 * This logic was written for issue trackers and lived in integrations.php, where
 * `integrationsAbsoluteUrl()` still is — it now calls this. The [ticket_url] merge
 * code (discussion #80) needs precisely the same answer, and a second copy of
 * "how do I address this install" is exactly the kind of duplication that drifts:
 * one of them would get the reverse-proxy handling and the other would not.
 * `includes/csat.php` and `includes/self_service_email.php` each still build their
 * own; they are older, they only ever run in request context, and folding them in
 * is a separate job with its own testing.
 */

/**
 * The address an administrator has explicitly configured, or '' if none.
 *
 * Separate from publicBaseUrl() on purpose: the UI needs to distinguish "nothing is
 * set, so a link sent from cron will be broken" from "something is set". Those are
 * different facts and only this function can tell them apart — publicBaseUrl()
 * always returns *something*, which is what makes it safe to build a link with and
 * useless for deciding whether to warn.
 *
 * `messaging_public_base_url` is read as a fallback because it already holds exactly
 * this fact on installs that configured web chat; `public_base_url` is preferred so
 * the general setting supersedes it without needing a migration.
 */
function publicBaseUrlSetting(PDO $conn): string
{
    try {
        $stmt = $conn->prepare(
            "SELECT setting_key, setting_value FROM system_settings
              WHERE setting_key IN ('public_base_url','messaging_public_base_url')"
        );
        $stmt->execute();
        $found = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $found[$r['setting_key']] = trim((string)$r['setting_value']);
        }
        $configured = $found['public_base_url'] ?? '';
        if ($configured === '') {
            $configured = $found['messaging_public_base_url'] ?? '';
        }
    } catch (Exception $ignored) {
        // An install whose system_settings table predates this still gets a link.
        return '';
    }

    return preg_match('~^https?://~i', $configured) ? rtrim($configured, '/') : '';
}

/**
 * Make sure the configured address carries the app's sub-path exactly once.
 *
 * ⚠️ THE TWO SETTINGS ARE DIFFERENT SHAPES, and this is where that is reconciled.
 * `messaging_public_base_url` is an ORIGIN by design — its own save endpoint strips
 * any path, because messagingWebhookUrl() adds the app root itself. `public_base_url`
 * is the whole root, sub-path included, because that is the natural thing to type
 * and what the integrations code has always appended paths to directly.
 *
 * Left alone, an install at `https://example.com/freeitsm-app` that had only ever
 * configured the messaging setting would get `https://example.com/self-service/...`
 * — a link to a page that is not there. Appending unconditionally instead gives
 * `.../freeitsm-app/freeitsm-app/...` for anyone who typed the sub-path in. So the
 * sub-path is added only when it is not already on the end, which is the same guard
 * messagingWebhookUrl() arrived at for the same reason.
 */
function publicUrlWithAppPath(string $root): string
{
    $root = rtrim($root, '/');
    $app  = rtrim(defined('BASE_URL') ? BASE_URL : '/', '/');   // '' when at a domain root

    if ($app === '' || substr($root, -strlen($app)) === $app) {
        return $root;
    }
    return $root . $app;
}

/**
 * The root every outbound link should be built on, without a trailing slash.
 *
 * Resolution order, most trustworthy first:
 *   1. the configured address — the only one that is right from cron;
 *   2. the current request's scheme + host, when somebody clicked something;
 *   3. BASE_URL alone — a path, which is no worse than what a caller would have
 *      done unaided, so an install that has configured nothing is not made worse.
 */
function publicBaseUrl(PDO $conn): string
{
    static $root = null;
    if ($root !== null) {
        return $root;
    }

    $configured = publicBaseUrlSetting($conn);
    if ($configured !== '') {
        $root = publicUrlWithAppPath($configured);
        return $root;
    }

    if (!empty($_SERVER['HTTP_HOST'])) {
        // The ONE answer to "is this request HTTPS?" (GH #152): it honours a
        // proxy's X-Forwarded-Proto only when TRUST_PROXY_HTTPS says the proxy
        // is ours, takes the first hop of a chained header, and counts port 443.
        // This used to trust the header outright, so a client could choose the
        // scheme of a link put in somebody else's inbox, and a sign-in link and
        // an emailed link could disagree about the same site.
        require_once __DIR__ . '/session_security.php';
        $https = requestIsHttps();
        // The Host header is attacker-controllable and this value ends up in mail
        // sent to other people, so only characters a hostname may legally contain
        // survive. A link we put in somebody else's inbox is not somewhere to
        // reflect an unchecked header.
        $host = preg_replace('/[^A-Za-z0-9\.\-:]/', '', (string)$_SERVER['HTTP_HOST']);
        if ($host !== '') {
            $root = ($https ? 'https://' : 'http://') . $host . rtrim(BASE_URL, '/');
            return $root;
        }
    }

    $root = rtrim(BASE_URL, '/');
    return $root;
}

/** An absolute URL for an app-relative path, e.g. 'self-service/tickets.php?id=9'. */
function publicAbsoluteUrl(PDO $conn, string $path): string
{
    return publicBaseUrl($conn) . '/' . ltrim($path, '/');
}
