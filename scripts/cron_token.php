<?php
/**
 * Print a cron shared-secret token in plaintext.
 *
 *   php scripts/cron_token.php              list every cron token
 *   php scripts/cron_token.php sla          print just the SLA one
 *   php scripts/cron_token.php --url        print the full HTTP URL for each
 *
 * ⚠️ WHY THIS EXISTS. The four *_cron_token values are encrypted at rest — they end in
 * _token, so isEncryptedSettingKey() covers them and Database Verify encrypts them in
 * place. Every worker decrypts correctly, so the jobs keep running, but the setup docs
 * used to say "read it with SELECT setting_value FROM system_settings", and that now
 * returns "ENC:…" — an admin pasting it into a cron URL gets a 403 from their own
 * install. This is the supported way to read one.
 *
 * CLI only, and deliberately so: this prints secrets, and the whole point of the
 * encryption is that reading the row is not enough.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script is command-line only.\n");
}

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/encryption.php';

/** cron name => [setting key, the script that reads it] */
const CRON_TOKENS = [
    'sla'         => ['sla_cron_token',         'cron/sla_breach_check.php'],
    'webhook'     => ['webhook_cron_token',     'cron/webhook_deliveries.php'],
    'workflow'    => ['workflow_cron_token',    'cron/workflow_scheduled.php'],
    'integration' => ['integration_cron_token', 'cron/integration_poll.php'],
    'domains'     => ['domain_cron_token',      'cron/domains.php'],
];

$args    = array_slice($argv, 1);
$wantUrl = in_array('--url', $args, true);
$which   = null;
foreach ($args as $a) {
    if (strpos($a, '--') !== 0) { $which = strtolower($a); break; }
}

if ($which !== null && !isset(CRON_TOKENS[$which])) {
    fwrite(STDERR, "Unknown cron '$which'. Known: " . implode(', ', array_keys(CRON_TOKENS)) . "\n");
    exit(1);
}

try {
    $conn = connectToDatabase();
} catch (Exception $e) {
    fwrite(STDERR, "Could not connect to the database: " . $e->getMessage() . "\n");
    exit(1);
}

$wanted = $which !== null ? [$which => CRON_TOKENS[$which]] : CRON_TOKENS;
$values = settingsGetDecrypted($conn, array_map(fn($p) => $p[0], array_values($wanted)));

$base = defined('BASE_URL') ? rtrim((string)BASE_URL, '/') : '';
$missing = 0;

foreach ($wanted as $name => [$key, $script]) {
    $token = $values[$key] ?? '';
    if ($token === '') {
        printf("%-12s  (not seeded — run Database Verification)\n", $name);
        $missing++;
        continue;
    }
    if ($wantUrl) {
        printf("%-12s  https://your-host%s/%s?token=%s\n", $name, $base, $script, urlencode($token));
    } else {
        printf("%-12s  %s\n", $name, $token);
    }
}

if (!$wantUrl && $missing < count($wanted)) {
    echo "\nCLI invocation needs no token at all — it is only for the HTTP endpoints.\n";
}

exit($missing > 0 && $which !== null ? 1 : 0);
