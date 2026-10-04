<?php
/**
 * API Endpoint: download the Microsoft Teams app package for one Teams channel.
 *
 *   GET /api/messaging/teams_package.php?id=<channel id>
 *
 * Same access rule as the channel settings it is linked from: the Tickets
 * messaging capability, and a channel this analyst may administer.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/rbac.php';
require_once '../../includes/encryption.php';
require_once '../../includes/messaging/messaging.php';
require_once '../../includes/messaging/TeamsPackage.php';
require_once '../../includes/tenancy.php';

function teamsPackageFail(string $message): void
{
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => $message]);
    exit;
}

if (!isset($_SESSION['analyst_id'])) {
    teamsPackageFail('Not authenticated');
}
requireModuleAccessJson('tickets');
requireCapabilityJson(Cap::TICKETS_MESSAGING);

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    teamsPackageFail('Channel ID is required');
}

try {
    $conn = connectToDatabase();
    if (!analystCanAccessChannel($conn, (int)$_SESSION['analyst_id'], $id)) {
        teamsPackageFail('Channel not found');
    }
    $channel = loadMessagingChannel($conn, $id);
    if (!$channel || ($channel['provider'] ?? '') !== 'teams') {
        teamsPackageFail('Channel not found');
    }

    $tmp = tempnam(sys_get_temp_dir(), 'teams');
    try {
        $fileName = teamsPackageBuild($conn, $channel, $tmp);
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . $fileName . '"');
        header('Content-Length: ' . filesize($tmp));
        header('Cache-Control: no-store');
        readfile($tmp);
    } finally {
        @unlink($tmp);
    }
} catch (Exception $e) {
    teamsPackageFail($e->getMessage());
}
