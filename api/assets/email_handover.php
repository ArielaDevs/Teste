<?php
/**
 * API: email the handover document to the person it is about (discussion #56).
 *
 * POST { user_id: <int>, template_id: <int|null> }
 *
 * Sends through ssSendSystemEmail(), the same sender the portal uses, so it
 * inherits the configured sending mailbox rather than introducing a second idea
 * of how this installation sends mail.
 *
 * ⚠️ The email is built by the SAME renderer as the printed page, with the
 * stylesheet inlined in a <style> block — a mail client will not fetch an
 * external sheet. One layout, three destinations.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/services/assets.php';
require_once '../../includes/services/handover_templates.php';
require_once '../../includes/handover_styles.php';
require_once '../../includes/self_service_email.php';
require_once '../../includes/tenancy.php';
require_once '../../includes/i18n.php';
I18n::initFromSession();

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}
requireModuleAccessJson('assets');
Tz::init();   // the analyst's zone, for the assigned date (fmt_local)

try {
    $in     = json_decode(file_get_contents('php://input'), true) ?: [];
    $userId = (int)($in['user_id'] ?? 0);
    if ($userId <= 0) {
        throw new Exception('user_id is required');
    }

    $conn = connectToDatabase();
    $data = AssetsService::assetsForUser($conn, ActorContext::fromSession($conn), $userId);
    if ($data === null) {
        throw new Exception('Unknown user');
    }
    $user   = $data['user'];
    $assets = $data['assets'];

    $to = trim((string)($user['email'] ?? ''));
    if ($to === '') {
        // Stated plainly rather than reported as a send failure — there is
        // nothing wrong with the mail setup, the person simply has no address.
        throw new Exception(t('asset-management.handover.email_no_address'));
    }

    $template = HandoverTemplates::effective($conn, isset($in['template_id']) ? (int)$in['template_id'] : null);
    $body     = HandoverTemplates::renderBlocks($template['blocks'], $user, $assets, [
        'logo_path'    => null,      // a relative path would not resolve in a mail client
        'analyst_name' => $_SESSION['analyst_name'] ?? null,
    ]);

    $html = '<!DOCTYPE html><html><head><meta charset="UTF-8"><style>'
          . handoverDocumentCss()
          . '</style></head><body style="margin:0;padding:24px;background:#ffffff;">'
          . '<div class="hb-doc" style="max-width:760px;margin:0 auto;">' . $body . '</div>'
          . '</body></html>';

    $subject = t('asset-management.handover.email_subject', ['name' => $user['name']]);

    if (!ssSendSystemEmail($conn, $to, $subject, $html)) {
        throw new Exception(t('asset-management.handover.email_failed'));
    }

    echo json_encode(['success' => true, 'sent_to' => $to]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
