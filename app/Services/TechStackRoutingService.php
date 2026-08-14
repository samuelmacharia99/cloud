<?php

namespace App\Services;

use App\Models\ContainerTemplate;
use App\Models\DatabaseTemplate;
use App\Models\Product;
use Illuminate\Database\Eloquent\Collection;

class TechStackRoutingService
{
    public static function isLaravel(ContainerTemplate $language): bool
    {
        return strtolower($language->slug) === 'laravel';
    }

    public static function isWordPress(ContainerTemplate $language): bool
    {
        return strtolower($language->slug) === 'wordpress';
    }

    /**
     * Platform tech-stack is application hosting only — no shared/DirectAdmin choice.
     */
    public static function supportsDeploymentPlatformChoice(ContainerTemplate $language): bool
    {
        return false;
    }

    /**
     * Determine hosting type and product based on language + database selection.
     *
     * Platform customers always get application (container) hosting.
     * DirectAdmin shared hosting is sold only via reseller catalogs.
     */
    public static function determineHostingType(
        ContainerTemplate $language,
        ?DatabaseTemplate $database,
        ?string $deploymentPlatform = null
    ): array {
        $database_name = $database?->name ?? 'None';
        $database_slug = $database?->slug ?? 'none';

        return [
            'hosting_type' => 'container',
            'deployment_platform' => $deploymentPlatform === 'container' ? 'container' : null,
            'language' => $language->name,
            'database' => $database_name,
            'language_slug' => $language->slug,
            'database_slug' => $database_slug,
        ];
    }

    /**
     * Get recommended product based on techstack
     */
    public static function getRecommendedProduct(
        ContainerTemplate $language,
        DatabaseTemplate $database
    ): ?Product {
        return Product::where('type', 'container_hosting')
            ->forTechstackLanguage((int) $language->id)
            ->where('is_active', true)
            ->orderByRaw('COALESCE(monthly_price, yearly_price / 12, 0) ASC')
            ->first();
    }

    /**
     * Raw stack definition for a container template slug.
     *
     * @return array<string, mixed>
     */
    public static function stackDefinition(ContainerTemplate $language): array
    {
        $slug = strtolower((string) $language->slug);
        $stacks = config('stack_builder.stacks', []);
        $definition = $stacks[$slug] ?? config('stack_builder.default', []);

        $definition['backend'] = $definition['backend'] ?? $slug;

        return $definition;
    }

    /**
     * @return list<array{value: string, label: string, locked?: bool}>
     */
    public static function availableFrameworks(ContainerTemplate $language): array
    {
        $definition = self::stackDefinition($language);
        $framework = $definition['framework'] ?? [];

        if (! ($framework['show'] ?? false)) {
            return [];
        }

        return self::mapOptionLabels(
            $framework['options'] ?? [],
            config('stack_builder.framework_labels', [])
        );
    }

    /**
     * @return list<array{value: string, label: string, locked?: bool}>
     */
    public static function availableFrontends(ContainerTemplate $language, ?string $framework = null): array
    {
        $definition = self::stackDefinition($language);
        $frontend = $definition['frontend'] ?? [];

        if (! ($frontend['show'] ?? false)) {
            return [];
        }

        $lockMap = $frontend['lock_when_framework'] ?? [];
        if ($framework !== null && isset($lockMap[$framework])) {
            $locked = (string) $lockMap[$framework];

            return self::mapOptionLabels(
                [$locked],
                config('stack_builder.frontend_labels', []),
                lockedValue: $locked
            );
        }

        return self::mapOptionLabels(
            $frontend['options'] ?? [],
            config('stack_builder.frontend_labels', [])
        );
    }

    /**
     * Resolve locked / default role values for a language.
     *
     * @return array{backend: string, framework: ?string, frontend: string}
     */
    public static function resolveDefaultRoles(ContainerTemplate $language, ?string $framework = null, ?string $frontend = null): array
    {
        $definition = self::stackDefinition($language);
        $frameworkConfig = $definition['framework'] ?? [];
        $frontendConfig = $definition['frontend'] ?? [];

        $resolvedFramework = $framework;
        if ($resolvedFramework === null || $resolvedFramework === '') {
            $resolvedFramework = $frameworkConfig['locked'] ?? null;
        }

        $resolvedFrontend = $frontend;
        $lockMap = $frontendConfig['lock_when_framework'] ?? [];
        if ($resolvedFramework !== null && isset($lockMap[$resolvedFramework])) {
            $resolvedFrontend = (string) $lockMap[$resolvedFramework];
        } elseif ($resolvedFrontend === null || $resolvedFrontend === '') {
            $resolvedFrontend = $frontendConfig['locked'] ?? 'none';
        }

        return [
            'backend' => (string) ($definition['backend'] ?? $language->slug),
            'framework' => $resolvedFramework !== null && $resolvedFramework !== ''
                ? (string) $resolvedFramework
                : null,
            'frontend' => (string) $resolvedFrontend,
        ];
    }

    /**
     * Payload for the stack-builder modal (AJAX).
     *
     * @return array<string, mixed>
     */
    public static function stackOptionsPayload(ContainerTemplate $language, ?string $framework = null): array
    {
        $definition = self::stackDefinition($language);
        $roles = self::resolveDefaultRoles($language, $framework);
        $databases = self::getAvailableDatabasesForLanguage($language);

        return [
            'language' => [
                'id' => $language->id,
                'name' => $language->name,
                'slug' => $language->slug,
            ],
            'backend' => $roles['backend'],
            'framework' => [
                'show' => (bool) ($definition['framework']['show'] ?? false),
                'required' => (bool) ($definition['framework']['required'] ?? false),
                'locked' => $definition['framework']['locked'] ?? null,
                'options' => self::availableFrameworks($language),
                'value' => $roles['framework'],
            ],
            'frontend' => [
                'show' => (bool) ($definition['frontend']['show'] ?? false),
                'required' => (bool) ($definition['frontend']['required'] ?? false),
                'locked' => $definition['frontend']['locked'] ?? null,
                'options' => self::availableFrontends($language, $roles['framework']),
                'value' => $roles['frontend'],
                'deferred_note' => 'Next.js starts a frontend sidecar plus an edge router on your public port. Laravel stays on an internal backend service; /api is routed automatically.',
            ],
            'database' => [
                'show' => (bool) ($definition['database']['show'] ?? false),
                'required' => (bool) ($definition['database']['required'] ?? false),
                'allow_none' => (bool) ($definition['database']['allow_none'] ?? false),
                'options' => $databases->map(fn (DatabaseTemplate $db) => [
                    'id' => $db->id,
                    'name' => $db->name,
                    'slug' => $db->slug,
                    'type' => $db->type,
                ])->values()->all(),
            ],
            'skip_modal' => strtolower((string) $language->slug) === 'static-site',
            'stack_builder_version' => (int) config('stack_builder.version', 1),
        ];
    }

    /**
     * Validate language + optional framework/frontend + database against the matrix.
     */
    public static function isValidStackSelection(
        ContainerTemplate $language,
        ?string $framework,
        ?string $frontend,
        ?DatabaseTemplate $database
    ): bool {
        $definition = self::stackDefinition($language);
        $roles = self::resolveDefaultRoles($language, $framework, $frontend);

        $frameworkConfig = $definition['framework'] ?? [];
        if ($frameworkConfig['required'] ?? false) {
            if ($roles['framework'] === null || $roles['framework'] === '') {
                return false;
            }
            $allowedFrameworks = $frameworkConfig['options'] ?? [];
            if ($allowedFrameworks !== [] && ! in_array($roles['framework'], $allowedFrameworks, true)) {
                return false;
            }
        } elseif ($framework !== null && $framework !== '') {
            $allowedFrameworks = $frameworkConfig['options'] ?? [];
            $locked = $frameworkConfig['locked'] ?? null;
            if ($allowedFrameworks !== [] && ! in_array($framework, $allowedFrameworks, true) && $framework !== $locked) {
                return false;
            }
        }

        $frontendConfig = $definition['frontend'] ?? [];
        $lockMap = $frontendConfig['lock_when_framework'] ?? [];
        $effectiveFramework = $roles['framework'] ?? $framework;
        if ($effectiveFramework !== null && isset($lockMap[$effectiveFramework])) {
            $expectedFrontend = (string) $lockMap[$effectiveFramework];
            if ($frontend !== null && $frontend !== '' && $frontend !== $expectedFrontend) {
                return false;
            }
        }

        $allowedFrontends = $frontendConfig['options'] ?? ['none'];
        if ($roles['framework'] !== null && isset($lockMap[$roles['framework']])) {
            if ($roles['frontend'] !== (string) $lockMap[$roles['framework']]) {
                return false;
            }
        } elseif ($frontendConfig['show'] ?? false) {
            if (($frontendConfig['required'] ?? false) && ($roles['frontend'] === null || $roles['frontend'] === '')) {
                return false;
            }
            if (! in_array($roles['frontend'], $allowedFrontends, true)) {
                return false;
            }
        } else {
            $lockedFrontend = $frontendConfig['locked'] ?? 'none';
            if ($roles['frontend'] !== $lockedFrontend) {
                // Allow submitting without an explicit frontend when locked — resolveDefaultRoles handles it.
                if ($frontend !== null && $frontend !== '' && $frontend !== $lockedFrontend) {
                    return false;
                }
            }
        }

        return self::isValidCombination($language, $database);
    }

    /**
     * Validate if selected techstack combination is allowed
     */
    public static function isValidCombination(
        ContainerTemplate $language,
        ?DatabaseTemplate $database
    ): bool {
        $definition = self::stackDefinition($language);
        $databaseConfig = $definition['database'] ?? [];
        $slug = strtolower((string) $language->slug);

        if ($database === null) {
            if ($slug === 'static-site') {
                return true;
            }

            return (bool) ($databaseConfig['allow_none'] ?? false)
                && ! ($databaseConfig['required'] ?? false);
        }

        if ($database->hosting_type !== 'container') {
            return false;
        }

        $allowedTypes = $databaseConfig['types'] ?? null;
        if (is_array($allowedTypes) && $allowedTypes !== []) {
            return in_array($database->type, $allowedTypes, true);
        }

        // WordPress (and plain PHP) stay on MySQL/MariaDB.
        if (in_array($slug, ['php', 'wordpress'], true)) {
            return in_array($database->type, ['mysql', 'mariadb'], true);
        }

        // Laravel and other application stacks can use any container database.
        if (
            self::isLaravel($language)
            || $language->hosting_type === 'container'
            || $language->hosting_type === 'directadmin'
        ) {
            return in_array($database->type, ['postgresql', 'mongodb', 'redis', 'mysql', 'mariadb'], true);
        }

        return false;
    }

    /**
     * Get available databases for a given language (container-hosted only).
     */
    public static function getAvailableDatabasesForLanguage(
        ContainerTemplate $language,
        ?string $deploymentPlatform = null
    ): Collection {
        $definition = self::stackDefinition($language);
        $databaseConfig = $definition['database'] ?? [];
        $slug = strtolower((string) $language->slug);

        if ($slug === 'static-site' || ($databaseConfig['show'] ?? true) === false) {
            return new Collection;
        }

        $types = $databaseConfig['types'] ?? [];

        if ($types !== []) {
            return DatabaseTemplate::active()
                ->whereIn('type', $types)
                ->where('hosting_type', 'container')
                ->orderBy('order')
                ->orderBy('name')
                ->get();
        }

        // Legacy fallbacks
        if (in_array($slug, ['php', 'wordpress'], true)) {
            return DatabaseTemplate::active()
                ->whereIn('type', ['mysql', 'mariadb'])
                ->where('hosting_type', 'container')
                ->get();
        }

        return DatabaseTemplate::active()
            ->forHostingType('container')
            ->get();
    }

    /**
     * Get available languages for a given database
     */
    public static function getAvailableLanguagesForDatabase(DatabaseTemplate $database): Collection
    {
        if ($database->hosting_type !== 'container') {
            return new Collection;
        }

        // MySQL and MariaDB support PHP, WordPress, and Laravel
        if (in_array($database->type, ['mysql', 'mariadb'], true)) {
            return ContainerTemplate::whereIn('slug', ['php', 'wordpress', 'laravel'])
                ->active()
                ->get();
        }

        // Other DBs: Laravel plus non-PHP application stacks
        return ContainerTemplate::query()
            ->active()
            ->where(function ($q) {
                $q->where('slug', 'laravel')
                    ->orWhere(function ($inner) {
                        $inner->where('hosting_type', 'container')
                            ->whereNotIn('slug', ['php', 'wordpress', 'static-site']);
                    });
            })
            ->get();
    }

    /**
     * Human-readable summary line for packages / checkout.
     */
    public static function selectionSummary(array $techstack): string
    {
        $parts = [];

        if (! empty($techstack['language_name'])) {
            $parts[] = (string) $techstack['language_name'];
        } elseif (! empty($techstack['backend'])) {
            $parts[] = (string) $techstack['backend'];
        }

        if (! empty($techstack['framework'])) {
            $labels = config('stack_builder.framework_labels', []);
            $key = (string) $techstack['framework'];
            $parts[] = $labels[$key] ?? $key;
        }

        if (! empty($techstack['frontend']) && $techstack['frontend'] !== 'none') {
            $labels = config('stack_builder.frontend_labels', []);
            $key = (string) $techstack['frontend'];
            $label = $labels[$key] ?? $key;
            $parts[] = 'frontend '.$label;
        }

        if (! empty($techstack['database_name'])) {
            $parts[] = (string) $techstack['database_name'];
        } else {
            $parts[] = 'No database';
        }

        return implode(' · ', $parts);
    }

    /**
     * Persist selected tech stack from checkout session onto service metadata.
     *
     * @param  array<string, mixed>  $serviceMeta
     * @return array<string, mixed>
     */
    public static function applySessionSelectionToServiceMeta(array $serviceMeta): array
    {
        $techstack = session('selected_techstack', []);
        if (! is_array($techstack) || $techstack === []) {
            return $serviceMeta;
        }

        if (! empty($techstack['language_id'])) {
            $serviceMeta['container_template_id'] = (int) $techstack['language_id'];
        }

        if (! empty($techstack['language_name'])) {
            $serviceMeta['application_stack'] = (string) $techstack['language_name'];
        }

        if (! empty($techstack['language_slug'])) {
            $serviceMeta['language_slug'] = (string) $techstack['language_slug'];
        }

        if (! empty($techstack['backend'])) {
            $serviceMeta['backend'] = (string) $techstack['backend'];
        }

        if (array_key_exists('framework', $techstack)) {
            $serviceMeta['framework'] = $techstack['framework'] !== null && $techstack['framework'] !== ''
                ? (string) $techstack['framework']
                : null;
        }

        if (! empty($techstack['frontend'])) {
            $serviceMeta['frontend'] = (string) $techstack['frontend'];
        }

        if (! empty($techstack['database_id'])) {
            $serviceMeta['database_id'] = (int) $techstack['database_id'];
        }

        if (! empty($techstack['database_name'])) {
            $serviceMeta['database_template_name'] = (string) $techstack['database_name'];
        }

        if (! empty($techstack['deployment_platform'])) {
            $serviceMeta['deployment_platform'] = (string) $techstack['deployment_platform'];
        }

        if (! empty($techstack['stack_builder_version'])) {
            $serviceMeta['stack_builder_version'] = (int) $techstack['stack_builder_version'];
        }

        return $serviceMeta;
    }

    /**
     * Apply framework / frontend / database choices chosen during container redeploy.
     *
     * @param  array<string, mixed>  $serviceMeta
     * @return array{meta: array<string, mixed>, database_changed: bool}
     */
    public static function applyRedeployStackSelection(
        array $serviceMeta,
        ContainerTemplate $language,
        ?string $framework,
        ?string $frontend,
        ?DatabaseTemplate $database,
    ): array {
        if (! self::isValidStackSelection($language, $framework, $frontend, $database)) {
            throw new \InvalidArgumentException('Invalid stack selection for this application type.');
        }

        $roles = self::resolveDefaultRoles($language, $framework, $frontend);
        $previousDatabaseId = isset($serviceMeta['database_id']) ? (int) $serviceMeta['database_id'] : null;
        $newDatabaseId = $database?->id;

        $serviceMeta['framework'] = $roles['framework'];
        $serviceMeta['frontend'] = $roles['frontend'];
        $serviceMeta['backend'] = $roles['backend'];
        $serviceMeta['stack_builder_version'] = (int) config('stack_builder.version', 1);

        if ($database) {
            $serviceMeta['database_id'] = $database->id;
            $serviceMeta['database_template_name'] = $database->name;
        } else {
            unset($serviceMeta['database_id'], $serviceMeta['database_template_name']);
        }

        return [
            'meta' => $serviceMeta,
            'database_changed' => $previousDatabaseId !== $newDatabaseId,
        ];
    }

    /**
     * @param  list<string>  $values
     * @param  array<string, string>  $labels
     * @return list<array{value: string, label: string, locked: bool}>
     */
    private static function mapOptionLabels(array $values, array $labels, ?string $lockedValue = null): array
    {
        $options = [];
        foreach ($values as $value) {
            $options[] = [
                'value' => $value,
                'label' => $labels[$value] ?? $value,
                'locked' => $lockedValue !== null && $value === $lockedValue && count($values) === 1,
            ];
        }

        return $options;
    }
}
