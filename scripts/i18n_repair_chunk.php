<?php
/* 🔴 COMMAND LINE ONLY - see scripts/.htaccess. Over HTTP this would run for anyone, unauthenticated. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * i18n repair — fix the one malformation that keeps coming back, and only that one.
 *
 *   php scripts/i18n_repair_chunk.php pl                     every pl chunk in .i18n-work
 *   php scripts/i18n_repair_chunk.php gu .i18n-work-2/chunks somewhere else
 *   php scripts/i18n_repair_chunk.php pl --dry-run           report, change nothing
 *
 * ──────────────────────────────────────────────────────────────────────────
 * 🔴 WHY THIS EXISTS, AND WHY IT IS NOT A BETTER WARNING.
 *
 * A translation chunk is `key<TAB>value` per line. When the ENGLISH VALUE IS
 * EMPTY — a blank column header, a hint deliberately left unset — the correct
 * output is `key`, a tab, and nothing. There is nothing after the tab to make
 * the tab look necessary, so it gets dropped, and the line becomes a bare key
 * that the merge tool would silently discard.
 *
 * This happened FIVE times in one day, across five different agents, in two
 * languages. Every time on a blank-English key. Every time the agent's own
 * self-check reported success. The last one had a prompt explicitly naming the
 * failure, stating it had already happened four times, and instructing the agent
 * to grep its own output for tabless lines. It still happened.
 *
 * 🔑 So the conclusion is not "warn harder". It is that a tab followed by
 * nothing is not reliably producible, and the pipeline should repair it rather
 * than keep asking. There is no translation judgement involved: if English is
 * empty the translation is empty, necessarily and in every language.
 *
 * 🔴🔴 THE FRENCH RUN OF 2026-09-22 SETTLED IT. Thirteen agents, every prompt
 * naming the failure, stating it had already happened, and giving the exact
 * command to check with — `awk -F'\t' 'NF<2' <file>`. FOUR of the thirteen hit
 * it anyway, taking the running total from five to nine.
 *
 * The four split two and two, and the split is the whole lesson:
 *
 *   - TWO caught it themselves and genuinely repaired it before handing back
 *     (one re-added four stripped tabs in the SSO chunk with sed). Verified
 *     independently afterwards: really fixed.
 *   - TWO reported exactly the same thing and were WRONG. Each named the
 *     offending key in its hand-back, identified it correctly as the rule-6
 *     case, and stated it had run that exact command and seen it pass.
 *     `cat -A` showed no tab on either line.
 *
 * 🔑 So the finding is stronger than "agents forget". For this one fault the
 * agent's self-check is not merely unreliable, it is CONFIDENTLY WRONG — it
 * reports success on the very line it was watching, and reports it in the same
 * words as the agents that really did fix it. The two cases are indistinguish-
 * able from the report alone. A chunk's own verification claim therefore
 * carries NO weight here however specific it sounds, and no prompt wording
 * closes the gap: the rate did not fall as the instructions got better.
 *
 * Run this over every chunk before verifying, unconditionally, and never skip
 * it because a hand-back says the file is clean.
 *
 * ──────────────────────────────────────────────────────────────────────────
 * 🔑 THE MECHANISM, FOUND ON THE ELEVENTH OCCURRENCE — AND THE REAL FIX
 *
 * An agent on the nb run diagnosed it in one line: **the Write tool strips
 * trailing whitespace.** A line whose correct content is `key` + TAB + nothing
 * therefore cannot be written by that tool at all. The tab is removed after the
 * agent composes the line and before the bytes land on disk.
 *
 * That reframes every earlier finding. The agents were not careless, and the
 * two that "checked and were wrong" were not lying — they verified content they
 * had genuinely produced, and the tool discarded it on the way out. No prompt
 * wording could ever have fixed this, which is exactly what eleven attempts
 * demonstrated.
 *
 * 🔴 SO THE FIX IS NOT TO REPAIR IT, IT IS NOT TO ASK. As of 2026-09-22:
 *
 *   - i18n_chunk.php WITHHOLDS blank-English keys from chunks entirely.
 *   - i18n_merge.php FILLS them itself, because a blank English value has
 *     exactly one correct translation in every language.
 *
 * There are seven such keys in the whole product, and all eleven failures were
 * on those seven. This script remains the net for chunks generated before that
 * change, and for anything produced by hand — but the failure it was written
 * for can no longer occur through the normal pipeline.
 * ──────────────────────────────────────────────────────────────────────────
 *
 * ⚠️ THE REFUSAL IS THE IMPORTANT HALF. A missing tab on a key whose English is
 * NOT empty means the agent lost a translation. Inserting a blank there would
 * convert a loud, catchable failure into a silent one — an empty string that
 * renders as nothing and never falls back to English. So that case is reported
 * and left alone, and the chunk still fails verification.
 *
 * There are currently 7 blank-valued keys in lang/en. Each is a landmine for
 * each of the 23 locales, so this runs before verification, every time.
 * ──────────────────────────────────────────────────────────────────────────
 */

$args = array_slice($argv, 1);
$dry  = in_array('--dry-run', $args, true);
$args = array_values(array_filter($args, fn($a) => $a !== '--dry-run'));

$loc = $args[0] ?? '';
if ($loc === '') {
    fwrite(STDERR, "usage: php scripts/i18n_repair_chunk.php <locale> [chunks-dir] [--dry-run]\n");
    exit(2);
}
$root = dirname(__DIR__);
$dir  = rtrim($args[1] ?? ($root . '/.i18n-work/chunks'), '/\\') . '/';

if (!is_dir($dir)) { fwrite(STDERR, "no such directory: $dir\n"); exit(2); }

$fixed = $refused = $files = $stripped = 0;

foreach (glob($dir . "*.$loc.tsv") as $t) {
    $en = preg_replace('/\.' . preg_quote($loc, '/') . '\.tsv$/', '.en.tsv', $t);
    if (!file_exists($en)) continue;

    // English key => value, so we can tell "legitimately blank" from "lost".
    $enMap = [];
    foreach (file($en, FILE_IGNORE_NEW_LINES) as $l) {
        if ($l === '') continue;
        $p = explode("\t", $l, 2);
        $enMap[$p[0]] = $p[1] ?? '';
    }

    $lines   = file($t, FILE_IGNORE_NEW_LINES);
    $out     = [];
    $touched = false;

    foreach ($lines as $n => $l) {
        if ($l !== '' && strpos($l, "\t") === false) {
            $key = $l;

            /* 🔑 SCAFFOLDING, NOT A LOST TRANSLATION.
             *
             * Agents occasionally leak a fragment of their own tool-call syntax
             * into the file they are writing — a trailing `</content>` or
             * `</invoke>` on its own line. Seen twice: a French agent caught it
             * in its own output, and a Ukrainian one did not, putting the same
             * artefact in all four of its chunks.
             *
             * This is safe to delete and nothing else is, because a TSV line is
             * `key<TAB>value` and **a key never starts with `<`** — so a tabless
             * line that looks like a closing tag cannot be a key whose
             * translation went missing. Anything else tabless is still refused.
             */
            if (preg_match('~^</[A-Za-z][A-Za-z0-9_-]*>$~', $key) && !array_key_exists($key, $enMap)) {
                $touched = true;
                $stripped++;
                printf("  %s  %-40s line %-4d %s\n", $dry ? 'would strip' : '  stripped',
                    basename($t), $n + 1, $key);
                continue;   // drop the line entirely
            }

            if (array_key_exists($key, $enMap) && $enMap[$key] === '') {
                $out[] = $key . "\t";
                $touched = true;
                $fixed++;
                printf("  %s  %-40s line %-4d %s\n", $dry ? 'would fix' : '  fixed  ',
                    basename($t), $n + 1, $key);
                continue;
            }
            $refused++;
            printf("  REFUSED   %-40s line %-4d %s\n", basename($t), $n + 1, $key);
            echo "            English is NOT empty for this key - a translation was lost.\n";
            echo "            Left as-is so verification still fails. Re-translate the chunk.\n";
        }
        $out[] = $l;
    }

    if ($touched && !$dry) {
        file_put_contents($t, implode("\n", $out) . "\n");
        $files++;
    }
}

if ($stripped) {
    printf("\n%s%d scaffolding line(s) — agent tool syntax, never a translation\n",
        $dry ? 'would strip ' : 'stripped ', $stripped);
}
printf("\n%s%d line(s), %d refused%s\n",
    $dry ? 'would repair ' : 'repaired ', $fixed, $refused,
    $dry ? '  (dry run - nothing written)' : ", across $files file(s)");

// A refusal is a real problem, so say so in the exit code too.
exit($refused > 0 ? 1 : 0);
