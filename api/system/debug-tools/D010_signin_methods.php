<?php
/**
 * Debug Tool D010 — Sign-in methods, and how you are actually signed in
 *
 * Answers three questions that get confused with each other constantly:
 *
 *   1. What sign-in methods exist, and what state is each one in?
 *   2. What will the login page actually OFFER a visitor right now?
 *   3. How did the person running this diagnostic get in?
 *
 * (3) matters more than it looks. An administrator looking at the Authentication
 * screen has, by definition, already signed in — so "nobody can sign in" and
 * "everything is fine" look identical from that chair. Naming the route they
 * personally used turns an argument into a fact.
 *
 * Written to be SENT TO SOMEBODY. Every username, email address, bind account and
 * DN is masked; secrets are reported present/absent and never printed. All the
 * diagnostic comparisons are computed on the real values and reported as
 * YES/NO/OK, so masking costs nothing.
 *
 * Read-only: makes no network calls and writes nothing.
 *
 * Output: plain text, section-delimited with === HEADERS ===.
 */

@session_start();

$DIAG_ID   = 'D010';
$DIAG_NAME = 'Sign-in methods, and how you are signed in';

require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../includes/functions.php';

// Debug tools are administrators-only (issue #34). Fail closed.
try {
    $__dbgAdmin = !empty($_SESSION['analyst_id']) && analystIsAdmin(connectToDatabase(), (int)$_SESSION['analyst_id']);
} catch (Throwable $e) {
    $__dbgAdmin = false;
}
if (!$__dbgAdmin) {
    http_response_code(403);
    if (!headers_sent()) header('Content-Type: text/plain; charset=utf-8');
    echo "Administrator access required.\n";
    exit;
}

if (!headers_sent()) header('Content-Type: text/plain; charset=utf-8');

$sections = [];
function addSection(&$sections, $title, $body) {
    if (is_array($body)) $body = implode("\n", $body);
    $sections[] = "=== {$title} ===\n" . rtrim($body, "\n");
}
function yn($v) { return $v ? 'YES' : 'NO'; }

// ---- masking ------------------------------------------------------------
// This report is made to be sent to somebody else, so nothing that names a
// human survives into the output. Kept just legible enough that the person who
// generated it can recognise their own row.

function mask($s) {
    $s = (string)$s;
    if ($s === '') return '(empty)';
    if (strlen($s) <= 3) return str_repeat('*', strlen($s));
    if (strlen($s) <= 8) return substr($s, 0, 1) . str_repeat('*', strlen($s) - 1);
    return substr($s, 0, 2) . str_repeat('*', 6) . substr($s, -1);
}

/** Local part masked, domain kept — the domain is the diagnostic half. */
function maskEmail($s) {
    $s = trim((string)$s);
    if ($s === '') return '(empty)';
    $at = strrpos($s, '@');
    if ($at === false) return mask($s);
    return mask(substr($s, 0, $at)) . '@' . substr($s, $at + 1);
}

/**
 * A DN carries a person's name in its leading component and the directory's
 * shape in the rest. The shape is what you need to diagnose, the name is not —
 * so mask every attribute VALUE and keep every attribute NAME and the commas.
 * "CN=svc_freeitsm,OU=Service Accounts,DC=cnss,DC=local"
 *   -> "CN=sv******m,OU=Se******s,DC=cn***,DC=lo***"
 */
function maskDn($s) {
    $s = trim((string)$s);
    if ($s === '') return '(not set)';
    $parts = preg_split('/(?<!\\\\),/', $s);
    foreach ($parts as &$p) {
        if (strpos($p, '=') === false) { $p = mask($p); continue; }
        list($k, $v) = explode('=', $p, 2);
        $p = trim($k) . '=' . mask(trim($v));
    }
    return implode(',', $parts);
}

/**
 * A tenant GUID in an issuer URL names the ORGANISATION, which is more than this
 * report needs to be useful — and D003 already masks them for the same reason.
 * The rest of the URL (which IdP, which realm shape) is the diagnostic half.
 */
function maskGuids($s) {
    return preg_replace_callback('/[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}/', function ($m) {
        return substr($m[0], 0, 8) . '…' . substr($m[0], -4);
    }, (string)$s);
}

/** A hostname is infrastructure, not a person — but strip any user@ prefix. */
function maskHost($s) {
    $s = trim((string)$s);
    if ($s === '') return '(not set)';
    $at = strrpos($s, '@');
    return $at === false ? $s : mask(substr($s, 0, $at)) . '@' . substr($s, $at + 1);
}

function emit_and_exit($sections) {
    echo implode("\n\n", $sections) . "\n";
    exit;
}

// ---- 1. HEADER ----------------------------------------------------------

$now = gmdate('Y-m-d H:i:s') . ' UTC';
addSection($sections, 'REPORT HEADER', [
    "Diagnostic   : {$DIAG_ID} — {$DIAG_NAME}",
    "Generated    : {$now}",
    "PHP          : " . PHP_VERSION . ' on ' . PHP_OS,
    "Server       : " . ($_SERVER['SERVER_SOFTWARE'] ?? '(unknown)'),
    "Mode         : READ-ONLY. Safe to send on — usernames, email addresses, bind",
    "               accounts and DNs are masked, and secrets are reported only as",
    "               present or absent. Every check below is computed on the real",
    "               values, so masking hides nothing diagnostic.",
]);

try {
    $conn = connectToDatabase();
} catch (Throwable $e) {
    addSection($sections, 'DATABASE', 'CANNOT CONNECT: ' . $e->getMessage());
    emit_and_exit($sections);
}

// ---- 2. SCHEMA READINESS ------------------------------------------------
// An installation older than a feature has no column for it, and every question
// below then answers "no" for a reason that has nothing to do with configuration.

function colExists(PDO $c, $table, $col) {
    try {
        $s = $c->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS
                          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
        $s->execute([$table, $col]);
        return (int)$s->fetchColumn() > 0;
    } catch (Throwable $e) { return false; }
}
function tableExists(PDO $c, $table) {
    try {
        $s = $c->prepare("SELECT COUNT(*) FROM information_schema.TABLES
                          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
        $s->execute([$table]);
        return (int)$s->fetchColumn() > 0;
    } catch (Throwable $e) { return false; }
}

$hasProviders = tableExists($conn, 'auth_providers');
$schema = ["auth_providers table            : " . ($hasProviders ? 'OK' : 'MISSING — nothing below can work')];

$syncCols = ['sync_enabled', 'sync_base_dn', 'sync_ou_includes', 'sync_ou_excludes', 'sync_brake_percent', 'sync_last_run_datetime'];
$missingSync = [];
if ($hasProviders) {
    foreach (['protocol', 'enabled', 'ldap_host', 'ldap_base_dn', 'auto_create_users', 'auto_create_analysts', 'profile_sync_mode'] as $c) {
        $schema[] = str_pad("  column {$c}", 33) . ': ' . (colExists($conn, 'auth_providers', $c) ? 'OK' : 'MISSING');
    }
    foreach ($syncCols as $c) {
        $ok = colExists($conn, 'auth_providers', $c);
        if (!$ok) $missingSync[] = $c;
        $schema[] = str_pad("  column {$c}", 33) . ': ' . ($ok ? 'OK' : 'MISSING');
    }
}
$schema[] = "user_sso_identities table       : " . (tableExists($conn, 'user_sso_identities') ? 'OK' : 'MISSING');
$schema[] = "directory_sync_runs table       : " . (tableExists($conn, 'directory_sync_runs') ? 'OK' : 'MISSING (import history unavailable)');
if ($missingSync) {
    $schema[] = '';
    $schema[] = '>> This installation PREDATES directory sync (' . count($missingSync) . ' column(s) missing).';
    $schema[] = '>> Update FreeITSM, then run System > Debug Tools > Database Verification.';
    $schema[] = '>> Until then no directory can import people, however it is configured.';
}
addSection($sections, 'SCHEMA READINESS', $schema);

// ---- 3. HOW YOU ARE SIGNED IN -------------------------------------------
// The point of the whole tool: the person reading this is already inside, so
// their own route is the one piece of evidence they cannot get from the screen.

$me = [];
$analystId = (int)($_SESSION['analyst_id'] ?? 0);
$me[] = "Session analyst id     : " . ($analystId ?: '(none)');
$me[] = "Session name           : " . mask($_SESSION['analyst_name'] ?? '');
$me[] = "Session username       : " . mask($_SESSION['analyst_username'] ?? '');
$me[] = "Session email          : " . maskEmail($_SESSION['analyst_email'] ?? '');
$me[] = "Administrator          : " . yn(!empty($_SESSION['is_admin']));

// Which session keys are present tells you which login path wrote them.
$ssoKeys = [];
foreach ($_SESSION as $k => $v) {
    if (preg_match('/sso|oidc|ldap|provider|auth_method|login_method/i', (string)$k)) {
        $ssoKeys[] = $k . ' = ' . (is_scalar($v) ? mask((string)$v) : '(' . gettype($v) . ')');
    }
}
$me[] = "SSO-ish session keys   : " . ($ssoKeys ? implode(' | ', $ssoKeys) : '(none — looks like a local password login)');

if ($analystId && tableExists($conn, 'analysts')) {
    try {
        $s = $conn->prepare("SELECT * FROM analysts WHERE id = ?");
        $s->execute([$analystId]);
        $a = $s->fetch(PDO::FETCH_ASSOC) ?: [];
        if ($a) {
            $hasPw = !empty($a['password_hash']);
            $me[] = '';
            $me[] = "Your analyst record:";
            $me[] = "  Username             : " . mask($a['username'] ?? '');
            $me[] = "  Email                : " . maskEmail($a['email'] ?? '');
            $me[] = "  Active               : " . yn(!isset($a['is_active']) || $a['is_active']);
            $me[] = "  Local password set   : " . yn($hasPw) . ($hasPw ? '' : '  <-- no password: this account can ONLY arrive via a provider');
            if (array_key_exists('auth_provider_id', $a)) {
                $pid = $a['auth_provider_id'];
                $me[] = "  Pinned to provider   : " . ($pid ? ('#' . (int)$pid) : 'no (may use any enabled method)');
            }
            if (array_key_exists('totp_enabled', $a)) {
                $me[] = "  Two-factor           : " . yn(!empty($a['totp_enabled']));
            }
            $me[] = '';
            $me[] = "  ROUTE YOU USED       : " . ($ssoKeys
                ? 'a provider (session carries provider keys)'
                : ($hasPw ? 'local username + password' : 'a provider (no local password exists on this account)'));
        }
    } catch (Throwable $e) {
        $me[] = 'Could not read your analyst record: ' . $e->getMessage();
    }
}
addSection($sections, 'HOW YOU ARE SIGNED IN', $me);

// ---- 4. WHAT THE LOGIN PAGE WILL OFFER ----------------------------------
// Mirrors auth/login.php: sso_enabled gates the provider buttons, and
// local_login_enabled ABSENT means ON — the safe reading, deliberately.

$cfg = [];
try {
    foreach ($conn->query("SELECT setting_key, setting_value FROM system_settings
                           WHERE setting_key IN ('sso_enabled','local_login_enabled')") as $r) {
        $cfg[$r['setting_key']] = $r['setting_value'];
    }
} catch (Throwable $e) { /* reported as absent below */ }

$ssoOn    = ($cfg['sso_enabled'] ?? '0') === '1';
$localOn  = ($cfg['local_login_enabled'] ?? '1') !== '0';
$ssoRaw   = array_key_exists('sso_enabled', $cfg) ? "'" . $cfg['sso_enabled'] . "'" : 'NOT STORED (treated as off)';
$localRaw = array_key_exists('local_login_enabled', $cfg) ? "'" . $cfg['local_login_enabled'] . "'" : 'NOT STORED (treated as ON)';

$enabledProviders = 0;
if ($hasProviders) {
    try { $enabledProviders = (int)$conn->query("SELECT COUNT(*) FROM auth_providers WHERE enabled = 1")->fetchColumn(); }
    catch (Throwable $e) { $enabledProviders = 0; }
}

$g = [
    "sso_enabled (stored)        : {$ssoRaw}   -> single sign-on " . ($ssoOn ? 'ON' : 'OFF'),
    "local_login_enabled (stored): {$localRaw}   -> password form " . ($localOn ? 'ON' : 'OFF'),
    "Providers marked Enabled    : {$enabledProviders}",
    '',
    'So the login page will show:',
    '  Password form             : ' . yn($localOn),
    '  Provider buttons          : ' . yn($ssoOn && $enabledProviders > 0)
        . (!$ssoOn && $enabledProviders > 0 ? '   (providers exist but the master switch is off)' : ''),
];
if (!$localOn && !(($ssoOn) && $enabledProviders > 0)) {
    $g[] = '';
    $g[] = '>> WARNING: the login page currently offers NO way in.';
    $g[] = '>> Break-glass: /auth/login.php?local=1 still reaches the password form.';
    $g[] = '>> Anyone already signed in (including whoever ran this) stays signed in until';
    $g[] = '>> their session expires, which is why this can go unnoticed for days.';
}
if (!$ssoOn && $enabledProviders > 0) {
    $g[] = '';
    $g[] = 'NOTE: single sign-on being off does NOT stop a directory importing people.';
    $g[] = 'Importing is governed by the provider\'s own sync settings — see D011.';
}
addSection($sections, 'WHAT THE LOGIN PAGE WILL OFFER', $g);

// ---- 5. EVERY SIGN-IN METHOD --------------------------------------------

if (!$hasProviders) {
    addSection($sections, 'SIGN-IN METHODS', 'auth_providers table is missing — cannot list.');
    emit_and_exit($sections);
}

try {
    $providers = $conn->query("SELECT * FROM auth_providers ORDER BY sort_order, id")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    addSection($sections, 'SIGN-IN METHODS', 'Query failed: ' . $e->getMessage());
    emit_and_exit($sections);
}

if (!$providers) {
    addSection($sections, 'SIGN-IN METHODS', 'None configured. Local accounts only.');
} else {
    $blocks = ['Total: ' . count($providers), ''];
    foreach ($providers as $p) {
        // Two different questions, deliberately answered by two different tests.
        // WHAT IT IS: forgiving, so a directory stored as 'LDAP ' is still described
        // as a directory and its connection fields are printed.
        // WHAT THE SCREEN DOES: strict, because system/sso/index.php compares with
        // === and a near-miss silently renders the row as OIDC. Reporting the
        // forgiving answer as though it were the screen's would hide the fault.
        $isLdap       = strtolower(trim((string)($p['protocol'] ?? ''))) === 'ldap';
        $screenSaysLdap = ($p['protocol'] ?? null) === 'ldap';
        $b = [];
        $b[] = '--- Provider #' . (int)$p['id'] . ' -------------------------------------------';
        $b[] = '  Display name         : ' . ($p['display_name'] ?? '(none)');   // a label, not a person
        $b[] = '  Protocol             : ' . strtoupper((string)($p['protocol'] ?? '?'));
        $b[] = '  Enabled              : ' . yn(!empty($p['enabled']));
        $b[] = '  Sort order           : ' . (int)($p['sort_order'] ?? 0);
        if (array_key_exists('tenant_id', $p)) {
            $b[] = '  Company              : ' . ($p['tenant_id'] ? ('#' . (int)$p['tenant_id']) : 'all companies');
        }
        $b[] = '  Create portal requesters on first sign-in (JIT): ' . yn(!empty($p['auto_create_users']));
        // Split out of the switch above in discussion #155. Absent until
        // Database Verification adds it, when the old switch still decides.
        $b[] = '  Create analysts on first sign-in (JIT)         : '
             . (array_key_exists('auto_create_analysts', $p)
                ? yn(!empty($p['auto_create_analysts']))
                : yn(!empty($p['auto_create_users'])) . ' (column not added yet - run Database Verification)');
        if (($p['protocol'] ?? '') === 'oidc' && array_key_exists('profile_sync_mode', $p)) {
            $b[] = '  No analyst account on the analyst login        : ' . ($p['analyst_fallback_mode'] ?? '?');
            $b[] = '  Profile sync                                   : ' . $p['profile_sync_mode'];
        }

        if ($isLdap) {
            $b[] = '  Host                 : ' . maskHost($p['ldap_host'] ?? '') . ':' . ((int)($p['ldap_port'] ?? 0) ?: '(no port)');
            $b[] = '  Encryption           : ' . (($p['ldap_encryption'] ?? '') !== '' ? $p['ldap_encryption'] : '(none — plain LDAP)');
            $b[] = '  Bind account         : ' . maskDn($p['ldap_bind_dn'] ?? '');
            $b[] = '  Bind password        : ' . (!empty($p['ldap_bind_password']) ? 'SET (not shown)' : 'NOT SET (anonymous bind)');
            $b[] = '  Sign-in base DN      : ' . maskDn($p['ldap_base_dn'] ?? '');
            $b[] = '  User filter          : ' . (($p['ldap_user_filter'] ?? '') !== '' ? $p['ldap_user_filter'] : '(default)');
            $b[] = '  Username attribute   : ' . (($p['ldap_attr_username'] ?? '') ?: '(unset -> flavour default)');
            $b[] = '  Unique-id attribute  : ' . (($p['ldap_attr_guid'] ?? '') ?: '(unset -> flavour default)');
            $b[] = '  Email attribute      : ' . (($p['ldap_attr_email'] ?? '') ?: '(unset -> flavour default)');
            $b[] = '  Analyst group        : ' . maskDn($p['ldap_analyst_group'] ?? '');
            $b[] = '  User group           : ' . maskDn($p['ldap_user_group'] ?? '');
            if (!$missingSync) {
                $b[] = '  Directory sync       : ' . (!empty($p['sync_enabled']) ? 'ON' : 'OFF') . '   (why: run D011)';
                $b[] = '  Last import          : ' . (($p['sync_last_run_datetime'] ?? '') ?: 'never')
                     . (isset($p['sync_last_count']) && $p['sync_last_count'] !== null ? ('  (' . (int)$p['sync_last_count'] . ' found)') : '');
            }
            $b[] = '  Actions column shows : ' . ($screenSaysLdap
                ? 'Configure  (LDAP rows link to provider.php)'
                : 'Edit');
            if (!$screenSaysLdap) {
                $b[] = '  >> This IS a directory, but protocol is not stored as exactly';
                $b[] = '     lower-case \'ldap\' (it is ' . var_export($p['protocol'], true) . '), so the screen';
                $b[] = '     labels the row OIDC and the importing tabs cannot be opened. See D011.';
            }
        } else {
            $b[] = '  Issuer               : ' . (($p['issuer_url'] ?? '') !== '' ? maskGuids($p['issuer_url']) : '(not set)');
            $b[] = '  Client ID            : ' . mask($p['client_id'] ?? '');
            $b[] = '  Client secret        : ' . (!empty($p['client_secret']) ? 'SET (not shown)' : 'NOT SET');
            $b[] = '  Scopes               : ' . (($p['scopes'] ?? '') ?: '(default)');
            $b[] = '  Actions column shows : Edit  (OIDC keeps the dialog)';
        }

        // The bit people actually want: will this method work right now?
        $blockers = [];
        if (empty($p['enabled']))                   $blockers[] = 'the provider is not Enabled';
        if (!$ssoOn)                                $blockers[] = 'single sign-on is off globally';
        if ($isLdap && trim((string)($p['ldap_host'] ?? '')) === '')     $blockers[] = 'no host set';
        if (!$isLdap && trim((string)($p['issuer_url'] ?? '')) === '')   $blockers[] = 'no issuer set';
        if (!$isLdap && empty($p['client_secret']))                      $blockers[] = 'no client secret';
        $b[] = '  CAN PEOPLE SIGN IN WITH IT NOW? ' . ($blockers ? 'NO — ' . implode('; ', $blockers) : 'YES');
        $b[] = '';
        $blocks = array_merge($blocks, $b);
    }
    addSection($sections, 'SIGN-IN METHODS', $blocks);
}

// ---- 6. WHO CAN ACTUALLY GET IN -----------------------------------------
// Counts only. A method being enabled says nothing about whether anybody is
// positioned to use it.

$who = [];
try {
    $who[] = 'Analysts:';
    $who[] = '  Total                    : ' . (int)$conn->query("SELECT COUNT(*) FROM analysts")->fetchColumn();
    $who[] = '  Active                   : ' . (int)$conn->query("SELECT COUNT(*) FROM analysts WHERE is_active = 1")->fetchColumn();
    $who[] = '  With a local password    : ' . (int)$conn->query("SELECT COUNT(*) FROM analysts WHERE password_hash IS NOT NULL AND password_hash <> ''")->fetchColumn();
    $who[] = '  Administrators           : ' . (int)$conn->query("SELECT COUNT(*) FROM analysts WHERE is_admin = 1")->fetchColumn();
    if (colExists($conn, 'analysts', 'auth_provider_id')) {
        $who[] = '  Pinned to a provider     : ' . (int)$conn->query("SELECT COUNT(*) FROM analysts WHERE auth_provider_id IS NOT NULL")->fetchColumn();
    }
} catch (Throwable $e) { $who[] = 'analysts: ' . $e->getMessage(); }

try {
    $who[] = '';
    $who[] = 'Self-service users:';
    $who[] = '  Total                    : ' . (int)$conn->query("SELECT COUNT(*) FROM users")->fetchColumn();
    $who[] = '  Active                   : ' . (int)$conn->query("SELECT COUNT(*) FROM users WHERE is_active = 1")->fetchColumn();
    $who[] = '  With a local password    : ' . (int)$conn->query("SELECT COUNT(*) FROM users WHERE password_hash IS NOT NULL AND password_hash <> ''")->fetchColumn();
    if (colExists($conn, 'users', 'is_managed')) {
        $who[] = '  Imported from a directory: ' . (int)$conn->query("SELECT COUNT(*) FROM users WHERE is_managed = 1")->fetchColumn();
    }
    if (tableExists($conn, 'user_sso_identities')) {
        $who[] = '  With a linked SSO identity: ' . (int)$conn->query("SELECT COUNT(DISTINCT user_id) FROM user_sso_identities")->fetchColumn();
    }
} catch (Throwable $e) { $who[] = 'users: ' . $e->getMessage(); }
addSection($sections, 'WHO CAN ACTUALLY GET IN', $who);

// ---- 7. VERDICT ---------------------------------------------------------

$v = [];
if (!$localOn && !($ssoOn && $enabledProviders > 0)) {
    $v[] = 'CRITICAL: no sign-in method is currently offered. Use ?local=1 to reach the';
    $v[] = '          password form, then turn "Allow local login" back on.';
}
if (!$localOn && $ssoOn && $enabledProviders > 0) {
    $v[] = 'The password form is off, so every sign-in depends on a provider. If that';
    $v[] = 'provider stops answering, nobody gets in. ?local=1 is the break-glass.';
}
if ($localOn && !$ssoOn && $enabledProviders > 0) {
    $v[] = 'Local logins only. Providers are configured but the master switch is off —';
    $v[] = 'which is a perfectly normal setup if the directory is there to IMPORT people';
    $v[] = 'rather than to sign them in. Run D011 to check the import side.';
}
if ($localOn && $ssoOn && $enabledProviders > 0) {
    $v[] = 'Both local logins and provider sign-in are available.';
}
if ($enabledProviders === 0) {
    $v[] = 'No provider is enabled, so sign-in is local accounts only.';
}
if ($missingSync) {
    $v[] = '';
    $v[] = 'This installation predates directory sync. Update, then run Database Verification.';
}
$v[] = '';
$v[] = 'Next: D011 explains, per method, what is stopping it being used to import people.';
addSection($sections, 'VERDICT', $v);

emit_and_exit($sections);
