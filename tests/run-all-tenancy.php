<?php
/* 🔴 NEVER OVER THE WEB. See tests/README.md. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * Run every tenancy isolation/hardening test and summarise.
 *
 * Discovers tests/*tenant*isolation*.php, tests/*tenant*failclosed*.php and
 * tests/upload-recording-hardening.php, runs each with the current PHP binary
 * in order, captures exit codes, and prints one line per file.
 *
 * Exit codes: 0 = all passed (skips allowed), 1 = at least one FAILED.
 * A per-file exit of 2, or exit 0 with a "SKIP" marker in the output, counts
 * as SKIP (e.g. single-company installs) — not a failure.
 *
 * Usage:
 *   php tests/run-all-tenancy.php          human-readable summary
 *   php tests/run-all-tenancy.php --json   JSON summary for CI
 */

$asJson = in_array('--json', $argv ?? [], true);

$files = [];
foreach ((array)glob(__DIR__ . '/*isolation*.php') as $f) $files[] = $f;
foreach ((array)glob(__DIR__ . '/*failclosed*.php') as $f) $files[] = $f;
foreach ((array)glob(__DIR__ . '/*hardening*.php') as $f) $files[] = $f;
// Sprint-1 gap proofs (Agente 1 source): tests/tenant-isolation/run.php.
$legacy = __DIR__ . '/tenant-isolation/run.php';
if (is_file($legacy)) $files[] = $legacy;
$files = array_values(array_unique($files));
sort($files);
// The orchestrator never runs itself.
$files = array_values(array_filter($files, fn($f) => basename($f) !== 'run-all-tenancy.php'));

$results = [];
foreach ($files as $file) {
    $name = ltrim(str_replace(__DIR__, '', $file), '/\\');
    if ($name === '') $name = basename($file);
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($file) . ' 2>&1';
    $out = [];
    $code = 0;
    exec($cmd, $out, $code);
    $text = implode("\n", $out);
    if ($code === 2 || ($code === 0 && preg_match('/^\s*(SKIP|  SKIP)/m', $text))) {
        $status = 'SKIP';
    } elseif ($code === 0) {
        $status = 'PASS';
    } else {
        $status = 'FAIL';
    }
    // Last "N passed, M failed" line, when present.
    $tally = null;
    if (preg_match('/(\d+)\s+passed,\s*(\d+)\s+failed\s*$/m', $text, $m)) {
        $tally = ['passed' => (int)$m[1], 'failed' => (int)$m[2]];
    }
    $results[] = ['file' => $name, 'status' => $status, 'exit' => $code, 'tally' => $tally, 'output' => $text];
}

$counts = ['PASS' => 0, 'FAIL' => 0, 'SKIP' => 0];
foreach ($results as $r) $counts[$r['status']]++;

if ($asJson) {
    $slim = array_map(fn($r) => [
        'file' => $r['file'], 'status' => $r['status'],
        'exit' => $r['exit'], 'tally' => $r['tally'],
    ], $results);
    echo json_encode(['results' => $slim, 'summary' => $counts], JSON_PRETTY_PRINT) . "\n";
} else {
    echo "\nTenancy suite\n" . str_repeat('=', 70) . "\n";
    foreach ($results as $r) {
        $extra = $r['tally'] !== null ? " ({$r['tally']['passed']} passed, {$r['tally']['failed']} failed)" : '';
        printf("  %-42s %s%s\n", $r['file'], $r['status'], $extra);
        if ($r['status'] === 'FAIL') {
            foreach (explode("\n", $r['output']) as $line) {
                if (str_contains($line, 'FAIL')) echo "      {$line}\n";
            }
        }
    }
    echo str_repeat('-', 70) . "\n";
    echo "  PASS: {$counts['PASS']}  FAIL: {$counts['FAIL']}  SKIP: {$counts['SKIP']}\n";
}

exit($counts['FAIL'] > 0 ? 1 : 0);
