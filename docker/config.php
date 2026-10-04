<?php
/**
 * Docker Configuration for FreeITSM
 * Credentials are read from environment variables set in docker-compose.yml
 */

// Database credentials from environment
define('DB_SERVER',   getenv('DB_SERVER')   ?: 'db');
define('DB_NAME',     getenv('DB_NAME')     ?: 'freeitsm');
define('DB_USERNAME', getenv('DB_USERNAME') ?: 'freeitsm');
define('DB_PASSWORD', getenv('DB_PASSWORD') ?: 'freeitsm');

// Point to the placeholder db_config.php (keeps setup page compatible)
$db_config_path = '/var/www/html/db_config.php';
require_once($db_config_path);

// Encryption key file location: set via the ENCRYPTION_KEY_PATH environment
// variable in docker-compose.yml (defaults to /var/www/encryption_keys/freeitsm.key,
// stored on a persistent volume). Leave it to the env var here — do NOT define
// ENCRYPTION_KEY_PATH in this file, or it would override the compose setting.

// Timezone - the install's default: what an analyst sees until they choose their
// own in Preferences, and what emails sent by scheduled jobs use. Set TZ in the
// environment (docker-compose.yml), e.g. TZ=Europe/London. This used to be fixed
// at UTC, so an install whose container was set to UTC+7 still showed UTC to every
// analyst who had not picked a zone (GH #161). Dates are STORED in UTC either way.
$tz = getenv('TZ');
date_default_timezone_set(is_string($tz) && in_array($tz, timezone_identifiers_list(), true) ? $tz : 'UTC');

// Behind a reverse proxy that terminates HTTPS?
// ⚠️ The single most likely thing to need setting on this image. Almost nobody exposes
// the container directly — TLS normally ends at nginx, Traefik, Caddy or Cloudflare and
// plain HTTP is spoken to the container. PHP then sees an HTTP request, so the session
// cookie ships WITHOUT its `Secure` flag unless this is on. Set TRUST_PROXY_HTTPS=1 in
// the environment (docker-compose.yml) once such a proxy is the only route in.
//
// Off unless asked for, deliberately: X-Forwarded-Proto is a header any client can send,
// so trusting it by default would let a visitor mark their own cookie Secure over plain
// HTTP and lock themselves out of an install that has no TLS at all.
if (getenv('TRUST_PROXY_HTTPS')) {
    define('TRUST_PROXY_HTTPS', true);
}

// SSL Certificate Verification
// Single global switch for outbound HTTPS certificate verification — see
// includes/ssl.php and workflow/help-ssl.php. Leave ON in production. In the
// Docker image (Debian php:8.4-apache) the OS ships a CA bundle that libcurl
// finds automatically, so verification works out of the box with no cacert.pem.
define('SSL_VERIFY_PEER', true);

// Load the shared SSL helper (sslApplyCurl) and resolve the CA bundle. REQUIRED:
// every outbound curl handle calls sslApplyCurl(), which is defined here. This
// config.php is copied to /var/www/html/config.php, so __DIR__ is the app root.
require_once(__DIR__ . '/includes/ssl.php');
if (!defined('SSL_CA_BUNDLE')) {
    define('SSL_CA_BUNDLE', sslResolveCaBundle());
}

// Error reporting (set to 0 in production)
error_reporting(E_ALL);
ini_set('display_errors', 0);

/**
 * BASE_URL — absolute URL path prefix for the app's deployment root.
 * Auto-detected by includes/base_url.php (shipped with the app) from where the
 * app sits under the web server's DOCUMENT_ROOT. In the standard Docker image
 * the app is at /var/www/html and Apache's doc root is /var/www/html, so this
 * resolves to '/'.
 */
require_once(__DIR__ . '/includes/base_url.php');
?>
