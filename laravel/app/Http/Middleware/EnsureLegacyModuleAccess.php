<?php

namespace App\Http\Middleware;

use App\Support\ModuleCatalog;
use Closure;
use Illuminate\Http\Request;

class EnsureLegacyModuleAccess
{
    public function __construct(private readonly ModuleCatalog $catalog)
    {
    }

    public function handle(Request $request, Closure $next)
    {
        $routeModule = (string) $request->route('module', '');
        $definition = $this->catalog->find($routeModule);

        abort_if($definition === null, 404);

        $context = $request->attributes->get('legacy_analyst');
        abort_unless($this->catalog->canAccess((string) $definition['module'], is_array($context) ? $context : null), 403);

        $request->attributes->set('resolved_module', $definition);

        return $next($request);
    }
}
