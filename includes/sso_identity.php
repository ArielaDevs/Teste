<?php
/**
 * Sign-in identity links — the one home for the (provider, subject) → account
 * mapping that both sign-in paths share.
 *
 * `analyst_sso_identities` and `user_sso_identities` record which directory or
 * identity-provider identity belongs to which FreeITSM account. OIDC writes and
 * reads them (api/auth/oidc_callback.php) and so does LDAP/AD (includes/ldap.php),
 * and both resolve a person the same way: existing link → email match →
 * just-in-time create. The link lookup runs FIRST in all four branches, which is
 * why the dangling-link case below had to be fixed in one place rather than four.
 */

/**
 * Drop a sign-in link whose account no longer exists.
 *
 * A link can outlive the account it points at. Both identity tables declare
 * ON DELETE CASCADE, but anything that deletes rows with FOREIGN_KEY_CHECKS
 * off bypasses the cascade — the demo data importer used to do exactly that
 * when it emptied `analysts` and `users` (#1297) — and the link is left behind,
 * pointing at an id that is gone.
 *
 * That is not a cosmetic leftover. Every sign-in path looks a person up by
 * (provider, subject) FIRST, so a dangling link WINS that lookup, and email
 * matching and just-in-time provisioning both live in the branch past it. The
 * person is locked out permanently, and re-creating their account by hand does
 * not help, because the link still points at the old id. It happened to a real
 * user, and the message he saw ("Your account is no longer available") named
 * nothing he could act on.
 *
 * Clearing the link puts them back on the path a first-time sign-in takes, with
 * the same verified-email and provider-assignment checks — no account becomes
 * reachable that a first-time user could not already reach. Deleting an account
 * through the interface already cascades the link away and already allows
 * just-in-time re-creation, so this adds no new exposure.
 *
 * $table is a caller-supplied literal and is checked against a fixed list; it
 * never comes from request input.
 */
function ssoClearDanglingLink(PDO $conn, string $table, int $providerId, string $sub): void {
    if (!in_array($table, ['analyst_sso_identities', 'user_sso_identities'], true)) {
        throw new Exception("ssoClearDanglingLink: unknown table $table");
    }
    $conn->prepare("DELETE FROM `$table` WHERE provider_id = ? AND subject = ?")
         ->execute([$providerId, $sub]);
}

/**
 * Have the discussion #155 provider columns (auto_create_analysts,
 * analyst_fallback_mode, profile_sync_mode) been added yet?
 *
 * 🔴 An upgraded install has none of them until someone runs System →
 * Database Verification. Any query that NAMES one fails until then, and the
 * screens that read providers treat a failed query as "nothing there" - the
 * Authentication page would say "No providers yet". So every reader that
 * names them asks this first. The three arrive together, so one probe covers
 * all of them. Cached per request.
 */
function ssoJitColumnsReady(PDO $conn): bool {
    static $ready = null;
    if ($ready === null) {
        try {
            $ready = (bool)$conn->query("SHOW COLUMNS FROM auth_providers LIKE 'profile_sync_mode'")->fetch();
        } catch (PDOException $e) {
            $ready = false;
        }
    }
    return $ready;
}

/**
 * Is this provider rewriting profile details on every sign-in? (discussion #155)
 *
 * True only for an OIDC provider set to 'always'. When it is, the synced
 * fields are shown read-only on My Account (portal) and My details (analyst),
 * because anything typed there would be put back at the next sign-in.
 *
 * Deliberately NOT true for LDAP or CardDAV: they keep records up to date
 * their own way (directory sync, address-book write-back), with their own
 * rules about what is editable - see portalProfileAccess().
 */
function ssoProfileSyncLocks(?string $protocol, ?string $syncMode): bool {
    return strtolower((string)$protocol) === 'oidc' && $syncMode === 'always';
}

/**
 * Does the given analyst's sign-in provider lock their My details fields?
 * Safe before Database Verification (answers false).
 */
function ssoAnalystProfileLocked(PDO $conn, int $analystId): bool {
    if (!ssoJitColumnsReady($conn)) return false;
    $st = $conn->prepare(
        "SELECT p.protocol, p.profile_sync_mode
           FROM analysts a
           JOIN auth_providers p ON p.id = a.auth_provider_id
          WHERE a.id = ?"
    );
    $st->execute([$analystId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ? ssoProfileSyncLocks($row['protocol'], $row['profile_sync_mode']) : false;
}

/**
 * Have the GH #147 portal routing columns (portal_email_domains,
 * portal_show_button) been added yet? Same reason as ssoJitColumnsReady():
 * the portal login and the resolver must keep working on an upgraded install
 * that has not run Database Verification. The two arrive together.
 */
function ssoPortalRoutingColumnsReady(PDO $conn): bool {
    static $ready = null;
    if ($ready === null) {
        try {
            $ready = (bool)$conn->query("SHOW COLUMNS FROM auth_providers LIKE 'portal_email_domains'")->fetch();
        } catch (PDOException $e) {
            $ready = false;
        }
    }
    return $ready;
}

/**
 * Split what an admin typed into the "Email domains" box into clean domains.
 *
 * One per line (commas and semicolons are accepted too, because people paste
 * lists). A leading "@" is dropped, so "@acme.com" works as well as "acme.com".
 * Returns ['domains' => [...unique, lowercase...], 'invalid' => [...as typed...]]
 * so the save can name what it refused rather than silently dropping it.
 *
 * Matching is on the WHOLE domain: "acme.com" does not cover "uk.acme.com".
 * A subdomain is a separate line. Predictable beats clever here - a suffix
 * match would quietly route addresses nobody listed.
 */
function ssoParseEmailDomains($value): array {
    // Lines, commas and semicolons - not spaces, so a typo such as "not a domain"
    // is quoted back whole in the error rather than as three fragments.
    $parts = is_array($value) ? $value : preg_split('/[\r\n,;]+/', (string)$value);
    $domains = [];
    $invalid = [];
    foreach ($parts as $part) {
        $d = strtolower(trim((string)$part));
        $d = ltrim($d, '@');
        if ($d === '') continue;
        if (strlen($d) > 253 || !preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9-]{2,63}$/', $d)) {
            $invalid[] = trim((string)$part);
            continue;
        }
        $domains[$d] = true;
        if (count($domains) >= 200) break;
    }
    return ['domains' => array_keys($domains), 'invalid' => $invalid];
}

/**
 * The OIDC provider whose "Email domains" list holds this address's domain,
 * or null. Used only by the portal's email-first router (GH #147).
 *
 * Enabled OIDC providers only: an LDAP directory has nowhere to redirect to.
 * Safe before Database Verification (answers null).
 */
function ssoPortalProviderForEmail(PDO $conn, string $email): ?array {
    if (!ssoPortalRoutingColumnsReady($conn)) return null;
    $at = strrpos($email, '@');
    if ($at === false) return null;               // a bare username has no domain
    $domain = strtolower(trim(substr($email, $at + 1)));
    if ($domain === '') return null;

    $rows = $conn->query(
        "SELECT id, display_name, portal_email_domains FROM auth_providers
          WHERE enabled = 1 AND protocol = 'oidc'
            AND portal_email_domains IS NOT NULL AND portal_email_domains <> ''
          ORDER BY sort_order, display_name"
    )->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) {
        if (in_array($domain, explode("\n", $r['portal_email_domains']), true)) {
            return ['id' => (int)$r['id'], 'display_name' => $r['display_name']];
        }
    }
    return null;
}
