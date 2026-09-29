<?php
/**
 * Domains — the start of every api/domains/*.php endpoint.
 *
 * Signed in, allowed into the module, JSON out, a connection and an actor.
 * Kept here rather than repeated fifteen times so a guard cannot be forgotten
 * on the sixteenth. Capabilities (settings tabs, auth codes) are checked by the
 * endpoint that needs them, AFTER this, with domainRequireCap().
 *
 * Lives in includes/ so it cannot be requested on its own.
 */

session_start(['read_and_close' => true]);
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../functions.php';
require_once __DIR__ . '/../rbac.php';
require_once __DIR__ . '/../services/domains.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}
requireModuleAccessJson('domains');

$conn      = connectToDatabase();
$analystId = (int)$_SESSION['analyst_id'];
$ctx       = ActorContext::fromSession($conn);

/** The decoded JSON body (never null). */
function domainApiBody(): array
{
    static $b = null;
    if ($b === null) {
        $raw = file_get_contents('php://input');
        $b = $raw !== '' ? (json_decode($raw, true) ?: []) : [];
    }
    return $b;
}

function domainApiOk(array $data = []): void
{
    echo json_encode(['success' => true] + $data);
    exit;
}

function domainApiFail(string $message, int $status = 200): void
{
    if ($status !== 200) http_response_code($status);
    echo json_encode(['success' => false, 'error' => $message]);
    exit;
}

function domainRequireCap(PDO $conn, string $cap): void
{
    requireCapabilityJson($cap, $conn);
}

function domainHasCap(PDO $conn, int $analystId, string $cap): bool
{
    try { return analystHasCapability($conn, $analystId, $cap); } catch (Throwable $e) { return false; }
}

/**
 * The company a NEW record belongs to: the one the request names (checked
 * against what this analyst may reach), else the analyst's active company.
 */
function domainApiTenantForCreate(PDO $conn, int $analystId, $requested): int
{
    if ($requested !== null && $requested !== '' && (int)$requested > 0 && isMultiTenant($conn)) {
        $t = (int)$requested;
        if (!getTenantById($conn, $t) || !analystCanAccessTenant($conn, $analystId, $t)) {
            domainApiFail('You cannot add domains for that company.');
        }
        return $t;
    }
    return getActiveTenantId($conn, $analystId);
}

/** Run a service call and turn any failure into the UI's {success:false} shape. */
function domainApiRun(callable $fn): void
{
    try {
        $fn();
    } catch (ServiceError $e) {
        domainApiFail($e->getMessage());
    } catch (Throwable $e) {
        error_log('domains api: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
        domainApiFail('Something went wrong: ' . $e->getMessage());
    }
}
