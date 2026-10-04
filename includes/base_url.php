<?php
/**
 * BASE_URL - the app's web path, e.g. '/freeitsm-app/' or '/'.
 *
 * 🔴 WHY THIS LIVES HERE AND NOT ONLY IN config.php. config.php is the
 * OPERATOR'S file: every install edits it once and keeps that copy, and Docker
 * copies docker/config.php over it. Anything the app cannot run without must
 * therefore come from shipped code too (GH #129 - dbConnectionOptions(); the
 * sslApplyCurl() recurrence - see includes/db.php and includes/ssl.php).
 *
 * BASE_URL was the last such thing: 300+ uses with no fallback, and on PHP 8 an
 * undefined constant is a thrown Error, so a config.php without the block meant
 * every page failing at once. No released config.php lacks it (it shipped before
 * v1.0.0), but a hand-assembled or pre-1.0.0 copy can.
 *
 * Loaded by includes/functions.php before anything else, and directly by the
 * few entry points that do not load functions.php. The operator's own value
 * always wins: this defines BASE_URL only when nothing has yet.
 *
 * Auto-detected from where the app sits under the web server's DOCUMENT_ROOT:
 *   http://localhost/freeitsm-app/   ->  '/freeitsm-app/'
 *   https://itsm.example.com/        ->  '/'
 */

if (!function_exists('appBaseUrlDetect')) {
    function appBaseUrlDetect(): string
    {
        $appRoot = str_replace('\\', '/', (string)realpath(dirname(__DIR__)));
        $docRoot = str_replace('\\', '/', (string)realpath($_SERVER['DOCUMENT_ROOT'] ?? ''));
        $rel = '';
        if ($docRoot !== '' && strpos($appRoot, $docRoot) === 0) {
            $rel = substr($appRoot, strlen($docRoot));
        }
        $rel = '/' . trim($rel, '/') . '/';
        return $rel === '//' ? '/' : $rel;     // '//' = deployed at the document root
    }
}

if (!defined('BASE_URL')) {
    define('BASE_URL', appBaseUrlDetect());
}
