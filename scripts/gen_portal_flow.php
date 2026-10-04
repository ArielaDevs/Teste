<?php
/* 🔴 COMMAND LINE ONLY - see scripts/.htaccess. Over HTTP this would run for anyone, unauthenticated. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * Generate the portal's "Flow" background: three rotated ribbons of fine lines,
 * written to SVG so the page pays nothing to draw them.
 *
 * Run after changing any number in here:
 *   php scripts/gen_portal_flow.php
 *
 * ──────────────────────────────────────────────────────────────────────────
 * THE MATHS, AND WHERE IT CAME FROM
 *
 * Ed wrote an animated canvas version and asked for the same look without the
 * animation. Three things in his version do the heavy lifting, and all three
 * are ported here - the first was missing from the first attempt, which is why
 * that one read as a single flat sweep rather than folded fabric:
 *
 *  1. ROTATION. Each ribbon is rotated about its own centre, so the three cross
 *     at angles and read as in front of and behind one another. This is the one
 *     that turns a wave into a drawing.
 *
 *  2. TWO SUPERPOSED WAVES at different frequencies, travelling in opposite
 *     directions (2π forward, 4π back). Their periods do not divide evenly, so
 *     the combined shape never visibly repeats along the ribbon - which is what
 *     makes it look like cloth instead of a waveform.
 *
 *  3. ALPHA BY DISTANCE FROM THE RIBBON'S SPINE. Each ribbon is brightest down
 *     its centre and fades to its edges, so where two overlap the eye reads
 *     depth. Varying stroke WIDTH alone (the first attempt) does not do this.
 *
 * A fourth term, the envelope sin(πp), pinches each ribbon at its ends so the
 * lines converge rather than running off the edge squared.
 *
 * ──────────────────────────────────────────────────────────────────────────
 * WHY TWO FILES, AND WHY THE STRENGTH IS BAKED IN
 *
 * An SVG referenced from `background-image` is a separate document: it cannot
 * inherit the page's `currentColor`, and CSS gives no opacity control over a
 * background image. So light and dark get their own file, and each carries its
 * own group opacity. The alternative - a masked pseudo-element - is more
 * machinery than two small files deserve.
 *
 * SIZE IS A REAL CONSTRAINT. Ed's canvas draws 390 lines at 180 steps each,
 * which is free per frame but would be ~630KB as SVG. The counts below are
 * tuned to keep both files well under 100KB while holding the density that
 * makes the mesh read; coordinates are rounded to integers because at 1600px
 * wide a sub-pixel is invisible and the decimals were most of the file.
 */

$W = 1600;
$H = 900;

/* Ribbon geometry, in fractions of the canvas - ported from Ed's version, with
   the line counts reduced for file size. `rotation` is radians. */
$RIBBONS = [
    ['x' => 0.40, 'y' => -0.05, 'w' => 0.40, 'h' => 1.25, 'rot' => -0.22, 'lines' => 58, 'phase' => 0.0],
    ['x' => 0.62, 'y' =>  0.03, 'w' => 0.34, 'h' => 1.25, 'rot' =>  0.16, 'lines' => 50, 'phase' => 2.4],
    ['x' => 0.30, 'y' =>  0.35, 'w' => 0.60, 'h' => 0.65, 'rot' => -0.05, 'lines' => 42, 'phase' => 4.0],
];

$STEPS = 54;   // points per line; round joins hide the faceting at this scale

/** Rotate (x,y) about (cx,cy). The term that makes the ribbons cross. */
function rotatePoint(float $x, float $y, float $cx, float $cy, float $angle): array
{
    $dx = $x - $cx;
    $dy = $y - $cy;
    $cos = cos($angle);
    $sin = sin($angle);
    return [$cx + $dx * $cos - $dy * $sin, $cy + $dx * $sin + $dy * $cos];
}

/**
 * The SINGLE SWEEP - one family of curves crossing the page, no rotation.
 *
 * Kept alongside the three-ribbon mesh because they are different things, not
 * an earlier and a later draft: this one is quiet enough to sit behind a page
 * of text all day, and the mesh is a showpiece. Same equation, one ribbon,
 * no rotation:
 *
 *   baseline(t)   walks down the canvas so the family sweeps
 *   amplitude(t)  peaks mid-family (sin πt), so the band bulges
 *   envelope(x)   pinches both ends so the lines converge at the edges
 *   phase(t)      shifts each curve, which is what makes them cross
 */
function sweepLines(int $W, int $H, int $curves, int $steps, float $freq): array
{
    $out = [];
    for ($i = 0; $i < $curves; $i++) {
        $t = $curves > 1 ? $i / ($curves - 1) : 0.0;

        $baseline  = $H * 0.30 + $t * ($H * 0.52);
        $amplitude = $H * 0.10 + sin($t * M_PI) * ($H * 0.13);
        $phase     = $t * 2.35;

        $pts = [];
        for ($s = 0; $s <= $steps; $s++) {
            $x = $W * $s / $steps;
            $u = $x / $W;
            $envelope = 0.25 + 0.75 * sin(M_PI * $u);
            $y = $baseline + $amplitude * $envelope * sin(2 * M_PI * $freq * $u + $phase);
            $pts[] = ((int) round($x)) . ',' . ((int) round($y));
        }
        // Fainter towards the back of the family, for depth.
        $out[] = ['pts' => implode(' ', $pts), 'alpha' => round(0.35 + 0.65 * sin($t * M_PI), 3)];
    }
    return $out;
}

/**
 * One ribbon: a family of lines spread across its width, each a sum of two
 * sine waves, the whole thing rotated about its centre.
 *
 * Returns [['pts' => '...', 'alpha' => float], ...].
 */
function ribbonLines(array $r, int $W, int $H, int $STEPS): array
{
    $baseX = $r['x'] * $W;
    $baseY = $r['y'] * $H;
    $rw    = $r['w'] * $W;
    $rh    = $r['h'] * $H;
    $cx    = $baseX + $rw / 2;
    $cy    = $baseY + $rh / 2;

    $out = [];
    for ($i = 0; $i < $r['lines']; $i++) {
        $t = $r['lines'] > 1 ? $i / ($r['lines'] - 1) : 0.0;

        // -1 at one edge of the ribbon, +1 at the other, 0 down its spine.
        $offset = ($t - 0.5) * 2;

        $pts = [];
        for ($j = 0; $j <= $STEPS; $j++) {
            $p = $j / $STEPS;
            $x = $baseX + $p * $rw;

            // The two waves. Different frequencies AND opposite signs, so the
            // combination does not repeat along the ribbon.
            $wave  = sin($p * M_PI * 2.0 + $r['phase']);
            $wave2 = sin($p * M_PI * 4.0);

            // Pinch the ends so the family converges instead of running off square.
            $envelope = sin($p * M_PI);

            $y = $baseY
               + $rh * 0.5
               + $offset * $rh * 0.33 * $envelope
               + $wave  * $rh * 0.11
               + $wave2 * $rh * 0.035;

            // A third, much smaller ripple, varied per line - it stops the
            // lines sitting in perfect parallel, which is what reads as fabric.
            $y += sin($p * M_PI * 7 + $t * 5) * 4;

            [$rx, $ry] = rotatePoint($x, $y, $cx, $cy, $r['rot']);
            $pts[] = ((int) round($rx)) . ',' . ((int) round($ry));
        }

        // Brightest down the spine, fading to the edges: this is what makes two
        // overlapping ribbons read as one in front of the other.
        $alpha = round(0.30 + (1 - abs($offset)) * 0.70, 3);
        $out[] = ['pts' => implode(' ', $pts), 'alpha' => $alpha];
    }
    return $out;
}

/**
 * @param array  $glows  [[x, y, radius], ...] in canvas units, or [] for none
 */
function svg(array $ribbons, int $W, int $H, string $stroke, float $groupOpacity, array $glows, string $glowColour): string
{
    $out = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $W . ' ' . $H . '"'
         . ' width="' . $W . '" height="' . $H . '" fill="none">';

    if ($glows) {
        $out .= '<defs><radialGradient id="g"><stop offset="0" stop-color="' . $glowColour . '" stop-opacity="0.55"/>'
              . '<stop offset="0.35" stop-color="' . $glowColour . '" stop-opacity="0.16"/>'
              . '<stop offset="1" stop-color="' . $glowColour . '" stop-opacity="0"/></radialGradient></defs>';
        foreach ($glows as [$gx, $gy, $gr]) {
            $out .= '<circle cx="' . $gx . '" cy="' . $gy . '" r="' . $gr . '" fill="url(#g)"/>';
        }
    }

    // Presence lives here rather than in CSS: background-image has no opacity.
    $out .= '<g stroke="' . $stroke . '" fill="none" opacity="' . $groupOpacity . '"'
          . ' stroke-linecap="round" stroke-linejoin="round" stroke-width="0.65">';
    foreach ($ribbons as $lines) {
        foreach ($lines as $l) {
            $out .= '<polyline points="' . $l['pts'] . '" opacity="' . $l['alpha'] . '"/>';
        }
    }
    $out .= '</g></svg>';
    return $out;
}

$ribbons = [];
$total = 0;
foreach ($RIBBONS as $r) {
    $lines = ribbonLines($r, $W, $H, $STEPS);
    $total += count($lines);
    $ribbons[] = $lines;
}

$dir = dirname(__DIR__) . '/assets/img';
if (!is_dir($dir)) { mkdir($dir, 0775, true); }

/* Glow points only on the dark file. On a light page they read as smudges
   rather than light, because there is nothing for them to be brighter than. */
$glows = [[1090, 330, 150], [1260, 540, 190], [960, 660, 130]];

/* TWO PATTERNS, not one superseding the other.
   "Flow" is the quiet single sweep, meant to sit behind a page of text all day.
   "Mesh" is the three-ribbon showpiece. Ed asked to keep both. */
$sweep = [sweepLines($W, $H, 34, 72, 1.35)];

$files = [
    $dir . '/portal-flow-light.svg' => [$sweep,   '#0b1220', 0.30, [],     ''],
    $dir . '/portal-flow-dark.svg'  => [$sweep,   '#cfe3ff', 0.38, [],     ''],
    $dir . '/portal-mesh-light.svg' => [$ribbons, '#0b1220', 0.26, [],     ''],
    $dir . '/portal-mesh-dark.svg'  => [$ribbons, '#7fc4f5', 0.42, $glows, '#3fb2f5'],
];

foreach ($files as $path => $spec) {
    file_put_contents($path, svg($spec[0], $W, $H, $spec[1], $spec[2], $spec[3], $spec[4]));
    printf("  %-24s %6.1f KB  stroke %s at %.2f%s\n",
        basename($path), filesize($path) / 1024, $spec[1], $spec[2], $spec[3] ? '  + glow' : '');
}
printf("  mesh: %d ribbons, %d lines, %d points each\n", count($RIBBONS), $total, $STEPS + 1);
printf("  flow: 1 sweep, 34 lines, 73 points each\n");
