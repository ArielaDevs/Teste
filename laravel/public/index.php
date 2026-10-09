<?php

define('LARAVEL_START', microtime(true));

$autoload = __DIR__ . '/../vendor/autoload.php';
if (!is_file($autoload)) {
    http_response_code(503);
    header('Content-Type: text/plain; charset=UTF-8');
    echo "Laravel scaffold exists but dependencies are not installed yet.\n";
    echo "Run: cd /home/runner/work/Teste/Teste/laravel && composer install\n";
    exit;
}

require $autoload;

$app = require_once __DIR__ . '/../bootstrap/app.php';

$app->handleRequest(Illuminate\Http\Request::capture());
