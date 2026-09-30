<?php
/**
 * API Endpoint: analyst sends a file (image, document, …) out over a
 * messaging channel from the ticket reading pane.
 *
 * The file twin of send_message.php — same recipient/window/channel
 * resolution, but multipart/form-data (ticket_id + file + optional caption)
 * instead of a JSON body, because a browser cannot put a file into JSON.
 *
 * Today only TelegramProvider implements MessagingProvider::sendMedia();
 * every other provider's default throws "not supported for this channel
 * yet", which this endpoint surfaces as an ordinary error rather than a 500.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/tenancy.php';
require_once '../../includes/messaging/messaging.php';
require_once '../../includes/uploads.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

// Same door as send_message.php — everyday reply work.
requireModuleAccessJson('tickets');

$tmpPath = null;

try {
    $ticketId = (int) ($_POST['ticket_id'] ?? 0);
    $caption  = trim((string) ($_POST['caption'] ?? ''));

    if ($ticketId <= 0) {
        throw new Exception('Ticket ID is required');
    }
    if (empty($_FILES['file'])) {
        throw new Exception('No file was uploaded');
    }

    $conn = connectToDatabase();

    // Multi-tenancy gate — never act on a ticket in a company this analyst can't access.
    if (!analystCanAccessTicket($conn, (int) $_SESSION['analyst_id'], $ticketId)) {
        throw new Exception('Ticket not found');
    }

    // The conversation: most recent inbound channel message gives us the
    // recipient and the channel row to reply from. See send_message.php for
    // the full reasoning — this mirrors it exactly.
    $stmt = $conn->prepare(
        "SELECT from_address, to_recipients, channel, channel_id
         FROM emails
         WHERE ticket_id = ? AND channel <> 'email' AND direction = 'Inbound'
         ORDER BY received_datetime DESC, id DESC
         LIMIT 1"
    );
    $stmt->execute([$ticketId]);
    $conv = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$conv) {
        throw new Exception('This ticket has no inbound channel message to reply to.');
    }

    $channelType = $conv['channel'];
    $recipient = $conv['from_address'];
    if ($channelType === 'slack') {
        $recipient = trim((string) ($conv['to_recipients'] ?? ''));
        if ($recipient === '') {
            throw new Exception('This ticket has no Slack thread to reply to.');
        }
    }
    $channel = loadMessagingChannel($conn, (int) $conv['channel_id']);
    if (!$channel) {
        throw new Exception('The channel this ticket arrived on no longer exists.');
    }
    if (empty($channel['is_active'])) {
        throw new Exception('The channel this ticket arrived on is inactive.');
    }

    // Same 24h-window rule as a text reply — see send_message.php for why
    // webchat/slack/telegram are exempt. Kept identical deliberately.
    if (!in_array($channelType, ['webchat', 'slack', 'telegram'], true)) {
        $win = $conn->prepare("SELECT last_inbound_at FROM tickets WHERE id = ?");
        $win->execute([$ticketId]);
        if (!channelWindowOpen($win->fetchColumn() ?: null)) {
            throw new Exception('The 24-hour reply window has closed. A pre-approved template message is required to reopen the conversation.');
        }
    }

    // Validate the upload the same way any other ticket attachment is (two
    // gates: an extension WE named, and content that matches it) — it is
    // about to be forwarded to a third party (Telegram), so it gets no more
    // trust than an inbound attachment would. Stored to a temp path first;
    // moved into the ticket's own attachment directory only once sending
    // succeeds and the email row (whose id that path is keyed on) exists.
    $stored = uploadStoreFile($_FILES['file'], sys_get_temp_dir(), attachmentAllowedTypes($conn));
    $tmpPath = $stored['path'];

    $provider = messagingProvider($channel);
    $providerMsgId = $provider->sendMedia($recipient, $tmpPath, $stored['mime'], $caption);

    // Store the outbound message in the shared thread (same shape as a text reply).
    $ins = $conn->prepare(
        "INSERT INTO emails (
            exchange_message_id, subject, from_address, from_name, to_recipients,
            received_datetime, body_content, body_type, has_attachments, ticket_id,
            is_initial, direction, channel, channel_id
        ) VALUES (?, NULL, ?, ?, ?, UTC_TIMESTAMP(), ?, 'text', 1, ?, 0, 'Outbound', ?, ?)"
    );
    $ins->execute([
        $providerMsgId !== '' ? $providerMsgId : null,
        (string) ($channel['phone_number'] ?? ''),
        $channel['name'] ?? 'Service Desk',
        $recipient,
        $caption, // shown as the message body in the thread, same as a normal reply's text
        $ticketId,
        $channelType,
        (int) $channel['id'],
    ]);
    $emailId = (int) $conn->lastInsertId();

    // Move the validated file into the same storage convention inbound channel
    // media uses (tickets/attachments/{floor(id/1000)}/{emailId}/…), so the
    // reading pane's existing attachment bar picks it up with no special case.
    $attachmentsDir = dirname(dirname(__DIR__)) . '/tickets/attachments';
    $subDir   = floor($emailId / 1000);
    $emailDir = $attachmentsDir . '/' . $subDir . '/' . $emailId;
    if (!is_dir($emailDir)) {
        mkdir($emailDir, 0755, true);
    }
    $finalPath = $emailDir . '/' . $stored['stored_name'];
    if (!rename($tmpPath, $finalPath)) {
        throw new Exception('The message was sent, but its attachment could not be saved to this ticket.');
    }
    $tmpPath = null; // moved — nothing left for the finally-style cleanup below

    $filePath = $subDir . '/' . $emailId . '/' . $stored['stored_name'];
    $conn->prepare(
        "INSERT INTO email_attachments (email_id, exchange_attachment_id, filename, content_type, content_id, file_path, file_size, is_inline)
         VALUES (?, NULL, ?, ?, NULL, ?, ?, 0)"
    )->execute([$emailId, $stored['original_name'], $stored['mime'], $filePath, $stored['size']]);

    $conn->prepare("UPDATE tickets SET updated_datetime = UTC_TIMESTAMP() WHERE id = ?")->execute([$ticketId]);

    echo json_encode(['success' => true, 'message' => 'Attachment sent']);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
} finally {
    // Only reached on a failure path before the rename() above — clean up the
    // validated-but-unsent temp copy rather than leaving it in the OS temp dir.
    if ($tmpPath !== null && is_file($tmpPath)) {
        @unlink($tmpPath);
    }
}
