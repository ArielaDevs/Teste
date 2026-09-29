<?php
/**
 * Domains — change detection: noticing that something important moved.
 *
 * The classic domain hijack is quiet: somebody who has phished the registrar
 * login changes the name servers, and from then on they control the website and
 * the mail. Nobody at the organisation changed anything, so nobody is looking.
 * The first sign is usually customers reporting a strange page.
 *
 * So after every check the fields that only change on purpose are compared with
 * the last run, and anything that moved is (1) written to the domain's history
 * and (2) announced as a `domain.changed` event — which the notification bell,
 * workflows and the alert e-mail all pick up.
 *
 * ⚠️ DELIBERATELY NOT WATCHED: A / AAAA records. Sites behind a CDN or a cloud
 * load balancer change address constantly and legitimately; watching them would
 * bury the one alert that matters under hundreds that do not.
 */

/** The fields compared, and a label key for each (lang: domains.watch.<key>). */
function domainWatchedFields(): array
{
    // Name servers are watched as DNS actually answers (dns_ns), not as the
    // record says: a person editing the record's list is not a hijack, and a
    // hijack shows up in live DNS whatever the record says.
    return ['registrar', 'transfer_lock', 'registry_lock', 'dnssec', 'dns_ns', 'mx', 'spf', 'dmarc'];
}

/** Build the baseline from a domain row plus the check snapshot. */
function domainBaseline(array $d, array $snapshot): array
{
    $ns = array_values(array_filter(array_map(fn($x) => strtolower(rtrim(trim($x), '.')), preg_split('/\R/', (string)($d['nameservers'] ?? '')))));
    sort($ns);
    $flag = fn($v) => ($v === null || $v === '') ? null : (int)$v;
    return [
        'registrar'     => (string)($d['registrar_name'] ?? ''),
        'nameservers'   => implode(', ', $ns),
        'transfer_lock' => $flag($d['transfer_lock'] ?? null),
        'registry_lock' => $flag($d['registry_lock'] ?? null),
        'dnssec'        => $flag($d['dnssec'] ?? null),
        'dns_ns'        => implode(', ', $snapshot['dns_ns'] ?? []),
        'mx'            => implode(', ', $snapshot['mx'] ?? []),
        'spf'           => (string)($snapshot['spf'] ?? ''),
        'dmarc'         => (string)($snapshot['dmarc'] ?? ''),
    ];
}

/**
 * What changed between the previous baseline and this one.
 *
 * ⚠️ A value going from KNOWN to UNKNOWN (a failed lookup, a DNS timeout) is not
 * a change — it is a gap in our knowledge. Reporting it would page somebody
 * every time a registry had a slow afternoon. Only known → different known
 * counts. Unknown → known is the first sighting, recorded without an alert.
 *
 * @return array<int,array{field:string,old:string,new:string}>
 */
function domainBaselineDiff(?array $old, array $new): array
{
    if (!$old) return [];
    $out = [];
    foreach (domainWatchedFields() as $k) {
        $o = $old[$k] ?? null;
        $n = $new[$k] ?? null;
        if ($o === null || $o === '' || $n === null || $n === '') continue;
        if ((string)$o === (string)$n) continue;
        $out[] = ['field' => $k, 'old' => (string)$o, 'new' => (string)$n];
    }
    return $out;
}

/**
 * Keep the previous value of a field when this run could not see it, so a
 * one-off gap never erases what we knew (and never makes tomorrow's reappearance
 * look like a change).
 */
function domainBaselineMerge(?array $old, array $new): array
{
    if (!$old) return $new;
    foreach ($new as $k => $v) {
        if (($v === null || $v === '') && isset($old[$k]) && $old[$k] !== '' && $old[$k] !== null) {
            $new[$k] = $old[$k];
        }
    }
    return $new;
}

/** Is this change one that should wake somebody up (vs. merely be recorded)? */
function domainChangeIsSerious(array $c): bool
{
    // Losing a lock, or the servers that answer for the domain moving, is how
    // a hijack looks. A DMARC tweak is housekeeping.
    if (in_array($c['field'], ['registrar', 'dns_ns', 'mx'], true)) return true;
    if (in_array($c['field'], ['transfer_lock', 'registry_lock', 'dnssec'], true) && $c['new'] === '0') return true;
    return false;
}
