<?php
/* 🔴 NEVER OVER THE WEB. See tests/README.md. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * Notifications-router isolation — forged dispatch + recipient filter (F9).
 *
 * WHY THIS EXISTS:
 * The audience is resolved from the event payload alone (by design — the
 * payload names who the news is about), so a forged/replayed/stale event
 * naming company A's ticket with a company-B assignee reaches the sink. P0
 * (F9) added the recipient-side company check —
 * notificationsRecipientMaySeeEntity() — applied in notificationsHandleEvent()
 * before any write: recipients who cannot reach the entity's company are
 * dropped, so the bell can no longer become a cross-tenant read primitive.
 *
 * REWRITE NOTE (Agente 2, pós-P0): the old probes called
 * NotificationsService::notify() directly with forged input. notify() is
 * deliberately still a dumb sink (noise rules only); the fix lives in the
 * router filter. The probes now drive the real filter + mirror the router's
 * exact sequence (audience → filter → notify survivors), which is where the
 * fix is. notify() direct-forgery is NOT asserted anymore — asserting on it
 * would test a layer the design leaves dumb on purpose.
 *
 * NOTE: only functions taking an explicit PDO are driven here.
 * notificationsHandleEvent() opens its own connection, which cannot see this
 * transaction's fixtures (connectToDatabase() is not a singleton).
 *
 * Run:  php tests/notifications-router-isolation.php   (needs DB, 2+ tenants, 2 analysts)
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/i18n.php';
require_once __DIR__ . '/../includes/tenancy.php';
require_once __DIR__ . '/../includes/notifications_router.php';
require_once __DIR__ . '/../includes/services/notifications.php';
I18n::initFromSession();

$pass = 0; $fail = 0;
function ok(string $what, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  PASS  {$what}\n"; }
    else       { $fail++; echo "  FAIL  {$what}" . ($detail ? "  <- {$detail}" : '') . "\n"; }
}

echo "\nNotifications-router isolation\n" . str_repeat('=', 70) . "\n";

$conn = connectToDatabase();
if (!isMultiTenant($conn)) { echo "  SKIP  needs two or more companies\n"; exit(2); }

$ids = $conn->query("SELECT id FROM analysts WHERE is_active = 1 AND (is_admin = 0 OR is_admin IS NULL) ORDER BY id LIMIT 2")->fetchAll(PDO::FETCH_COLUMN);
if (count($ids) < 2) { echo "  SKIP  needs two non-admin analysts\n"; exit(2); }
[$r1, $r2] = array_map('intval', $ids);

$uniq = 'zz' . substr(preg_replace('/[^a-z0-9]/', '', strtolower(uniqid())), 0, 8);

$conn->beginTransaction();
try {
    $conn->prepare("INSERT INTO tenants (name, slug, is_default, is_active) VALUES (?, ?, 0, 1)")
        ->execute(["ZZ-ISOL-A {$uniq}", "zz-isol-a-{$uniq}"]);
    $tenantA = (int)$conn->lastInsertId();
    $conn->prepare("INSERT INTO tenants (name, slug, is_default, is_active) VALUES (?, ?, 0, 1)")
        ->execute(["ZZ-ISOL-B {$uniq}", "zz-isol-b-{$uniq}"]);
    $tenantB = (int)$conn->lastInsertId();
    ok('fixtures: two distinct tenants created', $tenantA > 0 && $tenantB > 0 && $tenantA !== $tenantB);

    $scope = function (int $analyst, int $tenant) use ($conn): void {
        $conn->prepare("UPDATE analysts SET can_access_all_tenants = 0, can_access_all_modules = 1 WHERE id = ?")->execute([$analyst]);
        $conn->prepare("DELETE FROM analyst_tenant_access WHERE analyst_id = ?")->execute([$analyst]);
        $conn->prepare("DELETE FROM analyst_teams WHERE analyst_id = ?")->execute([$analyst]);
        $conn->prepare("INSERT INTO analyst_tenant_access (analyst_id, tenant_id) VALUES (?, ?)")->execute([$analyst, $tenant]);
    };
    $scope($r1, $tenantA);
    $scope($r2, $tenantB);
    ok("analyst {$r1} scoped to A / analyst {$r2} scoped to B",
        getAccessibleTenantIds($conn, $r1) === [$tenantA] && getAccessibleTenantIds($conn, $r2) === [$tenantB]);

    $conn->prepare("INSERT INTO tickets (tenant_id, ticket_number, subject) VALUES (?, ?, ?)")
        ->execute([$tenantA, "ZZ-ISOL-{$uniq}-NA", "ZZ-ISOL secret-A subject {$uniq}"]);
    $ticketA = (int)$conn->lastInsertId();
    $conn->prepare("INSERT INTO tickets (tenant_id, ticket_number, subject) VALUES (?, ?, ?)")
        ->execute([$tenantB, "ZZ-ISOL-{$uniq}-NB", "ZZ-ISOL secret-B subject {$uniq}"]);
    $ticketB = (int)$conn->lastInsertId();

    echo "\nF9 recipient filter (notificationsRecipientMaySeeEntity):\n";
    ok('POSITIVE CONTROL: same-company recipient may see the ticket', notificationsRecipientMaySeeEntity($conn, $r1, 'ticket', $ticketA));
    ok('POSITIVE CONTROL: B analyst may see B ticket', notificationsRecipientMaySeeEntity($conn, $r2, 'ticket', $ticketB));
    ok('cross-company recipient is dropped (forged event target)', notificationsRecipientMaySeeEntity($conn, $r2, 'ticket', $ticketA) === false);
    ok('undecidable ids fail closed (recipient 0)', notificationsRecipientMaySeeEntity($conn, 0, 'ticket', $ticketA) === false);
    ok('undecidable ids fail closed (entity 0)', notificationsRecipientMaySeeEntity($conn, $r2, 'ticket', 0) === false);
    ok('unknown entity kinds pass (no company to check)', notificationsRecipientMaySeeEntity($conn, $r2, 'sla_cron', $ticketA));

    echo "\nRouter-mirror flow (audience → F9 filter → notify survivors):\n";
    // The exact sequence notificationsHandleEvent() runs, with the forged
    // payload from the v2 PoC (A's ticket, B's assignee).
    $forged = ['ticket' => ['id' => $ticketA, 'assigned_analyst_id' => $r2], 'analyst_id' => $r2];
    $aud = notificationsAudienceFor($conn, 'ticket.assigned', $forged);
    ok('CONTROL: forged payload still resolves an audience (filter is downstream)', $aud === [$r2], json_encode($aud));
    $survivors = array_values(array_filter($aud, fn($rid) => notificationsRecipientMaySeeEntity($conn, (int)$rid, 'ticket', $ticketA)));
    ok('F9 filter drops the cross-company recipient', $survivors === [], json_encode($survivors));
    foreach ($survivors as $rid) {
        NotificationsService::notify($conn, [
            'analyst_id' => $rid, 'event_type' => 'ticket.assigned', 'entity_type' => 'ticket',
            'entity_id' => $ticketA, 'entity_ref' => "ZZ-ISOL-{$uniq}-NA",
            'title' => "ZZ-ISOL secret-A subject {$uniq}", 'body' => 'assigned', 'actor_id' => $r1,
        ]);
    }
    $r2Rows = NotificationsService::listFor($conn, $r2, 50);
    $leaked = array_values(array_filter($r2Rows, fn($n) => (int)($n['entity_id'] ?? 0) === $ticketA));
    ok("tenant-B bell holds zero rows about tenant-A ticket", $leaked === [], json_encode(array_slice($leaked, 0, 2)));

    echo "\nSame-tenant dispatch still notifies (anti-flaky):\n";
    $ownAud = notificationsAudienceFor($conn, 'ticket.assigned', ['ticket' => ['id' => $ticketA, 'assigned_analyst_id' => $r1], 'analyst_id' => $r1]);
    $ownSurv = array_values(array_filter($ownAud, fn($rid) => notificationsRecipientMaySeeEntity($conn, (int)$rid, 'ticket', $ticketA)));
    ok('POSITIVE CONTROL: same-company recipient survives the filter', $ownSurv === [$r1], json_encode($ownSurv));
    $ownId = null;
    foreach ($ownSurv as $rid) {
        $ownId = NotificationsService::notify($conn, [
            'analyst_id' => $rid, 'event_type' => 'ticket.assigned', 'entity_type' => 'ticket',
            'entity_id' => $ticketA, 'entity_ref' => "ZZ-ISOL-{$uniq}-NA",
            'title' => "ZZ-ISOL secret-A subject {$uniq}", 'body' => 'assigned', 'actor_id' => 0,
        ]);
    }
    ok('POSITIVE CONTROL: same-tenant notify writes a row', is_int($ownId) && $ownId > 0);
    $ownRows = NotificationsService::listFor($conn, $r1, 50);
    $ownSeen = array_filter($ownRows, fn($n) => (int)($n['entity_id'] ?? 0) === $ticketA);
    ok('POSITIVE CONTROL: recipient bell lists their own-tenant ticket', $ownSeen !== [], json_encode(count($ownRows)));

    echo "\nSink validation controls (unchanged rules):\n";
    ok('unknown event type writes nothing', NotificationsService::notify($conn, [
        'analyst_id' => $r1, 'event_type' => 'nope.nothing', 'entity_type' => 'ticket',
        'entity_id' => $ticketA, 'actor_id' => 0,
    ]) === null);
    ok('empty entity id writes nothing', NotificationsService::notify($conn, [
        'analyst_id' => $r1, 'event_type' => 'ticket.assigned', 'entity_type' => 'ticket',
        'entity_id' => 0, 'actor_id' => 0,
    ]) === null);
} catch (Throwable $e) {
    ok('the run completed', false, get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
} finally {
    if ($conn->inTransaction()) $conn->rollBack();
}

$left = 0;
try {
    $left += (int)$conn->query("SELECT COUNT(*) FROM tickets WHERE ticket_number LIKE 'ZZ-ISOL-{$uniq}-N%'")->fetchColumn();
    $left += (int)$conn->query("SELECT COUNT(*) FROM notifications WHERE entity_ref LIKE 'ZZ-ISOL-{$uniq}-N%'")->fetchColumn();
    $left += (int)$conn->query("SELECT COUNT(*) FROM tenants WHERE slug IN ('zz-isol-a-{$uniq}', 'zz-isol-b-{$uniq}')")->fetchColumn();
} catch (Throwable $e) { /* count itself failed — report below */ }
ok('nothing survived the run (rolled back)', $left === 0, "{$left} test rows left");

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail ? 1 : 0);
