<?php
/**
 * Feature Bingo - run every card's check against a database and report.
 *
 *   php scripts/feature_bingo_check.php            summary + every problem
 *   php scripts/feature_bingo_check.php --all      every card and its state
 *
 * READ-ONLY: cards can only express reads (see includes/feature_bingo.php), so
 * this is safe against any database. A check that ERRORS (a table or column that
 * does not exist) is the thing this is for: on a page it would quietly read as
 * "not configured" forever. Exit code 1 if anything is wrong.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
chdir(dirname(__DIR__));
require 'config.php';
require 'includes/functions.php';
require 'includes/feature_bingo.php';

$conn = connectToDatabase();
$cards = featureBingoCards($problems);
$all = in_array('--all', $argv, true);

$byModule = []; $errors = 0; $lit = 0; $linkBad = 0;
foreach ($cards as $c) {
    $isLit = featureBingoRunCheck($conn, $c['check'], $err);
    $byModule[$c['module']] = ($byModule[$c['module']] ?? 0) + 1;
    if ($isLit) $lit++;
    // The page the card sends people to must exist (query string and #fragment aside).
    $path = preg_replace('/[?#].*$/', '', $c['link']);
    $linkOk = $c['link'] === '' || file_exists($path) || file_exists(rtrim($path, '/') . '/index.php') || file_exists($path . '.php');
    if (!$linkOk) $linkBad++;
    if ($err) {
        $errors++;
        echo "ERROR  {$c['id']}: $err\n";
    } elseif (!$linkOk) {
        echo "LINK   {$c['id']}: '{$c['link']}' does not exist\n";
    } elseif ($all) {
        echo ($isLit ? "  *    " : "  .    ") . "{$c['id']}  {$c['title']}\n";
    }
}
foreach ($problems as $p) echo "CARD   $p\n";

echo "\n" . count($cards) . " cards, $lit lit here, " . count($problems) . " malformed, $errors check errors, $linkBad bad links\n";
ksort($byModule);
foreach ($byModule as $m => $n) echo "  $m: $n\n";
exit(($problems || $errors || $linkBad) ? 1 : 0);
