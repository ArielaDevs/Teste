<?php
/**
 * Domains — the health and security checks, and the grade they add up to.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * WHAT A FINDING IS
 *
 *   ['key' => 'spf_missing', 'area' => 'email', 'level' => 'fail',
 *    'weight' => 10, 'params' => ['...']]
 *
 * `key` names a pair of strings in lang/en/domains.php — check.<key>.title and
 * check.<key>.advice — so the words are translated and the stored JSON is not
 * English. `level` is pass | info | warn | fail; only warn and fail cost points.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * 🔑 THE PURPOSE CHANGES THE QUESTION
 *
 * A domain nobody sends mail from is not "missing" SPF — it should have a
 * DIFFERENT SPF, one that says "nobody sends mail as me" (v=spf1 -all), plus a
 * DMARC reject policy and a null MX. Otherwise it is the perfect address for
 * somebody else to send phishing from: unused, trusted, and unmonitored. So
 * defensive / parked / redirect domains get the lockdown checks instead of the
 * mail-sending ones. Most tools grade every domain the same and call a parked
 * domain "fine" because it has no mail to fail.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * THE GRADE
 *
 * 100 minus the weight of every warn and fail, clamped to 0-100, then A+ / A /
 * B / C / D / F. An expired or redemption-period domain is an F whatever else
 * it gets right — nothing else matters if the name is about to be lost.
 */

require_once __DIR__ . '/names.php';
require_once __DIR__ . '/dns.php';
require_once __DIR__ . '/tls.php';

/**
 * Run every check for one domain row.
 *
 * @param array $d  the `domains` row
 * @param array $settings domainSettings()
 * @return array{findings:array, score:int, grade:string, ssl_expiry_date:?string,
 *               ssl_issuer:?string, snapshot:array, certificates:array}
 */
function domainRunChecks(array $d, array $settings): array
{
    $name     = (string)$d['domain_name'];
    $purpose  = (string)($d['purpose'] ?? 'primary');
    $resolver = (string)($settings['domain_dns_resolver'] ?? 'auto');
    $sslWarn  = (int)($settings['domain_ssl_warn_days'] ?? 21);
    $f = [];
    $add = function (string $key, string $area, string $level, int $weight = 0, array $params = []) use (&$f) {
        $f[] = ['key' => $key, 'area' => $area, 'level' => $level, 'weight' => in_array($level, ['warn', 'fail'], true) ? $weight : 0, 'params' => $params];
    };
    $isLive    = in_array($purpose, ['primary', 'secondary', 'email_only', 'campaign'], true);
    $isNonMail = domainPurposeIsNonMail($purpose);

    // ------------------------------------------------------------------ registration
    $statuses = array_filter(array_map('trim', explode(',', (string)($d['registry_statuses'] ?? ''))));
    if (in_array('redemption period', $statuses, true) || in_array('pending delete', $statuses, true)) {
        $add('reg_redemption', 'registration', 'fail', 40);
    }

    $today = gmdate('Y-m-d');
    if (!empty($d['expiry_date'])) {
        $days = (int)floor((strtotime($d['expiry_date'] . ' 00:00:00 UTC') - strtotime($today . ' 00:00:00 UTC')) / 86400);
        if ($days < 0)        $add('reg_expired', 'registration', 'fail', 40, ['date' => $d['expiry_date'], 'days' => -$days]);
        elseif ($days <= 30)  $add('reg_expiring', 'registration', 'warn', 8, ['date' => $d['expiry_date'], 'days' => $days]);
        else                  $add('reg_expiry_ok', 'registration', 'pass', 0, ['date' => $d['expiry_date'], 'days' => $days]);
    } else {
        $add('reg_expiry_unknown', 'registration', 'info');
    }

    if ($d['transfer_lock'] === null && domainTransferLockNotApplicable($name)) $add('reg_transfer_uk', 'registration', 'info');
    elseif ($d['transfer_lock'] === null || $d['transfer_lock'] === '') $add('reg_transfer_unknown', 'registration', 'info');
    elseif ((int)$d['transfer_lock'] === 1)                          $add('reg_transfer_locked', 'registration', 'pass');
    else                                                             $add('reg_transfer_unlocked', 'registration', 'fail', 12);

    if ((int)($d['registry_lock'] ?? 0) === 1)  $add('reg_registry_locked', 'registration', 'pass');
    elseif ($purpose === 'primary')             $add('reg_registry_lock_suggest', 'registration', 'info');

    $renew = (string)($d['renewal_mode'] ?? 'unknown');
    if ($renew === 'auto')          $add('renew_auto', 'registration', 'pass');
    elseif ($renew === 'manual')    $add('renew_manual', 'registration', 'info');
    elseif ($renew === 'unknown')   $add('renew_unknown', 'registration', 'warn', 3);

    // ------------------------------------------------------------------ DNS
    $nsQ = domainDns($name, 'NS', $resolver);
    $dnsNs = $nsQ['records'];
    if ($nsQ['nxdomain']) {
        $add('dns_nxdomain', 'dns', 'fail', 25);
    } elseif ($nsQ['ok'] && !$dnsNs) {
        $add('dns_no_ns', 'dns', 'fail', 20);
    } elseif ($dnsNs) {
        if (count($dnsNs) < 2) $add('dns_single_ns', 'dns', 'warn', 5, ['ns' => implode(', ', $dnsNs)]);
        else                   $add('dns_ns_ok', 'dns', 'pass', 0, ['count' => count($dnsNs)]);
        $regNs = array_values(array_filter(array_map(fn($x) => strtolower(rtrim(trim($x), '.')), preg_split('/\R/', (string)($d['nameservers'] ?? '')))));
        sort($regNs);
        if ($regNs && array_diff($regNs, $dnsNs) && array_diff($dnsNs, $regNs)) {
            $add('dns_ns_mismatch', 'dns', 'warn', 5, ['registry' => implode(', ', $regNs), 'dns' => implode(', ', $dnsNs)]);
        }
    }

    // DNSSEC — the registry's word first, a DS lookup when it gave none.
    $dnssec = ($d['dnssec'] === null || $d['dnssec'] === '') ? null : (int)$d['dnssec'] === 1;
    if ($dnssec === null) {
        $ds = domainDns($name, 'DS', $resolver === 'system' ? 'google' : $resolver);
        if ($ds['ok']) $dnssec = (bool)$ds['records'];
    }
    if ($dnssec === true)                         $add('dnssec_on', 'dns', 'pass');
    elseif ($dnssec === false && $isLive)         $add('dnssec_off', 'dns', 'warn', 4);
    elseif ($dnssec === false)                    $add('dnssec_off', 'dns', 'info');

    $caa = domainDns($name, 'CAA', $resolver);
    if ($caa['ok'] && $caa['records']) {
        $issuers = [];
        foreach ($caa['records'] as $c) if (in_array($c[1], ['issue', 'issuewild'], true)) $issuers[] = $c[2];
        $add('caa_present', 'dns', 'pass', 0, ['issuers' => implode(', ', array_unique($issuers)) ?: '-']);
    } elseif ($caa['ok'] && in_array($purpose, ['primary', 'secondary'], true)) {
        $add('caa_missing', 'dns', 'warn', 2);
    }

    // ------------------------------------------------------------------ email
    $mxQ = domainDns($name, 'MX', $resolver);
    $mx = $mxQ['records'];
    $nullMx = count($mx) === 1 && $mx[0][1] === '';
    $mxHosts = $nullMx ? [] : array_map(fn($m) => $m[1], $mx);
    $spfs  = domainTxtStartingWith($name, 'v=spf1', $resolver);
    $dmarcs = domainTxtStartingWith('_dmarc.' . $name, 'v=DMARC1', $resolver);
    $dmarc = $dmarcs ? domainParseTags($dmarcs[0]) : [];

    if ($isNonMail) {
        // Lock-down: this name should never send or receive mail.
        if ($mxHosts) $add('lockdown_mx', 'email', 'warn', 6, ['mx' => implode(', ', $mxHosts)]);
        else          $add('lockdown_mx_ok', 'email', 'pass', 0, ['null' => $nullMx ? 1 : 0]);

        $spfLocked = count($spfs) === 1 && preg_match('/^v=spf1\s+-all\s*$/i', trim($spfs[0]));
        if ($spfLocked) $add('lockdown_spf_ok', 'email', 'pass');
        else            $add('lockdown_spf', 'email', 'fail', 10, ['current' => $spfs ? implode(' | ', $spfs) : '']);

        if (strtolower($dmarc['p'] ?? '') === 'reject') $add('lockdown_dmarc_ok', 'email', 'pass');
        else                                            $add('lockdown_dmarc', 'email', 'fail', 10, ['current' => $dmarc['p'] ?? '', 'domain' => $name]);
    } else {
        if ($nullMx)       $add('mx_null', 'email', 'info');
        elseif ($mxHosts)  $add('mx_present', 'email', 'pass', 0, ['mx' => implode(', ', array_slice($mxHosts, 0, 4))]);
        else               $add('mx_none', 'email', 'info');

        // SPF
        if (!$spfs) {
            $add('spf_missing', 'email', 'fail', 10);
        } elseif (count($spfs) > 1) {
            $add('spf_multiple', 'email', 'fail', 10, ['count' => count($spfs)]);
        } else {
            $spf = trim($spfs[0]);
            if (preg_match('/(^|\s)\+?all(\s|$)/i', $spf) && !preg_match('/[-~?]all/i', $spf)) {
                $add('spf_pass_all', 'email', 'fail', 15, ['record' => $spf]);
            } elseif (preg_match('/-all\s*$/i', $spf)) {
                $add('spf_hardfail', 'email', 'pass', 0, ['record' => $spf]);
            } elseif (preg_match('/~all\s*$/i', $spf)) {
                $add('spf_softfail', 'email', 'pass', 0, ['record' => $spf]);
            } else {
                $add('spf_neutral', 'email', 'warn', 6, ['record' => $spf]);
            }
            $broken = [];
            $lookups = domainSpfLookupCount($spf, $resolver, 0, $broken);
            if ($lookups > 10) $add('spf_too_many_lookups', 'email', 'fail', 8, ['count' => $lookups]);
            foreach (array_slice($broken, 0, 3) as $b) $add('spf_broken_include', 'email', 'warn', 4, ['name' => $b]);
        }

        // DMARC
        if (!$dmarcs) {
            $add('dmarc_missing', 'email', 'fail', 12, ['domain' => $name]);
        } elseif (count($dmarcs) > 1) {
            $add('dmarc_multiple', 'email', 'fail', 10);
        } else {
            $p = strtolower($dmarc['p'] ?? '');
            if ($p === 'reject')          $add('dmarc_reject', 'email', 'pass');
            elseif ($p === 'quarantine')  $add('dmarc_quarantine', 'email', 'pass');
            else                          $add('dmarc_monitor_only', 'email', 'warn', 8, ['policy' => $p ?: 'none']);
            if (isset($dmarc['pct']) && (int)$dmarc['pct'] < 100) $add('dmarc_partial', 'email', 'warn', 3, ['pct' => (int)$dmarc['pct']]);
            if (empty($dmarc['rua'])) $add('dmarc_no_reports', 'email', 'info');
        }

        // DKIM — selectors cannot be listed, so the ones on the record are
        // checked, and a few common defaults are tried quietly when there are none.
        $configured = array_values(array_filter(array_map('trim', preg_split('/[\s,;]+/', (string)($d['dkim_selectors'] ?? '')))));
        if ($configured) {
            foreach ($configured as $sel) {
                $k = domainTxtStartingWith(strtolower($sel) . '._domainkey.' . $name, 'v=DKIM1', $resolver)
                  ?: domainTxtStartingWith(strtolower($sel) . '._domainkey.' . $name, 'k=', $resolver)
                  ?: domainTxtStartingWith(strtolower($sel) . '._domainkey.' . $name, 'p=', $resolver);
                if ($k) $add('dkim_found', 'email', 'pass', 0, ['selector' => $sel]);
                else    $add('dkim_selector_missing', 'email', 'warn', 5, ['selector' => $sel]);
            }
        } elseif ($mxHosts || $spfs) {
            $found = null;
            // Microsoft 365, Google Workspace, generic, Mailchimp, and two common
            // defaults. A CNAME-published key is followed by the TXT query itself.
            foreach (['selector1', 'google', 'default', 'k1', 's1', 'dkim'] as $sel) {
                $txt = domainDns($sel . '._domainkey.' . $name, 'TXT', $resolver);
                if ($txt['ok'] && $txt['records']) { $found = $sel; break; }
            }
            if ($found) $add('dkim_found', 'email', 'pass', 0, ['selector' => $found]);
            else        $add('dkim_unknown', 'email', 'info');
        }

        if ($mxHosts) {
            if (domainTxtStartingWith('_mta-sts.' . $name, 'v=STSv1', $resolver)) $add('mta_sts_on', 'email', 'pass');
            else $add('mta_sts_off', 'email', 'info');
            if (domainTxtStartingWith('_smtp._tls.' . $name, 'v=TLSRPTv1', $resolver)) $add('tls_rpt_on', 'email', 'pass');
            else $add('tls_rpt_off', 'email', 'info');
        }
    }

    // ------------------------------------------------------------------ web / certificates
    $hosts = array_merge([$name, 'www.' . $name], domainParseSslHosts($d['ssl_hosts'] ?? '', $name));
    $soonest = null; $soonestIssuer = null; $certs = [];
    foreach ($hosts as $i => $h) {
        $isExtra = $i >= 2;
        $a  = domainDns($h, 'A', $resolver);
        $aa = domainDns($h, 'AAAA', $resolver);
        if (!($a['records'] || $aa['records'])) {
            if ($isExtra) $add('web_no_host', 'web', 'warn', 3, ['host' => $h]);
            continue;
        }
        $c = domainTlsCertificate($h);
        $certs[] = $c;
        $wantsWeb = !in_array($purpose, ['email_only', 'defensive', 'parked'], true) || $isExtra;
        if (!$c['ok']) {
            $add('tls_unavailable', 'web', $wantsWeb ? 'warn' : 'info', 6, ['host' => $h, 'error' => (string)$c['error']]);
            continue;
        }
        if ($c['days_left'] !== null && $c['days_left'] < 0) {
            $add('tls_expired', 'web', 'fail', 15, ['host' => $h, 'date' => $c['valid_to']]);
        } elseif ($c['days_left'] !== null && $c['days_left'] <= $sslWarn) {
            $add('tls_expiring', 'web', 'warn', 6, ['host' => $h, 'date' => $c['valid_to'], 'days' => $c['days_left']]);
        }
        if ($c['hostname_match'] === false) {
            $add('tls_name_mismatch', 'web', 'fail', 10, ['host' => $h, 'names' => implode(', ', array_slice($c['sans'], 0, 5))]);
        } elseif ($c['chain_valid'] === false) {
            $add('tls_untrusted', 'web', 'warn', 8, ['host' => $h, 'issuer' => (string)$c['issuer']]);
        } elseif ($c['days_left'] !== null && $c['days_left'] > $sslWarn) {
            $add('tls_ok', 'web', 'pass', 0, ['host' => $h, 'date' => $c['valid_to'], 'issuer' => (string)$c['issuer']]);
        }
        if ($c['valid_to'] && ($soonest === null || $c['valid_to'] < $soonest)) {
            $soonest = $c['valid_to'];
            $soonestIssuer = $c['issuer'];
        }
    }

    // ------------------------------------------------------------------ grade
    [$score, $grade] = domainGrade($f);

    return [
        'findings'        => $f,
        'score'           => $score,
        'grade'           => $grade,
        'ssl_expiry_date' => $soonest,
        'ssl_issuer'      => $soonestIssuer,
        'certificates'    => $certs,
        // What change detection compares tomorrow (see monitor.php).
        'snapshot'        => [
            'dns_ns' => $dnsNs,
            'mx'     => $mxHosts,
            'spf'    => $spfs ? trim($spfs[0]) : '',
            'dmarc'  => $dmarcs ? trim($dmarcs[0]) : '',
        ],
    ];
}

/** @return array{0:int,1:string} */
function domainGrade(array $findings): array
{
    $score = 100;
    $fatal = false;
    foreach ($findings as $x) {
        $score -= (int)$x['weight'];
        if (in_array($x['key'], ['reg_expired', 'reg_redemption', 'dns_nxdomain'], true)) $fatal = true;
    }
    $score = max(0, min(100, $score));
    if ($fatal)            $grade = 'F';
    elseif ($score >= 100) $grade = 'A+';
    elseif ($score >= 90)  $grade = 'A';
    elseif ($score >= 80)  $grade = 'B';
    elseif ($score >= 65)  $grade = 'C';
    elseif ($score >= 50)  $grade = 'D';
    else                   $grade = 'F';
    return [$score, $grade];
}

/** "v=DMARC1; p=reject; rua=mailto:x" → ['v'=>'DMARC1','p'=>'reject','rua'=>'mailto:x'] */
function domainParseTags(string $record): array
{
    $out = [];
    foreach (explode(';', $record) as $part) {
        $kv = explode('=', trim($part), 2);
        if (count($kv) === 2) $out[strtolower(trim($kv[0]))] = trim($kv[1]);
    }
    return $out;
}

/**
 * How many DNS lookups evaluating this SPF record costs (RFC 7208 §4.6.4 caps it
 * at 10; past that, receivers treat the record as a permanent error — i.e. SPF
 * silently stops protecting the domain). Follows include: and redirect=.
 *
 * @param array $broken collects include/redirect targets that have no SPF record
 */
function domainSpfLookupCount(string $spf, string $resolver, int $depth, array &$broken, array &$seen = []): int
{
    if ($depth > 10) return 0;
    $count = 0;
    foreach (preg_split('/\s+/', trim($spf)) as $term) {
        $t = strtolower(ltrim($term, '+-~?'));
        if (preg_match('/^(a|mx|ptr|exists)([:\/]|$)/', $t)) {
            $count++;
        } elseif (preg_match('/^(include:|redirect=)(.+)$/', $t, $m)) {
            $count++;
            $target = rtrim($m[2], '.');
            if (strpos($target, '%') !== false || isset($seen[$target])) continue;   // macros: cannot follow
            $seen[$target] = true;
            $sub = domainTxtStartingWith($target, 'v=spf1', $resolver);
            if (!$sub) { $broken[] = $target; continue; }
            $count += domainSpfLookupCount($sub[0], $resolver, $depth + 1, $broken, $seen);
        }
    }
    return $count;
}
