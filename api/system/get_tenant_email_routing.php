<?php
/**
 * API: "How email reaches this company" — the derived, read-only routing
 * summary (design §7). Given a company (tenant), work out every way inbound
 * mail reaches it and which identity replies go out from.
 *
 * Routing is never stored as a per-company "mode" (design §3.5 / §7 rejected
 * idea) — it's *derived* here from the mailboxes and registered domains:
 *
 *   - Pinned mailbox (tenant_id = this company) → a dedicated inbound address
 *     AND the outbound reply identity. Sender domain is ignored.
 *   - Shared-intake mailbox (tenant_id NULL) → reaches this company only when
 *     the sender's domain matches one of the company's registered domains.
 *   - The Default company also catches anything that routed nowhere (triage /
 *     NULL tenant), so it's never a dead end.
 *
 * Replies always go back out from the same mailbox the ticket arrived on
 * (see api/tickets/send_email.php → getMailboxForTicket), so each inbound
 * path's reply identity is just that path's mailbox.
 *
 * "Company" is the user-facing word for a tenant; the table/code stays `tenant`.
 *
 * GET ?tenant_id=N
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/encryption.php';
require_once '../../includes/tenancy.php';
require_once '../../includes/mailbox_auth.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}
requireModuleAccessJson('system');

$tenantId = isset($_GET['tenant_id']) ? (int)$_GET['tenant_id'] : 0;
if ($tenantId <= 0) {
    echo json_encode(['success' => false, 'error' => 'Missing company id']);
    exit;
}

try {
    $conn = connectToDatabase();

    // The company itself.
    $stmt = $conn->prepare("SELECT id, name, is_default FROM tenants WHERE id = ?");
    $stmt->execute([$tenantId]);
    $company = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$company) {
        echo json_encode(['success' => false, 'error' => 'Company not found']);
        exit;
    }
    $isDefault = (bool)$company['is_default'];

    // Registered domains (shared-intake routing keys).
    $stmt = $conn->prepare("SELECT domain FROM tenant_domains WHERE tenant_id = ? ORDER BY domain");
    $stmt->execute([$tenantId]);
    $domains = array_map(fn($r) => $r['domain'], $stmt->fetchAll(PDO::FETCH_ASSOC));

    // Mapped specific sender addresses (shared-intake routing keys, address-level).
    $senders = [];
    try {
        $stmt = $conn->prepare("SELECT email FROM tenant_sender_addresses WHERE tenant_id = ? ORDER BY email");
        $stmt->execute([$tenantId]);
        $senders = array_map(fn($r) => $r['email'], $stmt->fetchAll(PDO::FETCH_ASSOC));
    } catch (Exception $e) { $senders = []; }

    // Mailbox auth/active state is enough here — no secrets, so no decryption.
    // target_mailbox (the address) is encrypted at rest; decrypt just that one.
    // Shared definition: this used to be an inline OAuth-token test, which called
    // every Basic IMAP mailbox unauthenticated and warned that no mail would flow.
    $authExpr = mailboxAuthenticatedSql();

    // Pinned mailboxes for this company.
    $stmt = $conn->prepare(
        "SELECT id, name, target_mailbox, is_active, $authExpr AS is_authenticated
         FROM target_mailboxes WHERE tenant_id = ? ORDER BY name"
    );
    $stmt->execute([$tenantId]);
    $pinnedRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Active shared-intake mailboxes (tenant_id NULL) — the install's shared
    // front doors. They reach this company only via domain match.
    $stmt = $conn->prepare(
        "SELECT id, name, target_mailbox, is_active, $authExpr AS is_authenticated
         FROM target_mailboxes WHERE tenant_id IS NULL ORDER BY name"
    );
    $stmt->execute();
    $sharedRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $decryptAddr = function ($v) {
        if (empty($v)) return '';
        try { return decryptValue($v); } catch (Exception $e) { return ''; }
    };

    $paths = [];

    // Pinned: dedicated inbound + outbound identity, sender ignored.
    foreach ($pinnedRows as $m) {
        $paths[] = [
            'type'          => 'pinned',
            'name'          => $m['name'],
            'address'       => $decryptAddr($m['target_mailbox']),
            'is_active'     => (bool)$m['is_active'],
            'authenticated' => (bool)$m['is_authenticated'],
        ];
    }

    // Shared intake: a path only when this company has domains or specific
    // sender addresses to match on.
    if (!empty($domains) || !empty($senders)) {
        foreach ($sharedRows as $m) {
            if (!$m['is_active']) continue; // inactive shared box routes nothing
            $paths[] = [
                'type'            => 'shared',
                'name'            => $m['name'],
                'address'         => $decryptAddr($m['target_mailbox']),
                'is_active'       => true,
                'authenticated'   => (bool)$m['is_authenticated'],
                'matched_domains' => $domains,
                'matched_senders' => $senders,
            ];
        }
    }

    // Warnings — surface dead-ends and half-configured routes.
    $warnings = [];
    $hasActiveShared = false;
    foreach ($sharedRows as $m) { if ($m['is_active']) { $hasActiveShared = true; break; } }

    if (empty($paths)) {
        // No pinned box, and either no domains/senders or nothing shared to match them.
        $warnings[] = 'no_route';
    }
    if ((!empty($domains) || !empty($senders)) && !$hasActiveShared) {
        // Domains/senders registered but nothing to match them against.
        $warnings[] = 'domains_no_shared';
    }
    foreach ($paths as $p) {
        if (!$p['authenticated']) { $warnings[] = 'unauthenticated'; break; }
    }

    echo json_encode([
        'success'    => true,
        'company'    => ['id' => (int)$company['id'], 'name' => $company['name'], 'is_default' => $isDefault],
        'domains'    => $domains,
        'senders'    => $senders,
        'paths'      => $paths,
        'warnings'   => $warnings,
        // Default catches anything that routed nowhere (triage / NULL tenant).
        'catches_unrouted' => $isDefault,
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
