<?php
/**
 * Domains — DNS questions, answered one way whatever the resolver.
 *
 * Two resolvers, one shape:
 *
 *   system      PHP's dns_get_record(), i.e. whatever the server itself uses.
 *               Fast and private, but PHP cannot ask for every type on every
 *               platform — on Windows there is no DNS_CAA constant at all, and no
 *               PHP anywhere can ask for DS.
 *   google /    DNS-over-HTTPS JSON (dns.google/resolve, cloudflare-dns.com).
 *   cloudflare  Answers every type, and is what the "system" resolver falls back
 *               to for the types it cannot ask.
 *
 * 🔑 EVERY ANSWER IS NORMALISED: host names lower-case with no trailing dot, TXT
 * records re-joined from their 255-byte chunks, MX as [priority, host]. The
 * checks compare answers from different resolvers and from the registry, so a
 * trailing dot or a chunk boundary must never read as a difference.
 */

require_once __DIR__ . '/lookup.php';   // domainHttpGet()

/**
 * @return array{ok:bool, nxdomain:bool, records:array, error:?string}
 *   records: A/AAAA → list of IPs; NS/CNAME → host names; MX → [[pri, host]];
 *   TXT → strings; CAA → [[flags, tag, value]]; DS → [string] (presence is what matters)
 */
function domainDns(string $name, string $type, string $resolver = 'system'): array
{
    static $cache = [];
    $key = "$resolver|$type|$name";
    if (isset($cache[$key])) return $cache[$key];

    $type = strtoupper($type);
    if ($resolver === 'auto' || $resolver === '') {
        $resolver = (PHP_OS_FAMILY === 'Windows') ? 'google' : 'system';
    }
    $useDoh = $resolver !== 'system'
           || in_array($type, ['DS', 'DNSKEY'], true)
           || ($type === 'CAA' && !defined('DNS_CAA'));

    $res = $useDoh
        ? domainDnsDoh($name, $type, $resolver === 'cloudflare' ? 'cloudflare' : 'google')
        : domainDnsSystem($name, $type);

    // The system resolver can fail for reasons that have nothing to do with the
    // domain (a server with no outbound DNS). DoH is the second opinion.
    if (!$useDoh && !$res['ok']) {
        $res = domainDnsDoh($name, $type, 'google');
    }
    return $cache[$key] = $res;
}

function domainDnsSystem(string $name, string $type): array
{
    $map = ['A' => DNS_A, 'AAAA' => DNS_AAAA, 'MX' => DNS_MX, 'TXT' => DNS_TXT, 'NS' => DNS_NS, 'CNAME' => DNS_CNAME];
    if (defined('DNS_CAA')) $map['CAA'] = constant('DNS_CAA');
    if (!isset($map[$type])) return ['ok' => false, 'nxdomain' => false, 'records' => [], 'error' => "Type $type not supported"];

    $raw = @dns_get_record($name, $map[$type]);
    if ($raw === false) return ['ok' => false, 'nxdomain' => false, 'records' => [], 'error' => 'DNS query failed'];

    $out = [];
    foreach ($raw as $r) {
        switch ($type) {
            case 'A':     if (!empty($r['ip']))   $out[] = $r['ip']; break;
            case 'AAAA':  if (!empty($r['ipv6'])) $out[] = strtolower($r['ipv6']); break;
            case 'NS':
            case 'CNAME': if (!empty($r['target'])) $out[] = strtolower(rtrim($r['target'], '.')); break;
            case 'MX':    if (isset($r['target'])) $out[] = [(int)($r['pri'] ?? 0), strtolower(rtrim($r['target'], '.'))]; break;
            case 'TXT':   $out[] = isset($r['entries']) ? implode('', $r['entries']) : (string)($r['txt'] ?? ''); break;
            case 'CAA':   $out[] = [(int)($r['flags'] ?? 0), strtolower((string)($r['tag'] ?? '')), (string)($r['value'] ?? '')]; break;
        }
    }
    return ['ok' => true, 'nxdomain' => false, 'records' => domainDnsSort($type, $out), 'error' => null];
}

function domainDnsDoh(string $name, string $type, string $provider): array
{
    $url = $provider === 'cloudflare'
        ? 'https://cloudflare-dns.com/dns-query?name=' . rawurlencode($name) . '&type=' . $type
        : 'https://dns.google/resolve?name=' . rawurlencode($name) . '&type=' . $type;
    [$code, $body, $err] = domainHttpGet($url, 'application/dns-json', 8);
    $j = $code === 200 ? json_decode($body, true) : null;
    if (!is_array($j)) return ['ok' => false, 'nxdomain' => false, 'records' => [], 'error' => 'DNS-over-HTTPS query failed' . ($err ? ": $err" : '')];

    $status = (int)($j['Status'] ?? 2);
    if ($status === 3) return ['ok' => true, 'nxdomain' => true, 'records' => [], 'error' => null];
    if ($status !== 0) return ['ok' => false, 'nxdomain' => false, 'records' => [], 'error' => "DNS server failure (status $status)"];

    $typeNum = ['A' => 1, 'NS' => 2, 'CNAME' => 5, 'MX' => 15, 'TXT' => 16, 'AAAA' => 28, 'DS' => 43, 'DNSKEY' => 48, 'CAA' => 257][$type] ?? 0;
    $out = [];
    foreach ($j['Answer'] ?? [] as $a) {
        if ((int)($a['type'] ?? 0) !== $typeNum) continue;   // skip the CNAME hops in a chain
        $d = (string)($a['data'] ?? '');
        switch ($type) {
            case 'A': case 'AAAA': $out[] = strtolower($d); break;
            case 'NS': case 'CNAME': $out[] = strtolower(rtrim($d, '.')); break;
            case 'MX':
                $p = preg_split('/\s+/', trim($d), 2);
                $out[] = [(int)($p[0] ?? 0), strtolower(rtrim($p[1] ?? '', '.'))];
                break;
            case 'TXT':
                // "\"v=spf1 include:a\" \"-all\"" → one string, chunks joined.
                if (preg_match_all('/"((?:[^"\\\\]|\\\\.)*)"/', $d, $m)) {
                    $out[] = implode('', array_map('stripcslashes', $m[1]));
                } else {
                    $out[] = $d;
                }
                break;
            case 'CAA':
                if (preg_match('/^(\d+)\s+(\S+)\s+"?(.*?)"?$/', $d, $m)) $out[] = [(int)$m[1], strtolower($m[2]), $m[3]];
                break;
            default: $out[] = $d;
        }
    }
    return ['ok' => true, 'nxdomain' => false, 'records' => domainDnsSort($type, $out), 'error' => null];
}

function domainDnsSort(string $type, array $recs): array
{
    if ($type === 'MX') {
        usort($recs, fn($a, $b) => $a[0] <=> $b[0] ?: strcmp($a[1], $b[1]));
        return $recs;
    }
    if ($type === 'CAA') return $recs;
    $recs = array_values(array_unique($recs, SORT_REGULAR));
    sort($recs);
    return $recs;
}

/** The TXT records at $name that start with $prefix (case-insensitive), e.g. "v=spf1". */
function domainTxtStartingWith(string $name, string $prefix, string $resolver): array
{
    $r = domainDns($name, 'TXT', $resolver);
    return array_values(array_filter($r['records'], fn($t) => stripos(ltrim($t), $prefix) === 0));
}
