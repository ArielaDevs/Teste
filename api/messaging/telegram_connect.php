<?php
/**
 * API Endpoint: register a saved Telegram channel's webhook with Telegram.
 *
 *   POST JSON { id }
 *
 * The Connect button in Tickets → Settings → Messaging. Calls Telegram's
 * setWebhook with this channel's webhook URL and its stored secret token, using
 * the stored bot token - all server-side.
 *
 * Added at merge time for PR #159, which instead showed a ready-made
 * `curl https://api.telegram.org/bot<TOKEN>/setWebhook?...` command to copy and
 * run. That put the bot token in the browser, the clipboard and the admin's shell
 * history, and asked an IT manager to run a terminal command. Here the token
 * never leaves the server and the result is shown in the dialog.
 *
 * Same gate as saving a channel: Tickets module + the messaging capability, and
 * the channel must be one this analyst may administer.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/rbac.php';
require_once '../../includes/messaging/messaging.php';
require_once '../../includes/tenancy.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}
requireModuleAccessJson('tickets');
requireCapabilityJson(Cap::TICKETS_MESSAGING);

try {
    $data = json_decode(file_get_contents('php://input'), true);
    $id = (int) ($data['id'] ?? 0);
    if ($id <= 0) {
        throw new Exception('Save the channel first.');
    }

    $conn = connectToDatabase();
    // Same refusal as save_channel.php for a channel in a company this analyst
    // cannot administer: "not found", never "not yours".
    if (!analystCanAccessChannel($conn, (int) $_SESSION['analyst_id'], $id)) {
        throw new Exception('Channel not found');
    }
    $channel = loadMessagingChannel($conn, $id);
    if (!$channel || ($channel['provider'] ?? '') !== 'telegram') {
        throw new Exception('Channel not found');
    }
    $secret = (string) ($channel['verify_token'] ?? '');
    if ($secret === '') {
        throw new Exception('This channel has no secret token yet - set one and save first.');
    }

    $provider = messagingProvider($channel);
    if (!$provider instanceof TelegramProvider) {
        throw new Exception('Channel not found');
    }
    $url = messagingWebhookUrl($conn, $id);
    $result = $provider->setWebhook($url, $secret);

    echo json_encode(['success' => true, 'message' => $result, 'webhook_url' => $url]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
