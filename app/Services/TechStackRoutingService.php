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

    public static function supportsDeploymentPlatformChoice(ContainerTemplate $language): bool
    {
        return in_array(strtolower($language->slug), ['laravel', 'wordpress'], true);
    }

    /**
     * Determine hosting type and product based on language + database selection
     *
     * Database Restrictions:
     * - PHP: MySQL and MariaDB only (DirectAdmin shared hosting)
     * - WordPress & Laravel: customer chooses shared (DirectAdmin) or container hosting
     * - Static Site: no database (Container hosting)
     * - Node.js, Python, Ruby, Go, etc: PostgreSQL, MongoDB, Redis, MySQL container (Container hosting)
     */
    public static function determineHostingType(
        ContainerTemplate $language,
        ?DatabaseTemplate $database,
        ?string $deploymentPlatform = null
    ): array {
        $hosting_type = 'container';
        $database_name = $database?->name ?? 'None';
        $database_slug = $database?->slug ?? 'none';

        if (self::supportsDeploymentPlatformChoice($language) && $deploymentPlatform) {
            $hosting_type = $deploymentPlatform === 'shared' ? 'directadmin' : 'container';
        } elseif ($language->hosting_type === 'directadmin' && $database && in_array($database->type, ['mysql', 'mariadb'])) {
            $hosting_type = 'directadmin';
        }

        return [
            'hosting_type' => $hosting_type,
            'deployment_platform' => $deploymentPlatform,
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
        $routing = self::determineHostingType($language, $database);

        if ($routing['hosting_type'] === 'directadmin') {
            // Get shared hosting/PHP product
            return Product::where('type', 'shared_hosting')
                ->where('is_active', true)
                ->first();
        } else {
            // Get container hosting product that matches the language
            return Product::where('type', 'container_hosting')
                ->where('container_template_id', $language->id)
                ->where('is_active', true)
                ->first();
        }
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

        if (in_array(strtolower($language->slug), ['php', 'wordpress', 'laravel'])) {
            return in_array($database->type, ['mysql', 'mariadb']);
        }

        if ($language->hosting_type === 'container') {
            return in_array($database->type, ['postgresql', 'mongodb', 'redis', 'mysql']);
        }

        return false;
    }

    /**
     * Get available databases for a given language
     */
    public static function getAvailableDatabasesForLanguage(
        ContainerTemplate $language,
        ?string $deploymentPlatform = null
    ): Collection {
        if (self::supportsDeploymentPlatformChoice($language) && $deploymentPlatform) {
            $hostingType = $deploymentPlatform === 'shared' ? 'directadmin' : 'container';

            return DatabaseTemplate::active()
                ->whereIn('type', ['mysql', 'mariadb'])
                ->where('hosting_type', $hostingType)
                ->get();
        }

        // PHP uses MySQL/MariaDB; hosting type depends on template routing.
        if (in_array(strtolower($language->slug), ['php', 'laravel', 'wordpress'])) {
            $hostingType = $language->hosting_type ?? 'container';

            return DatabaseTemplate::active()
                ->whereIn('type', ['mysql', 'mariadb'])
                ->where('hosting_type', $hostingType)
                ->get();
        }

        // Static site needs no database
        if (strtolower($language->slug) === 'static-site') {
            return collect();
        }

        // Container languages show all container-hosted databases
        return DatabaseTemplate::active()
            ->forHostingType('container')
            ->get();
    }

    /**
     * Get available languages for a given database
     */
    public static function getAvailableLanguagesForDatabase(DatabaseTemplate $database): Collection
    {
        // MySQL and MariaDB support PHP, WordPress, and Laravel (DirectAdmin)
        if (in_array($database->type, ['mysql', 'mariadb'])) {
            return ContainerTemplate::whereIn('slug', ['php', 'wordpress', 'laravel'])
                ->active()
                ->get();
        }

        // Other databases (PostgreSQL, MongoDB, Redis) only for container hosting
        if ($database->hosting_type === 'container') {
            return ContainerTemplate::where('hosting_type', 'container')
                ->active()
                ->get();
        }

        return ContainerTemplate::where('hosting_type', $database->hosting_type)
            ->active()
            ->get();
    }
}
