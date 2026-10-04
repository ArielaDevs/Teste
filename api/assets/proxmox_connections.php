<?php
/**
 * API Endpoint: Proxmox VE servers — list, add/edit, delete.
 *   GET                       → every server (no passwords)
 *   POST {action:'save', ...} → create (no id) or update (id); a blank password keeps the stored one
 *   POST {action:'delete', id} → remove a server and its synced VMs/nodes
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/rbac.php';
require_once '../../includes/encryption.php';
require_once '../../includes/proxmox_sync.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}
requireCapabilityJson(Cap::ASSETS_PROXMOX);

function proxmoxConnectionOut(array $r): array
{
    return [
        'id'                    => (int)$r['id'],
        'name'                  => $r['name'],
        'host'                  => $r['host'],
        'username'              => $r['username'],
        'has_password'          => !empty($r['password_enc']),
        'verify_ssl'            => (bool)$r['verify_ssl'],
        'is_active'             => (bool)$r['is_active'],
        'sync_interval_minutes' => (int)$r['sync_interval_minutes'],
        'last_sync_datetime'    => $r['last_sync_datetime'],
        'last_sync_status'      => $r['last_sync_status'],
        'last_sync_message'     => $r['last_sync_message'],
    ];
}

try {
    $conn = connectToDatabase();

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $rows = $conn->query("SELECT * FROM proxmox_connections ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['success' => true, 'connections' => array_map('proxmoxConnectionOut', $rows)]);
        exit;
    }

    $in = json_decode(file_get_contents('php://input'), true);
    if (!is_array($in)) {
        throw new Exception('Invalid request data');
    }
    $action = (string)($in['action'] ?? '');

    if ($action === 'delete') {
        $id = (int)($in['id'] ?? 0);
        if ($id <= 0) {
            throw new Exception('Server ID is required');
        }
        $conn->prepare("DELETE FROM proxmox_connections WHERE id = ?")->execute([$id]);   // nodes and VMs cascade
        echo json_encode(['success' => true, 'message' => 'Server removed']);
        exit;
    }

    if ($action !== 'save') {
        throw new Exception('Unknown action');
    }

    $id       = (int)($in['id'] ?? 0);
    $name     = trim((string)($in['name'] ?? ''));
    $host     = rtrim(trim((string)($in['host'] ?? '')), '/');
    $username = trim((string)($in['username'] ?? ''));
    $password = (string)($in['password'] ?? '');
    $verify   = !empty($in['verify_ssl']) ? 1 : 0;
    $active   = !empty($in['is_active']) ? 1 : 0;
    $interval = max(5, min(10080, (int)($in['sync_interval_minutes'] ?? 60)));

    if ($name === '' || mb_strlen($name) > 100) {
        throw new Exception('Give the server a name (up to 100 characters).');
    }
    if (!preg_match('#^https?://[^\s/]+(:\d+)?$#i', $host)) {
        throw new Exception('The server address must look like https://pve.example.com:8006');
    }
    if ($username === '' || mb_strlen($username) > 100) {
        throw new Exception('A Proxmox user or API token is required, for example monitor@pve or monitor@pve!freeitsm.');
    }
    if (strpos($username, '!') !== false && !preg_match('/^[^\s@!]+@[^\s!]+![^\s!]+$/', $username)) {
        throw new Exception('An API token ID must look like monitor@pve!freeitsm.');
    }
    if ($id === 0 && $password === '') {
        throw new Exception('A password is required for a new server.');
    }

    $passwordEnc = null;
    if ($password !== '' && !preg_match('/^\*+$/', $password)) {
        $passwordEnc = encryptValue($password);
    } elseif ($id > 0) {
        $cur = $conn->prepare("SELECT password_enc FROM proxmox_connections WHERE id = ?");
        $cur->execute([$id]);
        $passwordEnc = $cur->fetchColumn() ?: null;
    }

    if ($id > 0) {
        $conn->prepare(
            "UPDATE proxmox_connections SET name = ?, host = ?, username = ?, password_enc = ?, verify_ssl = ?,
                is_active = ?, sync_interval_minutes = ? WHERE id = ?"
        )->execute([$name, $host, $username, $passwordEnc, $verify, $active, $interval, $id]);
    } else {
        $conn->prepare(
            "INSERT INTO proxmox_connections (name, host, username, password_enc, verify_ssl, is_active, sync_interval_minutes)
             VALUES (?, ?, ?, ?, ?, ?, ?)"
        )->execute([$name, $host, $username, $passwordEnc, $verify, $active, $interval]);
        $id = (int)$conn->lastInsertId();
    }

    echo json_encode(['success' => true, 'id' => $id, 'message' => 'Server saved']);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
