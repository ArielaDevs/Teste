<?php
/* 🔴 NEVER OVER THE WEB. A test writes to the real tables — it creates forms,
   assets, documents and even working analyst accounts, and only tidies them up
   if it runs to the end. Served by a web server it is an unauthenticated write
   endpoint, and the request can be cut off half way. FreeITSM is normally
   deployed by putting the repository in the document root, so this file is
   reachable unless it refuses. See tests/README.md. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * config.php must not be load-bearing (GH #129).
 *
 * 🔴 WHAT THIS EXISTS TO CATCH. #1446 put dbConnectionOptions() into config.php and
 * changed eleven callers to use it. config.php is the OPERATOR'S file - it ships as
 * a template carrying a developer's own credentials path, so every install edits it
 * once and keeps that copy, and the Docker image copies docker/config.php straight
 * over the top of it. Upgrading therefore delivered the callers and left the
 * definition behind: HTTP 500, empty body, every page in the product.
 *
 * 🔑 The rule: config.php is for VALUES the operator chooses. Behaviour lives in
 * includes/, which upgrades with the product.
 *
 * ⚠️ Why the ordinary suite could not catch it: the development machine's config.php
 * IS the repository's config.php. On the one install where that file is not
 * customised, the function was present and everything worked. So this test must
 * never ask "does it work here" - it asks the structural question instead.
 *
 * Run: php tests/config-not-load-bearing.php
 */

$root = dirname(__DIR__);
$pass = 0; $fail = 0;

function ok(string $label, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; printf("  PASS %-62s %s\n", $label, $detail); }
    else       { $fail++; printf("  FAIL %-62s %s\n", $label, $detail); }
}

echo "\n1. config.php declares no functions at all\n";

// A `function foo(` at the start of a line in either config file is the exact
// shape of the #129 outage. Values are fine; behaviour is not.
foreach (['config.php', 'docker/config.php'] as $rel) {
    $src = file_get_contents("$root/$rel");
    preg_match_all('/^\s*function\s+(\w+)\s*\(/m', $src, $m);
    ok("$rel declares no functions", $m[1] === [],
       $m[1] ? 'FOUND: ' . implode(', ', $m[1]) : 'none');
}

echo "\n2. Every function the app calls has a home under includes/\n";

// The two that caused, or were one upgrade away from causing, #129.
$mustLiveInIncludes = [
    'dbConnectionOptions' => 'includes/db.php',
    'sslApplyCurl'        => 'includes/ssl.php',
    'sslResolveCaBundle'  => 'includes/ssl.php',
];
foreach ($mustLiveInIncludes as $fn => $home) {
    $src = file_exists("$root/$home") ? file_get_contents("$root/$home") : '';
    ok("$fn() is defined in $home", (bool)preg_match('/function\s+' . $fn . '\s*\(/', $src));
}

echo "\n3. Positive control: the app works with a config.php that defines nothing\n";

// This is the test that would have failed in #1446. It builds the situation every
// Docker user and every upgrader was actually in - a config.php supplying only
// values - and asks whether the product can still open a connection.
$stub = sys_get_temp_dir() . '/freeitsm_stub_config_' . getmypid() . '.php';
file_put_contents($stub, "<?php\n// values only, exactly like docker/config.php\ndefine('STUB_CONFIG_LOADED', true);\n");

$probe = sys_get_temp_dir() . '/freeitsm_probe_' . getmypid() . '.php';
file_put_contents($probe, '<?php
require_once ' . var_export($stub, true) . ';
require_once ' . var_export("$root/includes/db.php", true) . ';
require_once ' . var_export("$root/includes/ssl.php", true) . ';
echo function_exists("dbConnectionOptions") && function_exists("sslApplyCurl") ? "BOTH" : "MISSING";
$o = dbConnectionOptions();
echo isset($o[PDO::MYSQL_ATTR_INIT_COMMAND]) && strpos($o[PDO::MYSQL_ATTR_INIT_COMMAND], "+00:00") !== false ? "|UTC" : "|NOTUTC";
');
$out = trim((string)shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($probe) . ' 2>&1'));
ok('a values-only config.php still yields both functions', strpos($out, 'BOTH') !== false, $out);
ok('and the connection options still pin UTC', strpos($out, '|UTC') !== false, $out);

echo "\n4. The definition is guarded, so a stale config.php cannot redeclare it\n";

// An install upgrading from #1446 still carries the copy in its own config.php,
// and every caller loads config.php first. Without the guard those installs trade
// "undefined function" for "cannot redeclare" - the same outage, different message.
$legacy = sys_get_temp_dir() . '/freeitsm_legacy_' . getmypid() . '.php';
file_put_contents($legacy, '<?php
function dbConnectionOptions(): array { return [PDO::MYSQL_ATTR_INIT_COMMAND => "SET time_zone = \'+00:00\'"]; }
require_once ' . var_export("$root/includes/db.php", true) . ';
echo "SURVIVED";
');
$out2 = trim((string)shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($legacy) . ' 2>&1'));
ok('loading includes/db.php over a legacy definition does not fatal',
   strpos($out2, 'SURVIVED') !== false, $out2);

@unlink($stub); @unlink($probe); @unlink($legacy);

echo "\n5. Every entry point that CALLS a helper also LOADS its home itself\n";

// 🔴 WHAT THIS EXISTS TO CATCH, and why sections 1-4 could not.
// Sections above ask "does the function have a HOME?". A real user hit
// `Call to undefined function sslApplyCurl()` connecting a Microsoft 365 mailbox
// anyway (2026-09-17), because the question that matters is the other one: "does
// each CALLER load that home?". auth/oauth_callback.php called it while loading
// only config.php + db.php + encryption.php - and config.php is the OPERATOR'S
// file. His copy predated the `require_once includes/ssl.php` line, so on his
// install the definition was simply not there. His D006 confirmed it: SSL_CA_BUNDLE
// not defined, so his config.php lacks that whole block.
//
// 🔑 THE ONE RULE THAT MAKES THIS AUDIT MEAN ANYTHING: walk the include graph with
// config.php's OWN EDGES CUT. An earlier version of this audit let paths run THROUGH
// config.php and therefore cleared oauth_callback.php - the one file already known to
// be broken. If the operator's file is what carries you to the definition, you have
// proved the bug, not its absence.

$helperHomes = [
    'sslApplyCurl'        => 'includes/ssl.php',
    'sslResolveCaBundle'  => 'includes/ssl.php',
    'dbConnectionOptions' => 'includes/db.php',
];

/**
 * Real call sites only, via the tokeniser.
 *
 * ⚠️ A regex cannot do this. `sslApplyCurl()` appears inside a description STRING in
 * system/debug-tools/includes/tools.php, and a regex audit reported that file as a
 * broken caller. Comments and string literals are not calls.
 */
$callsHelper = static function (string $src, string $fn): bool {
    $tokens = @token_get_all($src);
    if (!is_array($tokens)) return false;
    $count = count($tokens);
    $prev = null;
    foreach ($tokens as $i => $t) {
        if (is_array($t) && $t[0] === T_STRING && $t[1] === $fn) {
            // Not a method or static call, and not the declaration itself.
            $isMemberOrDecl = is_array($prev)
                && in_array($prev[0], [T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION], true);
            if (!$isMemberOrDecl) {
                // Followed by '(' - skipping trivia - makes it a call.
                for ($j = $i + 1; $j < $count; $j++) {
                    $n = $tokens[$j];
                    if (is_array($n) && in_array($n[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) continue;
                    if ($n === '(') return true;
                    break;
                }
            }
        }
        if (!is_array($t) || $t[0] !== T_WHITESPACE) $prev = $t;
    }
    return false;
};

/** The files a given file requires, resolved to real paths. */
$requiresOf = static function (string $file, string $root): array {
    $src = (string)@file_get_contents($file);
    $dir = dirname($file);
    $hits = [];
    $patterns = [
        ['/(?:require|include)(?:_once)?\s*\(?\s*__DIR__\s*\.\s*([\'"])([^\'"]+)\1/',          static function ($rel) use ($dir) { return $dir . $rel; }],
        ['/(?:require|include)(?:_once)?\s*\(?\s*dirname\(__DIR__\)\s*\.\s*([\'"])([^\'"]+)\1/', static function ($rel) use ($dir) { return dirname($dir) . $rel; }],
        ['/(?:require|include)(?:_once)?\s*\(?\s*([\'"])([^\'"$]+\.php)\1/',                    static function ($rel) use ($dir) { return $dir . '/' . $rel; }],
    ];
    foreach ($patterns as $pair) {
        list($re, $make) = $pair;
        if (preg_match_all($re, $src, $m)) {
            foreach ($m[2] as $rel) {
                $p = realpath($make($rel));
                if (!$p) $p = realpath($root . '/' . ltrim($rel, '/'));
                if ($p) $hits[] = str_replace('\\', '/', $p);
            }
        }
    }
    return array_values(array_unique($hits));
};

/** Reachability through requires, with the operator's file cut out of the graph. */
$reaches = static function (string $file, string $target, string $root) use ($requiresOf): bool {
    $target = str_replace('\\', '/', (string)realpath($target));
    $start  = str_replace('\\', '/', (string)realpath($file));
    $seen  = [];
    $queue = [$start];
    while ($queue) {
        $cur = array_pop($queue);
        if ($cur === '' || isset($seen[$cur])) continue;
        $seen[$cur] = true;
        // 🔴 Cut the operator's file - unless it IS the file under test. This is
        // the whole point of the section; see the note above.
        if ($cur !== $start && preg_match('#(^|/)config\.php$#', $cur)) continue;
        foreach ($requiresOf($cur, $root) as $r) {
            if ($r === $target) return true;
            $queue[] = $r;
        }
    }
    return false;
};

// Walk the tree once.
$rootFwd = str_replace('\\', '/', $root);
$allPhp = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    if (!$f->isFile() || strtolower($f->getExtension()) !== 'php') continue;
    $p = str_replace('\\', '/', $f->getPathname());
    if (preg_match('#/(vendor|node_modules|\.git|tests)/#', $p)) continue;
    $allPhp[] = $p;
}

$checked = 0;
$broken  = [];

foreach ($helperHomes as $fn => $homeRel) {
    $home = "$rootFwd/$homeRel";
    foreach ($allPhp as $file) {
        $rel = ltrim(str_replace($rootFwd, '', $file), '/');

        // The home declares it, and config.php is the operator's file - cut by design.
        if ($rel === $homeRel) continue;
        if (preg_match('#(^|/)config\.php$#', $rel)) continue;

        // A library under an includes/ directory only ever executes through a caller,
        // and loading the home is that caller's job. Same for _-prefixed partials,
        // which are always required after functions.php.
        if (preg_match('#(^|/)includes/#', $rel)) continue;
        if (preg_match('#(^|/)_[^/]+\.php$#', $rel)) continue;

        if (!$callsHelper((string)file_get_contents($file), $fn)) continue;

        $checked++;
        if (!$reaches($file, $home, $rootFwd)) {
            $broken[] = "$rel calls $fn() but never loads $homeRel";
        }
    }
}

ok('every directly-requestable caller loads the helper it calls', $broken === [],
   $broken ? "\n       " . implode("\n       ", $broken) : "$checked call sites checked, config.php cut");

// 🔑 The rule must have found something. A pattern that silently matched nothing
// would print exactly the same PASS as a clean install.
ok('the audit actually examined some call sites', $checked > 0, "$checked found");

// Positive control: reintroduce the fault and require that it is caught.
$faulty = sys_get_temp_dir() . '/freeitsm_faulty_caller_' . getmypid() . '.php';
file_put_contents($faulty,
    "<?php\nrequire_once " . var_export("$rootFwd/config.php", true) . ";\n\$ch = curl_init();\nsslApplyCurl(\$ch);\n");
ok('positive control: a caller reaching ssl.php ONLY via config.php is flagged',
   !$reaches($faulty, "$rootFwd/includes/ssl.php", $rootFwd),
   'a config.php-only path must not count as reaching it');
@unlink($faulty);

echo "\n6. A config.php with no SSL block still gets a CA bundle\n";

// SSL_CA_BUNDLE is defined by config.php - the OPERATOR'S file. sslApplyCurl() used
// to attach a bundle only when that constant existed, so an install whose config.php
// lacked the block verified with no bundle and died on "unable to get local issuer
// certificate" instead. Reproduced live on a stripped config.php before this changed.
$noBundle = sys_get_temp_dir() . '/freeitsm_nobundle_' . getmypid() . '.php';
file_put_contents($noBundle, '<?php
define("SSL_VERIFY_PEER", true);        // values only, and deliberately NO SSL_CA_BUNDLE
require_once ' . var_export("$root/includes/ssl.php", true) . ';
$ch = curl_init();
sslApplyCurl($ch);
// CURLINFO_CAINFO is not readable back, so assert on the resolver the fallback uses.
echo sslResolveCaBundle() !== "" || stripos(PHP_OS, "WIN") !== 0 ? "RESOLVED" : "EMPTY";
');
$out3 = trim((string)shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($noBundle) . ' 2>&1'));
ok('sslApplyCurl survives an undefined SSL_CA_BUNDLE and finds a bundle',
   strpos($out3, 'RESOLVED') !== false, $out3);

// And the operator's own value must still win when they have one.
$ownBundle = sys_get_temp_dir() . '/freeitsm_ownbundle_' . getmypid() . '.php';
file_put_contents($ownBundle, '<?php
define("SSL_VERIFY_PEER", true);
define("SSL_CA_BUNDLE", "Z:/operators/own/cacert.pem");
require_once ' . var_export("$root/includes/ssl.php", true) . ';
echo SSL_CA_BUNDLE === "Z:/operators/own/cacert.pem" ? "THEIRS" : "OVERRIDDEN";
');
$out4 = trim((string)shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($ownBundle) . ' 2>&1'));
ok("the operator's own SSL_CA_BUNDLE is not overridden", strpos($out4, 'THEIRS') !== false, $out4);

@unlink($noBundle); @unlink($ownBundle);

echo "\n7. BASE_URL: every entry point that USES it reaches includes/base_url.php itself\n";

// 🔴 The last thing config.php supplied with no fallback: 300+ uses, and on PHP 8
// an undefined constant is a thrown Error - every page at once. includes/base_url.php
// now defines it when config.php has not; this asks section 5's question of it,
// with config.php's edges cut, because the operator's file is the thing that may
// be missing the line.

/** A real use of the BASE_URL constant (not the string 'BASE_URL' in defined()). */
$usesBaseUrl = static function (string $src): bool {
    $tokens = @token_get_all($src);
    if (!is_array($tokens)) return false;
    $prev = null;
    foreach ($tokens as $t) {
        if (is_array($t) && $t[0] === T_STRING && $t[1] === 'BASE_URL') {
            $isMember = is_array($prev) && in_array($prev[0], [T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_CONST], true);
            if (!$isMember) return true;
        }
        if (!is_array($t) || $t[0] !== T_WHITESPACE) $prev = $t;
    }
    return false;
};

$buHome = "$rootFwd/includes/base_url.php";
$buChecked = 0;
$buBroken = [];
foreach ($allPhp as $file) {
    $rel = ltrim(str_replace($rootFwd, '', $file), '/');
    if ($rel === 'includes/base_url.php') continue;
    if (preg_match('#(^|/)config\.php$#', $rel)) continue;          // the operator's file
    if (preg_match('#(^|/)includes/#', $rel)) continue;              // libraries run through a caller
    if (preg_match('#(^|/)_[^/]+\.php$#', $rel)) continue;           // _partials, likewise
    if (!$usesBaseUrl((string)file_get_contents($file))) continue;
    $buChecked++;
    if (!$reaches($file, $buHome, $rootFwd)) $buBroken[] = $rel;
}
ok('every directly-requestable user of BASE_URL loads base_url.php', $buBroken === [],
   $buBroken ? "\n       " . implode("\n       ", $buBroken) : "$buChecked entry points checked, config.php cut");
ok('the BASE_URL audit actually examined some files', $buChecked > 50, "$buChecked found");

// Positive control: the fallback itself, with a config.php that never defines it.
$noBase = sys_get_temp_dir() . '/freeitsm_nobase_' . getmypid() . '.php';
file_put_contents($noBase, '<?php
$_SERVER["DOCUMENT_ROOT"] = ' . var_export(dirname($rootFwd), true) . ';
require_once ' . var_export("$rootFwd/includes/base_url.php", true) . ';
echo defined("BASE_URL") ? "DEFINED:" . BASE_URL : "UNDEFINED";
');
$out5 = trim((string)shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($noBase) . ' 2>&1'));
$want = '/' . basename($rootFwd) . '/';
ok("a config.php without BASE_URL still gets '$want'", $out5 === "DEFINED:$want", $out5);

// And the operator's own value wins.
$ownBase = sys_get_temp_dir() . '/freeitsm_ownbase_' . getmypid() . '.php';
file_put_contents($ownBase, '<?php
define("BASE_URL", "/helpdesk/");
require_once ' . var_export("$rootFwd/includes/base_url.php", true) . ';
echo BASE_URL;
');
$out6 = trim((string)shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($ownBase) . ' 2>&1'));
ok("the operator's own BASE_URL is not overridden", $out6 === '/helpdesk/', $out6);
@unlink($noBase); @unlink($ownBase);

// --------------------------------------------------------------------------
echo "\n8. The config requirements list (D017) matches both templates and leaks nothing\n";
// One list - includes/config_requirements.php - says what config.php should
// define. If a template gains a constant the list does not know, or the list
// claims a default that shipped code does not provide, the diagnostic lies.
require_once "$root/includes/config_requirements.php";
$reqs = configRequirements();
foreach (['config.php', 'docker/config.php'] as $rel) {
    $shape = configFileShape("$root/$rel");
    $unlisted = array_diff($shape['defines'], array_keys($reqs));
    ok("every constant $rel defines is on the list", $shape['readable'] && $unlisted === [],
       $unlisted ? implode(', ', $unlisted) : count($shape['defines']) . ' defines');
}
$docker = configFileShape("$root/docker/config.php");
$missingReq = [];
$noDefault  = [];
foreach ($reqs as $name => $r) {
    if ($r['kind'] === 'required') {
        if (!in_array($name, $docker['defines'], true)) $missingReq[] = $name;
    } else {
        $src = @file_get_contents("$root/" . ($r['fallback'] ?? '-'));
        if ($src === false || !preg_match("/defined\(\s*['\"]" . $name . "['\"]\s*\)/", $src)) $noDefault[] = $name;
    }
}
ok('docker/config.php defines every "required" setting', $missingReq === [], implode(', ', $missingReq));
ok('every "default" setting is really handled by its shipped file', $noDefault === [], implode(', ', $noDefault));

// Positive controls: the scan can come back negative.
$fake = sys_get_temp_dir() . '/freeitsm_fakecfg_' . getmypid() . '.php';
file_put_contents($fake, "<?php\n// define('COMMENTED_OUT', 1);\ndefine('DB_PASSWORD', 'PLANTED-pw-7f3a');\n"
    . "define('BASE_URL', '/planted-path-9c1e/');\ndefine('SOMETHING_NEW', 'PLANTED-new-2b4d');\n"
    . "function legacyHelper() { return 'PLANTED-fn-5e6f'; }\n");
$fr = configRequirementsReport($fake);
ok('positive control: an unlisted constant is reported', $fr['unknown'] === ['SOMETHING_NEW'], implode(',', $fr['unknown']));
ok('positive control: a function in config.php is reported', $fr['functions'] === ['legacyHelper']);
$baseRow = array_values(array_filter($fr['rows'], fn($r) => $r['name'] === 'BASE_URL'))[0];
ok('a define() in the file counts; a commented-out one does not',
   $baseRow['inConfig'] === true && !in_array('COMMENTED_OUT', $fr['unknown'], true));
ok('the report carries no planted value', strpos(json_encode($fr), 'PLANTED') === false
   && strpos(json_encode($fr), 'planted') === false);
@unlink($fake);

// The real tool, over HTTP, as an administrator: it runs, and the real
// database password is nowhere in what it prints. Then without a session.
require_once "$root/config.php";
$base = rtrim(getenv('FREEITSM_URL') ?: 'http://localhost/' . basename($root) . '/', '/') . '/';
$sdir = rtrim(ini_get('session.save_path') ?: 'c:/wamp64/tmp', '/\\');
$sid  = 'cfgtest' . bin2hex(random_bytes(4));
file_put_contents("$sdir/sess_$sid", 'analyst_id|i:1;analyst_name|s:13:"Administrator";is_admin|i:1;');
$get = function (?string $cookie) use ($base) {
    $ch = curl_init($base . 'api/system/debug-tools/D017_config_completeness.php');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20]);
    if ($cookie) curl_setopt($ch, CURLOPT_COOKIE, 'PHPSESSID=' . $cookie);
    $body = (string)curl_exec($ch);
    return [(int)curl_getinfo($ch, CURLINFO_HTTP_CODE), $body];
};
[$code, $body] = $get($sid);
@unlink("$sdir/sess_$sid");
if ($code === 0) {
    ok('D017 reachable over HTTP (set FREEITSM_URL if not on localhost)', false, 'no web server answered');
} else {
    ok('D017 runs for an administrator', $code === 200 && strpos($body, '=== VERDICT ===') !== false, "HTTP $code");
    ok('D017 lists every setting by name', count(array_filter(array_keys($reqs), fn($n) => strpos($body, $n) !== false)) === count($reqs));
    $secret = defined('DB_PASSWORD') ? (string)DB_PASSWORD : '';
    ok('D017 never prints the database password', strlen($secret) < 4 || strpos($body, $secret) === false,
       strlen($secret) < 4 ? 'password too short to test' : '');
    [$code2] = $get(null);
    ok('D017 refuses without a session', $code2 === 403, "HTTP $code2");
}

echo "\n" . str_repeat('-', 78) . "\n";
printf("  %d passed, %d failed\n\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
