<?php
/* 🔴 COMMAND LINE ONLY - see scripts/.htaccess. Over HTTP this would run for anyone, unauthenticated. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * Detect a locale drifting into a closely related one.
 *
 *   php scripts/i18n_drift.php nn nb      is the nynorsk locale writing bokmål?
 *   php scripts/i18n_drift.php nb nn      and the reverse
 *   php scripts/i18n_drift.php ms id      Malay writing Indonesian
 *
 * ──────────────────────────────────────────────────────────────────────────
 * 🔴 WHY A SCRIPT, WHEN THIS IS "JUST A GREP".
 *
 * Because the grep is wrong, in a way that reports success.
 *
 * `grep -E '\bfrå\b'` finds 9 occurrences in lang/nn. The real number is 630.
 * The word ENDS in a non-ASCII letter, and `\b` marks a transition between a
 * word character and a non-word character — 'å' is not a word character to
 * grep in this locale, so "å" followed by a space is not a boundary and the
 * match fails. Every Norwegian, Danish, German, Polish, Portuguese and Spanish
 * form that ends in an accented letter is invisible to that check.
 *
 * 🔑 The danger is the direction of the error. A drift check that silently
 * under-counts reports CLEAN on a locale that is drifting. It is precisely the
 * "check whose answer cannot come back negative" that this project has been
 * bitten by before.
 *
 * So matching is done in PHP with an explicit alphabet, /u, and boundaries
 * written as "not a letter of this alphabet" rather than \b.
 * ──────────────────────────────────────────────────────────────────────────
 *
 * ⚠️ IT READS VALUES ONLY, never keys — a key like `tickets.from_address`
 * contains "from" and would otherwise read as drift in a dozen languages.
 */

$root = dirname(__DIR__);
chdir($root);

/**
 * Word pairs that distinguish two close written standards, in UI prose.
 * Each entry is [form used by the FIRST locale, form used by the SECOND].
 * Add a pair only when you have checked both forms actually occur — a pair
 * that never appears proves nothing and pads the report.
 */
const PAIRS = [
    'nn:nb' => [
        ['ikkje', 'ikke'], ['kva', 'hva'], ['frå', 'fra'], ['nokon', 'noen'],
        ['kven', 'hvem'], ['vere', 'være'], ['viss', 'hvis'], ['eg', 'jeg'],
    ],
    /**
     * ⚠️ uk:ru is NOT the same kind of pair as the two below. Ukrainian and
     * Russian are separate languages, not two written standards of one, so a
     * translation does not "drift" between them the way bokmål slides into
     * nynorsk. What this catches is surzhyk — Russianisms reaching for a
     * cognate where standard literary Ukrainian has its own word.
     *
     * Every form on the right was measured at zero in the existing locale
     * before being listed, and every form on the left was measured present, so
     * the list reflects what this product already does rather than a position
     * on what Ukrainian ought to be.
     */
    'uk:ru' => [
        ['отримати', 'получити'], ['скасувати', 'відмінити'],
        ['за замовчуванням', 'по замовчуванню'], ['налаштування', 'настройки'],
        ['помилка', 'ошибка'], ['користувач', 'пользователь'],
    ],
    'ms:id' => [
        // 🔴 `bisa` vs `boleh` is the decisive one: a finished Bahasa Melayu
        // locale came out with `boleh` 432 times and `bisa` zero.
        ['boleh', 'bisa'], ['kualiti', 'kualitas'], ['aktiviti', 'aktivitas'],
        ['perisian', 'perangkat lunak'], ['muat turun', 'unduh'],
        ['muat naik', 'unggah'], ['fail', 'berkas'], ['tetapan', 'pengaturan'],
        ['e-mel', 'surel'],
    ],
];

/**
 * Letters that count as part of a word, for the languages handled here:
 * Latin with diacritics, plus Cyrillic for the uk/ru pair.
 *
 * ⚠️ The \x{...} escapes need their backslashes. An edit that lost them left
 * the class as a literal "x{0400}-x{04FF}", which PCRE read as an out-of-order
 * range and refused to compile — so every count came back 0 and the run
 * reported "neither form occurs". --self-test caught it immediately, 9 of 13
 * cases failing, which is the entire reason that self-test exists.
 */
const ALPHA = 'A-Za-zÀ-ÖØ-öø-ÿĀ-ž\x{0400}-\x{04FF}';

function flatten(array $a): array
{
    $out = [];
    array_walk_recursive($a, function ($v) use (&$out) { $out[] = (string) $v; });
    return $out;
}

/** Occurrences of $word as a whole word, accent-safe. */
function countWord(string $hay, string $word): int
{
    $re = '/(?<![' . ALPHA . '])' . preg_quote($word, '/') . '(?![' . ALPHA . '])/iu';
    return preg_match_all($re, $hay, $m);
}

$args = array_slice($argv, 1);

if (in_array('--self-test', $args, true)) { exit(driftSelfTest()); }

if (count($args) < 2) {
    fwrite(STDERR, "usage: i18n_drift.php <locale> <locale-it-must-not-drift-into>\n");
    fwrite(STDERR, "       i18n_drift.php --self-test\n");
    fwrite(STDERR, "known pairs: " . implode(', ', array_keys(PAIRS)) . "\n");
    exit(2);
}
[$loc, $other] = $args;

// A word list works in both directions — the pair is symmetrical, so reading
// it backwards is the reverse check rather than a second list to keep in step.
$key  = "$loc:$other";
$list = PAIRS[$key] ?? null;
if ($list === null && isset(PAIRS["$other:$loc"])) {
    $list = array_map(fn($p) => [$p[1], $p[0]], PAIRS["$other:$loc"]);
}
if ($list === null) {
    fwrite(STDERR, "no word list for $key — add one to PAIRS in this file.\n");
    fwrite(STDERR, "known pairs (usable in either direction): " . implode(', ', array_keys(PAIRS)) . "\n");
    exit(2);
}
if (!is_dir("lang/$loc")) { fwrite(STDERR, "no such locale dir: lang/$loc\n"); exit(2); }

// VALUES only. A key name is English and would produce constant false hits.
$text = '';
foreach (glob("lang/$loc/*.php") as $f) {
    $data = @include $f;
    if (is_array($data)) $text .= "\n" . implode("\n", flatten($data));
}

printf("%s must not read as %s — %s strings scanned\n\n",
    $loc, $other, number_format(substr_count($text, "\n")));
printf("  %-18s %8s   %-18s %8s\n", "expected ($loc)", 'uses', "wrong ($other)", 'uses');
printf("  %s\n", str_repeat('-', 60));

$drift = 0; $proven = 0;
foreach ($list as [$right, $wrong]) {
    $r = countWord($text, $right);
    $w = countWord($text, $wrong);
    if ($w > 0) $drift += $w;
    if ($r > 0) $proven++;
    printf("  %-18s %8d   %-18s %8d %s\n", $right, $r, $wrong, $w,
        $w > 0 ? '  <-- DRIFT' : ($r > 0 ? '  ok' : '  (neither form occurs)'));
}

echo "\n";
if ($drift > 0) {
    printf("🔴 %d occurrence(s) of %s forms in the %s locale.\n", $drift, $other, $loc);
    exit(1);
}
if ($proven === 0) {
    // ⚠️ Zero drift AND zero expected forms means the check proved nothing —
    // a wrong alphabet or an empty locale looks identical to a clean one.
    printf("⚠️  No %s forms found either. This check proved NOTHING — verify the word list.\n", $loc);
    exit(2);
}
printf("clean: no %s forms, and %d of the %s forms are present as a positive control.\n",
    $other, $proven, $loc);
exit(0);

/**
 * 🔑 PROVE THE DETECTOR CAN FAIL, NOT JUST PASS.
 *
 * This script replaced a grep that reported "clean" on a locale it could not
 * actually see. A detector that has only ever been shown agreeing is worth
 * nothing, and the accented cases are exactly the ones the old check missed —
 * so they are asserted explicitly here rather than assumed to work.
 */
function driftSelfTest(): int
{
    $cases = [
        // [haystack, needle, expected count, what it proves]
        ['Du kan ikkje sjå dette.',            'ikkje', 1, 'plain ASCII word'],
        ['Du kan ikke se dette.',              'ikkje', 0, 'near-miss is not a match'],
        ['Hentar frå serveren.',               'frå',   1, '🔴 word ENDING in an accent — the bug that caused this file'],
        ['frå',                                'frå',   1, 'accented word alone, no surrounding text'],
        ['Data frå, og til, serveren.',        'frå',   1, 'accented word before a comma'],
        ['infrastruktur og fragment',          'fra',   0, '🔴 must NOT match inside another word'],
        ['Kva skjer? kva no?',                 'kva',   2, 'counts every occurrence, case-insensitively'],
        ['Det skal vere slik.',                'vere',  1, 'internal accent neighbours'],
        ['Det skal være slik.',                'vere',  0, 'vere must not match være'],
        ['Det skal være slik.',                'være',  1, 'internal accent matches itself'],
        ['Boleh guna ini.',                    'bisa',  0, 'ms/id: correct form is not flagged'],
        ['Anda bisa guna ini.',                'bisa',  1, 'ms/id: the decisive Indonesian form IS flagged'],
        ['perangkat lunak ini',                'perangkat lunak', 1, 'multi-word needle'],
    ];

    $fail = 0;
    foreach ($cases as [$hay, $needle, $want, $what]) {
        $got = countWord($hay, $needle);
        $ok  = $got === $want;
        if (!$ok) $fail++;
        printf("  %-4s %-62s expected %d, got %d\n", $ok ? 'ok' : 'FAIL', $what, $want, $got);
    }

    printf("\n%d passed, %d failed\n", count($cases) - $fail, $fail);
    if ($fail) {
        echo "🔴 The detector is wrong. A drift check that under-counts reports CLEAN on a drifting locale.\n";
        return 1;
    }
    echo "The detector finds what it should and ignores what it should, including words ending in an accent.\n";
    return 0;
}
