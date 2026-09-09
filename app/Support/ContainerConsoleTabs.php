<?php

namespace App\Support;

/**
 * Tab order for the application console.
 *
 * The tab strip and the Alpine component that guards navigation live in two
 * separate partials, and setTab() silently refuses any tab missing from its
 * allow-list. Both sides must therefore read the order from here rather than
 * rebuilding it, or the console renders buttons that do nothing.
 */
class ContainerConsoleTabs
{
    /** Tabs every deployed application has. */
    public const BASE = [
        'overview',
        'environment',
        'files',
        'terminal',
        'backups',
        'domains',
        'database',
        'cron',
        'logs',
        'documentation',
    ];

    /** Only the overview panel renders before an application is deployed. */
    public const NOT_DEPLOYED = ['overview'];

    /**
     * @return list<string>
     */
    public static function resolve(
        bool $supportsOllamaChat = false,
        bool $supportsGitRepository = false,
        bool $supportsPhpExtensions = false
    ): array {
        $tabs = self::BASE;

        if ($supportsOllamaChat) {
            self::insertAt($tabs, 'chat', self::indexOf($tabs, 'terminal'));
        }

        if ($supportsGitRepository) {
            self::insertAt($tabs, 'github', self::indexOf($tabs, 'terminal') + 1);
        }

        if ($supportsPhpExtensions) {
            self::insertAt($tabs, 'php-extensions', self::indexOf($tabs, 'documentation'));
        }

        return $tabs;
    }

    /**
     * Deep links such as ?tab=logs only survive if the tab actually exists.
     *
     * @param  list<string>  $tabs
     */
    public static function initial(array $tabs, mixed $requested): string
    {
        return in_array($requested, $tabs, true) ? (string) $requested : 'overview';
    }

    /**
     * @param  list<string>  $tabs
     */
    private static function indexOf(array $tabs, string $tab): int
    {
        $index = array_search($tab, $tabs, true);

        return $index === false ? count($tabs) : (int) $index;
    }

    /**
     * @param  list<string>  $tabs
     */
    private static function insertAt(array &$tabs, string $tab, int $index): void
    {
        array_splice($tabs, $index, 0, $tab);
    }
}
