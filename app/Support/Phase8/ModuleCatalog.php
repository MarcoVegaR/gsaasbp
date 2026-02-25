<?php

declare(strict_types=1);

namespace App\Support\Phase8;

final class ModuleCatalog
{
    /**
     * @return list<array{slug:string,title:string,route_path:string,ability_prefix:string,model_class:string,policy_class:string}>
     */
    public function modules(): array
    {
        $configured = config('phase8_modules.modules', []);

        if (! is_array($configured)) {
            return [];
        }

        $modules = [];

        foreach ($configured as $module) {
            if (! is_array($module)) {
                continue;
            }

            $slug = trim((string) ($module['slug'] ?? ''));
            $title = trim((string) ($module['title'] ?? ''));
            $routePath = trim((string) ($module['route_path'] ?? ''));
            $abilityPrefix = trim((string) ($module['ability_prefix'] ?? ''));
            $modelClass = trim((string) ($module['model_class'] ?? ''));
            $policyClass = trim((string) ($module['policy_class'] ?? ''));

            if ($slug === '' || $title === '' || $routePath === '' || $abilityPrefix === '' || $modelClass === '' || $policyClass === '') {
                continue;
            }

            $modules[] = [
                'slug' => $slug,
                'title' => $title,
                'route_path' => $routePath,
                'ability_prefix' => $abilityPrefix,
                'model_class' => $modelClass,
                'policy_class' => $policyClass,
            ];
        }

        usort($modules, static fn (array $a, array $b): int => strcmp($a['slug'], $b['slug']));

        return $modules;
    }

    /**
     * @return list<array{title:string,href:string}>
     */
    public function tenantNavigationItems(): array
    {
        return array_map(static fn (array $module): array => [
            'title' => $module['title'],
            'href' => $module['route_path'],
        ], $this->modules());
    }
}
