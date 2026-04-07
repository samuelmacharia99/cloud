<?php

namespace App\Services;

use App\Models\ContainerTemplate;
use App\Models\DatabaseTemplate;
use App\Models\Product;

class TechStackRoutingService
{
    /**
     * Determine hosting type and product based on language + database selection
     *
     * Rules:
     * - PHP + MySQL/MariaDB = DirectAdmin (shared hosting)
     * - Everything else + PostgreSQL/MongoDB/Redis = Container hosting
     */
    public static function determineHostingType(
        ContainerTemplate $language,
        DatabaseTemplate $database
    ): array {
        $isPhpWithMysql = $language->hosting_type === 'directadmin' &&
                         in_array($database->type, ['mysql', 'mariadb']);

        return [
            'hosting_type' => $isPhpWithMysql ? 'directadmin' : 'container',
            'language' => $language->name,
            'database' => $database->name,
            'language_slug' => $language->slug,
            'database_slug' => $database->slug,
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
        DatabaseTemplate $database
    ): bool {
        // PHP only works with MySQL/MariaDB
        if ($language->hosting_type === 'directadmin') {
            return in_array($database->type, ['mysql', 'mariadb']);
        }

        // Container languages work with PostgreSQL, MongoDB, Redis
        if ($language->hosting_type === 'container') {
            return in_array($database->type, ['postgresql', 'mongodb', 'redis']);
        }

        return false;
    }

    /**
     * Get available databases for a given language
     */
    public static function getAvailableDatabasesForLanguage(ContainerTemplate $language): \Illuminate\Database\Eloquent\Collection
    {
        if ($language->hosting_type === 'directadmin') {
            return DatabaseTemplate::active()
                                  ->forHostingType('directadmin')
                                  ->get();
        }

        return DatabaseTemplate::active()
                              ->forHostingType('container')
                              ->get();
    }

    /**
     * Get available languages for a given database
     */
    public static function getAvailableLanguagesForDatabase(DatabaseTemplate $database): \Illuminate\Database\Eloquent\Collection
    {
        if ($database->hosting_type === 'directadmin') {
            return ContainerTemplate::where('hosting_type', 'directadmin')
                                   ->active()
                                   ->get();
        }

        return ContainerTemplate::where('hosting_type', 'container')
                               ->active()
                               ->get();
    }
}
