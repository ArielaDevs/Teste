<?php
/**
 * System Help — Portal profile (#133).
 * What people may change about themselves in the self-service portal, and the
 * one setting that lets a customer write into your address book.
 */
require __DIR__ . '/_init.php';

$helpSlug = 'portal-profile';
require __DIR__ . '/_top.php';
?>

<!-- 1. Overview -->
<div class="help-section" id="overview">
    <div class="help-section-header"><?php echo helpSectionNum('overview'); ?>
        <div>
            <h3>What this decides</h3>
            <p>Under <strong>My Account</strong> in the self-service portal, people can keep some of their own details up to date. This page decides which.</p>
        </div>
    </div>
    <p>The case for it is simple: a person knows their own phone number better than the service desk does, and a record they can correct themselves is a record that stays right. Under GDPR, people also have a right to have inaccurate details about them corrected (Article 16), and this is the easiest place to let them do it.</p>
    <div class="help-note"><strong>On a new or upgraded system, all four are ticked</strong>, which is what the portal has always offered. Nothing changes until you change it here.</div>
</div>

<!-- 2. The fields -->
<div class="help-section" id="fields">
    <div class="help-section-header"><?php echo helpSectionNum('fields'); ?>
        <div>
            <h3>What people may change</h3>
            <p>Four you choose, one that is always theirs, and four that are never offered.</p>
        </div>
    </div>
    <div class="help-table"><table>
        <thead><tr><th>Detail</th><th>Portal</th><th>Why</th></tr></thead>
        <tbody>
            <tr><td><strong>Preferred name</strong></td><td><span class="help-pill ok">Always</span></td><td>How the portal and your emails address them. No directory holds it.</td></tr>
            <tr><td><strong>Job title</strong></td><td><span class="help-pill info">Your choice</span></td><td>Changes after a promotion, usually without anybody telling the service desk.</td></tr>
            <tr><td><strong>Office or location</strong></td><td><span class="help-pill info">Your choice</span></td><td>The asset picker on a ticket searches on it.</td></tr>
            <tr><td><strong>Phone</strong> and <strong>Mobile</strong></td><td><span class="help-pill info">Your choice</span></td><td>The numbers your team rings back on.</td></tr>
            <tr><td><strong>Department</strong></td><td><span class="help-pill bad">Never</span></td><td>An organisational fact your team maintains; reports and the asset search group by it.</td></tr>
            <tr><td><strong>Employee ID</strong></td><td><span class="help-pill bad">Never</span></td><td>The key used to match people against HR, so typing one is a claim about who they are.</td></tr>
            <tr><td><strong>Manager</strong></td><td><span class="help-pill bad">Never</span></td><td>Nobody should be able to choose their own approver.</td></tr>
            <tr><td><strong>Email address</strong></td><td><span class="help-pill bad">Never</span></td><td>It is how they sign in.</td></tr>
        </tbody>
    </table></div>
    <p>A detail you untick disappears from the portal form. Nothing already stored is removed, and your team can still change it on the person's record.</p>
</div>

<!-- 3. People from a directory -->
<div class="help-section" id="directory">
    <div class="help-section-header"><?php echo helpSectionNum('directory'); ?>
        <div>
            <h3>People from a directory or address book</h3>
            <p>Where a person's details are kept up to date from elsewhere, that source wins.</p>
        </div>
    </div>
    <ul>
        <li><strong>People imported from LDAP or Active Directory:</strong> those details are shown read-only in the portal, with a note to ask IT. The next directory run would put back anything typed here, so the portal does not pretend otherwise.</li>
        <li><strong>People whose account uses SSO with Profile Sync:</strong> when a sign-in provider is set to <em>Always sync</em>, profile contact details are authoritative in the Identity Provider and locked in the portal.</li>
        <li><strong>A CardDAV address book:</strong> the same, <em>unless</em> you tick <strong>Let them change these too, and send their change to the address book</strong>.</li>
    </ul>
    <div class="help-note warn"><strong>That setting lets your customers write into your address book.</strong> It is off by default for that reason. It only affects address books that already have <strong>Write changes back</strong> switched on under <a href="sso.php">Authentication</a>; the page lists which ones.</div>
    <p>With it on:</p>
    <ul>
        <li>The portal tells the person that their details also go to your address book.</li>
        <li><strong>Their change is sent first, and saved in FreeITSM only if the address book accepts it.</strong> If it does not - the server is unreachable, or somebody changed the same detail on the card since the last import - nothing is changed anywhere and the person is told. (An analyst's edit works the other way round, because an analyst can act on the warning and a customer cannot.)</li>
        <li>Only the details they actually changed are sent, and only those; photos, notes and everything else on the card are left alone.</li>
        <li>Every write appears in that address book's <strong>write log</strong>, marked as changed by the person themselves in the self-service portal.</li>
    </ul>
</div>

<?php require __DIR__ . '/_bottom.php'; ?>
