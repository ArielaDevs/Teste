<?php

namespace App\Http\Controllers;

use App\Support\LegacyBridge;
use Illuminate\Http\RedirectResponse;

class LegacyBridgeController extends Controller
{
    public function redirect(string $module, ?string $path = null): RedirectResponse
    {
        $target = LegacyBridge::modulePath($module, $path);

        abort_if($target === null, 404);

        return redirect($target);
    }
}
