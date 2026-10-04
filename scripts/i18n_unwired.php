<?php
/* 🔴 COMMAND LINE ONLY - see scripts/.htaccess. Over HTTP this would run for anyone, unauthenticated. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * Find user-facing screens that render text but never call t().
 *
 *   php scripts/i18n_unwired.php            report, exit 1 if anything is unwired
 *   php scripts/i18n_unwired.php --all      list every scanned file and its score
 *
 * ──────────────────────────────────────────────────────────────────────────
 * 🔴 THE DEFECT THIS CATCHES IS INVISIBLE TO EVERY OTHER CHECK WE HAVE.
 *
 * `i18n_audit.php` compares each locale against `lang/en`. That is the right
 * question and it cannot see this one, because the failure is a gap in
 * `lang/en` ITSELF — a screen whose strings were never added to it.
 *
 * Found in the running product by the maintainer, not by tooling: the Spanish
 * Checklists screen was in English while the audit reported Spanish at 100%.
 * Both facts were true. `lang/en/checklists.php` held 70 help-page keys and 3
 * nav items, and the module's own screens — 2,011 lines across four files —
 * contained no t() call at all. Its help page had been internationalised and
 * the module never had been, so all 24 locales rendered it in English.
 *
 * 🔑 A locale cannot be more complete than its source. Coverage of `lang/en`
 * says nothing about coverage of the PRODUCT, and only this check asks that.
 * ──────────────────────────────────────────────────────────────────────────
 *
 * ⚠️ IT FAILS ONLY ON THE UNAMBIGUOUS CASE: a file with several visible
 * strings and ZERO t() calls. A half-wired file is reported as a warning, not
 * a failure, because "some strings are hardcoded" is a judgement about which
 * ones, and a checker that argues about judgement gets switched off. Prefer
 * missing an exotic case to crying wolf on a common one.
 */

$root = dirname(__DIR__);
chdir($root);

$all  = in_array('--all', array_slice($argv, 1), true);

/** --list=<path> prints one file's visible strings instead of scanning the tree. */
$list = '';
foreach (array_slice($argv, 1) as $a) {
    if (str_starts_with($a, '--list=')) $list = substr($a, 7);
}

/** A file needs at least this many visible strings before a zero score is damning. */
const MIN_STRINGS = 5;

/**
 * Directories that never render a screen. `api/` returns JSON, `includes/`
 * holds logic — but NOT `<module>/includes/`, which holds page headers that do
 * render, so the exclusion is anchored to the start of the path.
 */
const SKIP = ['api/', 'includes/', 'vendor/', 'node_modules/', 'tests/', 'scripts/', 'lang/', 'cron/', 'assets/vendor/'];

/**
 * A file can declare itself out of scope with `i18n-exempt: <reason>` in a
 * comment. There is exactly one honest reason - the strings are not the
 * product's to translate - and the marker forces it to be WRITTEN DOWN next to
 * the strings, where the next person to open the file will read it.
 *
 * ⚠️ This is a decision, not a suppression: an exempt file is still listed,
 * with its reason, so "I marked it and forgot" is not available. It just
 * doesn't fail the run.
 */
function exemptReason(string $src): ?string
{
    if (preg_match('/i18n-exempt:\s*(.+)/', $src, $m)) {
        return trim(preg_replace('/\s+/', ' ', substr($m[1], 0, 120)));
    }
    return null;
}

function skipped(string $p): bool
{
    foreach (SKIP as $s) if (str_starts_with($p, $s)) return true;
    return str_contains($p, '/vendor/') || str_contains($p, '.min.js');
}

/**
 * Count t(...) and window.t(...) calls — plus calls to any LOCAL WRAPPER
 * around window.t that the file defines for itself.
 *
 * ⚠️ Counting only the literal `t(` reported a fully wired file as unwired.
 * Two files here deliberately wrap the lookup rather than call it directly:
 * `checklists/ticket_view.js` has `chkT()` because its own `forEach(t => ...)`
 * loops SHADOW the global `t`, and `assets/js/calendar.js` has `tr()`. Both are
 * the right thing to do, and to the old regex both looked like zero t() calls.
 *
 * 🔑 A wrapper is recognised by what it DOES, not by its name: a function whose
 * body calls `window.t(`. Matching on a list of known names would have to be
 * kept in step with the code, and the whole point of this script is to find the
 * places nobody remembered to keep in step.
 */
function tCalls(string $src): int
{
    // `tf(key, english)` is the other global i18n.js exposes - a translation
    // with the English as its fallback - so it counts exactly like t().
    $n = preg_match_all('/(?<![A-Za-z0-9_$.])(?:window\.)?tf?\s*\(/', $src);

    // function NAME(...) { ... window.t( ... }  — take the first 400 chars of
    // the body, which is plenty for a lookup wrapper and stops a huge function
    // that happens to mention window.t from being counted as one.
    if (preg_match_all('/function\s+([A-Za-z_$][A-Za-z0-9_$]*)\s*\([^)]*\)\s*\{(.{0,400}?)\}/s', $src, $m, PREG_SET_ORDER)) {
        foreach ($m as $fn) {
            [, $name, $body] = $fn;
            if ($name === 't' || $name === 'tf' || !preg_match('/window\.tf?\(/', $body)) continue;
            $n += preg_match_all('/(?<![A-Za-z0-9_$.])' . preg_quote($name, '/') . '\s*\(/', $src);
        }
    }

    return $n;
}

/**
 * Visible English strings a user would read.
 *
 * For PHP/HTML: text sitting between tags, and the value of a placeholder or
 * title attribute. For JS: quoted strings of two or more words that start with
 * a capital — a label or a sentence, not an id, class or key.
 *
 * ⚠️ Script and style blocks are stripped from HTML first, or every line of
 * inline JavaScript counts as page text.
 */
function visibleStrings(string $src, bool $isJs): int
{
    if ($isJs) {
        preg_match_all('/([\'"])([A-Z][a-z]+(?:[ ,\'-][A-Za-z]+){1,12}[.?!]?)\1/', $src, $m);
        return count(array_filter($m[2], fn($s) => !preg_match('/^[A-Z][a-z]+ ?[A-Z]/', $s) || str_contains($s, ' ')));
    }
    $src = preg_replace('~<script\b.*?</script>~is', '', $src);
    $src = preg_replace('~<style\b.*?</style>~is', '', $src);
    $n = preg_match_all('~>\s*([A-Z][A-Za-z]*(?:[ ,\'&;-][A-Za-z]+){1,15}[.?!]?)\s*<~', $src, $m);
    $n += preg_match_all('~\b(?:placeholder|title|aria-label)\s*=\s*"([A-Z][^"]{4,60})"~', $src, $m2);
    return $n;
}

/**
 * The same matches visibleStrings() counts, but kept rather than tallied, with
 * the line each one sits on.
 *
 * Counting told us WHICH files are unwired. Wiring one needs to know WHAT is in
 * it, and reading that off by eye is how a string gets missed - so the list and
 * the count come from the same patterns, and cannot drift apart.
 *
 * @return array<int, array{0:int, 1:string}> [line, string]
 */
function visibleStringList(string $src, bool $isJs): array
{
    $lineOf = function (int $offset) use ($src): int {
        return substr_count($src, "\n", 0, $offset) + 1;
    };
    $out = [];

    if ($isJs) {
        preg_match_all(
            '/([\'"])([A-Z][a-z]+(?:[ ,\'-][A-Za-z]+){1,12}[.?!]?)\1/',
            $src, $m, PREG_OFFSET_CAPTURE
        );
        foreach ($m[2] as $hit) {
            [$str, $off] = $hit;
            if (preg_match('/^[A-Z][a-z]+ ?[A-Z]/', $str) && !str_contains($str, ' ')) continue;
            $out[] = [$lineOf($off), $str];
        }
        return $out;
    }

    // Blanking rather than deleting keeps every offset, and so every line
    // number, exactly where it was in the original file.
    $blank = fn($m) => str_repeat(' ', strlen($m[0] ?? ''));
    $stripped = preg_replace_callback('~<script\b.*?</script>~is', fn($m) => preg_replace('/[^\n]/', ' ', $m[0]), $src);
    $stripped = preg_replace_callback('~<style\b.*?</style>~is',  fn($m) => preg_replace('/[^\n]/', ' ', $m[0]), $stripped);

    preg_match_all(
        '~>\s*([A-Z][A-Za-z]*(?:[ ,\'&;-][A-Za-z]+){1,15}[.?!]?)\s*<~',
        $stripped, $m, PREG_OFFSET_CAPTURE
    );
    foreach ($m[1] as [$str, $off]) $out[] = [$lineOf($off), $str];

    preg_match_all(
        '~\b(?:placeholder|title|aria-label)\s*=\s*"([A-Z][^"]{4,60})"~',
        $stripped, $m2, PREG_OFFSET_CAPTURE
    );
    foreach ($m2[1] as [$str, $off]) $out[] = [$lineOf($off), $str];

    usort($out, fn($a, $b) => $a[0] <=> $b[0]);
    return $out;
}

// ---------------------------------------------------------------- scan

if ($list !== '') {
    $list = str_replace('\\', '/', $list);
    if (!is_file($list)) { fwrite(STDERR, "not a file: $list\n"); exit(2); }
    $src  = (string) file_get_contents($list);
    $rows = visibleStringList($src, str_ends_with($list, '.js'));
    printf("%s - %d visible string(s), %d t() call(s)\n\n", $list, count($rows), tCalls($src));
    foreach ($rows as [$ln, $str]) printf("%5d  %s\n", $ln, $str);
    echo "\n⚠️  These are what the COUNTER sees. It is deliberately conservative:\n";
    echo "   a lower-case opener, a one-word label or text built in PHP will not\n";
    echo "   appear here and still has to be wired. Read the file too.\n";
    exit(0);
}

$files = [];
foreach (['php', 'js'] as $ext) {
    foreach (glob("*/*.$ext") as $f)        $files[] = $f;
    foreach (glob("*/*/*.$ext") as $f)      $files[] = $f;
    foreach (glob("*/*/*/*.$ext") as $f)    $files[] = $f;
}
sort($files);
$files = array_values(array_unique(array_filter($files, fn($f) => !skipped($f))));

$bad = [];
$warn = [];
$exempt = [];
$rows = [];

foreach ($files as $f) {
    $src = (string) file_get_contents($f);
    $isJs = str_ends_with($f, '.js');

    // A page that renders nothing is not this check's business.
    if (!$isJs && !preg_match('/<(?:html|body|div|table|form|h1|h2|button)\b/i', $src)) continue;

    $vis = visibleStrings($src, $isJs);
    if ($vis < MIN_STRINGS) continue;

    $t = tCalls($src);
    $rows[] = [$f, $vis, $t];

    $why = exemptReason($src);
    if ($why !== null)             $exempt[] = [$f, $vis, $why];
    elseif ($t === 0)              $bad[]  = [$f, $vis, $t];
    elseif ($vis > $t * 3)         $warn[] = [$f, $vis, $t];
}

usort($rows, fn($a, $b) => $b[1] <=> $a[1]);

if ($all) {
    printf("%-52s %8s %8s\n", 'file', 'visible', 't()');
    foreach ($rows as [$f, $v, $t]) printf("%-52s %8d %8d\n", $f, $v, $t);
    echo "\n";
}

printf("scanned %d user-facing file(s)\n\n", count($rows));

if ($bad) {
    printf("🔴 %d file(s) render text and call t() ZERO times:\n", count($bad));
    foreach ($bad as [$f, $v, $t]) printf("   %-50s %4d visible string(s), 0 t()\n", $f, $v);
    echo "\n   These are shown in English in every locale, and i18n_audit.php\n";
    echo "   cannot see it: the strings are not in lang/en either.\n";
}

if ($exempt) {
    printf("\n✅ %d file(s) declare themselves out of scope:\n", count($exempt));
    foreach ($exempt as [$f, $v, $why]) printf("   %-44s %4d string(s) - %s\n", $f, $v, $why);
}

if ($warn) {
    printf("\n⚠️  %d file(s) look partly wired (more than 3x visible strings to t() calls).\n", count($warn));
    printf("   Reported, not failed — which strings should be translated is a judgement.\n");
    foreach (array_slice($warn, 0, 10) as [$f, $v, $t]) printf("   %-50s %4d visible, %3d t()\n", $f, $v, $t);
    if (count($warn) > 10) printf("   ... and %d more\n", count($warn) - 10);
}

if (!$bad) {
    echo "\nEvery user-facing file that renders text calls t() at least once.\n";
    exit(0);
}
exit(1);
