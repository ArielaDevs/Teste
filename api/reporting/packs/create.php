<?php
/**
 * Report Packs: make a new pack (with the starter design), or a copy of one.
 * POST {name, description?, copy_of?}
 *
 * A copy belongs to whoever made it and is shared with nobody: copying a pack you
 * can only VIEW is how you get one of your own to change.
 */
session_start(['read_and_close' => true]);
require_once __DIR__ . '/../../../includes/report_packs/api_common.php';
if (!isset($_SESSION['analyst_id'])) rpFail('Not authenticated', 401);
requireModuleAccessJson('reporting');

try {
    $conn = connectToDatabase();
    rpInitLocale($conn);
    $aid  = (int)$_SESSION['analyst_id'];
    $in   = rpInput();
    $name = trim(rpStr($in['name'] ?? '', 200));
    if ($name === '') rpFail(t('reporting.packs.err.name_required'));
    $desc = trim(rpStr($in['description'] ?? '', 500));

    $design = rpDefaultDesign($name);
    $copyOf = (int)($in['copy_of'] ?? 0);
    if ($copyOf > 0) {
        if (rpRole($conn, $aid, $copyOf) === null) rpFail(t('reporting.packs.err.not_found'), 404);
        $s = $conn->prepare("SELECT design FROM report_packs WHERE id = ?");
        $s->execute([$copyOf]);
        $src = json_decode((string)$s->fetchColumn(), true);
        if (is_array($src)) $design = $src;
    }
    $design = rpCleanDesign($design, $name);

    $conn->prepare("INSERT INTO report_packs (name, description, owner_id, design, updated_datetime, updated_by)
                    VALUES (?, ?, ?, ?, UTC_TIMESTAMP(), ?)")
         ->execute([$name, $desc !== '' ? $desc : null, $aid, json_encode($design, JSON_UNESCAPED_UNICODE), $aid]);
    rpOut(['success' => true, 'id' => (int)$conn->lastInsertId()]);
} catch (InvalidArgumentException $e) {
    rpFail($e->getMessage());
} catch (Throwable $e) {
    error_log('report packs create: ' . $e->getMessage());
    rpFail(t('reporting.packs.err.save'), 500);
}
