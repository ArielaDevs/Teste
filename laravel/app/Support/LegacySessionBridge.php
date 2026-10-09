<?php

namespace App\Support;

use Illuminate\Http\Request;

class LegacySessionBridge
{
    public static function readAnalystContext(Request $request): ?array
    {
        $cookieName = (string) config('legacy.session.cookie', 'PHPSESSID');
        $sessionId = (string) $request->cookie($cookieName, '');

        if ($sessionId === '' || !preg_match('/^[A-Za-z0-9,-]+$/', $sessionId)) {
            return null;
        }

        $filePath = self::sessionFilePath($sessionId);
        if ($filePath === null || !is_readable($filePath)) {
            return null;
        }

        $raw = @file_get_contents($filePath);
        if (!is_string($raw) || $raw === '') {
            return null;
        }

        $analystId = self::extractValue($raw, 'analyst_id');
        if (!is_int($analystId) || $analystId <= 0) {
            return null;
        }

        $analystName = self::extractValue($raw, 'analyst_name');
        $allowedModules = self::extractValue($raw, 'allowed_modules');
        $isAdmin = self::extractValue($raw, 'is_admin');

        return [
            'analyst_id' => $analystId,
            'analyst_name' => is_string($analystName) ? $analystName : null,
            'allowed_modules' => self::normalizeModuleList($allowedModules),
            'is_admin' => (bool) $isAdmin,
            'session_cookie' => $cookieName,
            'session_id' => $sessionId,
        ];
    }

    private static function normalizeModuleList(mixed $modules): array
    {
        if (!is_array($modules)) {
            return [];
        }

        $result = [];
        foreach ($modules as $module) {
            if (is_string($module) && $module !== '') {
                $result[] = $module;
            }
        }

        return array_values(array_unique($result));
    }

    private static function sessionFilePath(string $sessionId): ?string
    {
        $configured = (string) config('legacy.session.path', '');
        $savePath = $configured !== '' ? $configured : (string) ini_get('session.save_path');

        if ($savePath === '') {
            return null;
        }

        // session.save_path may contain handler options like "2;/var/lib/php/sessions"
        if (str_contains($savePath, ';')) {
            $parts = explode(';', $savePath);
            $savePath = (string) end($parts);
        }

        $savePath = trim($savePath);
        if ($savePath === '') {
            return null;
        }

        $prefix = (string) config('legacy.session.file_prefix', 'sess_');

        return rtrim($savePath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $prefix . $sessionId;
    }

    private static function extractValue(string $rawSession, string $key): mixed
    {
        $needle = $key . '|';
        $offset = strpos($rawSession, $needle);

        if ($offset === false) {
            return null;
        }

        $serialized = substr($rawSession, $offset + strlen($needle));
        if (!is_string($serialized) || $serialized === '') {
            return null;
        }

        $length = self::serializedLength($serialized);
        if ($length === null) {
            return null;
        }

        $payload = substr($serialized, 0, $length);
        if (!is_string($payload) || $payload === '') {
            return null;
        }

        return @unserialize($payload, ['allowed_classes' => false]);
    }

    private static function serializedLength(string $input): ?int
    {
        $first = $input[0] ?? '';

        return match ($first) {
            'N' => str_starts_with($input, 'N;') ? 2 : null,
            'b', 'i', 'd' => self::simpleScalarLength($input),
            's' => self::stringLength($input),
            'a' => self::arrayLength($input),
            default => null,
        };
    }

    private static function simpleScalarLength(string $input): ?int
    {
        $end = strpos($input, ';');
        if ($end === false) {
            return null;
        }

        return $end + 1;
    }

    private static function stringLength(string $input): ?int
    {
        if (!preg_match('/^s:(\d+):"/A', $input, $match)) {
            return null;
        }

        $declared = (int) $match[1];
        $headLength = strlen($match[0]);
        $total = $headLength + $declared + 2; // closing " and ;

        if (strlen($input) < $total) {
            return null;
        }

        if (substr($input, $headLength + $declared, 2) !== '";') {
            return null;
        }

        return $total;
    }

    private static function arrayLength(string $input): ?int
    {
        if (!preg_match('/^a:(\d+):\{/A', $input, $match)) {
            return null;
        }

        $items = (int) $match[1] * 2;
        $cursor = strlen($match[0]);

        for ($i = 0; $i < $items; $i++) {
            $chunk = substr($input, $cursor);
            if (!is_string($chunk) || $chunk === '') {
                return null;
            }

            $segmentLength = self::serializedLength($chunk);
            if ($segmentLength === null) {
                return null;
            }

            $cursor += $segmentLength;
        }

        if (($input[$cursor] ?? '') !== '}') {
            return null;
        }

        return $cursor + 1;
    }
}
