<?php
/**
 * Who has looked at a ticket (discussion #62, step 2).
 *
 * WHY
 * ---
 * Managers are coming to the self-service portal. If a manager can read the
 * tickets of the people they manage, those people are owed the knowledge of
 * WHEN they did. So the requester sees, on their own ticket, every time a
 * manager opened it - shown by default, not behind a button.
 *
 * WHAT THE REQUESTER DOES NOT SEE: service-desk views. Ed's reasoning: "Viewed
 * by the service desk, Monday 09:02" invites "why did you look at my ticket and
 * not do anything about it", which is pressure the desk should not be under for
 * doing its job. Analysts' views are recorded all the same, and appear in the
 * ticket's audit trail for analysts.
 *
 * STORAGE
 * -------
 * `ticket_views`, one row per viewer per UTC day, kept by the unique key
 * uq_ticket_views_day - opening a ticket twenty times is one row with
 * view_count 20. Its own table rather than ticket_audit, because the audit
 * trail's actor can only be an analyst and a manager reading in the portal is
 * not one; and because views are many times more frequent than changes.
 *
 * ⚠️ The unique key is what makes the upsert below an upsert. Database
 * Verification creates tables without constraints, so it is restored from
 * db_verify_indexes.php - without it every view would be a new row.
 */

function ticketViewsReady(PDO $conn): bool
{
    static $ready = null;
    if ($ready === null) {
        try {
            $conn->query("SELECT viewer_type FROM ticket_views LIMIT 0");
            $ready = true;
        } catch (Throwable $e) {
            $ready = false;
        }
    }
    return $ready;
}

/**
 * Record that someone opened a ticket. Never throws: a view that fails to be
 * recorded must not stop the ticket from opening.
 *
 * @param string $viewerType 'analyst' or 'user' (a self-service portal user)
 */
function ticketViewRecord(PDO $conn, int $ticketId, string $viewerType, int $viewerId): void
{
    if ($ticketId <= 0 || $viewerId <= 0 || !in_array($viewerType, ['analyst', 'user'], true)) {
        return;
    }
    try {
        if (!ticketViewsReady($conn)) {
            return;
        }
        $conn->prepare(
            "INSERT INTO ticket_views
                    (ticket_id, viewer_type, viewer_id, view_date, first_viewed_datetime, last_viewed_datetime, view_count)
             VALUES (?, ?, ?, UTC_DATE(), UTC_TIMESTAMP(), UTC_TIMESTAMP(), 1)
             ON DUPLICATE KEY UPDATE last_viewed_datetime = UTC_TIMESTAMP(), view_count = view_count + 1"
        )->execute([$ticketId, $viewerType, $viewerId]);
    } catch (Throwable $e) {
        error_log('[ticket_views] could not record a view: ' . $e->getMessage());
    }
}

/**
 * Views shaped as audit-trail rows, so the analyst's Audit window (desktop and
 * phone alike, both fed by api/tickets/get_ticket_audit.php) shows them with no
 * change of its own. One row per viewer per day, dated at their LAST view.
 *
 * The view count goes in new_value as a plain number phrase; times are left to
 * created_datetime, which the browser formats in the reader's own time zone.
 */
function ticketViewsAsAuditRows(PDO $conn, int $ticketId): array
{
    if (!ticketViewsReady($conn)) {
        return [];
    }
    $stmt = $conn->prepare(
        "SELECT v.id, v.viewer_type, v.viewer_id, v.view_count, v.last_viewed_datetime,
                a.full_name AS analyst_name,
                COALESCE(NULLIF(u.display_name, ''), u.email) AS user_name
           FROM ticket_views v
      LEFT JOIN analysts a ON v.viewer_type = 'analyst' AND a.id = v.viewer_id
      LEFT JOIN users    u ON v.viewer_type = 'user'    AND u.id = v.viewer_id
          WHERE v.ticket_id = ?
       ORDER BY v.last_viewed_datetime DESC"
    );
    $stmt->execute([$ticketId]);

    $rows = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $v) {
        $isUser = $v['viewer_type'] === 'user';
        $name   = $isUser ? $v['user_name'] : $v['analyst_name'];
        $count  = (int)$v['view_count'];
        $rows[] = [
            'id'               => 'v' . $v['id'],   // never collides with an audit id
            'ticket_id'        => $ticketId,
            'field_name'       => 'Viewed',
            'old_value'        => null,
            'new_value'        => $count === 1 ? 'Opened once that day' : 'Opened ' . $count . ' times that day',
            'created_datetime' => $v['last_viewed_datetime'],
            'analyst_id'       => $isUser ? null : (int)$v['viewer_id'],
            // A portal viewer is labelled as such, so nobody mistakes a manager
            // reading in the portal for a member of the service desk.
            'analyst_name'     => $name !== null ? ($isUser ? $name . ' (self-service portal)' : $name) : null,
            'author_kind'      => $name !== null ? ($isUser ? 'portal' : 'analyst') : 'former',
            'is_view'          => true,
        ];
    }
    return $rows;
}

/**
 * Portal viewers of a ticket OTHER than its requester, newest first - what the
 * requester is shown under "Who has seen this ticket". Analysts are
 * deliberately absent (see the header).
 *
 * @return array<int, array{name:string, first:string, last:string, count:int}>
 */
function ticketViewsForRequester(PDO $conn, int $ticketId, int $requesterUserId): array
{
    if (!ticketViewsReady($conn)) {
        return [];
    }
    $stmt = $conn->prepare(
        "SELECT COALESCE(NULLIF(u.display_name, ''), u.email) AS name,
                v.first_viewed_datetime AS first, v.last_viewed_datetime AS last, v.view_count AS count
           FROM ticket_views v
           JOIN users u ON u.id = v.viewer_id
          WHERE v.ticket_id = ? AND v.viewer_type = 'user' AND v.viewer_id <> ?
       ORDER BY v.last_viewed_datetime DESC"
    );
    $stmt->execute([$ticketId, $requesterUserId]);
    return array_map(function ($r) {
        return ['name' => (string)$r['name'], 'first' => $r['first'], 'last' => $r['last'], 'count' => (int)$r['count']];
    }, $stmt->fetchAll(PDO::FETCH_ASSOC));
}
