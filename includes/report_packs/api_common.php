<?php
/**
 * Report Packs: what every api/reporting/packs/ endpoint shares. Each endpoint
 * still calls requireModuleAccessJson('reporting') itself, so the guard is
 * visible in the file (and tests/module-access-coverage.php can see it).
 */

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../functions.php';
require_once __DIR__ . '/../i18n.php';
require_once __DIR__ . '/../timezone.php';
require_once __DIR__ . '/access.php';
require_once __DIR__ . '/design.php';

header('Content-Type: application/json');

function rpOut(array $payload, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function rpFail(string $message, int $code = 400): void
{
    rpOut(['success' => false, 'error' => $message], $code);
}

/** The JSON body of a POST. */
function rpInput(): array
{
    $in = json_decode(file_get_contents('php://input'), true);
    return is_array($in) ? $in : [];
}

/** Bootstrap i18n and the viewer's timezone/date format, for labels and dates. */
function rpInitLocale(PDO $conn): void
{
    I18n::initFromSession();
    if (class_exists('Tz')) Tz::init();
    if (class_exists('DateFmt')) DateFmt::init($conn);
}
