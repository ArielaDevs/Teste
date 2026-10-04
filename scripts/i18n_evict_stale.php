<?php
/* 🔴 COMMAND LINE ONLY - see scripts/.htaccess. Over HTTP this would run for anyone, unauthenticated. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * Remove translations whose ENGLISH SOURCE HAS CHANGED, so the pipeline can refill them.
 *
 *   php scripts/i18n_evict_stale.php --since HEAD~1 --ns tickets,checklists --dry-run
 *   php scripts/i18n_evict_stale.php --since v2.4.0 --ns tickets
 *   php scripts/i18n_evict_stale.php --since f521fdef^ --ns tickets,checklists --apply
 *
 * Exit 0 = nothing to do, or done. Exit 1 = a locale was left unchanged because
 * the proof failed. Exit 2 = bad usage or git unavailable.
 *
 * ──────────────────────────────────────────────────────────────────────────
 * 🔴 WHY THIS EXISTS — THE GAP THE REST OF THE PIPELINE CANNOT SEE
 *
 * `i18n_audit.php` counts keys a locale LACKS. `i18n_merge.php` never overwrites
 * a value that is already there. Both are right, and together they are blind to
 * the commonest way a finished locale goes wrong:
 *
 *     English changes, and the translation of the OLD English stays behind.
 *
 * The key is present, so nothing is missing. The value is non-empty, so nothing
 * is blank. Coverage still reads 100%. The screen is simply wrong, in a language
 * the person who changed the English does not read.
 *
 * It happened at scale here: renaming "SOP checklist" to "Checklist" across the
 * product changed 33 English values that twelve locales had already translated.
 * Every one of those locales went on saying "SOP-Checkliste", and every tool in
 * this directory reported them complete.
 *
 * ⚠️ THE ONLY SAFE FIX IS TO DELETE THE STALE TRANSLATION. A missing key falls
 * back to English, which is readable; a stale key renders confidently wrong text
 * and nothing will ever flag it. So this tool evicts, and the normal chunk →
 * translate → verify → merge path refills.
 *
 * ──────────────────────────────────────────────────────────────────────────
 * ⚠️ IT REWRITES THE WHOLE FILE, AND THAT IS NOT FREE.
 *
 * Every file it touches is re-emitted from the parsed array, so it comes back
 * with the COMMENTS GONE and the `=>` alignment re-normalised. Evicting one
 * key from 24 locale files on 2026-09-23 produced a 3,191-insertion /
 * 3,463-deletion diff and stripped every explanatory comment those files had.
 *
 * 🔑 Its self-proof did not catch that, and could not: the proof compares
 * VALUES, and the values were all correct. What was lost was everything around
 * them. A check that only looks where you expect the damage will always say
 * the damage did not happen.
 *
 * So: for a SMALL eviction - one key, a handful of locales - remove the line
 * in place instead and leave the rest of the file byte-identical. Use this
 * tool when the eviction is large enough that a reformat is the lesser cost,
 * and read the diffstat before you commit either way.
 *
 * 🔴 IT DELETES REAL TRANSLATIONS, SO IT PROVES ITSELF BEFORE WRITING
 *
 * For every locale it flattens the file before and after and asserts:
 *
 *   - every key that disappeared is one it MEANT to evict, and
 *   - every key that remains is BYTE-IDENTICAL to what it was.
 *
 * If either fails the file is not written and the run exits non-zero. This is
 * the same shape as the merge tool's own non-destruction proof, for the same
 * reason: nobody will read 30,000 keys to check.
 *
 * ⚠️ A key whose English merely gained or lost whitespace is NOT evicted —
 * trimming a trailing space does not invalidate a translation, and evicting on
 * it would throw away good work every time somebody reformats a file.
 */

require_once __DIR__ . '/i18n_lib.php';

$root = dirname(__DIR__);

// ---------------------------------------------------------------- arguments
$since = null;
$namespaces = [];
$apply = false;
$dryRun = false;

foreach ($argv as $i => $a) {
    if ($a === '--since' && isset($argv[$i + 1])) { $since = $argv[$i + 1]; }
    if ($a === '--ns'    && isset($argv[$i + 1])) { $namespaces = array_filter(explode(',', $argv[$i + 1])); }
    if ($a === '--apply')   { $apply = true; }
    if ($a === '--dry-run') { $dryRun = true; }
}

if ($since === null || !$namespaces) {
    fwrite(STDERR, "usage: php scripts/i18n_evict_stale.php --since <rev> --ns <a,b> [--apply|--dry-run]\n");
    exit(2);
}
if ($apply === $dryRun) {
    fwrite(STDERR, "choose exactly one of --apply or --dry-run\n");
    exit(2);
}

/** The English file as it stood at a revision, flattened. Empty if it did not exist. */
function enAtRevision(string $root, string $rev, string $ns): array
{
    $cmd = 'git -C ' . escapeshellarg($root) . ' show ' . escapeshellarg("$rev:lang/en/$ns.php") . ' 2>&1';
    $php = shell_exec($cmd);
    if (!is_string($php) || strpos(ltrim($php), '<?php') !== 0) return [];

    $tmp = tempnam(sys_get_temp_dir(), 'i18nev') . '.php';
    file_put_contents($tmp, $php);
    $arr = @include $tmp;
    unlink($tmp);
    return is_array($arr) ? i18nFlatten($arr) : [];
}

// ---------------------------------------------------------------- what is stale
$stale = [];      // ns => [key => ['old' => ..., 'new' => ...]]
$orphan = [];     // ns => [key, ...]   English no longer has it at all

foreach ($namespaces as $ns) {
    $old = enAtRevision($root, $since, $ns);
    if (!$old) {
        fwrite(STDERR, "cannot read lang/en/$ns.php at $since - is the revision right?\n");
        exit(2);
    }
    $new = i18nFlatten(i18nLoad("$root/lang/en/$ns.php"));

    foreach ($new as $k => $v) {
        // ⚠️ Compared trimmed: reformatting is not a change of meaning.
        if (isset($old[$k]) && trim($old[$k]) !== trim($v)) {
            $stale[$ns][$k] = ['old' => $old[$k], 'new' => $v];
        }
    }
    foreach ($old as $k => $v) {
        if (!array_key_exists($k, $new)) $orphan[$ns][] = $k;
    }
}

$totalStale  = array_sum(array_map('count', $stale));
$totalOrphan = array_sum(array_map('count', $orphan));

echo "English changed since {$since}\n";
foreach ($namespaces as $ns) {
    printf("  %-12s %d value(s) changed, %d key(s) removed\n",
        $ns, count($stale[$ns] ?? []), count($orphan[$ns] ?? []));
}
if (!$totalStale && !$totalOrphan) { echo "\nNothing to evict.\n"; exit(0); }

// ---------------------------------------------------------------- evict
$locales = array_values(array_filter(scandir("$root/lang"), function ($d) use ($root) {
    return $d !== '.' && $d !== '..' && $d !== 'en' && is_dir("$root/lang/$d");
}));

$failures = 0;
$touched  = 0;
$evicted  = 0;

echo "\n";
printf("%-8s %-10s %s\n", 'locale', 'namespace', 'evicted');

foreach ($locales as $loc) {
    foreach ($namespaces as $ns) {
        $path = "$root/lang/$loc/$ns.php";
        if (!is_file($path)) continue;

        $tree   = i18nLoad($path);
        $before = i18nFlatten($tree);

        $targets = array_merge(
            array_keys($stale[$ns] ?? []),
            $orphan[$ns] ?? []
        );
        $hit = array_values(array_intersect($targets, array_keys($before)));
        if (!$hit) continue;

        $after = $before;
        foreach ($hit as $k) unset($after[$k]);

        // ---- the proof, before anything is written ----
        $vanished = array_keys(array_diff_key($before, $after));
        $unexpected = array_diff($vanished, $hit);
        $mutated = [];
        foreach ($after as $k => $v) {
            if (!array_key_exists($k, $before) || $before[$k] !== $v) $mutated[] = $k;
        }

        if ($unexpected || $mutated) {
            printf("%-8s %-10s REFUSED - %d unexpected removal(s), %d mutation(s)\n",
                $loc, $ns, count($unexpected), count($mutated));
            $failures++;
            continue;
        }

        printf("%-8s %-10s %d\n", $loc, $ns, count($hit));
        $evicted += count($hit);
        $touched++;

        if ($apply) {
            $header = i18nFileHeader($path);
            file_put_contents($path, i18nEmitPhpFile(i18nUnflatten($after), $header));

            // Re-read from disk: the proof above was on an in-memory array, and
            // the thing that ships is the file.
            $reread = i18nFlatten(i18nLoad($path));
            if (array_keys($reread) !== array_keys($after)) {
                fwrite(STDERR, "  🔴 $loc/$ns re-read does not match what was written\n");
                $failures++;
            }
        }
    }
}

echo "\n" . ($apply ? "EVICTED" : "WOULD EVICT") . ": $evicted key(s) across $touched file(s)\n";
if ($failures) {
    echo "🔴 $failures file(s) refused or failed re-read - fix before continuing\n";
    exit(1);
}
echo $apply
    ? "Now rebuild chunks and translate: the evicted keys are ordinary gaps again.\n"
    : "Re-run with --apply to write.\n";
exit(0);

/**
 * Keep whatever header comment the locale file already carries.
 *
 * ⚠️ The trailing newline is part of the contract. i18nEmitPhpFile() writes
 * "<?php\n" . $header . "\nreturn ...", and i18n_merge.php passes a header that
 * ends in one — so returning the comment without it emits a file one byte
 * different from every other tool's output. That was caught by round-tripping
 * ten untouched locale files and finding all ten off by exactly one byte: worth
 * doing, because a reformatting diff would have buried the real change.
 */
function i18nFileHeader(string $path): string
{
    $src = (string)file_get_contents($path);
    if (preg_match('#^<\?php\s*(/\*\*.*?\*/\n)#s', $src, $m)) return $m[1];
    return '';
}
