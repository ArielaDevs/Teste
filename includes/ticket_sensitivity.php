<?php
/**
 * Ticket sensitivity: Normal or Confidential (discussion #62, step 1).
 *
 * WHY THIS EXISTS BEFORE MANAGERS DO
 * ----------------------------------
 * Managers are coming to the self-service portal: people who can read tickets
 * raised by the people they manage. Before that ships, a ticket has to be able
 * to say "not for my manager". Someone emailing HR about a diagnosis, or about
 * how to raise a grievance against their own manager, must never have that
 * ticket shown to the manager. Confidential is how a ticket says so; step 3
 * decides what a manager sees of one (default: nothing at all).
 *
 * THE DANGEROUS MOMENT IS BEFORE ANYONE HAS LOOKED
 * ------------------------------------------------
 * An analyst can mark a ticket confidential - but a grievance emailed to HR
 * sits unmarked until someone gets to it. So a ticket also becomes confidential
 * WITHOUT anyone deciding, when:
 *   - the requester ticks Confidential when raising it in the portal;
 *   - it arrives through a mailbox set to confidential (an HR mailbox);
 *   - it is in, or is moved into, a service-desk department set to confidential;
 *   - it is split from, or merged with, a confidential ticket.
 *
 * 🔴 DEFAULTS ONLY EVER RAISE. Nothing automatic ever makes a ticket Normal
 * again: moving a ticket OUT of HR leaves it confidential until a person
 * decides otherwise. Lowering is always a deliberate act by an analyst.
 *
 * 🔴 EVERY PATH THAT CREATES A TICKET MUST CALL ticketSensitivityApplyDefaults()
 * after its insert. There were eleven such paths when this was written and only
 * two went through TicketsService, so a hook in the service alone would have
 * missed nine - including the mailbox ingest, which is where the HR email lands.
 * The list is in the wiki: Confidential-Tickets-Developer-Guide.
 *
 * Confidential does NOT restrict analysts. Who on the service desk can see a
 * ticket is still decided by teams, departments and companies, as before.
 */

const TICKET_SENSITIVITIES = ['normal', 'confidential'];

/**
 * Have the columns been added yet? New code reaches an install before
 * System -> Database Verification runs, and every ticket path calls into here,
 * so a missing column must mean "do nothing", never an error.
 */
function ticketSensitivityReady(PDO $conn): bool
{
    static $ready = null;
    if ($ready === null) {
        try {
            $conn->query("SELECT sensitivity FROM tickets LIMIT 0");
            $conn->query("SELECT default_sensitivity FROM departments LIMIT 0");
            $conn->query("SELECT default_sensitivity FROM target_mailboxes LIMIT 0");
            $ready = true;
        } catch (Throwable $e) {
            $ready = false;
        }
    }
    return $ready;
}

/** Anything unrecognised reads as normal - the column's own default. */
function ticketSensitivityNormalise($value): string
{
    $v = strtolower(trim((string)$value));
    return in_array($v, TICKET_SENSITIVITIES, true) ? $v : 'normal';
}

/**
 * Make a ticket confidential if it is not already, and say why in the audit
 * trail. Returns true if it changed.
 *
 * The audit entry has no analyst (NULL): a rule did this, not a person, and the
 * value names the rule so nobody has to wonder how it got there.
 */
function ticketSensitivityRaise(PDO $conn, int $ticketId, string $reason): bool
{
    if (!ticketSensitivityReady($conn)) {
        return false;
    }
    $stmt = $conn->prepare("UPDATE tickets SET sensitivity = 'confidential' WHERE id = ? AND sensitivity <> 'confidential'");
    $stmt->execute([$ticketId]);
    if ($stmt->rowCount() === 0) {
        return false;
    }
    $conn->prepare(
        "INSERT INTO ticket_audit (ticket_id, analyst_id, field_name, old_value, new_value, created_datetime)
         VALUES (?, NULL, 'Sensitivity', 'Normal', ?, UTC_TIMESTAMP())"
    )->execute([$ticketId, 'Confidential - ' . $reason]);
    return true;
}

/**
 * Apply the automatic defaults to a ticket: its service-desk department's, and
 * the mailbox it arrived through, if there was one. Call after every insert, and
 * after every change of department.
 */
function ticketSensitivityApplyDefaults(PDO $conn, int $ticketId, ?int $mailboxId = null): bool
{
    if (!ticketSensitivityReady($conn)) {
        return false;
    }
    $stmt = $conn->prepare(
        "SELECT d.name, d.default_sensitivity
           FROM tickets t JOIN departments d ON d.id = t.department_id
          WHERE t.id = ?"
    );
    $stmt->execute([$ticketId]);
    $dept = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($dept && $dept['default_sensitivity'] === 'confidential') {
        return ticketSensitivityRaise($conn, $ticketId, 'the ' . $dept['name'] . ' department is confidential');
    }

    if ($mailboxId) {
        $stmt = $conn->prepare("SELECT name, default_sensitivity FROM target_mailboxes WHERE id = ?");
        $stmt->execute([$mailboxId]);
        $mb = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($mb && $mb['default_sensitivity'] === 'confidential') {
            return ticketSensitivityRaise($conn, $ticketId, 'arrived through the ' . $mb['name'] . ' mailbox');
        }
    }
    return false;
}

/**
 * A ticket made from, or combined with, others is confidential if any of them
 * was. Used by split (one source) and merge (several).
 *
 * @param int[] $sourceIds
 */
function ticketSensitivityInherit(PDO $conn, int $ticketId, array $sourceIds, string $reason): bool
{
    if (!ticketSensitivityReady($conn) || !$sourceIds) {
        return false;
    }
    $ids = array_values(array_unique(array_map('intval', $sourceIds)));
    $in  = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $conn->prepare("SELECT COUNT(*) FROM tickets WHERE id IN ($in) AND sensitivity = 'confidential'");
    $stmt->execute($ids);
    if ((int)$stmt->fetchColumn() === 0) {
        return false;
    }
    return ticketSensitivityRaise($conn, $ticketId, $reason);
}
