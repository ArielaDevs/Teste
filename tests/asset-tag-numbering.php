<?php
/* 🔴 NEVER OVER THE WEB. See tests/README.md. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * Asset tag numbering (PR #164) - AssetTagsService: the format, the counter,
 * the one lock every tag write goes through, and the rollback with a failed
 * asset.
 *
 * Based on Sandy's original suite. Settings come from AssetTagsService::
 * withSettings(), never from the live install's rows. Everything that writes
 * runs inside ONE transaction that is always rolled back; the single check that
 * needs the service to own its transaction fails before anything is written.
 * Rows are prefixed ZZTAG-.
 *
 * Run: php tests/asset-tag-numbering.php
 */

$root = dirname(__DIR__);
require_once "$root/config.php";
require_once "$root/includes/functions.php";
require_once "$root/includes/services/assets.php";
require_once "$root/includes/services/asset_tags.php";

$pass = 0; $fail = 0;
function ok(string $label, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; printf("  PASS %-70s\n", $label); }
    else       { $fail++; printf("  FAIL %-70s %s\n", $label, $detail); }
}
function refused(callable $fn, string $kind = 'conflict'): ?string {
    try { $fn(); return null; } catch (ServiceError $e) { return $e->kind === $kind ? $e->getMessage() : null; }
}

$conn = connectToDatabase();
$ctx  = ActorContext::system('Tag test');
$on   = ['asset_tag_autogen_enabled' => '1', 'asset_tag_format' => 'ZZTAG-{####}', 'asset_tag_start' => '1', 'asset_tag_scope' => 'per_company'];
$create = fn(string $host, ?string $typed = null) => AssetTagsService::createWithTag($conn, null, $typed, function (?string $tag) use ($conn, $host): int {
    $conn->prepare("INSERT INTO assets (hostname, asset_tag, first_seen) VALUES (?, ?, UTC_TIMESTAMP())")->execute([$host, $tag]);
    return (int)$conn->lastInsertId();
});

echo "\nAsset tag numbering\n" . str_repeat('=', 72) . "\n";

echo "\nFormats\n";
ok('the default is AST-{#####}', AssetTagsService::DEFAULTS['asset_tag_format'] === 'AST-{#####}');
ok('...and auto-generation is OFF until switched on', AssetTagsService::DEFAULTS['asset_tag_autogen_enabled'] === '0');
ok('a format needs a number', (bool)AssetTagsService::validateFormat('AST-'));
ok('...exactly one', (bool)AssetTagsService::validateFormat('{###}-{###}'));
ok('{TYPE} is a ticket token, not an asset one', (bool)AssetTagsService::validateFormat('{TYPE}-{####}'));
ok('spaces are refused', (bool)AssetTagsService::validateFormat('AST {####}'));
ok('POSITIVE CONTROL: {COMPANY}-LT-{YY}{####} is fine', AssetTagsService::validateFormat('{COMPANY}-LT-{YY}{####}') === []);
ok('the preview fills in {COMPANY} with a stand-in', AssetTagsService::preview(['asset_tag_format' => '{COMPANY}-{###}'], 7, 1) === ['ACME-007']);
ok('{###} is a minimum width, never a limit', AssetTagsService::preview(['asset_tag_format' => 'A{##}'], 123, 1) === ['A123']);

AssetTagsService::withSettings($on);

echo "\nA failed create gives its number back\n";
$before = AssetTagsService::nextNumber($conn, null);
$msg = null;
try {
    AssetTagsService::createWithTag($conn, null, null, function (?string $tag) { throw new RuntimeException('insert failed'); });
} catch (RuntimeException $e) { $msg = $e->getMessage(); }
ok('the failure reaches the caller', $msg === 'insert failed');
ok('...and the counter is where it was', AssetTagsService::nextNumber($conn, null) === $before, AssetTagsService::nextNumber($conn, null) . " vs $before");

$conn->beginTransaction();
try {
    echo "\nGenerating\n";
    $start = AssetTagsService::nextNumber($conn, null);
    $t1 = $create('ZZTAG-H1')['tag'];
    $t2 = $create('ZZTAG-H2')['tag'];
    ok('consecutive assets get consecutive tags', $t1 === sprintf('ZZTAG-%04d', $start) && $t2 === sprintf('ZZTAG-%04d', $start + 1), "$t1 $t2");
    $taken = sprintf('ZZTAG-%04d', $start + 2);
    $conn->prepare("INSERT INTO assets (hostname, asset_tag, first_seen) VALUES ('ZZTAG-SQUAT', ?, UTC_TIMESTAMP())")->execute([$taken]);
    $t3 = $create('ZZTAG-H3')['tag'];
    ok('a tag already in use is skipped, never duplicated', $t3 !== $taken && $t3 === sprintf('ZZTAG-%04d', $start + 3), $t3);

    echo "\nTyped-in tags\n";
    $typed = $create('ZZTAG-H4', 'ZZTAG-0500');
    ok('a typed tag is kept as typed', $typed['tag'] === 'ZZTAG-0500');
    ok('...and, having the generated shape and a higher number, winds the counter past it', $create('ZZTAG-H5')['tag'] === 'ZZTAG-0501');
    $clash = refused(fn() => $create('ZZTAG-H6', 'ZZTAG-0500'));
    ok('a typed tag already in use is refused...', $clash !== null);
    ok('...naming the asset that has it', $clash !== null && strpos($clash, 'ZZTAG-H4') !== false, (string)$clash);
    $free = $create('ZZTAG-H7', 'ZZTAG-ODD-1');
    ok('a typed tag of another shape is fine and leaves the counter alone', $free['tag'] === 'ZZTAG-ODD-1' && $create('ZZTAG-H8')['tag'] === 'ZZTAG-0502');

    echo "\nChanging a tag on an existing asset\n";
    $id7 = $free['id'];
    ok('a clash is refused there too', refused(fn() => AssetTagsService::assign($conn, $ctx, $id7, 'ZZTAG-0500')) !== null);
    $res = AssetTagsService::assign($conn, $ctx, $id7, 'ZZTAG-ODD-2');
    ok('a free tag is set', $res['asset_tag'] === 'ZZTAG-ODD-2' && !$res['unchanged']);
    $hist = $conn->query("SELECT old_value, new_value FROM asset_history WHERE asset_id = $id7 AND field_name = 'Asset tag'")->fetch(PDO::FETCH_ASSOC);
    ok('...with the change in the asset\'s history', $hist && $hist['old_value'] === 'ZZTAG-ODD-1' && $hist['new_value'] === 'ZZTAG-ODD-2', json_encode($hist));
    ok('the same tag again is "unchanged"', AssetTagsService::assign($conn, $ctx, $id7, 'ZZTAG-ODD-2')['unchanged']);

    echo "\nThe counter only goes forward\n";
    $now = AssetTagsService::nextNumber($conn, null);
    AssetTagsService::setNextNumber($conn, null, 1);
    ok('asking for a lower next number does nothing', AssetTagsService::nextNumber($conn, null) === $now);
    AssetTagsService::setNextNumber($conn, null, $now + 100);
    ok('POSITIVE CONTROL: a higher one is honoured', AssetTagsService::nextNumber($conn, null) === $now + 100);

    echo "\nScope\n";
    ok('per company: Default counts on its own key', AssetTagsService::counterKey($conn, $on, null) === 'asset:co0');
    ok('global: one key for the install', AssetTagsService::counterKey($conn, ['asset_tag_scope' => 'global'] + $on, 42) === 'asset');

    echo "\nSwitched off\n";
    AssetTagsService::withSettings(['asset_tag_autogen_enabled' => '0'] + $on);
    ok('no tag is generated', $create('ZZTAG-OFF')['tag'] === null);

    echo "\nThe discovered-asset path uses the same rules\n";
    AssetTagsService::withSettings($on);
    $d = AssetsService::createDiscoveredAsset($conn, $ctx, ['hostname' => 'ZZTAG-AGENT'], null, 'the test');
    $dt = (string)$conn->query("SELECT asset_tag FROM assets WHERE id = $d")->fetchColumn();
    ok('an agent-found asset gets the next tag too', preg_match('/^ZZTAG-\d{4,}$/', $dt) === 1, $dt);
} catch (Throwable $e) {
    ok('the run completed', false, get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
} finally {
    if ($conn->inTransaction()) $conn->rollBack();
    AssetTagsService::forget();
}

$left = (int)$conn->query("SELECT COUNT(*) FROM assets WHERE hostname LIKE 'ZZTAG-%'")->fetchColumn();
ok('nothing survived the run (rolled back)', $left === 0, "$left rows left");
echo "\n{$pass} passed, {$fail} failed\n";
exit($fail ? 1 : 0);
