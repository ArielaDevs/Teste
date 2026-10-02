<?php
/**
 * Report Packs: one pack's design, for the designer or for export.
 * GET ?id=N
 */
session_start(['read_and_close' => true]);
require_once __DIR__ . '/../../../includes/report_packs/api_common.php';
if (!isset($_SESSION['analyst_id'])) rpFail('Not authenticated', 401);
requireModuleAccessJson('reporting');

try {
    $conn = connectToDatabase();
    rpInitLocale($conn);
    $id = (int)($_GET['id'] ?? 0);
    $role = rpRole($conn, (int)$_SESSION['analyst_id'], $id);
    if ($role === null) rpFail(t('reporting.packs.err.not_found'), 404);

    $s = $conn->prepare("SELECT p.id, p.name, p.description, p.design, p.updated_datetime, p.created_datetime,
                                o.full_name AS owner_name
                           FROM report_packs p LEFT JOIN analysts o ON o.id = p.owner_id WHERE p.id = ?");
    $s->execute([$id]);
    $p = $s->fetch(PDO::FETCH_ASSOC);
    $design = json_decode((string)$p['design'], true);
    // Checked on the way OUT as well: a design written by an older version, or
    // edited in the database, is brought into today's shape before the designer
    // or the PDF engine ever sees it.
    $design = rpCleanDesign(is_array($design) ? $design : rpDefaultDesign($p['name']), $p['name']);

    rpOut(['success' => true, 'pack' => [
        'id'          => (int)$p['id'],
        'name'        => $p['name'],
        'description' => $p['description'],
        'owner_name'  => $p['owner_name'],
        'role'        => $role,
        'updated'     => $p['updated_datetime'] ?: $p['created_datetime'],
        'design'      => $design,
    ]]);
} catch (Throwable $e) {
    error_log('report packs get: ' . $e->getMessage());
    rpFail(t('reporting.packs.err.load'), 500);
}
