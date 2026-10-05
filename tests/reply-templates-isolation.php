<?php
/* 🔴 NEVER OVER THE WEB. See tests/README.md. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * Reply-templates isolation — delete/update cross-tenant by id.
 *
 * WHY THIS EXISTS:
 * replyTemplateWriteScope() (includes/reply_templates.php) resolves a
 * template by id looking ONLY at analyst_id: a SHARED template owned by
 * company B answers 'shared' for an analyst scoped to company A. Both
 * save_reply_template.php and delete_reply_template.php then check only the
 * settings capability — never the template's tenant_id. So analyst A (with
 * the capability) can rewrite or delete B's team templates by id, and the
 * 403 message is identical for "not found", giving no hint but no protection
 * either.
 *
 * STATUS (pós-P0/F10): the write-scope probes drive the fixed gate
 * (tenant check; global needs all-tenant reach) and must PASS. If any gap
 * probe fails, it is a REGRESSION — reopen with Agente 1. The
 * list-visibility probes pass via getTenantConfigRows(). See
 * docs/testing/isolation-matrix.md.
 *
 * Run:  php tests/reply-templates-isolation.php   (needs a DB with 2+ tenants)
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/i18n.php';
require_once __DIR__ . '/../includes/tenancy.php';
require_once __DIR__ . '/../includes/reply_templates.php';
I18n::initFromSession();

$pass = 0; $fail = 0;
function ok(string $what, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  PASS  {$what}\n"; }
    else       { $fail++; echo "  FAIL  {$what}" . ($detail ? "  <- {$detail}" : '') . "\n"; }
}

echo "\nReply-templates isolation\n" . str_repeat('=', 70) . "\n";

$conn = connectToDatabase();
if (!isMultiTenant($conn)) { echo "  SKIP  needs two or more companies\n"; exit(2); }

$ids = $conn->query("SELECT id FROM analysts WHERE is_active = 1 AND (is_admin = 0 OR is_admin IS NULL) ORDER BY id LIMIT 2")->fetchAll(PDO::FETCH_COLUMN);
if (count($ids) < 1) { echo "  SKIP  needs a non-admin analyst\n"; exit(2); }
$r = (int)$ids[0];
$rAll = count($ids) > 1 ? (int)$ids[1] : 0;

$uniq = 'zz' . substr(preg_replace('/[^a-z0-9]/', '', strtolower(uniqid())), 0, 8);

$conn->beginTransaction();
try {
    $conn->prepare("INSERT INTO tenants (name, slug, is_default, is_active) VALUES (?, ?, 0, 1)")
        ->execute(["ZZ-ISOL-A {$uniq}", "zz-isol-a-{$uniq}"]);
    $tenantA = (int)$conn->lastInsertId();
    $conn->prepare("INSERT INTO tenants (name, slug, is_default, is_active) VALUES (?, ?, 0, 1)")
        ->execute(["ZZ-ISOL-B {$uniq}", "zz-isol-b-{$uniq}"]);
    $tenantB = (int)$conn->lastInsertId();
    ok('fixtures: two distinct tenants created', $tenantA > 0 && $tenantB > 0 && $tenantA !== $tenantB);

    $conn->prepare("UPDATE analysts SET can_access_all_tenants = 0, can_access_all_modules = 1 WHERE id = ?")->execute([$r]);
    $conn->prepare("DELETE FROM analyst_tenant_access WHERE analyst_id = ?")->execute([$r]);
    $conn->prepare("DELETE FROM analyst_teams WHERE analyst_id = ?")->execute([$r]);
    $conn->prepare("INSERT INTO analyst_tenant_access (analyst_id, tenant_id) VALUES (?, ?)")->execute([$r, $tenantA]);
    ok("analyst {$r} is scoped to tenant A only", getAccessibleTenantIds($conn, $r) === [$tenantA]);
    if ($rAll) {
        $conn->prepare("UPDATE analysts SET can_access_all_tenants = 1, can_access_all_modules = 1 WHERE id = ?")->execute([$rAll]);
        $conn->prepare("DELETE FROM analyst_tenant_access WHERE analyst_id = ?")->execute([$rAll]);
        $conn->prepare("DELETE FROM analyst_teams WHERE analyst_id = ?")->execute([$rAll]);
        ok("analyst {$rAll} reaches every company (global-template control)", analystHasAllTenantAccess($conn, $rAll));
    } else {
        echo "  SKIP  global-template write control needs a second non-admin analyst\n";
    }

    // Shared template owned by B, shared global default, private of r.
    $conn->prepare("INSERT INTO ticket_reply_templates (name, body, analyst_id, tenant_id, is_active) VALUES (?, 'body', NULL, ?, 1)")
        ->execute(["ZZ-ISOL tpl B {$uniq}", $tenantB]);
    $tplB = (int)$conn->lastInsertId();
    $conn->prepare("INSERT INTO ticket_reply_templates (name, body, analyst_id, tenant_id, is_active) VALUES (?, 'body', NULL, NULL, 1)")
        ->execute(["ZZ-ISOL tpl global {$uniq}"]);
    $tplGlobal = (int)$conn->lastInsertId();
    $conn->prepare("INSERT INTO ticket_reply_templates (name, body, analyst_id, tenant_id, is_active) VALUES (?, 'body', ?, NULL, 1)")
        ->execute(["ZZ-ISOL tpl mine {$uniq}", $r]);
    $tplMine = (int)$conn->lastInsertId();

    $_SESSION['active_tenant_id'] = $tenantA;
    $_SESSION['active_tenant_all'] = false;

    echo "\nList visibility (already scoped via getTenantConfigRows):\n";
    $names = array_column(replyTemplatesVisibleTo($conn, $r), 'name');
    ok('POSITIVE CONTROL: global default is visible', in_array("ZZ-ISOL tpl global {$uniq}", $names, true), json_encode($names));
    ok('POSITIVE CONTROL: own private template is visible', in_array("ZZ-ISOL tpl mine {$uniq}", $names, true));
    ok("tenant-B shared template is absent from tenant-A picker", !in_array("ZZ-ISOL tpl B {$uniq}", $names, true), json_encode($names));

    echo "\nWrite scope by id (the gap — save/delete endpoints rely on this):\n";
    ok('POSITIVE CONTROL: own private template is writable as mine', replyTemplateWriteScope($conn, $r, $tplMine) === 'mine');
    ok('unknown template id is refused', replyTemplateWriteScope($conn, $r, 2147483647) === null);
    // Probe: B's shared template resolves as editable-'shared' for an analyst
    // who cannot access B. Desired: null (same answer as "not found", which the
    // endpoints already give for that case — no oracle is created).
    $scopeB = replyTemplateWriteScope($conn, $r, $tplB);
    ok('tenant-B shared template is not writable by tenant-A analyst', $scopeB === null, 'scope=' . var_export($scopeB, true));
    // GLOBAL template (NULL tenant, served to every company): writing one
    // takes reach over every company (F10 destination rule, v3 V3 demote
    // path included — demoting a global template still rewrites a global row).
    $scopeG = replyTemplateWriteScope($conn, $r, $tplGlobal);
    ok('GLOBAL template is not writable by a merely company-scoped analyst', $scopeG === null, 'scope=' . var_export($scopeG, true));
    if ($rAll) {
        ok('POSITIVE CONTROL: all-reach analyst may write the global template', replyTemplateWriteScope($conn, $rAll, $tplGlobal) === 'shared');
        ok('POSITIVE CONTROL: all-reach analyst may write B template', replyTemplateWriteScope($conn, $rAll, $tplB) === 'shared');
    }

    echo "\nEnd-to-end effect (what the endpoints would then execute):\n";
    // Probe: with scope 'shared' the endpoints proceed to UPDATE/DELETE with
    // only a capability check. Desired: the write never becomes reachable.
    // (Executed in-transaction; always rolled back.)
    $writable = ($scopeB !== null);
    if ($writable) {
        $conn->prepare("UPDATE ticket_reply_templates SET body = 'ZZ-ISOL hijacked' WHERE id = ?")->execute([$tplB]);
    }
    $body = (string)$conn->query("SELECT body FROM ticket_reply_templates WHERE id = {$tplB}")->fetchColumn();
    ok("tenant-B template body is untouched by tenant-A analyst", $body !== 'ZZ-ISOL hijacked', "body now reads '{$body}'");
} catch (Throwable $e) {
    ok('the run completed', false, get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
} finally {
    if (isset($_SESSION['active_tenant_id'])) unset($_SESSION['active_tenant_id']);
    if (isset($_SESSION['active_tenant_all'])) unset($_SESSION['active_tenant_all']);
    if ($conn->inTransaction()) $conn->rollBack();
}

$left = 0;
try {
    $left += (int)$conn->query("SELECT COUNT(*) FROM ticket_reply_templates WHERE name LIKE 'ZZ-ISOL tpl % {$uniq}'")->fetchColumn();
    $left += (int)$conn->query("SELECT COUNT(*) FROM tenants WHERE slug IN ('zz-isol-a-{$uniq}', 'zz-isol-b-{$uniq}')")->fetchColumn();
} catch (Throwable $e) { /* count itself failed — report below */ }
ok('nothing survived the run (rolled back)', $left === 0, "{$left} test rows left");

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail ? 1 : 0);
