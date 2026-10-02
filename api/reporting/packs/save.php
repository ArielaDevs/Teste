<?php
/**
 * Report Packs: save a pack's name, description and design. Needs Edit or owner.
 * POST {id, name, description?, design, updated?, force?}
 *
 * `updated` is the stamp the designer loaded. If somebody else has saved since,
 * the save is refused with `conflict` rather than silently overwriting their work -
 * a shared pack with two editors open is exactly when that would happen. `force`
 * is the "Save mine anyway" answer to that.
 */
session_start(['read_and_close' => true]);
require_once __DIR__ . '/../../../includes/report_packs/api_common.php';
if (!isset($_SESSION['analyst_id'])) rpFail('Not authenticated', 401);
requireModuleAccessJson('reporting');

try {
    $conn = connectToDatabase();
    rpInitLocale($conn);
    $aid = (int)$_SESSION['analyst_id'];
    $in  = rpInput();
    $id  = (int)($in['id'] ?? 0);
    $role = rpRole($conn, $aid, $id);
    if ($role === null) rpFail(t('reporting.packs.err.not_found'), 404);
    if (!rpRoleAtLeast($role, 'edit')) rpFail(t('reporting.packs.err.view_only'), 403);

    $name = trim(rpStr($in['name'] ?? '', 200));
    if ($name === '') rpFail(t('reporting.packs.err.name_required'));
    $desc = trim(rpStr($in['description'] ?? '', 500));
    $design = rpCleanDesign($in['design'] ?? null, $name);

    $stamp = $conn->prepare("SELECT COALESCE(p.updated_datetime, p.created_datetime) AS stamp, u.full_name
                               FROM report_packs p LEFT JOIN analysts u ON u.id = p.updated_by WHERE p.id = ?");
    $stamp->execute([$id]);
    $cur = $stamp->fetch(PDO::FETCH_ASSOC);
    if (empty($in['force']) && !empty($in['updated']) && $cur && $cur['stamp'] !== $in['updated']) {
        rpOut(['success' => false, 'conflict' => true,
               'error' => t('reporting.packs.err.conflict', ['name' => $cur['full_name'] ?: '?'])], 409);
    }

    $conn->prepare("UPDATE report_packs SET name = ?, description = ?, design = ?, updated_datetime = UTC_TIMESTAMP(), updated_by = ? WHERE id = ?")
         ->execute([$name, $desc !== '' ? $desc : null, json_encode($design, JSON_UNESCAPED_UNICODE), $aid, $id]);
    $stamp->execute([$id]);
    rpOut(['success' => true, 'updated' => $stamp->fetch(PDO::FETCH_ASSOC)['stamp'], 'design' => $design]);
} catch (InvalidArgumentException $e) {
    rpFail($e->getMessage());
} catch (Throwable $e) {
    error_log('report packs save: ' . $e->getMessage());
    rpFail(t('reporting.packs.err.save'), 500);
}
