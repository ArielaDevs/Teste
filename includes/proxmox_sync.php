<?php
/**
 * Proxmox VE sync — pulls VMs, LXC containers, nodes, IPs and MACs from one
 * Proxmox server into proxmox_vms / proxmox_nodes. Several servers are supported:
 * each proxmox_connections row is synced on its own, and its rows never mix with
 * another server's (every row is keyed by connection_id).
 *
 * Rules carried over from the NetBox-based sync this replaces (PR #167), kept
 * because each one answers a real incident:
 *   - a VM's identity is (connection, vmid). Renaming a VM updates it, never duplicates it.
 *   - IPs for a QEMU VM come only from the QEMU guest agent, and only for interfaces
 *     whose MAC is one of the VM's own NICs (so docker0 and veth* are skipped).
 *   - IPs for an LXC container come from ip= on its netN lines; ip=dhcp is skipped.
 *   - a node that did not answer this cycle is not "removed": its VMs are left alone.
 *   - a VM is removed only from a LIST THAT CAME BACK - the qemu or lxc list of
 *     one node. See the TRAP in proxmoxSyncConnection().
 *   - SAFETY GUARD: if fewer than half of the known VMs were seen this cycle, nothing
 *     is deleted. A mass deletion has to be confirmed by a healthy cycle, never by a
 *     cycle where requests failed.
 *
 * Every request goes through HypervisorHttp (https only, the shared CA bundle).
 * Read-only: nothing here ever changes anything in Proxmox.
 */

require_once __DIR__ . '/encryption.php';
require_once __DIR__ . '/hypervisor_http.php';

const PROXMOX_MIN_VMS_FOR_GUARD = 5;
const PROXMOX_MIN_SEEN_RATIO    = 0.5;

/** Decrypted connection row, or throws. Never returns the password to a caller that will display it. */
function proxmoxLoadConnection(PDO $conn, int $connectionId): array
{
    $stmt = $conn->prepare("SELECT * FROM proxmox_connections WHERE id = ?");
    $stmt->execute([$connectionId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        throw new Exception('Proxmox server not found');
    }
    $row['password'] = $row['password_enc'] ? decryptValue($row['password_enc']) : '';
    return $row;
}

/**
 * A username containing "!" is an API token: "user@realm!tokenid", with the token's
 * secret typed into the password field. Token auth needs no login and no CSRF token.
 */
function proxmoxIsApiToken(array $c): bool
{
    return strpos((string)($c['username'] ?? ''), '!') !== false;
}

/** Authentication for this server: an API token header, or a login ticket for a user. */
function proxmoxLogin(array $c): array
{
    if (proxmoxIsApiToken($c)) {
        return ['header' => 'Authorization: PVEAPIToken=' . $c['username'] . '=' . $c['password']];
    }
    $resp = proxmoxCall($c, 'POST', '/api2/json/access/ticket', [
        'username' => $c['username'],
        'password' => $c['password'],
    ]);
    $data = $resp['data'] ?? [];
    if (empty($data['ticket'])) {
        throw new Exception('Proxmox did not accept the username or password.');
    }
    return ['ticket' => $data['ticket'], 'csrf' => $data['CSRFPreventionToken'] ?? ''];
}

/** One API call. $auth is null for the login call itself. Throws with a readable message. */
function proxmoxCall(array $c, string $method, string $path, ?array $form = null, ?array $auth = null): array
{
    $headers = [];
    if ($auth && isset($auth['header'])) {
        $headers[] = $auth['header'];
    } elseif ($auth) {
        $headers[] = 'Cookie: PVEAuthCookie=' . $auth['ticket'];
        if ($method !== 'GET') {
            $headers[] = 'CSRFPreventionToken: ' . $auth['csrf'];
        }
    }
    if ($form !== null) {
        $headers[] = 'Content-Type: application/x-www-form-urlencoded';
    }
    try {
        [$code, $body] = HypervisorHttp::request(
            $method,
            rtrim((string)$c['host'], '/') . $path,
            $headers,
            $form !== null ? http_build_query($form) : null,
            !empty($c['verify_ssl'])
        );
    } catch (Exception $e) {
        throw new Exception('Could not reach the Proxmox server: ' . $e->getMessage());
    }
    $json = json_decode((string)$body, true);
    if ($code === 401 || $code === 403) {
        throw new Exception('Proxmox refused this request (HTTP ' . $code . '). Check the user and its permissions.');
    }
    if ($code < 200 || $code >= 300) {
        throw new Exception('Proxmox answered HTTP ' . $code . ($json['message'] ?? '' ? ': ' . $json['message'] : ''));
    }
    return is_array($json) ? $json : [];
}

/** GET a resource and return its data, or null if this node/VM cannot answer (not an error for the whole sync). */
function proxmoxTryGet(array $c, array $auth, string $path)
{
    try {
        return proxmoxCall($c, 'GET', '/api2/json' . $path, null, $auth)['data'] ?? null;
    } catch (Exception $e) {
        return null;
    }
}

/** Test the connection without writing anything: login, then read the node list. */
function proxmoxTestConnection(array $c): string
{
    $auth = proxmoxLogin($c);
    // TRAP: proxmoxCall, not proxmoxTryGet - the real reason must reach the
    //   admin. With a token there is no separate login call, so the node list is
    //   the first request; swallowing its error turned "could not reach the
    //   server" into "check the user's permissions" (found at merge, by Testing
    //   a server that does not exist).
    $nodes = proxmoxCall($c, 'GET', '/api2/json/nodes', null, $auth)['data'] ?? null;
    if (!is_array($nodes)) {
        throw new Exception('Connected, but the node list could not be read. Check the user\'s permissions (Sys.Audit on /).');
    }
    return 'Connected. ' . count($nodes) . ' node(s) visible.';
}

/**
 * Sync one server. Returns a summary and records it on the connection row.
 * Never throws for a per-VM or per-node problem: those are counted and reported.
 */
function proxmoxSyncConnection(PDO $conn, int $connectionId): array
{
    $c = proxmoxLoadConnection($conn, $connectionId);
    $summary = ['status' => 'ok', 'message' => '', 'vms' => 0, 'nodes' => 0, 'removed' => 0, 'skipped_nodes' => [], 'guard' => false];
    try {
        $auth = proxmoxLogin($c);
        $nodeList = proxmoxCall($c, 'GET', '/api2/json/nodes', null, $auth)['data'] ?? null;   // its error is the message
        if (!is_array($nodeList)) {
            throw new Exception('The node list could not be read.');
        }

        $clusterName = '';
        $cluster = proxmoxTryGet($c, $auth, '/cluster/status');
        foreach (is_array($cluster) ? $cluster : [] as $entry) {
            if (($entry['type'] ?? '') === 'cluster') {
                $clusterName = (string)($entry['name'] ?? '');
            }
        }

        $readLists = [];   // "node|qemu" or "node|lxc" => true, for each list that came back
        $seen = [];   // vmid => true, VMs found this cycle on nodes that answered
        $pending = [];

        foreach ($nodeList as $node) {
            $nodeName = (string)($node['node'] ?? '');
            if ($nodeName === '') {
                continue;
            }
            $online = (($node['status'] ?? '') === 'online');
            $cname  = $clusterName !== '' ? $clusterName : $nodeName;
            $conn->prepare(
                "INSERT INTO proxmox_nodes (connection_id, node_name, cluster_name, status, cpu_cores, memory_mb, last_seen_datetime)
                 VALUES (?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())
                 ON DUPLICATE KEY UPDATE cluster_name = VALUES(cluster_name), status = VALUES(status),
                    cpu_cores = VALUES(cpu_cores), memory_mb = VALUES(memory_mb), last_seen_datetime = VALUES(last_seen_datetime)"
            )->execute([$connectionId, $nodeName, $cname, (string)($node['status'] ?? ''),
                (int)($node['maxcpu'] ?? 0) ?: null, isset($node['maxmem']) ? (int)round($node['maxmem'] / 1048576) : null]);
            $summary['nodes']++;

            if (!$online) {
                $summary['skipped_nodes'][] = $nodeName;   // offline: its VMs are not "removed", just unknown
                continue;
            }
            foreach (['qemu' => 'qemu', 'lxc' => 'lxc'] as $type => $segment) {
                $list = proxmoxTryGet($c, $auth, '/nodes/' . rawurlencode($nodeName) . '/' . $segment);
                if (!is_array($list)) {
                    $summary['skipped_nodes'][] = $nodeName . '/' . $segment;
                    continue;
                }
                $readLists[$nodeName . '|' . $type] = true;
                foreach ($list as $vm) {
                    $vmid = (int)($vm['vmid'] ?? 0);
                    if ($vmid <= 0) {
                        continue;
                    }
                    $seen[$vmid] = true;
                    $pending[] = proxmoxVmDetails($c, $auth, $type, $nodeName, $vmid, $vm, $cname);
                }
            }
        }

        // Write every VM we saw.
        $upsert = $conn->prepare(
            "INSERT INTO proxmox_vms (connection_id, vmid, vm_type, node_name, cluster_name, name, status, vcpus, memory_mb,
                disk_gb, os_type, ip_addresses, mac_addresses, details_json, last_seen_datetime)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE vm_type = VALUES(vm_type), node_name = VALUES(node_name), cluster_name = VALUES(cluster_name),
                name = VALUES(name), status = VALUES(status), vcpus = VALUES(vcpus), memory_mb = VALUES(memory_mb),
                disk_gb = VALUES(disk_gb), os_type = VALUES(os_type), ip_addresses = VALUES(ip_addresses),
                mac_addresses = VALUES(mac_addresses), details_json = VALUES(details_json), last_seen_datetime = VALUES(last_seen_datetime)"
        );
        foreach ($pending as $v) {
            $upsert->execute([$connectionId, $v['vmid'], $v['vm_type'], $v['node_name'], $v['cluster_name'], $v['name'],
                $v['status'], $v['vcpus'], $v['memory_mb'], $v['disk_gb'], $v['os_type'], $v['ip_addresses'], $v['mac_addresses'], $v['details_json']]);
            $summary['vms']++;
        }

        // Remove VMs that really are gone, and only if the guard passes.
        //
        // TRAP: "gone" means missing from a LIST THAT CAME BACK - one node's qemu
        //   list or its lxc list - never merely "on a node that answered". The PR
        //   scoped removal by node, so a node that was online but whose qemu list
        //   failed (a timeout, a permission on that node) still counted as read,
        //   and every VM on it was deleted. The guard did not catch it either: it
        //   compared all VMs seen across the cluster with the known ones, so one
        //   small node failing in a big cluster stayed under the 50% line.
        //   The rows are upserted above first, so a VM that moved to another
        //   node is already recorded there before this looks.
        if ($readLists) {
            $stmt = $conn->prepare("SELECT vmid, node_name, vm_type FROM proxmox_vms WHERE connection_id = ?");
            $stmt->execute([$connectionId]);
            $known = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                if (isset($readLists[$row['node_name'] . '|' . $row['vm_type']])) {
                    $known[] = (int)$row['vmid'];
                }
            }
            $stale = array_values(array_diff($known, array_keys($seen)));
            $knownCount = count($known);
            $seenCount  = $knownCount - count($stale);   // of the ones we could check, how many are still there
            $guardFires = $knownCount >= PROXMOX_MIN_VMS_FOR_GUARD && $seenCount < $knownCount * PROXMOX_MIN_SEEN_RATIO;
            if ($guardFires) {
                $summary['guard'] = true;
                $summary['status'] = 'warning';
                $summary['message'] = 'SAFETY GUARD: only ' . $seenCount . ' of ' . $knownCount . ' known VMs were seen; nothing was deleted. Check the Proxmox user and node availability.';
            } elseif ($stale) {
                $placeholders = implode(',', array_fill(0, count($stale), '?'));
                $del = $conn->prepare("DELETE FROM proxmox_vms WHERE connection_id = ? AND vmid IN ($placeholders)");
                $del->execute(array_merge([$connectionId], $stale));
                $summary['removed'] = $del->rowCount();
            }
        }

        if ($summary['status'] === 'ok' && $summary['skipped_nodes']) {
            $summary['status'] = 'warning';
            $summary['message'] = 'Synced, but these were not reachable this cycle: ' . implode(', ', $summary['skipped_nodes']);
        }
        if ($summary['message'] === '') {
            $summary['message'] = $summary['vms'] . ' VMs and containers, ' . $summary['nodes'] . ' node(s).'
                . ($summary['removed'] ? ' Removed ' . $summary['removed'] . ' that no longer exist.' : '');
        }
    } catch (Exception $e) {
        $summary['status'] = 'error';
        $summary['message'] = $e->getMessage();
    }

    $conn->prepare(
        "UPDATE proxmox_connections SET last_sync_datetime = UTC_TIMESTAMP(), last_sync_status = ?, last_sync_message = ? WHERE id = ?"
    )->execute([$summary['status'], $summary['message'], $connectionId]);

    return $summary;
}

/** Everything we keep about one VM, from its list entry plus its config (and guest agent for IPs). */
function proxmoxVmDetails(array $c, array $auth, string $type, string $node, int $vmid, array $listEntry, string $clusterName): array
{
    $base = '/nodes/' . rawurlencode($node) . '/' . $type . '/' . $vmid;
    $cfg = proxmoxTryGet($c, $auth, $base . '/config');
    $cfg = is_array($cfg) ? $cfg : [];

    $macs = [];
    foreach ($cfg as $key => $value) {
        if (preg_match('/^net\d+$/', (string)$key) && preg_match('/([0-9A-Fa-f]{2}(?::[0-9A-Fa-f]{2}){5})/', (string)$value, $m)) {
            $macs[] = strtolower($m[1]);
        }
    }

    $ips = [];
    $guestIfaces = [];   // every non-loopback interface the guest agent reports, for the detail view
    if ($type === 'qemu') {
        if (($listEntry['status'] ?? '') === 'running') {
            $agent = proxmoxTryGet($c, $auth, $base . '/agent/network-get-interfaces');
            foreach (is_array($agent['result'] ?? null) ? $agent['result'] : [] as $iface) {
                if (($iface['name'] ?? '') !== 'lo') {
                    $guestIfaces[] = [
                        'name' => (string)($iface['name'] ?? ''),
                        'mac_address' => (string)($iface['hardware-address'] ?? ''),
                        'ip' => ['ip_addresses' => array_values(array_map(function ($a) {
                            return ['ip_address' => (string)($a['ip-address'] ?? ''), 'ip_address_type' => (string)($a['ip-address-type'] ?? '')];
                        }, is_array($iface['ip-addresses'] ?? null) ? $iface['ip-addresses'] : []))],
                    ];
                }
                $mac = strtolower((string)($iface['hardware-address'] ?? ''));
                if ($mac === '' || !in_array($mac, $macs, true)) {
                    continue;   // docker0, veth*, br-*: not one of this VM's NICs
                }
                foreach (($iface['ip-addresses'] ?? []) as $addr) {
                    $ip = (string)($addr['ip-address'] ?? '');
                    if (($addr['ip-address-type'] ?? '') === 'ipv4' && $ip !== '' && strpos($ip, '127.') !== 0) {
                        $ips[] = $ip;
                    }
                }
            }
        }
    } else {
        foreach ($cfg as $key => $value) {
            if (preg_match('/^net\d+$/', (string)$key) && preg_match('/(?:^|,)ip=(\d+\.\d+\.\d+\.\d+)\//', (string)$value, $m)) {
                $ips[] = $m[1];   // ip=dhcp has no address here; a DHCP lease is correlated elsewhere
            }
        }
    }

    $vcpus = $type === 'qemu'
        ? max(1, (int)($cfg['cores'] ?? 1)) * max(1, (int)($cfg['sockets'] ?? 1))
        : max(1, (int)($cfg['cores'] ?? 1));
    $memMb = isset($cfg['memory']) ? (int)$cfg['memory'] : (isset($listEntry['maxmem']) ? (int)round($listEntry['maxmem'] / 1048576) : null);

    return [
        'vmid'         => $vmid,
        'vm_type'      => $type,
        'node_name'    => $node,
        'cluster_name' => $clusterName,
        'name'         => (string)($listEntry['name'] ?? $cfg['name'] ?? $cfg['hostname'] ?? ('vm-' . $vmid)),
        'status'       => (string)($listEntry['status'] ?? ''),
        'vcpus'        => $vcpus,
        'memory_mb'    => $memMb,
        'disk_gb'      => proxmoxDiskGb($cfg, $type),
        'os_type'      => (string)($cfg['ostype'] ?? ''),
        'ip_addresses' => $ips ? implode(', ', array_values(array_unique($ips))) : null,
        'mac_addresses'=> $macs ? implode(', ', array_values(array_unique($macs))) : null,
        'details_json' => json_encode(proxmoxVmDetailBlock($cfg, $type, $guestIfaces), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
    ];
}

/**
 * The disks, adapters and guest interfaces in the shape the asset detail view reads
 * (the same shape the vCenter sync stores as raw_data), so Proxmox VMs show the same
 * sections without any special casing in the page.
 */
function proxmoxVmDetailBlock(array $cfg, string $type, array $guestIfaces): array
{
    $disks = [];
    $nics = [];
    foreach ($cfg as $key => $value) {
        if (!is_scalar($value)) {
            continue;   // a few config keys come back as arrays; they are not disks or NICs
        }
        $key = (string)$key;
        $value = (string)$value;
        $isDisk = $type === 'qemu'
            ? (bool)preg_match('/^(scsi|virtio|ide|sata)\d+$/', $key)
            : ($key === 'rootfs' || (bool)preg_match('/^mp\d+$/', $key));
        if ($isDisk && strpos($value, 'media=cdrom') === false && preg_match('/size=(\d+(?:\.\d+)?)([KMGT]?)/i', $value, $m)) {
            $gb = proxmoxSizeToGb((float)$m[1], strtoupper($m[2]));
            $disks[$key] = [
                'label'    => $key,
                'capacity' => (int)round($gb * 1073741824),
                'type'     => preg_match('/^(scsi|virtio|ide|sata)/', $key, $t) ? $t[1] : 'rootfs',
                'backing'  => ['type' => explode(':', $value)[0]],
            ];
        }
        if (preg_match('/^net\d+$/', $key)) {
            $model = $type === 'qemu' && preg_match('/^(virtio|e1000e?|vmxnet3|rtl8139)=/i', $value, $mm)
                ? strtolower($mm[1]) : ($type === 'lxc' ? 'veth' : 'unknown');
            $mac = preg_match('/([0-9A-Fa-f]{2}(?::[0-9A-Fa-f]{2}){5})/', $value, $mc) ? strtolower($mc[1]) : '';
            $bridge = preg_match('/bridge=([^,]+)/', $value, $bm) ? $bm[1] : '';
            $nics[$key] = [
                'label'       => $key,
                'type'        => $model,
                'mac_address' => $mac,
                'state'       => strpos($value, 'link_down=1') !== false ? 'disconnected' : 'connected',
                'backing'     => ['network_name' => $bridge],
            ];
        }
    }
    return [
        'vm_detail'        => ['disks' => $disks, 'nics' => $nics],
        'guest_networking' => $guestIfaces,
    ];
}

/** Proxmox size suffix to GB: K, M, G, T (no suffix means GB). */
function proxmoxSizeToGb(float $n, string $unit): float
{
    return $unit === 'T' ? $n * 1024 : ($unit === 'M' ? $n / 1024 : ($unit === 'K' ? $n / 1048576 : $n));
}

/** Provisioned disk size in GB, from the config keys (not live usage). */
function proxmoxDiskGb(array $cfg, string $type): ?float
{
    $total = 0.0;
    $found = false;
    foreach ($cfg as $key => $value) {
        $isDisk = $type === 'qemu'
            ? (bool)preg_match('/^(scsi|virtio|ide|sata)\d+$/', (string)$key)
            : ((string)$key === 'rootfs' || (bool)preg_match('/^mp\d+$/', (string)$key));
        if (!$isDisk || strpos((string)$value, 'media=cdrom') !== false) {
            continue;
        }
        if (preg_match('/size=(\d+(?:\.\d+)?)([KMGT]?)/i', (string)$value, $m)) {
            $found = true;
            $n = (float)$m[1];
            $unit = strtoupper($m[2]);
            $total += $unit === 'T' ? $n * 1024 : ($unit === 'M' ? $n / 1024 : ($unit === 'K' ? $n / 1048576 : $n));
        }
    }
    return $found ? round($total, 2) : null;
}

/**
 * Save a server from the settings form. Returns its id.
 *
 * Moved here from api/assets/proxmox_connections.php at merge time so that the
 * rules are in one place, and tests/hypervisor-sync.php runs the real ones.
 *
 * TRAP: the password field holds the API token SECRET (or the user's password).
 *   It is stored only through encryptValue(), never returned to the browser
 *   (proxmoxConnectionOut() says has_password and nothing more), and a blank or
 *   all-asterisks value on edit keeps the stored one.
 */
function proxmoxSaveConnection(PDO $conn, array $in): int
{
    $id       = (int)($in['id'] ?? 0);
    $name     = trim((string)($in['name'] ?? ''));
    $username = trim((string)($in['username'] ?? ''));
    $password = (string)($in['password'] ?? '');
    $verify   = !empty($in['verify_ssl']) ? 1 : 0;
    $active   = !empty($in['is_active']) ? 1 : 0;
    $interval = max(5, min(10080, (int)($in['sync_interval_minutes'] ?? 60)));

    if ($name === '' || mb_strlen($name) > 100) {
        throw new Exception('Give the server a name (up to 100 characters).');
    }
    $host = HypervisorHttp::validateHost((string)($in['host'] ?? ''), 'https://pve.example.com:8006');
    if ($username === '' || mb_strlen($username) > 100) {
        throw new Exception('A Proxmox user or API token is required, for example monitor@pve or monitor@pve!freeitsm.');
    }
    if (strpos($username, '!') !== false && !preg_match('/^[^\s@!]+@[^\s!]+![^\s!]+$/', $username)) {
        throw new Exception('An API token ID must look like monitor@pve!freeitsm.');
    }
    $current = null;
    if ($id > 0) {
        $exists = $conn->prepare("SELECT password_enc FROM proxmox_connections WHERE id = ?");
        $exists->execute([$id]);
        $current = $exists->fetch(PDO::FETCH_ASSOC);
        if (!$current) {
            throw new Exception('Proxmox server not found');
        }
    }
    $keep = ($password === '' || preg_match('/^\*+$/', $password));
    if ($id === 0 && $keep) {
        throw new Exception('A password or API token secret is required for a new server.');
    }
    $passwordEnc = $keep ? ($current['password_enc'] ?? null) : encryptValue($password);

    if ($id > 0) {
        $conn->prepare(
            "UPDATE proxmox_connections SET name = ?, host = ?, username = ?, password_enc = ?, verify_ssl = ?,
                is_active = ?, sync_interval_minutes = ? WHERE id = ?"
        )->execute([$name, $host, $username, $passwordEnc, $verify, $active, $interval, $id]);
        return $id;
    }
    $conn->prepare(
        "INSERT INTO proxmox_connections (name, host, username, password_enc, verify_ssl, is_active, sync_interval_minutes)
         VALUES (?, ?, ?, ?, ?, ?, ?)"
    )->execute([$name, $host, $username, $passwordEnc, $verify, $active, $interval]);
    return (int)$conn->lastInsertId();
}

/** A server as the browser may see it: never the password, only whether there is one. */
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
