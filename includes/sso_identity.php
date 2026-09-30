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
