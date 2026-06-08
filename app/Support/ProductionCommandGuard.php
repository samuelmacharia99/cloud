<?php

namespace App\Support;

use RuntimeException;

class ProductionCommandGuard
{
    public static function isProduction(): bool
    {
        return config('app.env') === 'production';
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public static function assertCommandAllowed(string $commandName, array $input = []): void
    {
        if (! self::isProduction()) {
            return;
        }

        $blocked = config('deploy.production_blocked_commands', []);

        if (in_array($commandName, $blocked, true)) {
            if ($commandName === 'db:seed') {
                self::assertSeederAllowed($input);

                return;
            }

            if ($commandName === 'migrate:rollback') {
                self::assertRollbackAllowed($input);

                return;
            }

            throw new RuntimeException(
                "Command [{$commandName}] is blocked in production to protect live data."
            );
        }
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private static function assertSeederAllowed(array $input): void
    {
        $class = $input['--class'] ?? $input['class'] ?? null;

        if (empty($class)) {
            throw new RuntimeException(
                'Running db:seed without --class is blocked in production. Use an allowlisted seeder explicitly.'
            );
        }

        $allowed = config('deploy.production_allowed_seeders', []);
        $normalized = self::normalizeSeederClass((string) $class);

        foreach ($allowed as $permitted) {
            if ($normalized === self::normalizeSeederClass($permitted)) {
                return;
            }
        }

        throw new RuntimeException(
            "Seeder [{$class}] is not allowlisted for production. Allowed: ".implode(', ', $allowed)
        );
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private static function assertRollbackAllowed(array $input): void
    {
        if (filter_var(env('DEPLOY_ALLOW_MIGRATE_ROLLBACK', false), FILTER_VALIDATE_BOOL)) {
            return;
        }

        $step = (int) ($input['--step'] ?? $input['step'] ?? 1);

        throw new RuntimeException(
            "migrate:rollback is blocked in production (step={$step}). Set DEPLOY_ALLOW_MIGRATE_ROLLBACK=true only for controlled recovery."
        );
    }

    private static function normalizeSeederClass(string $class): string
    {
        $class = ltrim($class, '\\');

        if (! str_contains($class, '\\')) {
            return 'Database\\Seeders\\'.$class;
        }

        return $class;
    }
}
