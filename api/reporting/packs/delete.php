<?php
/**
 * Report Packs: delete a pack and its shares. Owner only.
 * POST {id}
 */
session_start(['read_and_close' => true]);
require_once __DIR__ . '/../../../includes/report_packs/api_common.php';
if (!isset($_SESSION['analyst_id'])) rpFail('Not authenticated', 401);
requireModuleAccessJson('reporting');

try {
    $conn = connectToDatabase();
    rpInitLocale($conn);
    $id = (int)(rpInput()['id'] ?? 0);
    $role = rpRole($conn, (int)$_SESSION['analyst_id'], $id);
    if ($role === null) rpFail(t('reporting.packs.err.not_found'), 404);
    if ($role !== 'owner') rpFail(t('reporting.packs.err.owner_only'), 403);
    // The shares go with it (ON DELETE CASCADE).
    $conn->prepare("DELETE FROM report_packs WHERE id = ?")->execute([$id]);
    rpOut(['success' => true]);
} catch (Throwable $e) {
    error_log('report packs delete: ' . $e->getMessage());
    rpFail(t('reporting.packs.err.save'), 500);
}
