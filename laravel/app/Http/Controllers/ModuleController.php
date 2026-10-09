<?php

namespace App\Http\Controllers;

use App\Support\ModuleCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ModuleController extends Controller
{
    public function __construct(private readonly ModuleCatalog $catalog)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $context = $request->attributes->get('legacy_analyst');

        $modules = $this->catalog->all();
        if (is_array($context)) {
            $modules = array_values(array_filter(
                $modules,
                fn (array $module): bool => $this->catalog->canAccess((string) $module['module'], $context)
            ));
        }

        return response()->json([
            'phase' => 3,
            'authenticated' => is_array($context),
            'modules' => array_map(function (array $module): array {
                $module['legacy_redirect_url'] = route('legacy.redirect', [
                    'module' => $module['module'],
                ], false);

                return $module;
            }, $modules),
        ]);
    }

    public function show(Request $request, string $module): JsonResponse
    {
        $resolved = $request->attributes->get('resolved_module');

        $definition = is_array($resolved)
            ? $resolved
            : $this->catalog->find($module);

        abort_if($definition === null, 404);

        return response()->json([
            'phase' => 3,
            'module' => [
                ...$definition,
                'legacy_redirect_url' => route('legacy.redirect', [
                    'module' => $definition['module'],
                ], false),
            ],
        ]);
    }
}
