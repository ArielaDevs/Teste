<?php
/**
 * System Help — Photo Album.
 * What it does, the camera and its https rule, the styles and screens, the
 * sliders, and what is (and is not) kept.
 */
require __DIR__ . '/_init.php';

$helpSlug = 'photo-album';
require __DIR__ . '/_top.php';
?>

<!-- 1. Overview -->
<div class="help-section" id="overview">
    <div class="help-section-header"><?php echo helpSectionNum('overview'); ?>
        <div>
            <h3>What it does</h3>
            <p>Just for fun: your webcam picture, drawn entirely in text characters.</p>
        </div>
    </div>
    <p>Open <strong>System &rarr; Photo Album</strong> and press <strong>Start</strong>. The art follows you live as you move. Press <strong>Capture</strong> when you like what you see, then <strong>Save</strong> it to your album, <strong>Copy</strong> it as text, or <strong>Download</strong> it as a text file or a PNG image.</p>
    <p>There is <strong>no AI</strong> involved. The picture is shrunk to a grid, the brightness of each cell picks a character, and the rounding left over is passed on to the neighbouring cells so shading stays smooth (that is <em>dithering</em>). It all happens in your browser.</p>
</div>

<!-- 2. Camera -->
<div class="help-section" id="camera">
    <div class="help-section-header"><?php echo helpSectionNum('camera'); ?>
        <div>
            <h3>Taking a picture</h3>
            <p>The camera needs a secure address.</p>
        </div>
    </div>
    <p>Browsers only switch a camera on for a page served over <strong>https</strong>, or from <code>localhost</code> on the same machine. If FreeITSM is opened over plain http the page says so, and <strong>Upload</strong> still works: pick any picture from your device and it is turned into art the same way.</p>
    <ul>
        <li>The first time, your browser asks whether FreeITSM may use the camera. If you said no, allow it again from the camera icon in the address bar.</li>
        <li>On a phone or tablet with two cameras, <strong>Flip</strong> switches between the front and back camera.</li>
        <li>The camera is switched off the moment you press <strong>Capture</strong>. <strong>Retake</strong> switches it back on.</li>
    </ul>
</div>

<!-- 3. Styles -->
<div class="help-section" id="styles">
    <div class="help-section-header"><?php echo helpSectionNum('styles'); ?>
        <div>
            <h3>Styles and screens</h3>
            <p>Three ways to draw, four screens to draw on.</p>
        </div>
    </div>
    <ul>
        <li><strong>Braille</strong> - each character is a little grid of 2 by 4 dots, so it holds eight times the detail of an ordinary character. The sharpest likeness.</li>
        <li><strong>Classic</strong> - seventy characters from a full stop to <code>@</code>, the way ASCII art has always been done.</li>
        <li><strong>Blocks</strong> - shade characters, from light to solid. Chunky and bold.</li>
    </ul>
    <p>The <strong>Screen</strong> sets the colours: <strong>Green screen</strong> and <strong>Amber</strong> for an old terminal, <strong>Paper</strong> for dark ink on white, and <strong>Colour</strong> to keep the real colours of the picture.</p>
    <div class="help-note"><strong>Copy and the text file carry only the characters.</strong> Colour, and the screen's own colours, are kept in the album and in the PNG download.</div>
</div>

<!-- 4. Adjust -->
<div class="help-section" id="adjust">
    <div class="help-section-header"><?php echo helpSectionNum('adjust'); ?>
        <div>
            <h3>Getting a good likeness</h3>
            <p>The sliders work live, and on a captured picture too.</p>
        </div>
    </div>
    <ul>
        <li><strong>Detail</strong> - how many characters wide. More is finer but smaller.</li>
        <li><strong>Brightness</strong> and <strong>Contrast</strong> - a face lit from one side usually wants a little more contrast.</li>
        <li><strong>Sharpen</strong> - picks out edges such as glasses, eyebrows and the line of the jaw.</li>
        <li><strong>Dithering</strong> - smooth shading by scattering dots. Turn it off for a cleaner, poster-like look.</li>
        <li><strong>Mirror</strong> - shows you the way a mirror would, which is what most people expect from a front camera.</li>
    </ul>
    <p>Your settings are remembered in this browser for next time.</p>
</div>

<!-- 5. Album -->
<div class="help-section" id="album">
    <div class="help-section-header"><?php echo helpSectionNum('album'); ?>
        <div>
            <h3>Your album and privacy</h3>
            <p>What is kept, and who can see it.</p>
        </div>
    </div>
    <ul>
        <li><strong>Your photo never leaves your browser.</strong> Only the finished art is sent to FreeITSM, and only when you press Save: the characters, their colours if you used the Colour screen, and a small image of the art for the album.</li>
        <li><strong>Your album is yours alone.</strong> Nobody else - administrators included - sees it on this page. Click a picture to open it, copy it, download it or delete it.</li>
        <li>If your analyst account is deleted, its album goes with it.</li>
    </ul>
</div>

<?php require __DIR__ . '/_bottom.php'; ?>
