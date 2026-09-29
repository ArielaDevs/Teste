<?php
/**
 * POST {id, action:'reveal'} — the decrypted auth code, recorded in the
 * domain's history (unless switched off in Settings → Auth codes).
 * POST {id, action:'set', code} — set or clear it (blank clears).
 *
 * 🔴 BOTH need Cap::DOMAINS_AUTH_CODES. This is a READ that returns a secret,
 * which is the one kind of read a capability guards (docs/design/rbac.md §9b.1):
 * whoever holds a domain's auth code can move the domain to a registrar of
 * their choosing.
 */
require_once __DIR__ . '/../../includes/domains/api_bootstrap.php';
domainRequireCap($conn, Cap::DOMAINS_AUTH_CODES);

domainApiRun(function () use ($conn, $ctx) {
    $in = domainApiBody();
    $id = (int)($in['id'] ?? 0);
    $action = (string)($in['action'] ?? '');
    if ($action === 'reveal') {
        domainApiOk(['code' => DomainsService::revealAuthCode($conn, $ctx, $id)]);
    }
    if ($action === 'set') {
        DomainsService::updateDomain($conn, $ctx, $id, ['auth_code' => (string)($in['code'] ?? '')]);
        domainApiOk();
    }
    domainApiFail('Unknown action.');
});
