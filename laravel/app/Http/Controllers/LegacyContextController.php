<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LegacyContextController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $context = $request->attributes->get('legacy_analyst');

        if (!is_array($context)) {
            return response()->json([
                'authenticated' => false,
                'source' => 'legacy-session-bridge',
            ]);
        }

        return response()->json([
            'authenticated' => true,
            'source' => 'legacy-session-bridge',
            'analyst' => [
                'id' => $context['analyst_id'] ?? null,
                'name' => $context['analyst_name'] ?? null,
                'is_admin' => (bool) ($context['is_admin'] ?? false),
                'allowed_modules' => $context['allowed_modules'] ?? [],
            ],
        ]);
    }
}
