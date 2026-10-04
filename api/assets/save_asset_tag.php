<?php
/**
 * API: set an asset's human-readable tag — the number printed on its label.
 *
 * POST { asset_id, asset_tag } -> { success, asset_tag }
 *
 * WHY THIS ISN'T update_asset_field.php
 * -------------------------------------
 * That endpoint is a generic single-field writer over a whitelist, and this
 * field has a rule none of the others have: the tag must be unique WITHIN THE
 * COMPANY that owns the asset. Two companies on one install may each run their
 * own LT0001, so the check needs the asset's tenant — which a generic setter
 * has no reason to know about.
 *
 * The check is here, in code, and NOT a unique index: `UNIQUE (tenant_id,
 * asset_tag)` would not hold for the Default company, because MySQL treats
 * NULLs as distinct in a unique index — two default assets could both be
 * LT0001 while the index looked like it was guarding them. The same reasoning
 * already governs hostname uniqueness in this schema.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/rbac.php';
require_once '../../includes/tenancy.php';
require_once '../../includes/asset_labels.php';
require_once '../../includes/service_context.php';
require_once '../../includes/services/asset_tags.php';

header('Content-Type: application/json');
if (!isset($_SESSION['analyst_id'])) { echo json_encode(['success' => false, 'error' => 'Not authenticated']); exit; }
requireModuleAccessJson('assets');

try {
    $data    = json_decode(file_get_contents('php://input'), true) ?: [];
    $assetId = (int)($data['asset_id'] ?? 0);
    $tag     = trim((string)($data['asset_tag'] ?? ''));

    if ($assetId <= 0) throw new Exception('asset_id is required');
    if (mb_strlen($tag) > 64) throw new Exception('An asset tag can be at most 64 characters');

    $conn = connectToDatabase();
    $analystId = (int)$_SESSION['analyst_id'];

    if (!assetLabelsSchemaReady($conn)) {
        throw new Exception('Asset tags need a database update — run System → Database Verification.');
    }
    if (!analystCanAccessAsset($conn, $analystId, $assetId)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Asset not found']);
        exit;
    }

    // The check, the write and the history row are AssetTagsService::assign()'s
    // (PR #164): the same per-company lock as a tag typed in on create or
    // generated, so two paths can never hand out the same tag.
    $res = AssetTagsService::assign($conn, ActorContext::fromSession($conn), $assetId, $tag);
    echo json_encode(['success' => true, 'asset_tag' => $res['asset_tag']] + ($res['unchanged'] ? ['unchanged' => true] : []));
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
