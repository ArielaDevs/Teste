<?php
/**
 * Domains — turning whatever somebody typed or pasted into a domain name.
 *
 * People paste URLs, e-mail addresses, "WWW.Example.COM.", names with a port on
 * the end, and names in their own script. Every one of those should land as the
 * same row, so every path in (the form, the paste box, CSV import, the REST API)
 * goes through domainNormalise() and nothing stores a name it did not return.
 *
 * 🔑 STORED AS ASCII. An internationalised name (bücher.de) is kept as its
 * punycode form (xn--bcher-kva.de) because that is the name the registry, DNS
 * and certificates all use; the Unicode form goes in display_name for people.
 * Two spellings of one name must never become two rows.
 */

/**
 * @return array{ok:bool, name?:string, display?:?string, error?:string}
 */
function domainNormalise(string $raw): array
{
    $s = trim($raw);
    if ($s === '') return ['ok' => false, 'error' => 'Enter a domain name.'];

    $s = mb_strtolower($s, 'UTF-8');
    $s = preg_replace('#^[a-z][a-z0-9+.\-]*://#', '', $s);   // scheme
    if (strpos($s, '@') !== false) {                         // an e-mail address, or user@host
        $s = substr($s, strrpos($s, '@') + 1);
    }
    $s = preg_replace('#[/?\#].*$#', '', $s);                // path, query, fragment
    $s = preg_replace('#:\d+$#', '', $s);                    // port
    $s = rtrim($s, '.');                                     // the root dot
    $s = trim($s);

    // "www.example.com" is somebody pasting a web address, not registering a
    // host called www. Only stripped when a real name remains behind it.
    if (strncmp($s, 'www.', 4) === 0 && substr_count($s, '.') >= 2) {
        $s = substr($s, 4);
    }

    if ($s === '') return ['ok' => false, 'error' => 'Enter a domain name.'];

    $ascii = $s;
    if (preg_match('/[^\x20-\x7e]/', $s)) {
        if (!function_exists('idn_to_ascii')) {
            return ['ok' => false, 'error' => 'This server cannot handle international domain names (the PHP intl extension is not installed).'];
        }
        $conv = idn_to_ascii($s, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
        if ($conv === false || $conv === '') {
            return ['ok' => false, 'error' => 'That is not a valid international domain name.'];
        }
        $ascii = strtolower($conv);
    }

    if (strlen($ascii) > 253) return ['ok' => false, 'error' => 'A domain name can be at most 253 characters.'];

    $labels = explode('.', $ascii);
    if (count($labels) < 2) {
        return ['ok' => false, 'error' => 'Include the ending, for example example.com rather than example.'];
    }
    foreach ($labels as $l) {
        if ($l === '' || strlen($l) > 63 || !preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?$/', $l)) {
            return ['ok' => false, 'error' => "\"$raw\" is not a valid domain name."];
        }
    }
    if (ctype_digit(end($labels))) {
        return ['ok' => false, 'error' => 'That looks like an IP address, not a domain name.'];
    }

    $display = null;
    if (strpos($ascii, 'xn--') !== false && function_exists('idn_to_utf8')) {
        $u = idn_to_utf8($ascii, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
        if ($u !== false && $u !== $ascii) $display = $u;
    }

    return ['ok' => true, 'name' => $ascii, 'display' => $display];
}

/** The last label: "co.uk" is not a TLD, "uk" is. */
function domainTld(string $name): string
{
    $p = strrpos($name, '.');
    return $p === false ? $name : substr($name, $p + 1);
}

/**
 * Is $host this domain or one of its sub-domains? Used to keep the "extra hosts
 * to check certificates on" list from being pointed at somebody else's server —
 * or at an internal address, which would make this a way to probe the network.
 */
function domainHostBelongsTo(string $host, string $domain): bool
{
    $host = strtolower(rtrim(trim($host), '.'));
    return $host === $domain || (strlen($host) > strlen($domain) && substr($host, -strlen($domain) - 1) === '.' . $domain);
}

/**
 * The extra certificate hosts, cleaned: one per entry, only ones that belong to
 * the domain, de-duplicated. The apex and www are always checked and are not
 * listed here.
 */
function domainParseSslHosts(?string $raw, string $domain): array
{
    $out = [];
    foreach (preg_split('/[\s,;]+/', (string)$raw) as $h) {
        $h = strtolower(trim($h));
        if ($h === '') continue;
        $n = domainNormaliseHost($h);
        if ($n === null || !domainHostBelongsTo($n, $domain)) continue;
        if ($n === $domain || $n === 'www.' . $domain) continue;
        $out[$n] = true;
    }
    return array_keys($out);
}

/** A host name (may be a sub-domain, keeps "www."), or null when it is not one. */
function domainNormaliseHost(string $h): ?string
{
    $h = preg_replace('#^[a-z][a-z0-9+.\-]*://#i', '', trim($h));
    $h = preg_replace('#[/?\#:].*$#', '', $h);
    $h = strtolower(rtrim($h, '.'));
    if ($h === '' || strlen($h) > 253) return null;
    if (preg_match('/[^\x20-\x7e]/', $h)) {
        if (!function_exists('idn_to_ascii')) return null;
        $h = idn_to_ascii($h, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
        if ($h === false) return null;
    }
    foreach (explode('.', $h) as $l) {
        if ($l === '' || strlen($l) > 63 || !preg_match('/^[a-z0-9_]([a-z0-9-]*[a-z0-9])?$/', $l)) return null;
    }
    return $h;
}

/** Purposes a domain can be registered for. The keys drive checks; labels are in lang. */
function domainPurposes(): array
{
    // primary     the organisation's main name — web and mail
    // secondary   another live name — a brand, a product, a country site
    // redirect    only forwards web visitors somewhere else
    // email_only  used for mail, no website
    // defensive   held so nobody else can have it — no web, no mail
    // parked      not in use yet
    // campaign    short-lived marketing name
    return ['primary', 'secondary', 'redirect', 'email_only', 'defensive', 'parked', 'campaign'];
}

/** Purposes that should never send or receive mail, so get "lock it down" advice. */
function domainPurposeIsNonMail(string $purpose): bool
{
    return in_array($purpose, ['defensive', 'parked', 'redirect'], true);
}

/** auto | manual | do_not_renew | unknown */
function domainRenewalModes(): array
{
    return ['auto', 'manual', 'do_not_renew', 'unknown'];
}
