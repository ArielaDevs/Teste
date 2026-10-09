<?php

use App\Http\Middleware\AttachLegacyAnalystContext;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Foundation\Application;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        api: __DIR__ . '/../routes/api.php'
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            AttachLegacyAnalystContext::class,
        ]);
    })
    ->create();
