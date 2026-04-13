<?php

namespace App\Helpers;

use Illuminate\Support\Facades\Log;

class CronHelper
{
    /**
     * Generate the cron command for scheduling Laravel tasks
     * Dynamically adapts to the current environment and server setup
     *
     * @param string|null $outputFile Optional custom output file (null = /dev/null)
     * @return string The complete cron command
     */
    public static function generateCronCommand(?string $outputFile = null): string
    {
        $basePath = base_path();
        $phpPath = self::getPhpPath();
        $outputRedirect = self::getOutputRedirect($outputFile);

        return "* * * * * cd {$basePath} && {$phpPath} artisan schedule:run {$outputRedirect}";
    }

    /**
     * Get the correct PHP executable path for the environment
     *
     * @return string PHP executable path
     */
    public static function getPhpPath(): string
    {
        // Check environment variable first (for Docker/container setups)
        if ($phpPath = env('PHP_PATH')) {
            return $phpPath;
        }

        // Check if we're in Docker
        if (self::isDocker()) {
            return 'php';
        }

        // Try to find PHP in common locations
        $possiblePaths = [
            '/usr/bin/php',
            '/usr/local/bin/php',
            '/opt/homebrew/bin/php',
            '/usr/bin/php' . PHP_VERSION_ID,
            exec('which php') ?: null,
        ];

        foreach ($possiblePaths as $path) {
            if ($path && file_exists($path) && is_executable($path)) {
                return $path;
            }
        }

        // Default to 'php' (will use system PATH)
        return 'php';
    }

    /**
     * Get the output redirect string based on environment
     *
     * @param string|null $outputFile Custom output file
     * @return string Output redirect command
     */
    public static function getOutputRedirect(?string $outputFile = null): string
    {
        if ($outputFile) {
            return ">> {$outputFile} 2>&1";
        }

        // Production: log to file for debugging
        if (app()->isProduction()) {
            $logPath = storage_path('logs/cron.log');
            return ">> {$logPath} 2>&1";
        }

        // Development: suppress output
        return '>> /dev/null 2>&1';
    }

    /**
     * Check if running in Docker container
     *
     * @return bool
     */
    public static function isDocker(): bool
    {
        return file_exists('/.dockerenv') ||
               file_exists('/run/.dockerenv') ||
               getenv('APP_ENV') === 'docker';
    }

    /**
     * Get alternative cron commands for different scenarios
     *
     * @return array Array of cron command options
     */
    public static function getCronCommandOptions(): array
    {
        $basePath = base_path();
        $phpPath = self::getPhpPath();
        $logPath = storage_path('logs/cron.log');

        return [
            'default' => [
                'label' => 'Default (Recommended)',
                'description' => 'Suppresses output for cleaner cron logs',
                'command' => "* * * * * cd {$basePath} && {$phpPath} artisan schedule:run >> /dev/null 2>&1",
            ],
            'with_logging' => [
                'label' => 'With Logging',
                'description' => 'Logs output to storage/logs/cron.log for debugging',
                'command' => "* * * * * cd {$basePath} && {$phpPath} artisan schedule:run >> {$logPath} 2>&1",
            ],
            'with_email' => [
                'label' => 'With Email on Failure',
                'description' => 'Email notification if cron fails (requires sendmail)',
                'command' => "* * * * * cd {$basePath} && {$phpPath} artisan schedule:run >> {$logPath} 2>&1 || mail -s 'Cron failed on " . gethostname() . "' admin@example.com",
            ],
            'verbose' => [
                'label' => 'Verbose (Development)',
                'description' => 'Outputs all cron job details (development only)',
                'command' => "* * * * * cd {$basePath} && {$phpPath} artisan schedule:run -v 2>&1 | logger -t talksasa-cron",
            ],
        ];
    }

    /**
     * Validate that cron command is properly configured
     *
     * @return array Array with 'valid' boolean and 'message' string
     */
    public static function validateCronSetup(): array
    {
        $errors = [];

        // Check if artisan file exists
        if (!file_exists(base_path('artisan'))) {
            $errors[] = 'Laravel artisan file not found at ' . base_path('artisan');
        }

        // Check if PHP is accessible
        $phpPath = self::getPhpPath();
        if (!file_exists($phpPath) && !shell_exec("which {$phpPath}")) {
            $errors[] = "PHP executable not found at {$phpPath}";
        }

        // Check storage/logs directory is writable
        $logsDir = storage_path('logs');
        if (!is_writable($logsDir)) {
            $errors[] = "Storage logs directory is not writable: {$logsDir}";
        }

        // Check if any cron jobs are enabled
        if (class_exists(\App\Models\CronJob::class)) {
            $enabledCount = \App\Models\CronJob::where('enabled', true)->count();
            if ($enabledCount === 0) {
                $errors[] = 'No cron jobs are enabled in the database';
            }
        }

        return [
            'valid' => count($errors) === 0,
            'message' => count($errors) === 0
                ? 'Cron setup appears to be configured correctly'
                : 'Issues found: ' . implode('; ', $errors),
            'errors' => $errors,
        ];
    }

    /**
     * Get cron execution statistics
     *
     * @return array Statistics about cron executions
     */
    public static function getCronStats(): array
    {
        if (!class_exists(\App\Models\CronJob::class)) {
            return [];
        }

        try {
            $jobs = \App\Models\CronJob::with('latestLog')->get();

            $totalJobs = $jobs->count();
            $enabledJobs = $jobs->where('enabled', true)->count();
            $recentRuns = \App\Models\CronJobLog::where('started_at', '>=', now()->subHours(24))->count();
            $recentFailures = \App\Models\CronJobLog::where('started_at', '>=', now()->subHours(24))
                ->where('status', 'failed')
                ->count();

            return [
                'total_jobs' => $totalJobs,
                'enabled_jobs' => $enabledJobs,
                'recent_runs_24h' => $recentRuns,
                'recent_failures_24h' => $recentFailures,
                'health_status' => $recentFailures === 0 ? 'healthy' : 'warning',
            ];
        } catch (\Exception $e) {
            // If table doesn't exist yet, return empty stats
            return [];
        }
    }
}
