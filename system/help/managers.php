<?php
/**
 * System Help — Managers (discussion #62).
 * Who in the self-service portal may see whose tickets, where that comes from,
 * and what to check before switching it on.
 */
require __DIR__ . '/_init.php';

$helpSlug = 'managers';
require __DIR__ . '/_top.php';
?>

<!-- 1. Overview -->
<div class="help-section" id="overview">
    <div class="help-section-header"><?php echo helpSectionNum('overview'); ?>
        <div>
            <h3>What a manager is</h3>
            <p>Someone in the self-service portal who can see the tickets raised by the people they manage.</p>
        </div>
    </div>
    <p>The head of a department often needs to know what their people have asked IT for: a new starter still waiting on a laptop, a problem that has stopped a whole team, a ticket still open when somebody leaves. With managers switched on, such a person gets a <strong>Team tickets</strong> tab in the portal that lists their team's tickets, next to <strong>My Tickets</strong>.</p>
    <p><strong>Only the portal is affected.</strong> Who on the service desk sees what is still decided by teams, departments and companies, exactly as before.</p>
    <div class="help-note"><strong>Off until you switch it on.</strong> Nothing on this page has any effect while <strong>Let managers see their team's tickets</strong> is unticked, and managers stay off, whatever is set, until System &rarr; Database Verification has run.</div>
</div>

<!-- 2. Where managers come from -->
<div class="help-section" id="sources">
    <div class="help-section-header"><?php echo helpSectionNum('sources'); ?>
        <div>
            <h3>Where managers come from</h3>
            <p>Two sources, used together.</p>
        </div>
    </div>
    <div class="help-table"><table>
        <thead><tr><th>Source</th><th>How it is set</th><th>Good for</th></tr></thead>
        <tbody>
            <tr><td><strong>The Manager field</strong></td><td>On each person's record, by hand or from your directory (Active Directory's <em>manager</em>). Switch it on or off with <strong>Use each person's Manager field</strong>, and choose direct reports only or everyone below them.</td><td>Line managers, with no extra work if your directory already knows the reporting line.</td></tr>
            <tr><td><strong>Management lines</strong></td><td>On the <strong>Manager access</strong> page for that person: the button beside Edit on <strong>Tickets &rarr; Users</strong> or <strong>Assets &rarr; Users</strong>, or click their name in the list below the settings.</td><td>A head of department, a site lead, a deputy who covers someone's team - anyone the reporting line does not capture.</td></tr>
        </tbody>
    </table></div>
    <p>A management line gives a manager one of:</p>
    <ul>
        <li><strong>Everyone</strong> in their company;</li>
        <li><strong>a person</strong>;</li>
        <li><strong>a people group</strong> - the same groups Knowledge and Training use, with the same membership rules, so an expired membership no longer counts;</li>
        <li><strong>a department</strong>, by name;</li>
        <li><strong>their reporting line</strong> - direct reports or everyone below them - for this one manager, even when the Manager field is not used for everybody.</li>
    </ul>
    <p>And an <strong>exclusion</strong> leaves out a person, a group or a department. <strong>An exclusion always wins</strong>, over every line and over the Manager field: "everyone in Sales except the person who has raised a grievance about me" is what it is for.</p>
    <p>Somebody can have two managers, and one manager can have any number of lines. The Manager access page shows, for that person, what each line gives them, who added it and when, and the full list of people they can see.</p>
    <div class="help-note warn"><strong>Departments are matched on the name typed on each person</strong>, ignoring capitals and spaces at either end. If a department is renamed, a line for the old name stops matching anybody. The list of managers below warns about exactly that.</div>
</div>

<!-- 3. The rules that always apply -->
<div class="help-section" id="rules">
    <div class="help-section-header"><?php echo helpSectionNum('rules'); ?>
        <div>
            <h3>The rules that always apply</h3>
            <p>Whatever the lines say.</p>
        </div>
    </div>
    <ul>
        <li><strong>Same company only.</strong> A manager only ever sees people - and tickets - in their own company. If you have never set up companies, everyone is in the Default company and there is nothing to do.</li>
        <li><strong>A manager who has left sees nothing.</strong> Their lines are kept, in case they come back.</li>
        <li><strong>Never their own tickets through the team.</strong> Those stay under My Tickets.</li>
        <li><strong>Seeing is the floor.</strong> A manager can always read a team ticket; whether they may reply to it or close it is set here, and each team ticket tells the manager what they may do.</li>
    </ul>
</div>

<!-- 4. Confidential tickets -->
<div class="help-section" id="confidential">
    <div class="help-section-header"><?php echo helpSectionNum('confidential'); ?>
        <div>
            <h3>Confidential tickets</h3>
            <p>A diagnosis sent to HR, or a complaint about the manager themselves.</p>
        </div>
    </div>
    <p>Any ticket can be marked <strong>Confidential</strong> - by the person raising it, by the service desk, or automatically by a mailbox or department (see the Tickets help). This setting decides what a manager sees of one:</p>
    <div class="help-table"><table>
        <thead><tr><th>Choice</th><th>What the manager sees</th></tr></thead>
        <tbody>
            <tr><td><strong>Nothing at all</strong> <span class="help-pill ok">Default</span></td><td>The ticket is left out entirely. They cannot tell it exists.</td></tr>
            <tr><td><strong>That it exists</strong></td><td>"Confidential ticket", with its number and status and nothing it says. Be aware that even this can tell a manager someone raised something private.</td></tr>
            <tr><td><strong>Everything</strong></td><td>Shown like any other ticket. Only for a portal where nothing confidential is ever raised.</td></tr>
        </tbody>
    </table></div>
</div>

<!-- 5. Who has looked -->
<div class="help-section" id="views">
    <div class="help-section-header"><?php echo helpSectionNum('views'); ?>
        <div>
            <h3>Who has looked</h3>
            <p>Being seen is part of the deal.</p>
        </div>
    </div>
    <p>When a manager opens a team ticket it is recorded. The person who raised it sees <strong>Who has seen this ticket</strong> on the ticket, with the manager's name and when they last looked, and the ticket's Audit window lists it for the service desk. Service-desk views are never shown to the requester - only views from the portal - so nobody is asked why an analyst looked and did nothing.</p>
</div>

<!-- 6. The list of managers -->
<div class="help-section" id="list">
    <div class="help-section-header"><?php echo helpSectionNum('list'); ?>
        <div>
            <h3>The list of managers</h3>
            <p>Check before you switch on.</p>
        </div>
    </div>
    <p>Below the settings, every manager is listed - anybody with a management line, plus, while the Manager field is used, anybody who is someone's manager. Search it by name or email; it is shown a page at a time, because on a large organisation it can run to thousands. For each one:</p>
    <ul>
        <li><strong>From</strong> - their direct reports, lines and exclusions.</li>
        <li><strong>Can see</strong> - how many people's tickets they can see. This is worked out <strong>as if managers were switched on</strong>, so you can check the numbers first.</li>
        <li><span class="help-pill warn">Has left - sees nothing</span> for a manager who has left.</li>
        <li><span class="help-pill warn">Nobody is in "&hellip;" - renamed?</span> for a department line that matches nobody in their company.</li>
    </ul>
    <p>Click a name to open their Manager access page.</p>
</div>

<!-- 7. Settings -->
<div class="help-section" id="settings">
    <div class="help-section-header"><?php echo helpSectionNum('settings'); ?>
        <div>
            <h3>The settings</h3>
            <p>Each one, and its default.</p>
        </div>
    </div>
    <div class="help-table"><table>
        <thead><tr><th>Setting</th><th>Default</th><th>What it does</th></tr></thead>
        <tbody>
            <tr><td><strong>Let managers see their team's tickets</strong></td><td>Off</td><td>The master switch.</td></tr>
            <tr><td><strong>Use each person's Manager field</strong></td><td>On</td><td>Whoever is set as someone's Manager can see their tickets. <strong>How far down</strong>: direct reports, or everyone below them.</td></tr>
            <tr><td><strong>Reply to / Close their team's tickets</strong></td><td>Off</td><td>What a manager may do beyond reading. A manager's reply and a close are recorded as theirs.</td></tr>
            <tr><td><strong>Confidential tickets</strong></td><td>Nothing at all</td><td>See above.</td></tr>
            <tr><td><strong>Keep showing the tickets of people who have left</strong></td><td>On</td><td>An open ticket still needs oversight after its requester goes.</td></tr>
            <tr><td><strong>Who may set up management lines</strong></td><td>Administrators only</td><td>Or anyone who can edit people on Tickets &rarr; Users or Assets &rarr; Users. Everyone else sees the Manager access page read-only. A line lets one person read another's tickets, so keep it to a few.</td></tr>
        </tbody>
    </table></div>
</div>

<!-- 8. Before you switch on -->
<div class="help-section" id="before">
    <div class="help-section-header"><?php echo helpSectionNum('before'); ?>
        <div>
            <h3>Before you switch on</h3>
            <p>Five minutes that save an awkward conversation.</p>
        </div>
    </div>
    <div class="help-steps">
        <div class="help-step"><div class="help-step-num">1</div><div><strong>Run Database Verification</strong> if this page says to.</div></div>
        <div class="help-step"><div class="help-step-num">2</div><div><strong>Set up confidentiality first.</strong> Mark an HR mailbox or department confidential under Tickets &rarr; Settings, so those tickets are protected from the moment managers can look.</div></div>
        <div class="help-step"><div class="help-step-num">3</div><div><strong>Read the list of managers.</strong> Anyone whose count looks too high, or any department warning, is worth opening before the switch rather than after.</div></div>
        <div class="help-step"><div class="help-step-num">4</div><div><strong>Tell your people.</strong> The portal tells requesters that confidential tickets are kept from managers, and shows who has seen a ticket - but it is kinder to hear it from you first.</div></div>
        <div class="help-step"><div class="help-step-num">5</div><div><strong>Switch on</strong>, and look at the portal as a manager: the Team tickets tab appears once they have somebody to see.</div></div>
    </div>
</div>

<?php require __DIR__ . '/_bottom.php'; ?>
