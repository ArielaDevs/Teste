<?php
/**
 * API Endpoint: list messaging channels (WhatsApp etc.) for the settings page.
 * Never returns raw credentials — only whether they're set, plus the public
 * webhook URL to paste into the provider console.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/rbac.php';
require_once '../../includes/messaging/messaging.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

// Messaging settings tab — returns channel config.
//
// Slack is administered from System → Integrations (administrators-only), so an
// admin may list it without holding the Tickets messaging capability. They get
// ONLY the Slack rows: an admin without tickets.messaging still must not see a
// WhatsApp channel's configuration, so this narrows the result rather than
// widening the gate.
$slackOnly = false;
try {
    $capConn = connectToDatabase();
    $hasCap  = analystHasCapability($capConn, (int) $_SESSION['analyst_id'], Cap::TICKETS_MESSAGING);
} catch (Exception $e) {
    $hasCap = false;
}
if (!$hasCap) {
    if (!sessionIsAdmin()) {
        // Not an admin either → fail exactly as before, with the standard error.
        requireModuleAccessJson('tickets');
        requireCapabilityJson(Cap::TICKETS_MESSAGING);
    }
    $slackOnly = true;
}

try {
    $conn = connectToDatabase();

    // Public base URL the admin set (Settings → Messaging), returned so the UI can
    // pre-fill the field; webhook URLs are built via the shared helper.
    $configuredBase = '';
    try {
        $st = $conn->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'messaging_public_base_url'");
        $st->execute();
        $configuredBase = trim((string) ($st->fetchColumn() ?: ''));
    } catch (Exception $e) { /* table missing */ }

    // $slackOnly narrows an admin-without-the-capability to just the channels
    // they administer from System → Integrations.
    $rows = $slackOnly
        ? $conn->query("SELECT * FROM messaging_channels WHERE provider = 'slack' ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC)
        : $conn->query("SELECT * FROM messaging_channels ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
    $channels = array_map(function ($r) use ($conn) {
        $creds = messagingDecodeCredentials($r['credentials'] ?? null);
        return [
            'id'                    => (int) $r['id'],
            'name'                  => $r['name'],
            'channel_type'          => $r['channel_type'],
            'provider'              => $r['provider'],
            'phone_number'          => $r['phone_number'],
            'ingress_mode'          => $r['ingress_mode'],
            'tenant_id'             => $r['tenant_id'] !== null ? (int) $r['tenant_id'] : null,
            'is_active'             => (bool) $r['is_active'],
            'has_credentials'       => !empty($creds),
            'graph_version'         => $creds['graph_version'] ?? '',
            // Mattermost: the server address is not a secret, so the form can pre-fill it.
            'mm_server_url'         => $creds['server_url'] ?? '',
            // Slack: which channel is watched, and the workspace we last reached.
            // Neither is a secret — the tokens stay behind has_credentials.
            'watch_channel'         => $creds['watch_channel'] ?? '',
            'channel_ref'           => $r['channel_ref'] ?? '',
            'last_inbound_datetime' => $r['last_inbound_datetime'],
            'webhook_url'           => messagingWebhookUrl($conn, (int) $r['id']),
        ];
    }, $rows);

    echo json_encode([
        'success'          => true,
        'channels'         => $channels,
        'public_base_url'  => $configuredBase,
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
