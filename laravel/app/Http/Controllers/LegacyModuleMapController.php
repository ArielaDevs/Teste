<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;

class LegacyModuleMapController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'modules' => array_values((array) config('legacy.modules', [])),
            'aliases' => (array) config('legacy.aliases', []),
        ]);
    }
}
