<?php
/**
 * Spreadsheets in and out - CSV and Excel (.xlsx), in plain PHP.
 *
 * First used by System -> Cost Centres (GH #160); written to be reused by any
 * list that wants an import/export.
 *
 * 🔑 WHY .xlsx AND NOT JUST CSV: LEADING ZEROS. A cost centre "0010" written to
 * a CSV comes back as 10 the moment somebody double-clicks the file, because
 * Excel decides it is a number - and saving it again writes 10. An .xlsx can say
 * "this cell is text", so every cell this file WRITES is text, and Excel shows
 * 0010. CSV stays for systems that only speak CSV.
 *
 * 🔑 WHY NO ZipArchive: an .xlsx is a zip of XML files, but the Docker image
 * has no zip extension (docker-php-ext-install only adds pdo/pdo_mysql) and
 * plenty of shared hosts lack it too. Zip is simple enough to read and write
 * here with zlib, which every PHP has. The writer stores (no compression); the
 * reader handles stored and deflated entries, which is all Excel, LibreOffice
 * and Google Sheets produce.
 *
 * The reader is guarded like includes/search/extract.php - an uploaded zip is
 * untrusted: a cap on entries, on each entry's unzipped size and on the total.
 */

const SPREADSHEET_MAX_BYTES     = 20 * 1024 * 1024;   // the uploaded file
const SPREADSHEET_MAX_ENTRIES   = 2000;               // files inside an .xlsx
const SPREADSHEET_MAX_UNZIPPED  = 60 * 1024 * 1024;   // all the XML we will inflate
const SPREADSHEET_MAX_ROWS      = 50000;

/**
 * Read an uploaded CSV or .xlsx into rows of strings (the first sheet of a
 * workbook). Every value is a string exactly as the person sees it, trimmed.
 * Blank rows are dropped.
 *
 * @throws RuntimeException with a message fit to show a person
 * @return array<int, array<int, string>>
 */
function spreadsheetRead(string $path, string $originalName): array
{
    $size = @filesize($path);
    if ($size === false) throw new RuntimeException('The file could not be read.');
    if ($size > SPREADSHEET_MAX_BYTES) throw new RuntimeException('The file is too large (the limit is 20 MB).');

    $head = (string)@file_get_contents($path, false, null, 0, 4);
    $ext  = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    if (strncmp($head, "PK\x03\x04", 4) === 0) {
        $rows = spreadsheetReadXlsx($path);
    } elseif ($ext === 'xls') {
        throw new RuntimeException('Old .xls workbooks cannot be read. In Excel use File > Save As and choose "Excel Workbook (.xlsx)" or CSV.');
    } else {
        $rows = spreadsheetReadCsv($path);
    }

    $out = [];
    foreach ($rows as $row) {
        $row = array_map(fn($v) => trim(spreadsheetCleanText((string)$v)), $row);
        if (implode('', $row) === '') continue;
        $out[] = $row;
        if (count($out) > SPREADSHEET_MAX_ROWS) throw new RuntimeException('The file has more than ' . SPREADSHEET_MAX_ROWS . ' rows.');
    }
    return $out;
}

/** Strip control characters (bar tab) - a pasted line break must not reach the database. */
function spreadsheetCleanText(string $v): string
{
    return preg_replace('/[\x00-\x08\x0B-\x1F\x7F]/u', ' ', $v) ?? $v;
}

// ─────────────────────────────────────────────────────────────────────────────
//  CSV
// ─────────────────────────────────────────────────────────────────────────────

/**
 * CSV in any of the shapes Excel saves: UTF-8 with or without a BOM, or the
 * Windows code page; comma, semicolon (Excel in most of Europe) or tab.
 */
function spreadsheetReadCsv(string $path): array
{
    $raw = (string)file_get_contents($path);
    if (strncmp($raw, "\xEF\xBB\xBF", 3) === 0) {
        $raw = substr($raw, 3);
    } elseif (strncmp($raw, "\xFF\xFE", 2) === 0 || strncmp($raw, "\xFE\xFF", 2) === 0) {
        // "Unicode Text" from Excel is UTF-16 and tab separated.
        $raw = function_exists('mb_convert_encoding') ? mb_convert_encoding($raw, 'UTF-8', 'UTF-16') : $raw;
        $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw);
    } elseif (!preg_match('//u', $raw)) {
        $raw = function_exists('mb_convert_encoding') ? mb_convert_encoding($raw, 'UTF-8', 'Windows-1252') : (string)@iconv('Windows-1252', 'UTF-8//IGNORE', $raw);
    }

    // The delimiter is whichever of , ; tab appears most in the first line
    // (outside quotes is close enough for a header row).
    $firstLine = strtok($raw, "\r\n") ?: '';
    $counts = [',' => substr_count($firstLine, ','), ';' => substr_count($firstLine, ';'), "\t" => substr_count($firstLine, "\t")];
    arsort($counts);
    $delim = (string)array_key_first($counts);
    if ($counts[$delim] === 0) $delim = ',';

    $fh = fopen('php://temp', 'r+');
    fwrite($fh, $raw);
    rewind($fh);
    $rows = [];
    while (($row = fgetcsv($fh, 0, $delim, '"', '')) !== false) {
        if ($row === [null]) continue;
        // Undo the formula guard spreadsheetWriteCsv() adds, so an export
        // imports back unchanged.
        $rows[] = array_map(fn($v) => preg_match("/^'[=+\-@]/", (string)$v) ? substr((string)$v, 1) : (string)$v, $row);
        if (count($rows) > SPREADSHEET_MAX_ROWS + 1) break;
    }
    fclose($fh);
    return $rows;
}

/**
 * CSV bytes: UTF-8 with a BOM (so Excel reads accents correctly), CRLF.
 *
 * A cell that starts with = + - @ is prefixed with ' so a spreadsheet does not
 * run it as a formula ("CSV injection"); the reader takes the ' off again. A
 * plain negative number is left alone.
 */
function spreadsheetWriteCsv(array $header, array $rows): string
{
    // By hand rather than fputcsv(): its line-ending argument is PHP 8.1+, and
    // the floor is 7.4.
    $cell = function ($v) {
        $v = (string)$v;
        if ($v !== '' && strpbrk($v[0], '=+-@') !== false && !is_numeric($v)) $v = "'" . $v;
        return preg_match('/[",\r\n;]/', $v) ? '"' . str_replace('"', '""', $v) . '"' : $v;
    };
    $csv = implode(',', array_map($cell, $header)) . "\r\n";
    foreach ($rows as $r) $csv .= implode(',', array_map($cell, $r)) . "\r\n";
    return "\xEF\xBB\xBF" . $csv;
}

// ─────────────────────────────────────────────────────────────────────────────
//  XLSX - write
// ─────────────────────────────────────────────────────────────────────────────

/**
 * A one-sheet .xlsx. Every cell is an inline TEXT cell, never a number - that is
 * the point (see the file header). The header row is bold and frozen.
 *
 * @param array<int,int> $widths optional column widths in characters
 */
function spreadsheetWriteXlsx(string $sheetName, array $header, array $rows, array $widths = []): string
{
    $x = fn($s) => htmlspecialchars(spreadsheetCleanText((string)$s), ENT_XML1 | ENT_QUOTES, 'UTF-8');
    $col = function (int $index): string {   // 0 -> A, 26 -> AA
        $s = '';
        for ($n = $index + 1; $n > 0; $n = intdiv($n - 1, 26)) $s = chr(65 + ($n - 1) % 26) . $s;
        return $s;
    };
    $rowXml = function (array $cells, int $r, bool $bold) use ($x, $col): string {
        $out = '<row r="' . $r . '">';
        foreach (array_values($cells) as $i => $v) {
            $out .= '<c r="' . $col($i) . $r . '" t="inlineStr"' . ($bold ? ' s="1"' : ' s="2"') . '><is><t xml:space="preserve">' . $x($v) . '</t></is></c>';
        }
        return $out . '</row>';
    };

    $cols = '';
    foreach ($widths as $i => $w) $cols .= '<col min="' . ($i + 1) . '" max="' . ($i + 1) . '" width="' . (int)$w . '" customWidth="1"/>';
    $sheetRows = $rowXml($header, 1, true);
    foreach (array_values($rows) as $n => $r) $sheetRows .= $rowXml($r, $n + 2, false);

    $sheet = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
        . ($cols !== '' ? '<cols>' . $cols . '</cols>' : '')
        . '<sheetData>' . $sheetRows . '</sheetData></worksheet>';

    // Sheet names: max 31 characters, none of \ / ? * [ ] :
    $name = mb_substr(preg_replace('/[\\\\\/?*\[\]:]/', ' ', $sheetName), 0, 31) ?: 'Sheet1';

    $files = [
        '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . '</Types>',
        '_rels/.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>',
        'xl/workbook.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets><sheet name="' . $x($name) . '" sheetId="1" r:id="rId1"/></sheets></workbook>',
        'xl/_rels/workbook.xml.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            . '</Relationships>',
        // xf 1 = bold header, xf 2 = numFmt 49 ("@", Text) so a person typing a
        // new code into an exported sheet gets text too, not a number.
        'xl/styles.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts>'
            . '<fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills>'
            . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="3"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            . '<xf numFmtId="49" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1" applyNumberFormat="1"/>'
            . '<xf numFmtId="49" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/></cellXfs>'
            . '</styleSheet>',
        'xl/worksheets/sheet1.xml' => $sheet,
    ];
    return spreadsheetZipStore($files);
}

/** A zip with every entry STORED (no compression) - valid, and all Excel needs. */
function spreadsheetZipStore(array $files): string
{
    $data = ''; $central = ''; $n = 0;
    // A fixed DOS timestamp (2026-01-01 00:00) - the date inside is meaningless here.
    $dosTime = 0; $dosDate = ((2026 - 1980) << 9) | (1 << 5) | 1;
    foreach ($files as $name => $body) {
        $crc = crc32($body);
        $len = strlen($body);
        $offset = strlen($data);
        $local = pack('VvvvvvVVVvv', 0x04034b50, 20, 0x0800, 0, $dosTime, $dosDate, $crc, $len, $len, strlen($name), 0);
        $data .= $local . $name . $body;
        $central .= pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 20, 20, 0x0800, 0, $dosTime, $dosDate, $crc, $len, $len, strlen($name), 0, 0, 0, 0, 0, $offset) . $name;
        $n++;
    }
    $end = pack('VvvvvVVv', 0x06054b50, 0, 0, $n, $n, strlen($central), strlen($data), 0);
    return $data . $central . $end;
}

// ─────────────────────────────────────────────────────────────────────────────
//  XLSX - read
// ─────────────────────────────────────────────────────────────────────────────

/** The first worksheet of an .xlsx as rows of display strings. */
function spreadsheetReadXlsx(string $path): array
{
    $zip = spreadsheetZipOpen($path);

    // Which part is the first sheet: workbook.xml names it, its rels file maps
    // the id to a path. Fall back to sheet1.xml, which is right nearly always.
    $sheetPath = 'xl/worksheets/sheet1.xml';
    $wb = spreadsheetXml(spreadsheetZipGet($zip, 'xl/workbook.xml'));
    $rels = spreadsheetXml(spreadsheetZipGet($zip, 'xl/_rels/workbook.xml.rels'));
    if ($wb && $rels && isset($wb->sheets->sheet[0])) {
        $rid = (string)$wb->sheets->sheet[0]->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'];
        foreach ($rels->Relationship as $rel) {
            if ((string)$rel['Id'] === $rid) {
                $t = ltrim((string)$rel['Target'], '/');
                $sheetPath = strpos($t, 'xl/') === 0 ? $t : 'xl/' . $t;
                break;
            }
        }
    }

    $shared = [];
    if ($ss = spreadsheetXml(spreadsheetZipGet($zip, 'xl/sharedStrings.xml'))) {
        foreach ($ss->si as $si) $shared[] = spreadsheetInlineText($si);
    }

    // Number formats that are only zeros ("0000") pad a number to that many
    // digits on screen: a code typed as 10 and formatted 0000 SHOWS as 0010, so
    // that is what the person means. Map style index -> pad width.
    $pad = [];
    if ($st = spreadsheetXml(spreadsheetZipGet($zip, 'xl/styles.xml'))) {
        $fmts = [];
        if (isset($st->numFmts)) foreach ($st->numFmts->numFmt as $f) $fmts[(int)$f['numFmtId']] = (string)$f['formatCode'];
        if (isset($st->cellXfs)) {
            $i = 0;
            foreach ($st->cellXfs->xf as $xf) {
                $code = $fmts[(int)$xf['numFmtId']] ?? '';
                if (preg_match('/^0+$/', $code)) $pad[$i] = strlen($code);
                $i++;
            }
        }
    }

    $sheet = spreadsheetXml(spreadsheetZipGet($zip, $sheetPath));
    if (!$sheet) throw new RuntimeException('The workbook has no readable first sheet.');

    $rows = [];
    foreach ($sheet->sheetData->row as $row) {
        $cells = [];
        $next = 0;
        foreach ($row->c as $c) {
            $idx = spreadsheetColIndex((string)$c['r']);
            if ($idx === null) $idx = $next;
            $type = (string)$c['t'];
            if ($type === 's') {
                $v = $shared[(int)$c->v] ?? '';
            } elseif ($type === 'inlineStr') {
                $v = spreadsheetInlineText($c->is);
            } elseif ($type === 'b') {
                $v = ((string)$c->v) === '1' ? 'TRUE' : 'FALSE';
            } else {
                $v = (string)$c->v;
                if ($type === '' || $type === 'n') {
                    // 10.0 -> "10"; and the zero-padding format above.
                    if (is_numeric($v) && (float)$v == (int)$v && abs((float)$v) < 1e15) $v = (string)(int)$v;
                    $s = (int)$c['s'];
                    if (isset($pad[$s]) && ctype_digit($v)) $v = str_pad($v, $pad[$s], '0', STR_PAD_LEFT);
                }
            }
            $cells[$idx] = $v;
            $next = $idx + 1;
        }
        if ($cells) {
            $line = array_fill(0, max(array_keys($cells)) + 1, '');
            foreach ($cells as $i => $v) $line[$i] = $v;
            $rows[] = $line;
        }
        if (count($rows) > SPREADSHEET_MAX_ROWS + 1) break;
    }
    return $rows;
}

/** "BC12" -> 54 (zero-based column). */
function spreadsheetColIndex(string $ref): ?int
{
    if (!preg_match('/^([A-Z]+)\d*$/', strtoupper($ref), $m)) return null;
    $n = 0;
    foreach (str_split($m[1]) as $ch) $n = $n * 26 + (ord($ch) - 64);
    return $n - 1;
}

/** The text of an <si> or <is>: plain <t>, or rich-text runs <r><t>. Phonetic runs are skipped. */
function spreadsheetInlineText($node): string
{
    if (!$node) return '';
    if (isset($node->t)) return (string)$node->t;
    $s = '';
    foreach ($node->r as $r) $s .= (string)$r->t;
    return $s;
}

function spreadsheetXml(?string $xml)
{
    if ($xml === null || $xml === '') return null;
    $prev = libxml_use_internal_errors(true);
    // LIBXML_NONET: never fetch anything. Entities are not expanded (no LIBXML_NOENT).
    $doc = simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NONET | LIBXML_COMPACT);
    libxml_clear_errors();
    libxml_use_internal_errors($prev);
    return $doc ?: null;
}

/**
 * Read a zip's central directory. Returns [bytes, [name => entry]].
 * @throws RuntimeException
 */
function spreadsheetZipOpen(string $path): array
{
    $bin = (string)file_get_contents($path);
    // The end-of-central-directory record is in the last 64 KB + 22 bytes.
    $eocd = strrpos(substr($bin, -66000), "PK\x05\x06");
    if ($eocd === false) throw new RuntimeException('The file is not a valid Excel workbook.');
    $eocd += max(0, strlen($bin) - 66000);
    $e = unpack('Vsig/vdisk/vcddisk/vdiskentries/ventries/Vcdsize/Vcdoffset', substr($bin, $eocd, 20));
    if ($e['entries'] > SPREADSHEET_MAX_ENTRIES) throw new RuntimeException('The workbook has too many parts to be safe to open.');

    $entries = [];
    $p = $e['cdoffset'];
    for ($i = 0; $i < $e['entries']; $i++) {
        if (substr($bin, $p, 4) !== "PK\x01\x02") throw new RuntimeException('The workbook is damaged.');
        $h = unpack('vmade/vneed/vflags/vmethod/vtime/vdate/Vcrc/Vcsize/Vusize/vnamelen/vextralen/vcommentlen/vdisk/vint/Vext/Voffset', substr($bin, $p + 4, 42));
        $name = substr($bin, $p + 46, $h['namelen']);
        $entries[$name] = $h;
        $p += 46 + $h['namelen'] + $h['extralen'] + $h['commentlen'];
    }
    return ['bin' => $bin, 'entries' => $entries, 'inflated' => 0];
}

/** One entry's bytes, or null when it is not there. */
function spreadsheetZipGet(array &$zip, string $name): ?string
{
    $h = $zip['entries'][$name] ?? null;
    if ($h === null) return null;
    if ($h['usize'] > SPREADSHEET_MAX_UNZIPPED || $zip['inflated'] + $h['usize'] > SPREADSHEET_MAX_UNZIPPED) {
        throw new RuntimeException('The workbook is too large to open.');
    }
    $lh = unpack('Vsig/vneed/vflags/vmethod/vtime/vdate/Vcrc/Vcsize/Vusize/vnamelen/vextralen', substr($zip['bin'], $h['offset'], 30));
    if ($lh['sig'] !== 0x04034b50) throw new RuntimeException('The workbook is damaged.');
    $raw = substr($zip['bin'], $h['offset'] + 30 + $lh['namelen'] + $lh['extralen'], $h['csize']);
    if ($h['method'] === 0) {
        $out = $raw;
    } elseif ($h['method'] === 8) {
        // The size cap is enforced by gzinflate itself, so a zip that lies about
        // its sizes still cannot balloon past the limit.
        $out = @gzinflate($raw, SPREADSHEET_MAX_UNZIPPED - $zip['inflated']);
        if ($out === false) throw new RuntimeException('The workbook is damaged or too large to open.');
    } else {
        throw new RuntimeException('The workbook uses a compression this cannot read. Save it again from Excel as .xlsx, or as CSV.');
    }
    $zip['inflated'] += strlen($out);
    return $out;
}
