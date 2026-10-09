<?php

namespace App\Support;

use Illuminate\Support\Str;

class ModuleCatalog
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function all(): array
    {
        $modules = [];
        foreach ((array) config('legacy.modules', []) as $module) {
            if (!is_string($module) || $module === '') {
                continue;
            }

            $modules[] = $this->buildDefinition($module);
        }

        usort($modules, fn (array $a, array $b) => strcmp((string) $a['title'], (string) $b['title']));

        return $modules;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(string $module): ?array
    {
        $normalized = LegacyBridge::normalizeModule($module);
        if (!is_string($normalized) || $normalized === '') {
            return null;
        }

        if (!in_array($normalized, (array) config('legacy.modules', []), true)) {
            return null;
        }

        return $this->buildDefinition($normalized);
    }

    public function canAccess(string $module, ?array $legacyContext): bool
    {
        if (!is_array($legacyContext) || empty($legacyContext['analyst_id'])) {
            return false;
        }

        if (!empty($legacyContext['is_admin'])) {
            return true;
        }

        $allowed = $legacyContext['allowed_modules'] ?? [];
        if (!is_array($allowed)) {
            return false;
        }

        $normalized = LegacyBridge::normalizeModule($module);
        if (!is_string($normalized) || $normalized === '') {
            return false;
        }

        return in_array($normalized, $allowed, true);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildDefinition(string $module): array
    {
        $definition = (array) config('modules.definitions.' . $module, []);
        $legacyEntry = (string) ($definition['legacy_entry'] ?? ($module . '/index.php'));

        return [
            'module' => $module,
            'title' => (string) ($definition['title'] ?? Str::title(str_replace('-', ' ', $module))),
            'description' => $definition['description'] ?? null,
            'status' => (string) ($definition['status'] ?? config('modules.default_status', 'legacy')),
            'legacy_entry' => $legacyEntry,
            'legacy_url' => '/' . ltrim($legacyEntry, '/'),
            'aliases' => $this->aliasesFor($module),
        ];
    }

    /**
     * @return array<int, string>
     */
    private function aliasesFor(string $module): array
    {
        $aliases = [];

        foreach ((array) config('legacy.aliases', []) as $alias => $target) {
            if (is_string($alias) && is_string($target) && $target === $module) {
                $aliases[] = $alias;
            }
        }

        sort($aliases);

        return $aliases;
    }
}
