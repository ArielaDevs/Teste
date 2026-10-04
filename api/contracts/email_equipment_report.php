<?php
/**
 * API: email the equipment report for a contract.
 *
 * POST { contract_id: <int>, to?: <string> }
 *
 * Sends through ssSendSystemEmail(), the same sender the portal uses, so it
 * inherits the configured sending mailbox rather than introducing a second idea
 * of how this installation sends mail.
 *
 * ⚠️ The message is built by the SAME renderer as the printable page, with the
 * stylesheet inlined in a <style> block — a mail client will not fetch an
 * external sheet. One layout, three destinations.
 *
 * ⚠️ The equipment list is SCOPED to the sender, not to the recipient. What
 * gets attached is what the person pressing Send can see, which is the only
 * honest reading: they are the one choosing to send it.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/contract_report.php';
require_once '../../includes/self_service_email.php';
require_once '../../includes/i18n.php';
I18n::initFromSession();

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}
requireModuleAccessJson('contracts');

try {
    $in         = json_decode(file_get_contents('php://input'), true) ?: [];
    $contractId = (int)($in['contract_id'] ?? 0);
    if ($contractId <= 0) {
        throw new Exception('contract_id is required');
    }

    $conn     = connectToDatabase();
    $contract = contractReportLoad($conn, $contractId, (int)$_SESSION['analyst_id']);
    if (!$contract) {
        throw new Exception(t('contracts.report.not_found'));
    }

    // Default to the contract owner, because on a report about a contract they
    // are the person who has to act on it. An explicit address wins.
    $to = trim((string)($in['to'] ?? '')) ?: trim((string)($contract['owner_email'] ?? ''));
    if ($to === '') {
        // Said plainly rather than reported as a send failure: there is nothing
        // wrong with the mail setup, there is simply nobody to send it to.
        throw new Exception(t('contracts.report.email_no_address'));
    }
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        throw new Exception(t('contracts.report.email_invalid'));
    }

    $assets = contractAssetsFor($conn, (int)$_SESSION['analyst_id'], $contractId);

    $html = '<!DOCTYPE html><html><head><meta charset="UTF-8"><style>'
          . contractReportCss()
          . '</style></head><body style="margin:0;padding:24px;background:#ffffff;">'
          . '<div style="max-width:820px;margin:0 auto;">' . contractReportBody($contract, $assets) . '</div>'
          . '</body></html>';

    $subject = t('contracts.report.email_subject', [
        'contract' => trim(($contract['contract_number'] ?? '') . ' ' . ($contract['title'] ?? '')),
    ]);

    if (!ssSendSystemEmail($conn, $to, $subject, $html)) {
        throw new Exception(t('contracts.report.email_failed'));
    }

    echo json_encode(['success' => true, 'sent_to' => $to]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
