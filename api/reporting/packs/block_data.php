<?php
/**
 * Report Packs: one block's data, for the viewer, under the pack's criteria.
 * POST {handler, opts, criteria}
 *
 * Deliberately NOT tied to a pack id. The designer previews blocks before they
 * are saved, and the answer depends only on who is asking: their module access
 * decides whether the block may show at all, and their companies decide what it
 * counts (rpBlockData()). So this can show nothing a dashboard would not.
 */
session_start(['read_and_close' => true]);
require_once __DIR__ . '/../../../includes/report_packs/api_common.php';
if (!isset($_SESSION['analyst_id'])) rpFail('Not authenticated', 401);
requireModuleAccessJson('reporting');

try {
    $conn = connectToDatabase();
    rpInitLocale($conn);
    $in = rpInput();
    $data = rpBlockData($conn, (int)$_SESSION['analyst_id'], (string)($in['handler'] ?? ''),
                        $in['opts'] ?? [], is_array($in['criteria'] ?? null) ? $in['criteria'] : []);
    rpOut(['success' => true, 'data' => $data]);
} catch (RuntimeException $e) {
    // A message meant for the block itself ("You do not have access to ...").
    rpOut(['success' => false, 'error' => $e->getMessage(), 'blocked' => true]);
} catch (Throwable $e) {
    error_log('report packs block_data: ' . $e->getMessage());
    rpFail(t('reporting.packs.err.block'), 500);
}
