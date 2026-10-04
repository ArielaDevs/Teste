<?php
/* 🔴 COMMAND LINE ONLY - see scripts/.htaccess. Over HTTP this would run for anyone, unauthenticated. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * i18n gate — catch English keys added since a baseline that no maintained locale has.
 *
 *   php scripts/i18n_gate.php                   since the last release tag
 *   php scripts/i18n_gate.php --since HEAD~1    since the previous commit
 *   php scripts/i18n_gate.php --since v2.2.0    since a named tag
 *   php scripts/i18n_gate.php --keys            list every offending key
 *   php scripts/i18n_gate.php --warn            report but always exit 0
 *
 * Exit 0 = nothing added, or everything added has been translated. Exit 1 = a gap
 * was introduced. Exit 2 = bad usage or git unavailable.
 *
 * ──────────────────────────────────────────────────────────────────────────
 * WHY THIS EXISTS
 *
 * `i18n_audit.php` answers "how far behind is this locale?". That is the wrong
 * question to ask at commit time, because the answer is always "somewhat" and a
 * number that is always bad is a number nobody reads.
 *
 * This asks the one question that has a correct answer of zero: "did THIS change
 * make things worse?" A feature that adds thirty English strings silently knocks
 * every locale off parity, and nothing anywhere reports it — the fallback is
 * per-key and silent, so the new screen renders in English and looks finished.
 *
 * Measured on 2026-09-22: da, de, hi and pl had each been swept to 100% and had
 * all drifted back to 95.8%, every one of them missing THE SAME 347 keys. Those
 * keys were not a translation failure. They were features shipping without their
 * twins, five separate times, and being rediscovered by a full audit each time.
 * ──────────────────────────────────────────────────────────────────────────
 *
 * 🔑 WHY IT GATES ONLY THE *MAINTAINED* LOCALES, AND WHY THAT SET IS COMPUTED
 *
 * Thirteen locales currently sit around 35%. Demanding that a new key be present
 * in all 24 would fail on every commit, and a check that always fails is a check
 * that gets bypassed — which is how the real mismatch it would eventually catch
 * gets bypassed with it.
 *
 * So the gate applies to locales that were ALREADY CURRENT at the baseline, at
 * or above MAINTAINED_PCT of the baseline English. Coverage is measured against
 * the BASELINE, never against today's English — otherwise the new keys would
 * themselves drag a locale below the threshold and out of the set that is
 * supposed to catch them. That circularity would make the gate silently
 * self-disarming, which is worse than not having it.
 *
 * The set is computed rather than hard-coded so it strengthens by itself: a
 * locale swept to 100% joins the gated set on its next commit, with nobody
 * having to remember to add it to a list.
 *
 * ⚠️ It reports keys that are MISSING. It cannot tell you whether a translation
 * that is present is any good — `i18n_verify_chunk.php` checks structure and
 * only a reader of the language checks meaning.
 */

$root    = dirname(__DIR__);
$langDir = $root . '/lang';

/** A locale at or above this share of the BASELINE English is expected to keep up. */
const MAINTAINED_PCT = 95.0;

// The baseline can only come from git, so say so plainly rather than reporting
// "0 keys added" — which is what a disabled shell_exec would otherwise look like,
// and is indistinguishable from a clean run.
if (!function_exists('shell_exec') || in_array('shell_exec', array_map('trim', explode(',', (string) ini_get('disable_functions'))), true)) {
    fwrite(STDERR, "shell_exec is disabled, so the baseline cannot be read from git. This script is a development tool, not something the application runs.\n");
    exit(2);
}

$args     = array_slice($argv, 1);
$showKeys = in_array('--keys', $args, true);
$warnOnly = in_array('--warn', $args, true);

$since = null;
foreach ($args as $i => $a) {
    if ($a === '--since') { $since = $args[$i + 1] ?? null; }
    elseif (str_starts_with($a, '--since=')) { $since = substr($a, 8); }
}

if ($since === null) {
    $since = trim((string) @shell_exec('git -C ' . escapeshellarg($root) . ' describe --tags --abbrev=0 2>&1'));
    if ($since === '' || str_contains($since, 'fatal')) {
        fwrite(STDERR, "No release tag found. Pass --since <ref> explicitly.\n");
        exit(2);
    }
}

/** Flatten a nested lang array into dot paths. */
function flatten(array $a, string $prefix = ''): array
{
    $out = [];
    foreach ($a as $k => $v) {
        $key = $prefix === '' ? (string) $k : $prefix . '.' . $k;
        if (is_array($v)) { $out += flatten($v, $key); } else { $out[$key] = (string) $v; }
    }
    return $out;
}

/**
 * The flattened contents of one lang file as it stood at $ref.
 *
 * ⚠️ Written to a temp file and included rather than eval'd: a lang file at an
 * older ref may be anything at all, and include at least fails the way PHP
 * normally fails. An absent file is an empty array — a namespace that did not
 * exist at the baseline is not a regression, it is a new module.
 */
function atRef(string $root, string $ref, string $relPath): array
{
    $cmd = 'git -C ' . escapeshellarg($root) . ' show ' . escapeshellarg($ref . ':' . $relPath) . ' 2>' . (DIRECTORY_SEPARATOR === '\\' ? 'NUL' : '/dev/null');
    $src = shell_exec($cmd);
    if (!is_string($src) || trim($src) === '') return [];

    $tmp = tempnam(sys_get_temp_dir(), 'i18ngate') . '.php';
    file_put_contents($tmp, $src);
    $data = @include $tmp;
    @unlink($tmp);
    return is_array($data) ? flatten($data) : [];
}

// ---------------------------------------------------------------- English, now and then

$enNs = array_map(fn($f) => basename($f, '.php'), glob($langDir . '/en/*.php'));
sort($enNs);

$enNow = $enBase = [];
foreach ($enNs as $ns) {
    $data = include $langDir . '/en/' . $ns . '.php';
    $enNow[$ns]  = is_array($data) ? flatten($data) : [];
    $enBase[$ns] = atRef($root, $since, 'lang/en/' . $ns . '.php');
}

$added = [];
foreach ($enNs as $ns) {
    foreach ($enNow[$ns] as $k => $v) {
        if (!array_key_exists($k, $enBase[$ns])) $added[] = [$ns, $k];
    }
}

$baseTotal = array_sum(array_map('count', $enBase));

// ---------------------------------------------------------------- who is expected to keep up

$locales = array_values(array_filter(scandir($langDir), function ($d) use ($langDir) {
    return $d !== '.' && $d !== '..' && $d !== 'en' && is_dir($langDir . '/' . $d);
}));
sort($locales);

$maintained = [];
$current    = [];   // locale => [ns => flattened array], read once and reused

foreach ($locales as $loc) {
    $have = [];
    $baseHave = 0;
    foreach ($enNs as $ns) {
        $path = $langDir . '/' . $loc . '/' . $ns . '.php';
        if (!is_file($path)) { $have[$ns] = []; continue; }
        $data = @include $path;
        $have[$ns] = is_array($data) ? flatten($data) : [];
        // coverage measured against the BASELINE English — see the header.
        foreach ($enBase[$ns] as $k => $_) if (array_key_exists($k, $have[$ns])) $baseHave++;
    }
    $current[$loc] = $have;
    $pct = $baseTotal > 0 ? 100 * $baseHave / $baseTotal : 0;
    if ($pct >= MAINTAINED_PCT) $maintained[$loc] = round($pct, 1);
}

// ---------------------------------------------------------------- the finding

printf("baseline          : %s\n", $since);
printf("English keys added: %d\n", count($added));
printf("gated locales     : %s\n",
    $maintained ? implode(', ', array_map(fn($l) => "$l ({$maintained[$l]}%)", array_keys($maintained)))
                : '(none at ' . MAINTAINED_PCT . '% of the baseline — nothing to gate)');

if (!$added) { echo "\nOK — this change added no English strings.\n"; exit(0); }
if (!$maintained) { echo "\nOK — no locale is being kept current, so nothing regressed.\n"; exit(0); }

$gaps = [];   // locale => list of "ns.key"
foreach (array_keys($maintained) as $loc) {
    foreach ($added as [$ns, $k]) {
        if (!array_key_exists($k, $current[$loc][$ns])) $gaps[$loc][] = "$ns.$k";
    }
}

if (!$gaps) {
    printf("\nOK — all %d added strings are present in every gated locale.\n", count($added));
    exit(0);
}

echo "\n";
foreach ($gaps as $loc => $keys) {
    printf("  %-6s %4d of %d added strings missing\n", $loc, count($keys), count($added));
    if ($showKeys) foreach ($keys as $k) printf("           %s\n", $k);
}

// The union is the actual unit of work: one translation per key per locale, but
// the same English is read once, so it is worth saying how many distinct strings
// a person would have to sit down and translate.
$union = [];
foreach ($gaps as $keys) foreach ($keys as $k) $union[$k] = 1;

printf("\n%d distinct strings need translating, %d key-translations in total.\n",
    count($union), array_sum(array_map('count', $gaps)));
echo "Fix: php scripts/i18n_chunk.php " . implode(' ', array_keys($gaps)) . "\n";

exit($warnOnly ? 0 : 1);
