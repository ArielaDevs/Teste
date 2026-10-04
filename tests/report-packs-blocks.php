<?php
/* 🔴 NEVER OVER THE WEB. See tests/README.md. Reads the database only. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * Report Packs: every block's data function against this install's real data,
 * plus the rules around them. Read-only.
 *
 *   1. Every label the registry shows resolves in English (a missing key shows
 *      the key itself on screen, which looks finished to anyone who does not read it).
 *   2. Every toolbox item and every option value runs without an error and returns
 *      the shape its `kind` promises.
 *   3. A block from a module the analyst lacks is refused, not returned.
 *   4. Date ranges: last month is a whole calendar month in the viewer's zone, and
 *      a custom range is inclusive of both days.
 *   5. The design check drops what it does not recognise.
 *
 * Run: php tests/report-packs-blocks.php [admin_id] [restricted_analyst_id]
 */

$root = dirname(__DIR__);
require $root . '/config.php';
require_once $root . '/includes/functions.php';
require_once $root . '/includes/i18n.php';
require_once $root . '/includes/report_packs/design.php';
I18n::setLocale('en');

$adminId = (int)($argv[1] ?? 1);
$restricted = (int)($argv[2] ?? 39);
$conn = connectToDatabase();

$pass = 0; $fail = 0;
function check($ok, $label) {
    global $pass, $fail;
    echo ($ok ? '  PASS  ' : '  FAIL  ') . $label . "\n";
    $ok ? $pass++ : $fail++;
}

echo "1. Labels\n";
$cat = rpCatalogue($conn, $adminId);
$missing = [];
foreach ($cat['tools'] as $t) {
    foreach (['title', 'desc'] as $k) if (strpos($t[$k], 'reporting.') === 0) $missing[] = $t[$k];
}
foreach ($cat['handlers'] as $key => $h) {
    foreach ($h['opts'] as $o) {
        if (strpos($o['label'], 'reporting.') === 0) $missing[] = $o['label'];
        foreach ($o['values'] ?? [] as $v) if (strpos($v['label'], 'reporting.') === 0) $missing[] = $v['label'];
    }
}
foreach (RP_RANGE_PRESETS as $p) if (strpos(t('reporting.packs.range.' . $p), 'reporting.') === 0) $missing[] = $p;
check(!$missing, 'every toolbox, option and range label resolves' . ($missing ? ': ' . implode(', ', array_unique($missing)) : ''));
check(count($cat['tools']) >= 30, 'positive control: the toolbox has ' . count($cat['tools']) . ' items');

echo "2. Every block runs (as analyst $adminId, last 12 months)\n";
$criteria = ['range' => ['preset' => 'last_12_months'], 'tenant' => 'active'];
$shape = [
    'chart'  => fn($d) => isset($d['labels'], $d['series']) && count($d['series'][0]['values'] ?? []) === count($d['labels']),
    'kpi'    => fn($d) => !empty($d['tiles']) && isset($d['tiles'][0]['label'], $d['tiles'][0]['value']),
    'table'  => fn($d) => isset($d['columns'], $d['rows']) && is_array($d['rows']),
    'uptime' => fn($d) => isset($d['services']) && is_array($d['services']),
];
$runs = 0;
foreach (rpHandlers() as $key => $h) {
    // Every select value of every option, one at a time; the rest at defaults.
    $variants = [[]];
    foreach ($h['opts'] as $ok => $def) {
        if ($def['type'] === 'select') foreach (array_keys($def['values']) as $v) $variants[] = [$ok => $v];
        if ($def['type'] === 'bool') { $variants[] = [$ok => true]; $variants[] = [$ok => false]; }
    }
    $bad = [];
    foreach ($variants as $opts) {
        try {
            $d = rpBlockData($conn, $adminId, $key, $opts, $criteria);
            $runs++;
            if (($d['kind'] ?? '') !== $h['kind'] || !$shape[$h['kind']]($d)) $bad[] = json_encode($opts) . ' wrong shape';
        } catch (Throwable $e) {
            $bad[] = json_encode($opts) . ': ' . $e->getMessage();
        }
    }
    $n = rpBlockData($conn, $adminId, $key, [], $criteria);
    $size = $h['kind'] === 'chart' ? count($n['labels']) . ' labels' : ($h['kind'] === 'kpi' ? count($n['tiles']) . ' tiles'
          : ($h['kind'] === 'table' ? count($n['rows']) . ' rows' : count($n['services']) . ' services'));
    check(!$bad, "$key (" . count($variants) . " variants, default: $size)" . ($bad ? ' - ' . implode('; ', array_slice($bad, 0, 3)) : ''));
}
check($runs > 60, "positive control: $runs block runs");

echo "3. Module access is applied per block\n";
$denied = 0; $allowed = 0; $wrong = [];
foreach (rpHandlers() as $key => $h) {
    $can = analystCanAccessModule($conn, $restricted, $h['module']);
    try {
        rpBlockData($conn, $restricted, $key, [], $criteria);
        $can ? $allowed++ : $wrong[] = "$key returned data without {$h['module']}";
    } catch (RuntimeException $e) {
        $can ? $wrong[] = "$key refused although {$h['module']} is allowed: " . $e->getMessage() : $denied++;
    }
}
check(!$wrong, "analyst $restricted: $allowed allowed, $denied refused, as their modules say" . ($wrong ? ' - ' . implode('; ', $wrong) : ''));
check($denied > 0 && $allowed > 0, 'positive control: both outcomes occurred');

echo "4. Date ranges\n";
$r = rpResolveRange(['preset' => 'last_month'], 'America/Santo_Domingo');
$first = (new DateTimeImmutable('first day of last month', new DateTimeZone('America/Santo_Domingo')))->format('Y-m-01');
check($r['from_date'] === $first && substr($r['to_date'], 8) === (new DateTimeImmutable($first))->format('t'), "last month = $r[from_date] to $r[to_date]");
check($r['from_utc'] === $first . ' 04:00:00', "Santo Domingo midnight is 04:00 UTC ($r[from_utc])");
$c = rpResolveRange(['preset' => 'custom', 'from' => '2026-09-01', 'to' => '2026-09-30'], 'UTC');
check($c['from_date'] === '2026-09-01' && $c['to_date'] === '2026-09-30' && $c['to_utc'] === '2026-10-01 00:00:00' && $c['days'] === 30,
      'custom 1-30 September includes both days (ends ' . $c['to_utc'] . ', ' . $c['days'] . ' days)');
$x = rpResolveRange(['preset' => 'custom', 'from' => '2026-09-30', 'to' => '2026-09-01'], 'UTC');
check($x['from_date'] === '2026-09-01', 'a range entered backwards is turned round');

echo "5. The design check\n";
$dirty = rpDefaultDesign('Test');
$dirty['blocks'][] = ['id' => 'x1', 'type' => 'data', 'handler' => 'nope.nothing'];
$dirty['blocks'][] = ['id' => 'x2', 'type' => 'data', 'handler' => 'tickets.breakdown', 'opts' => ['by' => 'DROP TABLE', 'limit' => 99999, 'evil' => 1]];
$dirty['blocks'][] = ['id' => 'x3', 'type' => 'text', 'doc' => [['t' => 'script', 'r' => [['x' => 'hi', 'onclick' => 'x', 'c' => 'red;background:url(x)']]]]];
$dirty['header']['doc'][] = ['t' => 'img', 'src' => 'https://evil.example/beacon.png', 'w' => 9999];
$clean = rpCleanDesign($dirty, 'Test');
$ids = array_column($clean['blocks'], 'id');
check(!in_array('x1', $ids, true), 'a block with an unknown handler is dropped');
$x2 = $clean['blocks'][array_search('x2', $ids, true)];
check($x2['opts']['by'] === 'status' && $x2['opts']['limit'] === 50 && !isset($x2['opts']['evil']), 'options outside the schema are defaulted, clamped or dropped');
$x3 = $clean['blocks'][array_search('x3', $ids, true)];
check($x3['doc'][0]['t'] === 'p' && !isset($x3['doc'][0]['r'][0]['onclick']) && !isset($x3['doc'][0]['r'][0]['c']), 'an unknown paragraph type, attribute and colour are dropped');
$img = end($clean['header']['doc']);
check($img['src'] === 'logo' && $img['w'] === 180.0, 'a picture can only be the logo, at most 180 mm wide');
check(json_encode(rpCleanDesign($clean, 'Test')) === json_encode($clean), 'cleaning a clean design changes nothing');

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
