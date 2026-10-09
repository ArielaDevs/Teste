<?php

namespace App\Http\Controllers;

use App\Support\ModuleCatalog;
use Illuminate\Http\JsonResponse;

class LegacyModuleMapController extends Controller
{
    public function __construct(private readonly ModuleCatalog $catalog)
    {
    }

    public function index(): JsonResponse
    {
        return response()->json([
            'phase' => 3,
            'modules' => array_values((array) config('legacy.modules', [])),
            'aliases' => (array) config('legacy.aliases', []),
            'catalog' => $this->catalog->all(),
        ]);
    }
}
