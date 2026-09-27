<?php
/**
 * Engine test for includes/managers.php - who a portal manager may see
 * (discussion #62, step 3).
 *
 *   php tests/managers-engine.php
 *
 * ⚠️ Writes to the database it finds, but inside ONE transaction that is
 * rolled back at the end, so it leaves nothing behind. Still: run it against a
 * throwaway database (docker/proxy-test), not your real one. Needs Database
 * Verification to have run (manager_grants, sensitivity, ticket_views).
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
chdir(dirname(__DIR__));
require 'config.php';
require 'includes/functions.php';
require 'includes/managers.php';
$c = connectToDatabase();
$c->beginTransaction();

$pass = 0; $fail = 0;
function check($label, $got, $want) {
    global $pass, $fail;
    if ($got === $want) { $pass++; echo "  ok    $label\n"; }
    else { $fail++; echo "  FAIL  $label\n        got  " . json_encode($got) . "\n        want " . json_encode($want) . "\n"; }
}
function setting($k, $v) {
    global $c;
    $c->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")
      ->execute(['managers_' . $k, $v]);
    managersResetMemo();
}
function person($name, $tenant, $dept, $managerId = null, $active = 1) {
    global $c;
    $c->prepare("INSERT INTO users (email, display_name, tenant_id, department, manager_id, is_active) VALUES (?, ?, ?, ?, ?, ?)")
      ->execute([strtolower($name) . '@eng.test', $name, $tenant, $dept, $managerId, $active]);
    return (int)$c->lastInsertId();
}
function ticket($userId, $tenant, $sens = 'normal') {
    global $c;
    $c->prepare("INSERT INTO tickets (ticket_number, subject, user_id, tenant_id, sensitivity, created_datetime, updated_datetime) VALUES (?, 'engine test', ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())")
      ->execute(['ENG-' . mt_rand(100000, 999999), $userId, $tenant, $sens]);
    return (int)$c->lastInsertId();
}
function grantLine($m, $type, $id = 0, $value = '', $excl = 0) {
    global $c;
    $c->prepare("INSERT INTO manager_grants (manager_user_id, grant_type, target_id, target_value, is_exclusion) VALUES (?, ?, ?, ?, ?)")
      ->execute([$m, $type, $id, $value, $excl]);
    managersResetMemo();
}
$names = [];
function team($m) { global $names; managersResetMemo(); $out = array_map(fn($i) => $names[$i] ?? "#$i", managerTeamUserIds($GLOBALS['c'], $m)); sort($out); return $out; }

$T1 = 1;
// A second company, inside the rolled-back transaction, so the company rules
// have something to be tested against on any install.
$c->exec("INSERT INTO tenants (name, is_active, is_default) VALUES ('Beta (managers engine test)', 1, 0)");
$T2 = (int)$c->lastInsertId();
echo "multi-company: " . (isMultiTenant($c) ? 'yes' : 'no') . " (Beta = $T2)\n";

$megan = person('Megan', $T1, 'Operations');
$aaron = person('Aaron', $T1, 'Logistics', $megan);
$beth  = person('Beth',  $T1, 'Logistics', $aaron);
$cal   = person('Cal',   $T1, 'logistics ');
$dev   = person('Dev',   $T1, 'Sales', null, 0);
$eve   = person('Eve',   $T1, 'Sales');
$finn  = person('Finn',  $T2, 'Logistics', $megan);
$gina  = person('Gina',  $T1, 'Finance');
$hal   = person('Hal',   $T1, 'Finance');
foreach (compact('megan','aaron','beth','cal','dev','eve','finn','gina','hal') as $n => $i) $names[$i] = ucfirst($n);

$c->exec("INSERT INTO knowledge_user_groups (name, is_active) VALUES ('Night team (engine test)', 1)");
$grp = (int)$c->lastInsertId();
$c->prepare("INSERT INTO knowledge_user_group_members (group_id, member_type, member_id, expires_at) VALUES (?, 'user', ?, NULL), (?, 'user', ?, UTC_TIMESTAMP() - INTERVAL 1 DAY)")
  ->execute([$grp, $gina, $grp, $hal]);

$tAaron   = ticket($aaron, $T1);
$tAaronC  = ticket($aaron, $T1, 'confidential');
$tEveMoved= ticket($eve, $T2);            // Eve's ticket, moved to the other company
$tFinn    = ticket($finn, $T2);
$tMegan   = ticket($megan, $T1);

echo "\n1. Switched off\n";
setting('enabled', '0'); setting('directory', '1'); setting('directory_depth', 'direct'); setting('leavers', '1'); setting('confidential', 'none');
check('nobody is a manager while off', team($megan), []);

echo "\n2. Directory reporting line\n";
setting('enabled', '1');
check('direct reports only (Finn is in the other company)', team($megan), ['Aaron']);
setting('directory_depth', 'all');
check('the whole chain below', team($megan), ['Aaron', 'Beth']);
setting('directory', '0');
check('directory switched off', team($megan), []);

echo "\n3. Management lines\n";
grantLine($megan, 'department', 0, 'Logistics');
check('department, matched ignoring case and spaces (Cal is "logistics ")', team($megan), ['Aaron', 'Beth', 'Cal']);
grantLine($megan, 'group', $grp);
check('a people group (Hal\'s membership has expired)', team($megan), ['Aaron', 'Beth', 'Cal', 'Gina']);
grantLine($megan, 'user', $eve);
check('one person', team($megan), ['Aaron', 'Beth', 'Cal', 'Eve', 'Gina']);

echo "\n4. Exclusions always win\n";
grantLine($megan, 'user', $beth, '', 1);
check('Beth excluded from the department line', team($megan), ['Aaron', 'Cal', 'Eve', 'Gina']);
setting('directory', '1');
check('...and the exclusion also beats the directory line', team($megan), ['Aaron', 'Cal', 'Eve', 'Gina']);

echo "\n5. People who have left\n";
grantLine($megan, 'department', 0, 'Sales');
setting('leavers', '1');
check('leavers kept (default): Dev included', in_array('Dev', team($megan), true), true);
setting('leavers', '0');
check('leavers off: Dev gone', in_array('Dev', team($megan), true), false);
setting('leavers', '1');

echo "\n6. The ticket rule (portalTicketAccess)\n";
managersResetMemo();
$a = portalTicketAccess($c, $megan, $tAaron);
check('Aaron\'s ticket: Megan sees it as a manager', $a['role'] ?? null, 'manager');
check('...view only by default', [$a['can_reply'] ?? null, $a['can_close'] ?? null], [false, false]);
check('Aaron\'s confidential ticket: hidden (default)', portalTicketAccess($c, $megan, $tAaronC), null);
setting('confidential', 'stub');
$s = portalTicketAccess($c, $megan, $tAaronC);
check('...as a stub when set to stub', [$s['role'] ?? null, $s['stub'] ?? null, $s['can_reply'] ?? null], ['manager', true, false]);
setting('confidential', 'all');
check('...in full when set to everything', portalTicketAccess($c, $megan, $tAaronC)['stub'] ?? null, false);
setting('confidential', 'none');
check('Eve\'s ticket moved to the other company: hidden', portalTicketAccess($c, $megan, $tEveMoved), null);
check('Finn (other company): hidden', portalTicketAccess($c, $megan, $tFinn), null);
check('Megan\'s own ticket: requester', portalTicketAccess($c, $megan, $tMegan)['role'] ?? null, 'requester');
check('Aaron cannot see Megan\'s ticket (he manages nobody)', portalTicketAccess($c, $aaron, $tMegan), null);
setting('can_reply', '1'); setting('can_close', '1');
$a = portalTicketAccess($c, $megan, $tAaron);
check('reply and close when allowed', [$a['can_reply'], $a['can_close']], [true, true]);

echo "\n7. A manager who has left\n";
$c->prepare("UPDATE users SET is_active = 0 WHERE id = ?")->execute([$megan]);
managersResetMemo();
check('sees nothing at all', [team($megan), portalTicketAccess($c, $megan, $tAaron)], [[], null]);
$c->prepare("UPDATE users SET is_active = 1 WHERE id = ?")->execute([$megan]);

echo "\n8. No company means the Default company (the documented convention)\n";
// Ed: somebody who has never filed people into companies must not have to do
// anything extra for managers to work. $T1 is the Default company here.
$dflt = getDefaultTenantId($c);
$c->prepare("UPDATE users SET tenant_id = NULL WHERE id IN (?, ?)")->execute([$megan, $aaron]);
managersResetMemo();
check('an unfiled manager still sees an unfiled report (both Default)', in_array('Aaron', team($megan), true), $dflt === $T1);
$c->prepare("UPDATE tickets SET tenant_id = NULL WHERE id = ?")->execute([$tAaron]);
managersResetMemo();
check('...and their unrouted ticket (also Default)', (portalTicketAccess($c, $megan, $tAaron)['role'] ?? null), $dflt === $T1 ? 'manager' : null);
// A manager in a real CLIENT company does not reach unfiled (Default) people.
$c->prepare("UPDATE users SET tenant_id = ?, manager_id = ? WHERE id = ?")->execute([$T2, $finn, $cal]);
$c->prepare("UPDATE users SET tenant_id = NULL WHERE id = ?")->execute([$cal]);
$c->prepare("UPDATE users SET manager_id = ? WHERE id = ?")->execute([$finn, $cal]);
setting('directory_depth', 'direct');
check('a client-company manager does not see an unfiled report', in_array('Cal', team($finn), true), false);

$c->rollBack();
echo "\n$pass passed, $fail failed (all changes rolled back)\n";
exit($fail ? 1 : 0);
