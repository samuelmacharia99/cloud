<?php

namespace App\Services;

use App\Jobs\ProvisionResellerSslJob;
use App\Models\Setting;
use App\Models\User;
use Carbon\Carbon;
use Exception;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ResellerSslService
{
    private const RENEW_WITHIN_DAYS = 14;

    private const RETRY_AFTER_MINUTES = 60;

    public function queueProvision(User $reseller, string $reason = 'manual'): bool
    {
        $domain = $reseller->settings['branding']['custom_domain'] ?? null;

        if (empty($domain)) {
            return false;
        }

        $ssl = $this->getSslStatus($reseller);

        if (in_array($ssl['status'], ['pending', 'provisioning'], true)) {
            if (! $this->isStale($ssl, $ssl['status'] === 'pending' ? 15 : 20)) {
                return false;
            }
        }

        if ($ssl['status'] === 'failed' && ! $this->canRetry($ssl)) {
            return false;
        }

        $this->updateSslStatus($reseller, [
            'status' => 'pending',
            'domain' => $domain,
            'error' => null,
            'queued_reason' => $reason,
            'queued_at' => now()->toIso8601String(),
        ]);

        ProvisionResellerSslJob::dispatch($reseller->id, 'issue');

        Log::info('Queued reseller SSL provisioning job', [
            'reseller_id' => $reseller->id,
            'domain' => $domain,
            'reason' => $reason,
        ]);

        return true;
    }

    /**
     * Reset SSL state before a manual provision attempt (ignores in-progress locks).
     */
    public function prepareManualProvision(User $reseller, string $reason = 'manual'): void
    {
        $domain = $reseller->settings['branding']['custom_domain'] ?? null;

        if (empty($domain)) {
            throw new \InvalidArgumentException('No custom domain configured.');
        }

        $this->updateSslStatus($reseller, [
            'status' => 'provisioning',
            'domain' => $domain,
            'error' => null,
            'queued_reason' => $reason,
            'queued_at' => now()->toIso8601String(),
            'last_attempt_at' => now()->toIso8601String(),
        ]);
    }

    public function shouldProcess(User $reseller): bool
    {
        $domain = $reseller->settings['branding']['custom_domain'] ?? null;

        if (empty($domain)) {
            return false;
        }

        $ssl = $this->getSslStatus($reseller);

        if (in_array($ssl['status'], ['pending', 'provisioning'], true)) {
            return $this->isStale($ssl, $ssl['status'] === 'pending' ? 15 : 20);
        }

        if ($ssl['status'] === 'active') {
            return $this->expiresWithinDays($ssl, self::RENEW_WITHIN_DAYS);
        }

        if ($ssl['status'] === 'failed') {
            return $this->canRetry($ssl);
        }

        return in_array($ssl['status'], ['none', 'failed'], true) || empty($ssl['status']);
    }

    public function processAutomatically(User $reseller): array
    {
        $reseller->refresh();
        $domain = $reseller->settings['branding']['custom_domain'] ?? null;

        if (empty($domain)) {
            return ['action' => 'skipped', 'success' => false, 'message' => 'No custom domain configured.'];
        }

        $ssl = $this->getSslStatus($reseller);

        if ($ssl['status'] === 'active' && $this->expiresWithinDays($ssl, self::RENEW_WITHIN_DAYS)) {
            $this->updateSslStatus($reseller, [
                'status' => 'provisioning',
                'last_attempt_at' => now()->toIso8601String(),
            ]);

            $result = $this->renewCertificate($reseller);

            return array_merge(['action' => 'renew'], $result);
        }

        if ($ssl['status'] === 'active') {
            return ['action' => 'skipped', 'success' => true, 'message' => 'Certificate is still valid.'];
        }

        $this->updateSslStatus($reseller, [
            'status' => 'provisioning',
            'domain' => $domain,
            'last_attempt_at' => now()->toIso8601String(),
        ]);

        $result = $this->issueCertificate($reseller);

        return array_merge(['action' => 'issue'], $result);
    }

    /**
     * @return array<string, mixed>
     */
    public function sslStatusForDomain(?string $domain): array
    {
        if (empty($domain)) {
            return [
                'status' => 'none',
                'domain' => null,
                'cert_path' => null,
                'key_path' => null,
                'issued_at' => null,
                'expires_at' => null,
                'error' => null,
                'queued_reason' => null,
                'queued_at' => null,
                'last_attempt_at' => null,
            ];
        }

        return [
            'status' => 'pending',
            'domain' => $domain,
            'cert_path' => null,
            'key_path' => null,
            'issued_at' => null,
            'expires_at' => null,
            'error' => null,
            'queued_reason' => 'domain_updated',
            'queued_at' => now()->toIso8601String(),
            'last_attempt_at' => null,
        ];
    }

    public function checkDns(string $domain): array
    {
        try {
            $serverIp = gethostbyname(parse_url(config('app.url'), PHP_URL_HOST));
            $domainIp = gethostbyname($domain);

            $resolves = $domainIp !== $domain;
            $match = $resolves && $domainIp === $serverIp;

            Log::info('DNS check for custom domain', [
                'domain' => $domain,
                'domain_ip' => $domainIp,
                'server_ip' => $serverIp,
                'resolves' => $resolves,
                'match' => $match,
            ]);

            return [
                'resolves' => $resolves,
                'domain_ip' => $domainIp,
                'server_ip' => $serverIp,
                'match' => $match,
                'message' => $match ? 'DNS is correctly pointing to this server' : 'Domain does not resolve to this server',
            ];
        } catch (Exception $e) {
            Log::warning('DNS check failed', [
                'domain' => $domain,
                'error' => $e->getMessage(),
            ]);

            return [
                'resolves' => false,
                'domain_ip' => null,
                'server_ip' => null,
                'match' => false,
                'message' => 'Unable to check DNS: '.$e->getMessage(),
            ];
        }
    }

    public function isCertbotAvailable(): bool
    {
        try {
            exec('which certbot', $output, $exitCode);

            return $exitCode === 0;
        } catch (Exception $e) {
            Log::warning('Certbot availability check failed', ['error' => $e->getMessage()]);

            return false;
        }
    }

    public function issueCertificate(User $reseller): array
    {
        try {
            // Get custom domain from branding settings
            $customDomain = $reseller->settings['branding']['custom_domain'] ?? null;

            if (empty($customDomain)) {
                return $this->recordSslFailure($reseller, $customDomain, 'No custom domain configured.');
            }

            if (! $this->isCertbotAvailable()) {
                return $this->recordSslFailure(
                    $reseller,
                    $customDomain,
                    'certbot is not installed on this server. Ask your administrator to install it (e.g. sudo apt install certbot).',
                );
            }

            $dnsCheck = $this->checkDns($customDomain);
            if (! $dnsCheck['match']) {
                return $this->recordSslFailure(
                    $reseller,
                    $customDomain,
                    'Custom domain does not resolve to this server. Point your DNS A record to: '.($dnsCheck['server_ip'] ?? 'this server\'s IP'),
                );
            }

            // Create challenge directory
            $challengePath = public_path('.well-known/acme-challenge');
            if (! is_dir($challengePath)) {
                @mkdir($challengePath, 0755, true);
                Log::info('Created ACME challenge directory', ['path' => $challengePath]);
            }

            $run = $this->runCertbotIssue($reseller, $customDomain);
            $exitCode = $run['exit_code'];
            $outputText = $run['output'];
            $command = $run['command'];

            if ($exitCode !== 0) {
                Log::error('certbot failed for reseller', [
                    'reseller_id' => $reseller->id,
                    'domain' => $customDomain,
                    'exit_code' => $exitCode,
                    'output' => $outputText,
                    'logs_dir' => $run['logs_dir'],
                ]);

                return $this->recordSslFailure(
                    $reseller,
                    $customDomain,
                    $this->summarizeCertbotFailure($outputText),
                    $outputText,
                    [
                        'exit_code' => $exitCode,
                        'command' => $command,
                        'logs_dir' => $run['logs_dir'],
                    ],
                );
            }

            // Certbot succeeded - verify cert files exist
            $certPath = "/etc/letsencrypt/live/{$customDomain}/fullchain.pem";
            $keyPath = "/etc/letsencrypt/live/{$customDomain}/privkey.pem";

            if (! file_exists($certPath) || ! file_exists($keyPath)) {
                Log::error('Certificate files not found after certbot', [
                    'reseller_id' => $reseller->id,
                    'domain' => $customDomain,
                    'cert_path' => $certPath,
                    'key_path' => $keyPath,
                ]);

                return $this->recordSslFailure(
                    $reseller,
                    $customDomain,
                    'Certificate files were not found after certbot finished. Expected: '.$certPath,
                    $outputText,
                    [
                        'exit_code' => $exitCode,
                        'command' => $command,
                        'logs_dir' => $run['logs_dir'],
                    ],
                );
            }

            // Get certificate expiry
            $expiryDate = $this->getCertificateExpiry($certPath);

            // Store in settings
            $this->updateSslStatus($reseller, [
                'status' => 'active',
                'domain' => $customDomain,
                'cert_path' => $certPath,
                'key_path' => $keyPath,
                'issued_at' => now()->toIso8601String(),
                'expires_at' => $expiryDate?->toIso8601String(),
                'error' => null,
            ]);

            Log::info('Certificate issued successfully for reseller', [
                'reseller_id' => $reseller->id,
                'domain' => $customDomain,
                'expires_at' => $expiryDate,
            ]);

            return [
                'success' => true,
                'message' => 'SSL certificate issued successfully. Valid until '.($expiryDate?->format('M d, Y') ?? 'unknown'),
                'expires_at' => $expiryDate?->toIso8601String(),
            ];
        } catch (Exception $e) {
            Log::error('Exception while issuing certificate', [
                'reseller_id' => $reseller->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $domain = $reseller->settings['branding']['custom_domain'] ?? null;

            return $this->recordSslFailure(
                $reseller,
                is_string($domain) ? $domain : null,
                'An error occurred while issuing the certificate: '.$e->getMessage(),
            );
        }
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array{success: false, message: string}
     */
    public function recordSslFailure(
        User $reseller,
        ?string $domain,
        string $message,
        ?string $rawOutput = null,
        array $context = [],
    ): array {
        $logsDir = isset($context['logs_dir']) ? (string) $context['logs_dir'] : null;

        if ($rawOutput !== null && trim($rawOutput) !== '') {
            $rawOutput = $this->enrichCertbotOutput(trim($rawOutput), $logsDir);
        } elseif ($context !== []) {
            $rawOutput = $this->buildFailureDiagnostics($context);
        } else {
            $rawOutput = '';
        }

        $message = trim($message) !== '' ? trim($message) : 'SSL provisioning failed.';

        if ($this->isBoilerplateSslMessage($message) && $rawOutput !== '') {
            $message = $this->summarizeCertbotFailure($rawOutput);
        }

        if ($this->isBoilerplateSslMessage($message)) {
            $message = 'Certificate issuance failed. See the certbot output below for details.';
        }

        $this->updateSslStatus($reseller, [
            'status' => 'failed',
            'domain' => $domain,
            'error' => Str::limit($message, 1000),
            'last_output' => $rawOutput !== '' ? Str::limit($rawOutput, 8000) : null,
            'last_exit_code' => $context['exit_code'] ?? null,
            'last_command' => isset($context['command']) ? Str::limit((string) $context['command'], 500) : null,
            'last_attempt_at' => now()->toIso8601String(),
        ]);

        return [
            'success' => false,
            'message' => $message,
        ];
    }

    /**
     * @return array{exit_code: int, output: string, command: string, logs_dir: string}
     */
    public function runCertbotIssue(User $reseller, string $customDomain): array
    {
        $logsDir = storage_path('app/ssl-provisioning/reseller-'.$reseller->id.'/logs');
        if (! is_dir($logsDir)) {
            mkdir($logsDir, 0755, true);
        }

        $command = $this->buildSslProvisionCommand($customDomain, $logsDir);

        Log::info('Running certbot for reseller', [
            'reseller_id' => $reseller->id,
            'domain' => $customDomain,
            'command' => $command,
            'logs_dir' => $logsDir,
            'php_user' => get_current_user(),
        ]);

        $output = [];
        $exitCode = 1;
        exec($command.' 2>&1', $output, $exitCode);
        $outputText = implode("\n", $output);

        if (trim($outputText) === '') {
            $shellOutput = shell_exec($command.' 2>&1');
            if (is_string($shellOutput) && trim($shellOutput) !== '') {
                $outputText = $shellOutput;
            }
        }

        $outputText = $this->enrichCertbotOutput($outputText, $logsDir);
        $outputText = $this->appendLogsFromDirectory($outputText, $logsDir);

        return [
            'exit_code' => $exitCode,
            'output' => $outputText,
            'command' => $command,
            'logs_dir' => $logsDir,
        ];
    }

    public function buildSslProvisionCommand(string $customDomain, string $logsDir): string
    {
        $script = $this->resolveProvisionScriptPath();

        if ($script !== null && config('app.reseller_ssl_use_provision_script', true)) {
            return $this->buildProvisionScriptCommand($customDomain, $logsDir, $script);
        }

        return $this->buildCertbotIssueCommand($customDomain, $logsDir);
    }

    public function buildCertbotIssueCommand(string $customDomain, string $logsDir): string
    {
        $adminEmail = Setting::getValue('admin_email', 'admin@talksasa.cloud');
        $webroot = public_path();
        $certbot = (string) config('app.reseller_ssl_certbot_path', 'certbot');
        $prefix = config('app.reseller_ssl_certbot_sudo', false) ? 'sudo -n ' : '';

        return $prefix
            .escapeshellcmd($certbot).' certonly --webroot'
            .' -d '.escapeshellarg($customDomain)
            .' --webroot-path '.escapeshellarg($webroot)
            .' --non-interactive --agree-tos'
            .' --email '.escapeshellarg($adminEmail)
            .' --logs-dir '.escapeshellarg($logsDir);
    }

    public function buildProvisionScriptCommand(string $customDomain, string $logsDir, string $script): string
    {
        $adminEmail = Setting::getValue('admin_email', 'admin@talksasa.cloud');
        $webroot = public_path();
        $prefix = config('app.reseller_ssl_certbot_sudo', false) ? 'sudo -n ' : '';

        return $prefix
            .escapeshellarg($script)
            .' --domain '.escapeshellarg($customDomain)
            .' --webroot '.escapeshellarg($webroot)
            .' --email '.escapeshellarg($adminEmail)
            .' --logs-dir '.escapeshellarg($logsDir);
    }

    public function resolveProvisionScriptPath(): ?string
    {
        $configured = config('app.reseller_ssl_provision_script');
        $candidates = array_filter([
            is_string($configured) && $configured !== '' ? $configured : null,
            base_path('scripts/reseller-ssl/provision.sh'),
        ]);

        foreach ($candidates as $path) {
            if (is_file($path) && is_executable($path)) {
                return $path;
            }
        }

        foreach ($candidates as $path) {
            if (is_file($path)) {
                return $path;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function buildFailureDiagnostics(array $context): string
    {
        $lines = [
            '--- SSL provisioning diagnostics ---',
            'Time: '.now()->toIso8601String(),
            'PHP user: '.(function_exists('posix_getpwuid') && function_exists('posix_geteuid')
                ? (posix_getpwuid(posix_geteuid())['name'] ?? get_current_user())
                : get_current_user()),
        ];

        if (isset($context['exit_code'])) {
            $lines[] = 'Certbot exit code: '.$context['exit_code'];
        }

        if (! empty($context['command'])) {
            $lines[] = 'Command: '.$context['command'];
        }

        if (! empty($context['logs_dir'])) {
            $lines[] = 'Logs directory: '.$context['logs_dir'];
            $lines[] = $this->appendLogsFromDirectory('', (string) $context['logs_dir']);
        }

        if (! config('app.reseller_ssl_certbot_sudo', false)) {
            $lines[] = 'Tip: run once on the server: sudo bash scripts/reseller-ssl/install-host.sh — then set RESELLER_SSL_CERTBOT_SUDO=true.';
        }

        $lines[] = 'Also check storage/logs/laravel.log on this server for "certbot failed for reseller".';

        return trim(implode("\n", array_filter($lines)));
    }

    private function appendLogsFromDirectory(string $output, string $logsDir): string
    {
        if (! is_dir($logsDir)) {
            return $output;
        }

        $files = glob($logsDir.'/*') ?: [];
        usort($files, fn ($a, $b) => filemtime($b) <=> filemtime($a));

        foreach (array_slice($files, 0, 3) as $file) {
            if (! is_file($file)) {
                continue;
            }

            $tail = $this->readFileTail($file, 4000)
                ?: $this->tailLogViaShell($file, 4000);

            if ($tail === '') {
                continue;
            }

            $output .= ($output !== '' ? "\n\n" : '')
                .'--- Log file: '.basename($file)." ---\n"
                .$tail;
        }

        return $output;
    }

    public function isBoilerplateSslMessage(string $message): bool
    {
        $normalized = strtolower(trim($message));

        if ($normalized === '') {
            return true;
        }

        return (bool) preg_match('/^the following error was encountered:?$/i', $message)
            || str_contains($normalized, 'following error was encountered')
                && ! preg_match('/(detail:|invalid|unauthorized|404|403|challenge|could not)/i', $message);
    }

    /**
     * @return array{error: string, output: string, show_output: bool}
     */
    public function resolveSslFailureDisplay(array $ssl): array
    {
        $error = trim((string) ($ssl['error'] ?? ''));
        $output = trim((string) ($ssl['last_output'] ?? ''));

        if ($this->isBoilerplateSslMessage($error) && $output !== '') {
            $error = $this->summarizeCertbotFailure($output);
        } elseif ($this->isBoilerplateSslMessage($error)) {
            $error = '';
        }

        if ($error === '' && $output !== '') {
            $error = $this->summarizeCertbotFailure($output);
        }

        if ($this->isBoilerplateSslMessage($error)) {
            $error = 'Certificate issuance failed. See the certbot output below for details.';
        }

        if ($output === '' && ! empty($ssl['last_command'])) {
            $output = 'Command: '.$ssl['last_command'];
            if (isset($ssl['last_exit_code'])) {
                $output .= "\nExit code: ".$ssl['last_exit_code'];
            }
        }

        $showOutput = $output !== ''
            && ! Str::startsWith(strtolower($output), strtolower($error));

        return [
            'error' => $error,
            'output' => $output,
            'show_output' => $showOutput,
        ];
    }

    public function summarizeCertbotFailure(string $output): string
    {
        $lines = array_values(array_filter(array_map('trim', explode("\n", $output))));

        $skipPatterns = [
            '/^to fix these errors,?/i',
            '/^please visit/i',
            '/^hint:/i',
            '/^\-+$/',
            '/^certbot failed/i',
            '/^saving debug log to/i',
        ];

        $meaningful = [];
        foreach ($lines as $line) {
            if ($line === '') {
                continue;
            }

            if (preg_match('/^the following error was encountered:?\s*(.+)$/i', $line, $matches)) {
                $remainder = trim($matches[1]);
                if ($remainder !== '' && ! $this->isBoilerplateSslMessage($remainder)) {
                    $meaningful[] = $remainder;
                }

                continue;
            }

            if ($this->isBoilerplateSslMessage($line)) {
                continue;
            }

            $skip = false;
            foreach ($skipPatterns as $pattern) {
                if (preg_match($pattern, $line)) {
                    $skip = true;
                    break;
                }
            }

            if (! $skip) {
                $meaningful[] = $line;
            }
        }

        foreach (array_reverse($meaningful) as $line) {
            if (preg_match('/(detail:|type: urn:|problem|unauthorized|invalid|challenge|rate limit|connection|timeout|404|403|could not|denied|refused|letsencrypt log)/i', $line)) {
                return Str::limit($line, 1000);
            }
        }

        $tail = array_slice($meaningful, -6);
        if ($tail !== []) {
            $summary = Str::limit(implode("\n", $tail), 1000);
            if (! $this->isBoilerplateSslMessage($summary)) {
                return $summary;
            }
        }

        $trimmed = Str::limit(trim($output), 1000);
        if ($trimmed !== '' && ! $this->isBoilerplateSslMessage($trimmed)) {
            return $trimmed;
        }

        return 'Certificate issuance failed. See certbot output below or check /var/log/letsencrypt/letsencrypt.log on the server.';
    }

    public function enrichCertbotOutput(string $output, ?string $writableLogsDir = null): string
    {
        $output = trim($output);

        if ($writableLogsDir) {
            $output = $this->appendLogsFromDirectory($output, $writableLogsDir);
        }

        if ($this->hasActionableCertbotDetail($output)) {
            return $output;
        }

        $logPaths = [];
        if (preg_match('/Saving debug log to (.+)$/im', $output, $matches)) {
            $logPaths[] = trim($matches[1]);
        }

        $logPaths[] = '/var/log/letsencrypt/letsencrypt.log';

        foreach (array_unique($logPaths) as $path) {
            $tail = $this->readFileTail($path, 8000)
                ?: $this->tailLogViaShell($path, 8000);

            if ($tail === '') {
                continue;
            }

            return $output.($output !== '' ? "\n\n" : '')
                ."--- Let's Encrypt log (".basename($path).", last 8KB) ---\n"
                .$tail;
        }

        return $output;
    }

    private function tailLogViaShell(string $path, int $maxBytes = 8000): string
    {
        if (! file_exists($path)) {
            return '';
        }

        $escaped = escapeshellarg($path);
        $tail = shell_exec("tail -c {$maxBytes} {$escaped} 2>&1");

        return is_string($tail) ? trim($tail) : '';
    }

    private function hasActionableCertbotDetail(string $output): bool
    {
        $summary = $this->summarizeCertbotFailure($output);

        return ! $this->isBoilerplateSslMessage($summary)
            && ! str_contains(strtolower($summary), 'see certbot output below');
    }

    private function readFileTail(string $path, int $maxBytes): string
    {
        if (! is_readable($path)) {
            return '';
        }

        $size = filesize($path);
        if ($size === false || $size === 0) {
            return '';
        }

        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return '';
        }

        $read = (int) min($maxBytes, $size);
        fseek($handle, -$read, SEEK_END);
        $data = fread($handle, $read);
        fclose($handle);

        return is_string($data) ? $data : '';
    }

    public function renewCertificate(User $reseller): array
    {
        try {
            $customDomain = $reseller->settings['branding']['custom_domain'] ?? null;

            if (empty($customDomain)) {
                return [
                    'success' => false,
                    'message' => 'No custom domain configured.',
                ];
            }

            if (! $this->isCertbotAvailable()) {
                return [
                    'success' => false,
                    'message' => 'certbot is not installed on this server.',
                ];
            }

            $logsDir = storage_path('app/ssl-provisioning/reseller-'.$reseller->id.'/logs');
            if (! is_dir($logsDir)) {
                mkdir($logsDir, 0755, true);
            }

            $script = $this->resolveProvisionScriptPath();
            if ($script !== null && config('app.reseller_ssl_use_provision_script', true)) {
                $command = $this->buildProvisionScriptCommand($customDomain, $logsDir, $script).' --renew 2>&1';
            } else {
                $prefix = config('app.reseller_ssl_certbot_sudo', false) ? 'sudo -n ' : '';
                $certbot = (string) config('app.reseller_ssl_certbot_path', 'certbot');
                $command = $prefix.escapeshellcmd($certbot).' renew --cert-name '
                    .escapeshellarg($customDomain).' --quiet 2>&1';
            }

            Log::info('Running certbot renew for reseller', [
                'reseller_id' => $reseller->id,
                'domain' => $customDomain,
            ]);

            exec($command, $output, $exitCode);
            $outputText = implode("\n", $output);

            if ($exitCode !== 0) {
                $errorMsg = $this->extractErrorFromOutput($outputText);
                Log::error('certbot renew failed for reseller', [
                    'reseller_id' => $reseller->id,
                    'domain' => $customDomain,
                    'exit_code' => $exitCode,
                    'output' => $outputText,
                ]);

                return [
                    'success' => false,
                    'message' => 'Failed to renew certificate: '.$errorMsg,
                ];
            }

            // Get new expiry
            $certPath = "/etc/letsencrypt/live/{$customDomain}/fullchain.pem";
            $expiryDate = $this->getCertificateExpiry($certPath);

            // Update settings
            $settings = $reseller->settings ?? [];
            if (isset($settings['branding']['ssl'])) {
                $settings['branding']['ssl']['expires_at'] = $expiryDate?->toIso8601String();
                $reseller->update(['settings' => $settings]);
            }

            Log::info('Certificate renewed successfully for reseller', [
                'reseller_id' => $reseller->id,
                'domain' => $customDomain,
                'expires_at' => $expiryDate,
            ]);

            return [
                'success' => true,
                'message' => 'SSL certificate renewed successfully. Valid until '.($expiryDate?->format('M d, Y') ?? 'unknown'),
            ];
        } catch (Exception $e) {
            Log::error('Exception while renewing certificate', [
                'reseller_id' => $reseller->id,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => 'An error occurred: '.$e->getMessage(),
            ];
        }
    }

    public function getSslStatus(User $reseller): array
    {
        $defaults = [
            'status' => 'none',
            'domain' => null,
            'cert_path' => null,
            'key_path' => null,
            'issued_at' => null,
            'expires_at' => null,
            'error' => null,
            'queued_reason' => null,
            'queued_at' => null,
            'last_attempt_at' => null,
            'last_output' => null,
            'last_exit_code' => null,
            'last_command' => null,
        ];

        $ssl = $reseller->settings['branding']['ssl'] ?? [];

        return array_merge($defaults, $ssl);
    }

    private function updateSslStatus(User $reseller, array $statusData): void
    {
        $settings = $reseller->settings ?? [];

        if (! isset($settings['branding'])) {
            $settings['branding'] = [];
        }

        $settings['branding']['ssl'] = array_merge(
            $settings['branding']['ssl'] ?? [],
            $statusData
        );

        $reseller->update(['settings' => $settings]);
    }

    private function getCertificateExpiry(string $certPath): ?Carbon
    {
        try {
            if (! file_exists($certPath)) {
                return null;
            }

            exec('openssl x509 -enddate -noout -in '.escapeshellarg($certPath).' 2>/dev/null', $output, $exitCode);

            if ($exitCode !== 0 || empty($output)) {
                return null;
            }

            $dateString = str_replace('notAfter=', '', $output[0]);

            return Carbon::createFromFormat('M d H:i:s Y T', $dateString);
        } catch (Exception $e) {
            Log::warning('Failed to parse certificate expiry', [
                'cert_path' => $certPath,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    private function extractErrorFromOutput(string $output): string
    {
        return $this->summarizeCertbotFailure($output);
    }

    private function canRetry(array $ssl): bool
    {
        return $this->isStale($ssl, self::RETRY_AFTER_MINUTES, 'last_attempt_at');
    }

    private function isStale(array $ssl, int $minutes, string $field = 'queued_at'): bool
    {
        $timestamp = $ssl[$field] ?? $ssl['queued_at'] ?? $ssl['last_attempt_at'] ?? null;

        if (! $timestamp) {
            return true;
        }

        return now()->diffInMinutes(Carbon::parse($timestamp)) >= $minutes;
    }

    private function expiresWithinDays(array $ssl, int $days): bool
    {
        $expiresAt = $ssl['expires_at'] ?? null;

        if (! $expiresAt) {
            return false;
        }

        return Carbon::parse($expiresAt)->lte(now()->addDays($days));
    }
}
