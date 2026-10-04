<?php
/* 🔴 NEVER OVER THE WEB. See tests/README.md. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * Confidential tickets: the email and calendar exits (discussion #62).
 *
 * A workflow's Send email reaches a confidential ticket's requester and the
 * service desk only; a synced calendar event and the .ics feed carry the
 * ticket number, never the subject or the requester.
 *
 * Read-only against the database: the helper checks run inside a transaction
 * that is always rolled back, and the workflow check uses a ticket that is
 * already confidential, on the path that returns BEFORE any mailbox is touched.
 * Nothing is sent and nothing is written.
 *
 * Run: php tests/confidential-exits.php
 */

$root = dirname(__DIR__);
require_once "$root/config.php";
require_once "$root/includes/functions.php";
require_once "$root/includes/services/tickets.php";   // engine, push, sensitivity

$pass = 0; $fail = 0;
function ok(string $label, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; printf("  PASS %-64s %s\n", $label, $detail); }
    else       { $fail++; printf("  FAIL %-64s %s\n", $label, $detail); }
}

$conn = connectToDatabase();
if (!ticketSensitivityReady($conn)) {
    echo "Run Database Verification first - there is no sensitivity column.\n";
    exit(1);
}

echo "\n1. Who a workflow may email about a confidential ticket\n";
$t = $conn->query(
    "SELECT t.id, LOWER(u.email) AS email FROM tickets t JOIN users u ON u.id = t.user_id
      WHERE t.sensitivity = 'normal' AND u.email <> '' AND t.deleted_datetime IS NULL LIMIT 1"
)->fetch(PDO::FETCH_ASSOC);
$analyst = strtolower((string)$conn->query("SELECT email FROM analysts WHERE is_active = 1 AND email <> '' LIMIT 1")->fetchColumn());
$outsider = 'not-a-freeitsm-person-' . bin2hex(random_bytes(4)) . '@example.invalid';

if (!$t || $analyst === '') {
    ok('test data: a normal ticket with a requester email, and an analyst', false);
} else {
    $tid = (int)$t['id'];
    $conn->beginTransaction();
    try {
        ok('normal ticket: an outside address is allowed', ticketEmailRecipientsAllowed($conn, $tid, $outsider));
        $conn->prepare("UPDATE tickets SET sensitivity = 'confidential' WHERE id = ?")->execute([$tid]);
        ok('confidential: the requester is allowed', ticketEmailRecipientsAllowed($conn, $tid, $t['email']));
        ok('confidential: the requester in capitals is allowed', ticketEmailRecipientsAllowed($conn, $tid, strtoupper($t['email'])));
        ok('confidential: an active analyst is allowed', ticketEmailRecipientsAllowed($conn, $tid, $analyst));
        ok('confidential: requester + analyst together are allowed', ticketEmailRecipientsAllowed($conn, $tid, $t['email'] . '; ' . $analyst));
        ok('confidential: an outside address is refused', !ticketEmailRecipientsAllowed($conn, $tid, $outsider));
        ok('confidential: requester + outsider together are refused', !ticketEmailRecipientsAllowed($conn, $tid, $t['email'] . ',' . $outsider));
        ok('confidential: no address at all is refused', !ticketEmailRecipientsAllowed($conn, $tid, ' , '));

        // Raising by a rule re-syncs the calendar. On an unscheduled ticket that
        // must be a quiet no-op, from a context that may not have loaded much.
        $conn->prepare("UPDATE tickets SET sensitivity = 'normal' WHERE id = ?")->execute([$tid]);
        $threw = '';
        try { $raised = ticketSensitivityRaise($conn, $tid, 'test'); } catch (Throwable $e) { $threw = $e->getMessage(); $raised = false; }
        ok('a rule raising the flag re-syncs the calendar without error', $raised && $threw === '', $threw);
    } finally {
        $conn->rollBack();
    }
    $after = $conn->prepare("SELECT sensitivity FROM tickets WHERE id = ?");
    $after->execute([$tid]);
    ok('rolled back: the ticket is normal again', $after->fetchColumn() === 'normal');
}

echo "\n2. The workflow Send email action holds back, before any mailbox is used\n";
$conf = $conn->query(
    "SELECT t.id FROM tickets t JOIN users u ON u.id = t.user_id
      WHERE t.sensitivity = 'confidential' AND u.email <> '' AND t.deleted_datetime IS NULL LIMIT 1"
)->fetchColumn();
if (!$conf) {
    echo "  (no confidential ticket on this install - skipped)\n";
} else {
    $m = new ReflectionMethod('WorkflowEngine', 'action_send_email');
    $m->setAccessible(true);
    $res = $m->invoke(null, ['ticket_id' => (string)$conf, 'to' => $outsider, 'subject' => 'x', 'body' => 'y'], []);
    ok('to an outsider: skipped, and says why', !empty($res['skipped']) && !empty($res['confidential'])
       && $res['reason'] === TICKET_EMAIL_CONFIDENTIAL_SKIP);
    // The ticket arriving only in the payload (a "send from mailbox" email that
    // quotes {{ticket.subject}}) is held back too.
    $res2 = $m->invoke(null, ['mailbox_id' => '999999999', 'to' => $outsider, 'subject' => 'x', 'body' => 'y'],
                       ['ticket' => ['id' => (int)$conf]]);
    ok('ticket only in the payload: still skipped', !empty($res2['skipped']));
}

echo "\n3. The calendar event a sync writes\n";
$row = ['id' => 1, 'ticket_number' => 'T-TEST', 'subject' => 'Grievance about my manager',
        'requester_name' => 'Pat Example', 'status_name' => 'Open', 'priority_name' => 'High',
        'work_start_datetime' => '2026-10-05 09:00:00', 'work_end_datetime' => null, 'work_all_day' => 0];
$normal = calendarSyncEventFromTicket($row + ['sensitivity' => 'normal']);
$secret = calendarSyncEventFromTicket($row + ['sensitivity' => 'confidential']);
ok('positive control: a normal ticket carries its subject and requester',
   strpos($normal['subject'], 'Grievance') !== false && strpos($normal['body'], 'Pat Example') !== false);
ok('confidential: no subject', strpos($secret['subject'], 'Grievance') === false
   && strpos($secret['subject'], 'T-TEST') !== false && strpos($secret['subject'], TICKET_CONFIDENTIAL_SUBJECT) !== false,
   $secret['subject']);
ok('confidential: no requester, status still there', strpos($secret['body'], 'Pat Example') === false
   && strpos($secret['body'], 'Open') !== false);
ok('a row without the column reads as normal (before DB Verify)',
   strpos(calendarSyncEventFromTicket($row)['subject'], 'Grievance') !== false);

echo "\n4. The .ics feed\n";
$feed = (string)file_get_contents("$root/api/tickets/schedule_feed.php");
ok('the feed selects sensitivity safely and names confidential tickets',
   strpos($feed, 'ticketSensitivitySelectSql($conn)') !== false
   && preg_match("/=== 'confidential'\s*\?\s*TICKET_CONFIDENTIAL_SUBJECT/", $feed) === 1);
// The calendar code swallows its errors, so a broken SELECT would pass every
// check above in silence. Run the expression for real.
$sqlOk = '';
try { $conn->query("SELECT t.id, " . ticketSensitivitySelectSql($conn) . " AS sensitivity FROM tickets t LIMIT 1")->fetchAll(); }
catch (Throwable $e) { $sqlOk = $e->getMessage(); }
ok('the sensitivity column expression runs', $sqlOk === '', $sqlOk);

echo "\n" . str_repeat('-', 78) . "\n";
printf("  %d passed, %d failed\n\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
