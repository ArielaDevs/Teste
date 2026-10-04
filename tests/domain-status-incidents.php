<?php
/* 🔴 NEVER OVER THE WEB. See tests/README.md. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * Domains -> Service Status (3.0.0): includes/domains/status_link.php.
 *
 * ServiceStatusService runs its own transaction, so this cannot sit inside a
 * rolled-back one. Instead it creates its OWN domain and its OWN status service
 * (named so nobody could mistake them), works only on those, and deletes them -
 * with every incident they raised - in a finally block. The Domains settings it
 * changes are put back exactly as they were.
 *
 * Run: php tests/domain-status-incidents.php
 */

$root = dirname(__DIR__);
require_once "$root/config.php";
require_once "$root/includes/functions.php";
require_once "$root/includes/domains/status_link.php";

$pass = 0; $fail = 0;
function ok(string $label, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; printf("  PASS %-66s %s\n", $label, $detail); }
    else       { $fail++; printf("  FAIL %-66s %s\n", $label, $detail); }
}

$conn = connectToDatabase();
if (!domainStatusReady($conn)) { echo "Run Database Verification first.\n"; exit(1); }
$one = fn(string $sql, array $a = []) => (function () use ($conn, $sql, $a) { $s = $conn->prepare($sql); $s->execute($a); return $s->fetchColumn(); })();

$keys = ['domain_status_mode', 'domain_status_on_expired', 'domain_status_on_cert', 'domain_status_cert_days',
         'domain_status_impact', 'domain_status_public', 'domain_status_auto_resolve'];
$saved = [];
foreach ($keys as $k) $saved[$k] = $one("SELECT setting_value FROM system_settings WHERE setting_key = ?", [$k]);
$set = function (array $v) use ($conn) {
    foreach ($v as $k => $val) domainSettingWrite($conn, $k, (string)$val);
    domainSettings($conn, true);
};

$maxBefore = (int)$one("SELECT COALESCE(MAX(id), 0) FROM status_incidents");
$tag = 'freeitsm-status-test-' . bin2hex(random_bytes(3));
$domainId = 0; $serviceId = 0; $incidents = [];
$ctx = new ActorContext(actorId: 1, companyScope: null, source: 'ui', actorName: 'test');

try {
    $conn->prepare("INSERT INTO domains (domain_name, expiry_date, ssl_expiry_date, renewal_mode) VALUES (?, ?, ?, 'manual')")
         ->execute(["$tag.invalid", gmdate('Y-m-d', strtotime('-3 days')), gmdate('Y-m-d', strtotime('+30 days'))]);
    $domainId = (int)$conn->lastInsertId();
    $conn->prepare("INSERT INTO status_services (name, description, is_active) VALUES (?, 'test', 1)")->execute([$tag]);
    $serviceId = (int)$conn->lastInsertId();

    echo "\n1. What counts as trouble\n";
    $set(['domain_status_mode' => 'suggest', 'domain_status_on_expired' => '1', 'domain_status_on_cert' => '1',
          'domain_status_cert_days' => '0', 'domain_status_impact' => '', 'domain_status_public' => '0', 'domain_status_auto_resolve' => '1']);
    $kinds = fn() => array_column(domainStatusProblems($conn, domainStatusRow($conn, $domainId)), 'kind');
    ok('an expired domain is trouble', $kinds() === ['expired']);
    ok('a certificate 30 days out is not (cert days 0)', !in_array('cert', $kinds(), true));
    $set(['domain_status_cert_days' => '30']);
    ok('...and is, once cert days covers it', in_array('cert', $kinds(), true));
    $set(['domain_status_on_expired' => '0', 'domain_status_cert_days' => '0']);
    ok('switching "expired" off removes it', $kinds() === []);
    $set(['domain_status_on_expired' => '1']);
    $conn->prepare("UPDATE domains SET renewal_mode = 'do_not_renew' WHERE id = ?")->execute([$domainId]);
    ok('a domain being let go is never trouble', $kinds() === []);
    $conn->prepare("UPDATE domains SET renewal_mode = 'manual' WHERE id = ?")->execute([$domainId]);

    echo "\n2. Raising by hand (suggest)\n";
    $refused = false;
    try { domainStatusRaise($conn, $ctx, $domainId); } catch (ServiceError $e) { $refused = true; }
    ok('nothing to raise with no services linked', $refused);
    $conn->prepare("INSERT INTO domain_status_services (domain_id, service_id) VALUES (?, ?)")->execute([$domainId, $serviceId]);
    $inc = domainStatusRaise($conn, $ctx, $domainId); $incidents[] = $inc;
    ok('raised', $inc > 0, "#$inc");
    ok('on the linked service', (int)$one("SELECT COUNT(*) FROM status_incident_services WHERE incident_id = ? AND service_id = ?", [$inc, $serviceId]) === 1);
    $impact = $one("SELECT l.name FROM status_incident_services s JOIN service_impact_levels l ON l.id = s.impact_level_id WHERE s.incident_id = ?", [$inc]);
    ok('with the most severe downtime impact, not "Operational"', $impact !== false && strcasecmp((string)$impact, 'Operational') !== 0, (string)$impact);
    ok('opening update INTERNAL by default (customers read the page)', (int)$one("SELECT MIN(is_internal) FROM status_incident_updates WHERE incident_id = ?", [$inc]) === 1);
    ok('raising again returns the same open incident', domainStatusRaise($conn, $ctx, $domainId) === $inc);
    ok('the page sees the open incident', (domainStatusState($conn, 1, $domainId)['incident']['id'] ?? 0) === $inc);

    echo "\n3. Resolving when the domain recovers\n";
    $set(['domain_status_auto_resolve' => '0']);
    $conn->prepare("UPDATE domains SET expiry_date = ? WHERE id = ?")->execute([gmdate('Y-m-d', strtotime('+300 days')), $domainId]);
    domainStatusRun($conn);
    ok('auto-resolve OFF: left open', $one("SELECT resolved_datetime FROM status_incidents WHERE id = ?", [$inc]) === null);
    $set(['domain_status_auto_resolve' => '1']);
    $r = domainStatusRun($conn);
    ok('auto-resolve ON: resolved', $one("SELECT resolved_datetime FROM status_incidents WHERE id = ?", [$inc]) !== null, json_encode($r));
    ok('...with a resolved status', (int)$one("SELECT s.is_resolved FROM status_incidents i JOIN service_incident_statuses s ON s.id = i.status_id WHERE i.id = ?", [$inc]) === 1);

    echo "\n4. Raising by itself (auto) - never twice\n";
    $conn->prepare("UPDATE domains SET expiry_date = ? WHERE id = ?")->execute([gmdate('Y-m-d', strtotime('-1 days')), $domainId]);
    $set(['domain_status_mode' => 'suggest']);
    ok('suggest mode: the run raises nothing', domainStatusRun($conn)['raised'] === 0);
    $set(['domain_status_mode' => 'auto', 'domain_status_public' => '1']);
    $r = domainStatusRun($conn);
    $auto = (int)$one("SELECT MAX(incident_id) FROM domain_status_incidents WHERE domain_id = ?", [$domainId]);
    if ($auto && $auto !== $inc) $incidents[] = $auto;
    ok('auto mode: raised', $r['raised'] === 1 && $auto !== $inc);
    ok('public setting ON: the opening update is published', (int)$one("SELECT MIN(is_internal) FROM status_incident_updates WHERE incident_id = ?", [$auto]) === 0);
    ok('a second run raises nothing more', domainStatusRun($conn)['raised'] === 0);
    // A person resolves it by hand; the trouble is still there. Same dates: never again.
    ServiceStatusService::saveIncident($conn, $ctx, ['id' => $auto, 'status' => 'Resolved']);
    ok('resolved by a person, same trouble: not raised again', domainStatusRun($conn)['raised'] === 0);
    // Renewed and lapsed again - a new date - re-arms it.
    $conn->prepare("UPDATE domains SET expiry_date = ? WHERE id = ?")->execute([gmdate('Y-m-d', strtotime('-2 days')), $domainId]);
    $r = domainStatusRun($conn);
    $again = (int)$one("SELECT MAX(incident_id) FROM domain_status_incidents WHERE domain_id = ?", [$domainId]);
    if ($again && !in_array($again, $incidents, true)) $incidents[] = $again;
    ok('a new expiry date re-arms it', $r['raised'] === 1 && $again !== $auto);
} finally {
    foreach ($incidents as $i) {
        // No cascade can be relied on here: status_incident_services has none,
        // and an upgraded install may lack fk_siu_incident (DB Verify does not
        // add it), so the updates are deleted by hand too.
        $conn->prepare("DELETE FROM status_incident_services WHERE incident_id = ?")->execute([$i]);
        $conn->prepare("DELETE FROM status_incident_update_services WHERE update_id IN (SELECT id FROM status_incident_updates WHERE incident_id = ?)")->execute([$i]);
        $conn->prepare("DELETE FROM status_incident_updates WHERE incident_id = ?")->execute([$i]);
        $conn->prepare("DELETE FROM status_incidents WHERE id = ?")->execute([$i]);
    }
    if ($serviceId) $conn->prepare("DELETE FROM status_incident_services WHERE service_id = ?")->execute([$serviceId]);
    if ($domainId) $conn->prepare("DELETE FROM domains WHERE id = ?")->execute([$domainId]);
    if ($serviceId) $conn->prepare("DELETE FROM status_services WHERE id = ?")->execute([$serviceId]);
    foreach ($saved as $k => $v) {
        if ($v === false) $conn->prepare("DELETE FROM system_settings WHERE setting_key = ?")->execute([$k]);
        else $conn->prepare("UPDATE system_settings SET setting_value = ? WHERE setting_key = ?")->execute([$v, $k]);
    }
}
ok('cleaned up: no test domain, service, incident or update left',
   (int)$one("SELECT (SELECT COUNT(*) FROM domains WHERE domain_name LIKE 'freeitsm-status-test-%') + (SELECT COUNT(*) FROM status_services WHERE name LIKE 'freeitsm-status-test-%') + (SELECT COUNT(*) FROM status_incidents WHERE title LIKE '%freeitsm-status-test-%') + (SELECT COUNT(*) FROM status_incident_updates u LEFT JOIN status_incidents i ON i.id = u.incident_id WHERE i.id IS NULL AND u.incident_id > $maxBefore)") === 0);

echo "\n" . str_repeat('-', 78) . "\n";
printf("  %d passed, %d failed\n\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
