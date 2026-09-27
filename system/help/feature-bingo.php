<?php
/**
 * System Help — Feature Bingo.
 * What the cards are, what lights a star, and "Not for us".
 */
require __DIR__ . '/_init.php';

$helpSlug = 'feature-bingo';
require __DIR__ . '/_top.php';
?>

<!-- 1. Overview -->
<div class="help-section" id="overview">
    <div class="help-section-header"><?php echo helpSectionNum('overview'); ?>
        <div>
            <h3>What it is</h3>
            <p>A map of everything FreeITSM can do, with a star for each part you have set up.</p>
        </div>
    </div>
    <p>Most organisations use a small part of any system they buy - not because the rest is not useful, but because nobody ever finds it. Feature Bingo is one card per feature, in every module: the basics such as <em>at least one analyst</em>, down to the smallest switch. Each card says what the feature is, why it is worth setting up, and takes you to the page where you do it.</p>
    <p>The <strong>star lights</strong> once FreeITSM can see it has been set up on this install. The number at the top is how many of the features that apply to you are lit.</p>
</div>

<!-- 2. The cards -->
<div class="help-section" id="cards">
    <div class="help-section-header"><?php echo helpSectionNum('cards'); ?>
        <div>
            <h3>Reading a card</h3>
            <p>Module, category, tier - and exactly what lights it.</p>
        </div>
    </div>
    <ul>
        <li><strong>Module</strong> - where the feature lives. Cards are grouped by module.</li>
        <li><strong>Category</strong> - what kind of thing it is, across modules: AI, Security &amp; access, Automation, Email &amp; notifications, Integrations and so on. Filter by it to see, say, every AI feature in the product.</li>
        <li><strong>Tier</strong>:
            <span class="help-pill bad">Essential</span> the install is not really working without it,
            <span class="help-pill info">Recommended</span> most organisations should do it, and
            <strong>Extra</strong> for the ones that suit some.</li>
    </ul>
    <p>Click a card for its explainer. <strong>The star lights when</strong> says in plain words exactly what FreeITSM checks - "at least one business calendar exists", "the setting has been saved" - so a star that will not light is never a mystery. <strong>Take me there</strong> opens the page where it is set up.</p>
    <div class="help-note">A star is worked out each time the page opens, from what is in the database. It is never ticked by hand, so it cannot say something is set up when it is not.</div>
</div>

<!-- 3. Not for us -->
<div class="help-section" id="not-for-us">
    <div class="help-section-header"><?php echo helpSectionNum('not-for-us'); ?>
        <div>
            <h3>Not for us</h3>
            <p>For the features you will never use.</p>
        </div>
    </div>
    <p>Some cards will never apply - WhatsApp, if you do not use it; Jira, if you have no Jira. Open the card and choose <strong>Not for us</strong>: it greys out and leaves the score, so the score measures what matters to you rather than nagging about what does not. <strong>Put it back</strong> undoes it.</p>
    <p>For a whole module you do not use, choose the module in the filter and press <strong>Not for us: all of</strong> it - one click instead of one per card.</p>
    <p>Show <em>Not for us</em> in the last filter to see what has been set aside. A card set aside that is in fact set up still shows its lit star - you use it after all.</p>
    <div class="help-note">Marking cards Not for us needs <strong>Database Verification</strong> to have run once after upgrading. Until then the stars work, and the button is hidden.</div>
</div>

<!-- 4. Adding a card -->
<div class="help-section" id="developers">
    <div class="help-section-header"><?php echo helpSectionNum('developers'); ?>
        <div>
            <h3>For developers: adding a card</h3>
            <p>Every new feature should arrive with its card.</p>
        </div>
    </div>
    <p>Cards are PHP data in <code>includes/feature_bingo/cards/</code>, one file per module or area. A card is an id, a module, a tier, a category, a title, <em>what</em>, <em>why</em>, <em>done</em> (the plain-words check), a link, and a <strong>check</strong> - a declarative, read-only test: rows in a table, a setting's value, or a single counting <code>SELECT</code>, combined with any/all. The format is documented at the top of <code>includes/feature_bingo.php</code>.</p>
    <p>Run <code>php scripts/feature_bingo_check.php</code> against a database: it runs every check and reports any that errors (a table or column that does not exist) and any link to a page that is not there - on the page those would quietly read as "not set up" forever.</p>
</div>

<?php require __DIR__ . '/_bottom.php'; ?>
