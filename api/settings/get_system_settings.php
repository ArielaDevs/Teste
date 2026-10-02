<?php
/**
 * API Endpoint: Get system settings
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/encryption.php';

header('Content-Type: application/json');

// Check if user is logged in
if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}
// The settings pages of Assets, Software, Tickets and System read through here.
requireAnyModuleAccessJson(['assets', 'software', 'tickets', 'system']);

try {
    $conn = connectToDatabase();

    $sql = "SELECT setting_key, setting_value FROM system_settings";
    $stmt = $conn->prepare($sql);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Convert to key-value object, decrypting sensitive values, then masking
    // any true secrets so plaintext credentials never leave the server.
    $settings = [];
    foreach ($rows as $row) {
        $value = $row['setting_value'];
        // A secret by NAME (*_password, *_secret, *_token, *_api_key) that is not on
        // the mask list is never sent at all. Encryption is rule-based but masking is a
        // list, so without this csat_token_secret and the cron tokens - bearer
        // credentials for endpoints that need no sign-in - went out in plain text.
        // No page reads them from here (checked 2026-10-02).
        if (isSecretSettingName($row['setting_key']) && isEncryptedSettingKey($row['setting_key'])
            && !isMaskedSettingKey($row['setting_key'])) {
            continue;
        }
        if (isEncryptedSettingKey($row['setting_key'])) {
            $value = decryptValue($value);
        }
        if (isMaskedSettingKey($row['setting_key'])) {
            $value = maskSecret($value);
        }
        $settings[$row['setting_key']] = $value;
    }

    echo json_encode(['success' => true, 'settings' => $settings]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

?>
