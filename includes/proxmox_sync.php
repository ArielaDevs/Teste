<?php
/**
 * Proxmox VE sync — pulls VMs, LXC containers, nodes, IPs and MACs from one
 * Proxmox server into proxmox_vms / proxmox_nodes. Several servers are supported:
 * each proxmox_connections row is synced on its own, and its rows never mix with
 * another server's (every row is keyed by connection_id).
 *
 * Rules carried over from the NetBox-based sync this replaces (see
 * ~/Desktop/proxmox-vcloud/SKILL.md), kept because each one answers a real incident:
 *   - a VM's identity is (connection, vmid). Renaming a VM updates it, never duplicates it.
 *   - IPs for a QEMU VM come only from the QEMU guest agent, and only for interfaces
 *     whose MAC is one of the VM's own NICs (so docker0 and veth* are skipped).
 *   - IPs for an LXC container come from ip= on its netN lines; ip=dhcp is skipped.
 *   - a node that did not answer this cycle is not "removed": its VMs are left alone.
 *   - SAFETY GUARD: if fewer than half of the known VMs were seen this cycle, nothing
 *     is deleted. A mass deletion has to be confirmed by a healthy cycle, never by a
 *     cycle where requests failed.
 */

require_once __DIR__ . '/encryption.php';

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
    $base = rtrim((string)$c['host'], '/');
    if (!preg_match('#^https?://#i', $base)) {
        throw new Exception('The server address must start with https://');
    }
    $ch = curl_init($base . $path);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    $verify = !empty($c['verify_ssl']);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, $verify);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, $verify ? 2 : 0);
    $headers = [];
    if ($auth && isset($auth['header'])) {
        $headers[] = $auth['header'];
    } elseif ($auth) {
        curl_setopt($ch, CURLOPT_COOKIE, 'PVEAuthCookie=' . $auth['ticket']);
        if ($method !== 'GET') {
            $headers[] = 'CSRFPreventionToken: ' . $auth['csrf'];
        }
    }
    if ($form !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($form));
    }
    if ($headers) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    }
    $body = curl_exec($ch);
    if ($body === false) {
        $err = curl_error($ch);
        curl_close($ch);
        throw new Exception('Could not reach the Proxmox server: ' . $err);
    }
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
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
    $nodes = proxmoxTryGet($c, $auth, '/nodes');
    if (!is_array($nodes)) {
        throw new Exception('Logged in, but the node list could not be read. Check the user\'s permissions (Sys.Audit on /).');
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
        $nodeList = proxmoxTryGet($c, $auth, '/nodes');
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

        $scannedNodes = [];
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
            $scannedNodes[] = $nodeName;

            foreach (['qemu' => 'qemu', 'lxc' => 'lxc'] as $type => $segment) {
                $list = proxmoxTryGet($c, $auth, '/nodes/' . rawurlencode($nodeName) . '/' . $segment);
                if (!is_array($list)) {
                    $summary['skipped_nodes'][] = $nodeName . '/' . $segment;
                    continue;
                }
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

        // Remove VMs that really are gone: only on nodes that answered, and only if the guard passes.
        if ($scannedNodes) {
            $in = implode(',', array_fill(0, count($scannedNodes), '?'));
            $stmt = $conn->prepare("SELECT vmid FROM proxmox_vms WHERE connection_id = ? AND node_name IN ($in)");
            $stmt->execute(array_merge([$connectionId], $scannedNodes));
            $known = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
            $stale = array_values(array_diff($known, array_keys($seen)));
            $knownCount = count($known);
            $seenCount  = count($seen);
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
