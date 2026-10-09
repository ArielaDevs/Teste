<?php

namespace App\Support;

class LegacyBridge
{
    public static function modulePath(string $module, ?string $path = null): ?string
    {
        $allowedModules = (array) config('legacy.modules', []);
        if (!in_array($module, $allowedModules, true)) {
            return null;
        }

        $candidate = trim($path ?: (string) config('legacy.default_entrypoint', 'index.php'), '/');
        if ($candidate === '' || str_contains($candidate, '..')) {
            return null;
        }

        $root = rtrim((string) config('legacy.root'), DIRECTORY_SEPARATOR);
        $fullPath = $root . DIRECTORY_SEPARATOR . $module . DIRECTORY_SEPARATOR . $candidate;

        if (!is_file($fullPath)) {
            return null;
        }

        return '/' . trim($module . '/' . $candidate, '/');
    }
}
