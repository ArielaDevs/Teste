<?php
/**
 * VMware Cloud Director sync — VMs, their NICs and disks, and edge gateways, from
 * one vCD server into vcloud_vms / vcloud_edge_gateways. Several servers are
 * supported: every row is keyed by connection_id.
 *
 * API use, and why:
 *   - cloudapi (JSON) for the session and the edge gateways;
 *   - the legacy /api (XML) for the VM inventory (/api/query?type=vm), and each
 *     VM's NICs and disks. The XML is read by local element names, so it does not
 *     depend on one namespace URI (the namespace differs between provider and
 *     tenant org responses).
 *
 * Rules, the same as the Proxmox sync:
 *   - a VM's identity is (connection, VM uuid), which survives renames and vApp moves.
 *   - a VM is removed only after a COMPLETE inventory read. A page that failed means
 *     the list is partial, so nothing is removed that cycle.
 *   - SAFETY GUARD: fewer than half of the known VMs seen means nothing is deleted.
 *   - edge gateways follow the same rule: removed only after every page was read.
 *
 * Every request goes through HypervisorHttp (https only, the shared CA bundle).
 * Each sync and test logs its session out again (vcdLogout()). Read-only:
 * nothing here ever changes anything in Director.
 */

require_once __DIR__ . '/encryption.php';
require_once __DIR__ . '/hypervisor_http.php';

const VCD_MIN_VMS_FOR_GUARD = 5;
const VCD_MIN_SEEN_RATIO    = 0.5;
const VCD_PAGE_SIZE         = 128;

function vcdLoadConnection(PDO $conn, int $connectionId): array
{
    $stmt = $conn->prepare("SELECT * FROM vcloud_connections WHERE id = ?");
    $stmt->execute([$connectionId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        throw new Exception('vCloud Director server not found');
    }
    $row['password'] = $row['password_enc'] ? decryptValue($row['password_enc']) : '';
    return $row;
}

/**
 * One HTTP call. Returns [status code, body, access token from the response headers].
 * Throws only when the server cannot be reached at all; HTTP errors are returned.
 */
function vcdHttp(array $c, string $method, string $path, array $headers, ?string $bearer = null, ?string $basic = null): array
{
    $sendHeaders = $headers;
    if ($bearer !== null) {
        $sendHeaders[] = 'Authorization: Bearer ' . $bearer;
    } elseif ($basic !== null) {
        $sendHeaders[] = 'Authorization: Basic ' . $basic;
    }
    try {
        [$code, $body, $respHeaders] = HypervisorHttp::request(
            $method, rtrim((string)$c['host'], '/') . $path, $sendHeaders, null, !empty($c['verify_ssl']), 120
        );
    } catch (Exception $e) {
        throw new Exception('Could not reach the vCloud Director server: ' . $e->getMessage());
    }
    return [$code, (string)$body, (string)($respHeaders['x-vmware-vcloud-access-token'] ?? '')];
}

function vcdAcceptJson(string $version): array
{
    return ['Accept: application/json;version=' . $version];
}

function vcdAcceptXml(string $version): array
{
    return ['Accept: application/*+xml;version=' . $version];
}

/** Session: Basic auth as "user@org", and the access token comes back in a response header. */
function vcdLogin(array $c): string
{
    $basic = base64_encode($c['username'] . '@' . $c['org'] . ':' . $c['password']);
    [$code, $body, $token] = vcdHttp($c, 'POST', '/cloudapi/1.0.0/sessions', vcdAcceptJson($c['api_version']), null, $basic);
    if ($code === 401 || $code === 403) {
        throw new Exception('vCloud Director refused the login (HTTP ' . $code . '). Check the user, the organization and the password.');
    }
    if ($code < 200 || $code >= 300 || $token === '') {
        throw new Exception('vCloud Director did not return a session (HTTP ' . $code . ').');
    }
    return $token;
}

/**
 * End the session again. Best-effort.
 *
 * Added at merge time: the PR logged in on every test and every sync and never
 * logged out, so each scheduled run left a live session behind on the Director
 * side until it timed out - one more per server per interval.
 */
function vcdLogout(array $c, string $token): void
{
    try {
        vcdHttp($c, 'DELETE', '/cloudapi/1.0.0/sessions/current', vcdAcceptJson($c['api_version']), $token);
    } catch (Exception $e) {
        // Nothing to do: the session times out on its own.
    }
}

/** A GET that returns the body on success, or null on any failure (per-item problems never stop a sync). */
function vcdTryGet(array $c, string $token, string $path, array $headers): ?string
{
    try {
        [$code, $body] = vcdHttp($c, 'GET', $path, $headers, $token);
        return ($code >= 200 && $code < 300) ? $body : null;
    } catch (Exception $e) {
        return null;
    }
}

function vcdXml(?string $body): ?SimpleXMLElement
{
    if ($body === null || $body === '') {
        return null;
    }
    libxml_use_internal_errors(true);
    $xml = simplexml_load_string($body);
    return $xml === false ? null : $xml;
}

/** Elements by local name, ignoring namespaces. */
function vcdFind(SimpleXMLElement $node, string $localName): array
{
    $found = $node->xpath('.//*[local-name()="' . $localName . '"]');
    return is_array($found) ? $found : [];
}

function vcdTest(array $c): string
{
    $token = vcdLogin($c);
    $edges = vcdTryGet($c, $token, '/cloudapi/1.0.0/edgeGateways?pageSize=1', vcdAcceptJson($c['api_version']));
    $vms = vcdXml(vcdTryGet($c, $token, '/api/query?type=vm&format=records&page=1&pageSize=1', vcdAcceptXml($c['api_version'])));
    vcdLogout($c, $token);
    if (!$vms) {
        throw new Exception('Logged in, but the VM inventory could not be read. Check the user\'s rights in its organization.');
    }
    return 'Connected to org ' . $c['org'] . '.' . ($edges === null ? ' Edge gateways are not readable with this user.' : '');
}

/**
 * Sync one server. Never throws for a per-VM problem: those are counted and reported.
 * Returns a summary and records it on the connection row.
 */
function vcdSyncConnection(PDO $conn, int $connectionId): array
{
    $c = vcdLoadConnection($conn, $connectionId);
    $v = $c['api_version'];
    $summary = ['status' => 'ok', 'message' => '', 'vms' => 0, 'edges' => 0, 'removed' => 0, 'guard' => false, 'incomplete' => false];
    $token = '';
    try {
        $token = vcdLogin($c);

        // ---- VM inventory, page by page ----
        $seen = [];
        $pending = [];
        $complete = true;
        $read = 0;
        for ($page = 1; $page < 10000; $page++) {
            $xml = vcdXml(vcdTryGet($c, $token, '/api/query?type=vm&format=records&page=' . $page . '&pageSize=' . VCD_PAGE_SIZE, vcdAcceptXml($v)));
            if ($xml === null) {
                $complete = false;   // a page did not come back: the list is partial
                break;
            }
            $records = vcdFind($xml, 'VMRecord');
            foreach ($records as $rec) {
                $details = vcdVmRecord($c, $token, $rec);
                if ($details !== null) {
                    $seen[$details['vm_uuid']] = true;
                    $pending[] = $details;
                }
            }
            // TRAP: count what ARRIVED, never page x the size we asked for. The
            //   PR stopped when page * 128 >= total, but Director caps a page at
            //   its own maximum (restapi.queryservice.maxPageSize, which an admin
            //   can lower). On such a system page 1 came back short, the loop
            //   stopped, the list counted as complete - and every VM after the
            //   first page was deleted.
            $read += count($records);
            $total = (int)($xml['total'] ?? 0);
            if (count($records) === 0 || $read >= $total) {
                break;
            }
        }

        $upsert = $conn->prepare(
            "INSERT INTO vcloud_vms (connection_id, vm_uuid, name, org_vdc, vapp_name, status, vcpus, memory_mb, disk_gb,
                ip_addresses, mac_addresses, details_json, last_seen_datetime)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE name = VALUES(name), org_vdc = VALUES(org_vdc), vapp_name = VALUES(vapp_name),
                status = VALUES(status), vcpus = VALUES(vcpus), memory_mb = VALUES(memory_mb), disk_gb = VALUES(disk_gb),
                ip_addresses = VALUES(ip_addresses), mac_addresses = VALUES(mac_addresses),
                details_json = VALUES(details_json), last_seen_datetime = VALUES(last_seen_datetime)"
        );
        foreach ($pending as $d) {
            $upsert->execute([$connectionId, $d['vm_uuid'], $d['name'], $d['org_vdc'], $d['vapp_name'], $d['status'],
                $d['vcpus'], $d['memory_mb'], $d['disk_gb'], $d['ip_addresses'], $d['mac_addresses'], $d['details_json']]);
            $summary['vms']++;
        }

        if ($complete) {
            $known = $conn->prepare("SELECT vm_uuid FROM vcloud_vms WHERE connection_id = ?");
            $known->execute([$connectionId]);
            $knownIds = $known->fetchAll(PDO::FETCH_COLUMN);
            $stale = array_values(array_diff($knownIds, array_keys($seen)));
            $knownCount = count($knownIds);
            $seenCount = count($seen);
            if ($knownCount >= VCD_MIN_VMS_FOR_GUARD && $seenCount < $knownCount * VCD_MIN_SEEN_RATIO) {
                $summary['guard'] = true;
                $summary['status'] = 'warning';
                $summary['message'] = 'SAFETY GUARD: only ' . $seenCount . ' of ' . $knownCount . ' known VMs were seen; nothing was deleted. Check the user\'s rights and the organization.';
            } elseif ($stale) {
                $placeholders = implode(',', array_fill(0, count($stale), '?'));
                $del = $conn->prepare("DELETE FROM vcloud_vms WHERE connection_id = ? AND vm_uuid IN ($placeholders)");
                $del->execute(array_merge([$connectionId], $stale));
                $summary['removed'] = $del->rowCount();
            }
        } else {
            $summary['incomplete'] = true;
            $summary['status'] = 'warning';
            $summary['message'] = 'The VM inventory was only partly readable; nothing was removed this cycle.';
        }

        // ---- edge gateways ----
        // TRAP: every page, and remove only after all of them came back. The PR
        //   read one page of 128 and then deleted every gateway not on it, so an
        //   install with more than 128 lost the rest on every sync.
        $edgeValues = [];
        $edgesComplete = false;
        for ($page = 1; $page < 1000; $page++) {
            $edgeJson = vcdTryGet($c, $token, '/cloudapi/1.0.0/edgeGateways?page=' . $page . '&pageSize=' . VCD_PAGE_SIZE, vcdAcceptJson($v));
            $edgePage = $edgeJson !== null ? json_decode($edgeJson, true) : null;
            if (!is_array($edgePage) || !is_array($edgePage['values'] ?? null)) {
                break;   // unreadable: keep what we have, remove nothing
            }
            $edgeValues = array_merge($edgeValues, $edgePage['values']);
            if ($page >= (int)($edgePage['pageCount'] ?? 1) || !$edgePage['values']) {
                $edgesComplete = true;
                break;
            }
        }
        $edges = ['values' => $edgeValues];
        if ($edgeValues || $edgesComplete) {
            $edgeUpsert = $conn->prepare(
                "INSERT INTO vcloud_edge_gateways (connection_id, gateway_id, name, org_vdc, status, uplink_ips, details_json, last_seen_datetime)
                 VALUES (?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())
                 ON DUPLICATE KEY UPDATE name = VALUES(name), org_vdc = VALUES(org_vdc), status = VALUES(status),
                    uplink_ips = VALUES(uplink_ips), details_json = VALUES(details_json), last_seen_datetime = VALUES(last_seen_datetime)"
            );
            $seenEdges = [];
            foreach ($edges['values'] as $e) {
                $gid = (string)($e['id'] ?? '');
                if ($gid === '') {
                    continue;
                }
                $seenEdges[] = $gid;
                $uplinkIps = [];
                preg_match_all('/\b(\d{1,3}(?:\.\d{1,3}){3})\b/', json_encode($e['edgeGatewayUplinks'] ?? []), $m);
                foreach (array_unique($m[1]) as $ip) {
                    $uplinkIps[] = $ip;
                }
                $edgeUpsert->execute([$connectionId, $gid, (string)($e['name'] ?? ''), (string)($e['orgVdc']['name'] ?? ''),
                    (string)($e['status'] ?? ''), $uplinkIps ? implode(', ', $uplinkIps) : null,
                    json_encode(['uplinks' => $e['edgeGatewayUplinks'] ?? []], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)]);
                $summary['edges']++;
            }
            if ($edgesComplete && $seenEdges) {
                $placeholders = implode(',', array_fill(0, count($seenEdges), '?'));
                $gone = $conn->prepare("DELETE FROM vcloud_edge_gateways WHERE connection_id = ? AND gateway_id NOT IN ($placeholders)");
                $gone->execute(array_merge([$connectionId], $seenEdges));
            }
        }

        vcdLogout($c, $token);
        $token = '';
        if ($summary['message'] === '') {
            $summary['message'] = $summary['vms'] . ' VMs, ' . $summary['edges'] . ' edge gateway(s).'
                . ($summary['removed'] ? ' Removed ' . $summary['removed'] . ' that no longer exist.' : '');
        }
    } catch (Exception $e) {
        $summary['status'] = 'error';
        $summary['message'] = $e->getMessage();
        if ($token !== '') {
            vcdLogout($c, $token);
        }
    }

    $conn->prepare(
        "UPDATE vcloud_connections SET last_sync_datetime = UTC_TIMESTAMP(), last_sync_status = ?, last_sync_message = ? WHERE id = ?"
    )->execute([$summary['status'], $summary['message'], $connectionId]);

    return $summary;
}

/** One VM record from the inventory, with its NICs and disks. Null if it has no usable uuid. */
function vcdVmRecord(array $c, string $token, SimpleXMLElement $rec): ?array
{
    $href = (string)($rec['href'] ?? '');
    if (!preg_match('/vm-([0-9a-fA-F-]{36})/', $href, $m)) {
        return null;
    }
    $uuid = $m[1];
    $v = $c['api_version'];
    $statusCode = (string)($rec['status'] ?? '');
    $status = $statusCode === '4' ? 'running' : ($statusCode === '8' ? 'stopped' : ('status-' . $statusCode));

    $nics = [];
    $ips = [];
    $macs = [];
    $nicXml = vcdXml(vcdTryGet($c, $token, '/api/vApp/vm-' . $uuid . '/networkConnectionSection/', vcdAcceptXml($v)));
    if ($nicXml) {
        foreach (vcdFind($nicXml, 'NetworkConnection') as $n) {
            $key = 'nic' . (string)($n['networkConnectionIndex'] ?? count($nics));
            $mac = strtolower((string)(vcdFind($n, 'MACAddress')[0] ?? ''));
            $ip  = (string)(vcdFind($n, 'IpAddress')[0] ?? '');
            $connected = strtolower((string)(vcdFind($n, 'IsConnected')[0] ?? 'false')) === 'true';
            $nics[$key] = [
                'label' => $key, 'type' => (string)(vcdFind($n, 'NetworkAdapterType')[0] ?? ''),
                'mac_address' => $mac, 'state' => $connected ? 'connected' : 'disconnected',
                'backing' => ['network_name' => (string)($n['network'] ?? '')],
            ];
            if ($mac !== '') {
                $macs[] = $mac;
            }
            if ($connected && $ip !== '') {
                $ips[] = $ip;
            }
        }
    }
    if (!$ips && (string)($rec['ipAddress'] ?? '') !== '') {
        $ips[] = (string)$rec['ipAddress'];
    }

    $disks = [];
    $diskGb = 0.0;
    $diskXml = vcdXml(vcdTryGet($c, $token, '/api/vApp/vm-' . $uuid . '/virtualHardwareSection/disks', vcdAcceptXml($v)));
    if ($diskXml) {
        foreach (vcdFind($diskXml, 'Item') as $item) {
            if ((string)(vcdFind($item, 'ResourceType')[0] ?? '') !== '17') {
                continue;   // 17 is a disk drive; CPU, memory and NICs share this Item shape
            }
            $hr = vcdFind($item, 'HostResource');
            $capacityMb = isset($hr[0]['capacity']) ? (float)$hr[0]['capacity'] : null;
            if ($capacityMb === null) {
                $bytes = (float)(vcdFind($item, 'VirtualQuantity')[0] ?? 0);
                $capacityMb = $bytes / 1048576;
            }
            $key = 'disk' . count($disks);
            $disks[$key] = [
                'label' => (string)(vcdFind($item, 'ElementName')[0] ?? $key),
                'capacity' => (int)round($capacityMb * 1048576),
                'type' => 'disk',
                'backing' => ['type' => (string)($hr[0]['storageProfileName'] ?? '')],
            ];
            $diskGb += $capacityMb / 1024;
        }
    }

    return [
        'vm_uuid'      => strtolower($uuid),
        'name'         => (string)($rec['name'] ?? ('vm-' . $uuid)),
        'org_vdc'      => (string)($rec['vdcName'] ?? ''),
        'vapp_name'    => (string)($rec['vappName'] ?? ''),
        'status'       => $status,
        'vcpus'        => (int)($rec['numberOfCpus'] ?? 0) ?: null,
        'memory_mb'    => (int)($rec['memoryMB'] ?? 0) ?: null,
        'disk_gb'      => $diskGb > 0 ? round($diskGb, 2) : null,
        'ip_addresses' => $ips ? implode(', ', array_values(array_unique($ips))) : null,
        'mac_addresses'=> $macs ? implode(', ', array_values(array_unique($macs))) : null,
        'details_json' => json_encode(['vm_detail' => ['disks' => $disks, 'nics' => $nics], 'guest_networking' => []],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
    ];
}

/**
 * Save a server from the settings form. Returns its id.
 *
 * Moved here from api/assets/vcloud_connections.php at merge time, so the rules
 * are in one place and tests/hypervisor-sync.php runs the real ones.
 *
 * TRAP: the password is stored only through encryptValue(), never returned to
 *   the browser (vcdConnectionOut() says has_password and nothing more), and a
 *   blank or all-asterisks value on edit keeps the stored one.
 */
function vcdSaveConnection(PDO $conn, array $in): int
{
    $id       = (int)($in['id'] ?? 0);
    $name     = trim((string)($in['name'] ?? ''));
    $org      = trim((string)($in['org'] ?? ''));
    $username = trim((string)($in['username'] ?? ''));
    $password = (string)($in['password'] ?? '');
    $version  = trim((string)($in['api_version'] ?? '38.0')) ?: '38.0';
    $verify   = !empty($in['verify_ssl']) ? 1 : 0;
    $active   = !empty($in['is_active']) ? 1 : 0;
    $interval = max(5, min(10080, (int)($in['sync_interval_minutes'] ?? 60)));

    if ($name === '' || mb_strlen($name) > 100) {
        throw new Exception('Give the server a name (up to 100 characters).');
    }
    $host = HypervisorHttp::validateHost((string)($in['host'] ?? ''), 'https://vcd.example.com');
    if ($org === '' || mb_strlen($org) > 100) {
        throw new Exception('The organization is required. Use the organization name, or System for a provider administrator.');
    }
    if ($username === '' || mb_strlen($username) > 100 || strpos($username, ':') !== false) {
        throw new Exception('A user name is required (without a colon).');
    }
    if (!preg_match('/^\d+\.\d+$/', $version)) {
        throw new Exception('The API version must look like 38.0 (vCD 10.5) or 36.2 (vCD 10.3).');
    }
    $current = null;
    if ($id > 0) {
        $exists = $conn->prepare("SELECT password_enc FROM vcloud_connections WHERE id = ?");
        $exists->execute([$id]);
        $current = $exists->fetch(PDO::FETCH_ASSOC);
        if (!$current) {
            throw new Exception('vCloud Director server not found');
        }
    }
    $keep = ($password === '' || preg_match('/^\*+$/', $password));
    if ($id === 0 && $keep) {
        throw new Exception('A password is required for a new server.');
    }
    $passwordEnc = $keep ? ($current['password_enc'] ?? null) : encryptValue($password);

    if ($id > 0) {
        $conn->prepare(
            "UPDATE vcloud_connections SET name = ?, host = ?, org = ?, username = ?, password_enc = ?, api_version = ?,
                verify_ssl = ?, is_active = ?, sync_interval_minutes = ? WHERE id = ?"
        )->execute([$name, $host, $org, $username, $passwordEnc, $version, $verify, $active, $interval, $id]);
        return $id;
    }
    $conn->prepare(
        "INSERT INTO vcloud_connections (name, host, org, username, password_enc, api_version, verify_ssl, is_active, sync_interval_minutes)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
    )->execute([$name, $host, $org, $username, $passwordEnc, $version, $verify, $active, $interval]);
    return (int)$conn->lastInsertId();
}

/** A server as the browser may see it: never the password, only whether there is one. */
function vcdConnectionOut(array $r): array
{
    return [
        'id'                    => (int)$r['id'],
        'name'                  => $r['name'],
        'host'                  => $r['host'],
        'org'                   => $r['org'],
        'username'              => $r['username'],
        'api_version'           => $r['api_version'],
        'has_password'          => !empty($r['password_enc']),
        'verify_ssl'            => (bool)$r['verify_ssl'],
        'is_active'             => (bool)$r['is_active'],
        'sync_interval_minutes' => (int)$r['sync_interval_minutes'],
        'last_sync_datetime'    => $r['last_sync_datetime'],
        'last_sync_status'      => $r['last_sync_status'],
        'last_sync_message'     => $r['last_sync_message'],
    ];
}
