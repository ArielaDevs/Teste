<?php
/* 🔴 COMMAND LINE ONLY - see scripts/.htaccess. Over HTTP this would run for anyone, unauthenticated. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * Catch two UI strings that collapse onto one word in translation.
 *
 *   php scripts/i18n_collision.php              every locale
 *   php scripts/i18n_collision.php de nb pt-BR  named locales
 *   php scripts/i18n_collision.php --list       what it checks, and why
 *
 * Exit 0 = no collisions. Exit 1 = at least one. Exit 2 = bad usage.
 *
 * ──────────────────────────────────────────────────────────────────────────
 * 🔴 THE DEFECT THIS EXISTS FOR IS INVISIBLE TO EVERY OTHER CHECK HERE
 *
 * FreeITSM's checklist settings screen offers a gate mode called **Standard**
 * (warn, do not block) and, a few lines below, marks a preset **(Default)**.
 * Two different English words for two unrelated things, so English reads fine.
 *
 * Danish, Norwegian, German and Portuguese all collapse both onto one word —
 * *standard*, *standard*, *Standard*, *padrão*. The screen then says "Standard"
 * meaning *warn, do not block* directly above "(standard)" meaning *this is the
 * preset*, and an administrator has no way to tell which is which.
 *
 * 🔑 **The defect does not exist in any single string.** Each translation is
 * correct on its own; the fault is only in the pair, and only because of where
 * the two land in the interface. So:
 *
 *   - `i18n_verify_chunk.php` cannot see it — it compares one line to one line.
 *   - `i18n_audit.php` cannot see it — nothing is missing.
 *   - `i18n_evict_stale.php` cannot see it — the English never changed.
 *   - A 100% coverage score cannot see it.
 *
 * Four of the first nine locales translated in one batch hit it, and only one
 * translator noticed unprompted. That is not carelessness: a translator is
 * given a chunk, not a screenshot, so the adjacency is genuinely not visible
 * from the work. A machine holding both keys at once can see it, which is the
 * only reason this file can exist.
 *
 * ⚠️ IT REPORTS, IT DOES NOT FIX. Which of the two words should change is a
 * judgement about the language — sometimes the gate should be renamed, more
 * often the preset marker. There is no safe automatic answer.
 */

require_once __DIR__ . '/i18n_lib.php';

$root = dirname(__DIR__);

/**
 * The pairs worth checking, and why each one matters.
 *
 * Add a rule when English uses two DIFFERENT words for two DIFFERENT things
 * that a reader sees at the same time. Do not add one for strings that merely
 * appear in the same file — words repeat legitimately all over a locale, and a
 * check that cries wolf gets switched off.
 *
 *   a, b        dotted keys, each 'namespace.key'
 *   a_part      which part of a's value to compare (see below)
 *   b_part      likewise for b
 *   why         printed with any hit, so whoever reads the failure knows the
 *               adjacency without having to open the product
 *   allow       words that may legitimately appear in both
 *
 * ⚠️ COMPARE THE PARTS THAT CARRY THE MEANING, NOT WHOLE STRINGS. The first
 * version of this file intersected two whole values and reported six
 * collisions, every one of them false: both strings describe *warning*, so they
 * legitimately share "warn" / "advar" / "avisar" / "amaran". The words actually
 * at risk are the gate's NAME and the preset's MARKER, which in English are
 * "Standard" and "(Default)" — so compare the label before the bracket against
 * the text inside the brackets, and nothing else.
 *
 *   🔑 A guard with known noise gets switched off, and then it is not a guard.
 *   Six false positives on the first run was the whole rule's worth of warning.
 *
 * Parts:
 *   'label'  the text before the first "(" — "Standard (Warn & audit)" -> "Standard"
 *   'paren'  the text inside brackets — "Allow it (Default)" -> "Default"
 *   'all'    the whole value
 */
/**
 * Sets of keys that are all shown TOGETHER on one screen, where any two
 * collapsing onto the same word is a defect.
 *
 * 🔑 This is the pair check turned inside out. A pair rule asks "do these two
 * specific strings collide?" and needs somebody to have thought of the pair. A
 * set asks "do ANY two of these collide?" and needs nobody to have thought of
 * anything — which is the only way to cover a 79-item palette, where the pair
 * list would have to hold 3,081 entries to say the same thing.
 *
 * Found by translators, not by this file, before the set check existed:
 *   Polish   "Monitor / gauge" and "Display / screen"  -> both "Monitor"
 *   Spanish  "Registry" and "Log"                      -> both "Registro"
 */
$SETS = [
    [
        'name'   => 'network mapper icon palette',
        'prefix' => 'network-mapper.icons.label',
        'why'    => 'All 79 icon names are listed together in the icon picker. '
                  . 'Two with the same name are two rows a user cannot tell apart.',
    ],
    [
        'name'   => 'network mapper icon categories',
        'prefix' => 'network-mapper.icons.category',
        'why'    => 'The 13 category headings are stacked down the same modal.',
    ],
];

$RULES = [
    [
        'name'   => 'checklist gate mode vs default marker (settings screen)',
        'a'      => 'checklists.editor.closure_warn',        'a_part' => 'label',
        'b'      => 'tickets.settings.checklists.empty_off_title', 'b_part' => 'paren',
        'why'    => 'Tickets -> Settings -> Checklists shows the gate choice and the '
                  . 'empty-checklist choice on one screen. English says "Standard" for '
                  . 'the gate and "(Default)" for the preset; several languages have one '
                  . 'word for both, and the screen stops making sense.',
        'allow'  => [],
    ],
    [
        'name'   => 'checklist gate mode vs default marker (help page)',
        'a'      => 'checklists.help.closing_warn_title',    'a_part' => 'label',
        'b'      => 'checklists.help.closing_empty_desc',    'b_part' => 'paren',
        'why'    => 'The checklists help page names the gate modes and then lists the '
                  . 'empty-checklist options, marking one of them as the preset, within '
                  . 'a few lines of each other.',
        'allow'  => [],
    ],
];

// ---------------------------------------------------------------- arguments
$args = array_slice($argv, 1);
if (in_array('--list', $args, true)) {
    foreach ($RULES as $r) {
        echo "- {$r['name']}\n    {$r['a']}\n    {$r['b']}\n    {$r['why']}\n\n";
    }
    exit(0);
}

$locales = array_values(array_filter($args, fn($a) => $a[0] !== '-'));
if (!$locales) {
    $locales = array_values(array_filter(scandir("$root/lang"), function ($d) use ($root) {
        return $d !== '.' && $d !== '..' && $d !== 'en' && is_dir("$root/lang/$d");
    }));
}

/** Words worth comparing: 4+ letters, so articles and particles do not match. */
function significantWords(string $s): array
{
    // Strip HTML and placeholders first - a shared <strong> is not a collision.
    $s = preg_replace('/<[^>]*>|\{[a-z_]+\}|%[sd]/i', ' ', $s);
    preg_match_all('/\p{L}{4,}/u', mb_strtolower($s), $m);
    return array_values(array_unique($m[0]));
}

/**
 * The part of a value that carries the name or the marker.
 *
 * ⚠️ 'paren' returns '' when the value has no brackets, and the caller treats
 * that as "nothing to compare" rather than "no collision". A locale that
 * dropped the "(Default)" marker altogether is a different problem, and this
 * tool must not quietly report it as clean.
 */
function valuePart(string $value, string $part): string
{
    if ($part === 'label') {
        $cut = mb_strpos($value, '(');
        return trim($cut === false ? $value : mb_substr($value, 0, $cut));
    }
    if ($part === 'paren') {
        preg_match_all('/\(([^)]*)\)/u', $value, $m);
        return $m[1] ? implode(' ', $m[1]) : '';
    }
    return $value;
}

function lookup(string $root, string $loc, string $dotted): ?string
{
    [$ns, $key] = explode('.', $dotted, 2);
    $path = "$root/lang/$loc/$ns.php";
    if (!is_file($path)) return null;
    static $cache = [];
    $ck = "$loc/$ns";
    if (!isset($cache[$ck])) $cache[$ck] = i18nFlatten(i18nLoad($path));
    return $cache[$ck][$key] ?? null;
}

// ---------------------------------------------------------------- check
$hits = 0;
$checked = 0;
$skipped = 0;

foreach ($locales as $loc) {
    foreach ($RULES as $rule) {
        $a = lookup($root, $loc, $rule['a']);
        $b = lookup($root, $loc, $rule['b']);

        // A locale that has not translated these yet is not a collision. It is
        // also not a pass - say so, or an untranslated locale reads as clean.
        if ($a === null || $b === null) { $skipped++; continue; }

        $aPart = valuePart($a, $rule['a_part'] ?? 'all');
        $bPart = valuePart($b, $rule['b_part'] ?? 'all');

        // Nothing to compare is not a pass. Say so rather than counting it clean.
        if ($aPart === '' || $bPart === '') {
            echo "⚠️  $loc — {$rule['name']}: no '{$rule['b_part']}' part to compare"
               . " (the bracketed marker may have been dropped in translation)\n";
            $skipped++;
            continue;
        }
        $checked++;

        $shared = array_diff(
            array_intersect(significantWords($aPart), significantWords($bPart)),
            $rule['allow']
        );
        if (!$shared) continue;

        $hits++;
        echo "🔴 $loc — {$rule['name']}\n";
        echo "   shares: " . implode(', ', $shared) . "\n";
        echo "   {$rule['a']}  ->  \"$aPart\"\n      $a\n";
        echo "   {$rule['b']}  ->  \"$bPart\"\n      $b\n";
        echo "   why it matters: {$rule['why']}\n\n";
    }
}

// ── set checks ────────────────────────────────────────────────────────────
$setChecked = 0;
foreach ($locales as $loc) {
    foreach ($SETS as $set) {
        [$ns, $path] = explode('.', $set['prefix'], 2);
        $file = __DIR__ . "/../lang/$loc/$ns.php";
        if (!is_file($file)) continue;
        $data = require $file;
        foreach (explode('.', $path) as $step) {
            if (!is_array($data) || !array_key_exists($step, $data)) { $data = null; break; }
            $data = $data[$step];
        }
        if (!is_array($data) || count($data) < 2) continue;

        $setChecked++;
        $seen = [];
        foreach ($data as $k => $v) {
            if (!is_string($v) || trim($v) === '') continue;
            // Case and surrounding space are not what tells two labels apart
            // on screen, so they are not what tells them apart here either.
            $seen[mb_strtolower(trim($v))][] = $k;
        }
        foreach ($seen as $value => $keys) {
            if (count($keys) < 2) continue;
            $hits++;
            echo "🔴 $loc — {$set['name']}\n";
            echo "   " . count($keys) . " keys all read \"$value\": " . implode(', ', $keys) . "\n";
            foreach ($keys as $k) {
                $enFile = require __DIR__ . "/../lang/en/$ns.php";
                $enVal = $enFile;
                foreach (array_merge(explode('.', $path), [$k]) as $step) {
                    $enVal = is_array($enVal) && array_key_exists($step, $enVal) ? $enVal[$step] : '?';
                }
                echo "      $k — English: \"$enVal\"\n";
            }
            echo "   why it matters: {$set['why']}\n\n";
        }
    }
}

printf("%d pair(s) and %d set(s) checked across %d locale(s), %d untranslated pair(s) skipped\n",
    $checked, $setChecked, count($locales), $skipped);

if ($hits) {
    echo "🔴 $hits collision(s). Change ONE of the colliding values - for a pair that is\n";
    echo "   usually the preset marker, since the gate name is a setting an administrator\n";
    echo "   has to recognise; for a palette it is whichever label is less established.\n";
    exit(1);
}
echo "No collisions.\n";
exit(0);
