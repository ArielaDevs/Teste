<?php

namespace App\Http\Middleware;

use App\Support\LegacySessionBridge;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;

class AttachLegacyAnalystContext
{
    public function handle(Request $request, Closure $next)
    {
        $context = LegacySessionBridge::readAnalystContext($request);

        if (is_array($context)) {
            $request->attributes->set('legacy_analyst', $context);
            View::share('legacyAnalyst', $context);
        }

        return $next($request);
    }
}
