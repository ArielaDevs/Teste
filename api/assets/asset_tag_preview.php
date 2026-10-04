<?php
/**
 * API: what the next few asset tags would look like under a proposed format,
 * and whether that format is usable. The asset twin of
 * api/tickets/numbering_preview.php.
 *
 * POST { format, start } -> { success, problems: [], examples: [] }
 *
 * 🔑 Writes NOTHING - it never touches a counter, so an administrator can try
 * six formats and the next real tag is still the number they expect.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/rbac.php';
require_once '../../includes/capabilities.php';
require_once '../../includes/services/asset_tags.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}
requireModuleAccessJson('assets');
requireCapabilityJson(Cap::ASSETS_TAGS);

try {
    $data   = json_decode(file_get_contents('php://input'), true) ?: [];
    $format = trim((string)($data['format'] ?? ''));
    $start  = max(1, (int)($data['start'] ?? 1));
    $problems = AssetTagsService::validateFormat($format);
    echo json_encode([
        'success'  => true,
        'problems' => $problems,
        'examples' => $problems ? [] : AssetTagsService::preview(['asset_tag_format' => $format], $start),
    ]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
