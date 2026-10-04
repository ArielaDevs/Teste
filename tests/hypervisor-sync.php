<?php
/* 🔴 NEVER OVER THE WEB. See tests/README.md. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * Proxmox VE and VMware Cloud Director sync (PR #167).
 *
 * Neither product can run in CI, so a stand-in for each answers through
 * HypervisorHttp::$testTransport the way the real API does, and the REAL sync
 * code runs against it. Most checks are about what gets DELETED, because that is
 * where a sync does damage: each rule is a TRAP comment in the code.
 *
 *   Saving     https only; the secret is stored encrypted, never returned, and
 *              kept when the field is left empty or masked.
 *   Proxmox    token and ticket logins; IPs only from the VM's own NICs; a VM
 *              removed only from a list that came back (a node whose qemu list
 *              failed keeps its VMs); an offline node keeps its VMs; the guard.
 *   Director   the VM inventory page by page; a failed page removes nothing;
 *              edge gateways across pages, removed only after every page; the
 *              session is logged out; the guard.
 *
 * Everything is written inside ONE transaction that is always rolled back.
 * Run: php tests/hypervisor-sync.php
 */

$root = dirname(__DIR__);
require_once "$root/config.php";
require_once "$root/includes/functions.php";
require_once "$root/includes/proxmox_sync.php";
require_once "$root/includes/vcloud_sync.php";

$pass = 0; $fail = 0;
function ok(string $label, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; printf("  PASS %-70s\n", $label); }
    else       { $fail++; printf("  FAIL %-70s %s\n", $label, $detail); }
}

echo "\nProxmox VE and VMware Cloud Director sync\n" . str_repeat('=', 72) . "\n";

echo "\nThe address\n";
$refused = function (callable $fn): string { try { $fn(); return ''; } catch (Exception $e) { return $e->getMessage(); } };
ok('TRAP: http:// is refused when a server is saved', str_contains($refused(fn() => HypervisorHttp::validateHost('http://pve.lab:8006', 'x')), 'https'));
ok('https://host:port is accepted', $refused(fn() => HypervisorHttp::validateHost('https://pve.lab:8006/', 'x')) === '');
ok('an address with a path is refused', $refused(fn() => HypervisorHttp::validateHost('https://pve.lab:8006/api2', 'x')) !== '');
ok('TRAP: a request to http:// is refused even if a row holds one', str_contains($refused(fn() => HypervisorHttp::request('GET', 'http://pve.lab/x', [], null, true)), 'http'));

// ---------------------------------------------------------------- stand-ins
/** A Proxmox cluster in memory. Flip $px['fail'][...] to make one call fail. */
$px = [];
$pxReset = function () use (&$px) {
    $px = [
        'nodes' => ['pve1' => 'online', 'pve2' => 'online'],
        'qemu'  => ['pve1' => [101, 102], 'pve2' => [301]],
        'lxc'   => ['pve1' => [201, 202], 'pve2' => [401]],
        'fail'  => [],
        'calls' => [],
    ];
};
$pxReset();
$pxConfig = function (string $type, int $id): array {
    if ($type === 'qemu') {
        return ['name' => 'vm' . $id, 'cores' => 2, 'sockets' => 1, 'memory' => 4096, 'ostype' => 'l26',
                'scsi0' => 'local-lvm:vm-' . $id . '-disk-0,size=32G', 'ide2' => 'local:iso/x.iso,media=cdrom',
                'net0' => 'virtio=BC:24:11:00:00:' . sprintf('%02d', $id % 100) . ',bridge=vmbr0'];
    }
    return ['hostname' => 'ct' . $id, 'cores' => 1, 'memory' => 512, 'rootfs' => 'local-lvm:subvol-' . $id . ',size=8G',
            'net0' => 'name=eth0,bridge=vmbr0,hwaddr=BC:24:11:00:01:' . sprintf('%02d', $id % 100) . ',' . ($id === 202 ? 'ip=dhcp' : 'ip=10.0.1.' . ($id % 100) . '/24')];
};
$proxmox = function (string $method, string $url, array $headers, ?string $body) use (&$px, $pxConfig): array {
    $path = preg_replace('#^https://[^/]+#', '', $url);
    $px['calls'][] = [$method, $path, $headers];
    foreach ($px['fail'] as $f) {
        if ($path === $f) return [500, '{"data":null}', []];
    }
    $data = function ($d) { return [200, json_encode(['data' => $d]), []]; };
    if ($path === '/api2/json/access/ticket') return $data(['ticket' => 'PVE:freeitsm@pve:TICKET', 'CSRFPreventionToken' => 'CSRF']);
    if ($path === '/api2/json/cluster/status') return $data([['type' => 'cluster', 'name' => 'lab']]);
    if ($path === '/api2/json/nodes') {
        $out = [];
        foreach ($px['nodes'] as $n => $st) $out[] = ['node' => $n, 'status' => $st, 'maxcpu' => 8, 'maxmem' => 34359738368];
        return $data($out);
    }
    if (preg_match('#^/api2/json/nodes/([^/]+)/(qemu|lxc)$#', $path, $m)) {
        return $data(array_map(fn($id) => ['vmid' => $id, 'name' => ($m[2] === 'qemu' ? 'vm' : 'ct') . $id, 'status' => 'running'], $px[$m[2]][$m[1]] ?? []));
    }
    if (preg_match('#^/api2/json/nodes/[^/]+/(qemu|lxc)/(\d+)/config$#', $path, $m)) return $data($pxConfig($m[1], (int)$m[2]));
    if (preg_match('#^/api2/json/nodes/[^/]+/qemu/(\d+)/agent/network-get-interfaces$#', $path, $m)) {
        $id = (int)$m[1];
        return $data(['result' => [
            ['name' => 'lo', 'hardware-address' => '00:00:00:00:00:00', 'ip-addresses' => [['ip-address' => '127.0.0.1', 'ip-address-type' => 'ipv4']]],
            ['name' => 'eth0', 'hardware-address' => 'bc:24:11:00:00:' . sprintf('%02d', $id % 100), 'ip-addresses' => [['ip-address' => '10.0.0.' . ($id % 100), 'ip-address-type' => 'ipv4']]],
            ['name' => 'docker0', 'hardware-address' => '02:42:ac:11:00:01', 'ip-addresses' => [['ip-address' => '172.17.0.1', 'ip-address-type' => 'ipv4']]],
        ]]);
    }
    return [404, '{"data":null}', []];
};

/** A Cloud Director in memory: VMs by uuid, edge gateways, page failures. */
$vd = [];
$vdReset = function () use (&$vd) {
    $vms = [];
    for ($i = 1; $i <= 6; $i++) $vms[] = sprintf('aaaaaaaa-0000-0000-0000-%012d', $i);
    $vd = ['vms' => $vms, 'edges' => ['urn:vcloud:gateway:e1', 'urn:vcloud:gateway:e2', 'urn:vcloud:gateway:e3'],
           'pageSize' => 4, 'edgePageSize' => 2, 'fail' => [], 'calls' => []];
};
$vdReset();
$vcloud = function (string $method, string $url, array $headers, ?string $body) use (&$vd): array {
    $path = preg_replace('#^https://[^/]+#', '', $url);
    $vd['calls'][] = [$method, $path, $headers];
    foreach ($vd['fail'] as $f) {
        if (str_contains($path, $f)) return [500, '', []];
    }
    $ns = 'xmlns="http://www.vmware.com/vcloud/v1.5"';
    if ($method === 'POST' && $path === '/cloudapi/1.0.0/sessions') return [200, '{}', ['x-vmware-vcloud-access-token' => 'TOKEN']];
    if ($method === 'DELETE' && $path === '/cloudapi/1.0.0/sessions/current') return [204, '', []];
    if (str_starts_with($path, '/api/query?type=vm')) {
        parse_str((string)parse_url($path, PHP_URL_QUERY), $q);
        $page = (int)$q['page'];
        // the stand-in pages by ITS size, whatever was asked, to exercise paging
        $slice = array_slice($vd['vms'], ($page - 1) * $vd['pageSize'], $vd['pageSize']);
        $recs = '';
        foreach ($slice as $u) {
            $recs .= '<VMRecord name="vm-' . substr($u, -2) . '" href="https://vcd.lab/api/vApp/vm-' . $u . '" status="4" numberOfCpus="2" memoryMB="2048" vdcName="vdc1" vappName="app1" ipAddress="10.9.9.9"/>';
        }
        // total as Director reports it; the sync must keep going while page*its pageSize < total
        return [200, '<?xml version="1.0"?><QueryResultRecords ' . $ns . ' total="' . count($vd['vms']) . '" page="' . $page . '" pageSize="' . $vd['pageSize'] . '">' . $recs . '</QueryResultRecords>', []];
    }
    if (preg_match('#/api/vApp/vm-([0-9a-f-]{36})/networkConnectionSection/$#', $path, $m)) {
        $n = substr($m[1], -2);
        return [200, '<NetworkConnectionSection ' . $ns . '><NetworkConnection network="net1" networkConnectionIndex="0"><IpAddress>10.2.0.' . (int)$n . '</IpAddress><IsConnected>true</IsConnected><MACAddress>00:50:56:00:00:' . $n . '</MACAddress><NetworkAdapterType>VMXNET3</NetworkAdapterType></NetworkConnection></NetworkConnectionSection>', []];
    }
    if (preg_match('#/virtualHardwareSection/disks$#', $path)) {
        return [200, '<RasdItemsList ' . $ns . ' xmlns:rasd="http://schemas.dmtf.org/wbem/wscim/1/cim-schema/2/CIM_ResourceAllocationSettingData"><Item><rasd:ElementName>Hard disk 1</rasd:ElementName><rasd:HostResource capacity="40960" storageProfileName="Gold"/><rasd:ResourceType>17</rasd:ResourceType></Item><Item><rasd:ResourceType>5</rasd:ResourceType></Item></RasdItemsList>', []];
    }
    if (str_starts_with($path, '/cloudapi/1.0.0/edgeGateways')) {
        parse_str((string)parse_url($path, PHP_URL_QUERY), $q);
        $page = (int)($q['page'] ?? 1);
        $slice = array_slice($vd['edges'], ($page - 1) * $vd['edgePageSize'], $vd['edgePageSize']);
        return [200, json_encode([
            'page' => $page, 'pageCount' => max(1, (int)ceil(count($vd['edges']) / $vd['edgePageSize'])),
            'values' => array_map(fn($id) => ['id' => $id, 'name' => 'edge-' . substr($id, -2), 'status' => 'REALIZED',
                'orgVdc' => ['name' => 'vdc1'], 'edgeGatewayUplinks' => [['subnets' => ['values' => [['primaryIp' => '203.0.113.' . substr($id, -1)]]]]]], $slice),
        ]), []];
    }
    return [404, '', []];
};

$conn = connectToDatabase();
$conn->beginTransaction();
try {
    // ------------------------------------------------------------ saving
    echo "\nSaving a server\n";
    ok('TRAP: an http:// address is refused on save',
       str_contains($refused(fn() => proxmoxSaveConnection($conn, ['name' => 'ZZHV', 'host' => 'http://pve.lab:8006', 'username' => 'freeitsm@pve!itsm', 'password' => 's'])), 'https'));
    ok('a malformed token ID is refused', $refused(fn() => proxmoxSaveConnection($conn, ['name' => 'ZZHV', 'host' => 'https://pve.lab:8006', 'username' => 'freeitsm!itsm', 'password' => 's'])) !== '');
    ok('a new server needs a secret', $refused(fn() => proxmoxSaveConnection($conn, ['name' => 'ZZHV', 'host' => 'https://pve.lab:8006', 'username' => 'freeitsm@pve!itsm', 'password' => ''])) !== '');
    $pid = proxmoxSaveConnection($conn, ['name' => 'ZZHV Proxmox', 'host' => 'https://pve.lab:8006/', 'username' => 'freeitsm@pve!itsm',
        'password' => 'zz-token-secret', 'verify_ssl' => true, 'is_active' => true]);
    $row = $conn->query("SELECT * FROM proxmox_connections WHERE id = $pid")->fetch(PDO::FETCH_ASSOC);
    ok('TRAP: the secret is stored encrypted', str_starts_with((string)$row['password_enc'], 'ENC:') && !str_contains((string)$row['password_enc'], 'zz-token-secret'));
    ok('...and decrypts to what was typed', proxmoxLoadConnection($conn, $pid)['password'] === 'zz-token-secret');
    $out = proxmoxConnectionOut($row);
    ok('TRAP: what the browser gets has no secret in it', !str_contains(json_encode($out), 'zz-token-secret') && !str_contains(json_encode($out), 'ENC:') && $out['has_password'] === true);
    proxmoxSaveConnection($conn, ['id' => $pid, 'name' => 'ZZHV Proxmox', 'host' => 'https://pve.lab:8006', 'username' => 'freeitsm@pve!itsm', 'password' => '', 'verify_ssl' => true, 'is_active' => true]);
    ok('an empty secret on edit keeps the stored one', proxmoxLoadConnection($conn, $pid)['password'] === 'zz-token-secret');
    proxmoxSaveConnection($conn, ['id' => $pid, 'name' => 'ZZHV Proxmox', 'host' => 'https://pve.lab:8006', 'username' => 'freeitsm@pve!itsm', 'password' => '********', 'verify_ssl' => true, 'is_active' => true]);
    ok('...and so does a masked one', proxmoxLoadConnection($conn, $pid)['password'] === 'zz-token-secret');
    ok('editing a server that does not exist is refused', $refused(fn() => proxmoxSaveConnection($conn, ['id' => 999999999, 'name' => 'x', 'host' => 'https://a.b', 'username' => 'u@pve', 'password' => ''])) !== '');

    // ------------------------------------------------------------ Proxmox
    echo "\nProxmox VE\n";
    HypervisorHttp::$testTransport = $proxmox;
    $s = proxmoxSyncConnection($conn, $pid);
    $vms = $conn->query("SELECT * FROM proxmox_vms WHERE connection_id = $pid ORDER BY vmid")->fetchAll(PDO::FETCH_ASSOC);
    $by = array_column($vms, null, 'vmid');
    ok('a first sync stores every VM and container (6)', $s['status'] === 'ok' && count($vms) === 6, json_encode($s));
    $auth = $px['calls'][0][2] ?? [];
    ok('TRAP: token login sends PVEAPIToken=id=secret, over https', in_array('Authorization: PVEAPIToken=freeitsm@pve!itsm=zz-token-secret', $auth, true));
    ok('a VM\'s IP comes from the guest agent, on its own NIC', ($by[101]['ip_addresses'] ?? '') === '10.0.0.1', $by[101]['ip_addresses'] ?? '');
    ok('...docker0 and loopback are skipped', !str_contains((string)$by[101]['ip_addresses'], '172.17') && !str_contains((string)$by[101]['ip_addresses'], '127.'));
    ok('a container\'s IP comes from ip= in its config', ($by[201]['ip_addresses'] ?? '') === '10.0.1.1');
    ok('...and ip=dhcp gives none', $by[202]['ip_addresses'] === null);
    ok('the disk total leaves out the CD-ROM (32 GB)', (float)$by[101]['disk_gb'] === 32.0, (string)$by[101]['disk_gb']);
    ok('the result is recorded on the server row', $conn->query("SELECT last_sync_status FROM proxmox_connections WHERE id = $pid")->fetchColumn() === 'ok');

    $px['qemu']['pve1'] = [101];
    $s = proxmoxSyncConnection($conn, $pid);
    ok('a VM deleted in Proxmox is removed', $s['removed'] === 1 && !$conn->query("SELECT 1 FROM proxmox_vms WHERE connection_id = $pid AND vmid = 102")->fetchColumn(), json_encode($s));

    $px['qemu']['pve1'] = [101, 102];
    $px['fail'] = ['/api2/json/nodes/pve2/qemu'];
    $px['lxc']['pve2'] = [];
    $s = proxmoxSyncConnection($conn, $pid);
    ok('TRAP: a node whose qemu list failed keeps its VMs', (bool)$conn->query("SELECT 1 FROM proxmox_vms WHERE connection_id = $pid AND vmid = 301")->fetchColumn(), json_encode($s));
    ok('...while its lxc list, which did come back, is still applied', !$conn->query("SELECT 1 FROM proxmox_vms WHERE connection_id = $pid AND vmid = 401")->fetchColumn());
    ok('...and the sync says which list it could not read', $s['status'] === 'warning' && str_contains($s['message'], 'pve2/qemu'), $s['message']);

    $px['fail'] = [];
    $px['lxc']['pve2'] = [401];
    $px['nodes']['pve2'] = 'offline';
    proxmoxSyncConnection($conn, $pid);
    ok('an offline node keeps its VMs', (bool)$conn->query("SELECT 1 FROM proxmox_vms WHERE connection_id = $pid AND vmid = 301")->fetchColumn());

    $px['nodes']['pve2'] = 'online';
    $px['qemu']['pve1'] = [101, 102, 103, 104, 105, 106, 107, 108];
    proxmoxSyncConnection($conn, $pid);
    $px['qemu']['pve1'] = [101];
    $px['lxc']['pve1'] = [];
    $s = proxmoxSyncConnection($conn, $pid);
    ok('the safety guard: most VMs missing at once removes nothing', $s['guard'] === true && $s['removed'] === 0
       && (int)$conn->query("SELECT COUNT(*) FROM proxmox_vms WHERE connection_id = $pid AND node_name = 'pve1'")->fetchColumn() === 10, json_encode($s));

    $conn->prepare("UPDATE proxmox_connections SET username = 'freeitsm@pve' WHERE id = ?")->execute([$pid]);
    $px['calls'] = [];
    proxmoxSyncConnection($conn, $pid);
    $login = $px['calls'][0] ?? [];
    $later = $px['calls'][1][2] ?? [];
    ok('a user (not a token) logs in for a ticket first', ($login[0] ?? '') === 'POST' && ($login[1] ?? '') === '/api2/json/access/ticket');
    ok('...and sends it as the PVEAuthCookie', in_array('Cookie: PVEAuthCookie=PVE:freeitsm@pve:TICKET', $later, true));
    ok('Test reports the nodes it can see', str_contains(proxmoxTestConnection(proxmoxLoadConnection($conn, $pid)), '2 node'));
    HypervisorHttp::$testTransport = function () { throw new Exception('Could not resolve host: pve.lab'); };
    $conn->prepare("UPDATE proxmox_connections SET username = 'freeitsm@pve!itsm' WHERE id = ?")->execute([$pid]);
    $why = $refused(fn() => proxmoxTestConnection(proxmoxLoadConnection($conn, $pid)));
    ok('TRAP: an unreachable server says so, not "check the permissions"', str_contains($why, 'Could not reach') && !str_contains($why, 'permission'), $why);
    HypervisorHttp::$testTransport = $proxmox;

    // ------------------------------------------------------------ Director
    echo "\nVMware Cloud Director\n";
    $vid = vcdSaveConnection($conn, ['name' => 'ZZHV Director', 'host' => 'https://vcd.lab', 'org' => 'acme', 'username' => 'reader',
        'password' => 'zz-vcd-pass', 'api_version' => '38.0', 'verify_ssl' => true, 'is_active' => true]);
    ok('TRAP: the Director password is stored encrypted', str_starts_with((string)$conn->query("SELECT password_enc FROM vcloud_connections WHERE id = $vid")->fetchColumn(), 'ENC:'));
    ok('a user name with a colon is refused (it would break the login)', $refused(fn() => vcdSaveConnection($conn, ['name' => 'x', 'host' => 'https://vcd.lab', 'org' => 'acme', 'username' => 'a:b', 'password' => 'p'])) !== '');
    HypervisorHttp::$testTransport = $vcloud;
    $s = vcdSyncConnection($conn, $vid);
    $vvms = $conn->query("SELECT * FROM vcloud_vms WHERE connection_id = $vid ORDER BY vm_uuid")->fetchAll(PDO::FETCH_ASSOC);
    ok('every VM is read, across pages (6 in pages of 4)', $s['status'] === 'ok' && count($vvms) === 6, json_encode($s));
    ok('TRAP: ...even though 128 per page was asked for (Director\'s own cap wins)', str_contains(implode(' ', array_column($vd['calls'], 1)), 'pageSize=128'));
    $login = $vd['calls'][0] ?? [];
    ok('the login is Basic user@org over https', ($login[1] ?? '') === '/cloudapi/1.0.0/sessions'
       && in_array('Authorization: Basic ' . base64_encode('reader@acme:zz-vcd-pass'), $login[2] ?? [], true));
    ok('...and later calls carry the token from the response header', in_array('Authorization: Bearer TOKEN', $vd['calls'][1][2] ?? [], true));
    ok('NICs, IPs and MACs are read whatever the XML namespace', ($vvms[0]['ip_addresses'] ?? '') === '10.2.0.1' && ($vvms[0]['mac_addresses'] ?? '') === '00:50:56:00:00:01', json_encode($vvms[0] ?? []));
    ok('disks are read (40 GB, the CPU item ignored)', (float)$vvms[0]['disk_gb'] === 40.0, (string)$vvms[0]['disk_gb']);
    ok('the session is logged out afterwards', in_array(['DELETE', '/cloudapi/1.0.0/sessions/current'], array_map(fn($c) => [$c[0], $c[1]], $vd['calls']), true));
    ok('edge gateways are read across pages (3 in pages of 2)', (int)$conn->query("SELECT COUNT(*) FROM vcloud_edge_gateways WHERE connection_id = $vid")->fetchColumn() === 3);

    array_pop($vd['vms']);
    $s = vcdSyncConnection($conn, $vid);
    ok('a VM deleted in Director is removed', $s['removed'] === 1 && (int)$conn->query("SELECT COUNT(*) FROM vcloud_vms WHERE connection_id = $vid")->fetchColumn() === 5, json_encode($s));

    // vm 1 is gone from page 1, and page 2 fails: nothing may be removed, not even vm 1.
    $vd['vms'] = array_merge(array_slice($vd['vms'], 1, 4), [sprintf('aaaaaaaa-0000-0000-0000-%012d', 7)]);
    $vd['fail'] = ['page=2&'];
    $s = vcdSyncConnection($conn, $vid);
    ok('a page that failed removes nothing, not even a VM missing from page 1', $s['incomplete'] === true && $s['removed'] === 0
       && (bool)$conn->query("SELECT 1 FROM vcloud_vms WHERE connection_id = $vid AND vm_uuid LIKE '%000000000001'")->fetchColumn(), json_encode($s));

    $vd['fail'] = ['edgeGateways?page=2'];
    vcdSyncConnection($conn, $vid);
    ok('TRAP: an edge page that failed removes no gateway', (int)$conn->query("SELECT COUNT(*) FROM vcloud_edge_gateways WHERE connection_id = $vid")->fetchColumn() === 3);

    $vd['fail'] = [];
    $vd['edges'] = ['urn:vcloud:gateway:e1'];
    vcdSyncConnection($conn, $vid);
    ok('...and a complete read does', (int)$conn->query("SELECT COUNT(*) FROM vcloud_edge_gateways WHERE connection_id = $vid")->fetchColumn() === 1);

    $vd['vms'] = [];
    for ($i = 1; $i <= 5; $i++) $vd['vms'][] = sprintf('aaaaaaaa-0000-0000-0000-%012d', $i);
    vcdSyncConnection($conn, $vid);
    $vd['vms'] = array_slice($vd['vms'], 0, 1);
    $s = vcdSyncConnection($conn, $vid);
    ok('the safety guard: most VMs missing at once removes nothing', $s['guard'] === true && (int)$conn->query("SELECT COUNT(*) FROM vcloud_vms WHERE connection_id = $vid")->fetchColumn() === 5, json_encode($s));

    $vd['calls'] = [];
    $vd['fail'] = ['/cloudapi/1.0.0/sessions'];
    $s = vcdSyncConnection($conn, $vid);
    ok('a refused login is recorded as an error, nothing touched', $s['status'] === 'error' && (int)$conn->query("SELECT COUNT(*) FROM vcloud_vms WHERE connection_id = $vid")->fetchColumn() === 5);
} catch (Throwable $e) {
    ok('the run completed', false, get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
} finally {
    HypervisorHttp::$testTransport = null;
    if ($conn->inTransaction()) $conn->rollBack();
}
$left = (int)$conn->query("SELECT COUNT(*) FROM proxmox_connections WHERE name LIKE 'ZZHV%'")->fetchColumn()
      + (int)$conn->query("SELECT COUNT(*) FROM vcloud_connections WHERE name LIKE 'ZZHV%'")->fetchColumn();
ok('nothing survived the run (rolled back)', $left === 0, "$left rows left");

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail ? 1 : 0);
