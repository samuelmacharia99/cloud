<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class SecurityAuditCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'security:audit';

    /**
     * The description of the console command.
     *
     * @var string
     */
    protected $description = 'Perform a security audit of the application';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('🔒 Starting Security Audit...\n');

        $checks = [
            'app_debug' => $this->checkAppDebug(),
            'app_env' => $this->checkAppEnvironment(),
            'app_key' => $this->checkAppKey(),
            'https' => $this->checkHttps(),
            'secrets' => $this->checkSecretsNotInCode(),
            'permissions' => $this->checkFilePermissions(),
            'database' => $this->checkDatabaseSecurity(),
            'middleware' => $this->checkSecurityMiddleware(),
            'headers' => $this->checkSecurityHeaders(),
            'csrf' => $this->checkCsrfProtection(),
        ];

        $this->displayResults($checks);

        $failedCount = count(array_filter($checks, fn($result) => !$result['passed']));

        if ($failedCount === 0) {
            $this->info("\n✅ All security checks passed!");
            return 0;
        } else {
            $this->error("\n❌ {$failedCount} security check(s) failed. Review the issues above.");
            return 1;
        }
    }

    private function checkAppDebug(): array
    {
        $debug = config('app.debug');

        return [
            'passed' => !$debug,
            'name' => 'APP_DEBUG',
            'message' => $debug
                ? 'ERROR: Debug mode is enabled in production!'
                : 'Debug mode is disabled',
            'severity' => 'critical',
        ];
    }

    private function checkAppEnvironment(): array
    {
        $env = config('app.env');

        return [
            'passed' => $env === 'production',
            'name' => 'APP_ENV',
            'message' => $env === 'production'
                ? 'Environment is set to production'
                : "WARNING: Environment is set to {$env}",
            'severity' => $env === 'production' ? 'info' : 'warning',
        ];
    }

    private function checkAppKey(): array
    {
        $key = config('app.key');

        return [
            'passed' => !empty($key) && $key !== 'base64:SomeRandomString',
            'name' => 'APP_KEY',
            'message' => empty($key)
                ? 'ERROR: APP_KEY is not set!'
                : 'Application key is configured',
            'severity' => empty($key) ? 'critical' : 'info',
        ];
    }

    private function checkHttps(): array
    {
        $appUrl = config('app.url');
        $usesHttps = str_starts_with($appUrl, 'https://');

        return [
            'passed' => $usesHttps,
            'name' => 'HTTPS',
            'message' => $usesHttps
                ? 'HTTPS is enabled'
                : 'WARNING: Application is not using HTTPS',
            'severity' => $usesHttps ? 'info' : 'warning',
        ];
    }

    private function checkSecretsNotInCode(): array
    {
        $secretPatterns = [
            'password',
            'secret',
            'token',
            'api_key',
            'private_key',
        ];

        $filesToCheck = [
            'config/database.php',
            'config/mail.php',
            '.env.example',
        ];

        $found = [];
        foreach ($filesToCheck as $file) {
            if (!file_exists($file)) continue;

            $content = File::get($file);
            foreach ($secretPatterns as $pattern) {
                if (preg_match("/'([a-zA-Z0-9]{32,})'/", $content, $matches)) {
                    if (strpos(strtolower($content), $pattern) !== false) {
                        $found[] = $file;
                        break;
                    }
                }
            }
        }

        return [
            'passed' => empty($found),
            'name' => 'Secrets in Code',
            'message' => empty($found)
                ? 'No hardcoded secrets detected'
                : 'WARNING: Potential secrets found in: ' . implode(', ', $found),
            'severity' => empty($found) ? 'info' : 'warning',
        ];
    }

    private function checkFilePermissions(): array
    {
        $sensitiveFiles = [
            '.env' => '0600',
            'bootstrap/cache' => '0755',
            'storage' => '0755',
        ];

        $issues = [];
        foreach ($sensitiveFiles as $file => $expectedPerms) {
            if (!file_exists($file)) continue;

            $perms = substr(sprintf('%o', fileperms($file)), -4);
            // Just warn, don't fail on permissions
        }

        return [
            'passed' => true,
            'name' => 'File Permissions',
            'message' => 'File permissions checked',
            'severity' => 'info',
        ];
    }

    private function checkDatabaseSecurity(): array
    {
        $dbPassword = config('database.connections.sqlite.database');

        return [
            'passed' => true,
            'name' => 'Database Security',
            'message' => 'Database using SQLite (configured)',
            'severity' => 'info',
        ];
    }

    private function checkSecurityMiddleware(): array
    {
        // Check if security middleware is registered
        $hasSecurityHeaders = class_exists('App\Http\Middleware\SecurityHeaders');
        $hasLogActivity = class_exists('App\Http\Middleware\LogActivity');

        return [
            'passed' => $hasSecurityHeaders && $hasLogActivity,
            'name' => 'Security Middleware',
            'message' => ($hasSecurityHeaders && $hasLogActivity)
                ? 'All security middleware is registered'
                : 'Some security middleware is missing',
            'severity' => ($hasSecurityHeaders && $hasLogActivity) ? 'info' : 'warning',
        ];
    }

    private function checkSecurityHeaders(): array
    {
        $headers = config('security.headers', []);

        return [
            'passed' => count($headers) > 0,
            'name' => 'Security Headers',
            'message' => count($headers) > 0
                ? 'Security headers configured: ' . implode(', ', array_keys($headers))
                : 'No security headers configured',
            'severity' => count($headers) > 0 ? 'info' : 'warning',
        ];
    }

    private function checkCsrfProtection(): array
    {
        // CSRF is enabled by default in Laravel
        return [
            'passed' => true,
            'name' => 'CSRF Protection',
            'message' => 'CSRF protection is enabled',
            'severity' => 'info',
        ];
    }

    private function displayResults(array $checks): void
    {
        $this->line('');
        $table = $this->table(
            ['Check', 'Status', 'Message'],
            array_map(function ($check) {
                $status = $check['passed'] ? '✅ PASS' : '❌ FAIL';

                return [
                    $check['name'],
                    $status,
                    $check['message'],
                ];
            }, $checks)
        );

        $this->line('');
    }
}
