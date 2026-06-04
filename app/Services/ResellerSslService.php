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

            // Get admin email
            $adminEmail = Setting::getValue('admin_email', 'admin@talksasa.cloud');

            // Run certbot — escape all user-supplied and system-derived values
            $webroot = public_path();
            $command = 'certbot certonly --webroot'
                .' -d '.escapeshellarg($customDomain)
                .' --webroot-path '.escapeshellarg($webroot)
                .' --non-interactive --agree-tos'
                .' --email '.escapeshellarg($adminEmail)
                .' 2>&1';

            Log::info('Running certbot for reseller', [
                'reseller_id' => $reseller->id,
                'domain' => $customDomain,
                'command' => $command,
            ]);

            exec($command, $output, $exitCode);
            $outputText = implode("\n", $output);

            if ($exitCode !== 0) {
                Log::error('certbot failed for reseller', [
                    'reseller_id' => $reseller->id,
                    'domain' => $customDomain,
                    'exit_code' => $exitCode,
                    'output' => $outputText,
                ]);

                return $this->recordSslFailure(
                    $reseller,
                    $customDomain,
                    $this->summarizeCertbotFailure($outputText),
                    $outputText,
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
     * @return array{success: false, message: string}
     */
    public function recordSslFailure(
        User $reseller,
        ?string $domain,
        string $message,
        ?string $rawOutput = null,
    ): array {
        $message = trim($message) !== '' ? trim($message) : 'SSL provisioning failed.';

        $this->updateSslStatus($reseller, [
            'status' => 'failed',
            'domain' => $domain,
            'error' => Str::limit($message, 1000),
            'last_output' => $rawOutput ? Str::limit(trim($rawOutput), 8000) : null,
            'last_attempt_at' => now()->toIso8601String(),
        ]);

        return [
            'success' => false,
            'message' => $message,
        ];
    }

    public function summarizeCertbotFailure(string $output): string
    {
        $lines = array_values(array_filter(array_map('trim', explode("\n", $output))));

        $skipPatterns = [
            '/^the following error was encountered:?$/i',
            '/^to fix these errors,?/i',
            '/^please visit/i',
            '/^hint:/i',
            '/^\-+$/',
            '/^certbot failed/i',
        ];

        $meaningful = [];
        foreach ($lines as $line) {
            if ($line === '') {
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
            if (preg_match('/(detail:|type: urn:|problem|unauthorized|invalid|challenge|rate limit|connection|timeout|404|403|could not|denied|refused)/i', $line)) {
                return Str::limit($line, 1000);
            }
        }

        $tail = array_slice($meaningful, -4);
        if ($tail !== []) {
            return Str::limit(implode("\n", $tail), 1000);
        }

        return Str::limit(trim($output), 1000) ?: 'Certificate issuance failed. See certbot output below.';
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

            $command = 'certbot renew --cert-name '.escapeshellarg($customDomain).' --quiet 2>&1';

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
