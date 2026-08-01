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
            ->where('container_template_id', $language->id)
            ->where('is_active', true)
            ->first();
    }

    /**
     * Validate if selected techstack combination is allowed
     */
    public static function isValidCombination(
        ContainerTemplate $language,
        ?DatabaseTemplate $database
    ): bool {
        if ($database === null) {
            return $language->slug === 'static-site';
        }

        // WordPress (and plain PHP) stay on MySQL/MariaDB.
        if (in_array(strtolower($language->slug), ['php', 'wordpress'], true)) {
            return in_array($database->type, ['mysql', 'mariadb'], true)
                && $database->hosting_type === 'container';
        }

        // Laravel and other application stacks can use any container database.
        if (
            self::isLaravel($language)
            || $language->hosting_type === 'container'
            || $language->hosting_type === 'directadmin'
        ) {
            return in_array($database->type, ['postgresql', 'mongodb', 'redis', 'mysql', 'mariadb'], true)
                && $database->hosting_type === 'container';
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
        // WordPress / plain PHP → container MySQL / MariaDB only
        if (in_array(strtolower($language->slug), ['php', 'wordpress'], true)) {
            return DatabaseTemplate::active()
                ->whereIn('type', ['mysql', 'mariadb'])
                ->where('hosting_type', 'container')
                ->get();
        }

        // Static site needs no database
        if (strtolower($language->slug) === 'static-site') {
            return collect();
        }

        // Laravel and all other stacks → every container database
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

        if (! empty($techstack['database_id'])) {
            $serviceMeta['database_id'] = (int) $techstack['database_id'];
        }

        if (! empty($techstack['database_name'])) {
            $serviceMeta['database_template_name'] = (string) $techstack['database_name'];
        }

        if (! empty($techstack['deployment_platform'])) {
            $serviceMeta['deployment_platform'] = (string) $techstack['deployment_platform'];
        }

        return $serviceMeta;
    }
}
