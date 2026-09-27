<?php
/**
 * Escalate a ticket to an external tracker — the MANUAL path.
 *
 * The second entry point into integrationsEscalate(), the first being the
 * workflow action. Deliberately not a second implementation: every guard,
 * including the company check, lives in the service so there is exactly one copy
 * to get right.
 *
 * Two modes:
 *   preview = 1  → build the description and hand it back WITHOUT touching Jira.
 *   otherwise    → create the issue and record the link.
 *
 * ⚠️ The preview matters. This is a one-way door into a system we do not
 * control: once the issue exists we cannot unsend it, and its contents are
 * visible to everyone with access to that tracker. The analyst sees the exact
 * text first.
 *
 * ⚠️ Internal notes never cross. This builds the body from the ticket's public
 * request text only — `ticket_notes.is_internal` exists for a reason, and "we
 * should just refund this one" landing on a shared dev board is the kind of bug
 * that ends a customer relationship.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/tenancy.php';
require_once '../../includes/integrations/integrations.php';

header('Content-Type: application/json');
requireModuleAccessJson('tickets');

$in = json_decode(file_get_contents('php://input'), true);
if (!is_array($in)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Bad request.']);
    exit;
}

$conn         = connectToDatabase();
$ticketId     = (int)($in['ticket_id'] ?? 0);
$connectionId = (int)($in['connection_id'] ?? 0);
$project      = trim((string)($in['project'] ?? ''));
$issueType    = trim((string)($in['issue_type'] ?? '')) ?: 'Bug';
$isPreview    = !empty($in['preview']);

if ($ticketId <= 0) {
    echo json_encode(['success' => false, 'error' => 'No ticket.']);
    exit;
}
if (!integrationsSchemaReady($conn)) {
    echo json_encode(['success' => false, 'error' => 'Integrations are not set up on this install yet.']);
    exit;
}

// ⚠️ THE COMPANY CHECK BELONGS HERE, NOT IN THE SERVICE.
//
// integrationsEscalate() checks the company — and this file's design note says every
// check lives in the service. That held for the write, and only for the write: the
// `preview` branch further down builds its answer and returns BEFORE the service is
// ever called, so the invariant quietly covered one of the two paths.
//
// POST {"ticket_id": <any id>, "preview": 1} therefore returned another company's
// subject line, requester name and email address, priority, type, the requester's
// original message body, and the filename of every attachment — to any analyst with
// the tickets module. No tracker connection was needed, because the preview never
// touches one. Reported by Erlend Volden; it is the read-side sibling of S1.
//
// Checking here covers preview and escalation with one line, and the service keeps
// its own check because it is reachable from elsewhere. Two checks on the write path
// is the correct amount when one of them is a boundary and the other is a service.
if (!analystCanAccessTicket($conn, (int)($_SESSION['analyst_id'] ?? 0), $ticketId)) {
    // Same words as a deleted ticket, deliberately: "no longer exists" and "not
    // yours" must be indistinguishable or the refusal itself confirms the ticket.
    echo json_encode(['success' => false, 'error' => 'That ticket no longer exists.']);
    exit;
}

// Read the ticket for the summary and the description. Only public fields — the
// notes table is not touched at all, which is the strongest form of "internal
// notes never cross".
$tq = $conn->prepare(
    "SELECT t.id, t.ticket_number, t.subject, t.created_datetime,
            u.email AS requester_email, u.display_name AS requester_name,
            p.name AS priority_name, ty.name AS type_name
     FROM tickets t
     LEFT JOIN users u            ON u.id  = t.user_id
     LEFT JOIN ticket_priorities p ON p.id = t.priority_id
     LEFT JOIN ticket_types ty     ON ty.id = t.ticket_type_id
     WHERE t.id = ?"
);
$tq->execute([$ticketId]);
$ticket = $tq->fetch(PDO::FETCH_ASSOC);
if (!$ticket) {
    echo json_encode(['success' => false, 'error' => 'That ticket no longer exists.']);
    exit;
}

// The opening message — what the requester actually wrote. `is_initial` marks it
// explicitly; the ascending-id fallback covers rows predating that flag.
//
// ⚠️ `body_type` decides whether body_content is HTML or plain text — the same
// distinction the sanitiser honours. Getting it wrong here would either dump raw
// markup into a Jira description or strip a plain-text body to nothing.
$body = '';
try {
    $mq = $conn->prepare(
        "SELECT body_content, body_type FROM emails
         WHERE ticket_id = ? AND direction = 'inbound'
         ORDER BY is_initial DESC, id ASC LIMIT 1"
    );
    $mq->execute([$ticketId]);
    if ($m = $mq->fetch(PDO::FETCH_ASSOC)) {
        $body = integrationsBodyToText($m['body_content'] ?? '', (string)($m['body_type'] ?? 'text'));
    }
} catch (Exception $e) { /* no thread yet — the subject still carries the gist */ }

$ref     = (string)($ticket['ticket_number'] ?: ('#' . $ticket['id']));
$summary = trim((string)($in['summary'] ?? '')) ?: ('[' . $ref . '] ' . (string)$ticket['subject']);

$doc = (new IssueDoc)
    ->heading('Raised in FreeITSM')
    // ⚠️ Absolute, not BASE_URL. This link is read inside somebody else's
    // tracker, where a path resolves against THEIR host — see integrationsAbsoluteUrl().
    ->para('Ticket ', IssueDoc::link(integrationsAbsoluteUrl($conn, 'tickets/?id=' . (int)$ticket['id']), $ref));

$who = trim((string)($ticket['requester_name'] ?? '')) ?: (string)($ticket['requester_email'] ?? '');
if ($who !== '') {
    $doc->para('Reported by: ' . $who
        . ($ticket['requester_email'] ? ' (' . $ticket['requester_email'] . ')' : ''));
}
$meta = array_filter([
    $ticket['created_datetime'] ? 'Raised: ' . $ticket['created_datetime'] : null,
    // ⚠️ Priority as TEXT, never as Jira's priority field — Jira priorities are
    // per-project with arbitrary names and setting one 400s on any project that
    // renamed them.
    $ticket['priority_name'] ? 'Priority: ' . $ticket['priority_name'] : null,
    $ticket['type_name'] ? 'Type: ' . $ticket['type_name'] : null,
]);
if ($meta) $doc->para(implode(' · ', $meta));

if ($body !== '') {
    $doc->rule()->para($body);
}

// A confidential ticket (discussion #62) can still be escalated - the analyst
// may genuinely need the supplier's help - but only knowingly: the preview says
// so, and the escalation is refused unless that warning was acknowledged.
require_once __DIR__ . '/../../includes/ticket_sensitivity.php';
$isConfidential = ticketIsConfidential($conn, $ticketId);

if ($isPreview) {
    // ⚠️ The files are part of the preview, not a detail. "You cannot unsend
    // it" is doubly true of an attachment: a screenshot can carry a password, a
    // customer's name, a whole spreadsheet nobody meant to send outside the
    // company. The analyst sees the list before pressing Raise, or the safeguard
    // only covers half of what leaves.
    //
    // Listed for ANY connection this ticket could go to, because the connection
    // is chosen in the same modal and may not be picked yet. Whether they are
    // actually sent is each connection's own `send_attachments` setting.
    $files = [];
    foreach (integrationsTicketAttachments($conn, $ticketId) as $a) {
        $files[] = [
            'filename'    => $a['filename'],
            'size_human'  => integrationsFormatBytes($a['size']),
            'skip_reason' => $a['skip_reason'],
        ];
    }
    echo json_encode([
        'success'     => true,
        'preview'     => true,
        'summary'     => $summary,
        'body'        => $doc->toPlainText(),
        'attachments' => $files,
        'confidential' => $isConfidential,
    ]);
    exit;
}

if ($isConfidential && empty($in['confirm_confidential'])) {
    echo json_encode(['success' => false, 'confidential' => true,
        'error' => 'This ticket is confidential. Tick the box to confirm you mean to copy it into the tracker.']);
    exit;
}

if ($connectionId <= 0) { echo json_encode(['success' => false, 'error' => 'Choose a tracker.']); exit; }
if ($project === '')    { echo json_encode(['success' => false, 'error' => 'Enter a project key.']); exit; }

try {
    $link = integrationsEscalate($conn, [
        'entity_type'    => 'ticket',
        'entity_id'      => $ticketId,
        'connection_id'  => $connectionId,
        'target'         => ['project' => $project, 'issue_type' => $issueType],
        'summary'        => $summary,
        'body'           => $doc,
        'analyst_id'     => $_SESSION['analyst_id'] ?? null,
        'skip_if_linked' => false,   // the analyst asked for this one explicitly
    ]);
    echo json_encode(['success' => true, 'link' => $link]);
} catch (Exception $e) {
    // The tracker's own message is the useful part ("Epic Link is required"),
    // as is the guard's ("that tracker belongs to a different company").
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
