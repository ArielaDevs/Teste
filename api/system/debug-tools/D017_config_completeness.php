<?php
/**
 * Debug Tool D017 — is anything missing from config.php?
 *
 * config.php is the operator's own file. It is edited once at install and kept,
 * so an upgrade never adds a line to it (GH #129). This tool compares the copy
 * this server is actually running with the list of what config.php is expected
 * to define, and says - per setting - whether it is there, whether the app has
 * a built-in default for it, and the line to add if you want to set it.
 *
 * 🔑 THE LIST IS includes/config_requirements.php, NOT A SECOND COPY OF A
 * TEMPLATE. It does not diff against docker/config.php (which defines database
 * credentials a hand install must not add). tests/config-not-load-bearing.php
 * holds the list to both shipped templates, so it cannot drift.
 *
 * 🔴 IT NEVER PRINTS A VALUE. Names and present/absent only - the database
 * password is defined by these same files. The config file is read with the
 * tokeniser for the NAMES it declares; nothing it assigns is echoed.
 *
 * READ-ONLY. It reads config.php's text and checks which constants exist. It
 * writes nothing and touches no database row (beyond the administrator check).
 *
 * Output: plain text, section-delimited with === HEADERS === for easy skimming.
 */

@session_start();

$DIAG_ID   = 'D017';
$DIAG_NAME = 'Config completeness — is anything missing from config.php?';

require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../includes/functions.php';
require_once __DIR__ . '/../../../includes/config_requirements.php';
require_once __DIR__ . '/../../../includes/storage_persistence.php';

// Debug tools are administrators-only (issue #34). Fail closed.
try {
    $__dbgAdmin = !empty($_SESSION['analyst_id']) && analystIsAdmin(connectToDatabase(), (int)$_SESSION['analyst_id']);
} catch (Throwable $e) {
    $__dbgAdmin = false;
}
if (!$__dbgAdmin) {
    http_response_code(403);
    if (!headers_sent()) header('Content-Type: text/plain; charset=utf-8');
    echo "Administrator access required.\n";
    exit;
}

$sections = [];
function addSection(&$sections, $title, $body) {
    if (is_array($body)) $body = implode("\n", $body);
    $sections[] = "=== {$title} ===\n" . rtrim($body, "\n");
}
function emit_and_exit($sections) {
    if (!headers_sent()) header('Content-Type: text/plain; charset=utf-8');
    echo implode("\n\n", $sections) . "\n";
    exit;
}

$configPath = dirname(__DIR__, 3) . '/config.php';
$reqs   = configRequirements();
$report = configRequirementsReport($configPath);

// ---- 1. THE FILE -------------------------------------------------------
$file = [
    "PHP version     : " . PHP_VERSION,
    "config.php      : " . ($report['readable'] ? 'found and readable' : 'COULD NOT READ IT - the checks below use only what is loaded'),
    "Running in      : " . (storagePersistenceInContainer()
                            ? 'a container - config.php here is the image\'s copy of docker/config.php'
                            : 'a normal web server - config.php is your own copy'),
];
addSection($sections, "1. YOUR CONFIG.PHP", $file);

// ---- 2. EACH SETTING ---------------------------------------------------
$kindWord = ['required' => 'needed', 'fallback' => 'has a default', 'optional' => 'optional'];
$lines = [];
foreach ($report['rows'] as $r) {
    $req = $reqs[$r['name']];
    if ($r['status'] === 'problem') {
        $mark = 'MISSING';
    } elseif ($r['status'] === 'ok') {
        $mark = 'ok';
    } else {
        $mark = 'default';
    }
    $lines[] = sprintf("[%-7s] %-20s (%s)", $mark, $r['name'], $kindWord[$r['kind']]);
    if ($r['kind'] === 'required') {
        $lines[] = "            set: " . ($r['defined'] ? 'yes' : 'NO') . "   - normally in " . $req['where'];
    } else {
        $lines[] = "            in your config.php: " . ($r['inConfig'] ? 'yes' : 'no')
                 . "   set right now: " . ($r['defined'] ? 'yes' : 'no');
    }
    if ($r['status'] !== 'ok') {
        foreach (explode("\n", wordwrap($req['without'], 66)) as $w) $lines[] = "            " . $w;
    }
    $lines[] = "";
}
addSection($sections, "2. EACH SETTING (names only - no values are ever shown)", $lines);

// ---- 3. ANYTHING ELSE IN THE FILE --------------------------------------
$extra = [];
if ($report['unknown']) {
    $extra[] = "Constants your config.php defines that this list does not know about:";
    foreach ($report['unknown'] as $n) $extra[] = "  - " . $n;
    $extra[] = "These are usually harmless leftovers or your own additions. Nothing is wrong";
    $extra[] = "unless you expected one of them to do something.";
} else {
    $extra[] = "No other constants.";
}
$extra[] = "";
if ($report['functions']) {
    $extra[] = "⚠ Functions declared INSIDE your config.php:";
    foreach ($report['functions'] as $f) $extra[] = "  - " . $f . "()";
    $extra[] = "config.php should hold values only. A function here is not upgraded with the";
    $extra[] = "app - that is exactly how GH #129 took every page down. The app now ships its";
    $extra[] = "own copy of everything it needs, so these can usually be deleted. If a page";
    $extra[] = "says \"Cannot redeclare\", deleting them is the fix.";
} else {
    $extra[] = "No functions declared in config.php - good, it holds values only.";
}
addSection($sections, "3. ANYTHING ELSE IN THE FILE", $extra);

// ---- VERDICT -----------------------------------------------------------
$verdict = [];
if ($report['problems'] === 0) {
    $verdict[] = "✓ Nothing is missing. Every setting the app needs is present, and every";
    $verdict[] = "  one marked \"default\" is safely covered by the app itself.";
    $defaults = array_filter($report['rows'], fn($r) => $r['status'] === 'default');
    if ($defaults) {
        $verdict[] = "";
        $verdict[] = "  To choose your own value for a \"default\" setting, add its line to";
        $verdict[] = "  config.php (replace the example value):";
        foreach ($defaults as $r) {
            $verdict[] = "";
            $verdict[] = "    " . $r['name'] . ":";
            foreach (explode("\n", $reqs[$r['name']]['add']) as $l) $verdict[] = "      " . $l;
        }
    }
} else {
    $verdict[] = "✗ " . $report['problems'] . " needed setting(s) missing. Add these lines (with your own";
    $verdict[] = "  values) to the file named, then reload:";
    foreach ($report['rows'] as $r) {
        if ($r['status'] !== 'problem') continue;
        $verdict[] = "";
        $verdict[] = "    " . $r['name'] . " - in " . $reqs[$r['name']]['where'] . ":";
        foreach (explode("\n", $reqs[$r['name']]['add']) as $l) $verdict[] = "      " . $l;
    }
}
addSection($sections, "VERDICT", $verdict);

emit_and_exit($sections);
