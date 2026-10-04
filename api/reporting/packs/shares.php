<?php
/**
 * Report Packs: who a pack is shared with. Owner only.
 * GET  ?id=N           the shares, plus the analysts, teams and departments to pick from
 * POST {id, shares}    replace the shares with this list
 */
session_start(['read_and_close' => true]);
require_once __DIR__ . '/../../../includes/report_packs/api_common.php';
if (!isset($_SESSION['analyst_id'])) rpFail('Not authenticated', 401);
requireModuleAccessJson('reporting');

try {
    $conn = connectToDatabase();
    rpInitLocale($conn);
    $aid  = (int)$_SESSION['analyst_id'];
    $post = $_SERVER['REQUEST_METHOD'] === 'POST';
    $in   = $post ? rpInput() : [];
    $id   = (int)($post ? ($in['id'] ?? 0) : ($_GET['id'] ?? 0));

    $role = rpRole($conn, $aid, $id);
    if ($role === null) rpFail(t('reporting.packs.err.not_found'), 404);
    if ($role !== 'owner') rpFail(t('reporting.packs.err.owner_only'), 403);

    if ($post) {
        $owner = $conn->prepare("SELECT owner_id FROM report_packs WHERE id = ?");
        $owner->execute([$id]);
        rpSaveShares($conn, $id, (int)$owner->fetchColumn(), is_array($in['shares'] ?? null) ? $in['shares'] : []);
        rpOut(['success' => true, 'shares' => rpListShares($conn, $id)]);
    }

    // People to share with: active analysts other than me, and every active team.
    $analysts = $conn->prepare("SELECT id, full_name, department FROM analysts WHERE is_active = 1 AND id <> ? ORDER BY full_name");
    $analysts->execute([$aid]);
    $teams = $conn->query("SELECT id, name FROM teams WHERE is_active = 1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
    rpOut([
        'success'     => true,
        'shares'      => rpListShares($conn, $id),
        'analysts'    => array_map(fn($r) => ['id' => (int)$r['id'], 'name' => $r['full_name'], 'department' => $r['department']],
                                   $analysts->fetchAll(PDO::FETCH_ASSOC)),
        'teams'       => array_map(fn($r) => ['id' => (int)$r['id'], 'name' => $r['name']], $teams),
        'departments' => rpKnownDepartments($conn),
    ]);
} catch (Throwable $e) {
    error_log('report packs shares: ' . $e->getMessage());
    rpFail(t('reporting.packs.err.save'), 500);
}
