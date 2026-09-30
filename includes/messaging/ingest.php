<?php
/**
 * Channel message ingest — the bridge from a normalised inbound message onto the
 * existing ticket "membrane". This is the channel twin of saveEmailToDatabase()
 * in api/tickets/check_mailbox_email.php: find-or-create a ticket, store the
 * message in the shared `emails` table (so the reading-pane thread works for
 * free), route the company, and keep the 24h window state current.
 *
 * Deliberately self-contained (no dependency on the email importer's internals)
 * so it is safe to include from the webhook with just functions.php + tenancy.php
 * + messaging.php loaded.
 */

require_once __DIR__ . '/messaging.php';
require_once __DIR__ . '/../tenancy.php';
require_once __DIR__ . '/../ticket_reply.php';
require_once __DIR__ . '/../ticket_snooze.php';
require_once __DIR__ . '/../uploads.php';   // uploadStoreBytes() — see F1

/**
 * Ingest one normalised inbound message for a (decrypted) channel row.
 * Returns ['status' => 'created'|'appended'|'duplicate', 'ticket_id' => int|null].
 */
function ingestInboundMessage(PDO $conn, array $channel, array $msg): array
{
    $channelType = $channel['channel_type'] ?? 'whatsapp';
    // ⚠️ The channel type MUST be passed: normalisation differs per channel, and
    // the phone rules would shred a Slack user id into a shared identity.
    $from = normaliseChannelIdentifier((string) ($msg['from'] ?? ''), $channelType);
    if ($from === '') {
        throw new Exception('Inbound message has no usable sender identifier');
    }

    // Telegram identifies a chat by an opaque chat id, not a phone number, so
    // — unlike WhatsApp, where the phone IS the identifier — there is nothing
    // here to match against an existing `users` row. Gate on it: no ticket is
    // created for a chat until it has shared its phone number and that phone
    // has been linked (matched to an existing user, or turned into a new one).
    // See messagingTelegramIdentityGate()'s own comment for the full flow.
    if ($channelType === 'telegram') {
        $gate = messagingTelegramIdentityGate($conn, $channel, $from, $msg);
        if ($gate !== null) {
            return $gate; // 'awaiting_phone' or 'awaiting_message' — no ticket yet
        }
    }
    $profileName   = trim((string) ($msg['profile_name'] ?? ''));
    $providerMsgId = trim((string) ($msg['provider_msg_id'] ?? ''));

    // Dedupe: providers can retry webhooks. Skip a message id we already stored.
    if ($providerMsgId !== '') {
        $dup = $conn->prepare("SELECT id FROM emails WHERE exchange_message_id = ? AND channel = ? LIMIT 1");
        $dup->execute([$providerMsgId, $channelType]);
        if ($dup->fetchColumn()) {
            return ['status' => 'duplicate', 'ticket_id' => null];
        }
    }

    // Body is the caption (channel media) or the text. Media is downloaded after the
    // message row exists, because attachments are keyed to the email row's id.
    $body = trim((string) ($msg['body'] ?? ''));
    $mediaItems = is_array($msg['media'] ?? null) ? $msg['media'] : [];
    $hasMedia = !empty($mediaItems);
    if ($body === '' && !$hasMedia) {
        $body = '[empty message]';
    }

    // Where a reply to this message has to go.
    //
    // ⚠️ Phone channels keep their previous value EXACTLY — the channel's own
    // number. Twilio and Meta do both supply a 'to' in the parsed message and it
    // is normally the same number, but "normally" is not a good enough reason to
    // change what a working WhatsApp install writes to the database. Only Slack,
    // where the sender and the destination are genuinely different things (you
    // answer into a channel and a thread, not to a person), reads it.
    $replyAddress = (string) ($channel['phone_number'] ?? '');
    if ($channelType === 'slack') {
        $replyAddress = trim((string) ($msg['to'] ?? ''));
    }

    $displayName = $profileName !== '' ? $profileName : $from;
    if ($channelType === 'slack') {
        // Ask Slack who this is. Never fatal: a ticket must still be raised if
        // the lookup fails, just with a less useful name on it.
        [$userId, $displayName] = resolveSlackRequester($conn, $channel, $from);
    } elseif ($channelType === 'telegram') {
        // The gate above guarantees a link row exists by this point — reuse
        // its resolved user (a real matched user, or the placeholder created
        // for this phone) rather than a second chat-id-keyed pseudo-user.
        $link = $conn->prepare("SELECT user_id FROM messaging_identity_links WHERE channel_type = 'telegram' AND external_id = ?");
        $link->execute([$from]);
        $userId = (int) $link->fetchColumn();
        if ($userId) {
            $nameStmt = $conn->prepare("SELECT display_name FROM users WHERE id = ?");
            $nameStmt->execute([$userId]);
            $known = trim((string) ($nameStmt->fetchColumn() ?: ''));
            if ($known !== '') {
                $displayName = $known;
            }
        } else {
            // Should not happen (the gate links before returning null), but a
            // ticket must still be raised rather than silently dropping the
            // message if it somehow does.
            $userId = getOrCreateChannelUser($conn, $from, $displayName, $channelType);
        }
    } else {
        $userId = getOrCreateChannelUser($conn, $from, $displayName, $channelType);
    }

    // Thread into an open conversation, else open a new ticket.
    //
    // ⚠️ "The same conversation" is not the same question on every channel. On
    // WhatsApp a person has ONE conversation with the service desk, so the sender
    // identifies it. In Slack the same person can have several unrelated threads
    // going at once — so the THREAD is the conversation, and matching on the
    // sender would pile every one of their questions onto a single ticket.
    $ticketId  = findOpenChannelTicket($conn, $from, $channelType, $replyAddress);
    $isInitial = $ticketId ? 0 : 1;
    $subject   = null;

    if (!$ticketId) {
        $ticketNumber = messagingGenerateTicketNumber($conn);
        $tenantId     = resolveTicketTenantForChannel($conn, $channel['id'], $from);
        $originId     = getChannelOriginId($conn, $channelType);
        $subject      = buildChannelSubject($channelType, $displayName, $body);

        $sql = "INSERT INTO tickets (
                    ticket_number, subject, status_id, priority_id,
                    created_datetime, updated_datetime, last_inbound_at,
                    user_id, tenant_id, origin_id
                ) VALUES (
                    ?, ?,
                    (SELECT id FROM ticket_statuses   WHERE is_active = 1 ORDER BY is_default DESC, display_order, id LIMIT 1),
                    (SELECT id FROM ticket_priorities WHERE is_active = 1 ORDER BY is_default DESC, display_order, id LIMIT 1),
                    UTC_TIMESTAMP(), UTC_TIMESTAMP(), UTC_TIMESTAMP(),
                    ?, ?, ?
                )";
        $conn->prepare($sql)->execute([$ticketNumber, $subject, $userId, $tenantId, $originId]);
        $ticketId = (int) $conn->lastInsertId();
        // Confidential defaults (discussion #62). No department or mailbox here
        // yet, so today this does nothing - but every ticket-creating path calls
        // it, so a default added later cannot be missed on this one.
        require_once __DIR__ . '/../ticket_sensitivity.php';
        ticketSensitivityApplyDefaults($conn, $ticketId);
    } else {
        $conn->prepare("UPDATE tickets SET updated_datetime = UTC_TIMESTAMP(), last_inbound_at = UTC_TIMESTAMP() WHERE id = ?")
             ->execute([$ticketId]);
        // Messaging in on a finished ticket is the customer coming back, exactly
        // as an email or portal reply is — same shared rule, same setting.
        reopenTicketForCustomerReply($conn, (int)$ticketId);
        wakeSnoozedTicketOnCustomerReply($conn, (int)$ticketId);
    }

    // Store the message in the shared emails table (channel = 'whatsapp' etc.).
    $sql = "INSERT INTO emails (
                exchange_message_id, subject, from_address, from_name,
                to_recipients, received_datetime, body_content, body_type,
                has_attachments, is_read, processed_datetime, ticket_id,
                is_initial, direction, channel, channel_id
            ) VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP(), ?, 'text', ?, 0, UTC_TIMESTAMP(), ?, ?, 'Inbound', ?, ?)";
    $stmt = $conn->prepare($sql);
    $stmt->execute([
        $providerMsgId !== '' ? $providerMsgId : null,
        $subject,
        $from,
        $displayName,
        $replyAddress,
        $body,
        0, // has_attachments — set below once media is actually downloaded
        $ticketId,
        $isInitial,
        $channelType,
        (int) $channel['id'],
    ]);
    $dbEmailId = (int) $conn->lastInsertId();

    // Download any media and attach it (reuses the email-attachment store, so it shows
    // in the reading pane's attachment bar like any email attachment).
    if ($hasMedia) {
        $saved = 0;
        $mediaRejected = [];
        $mediaQuarantined = [];
        try {
            $provider = messagingProvider($channel);
            foreach ($mediaItems as $mediaItem) {
                try {
                    $dl = $provider->downloadMedia($mediaItem);
                    if (!empty($dl['data'])) {
                        $name = $dl['filename'] ?? 'media';
                        $outcome = saveChannelMediaAttachment(
                            $conn, $dbEmailId,
                            $name,
                            $dl['content_type'] ?? 'application/octet-stream',
                            $dl['data']
                        );
                        if ($outcome['stored']) {
                            $saved++;
                            if ($outcome['reason'] !== null) {
                                $mediaQuarantined[] = ['name' => $name, 'reason' => $outcome['reason']];
                            }
                        } else {
                            $mediaRejected[] = ['name' => $name, 'reason' => $outcome['reason']];
                        }
                    }
                } catch (Exception $e) {
                    error_log('channel media download failed (email ' . $dbEmailId . '): ' . $e->getMessage());
                }
            }
        } catch (Exception $e) {
            error_log('channel media: provider unavailable: ' . $e->getMessage());
        }

        // A file we refused is a different thing from one we could not fetch, and the
        // analyst needs to be able to tell them apart.
        $mediaNotice = attachmentIngestNotice($mediaRejected, $mediaQuarantined);

        if ($saved > 0) {
            $conn->prepare("UPDATE emails SET has_attachments = 1 WHERE id = ?")->execute([$dbEmailId]);
            if ($mediaNotice !== '') {
                $conn->prepare("UPDATE emails SET body_content = CONCAT(body_content, ?) WHERE id = ?")
                     ->execute([$mediaNotice, $dbEmailId]);
            }
        } else {
            // Nothing stored: either we refused it, or we couldn't fetch it at all.
            $note = $mediaNotice !== '' ? $mediaNotice : '[media attachment could not be downloaded]';
            $newBody = ($body === '' || $body === '[empty message]') ? $note : ($body . "\n\n" . $note);
            $conn->prepare("UPDATE emails SET body_content = ? WHERE id = ?")->execute([$newBody, $dbEmailId]);
        }
    }

    // Keep the channel's own last-inbound stamp current (diagnostics / settings).
    try {
        $conn->prepare("UPDATE messaging_channels SET last_inbound_datetime = UTC_TIMESTAMP() WHERE id = ?")
             ->execute([(int) $channel['id']]);
    } catch (Exception $e) { /* non-fatal */ }

    return ['status' => $isInitial ? 'created' : 'appended', 'ticket_id' => (int) $ticketId];
}

/**
 * Find the most recent OPEN (not closed, not trashed) ticket for this
 * conversation, so repeat messages thread into one ticket.
 *
 * ⚠️ What counts as "this conversation" is per-channel:
 *
 *   phone channels — the SENDER. A person has one running conversation with the
 *                    service desk, so their number identifies it.
 *   slack          — the THREAD. One person can have five unrelated threads open
 *                    at once, and matching on the sender would collapse all five
 *                    onto one ticket. $replyAddress is "C08HELP:1719500000.0001",
 *                    which is exactly the thread.
 */
function findOpenChannelTicket(PDO $conn, string $from, string $channelType, string $replyAddress = ''): ?int
{
    $byThread = ($channelType === 'slack' && $replyAddress !== '');

    $sql = "SELECT t.id
            FROM tickets t
            JOIN emails e ON e.ticket_id = t.id
            WHERE e.channel = ? AND " . ($byThread ? "e.to_recipients = ?" : "e.from_address = ?") . "
              AND t.deleted_datetime IS NULL
              AND t.closed_datetime IS NULL
            ORDER BY t.updated_datetime DESC
            LIMIT 1";
    $stmt = $conn->prepare($sql);
    $stmt->execute([$channelType, $byThread ? $replyAddress : $from]);
    $id = $stmt->fetchColumn();
    return $id ? (int) $id : null;
}

/**
 * Work out who a Slack message is from. Returns [userId|null, displayName].
 *
 * ⚠️ Halo's Slack integration requires the person's Slack email to equal their
 * Halo email, which quietly fails for guests, contractors and anyone whose Slack
 * account is a personal address. We do the lookup but never *depend* on it:
 *
 *   1. ask Slack for the profile (needs users:read + users:read.email)
 *   2. if it gives an email we already know, the ticket belongs to that person
 *   3. otherwise fall back to a channel pseudo-user — and NAME the case, so the
 *      ticket says "Sam Okafor (Slack)" or "Slack user @U08ABCDEF" rather than
 *      something anonymous that an analyst cannot act on
 *
 * Every failure path still returns a usable requester. Losing the identity must
 * never lose the ticket.
 */
function resolveSlackRequester(PDO $conn, array $channel, string $slackUserId): array
{
    $name = '';
    $email = '';
    try {
        $provider = messagingProvider($channel);
        if ($provider instanceof SlackProvider) {
            $info  = $provider->lookupUser($slackUserId);
            $name  = trim((string) ($info['name'] ?? ''));
            $email = trim((string) ($info['email'] ?? ''));
        }
    } catch (Exception $e) {
        error_log('Slack requester lookup failed for ' . $slackUserId . ': ' . $e->getMessage());
    }

    // A real person we already hold — their tickets, history and company all
    // line up with the rest of the service desk.
    if ($email !== '') {
        try {
            $stmt = $conn->prepare("SELECT id, display_name FROM users WHERE email = ? LIMIT 1");
            $stmt->execute([$email]);
            if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $known = trim((string) ($row['display_name'] ?? ''));
                return [(int) $row['id'], $known !== '' ? $known : ($name !== '' ? $name : $email)];
            }
        } catch (Exception $e) { /* fall through to the pseudo-user */ }
    }

    // Name the case rather than leaving it blank.
    $fallbackName = 'Slack user @' . $slackUserId;
    $displayName  = $name !== '' ? ($name . ' (Slack)') : $fallbackName;
    $userId = getOrCreateChannelUser($conn, $slackUserId, $displayName, 'slack');

    // ⚠️ Let the name HEAL. getOrCreateChannelUser only sets a display name when
    // it creates the row, so somebody first seen while the lookup was failing
    // would keep "Slack user @U0A1B2C3" for ever — even after the cause is fixed.
    //
    // That is not a rare edge: Slack grants an app its scopes at install time, so
    // an app created from a manifest cannot read profiles until someone clicks
    // "Reinstall to Workspace". Tickets raised before that all name nobody, and
    // there is no obvious way for an admin to connect the two facts.
    //
    // Only ever replaces our OWN fallback string. A name an analyst has edited by
    // hand does not match that pattern, so it is never overwritten.
    if ($userId && $name !== '') {
        try {
            $upd = $conn->prepare(
                "UPDATE users SET display_name = ? WHERE id = ? AND display_name = ?"
            );
            $upd->execute([$displayName, $userId, $fallbackName]);
        } catch (Exception $e) { /* cosmetic — never worth failing the ticket */ }
    }

    return [$userId, $displayName];
}

/**
 * The Telegram identity gate. Telegram identifies a chat by an opaque chat id
 * that says nothing about who the person actually is, so — unlike WhatsApp,
 * where the sender's phone number both identifies the chat AND is the thing
 * an analyst recognises — a brand-new Telegram chat cannot be matched to an
 * existing `users` row at all until the person shares their phone number.
 *
 * Returns:
 *   null                                    — already linked; caller continues
 *                                              normally (creates/updates the ticket).
 *   ['status'=>'awaiting_phone', ...]       — not linked yet, and this message
 *                                              wasn't a shared contact either.
 *                                              A prompt was just sent; no ticket
 *                                              is created, so the person's first
 *                                              message text is NOT silently lost
 *                                              into a ticket without their name.
 *   ['status'=>'awaiting_message', ...]     — the phone just arrived and was
 *                                              linked this call; a confirmation
 *                                              was sent, asking them to describe
 *                                              their issue. Still no ticket —
 *                                              sharing a contact card carries no
 *                                              question to raise one about.
 *
 * Either gated result leaves 'ticket_id' => null, matching ingestInboundMessage()'s
 * documented return shape.
 */
function messagingTelegramIdentityGate(PDO $conn, array $channel, string $chatId, array $msg): ?array
{
    // Already linked from a previous message — nothing to gate.
    $existing = $conn->prepare("SELECT id FROM messaging_identity_links WHERE channel_type = 'telegram' AND external_id = ?");
    $existing->execute([$chatId]);
    if ($existing->fetchColumn()) {
        return null;
    }

    $contact = is_array($msg['contact'] ?? null) ? $msg['contact'] : null;
    $phone = trim((string) ($contact['phone'] ?? ''));

    if ($phone === '') {
        // First contact from this chat, or they typed instead of tapping the
        // button — ask again. Best-effort: a failed send must not 500 the
        // webhook (the provider would just retry the whole batch).
        try {
            $provider = messagingProvider($channel);
            if ($provider instanceof TelegramProvider) {
                $provider->requestContact(
                    $chatId,
                    "Hi! To connect you with support, please share your phone number using the button below — then send your message again."
                );
            }
        } catch (Exception $e) {
            error_log('Telegram contact request failed for chat ' . $chatId . ': ' . $e->getMessage());
        }
        return ['status' => 'awaiting_phone', 'ticket_id' => null];
    }

    // Match against an existing user first — same phone, same person, same
    // ticket history. Only when nothing matches does this create a new one.
    $userId = messagingFindUserByPhone($conn, $phone);
    $matched = $userId !== null;
    if ($userId === null) {
        $displayName = trim((string) ($msg['profile_name'] ?? '')) ?: $phone;
        $pseudoEmail = ltrim($phone, '+') . '@telegram.local';
        try {
            $find = $conn->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
            $find->execute([$pseudoEmail]);
            $userId = $find->fetchColumn() ?: null;
            if (!$userId) {
                $ins = $conn->prepare("INSERT INTO users (email, display_name, phone, created_at) VALUES (?, ?, ?, UTC_TIMESTAMP())");
                $ins->execute([$pseudoEmail, $displayName, $phone]);
                $userId = (int) $conn->lastInsertId();
            }
        } catch (Exception $e) {
            error_log('Telegram identity: could not create placeholder user for ' . $chatId . ': ' . $e->getMessage());
            return ['status' => 'awaiting_phone', 'ticket_id' => null]; // try again next message
        }
    }
    $userId = (int) $userId;

    try {
        $link = $conn->prepare(
            "INSERT INTO messaging_identity_links (channel_type, external_id, user_id, phone) VALUES ('telegram', ?, ?, ?)"
        );
        $link->execute([$chatId, $userId, $phone]);
    } catch (Exception $e) {
        error_log('Telegram identity: could not save link for ' . $chatId . ': ' . $e->getMessage());
        return ['status' => 'awaiting_phone', 'ticket_id' => null]; // try again next message
    }

    try {
        $provider = messagingProvider($channel);
        if ($provider instanceof TelegramProvider) {
            $ack = $matched
                ? 'Thanks — found your account. Please describe your issue and we\'ll take it from here.'
                : 'Thanks! Please describe your issue and we\'ll take it from here.';
            $provider->sendMessage($chatId, $ack);
        }
    } catch (Exception $e) {
        error_log('Telegram identity: confirmation send failed for chat ' . $chatId . ': ' . $e->getMessage());
    }

    return ['status' => 'awaiting_message', 'ticket_id' => null];
}

/**
 * Find an existing user by phone number, comparing digits only so formatting
 * differences ("+1 415 555 0100" vs "14155550100") still match. Checks both
 * `phone` and `mobile`. Small, deliberate scan (not an indexed lookup) — phone
 * numbers are free-text and rarely normalised at entry, so a SQL equality or
 * prefix match would miss real matches; the users table is small enough on
 * every install this code runs on for a full scan to be the correct trade-off.
 */
function messagingFindUserByPhone(PDO $conn, string $phone): ?int
{
    $needle = preg_replace('/\D+/', '', $phone);
    if ($needle === '' || strlen($needle) < 7) {
        return null; // too short to be a real match, phone-vs-mobile column noise
    }
    $rows = $conn->query("SELECT id, phone, mobile FROM users WHERE (phone IS NOT NULL AND phone <> '') OR (mobile IS NOT NULL AND mobile <> '')")
                 ->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $row) {
        foreach (['phone', 'mobile'] as $col) {
            $digits = preg_replace('/\D+/', '', (string) ($row[$col] ?? ''));
            if ($digits !== '' && $digits === $needle) {
                return (int) $row['id'];
            }
        }
    }
    return null;
}

/**
 * Get-or-create a placeholder requester keyed by phone, so repeat senders map to
 * one user. The users table requires an email, so we synthesise a stable
 * non-routable address ('+44…@whatsapp.local'); the real identity is the
 * display name (the WhatsApp profile name) shown on the ticket.
 */
function getOrCreateChannelUser(PDO $conn, string $from, string $displayName, string $channelType): ?int
{
    $pseudoEmail = ltrim($from, '+') . '@' . $channelType . '.local';
    try {
        $stmt = $conn->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
        $stmt->execute([$pseudoEmail]);
        $id = $stmt->fetchColumn();
        if ($id) {
            return (int) $id;
        }
        $ins = $conn->prepare("INSERT INTO users (email, display_name, created_at) VALUES (?, ?, UTC_TIMESTAMP())");
        $ins->execute([$pseudoEmail, $displayName !== '' ? $displayName : $from]);
        return (int) $conn->lastInsertId();
    } catch (Exception $e) {
        // users table shape differs / unavailable → leave the ticket requester unset.
        return null;
    }
}

/** The origin id for a channel type (e.g. the seeded 'WhatsApp' origin), or null. */
function getChannelOriginId(PDO $conn, string $channelType): ?int
{
    $name = $channelType === 'whatsapp' ? 'WhatsApp' : ($channelType === 'webchat' ? 'Web chat' : ucfirst($channelType));
    try {
        $stmt = $conn->prepare("SELECT id FROM ticket_origins WHERE name = ? ORDER BY (tenant_id IS NULL) DESC, id ASC LIMIT 1");
        $stmt->execute([$name]);
        $id = $stmt->fetchColumn();
        return $id ? (int) $id : null;
    } catch (Exception $e) {
        return null;
    }
}

/** A short, human ticket subject from the first line of the first message. */
function buildChannelSubject(string $channelType, string $displayName, string $body): string
{
    $label = $channelType === 'whatsapp' ? 'WhatsApp' : ($channelType === 'webchat' ? 'Web chat' : ucfirst($channelType));
    $firstLine = trim(strtok($body, "\n"));
    if ($firstLine === '' || $firstLine === '[empty message]') {
        return "$label message from $displayName";
    }
    if (function_exists('mb_strimwidth')) {
        $snippet = mb_strimwidth($firstLine, 0, 80, '…');
    } else {
        $snippet = strlen($firstLine) > 80 ? substr($firstLine, 0, 79) . '…' : $firstLine;
    }
    return "$label: $snippet";
}

/** Unique ticket number in the existing XXX-NNN-NNNNN format. */
function messagingGenerateTicketNumber(PDO $conn): string
{
    for ($attempt = 0; $attempt < 10; $attempt++) {
        $letters = chr(rand(65, 90)) . chr(rand(65, 90)) . chr(rand(65, 90));
        $n1 = rand(0, 9) . rand(0, 9) . rand(0, 9);
        $n2 = rand(0, 9) . rand(0, 9) . rand(0, 9) . rand(0, 9) . rand(0, 9);
        $ticketNumber = "$letters-$n1-$n2";
        $check = $conn->prepare("SELECT COUNT(*) FROM tickets WHERE ticket_number = ?");
        $check->execute([$ticketNumber]);
        if (!$check->fetchColumn()) {
            return $ticketNumber;
        }
    }
    throw new Exception('Failed to generate unique ticket number');
}

/**
 * Save downloaded channel media as a ticket attachment, using the same storage
 * convention as email attachments (tickets/attachments/{floor(id/1000)}/{emailId}/…),
 * so get_ticket_attachments.php and the reading-pane attachment bar pick it up.
 */
/** @return array{stored:bool, reason:?string} — reason is set when the file was rejected or quarantined. */
function saveChannelMediaAttachment(PDO $conn, int $emailId, string $filename, string $contentType, string $data): array
{
    $attachmentsDir = dirname(dirname(__DIR__)) . '/tickets/attachments';
    $subDir   = floor($emailId / 1000);
    $emailDir = $attachmentsDir . '/' . $subDir . '/' . $emailId;

    // ⚠️ Media arrives from WhatsApp / web chat / Slack with a filename the SENDER
    // controls, and this wrote it into the web root after only replacing the
    // characters that make a path separator — so shell.php came through whole. The
    // report listed three ingest paths for this; this is a fourth with the same
    // shape. uploadStoreBytes() checks the extension AND the bytes, and picks the
    // name on disk itself.
    $stored = uploadStoreBytes($data, $filename, $emailDir, attachmentRejectPolicy($conn), attachmentAllowedTypes($conn));

    if (!$stored['stored']) {
        return ['stored' => false, 'reason' => $stored['reason']];   // caller notes it on the ticket
    }

    $filePath = $subDir . '/' . $emailId . '/' . $stored['stored_name'];

    // filename is the sender's, for display only. content_type is what we detected
    // from the bytes, not what the provider claimed.
    $sql = "INSERT INTO email_attachments (email_id, exchange_attachment_id, filename, content_type, content_id, file_path, file_size, is_inline)
            VALUES (?, NULL, ?, ?, NULL, ?, ?, 0)";
    $conn->prepare($sql)->execute([$emailId, $stored['original_name'], $stored['mime'], $filePath, $stored['size']]);

    return ['stored' => true, 'reason' => $stored['quarantined'] ? $stored['reason'] : null];
}
