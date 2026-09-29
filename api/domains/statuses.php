<?php
/**
 * GET — the domain statuses (open to anyone in the module: every form uses them).
 * POST {action:'save', id?, name, colour, alerts_enabled, is_active, display_order}
 * POST {action:'delete', id}
 * Writes need Cap::DOMAINS_STATUSES — capabilities guard writes, never reads.
 */
require_once __DIR__ . '/../../includes/domains/api_bootstrap.php';

domainApiRun(function () use ($conn) {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        $rows = $conn->query(
            "SELECT s.id, s.name, s.colour, s.alerts_enabled, s.is_active, s.display_order,
                    (SELECT COUNT(*) FROM domains d WHERE d.status_id = s.id) AS in_use
               FROM domain_statuses s ORDER BY s.display_order, s.name"
        )->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$r) {
            foreach (['id', 'display_order', 'in_use'] as $k) $r[$k] = (int)$r[$k];
            foreach (['alerts_enabled', 'is_active'] as $k) $r[$k] = (bool)(int)$r[$k];
        }
        domainApiOk(['statuses' => $rows]);
    }
    domainRequireCap($conn, Cap::DOMAINS_STATUSES);
    $in = domainApiBody();
    if (($in['action'] ?? '') === 'delete') {
        DomainsService::deleteStatus($conn, (int)($in['id'] ?? 0));
        WorkflowEngine::emitCrud('domain_status', 'deleted', (int)($in['id'] ?? 0));
        domainApiOk();
    }
    $res = DomainsService::saveStatus($conn, $in);
    WorkflowEngine::emitCrud('domain_status', $res['created'] ? 'created' : 'updated', $res['id'], (string)($in['name'] ?? ''));
    domainSyncAfterStatusChange($conn);
    domainApiOk(['id' => $res['id']]);
});

/** A status switched to "no alerts" drops its domains from the calendar. */
function domainSyncAfterStatusChange(PDO $conn): void
{
    try { require_once __DIR__ . '/../../includes/domains/calendar.php'; domainSyncExpiryCalendar($conn); } catch (Throwable $e) {}
}
