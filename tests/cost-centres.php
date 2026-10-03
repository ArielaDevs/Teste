<?php
/**
 * Cost centres (GH #160): the spreadsheet reader/writer and CostCentresService.
 *
 *   php tests/cost-centres.php
 *
 * Part 1 needs nothing. Part 2 writes to the database it finds, but inside ONE
 * transaction that is rolled back at the end, so it leaves nothing behind -
 * including the second company it makes to test the company rules. Needs
 * Database Verification to have run (cost_centres).
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
chdir(dirname(__DIR__));
require 'config.php';
require 'includes/functions.php';
require 'includes/spreadsheet.php';
require 'includes/services/cost_centres.php';

$pass = 0; $fail = 0;
function check($label, $got, $want) {
    global $pass, $fail;
    if ($got === $want) { $pass++; echo "  ok    $label\n"; }
    else { $fail++; echo "  FAIL  $label\n        got  " . json_encode($got, JSON_UNESCAPED_UNICODE) . "\n        want " . json_encode($want, JSON_UNESCAPED_UNICODE) . "\n"; }
}
/** The ServiceError code a call throws, or 'ok'. */
function throws(callable $fn): string {
    try { $fn(); return 'ok'; } catch (ServiceError $e) { return $e->errorCode; }
}
$tmp = sys_get_temp_dir() . '/cc-test-' . getmypid();
@mkdir($tmp);

// ─────────────────────────────────────────────────────────────────────────────
echo "Part 1 - spreadsheets\n";
// ─────────────────────────────────────────────────────────────────────────────
$rows = [['0010', 'Finance', 'Desc, with comma', ''], ['N1414', 'Ops "quoted"', '', '0010'], ['=SUM(A1)', 'Émile', '', ''], ['-5', 'neg', '', '']];
file_put_contents("$tmp/a.xlsx", spreadsheetWriteXlsx('Cost centres', ['Code', 'Name', 'Description', 'Parent code'], $rows));
file_put_contents("$tmp/a.csv", spreadsheetWriteCsv(['Code', 'Name', 'Description', 'Parent code'], $rows));
$x = spreadsheetRead("$tmp/a.xlsx", 'a.xlsx');
check('xlsx round trip keeps 0010, quotes, accents and a formula-looking value', array_slice($x, 1), $rows);
check('csv round trip is the same', spreadsheetRead("$tmp/a.csv", 'a.csv'), $x);
check('csv export guards a formula with an apostrophe', strpos(file_get_contents("$tmp/a.csv"), "'=SUM(A1)") !== false, true);
check('csv export leaves a plain negative number alone', strpos(file_get_contents("$tmp/a.csv"), "\r\n-5,") !== false, true);

file_put_contents("$tmp/semi.csv", "Kostenstelle;Bezeichnung\r\n0010;Café\r\n\r\n");
check('semicolon CSV (European Excel), blank line dropped', spreadsheetRead("$tmp/semi.csv", 'semi.csv'), [['Kostenstelle', 'Bezeichnung'], ['0010', 'Café']]);
file_put_contents("$tmp/ansi.csv", mb_convert_encoding("Code,Name\n7,Café\n", 'Windows-1252', 'UTF-8'));
check('Windows-1252 CSV becomes UTF-8', spreadsheetRead("$tmp/ansi.csv", 'ansi.csv')[1], ['7', 'Café']);

// A workbook shaped the way Excel saves one: DEFLATED entries, shared strings,
// and a code typed as the number 10 but formatted "0000" so it shows 0010.
if (class_exists('ZipArchive')) {
    $z = new ZipArchive();
    $z->open("$tmp/excel.xlsx", ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $z->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"/>');
    $z->addFromString('xl/workbook.xml', '<?xml version="1.0"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Data" sheetId="1" r:id="rId7"/></sheets></workbook>');
    $z->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId7" Type="worksheet" Target="worksheets/data.xml"/></Relationships>');
    $z->addFromString('xl/sharedStrings.xml', '<?xml version="1.0"?><sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><si><t>Code</t></si><si><t>Name</t></si><si><r><t>Fin</t></r><r><t>ance</t></r></si><si><t>N1414</t></si><si><t>Ops</t></si></sst>');
    $z->addFromString('xl/styles.xml', '<?xml version="1.0"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><numFmts count="1"><numFmt numFmtId="164" formatCode="0000"/></numFmts><cellXfs count="2"><xf numFmtId="0"/><xf numFmtId="164"/></cellXfs></styleSheet>');
    $z->addFromString('xl/worksheets/data.xml', '<?xml version="1.0"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'
        . '<row r="1"><c r="A1" t="s"><v>0</v></c><c r="B1" t="s"><v>1</v></c></row>'
        . '<row r="2"><c r="A2" s="1"><v>10</v></c><c r="B2" t="s"><v>2</v></c></row>'
        . '<row r="4"><c r="A4" t="s"><v>3</v></c><c r="C4" t="s"><v>4</v></c></row>'
        . '</sheetData></worksheet>');
    for ($i = 0; $i < $z->numFiles; $i++) $z->setCompressionIndex($i, ZipArchive::CM_DEFLATE);
    $z->close();
    check('Excel-style workbook: deflated, shared strings, rich text, "0000" format, gaps, sheet found via rels',
        spreadsheetRead("$tmp/excel.xlsx", 'excel.xlsx'), [['Code', 'Name'], ['0010', 'Finance'], ['N1414', '', 'Ops']]);
} else {
    echo "  skip  Excel-style workbook (no ZipArchive here to build one)\n";
}
file_put_contents("$tmp/junk.xlsx", "PK\x03\x04not really a zip");
check('a damaged workbook is refused with a message, not a crash', (function () use ($tmp) {
    try { spreadsheetRead("$tmp/junk.xlsx", 'junk.xlsx'); return 'read'; } catch (RuntimeException $e) { return 'refused'; }
})(), 'refused');
array_map('unlink', glob("$tmp/*"));
@rmdir($tmp);

// ─────────────────────────────────────────────────────────────────────────────
echo "\nPart 2 - CostCentresService\n";
// ─────────────────────────────────────────────────────────────────────────────
$c = connectToDatabase();
if (!CostCentresService::ready($c)) { echo "  skip  cost_centres table missing - run Database Verification\n"; goto done; }
$c->beginTransaction();

$T1 = getDefaultTenantId($c);
$c->exec("INSERT INTO tenants (name, is_active, is_default) VALUES ('Beta (cost centres test)', 1, 0)");
$T2 = (int)$c->lastInsertId();
$all = new ActorContext(1, null, 'ui');
$onlyT2 = new ActorContext(1, [$T2], 'ui');
$c->prepare("DELETE FROM cost_centres WHERE tenant_id IN (?, ?)")->execute([$T1, $T2]);   // rolled back with the rest

$fin = CostCentresService::create($c, $all, $T1, ['code' => ' 0010 ', 'name' => 'Finance']);
check('code is trimmed and keeps its leading zeros', CostCentresService::load($c, $all, $fin)['code'], '0010');
check('a new cost centre is active', (int)CostCentresService::load($c, $all, $fin)['is_active'], 1);
$ops = CostCentresService::create($c, $all, $T1, ['code' => 'N1414', 'name' => 'Ops', 'parent_code' => '0010']);
check('parent by code', (int)CostCentresService::load($c, $all, $ops)['parent_id'], $fin);
check('same code with different capitals is refused', throws(fn() => CostCentresService::create($c, $all, $T1, ['code' => 'n1414', 'name' => 'x'])), 'conflict');
check('another company may use the same code', throws(fn() => CostCentresService::create($c, $all, $T2, ['code' => '0010', 'name' => 'Beta finance'])), 'ok');
check('a parent in another company is refused', throws(fn() => CostCentresService::create($c, $all, $T2, ['code' => 'B1', 'name' => 'x', 'parent_id' => $fin])), 'invalid_field');
check('a name is required', throws(fn() => CostCentresService::create($c, $all, $T1, ['code' => 'X1'])), 'missing_field');
check('a 51-character code is refused', throws(fn() => CostCentresService::create($c, $all, $T1, ['code' => str_repeat('9', 51), 'name' => 'x'])), 'invalid_field');
check('its own parent is refused', throws(fn() => CostCentresService::update($c, $all, $fin, ['parent_id' => $fin])), 'invalid_field');
check('a parent below itself (a loop) is refused', throws(fn() => CostCentresService::update($c, $all, $fin, ['parent_id' => $ops])), 'invalid_field');
check('renaming to a code in use is refused', throws(fn() => CostCentresService::update($c, $all, $ops, ['code' => '0010'])), 'conflict');
check('PATCH-style update touches only what is sent', (function () use ($c, $all, $ops) {
    CostCentresService::update($c, $all, $ops, ['is_active' => false]);
    $r = CostCentresService::load($c, $all, $ops);
    return [(int)$r['is_active'], $r['name'], (int)$r['parent_id'] > 0];
})(), [0, 'Ops', true]);
check('deleting a parent is refused', throws(fn() => CostCentresService::delete($c, $all, $fin)), 'conflict');
check('a company outside the scope: by id is not found', throws(fn() => CostCentresService::load($c, $onlyT2, $fin)), 'not_found');
check('a company outside the scope: create is forbidden', throws(fn() => CostCentresService::create($c, $onlyT2, $T1, ['code' => 'Z', 'name' => 'z'])), 'forbidden');
check('a company outside the scope: list is forbidden', throws(fn() => CostCentresService::listForCompany($c, $onlyT2, $T1)), 'forbidden');
check('companiesFor honours the scope', array_column(CostCentresService::companiesFor($c, $onlyT2), 'id'), [$T2]);

// Sync
$count = fn() => (int)$c->query("SELECT COUNT(*) FROM cost_centres WHERE tenant_id = $T1")->fetchColumn();
$before = $count();
$r = CostCentresService::sync($c, $all, $T1, [
    ['line' => 2, 'code' => '0020', 'name' => 'IT', 'parent_code' => ''],
    ['line' => 3, 'code' => '0021', 'name' => 'Service desk', 'parent_code' => '0020'],   // parent from the same file
    ['line' => 4, 'code' => 'n1414', 'name' => 'Ops', 'is_active' => 'Yes'],             // capitals differ, reactivated
], ['dry_run' => true]);
check('dry run: counts', $r['counts'], ['create' => 2, 'update' => 1, 'unchanged' => 0, 'deactivate' => 0]);
check('dry run: the case change and reactivation are both seen', $r['rows'][2]['changes'], ['code', 'is_active']);
check('dry run writes nothing', [$r['applied'], $count()], [false, $before]);

$r = CostCentresService::sync($c, $all, $T1, [
    ['line' => 2, 'code' => '0020', 'name' => 'IT'],
    ['line' => 3, 'code' => 'NEW1'],                                     // new, no name
    ['line' => 4, 'code' => '0020', 'name' => 'dup'],                    // twice
    ['line' => 5, 'code' => 'P1', 'name' => 'x', 'parent_code' => 'NOPE'],
    ['line' => 6, 'code' => 'L1', 'name' => 'x', 'parent_code' => 'L2'],
    ['line' => 7, 'code' => 'L2', 'name' => 'x', 'parent_code' => 'L1'],  // a loop
    ['line' => 8, 'code' => 'A1', 'name' => 'x', 'is_active' => 'maybe'],
]);
check('bad rows: every problem reported with its line', array_column($r['errors'], 'line'), [3, 4, 8, 5, 6, 7]);
check('bad rows: ALL OR NOTHING - the good row was not written either', [$r['applied'], $count()], [false, $before]);

$r = CostCentresService::sync($c, $all, $T1, [
    ['code' => '0020', 'name' => 'IT', 'parent_code' => ''],
    ['code' => '0021', 'name' => 'Service desk', 'parent_code' => '0020'],
    ['code' => 'N1414', 'name' => 'Ops'],
], ['deactivate_missing' => true]);
check('apply: written, and 0010 (not in the list) made inactive', [$r['applied'], $r['counts']['create'], $r['counts']['deactivate']], [true, 2, 1]);
$byCode = [];
foreach (CostCentresService::listForCompany($c, $all, $T1) as $row) $byCode[$row['code']] = $row;
check('parent set from a code earlier in the same list', $byCode['0021']['parent_code'], '0020');
check('0010 made inactive, not deleted', (int)$byCode['0010']['is_active'], 0);
check('a key that was not sent is left alone (N1414 keeps its parent)', $byCode['N1414']['parent_code'], '0010');
check('is_active not sent = left as it was (N1414 stays inactive)', (int)$byCode['N1414']['is_active'], 0);

$r = CostCentresService::sync($c, $all, $T1, [['code' => '0020', 'name' => 'IT'], ['code' => '0021', 'name' => 'Service desk']]);
check('the same list again changes nothing', $r['counts'], ['create' => 0, 'update' => 0, 'unchanged' => 2, 'deactivate' => 0]);
check('sync into a company outside the scope is forbidden', throws(fn() => CostCentresService::sync($c, $onlyT2, $T1, [['code' => 'x', 'name' => 'x']])), 'forbidden');

$c->rollBack();
check('rolled back: nothing left behind', (int)$c->query("SELECT COUNT(*) FROM tenants WHERE name = 'Beta (cost centres test)'")->fetchColumn(), 0);

done:
echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
