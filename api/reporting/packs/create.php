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

    // F12c: a copy inherits the source pack's company (the copier already
    // reaches it through rpRole's tenant gate). A fresh pack stays NULL — a
    // personal draft by design (migration 004) — never the active tenant, so
    // creating a pack cannot silently publish it to a whole company.
    $design = rpDefaultDesign($name);
    $copyOf = (int)($in['copy_of'] ?? 0);
    $packTenantSupported = tenancyColumnExists($conn, 'report_packs', 'tenant_id');
    $packTenantId = null;
    if ($copyOf > 0) {
        if (rpRole($conn, $aid, $copyOf) === null) rpFail(t('reporting.packs.err.not_found'), 404);
        if ($packTenantSupported) {
            $s = $conn->prepare("SELECT design, tenant_id FROM report_packs WHERE id = ?");
            $s->execute([$copyOf]);
            $srcRow = $s->fetch(PDO::FETCH_ASSOC);
            $src = json_decode((string)($srcRow['design'] ?? ''), true);
            if ($srcRow && $srcRow['tenant_id'] !== null) $packTenantId = (int)$srcRow['tenant_id'];
        } else {
            $s = $conn->prepare("SELECT design FROM report_packs WHERE id = ?");
            $s->execute([$copyOf]);
            $src = json_decode((string)$s->fetchColumn(), true);
        }
        if (is_array($src)) $design = $src;
    }
    $design = rpCleanDesign($design, $name);

    if ($packTenantSupported) {
        $conn->prepare("INSERT INTO report_packs (name, description, owner_id, tenant_id, design, updated_datetime, updated_by)
                        VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP(), ?)")
             ->execute([$name, $desc !== '' ? $desc : null, $aid, $packTenantId, json_encode($design, JSON_UNESCAPED_UNICODE), $aid]);
    } else {
        $conn->prepare("INSERT INTO report_packs (name, description, owner_id, design, updated_datetime, updated_by)
                        VALUES (?, ?, ?, ?, UTC_TIMESTAMP(), ?)")
             ->execute([$name, $desc !== '' ? $desc : null, $aid, json_encode($design, JSON_UNESCAPED_UNICODE), $aid]);
    }
    rpOut(['success' => true, 'id' => (int)$conn->lastInsertId()]);
} catch (InvalidArgumentException $e) {
    rpFail($e->getMessage());
} catch (Throwable $e) {
    error_log('report packs create: ' . $e->getMessage());
    rpFail(t('reporting.packs.err.save'), 500);
}
