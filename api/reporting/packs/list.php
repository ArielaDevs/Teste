<?php
/**
 * Report Packs: every pack the signed-in analyst can open, with their role on it.
 * GET
 */
session_start(['read_and_close' => true]);
require_once __DIR__ . '/../../../includes/report_packs/api_common.php';
if (!isset($_SESSION['analyst_id'])) rpFail('Not authenticated', 401);
requireModuleAccessJson('reporting');

try {
    $conn = connectToDatabase();
    rpInitLocale($conn);
    $packs = rpListPacks($conn, (int)$_SESSION['analyst_id']);
    foreach ($packs as &$p) {
        $p['updated_display'] = rpFmtDateTime($p['updated']);
    }
    unset($p);
    rpOut(['success' => true, 'packs' => $packs]);
} catch (Throwable $e) {
    error_log('report packs list: ' . $e->getMessage());
    rpFail(t('reporting.packs.err.load'), 500);
}
