<?php
/* 🔴 COMMAND LINE ONLY - see scripts/.htaccess. Over HTTP this would run for anyone, unauthenticated. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * Verify, then merge — and merge ONLY what verified.
 *
 *   php scripts/i18n_gated_merge.php <locale> <chunkDir>
 *   php scripts/i18n_gated_merge.php nn C:/tmp/i18n-w3/chunks --dry-run
 *
 * ──────────────────────────────────────────────────────────────────────────
 * 🔴 WHY THIS EXISTS, WHEN THE TWO SCRIPTS IT CALLS ALREADY DID THE JOB.
 *
 * Because the orchestrator ran them as `verify; merge` instead of
 * `verify && merge`, and a chunk that FAILED verification was merged anyway.
 * The harm that time was nil — the failure was a benign key reordering, and
 * i18n_merge.php maps by key rather than position — but that was luck, not
 * design. The whole safety argument for letting agents loose on languages
 * nobody here reads is that a mechanical gate stands between their output and
 * lang/. A gate that depends on remembering a shell operator is not a gate.
 *
 * So the two steps are welded together here. It verifies every <locale> chunk
 * in the directory, merges only the ones that passed, and names the ones it
 * refused. It cannot be invoked in a way that merges an unverified chunk.
 * ──────────────────────────────────────────────────────────────────────────
 *
 * The namespace is taken from the chunk filename: <locale>__<ns>__<section>.
 * Run i18n_repair_chunk.php first if the chunks predate the blank-key change.
 */

$root = dirname(__DIR__);
chdir($root);

$args   = array_slice($argv, 1);
$dryRun = in_array('--dry-run', $args, true);
$args   = array_values(array_filter($args, fn($a) => $a !== '--dry-run'));

if (count($args) < 2) {
    fwrite(STDERR, "usage: i18n_gated_merge.php <locale> <chunkDir> [--dry-run]\n");
    exit(2);
}
[$loc, $dir] = $args;
$dir = rtrim($dir, '/\\');

if (!is_dir("lang/$loc")) { fwrite(STDERR, "no such locale dir: lang/$loc\n"); exit(2); }
if (!is_dir($dir))        { fwrite(STDERR, "no such chunk dir: $dir\n"); exit(2); }

$outs = glob("$dir/{$loc}__*.{$loc}.tsv");
sort($outs);
if (!$outs) { fwrite(STDERR, "no translated chunks for $loc in $dir\n"); exit(2); }

$passed = $failed = $merged = $refused = 0;
$added = 0;
$problems = [];

foreach ($outs as $out) {
    $base = basename($out);
    $en   = preg_replace('/\.' . preg_quote($loc, '/') . '\.tsv$/', '.en.tsv', $out);

    if (!is_file($en)) { $problems[] = "$base — no English source"; $failed++; continue; }

    // The namespace sits between the first and second "__".
    if (!preg_match('/^' . preg_quote($loc, '/') . '__(.+?)__/', $base, $m)) {
        $problems[] = "$base — cannot read namespace from filename";
        $failed++;
        continue;
    }
    $ns = $m[1];

    exec(sprintf('php %s %s %s --quiet 2>&1',
        escapeshellarg(__DIR__ . '/i18n_verify_chunk.php'),
        escapeshellarg($en), escapeshellarg($out)), $vOut, $vCode);

    if ($vCode !== 0) {
        $failed++;
        $problems[] = "$base — FAILED verification, NOT merged:\n      " . implode("\n      ", $vOut);
        $vOut = [];
        continue;
    }
    $vOut = [];
    $passed++;

    exec(sprintf('php %s %s %s %s 2>&1',
        escapeshellarg(__DIR__ . '/i18n_merge.php'),
        escapeshellarg($loc), escapeshellarg($ns), escapeshellarg($out)
        . ($dryRun ? ' --dry-run' : '')), $mOut, $mCode);

    $line = trim(implode(' ', array_filter($mOut, fn($l) => str_contains($l, 'MERGED') || str_contains($l, 'DRY') || str_contains($l, 'REFUSED'))));
    if ($mCode === 0 && !str_contains($line, 'REFUSED')) {
        $merged++;
        if (preg_match('/\+(\d+) key/', $line, $km)) $added += (int)$km[1];
        printf("  %-52s %s\n", $base, $line ?: 'merged');
    } else {
        $refused++;
        $problems[] = "$base — merge refused:\n      " . implode("\n      ", $mOut);
    }
    $mOut = [];
}

printf("\n%s: %d verified, %d merged (+%d keys)%s\n",
    $loc, $passed, $merged, $added, $dryRun ? ' [dry run]' : '');

if ($problems) {
    printf("\n🔴 %d chunk(s) NOT merged:\n", $failed + $refused);
    foreach ($problems as $p) echo "  - $p\n";
    exit(1);
}
echo "every chunk verified before it was merged.\n";
exit(0);
