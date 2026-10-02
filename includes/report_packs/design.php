<?php
/**
 * Report Packs: the design document - its shape, its starter, and the check every
 * save goes through.
 *
 * A pack's design is one JSON document (report_packs.design). The designer reads
 * and writes it whole; the layout engine (assets/js/report-packs/) turns it into
 * pages; nothing else interprets it.
 *
 * RICH TEXT IS NOT HTML
 * ---------------------
 * Text boxes, the header, the footer and the cover are stored as a small document
 * model - paragraphs of runs - rather than as HTML:
 *
 *   paragraph  {t: p|h1|h2|h3|li|img, a: left|center|right|justify,
 *               l: ul|ol (lists), lv: 0-4 (indent), r: [runs], w: mm (img)}
 *   run        {x: text, b, i, u, s: bool, c: '#rrggbb', hl: '#rrggbb',
 *               sz: points, fld: page|pages|date_from|date_to|today|title|company}
 *
 * Two reasons. The layout engine needs exactly this to break lines identically on
 * screen and in the PDF, so HTML would only be parsed into it anyway. And there is
 * then no markup to sanitise: every field below is checked for type and range, the
 * renderer writes text with textContent, and a run cannot carry a script or a
 * remote image because there is nowhere to put one.
 */

require_once __DIR__ . '/blocks.php';

const RP_DESIGN_VERSION = 1;
const RP_MAX_DESIGN_BYTES = 2 * 1024 * 1024;
const RP_MAX_BLOCKS = 400;
const RP_MAX_PARAGRAPHS = 800;
const RP_PAGE_SIZES = ['A4', 'Letter', 'Legal', 'A3'];
const RP_FONTS = ['helvetica', 'times', 'courier'];
const RP_PALETTES = ['default', 'ocean', 'sunset', 'forest', 'mono', 'vivid'];
const RP_FIELDS = ['page', 'pages', 'date_from', 'date_to', 'today', 'title', 'company'];

/** A paragraph of plain text, for the starter design. */
function rpPara(string $text, array $extra = []): array
{
    return $extra + ['t' => 'p', 'a' => 'left', 'r' => [['x' => $text]]];
}

/** The design a new pack starts from: a header like Enrique's, a footer, one heading. */
function rpDefaultDesign(string $name): array
{
    return [
        'v' => RP_DESIGN_VERSION,
        'page' => ['size' => 'A4', 'orient' => 'portrait', 'margin' => ['t' => 18, 'r' => 16, 'b' => 18, 'l' => 16]],
        'theme' => [
            'font' => 'helvetica', 'size' => 10, 'heading' => '#1f3864', 'accent' => '#1f3864',
            'palette' => 'default', 'th_bg' => '#1f3864', 'th_fg' => '#ffffff', 'stripe' => true,
        ],
        'criteria' => ['range' => ['preset' => 'last_month'], 'tenant' => 'active'],
        'header' => ['on' => true, 'rule' => false, 'first' => true, 'doc' => [
            ['t' => 'img', 'src' => 'logo', 'w' => 22, 'a' => 'center'],
            rpPara('', ['a' => 'center', 'r' => [['x' => $name, 'b' => true, 'sz' => 13]]]),
            rpPara('', ['a' => 'center', 'r' => [
                ['x' => t('reporting.packs.starter.from') . ' ', 'b' => true, 'sz' => 9], ['fld' => 'date_from', 'sz' => 9],
                ['x' => '     ' . t('reporting.packs.starter.to') . ' ', 'b' => true, 'sz' => 9], ['fld' => 'date_to', 'sz' => 9],
            ]]),
        ]],
        'footer' => ['on' => true, 'rule' => true, 'doc' => [
            rpPara('', ['a' => 'right', 'r' => [
                ['x' => t('reporting.packs.starter.page') . ' ', 'sz' => 8, 'c' => '#666666'], ['fld' => 'page', 'sz' => 8, 'c' => '#666666'],
                ['x' => ' ' . t('reporting.packs.starter.of') . ' ', 'sz' => 8, 'c' => '#666666'], ['fld' => 'pages', 'sz' => 8, 'c' => '#666666'],
            ]]),
        ]],
        'cover' => ['on' => false, 'doc' => [
            ['t' => 'img', 'src' => 'logo', 'w' => 40, 'a' => 'center'],
            rpPara('', ['t' => 'h1', 'a' => 'center', 'r' => [['fld' => 'title']]]),
            rpPara('', ['a' => 'center', 'r' => [['fld' => 'date_from'], ['x' => ' - '], ['fld' => 'date_to']]]),
        ]],
        'toc' => ['on' => false, 'title' => t('reporting.packs.starter.contents')],
        'blocks' => [
            ['id' => 'b1', 'type' => 'heading', 'span' => 12, 'text' => t('reporting.packs.starter.summary'), 'level' => 1, 'newPage' => false],
            ['id' => 'b2', 'type' => 'text', 'span' => 12, 'doc' => [rpPara(t('reporting.packs.starter.summary_text'))]],
        ],
    ];
}

function rpColour($v, ?string $default): ?string
{
    return (is_string($v) && preg_match('/^#[0-9a-fA-F]{6}$/', $v)) ? strtolower($v) : $default;
}
function rpNum($v, float $min, float $max, float $default): float
{
    return is_numeric($v) ? max($min, min($max, (float)$v)) : $default;
}
function rpStr($v, int $max): string
{
    $s = is_string($v) ? $v : (is_numeric($v) ? (string)$v : '');
    // Control characters other than tab and newline have no business in a report.
    $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $s) ?? '';
    return mb_substr($s, 0, $max);
}
function rpPick($v, array $allowed, string $default): string
{
    return (is_string($v) && in_array($v, $allowed, true)) ? $v : $default;
}

/** Check a rich-text document. Anything unrecognised is dropped, not kept. */
function rpCleanDoc($doc, int &$budget): array
{
    $out = [];
    if (!is_array($doc)) return $out;
    foreach ($doc as $p) {
        if (!is_array($p) || $budget-- <= 0) continue;
        $t = rpPick($p['t'] ?? 'p', ['p', 'h1', 'h2', 'h3', 'li', 'img'], 'p');
        $a = rpPick($p['a'] ?? 'left', ['left', 'center', 'right', 'justify'], 'left');
        if ($t === 'img') {
            // The only picture source is the install's own logo. Anything else would
            // need storing and serving, and a URL would let a pack beacon its readers.
            $out[] = ['t' => 'img', 'src' => 'logo', 'w' => rpNum($p['w'] ?? 30, 5, 180, 30), 'a' => $a];
            continue;
        }
        $para = ['t' => $t, 'a' => $a, 'r' => []];
        if ($t === 'li') {
            $para['l']  = rpPick($p['l'] ?? 'ul', ['ul', 'ol'], 'ul');
        }
        $lv = (int)rpNum($p['lv'] ?? 0, 0, 4, 0);
        if ($lv) $para['lv'] = $lv;
        foreach ((is_array($p['r'] ?? null) ? $p['r'] : []) as $r) {
            if (!is_array($r)) continue;
            $run = [];
            if (isset($r['fld'])) {
                if (!in_array($r['fld'], RP_FIELDS, true)) continue;
                $run['fld'] = $r['fld'];
            } else {
                $run['x'] = rpStr($r['x'] ?? '', 20000);
            }
            foreach (['b', 'i', 'u', 's'] as $f) if (!empty($r[$f])) $run[$f] = true;
            if ($c = rpColour($r['c'] ?? null, null))   $run['c'] = $c;
            if ($h = rpColour($r['hl'] ?? null, null))  $run['hl'] = $h;
            if (isset($r['sz'])) $run['sz'] = rpNum($r['sz'], 6, 72, 10);
            $para['r'][] = $run;
        }
        $out[] = $para;
    }
    return $out;
}

/**
 * Check a whole design from the browser. Returns the cleaned design; throws
 * InvalidArgumentException when it is not a design at all or is too large.
 */
function rpCleanDesign($d, string $name): array
{
    if (!is_array($d)) throw new InvalidArgumentException('Not a report design.');
    $def = rpDefaultDesign($name);
    $budget = RP_MAX_PARAGRAPHS;

    $pg = is_array($d['page'] ?? null) ? $d['page'] : [];
    $m  = is_array($pg['margin'] ?? null) ? $pg['margin'] : [];
    $page = [
        'size'   => rpPick($pg['size'] ?? 'A4', RP_PAGE_SIZES, 'A4'),
        'orient' => rpPick($pg['orient'] ?? 'portrait', ['portrait', 'landscape'], 'portrait'),
        'margin' => [
            't' => rpNum($m['t'] ?? 18, 5, 50, 18), 'r' => rpNum($m['r'] ?? 16, 5, 50, 16),
            'b' => rpNum($m['b'] ?? 18, 5, 50, 18), 'l' => rpNum($m['l'] ?? 16, 5, 50, 16),
        ],
    ];

    $th = is_array($d['theme'] ?? null) ? $d['theme'] : [];
    $theme = [
        'font'    => rpPick($th['font'] ?? 'helvetica', RP_FONTS, 'helvetica'),
        'size'    => rpNum($th['size'] ?? 10, 7, 16, 10),
        'heading' => rpColour($th['heading'] ?? null, $def['theme']['heading']),
        'accent'  => rpColour($th['accent'] ?? null, $def['theme']['accent']),
        'palette' => rpPick($th['palette'] ?? 'default', RP_PALETTES, 'default'),
        'th_bg'   => rpColour($th['th_bg'] ?? null, $def['theme']['th_bg']),
        'th_fg'   => rpColour($th['th_fg'] ?? null, $def['theme']['th_fg']),
        'stripe'  => !empty($th['stripe']),
    ];

    $cr = is_array($d['criteria'] ?? null) ? $d['criteria'] : [];
    $rg = is_array($cr['range'] ?? null) ? $cr['range'] : [];
    $range = ['preset' => rpPick($rg['preset'] ?? 'last_month', RP_RANGE_PRESETS, 'last_month')];
    if ($range['preset'] === 'custom') {
        foreach (['from', 'to'] as $k) {
            if (is_string($rg[$k] ?? null) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $rg[$k])) $range[$k] = $rg[$k];
        }
    }
    $tenant = $cr['tenant'] ?? 'active';
    $tenant = ($tenant === 'all' || $tenant === 'active') ? $tenant : (is_numeric($tenant) && (int)$tenant > 0 ? (int)$tenant : 'active');

    $part = function ($src, array $base) use (&$budget) {
        $src = is_array($src) ? $src : [];
        $o = ['on' => !empty($src['on']), 'doc' => rpCleanDoc($src['doc'] ?? [], $budget)];
        if (array_key_exists('rule', $base))  $o['rule']  = !empty($src['rule']);
        if (array_key_exists('first', $base)) $o['first'] = !empty($src['first']);
        return $o;
    };

    $toc = is_array($d['toc'] ?? null) ? $d['toc'] : [];

    $blocks = [];
    $seen = [];
    $handlers = rpHandlers();
    foreach (array_slice(is_array($d['blocks'] ?? null) ? $d['blocks'] : [], 0, RP_MAX_BLOCKS) as $b) {
        if (!is_array($b)) continue;
        $id = (is_string($b['id'] ?? null) && preg_match('/^[A-Za-z0-9_-]{1,40}$/', $b['id'])) ? $b['id'] : 'b' . bin2hex(random_bytes(4));
        if (isset($seen[$id])) $id .= '_' . bin2hex(random_bytes(2));
        $seen[$id] = true;
        $type = rpPick($b['type'] ?? '', ['text', 'heading', 'pagebreak', 'spacer', 'divider', 'data'], '');
        if ($type === '') continue;
        $blk = ['id' => $id, 'type' => $type, 'span' => (int)rpNum($b['span'] ?? 12, 1, 12, 12)];
        switch ($type) {
            case 'text':
                $blk['doc'] = rpCleanDoc($b['doc'] ?? [], $budget);
                if (!empty($b['box'])) $blk['box'] = true;   // light border and fill
                break;
            case 'heading':
                $blk['span']    = 12;
                $blk['text']    = rpStr($b['text'] ?? '', 300);
                $blk['level']   = (int)rpNum($b['level'] ?? 1, 1, 3, 1);
                $blk['newPage'] = !empty($b['newPage']);
                $blk['toc']     = !array_key_exists('toc', $b) || !empty($b['toc']);
                break;
            case 'spacer':
                $blk['height'] = rpNum($b['height'] ?? 8, 2, 120, 8);
                break;
            case 'pagebreak':
            case 'divider':
                $blk['span'] = 12;
                break;
            case 'data':
                $h = is_string($b['handler'] ?? null) ? $b['handler'] : '';
                if (!isset($handlers[$h])) continue 2;
                $blk['handler'] = $h;
                $blk['opts']    = rpCleanOpts($h, $b['opts'] ?? []);
                $blk['title']   = rpStr($b['title'] ?? '', 200);
                $blk['showTitle'] = !array_key_exists('showTitle', $b) || !empty($b['showTitle']);
                if (isset($b['height'])) $blk['height'] = rpNum($b['height'], 30, 250, 80);
                if (!empty($b['legend'])) $blk['legend'] = rpPick($b['legend'], ['auto', 'right', 'bottom', 'none'], 'auto');
                break;
        }
        $blocks[] = $blk;
    }

    $clean = [
        'v'        => RP_DESIGN_VERSION,
        'page'     => $page,
        'theme'    => $theme,
        'criteria' => ['range' => $range, 'tenant' => $tenant],
        'header'   => $part($d['header'] ?? [], $def['header']),
        'footer'   => $part($d['footer'] ?? [], $def['footer']),
        'cover'    => $part($d['cover'] ?? [], $def['cover']),
        'toc'      => ['on' => !empty($toc['on']), 'title' => rpStr($toc['title'] ?? '', 120)],
        'blocks'   => $blocks,
    ];
    if (strlen(json_encode($clean)) > RP_MAX_DESIGN_BYTES) {
        throw new InvalidArgumentException(t('reporting.packs.err.too_large'));
    }
    return $clean;
}
