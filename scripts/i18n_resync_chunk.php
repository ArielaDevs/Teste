<?php
/* 🔴 COMMAND LINE ONLY - see scripts/.htaccess. Over HTTP this would run for anyone, unauthenticated. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * Re-apply a CORRECTED chunk over values that were merged before it was fixed.
 *
 *   php scripts/i18n_resync_chunk.php ml <chunkDir> --dry-run
 *   php scripts/i18n_resync_chunk.php ml <chunkDir> --apply
 *
 * Exit 0 = nothing to do, or done. Exit 1 = refused. Exit 2 = bad usage.
 *
 * ──────────────────────────────────────────────────────────────────────────
 * 🔴 WHY THIS IS NOT JUST `i18n_merge.php --force`
 *
 * `i18n_merge.php` NEVER overwrites an existing value, and that rule is load
 * bearing: a locale file holding a partial translation may hold work somebody
 * reviewed by hand, and a merge that could overwrite would put every one of
 * those at the mercy of the next machine-generated pass.
 *
 * But there is one case the rule leaves stranded. A chunk is merged, someone
 * then finds a defect in it, the translator corrects the TSV — and the merge
 * politely declines to apply the correction, because a value is already there.
 * The fix sits on disk and never reaches the product.
 *
 * That happened here: eight Malayalam chunks were merged, a deeper check then
 * found 23 values across two namespaces with HTML tags dropped, invented, or
 * entity-substituted. Six of those chunks had already merged.
 *
 * So this tool evicts exactly the keys whose live value DIFFERS from the
 * corrected chunk, and then the ordinary merge fills them. No new overwrite
 * path is introduced; the general rule stands.
 *
 * ⚠️ THE CHUNK MUST HAVE PASSED `i18n_verify_chunk.php` FIRST. This tool does
 * not judge the translation - it only decides which keys are out of date. Run
 * it through the gate, or you will faithfully install the wrong text.
 *
 * 🔑 It compares the chunk against the LIVE FILE, not against a record of what
 * was merged. There is no state to get out of step: whatever the file says now
 * is what is compared, so running it twice is a no-op.
 */

require_once __DIR__ . '/i18n_lib.php';

$root = dirname(__DIR__);

$loc    = $argv[1] ?? '';
$dir    = $argv[2] ?? '';
$apply  = in_array('--apply', $argv, true);
$dryRun = in_array('--dry-run', $argv, true);

if ($loc === '' || $dir === '' || $apply === $dryRun) {
    fwrite(STDERR, "usage: php scripts/i18n_resync_chunk.php <locale> <chunkDir> --apply|--dry-run\n");
    exit(2);
}
if (!is_dir($dir)) { fwrite(STDERR, "no such chunk directory: $dir\n"); exit(2); }

/** key => value from a translated TSV. */
function resyncRows(string $path): array
{
    $out = [];
    foreach (file($path, FILE_IGNORE_NEW_LINES) as $line) {
        $parts = explode("\t", $line, 2);
        if (count($parts) === 2) $out[$parts[0]] = i18nTsvUnescape($parts[1]);
    }
    return $out;
}

$files = glob(rtrim($dir, '/\\') . "/{$loc}__*.{$loc}.tsv");
if (!$files) { echo "no $loc chunks in $dir\n"; exit(0); }

$stale = [];     // ns => [key => corrected value]
$unverified = [];

foreach ($files as $f) {
    $en = preg_replace('/\.' . preg_quote($loc, '/') . '\.tsv$/', '.en.tsv', $f);
    if (!is_file($en)) { $unverified[] = basename($f) . ' (no English source)'; continue; }

    // 🔴 Refuse a chunk the gate has not passed. Installing a correction that
    // was never verified is how a fix becomes the next defect.
    $cmd = 'php ' . escapeshellarg(__DIR__ . '/i18n_verify_chunk.php')
         . ' ' . escapeshellarg($en) . ' ' . escapeshellarg($f) . ' --quiet';
    exec($cmd, $out, $rc);
    if ($rc !== 0) { $unverified[] = basename($f) . ' (FAILS verification)'; continue; }

    if (!preg_match('#' . preg_quote($loc, '#') . '__([A-Za-z0-9_-]+)__#', basename($f), $m)) continue;
    $ns   = $m[1];
    $path = "$root/lang/$loc/$ns.php";
    if (!is_file($path)) continue;

    $live = i18nFlatten(i18nLoad($path));
    foreach (resyncRows($f) as $k => $v) {
        if (array_key_exists($k, $live) && $live[$k] !== $v) $stale[$ns][$k] = $v;
    }
}

if ($unverified) {
    fwrite(STDERR, "🔴 REFUSED - these chunks are not safe to resync:\n  " . implode("\n  ", $unverified) . "\n");
    exit(1);
}

$total = array_sum(array_map('count', $stale));
if (!$total) { echo "nothing stale - every merged value already matches its chunk\n"; exit(0); }

echo ($apply ? 'EVICTING' : 'WOULD EVICT') . " $total stale value(s) in $loc:\n";
foreach ($stale as $ns => $keys) {
    printf("  %-18s %d\n", $ns, count($keys));
    foreach (array_keys($keys) as $k) echo "      $k\n";
}

if (!$apply) { echo "\nRe-run with --apply, then merge the chunks again.\n"; exit(0); }

foreach ($stale as $ns => $keys) {
    $path   = "$root/lang/$loc/$ns.php";
    $before = i18nFlatten(i18nLoad($path));
    $after  = $before;
    foreach (array_keys($keys) as $k) unset($after[$k]);

    // Same proof the eviction tool makes: only the intended keys may vanish,
    // and nothing else may change by so much as a byte.
    $vanished   = array_keys(array_diff_key($before, $after));
    $unexpected = array_diff($vanished, array_keys($keys));
    $mutated    = [];
    foreach ($after as $k => $v) if (!array_key_exists($k, $before) || $before[$k] !== $v) $mutated[] = $k;

    if ($unexpected || $mutated) {
        fwrite(STDERR, "🔴 $loc/$ns refused: " . count($unexpected) . " unexpected, " . count($mutated) . " mutated\n");
        exit(1);
    }

    $src    = (string) file_get_contents($path);
    $header = preg_match('#^<\?php\s*(/\*\*.*?\*/\n)#s', $src, $hm) ? $hm[1] : '';
    file_put_contents($path, i18nEmitPhpFile(i18nUnflatten($after), $header));
}

echo "\nEvicted. Now re-run the merge to install the corrected text:\n";
echo "  php scripts/i18n_gated_merge.php $loc " . escapeshellarg($dir) . "\n";
exit(0);
