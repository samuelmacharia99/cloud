<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Log;
use Exception;

class ResellerSslService
{
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
                'message' => 'Unable to check DNS: ' . $e->getMessage(),
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
                return [
                    'success' => false,
                    'message' => 'No custom domain configured.',
                ];
            }

            // Check if certbot is available
            if (!$this->isCertbotAvailable()) {
                return [
                    'success' => false,
                    'message' => 'certbot is not installed on this server. Install with: sudo apt install certbot',
                ];
            }

            // Check DNS resolution
            $dnsCheck = $this->checkDns($customDomain);
            if (!$dnsCheck['match']) {
                return [
                    'success' => false,
                    'message' => 'Custom domain does not resolve to this server. Ensure your DNS A record points to: ' . $dnsCheck['server_ip'],
                ];
            }

            // Create challenge directory
            $challengePath = public_path('.well-known/acme-challenge');
            if (!is_dir($challengePath)) {
                @mkdir($challengePath, 0755, true);
                Log::info('Created ACME challenge directory', ['path' => $challengePath]);
            }

            // Get admin email
            $adminEmail = \App\Models\Setting::getValue('admin_email', 'admin@talksasa.cloud');

            // Run certbot
            $webroot = public_path();
            $command = "certbot certonly --webroot -d {$customDomain} --webroot-path {$webroot} --non-interactive --agree-tos --email {$adminEmail} 2>&1";

            Log::info('Running certbot for reseller', [
                'reseller_id' => $reseller->id,
                'domain' => $customDomain,
                'command' => $command,
            ]);

            exec($command, $output, $exitCode);
            $outputText = implode("\n", $output);

            if ($exitCode !== 0) {
                $errorMsg = $this->extractErrorFromOutput($outputText);
                Log::error('certbot failed for reseller', [
                    'reseller_id' => $reseller->id,
                    'domain' => $customDomain,
                    'exit_code' => $exitCode,
                    'output' => $outputText,
                ]);

                // Store error in settings
                $this->updateSslStatus($reseller, [
                    'status' => 'failed',
                    'domain' => $customDomain,
                    'error' => $errorMsg,
                ]);

                return [
                    'success' => false,
                    'message' => 'Failed to issue certificate: ' . $errorMsg,
                ];
            }

            // Certbot succeeded - verify cert files exist
            $certPath = "/etc/letsencrypt/live/{$customDomain}/fullchain.pem";
            $keyPath = "/etc/letsencrypt/live/{$customDomain}/privkey.pem";

            if (!file_exists($certPath) || !file_exists($keyPath)) {
                Log::error('Certificate files not found after certbot', [
                    'reseller_id' => $reseller->id,
                    'domain' => $customDomain,
                    'cert_path' => $certPath,
                    'key_path' => $keyPath,
                ]);

                return [
                    'success' => false,
                    'message' => 'Certificate files not found after issuance. Check server logs.',
                ];
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
                'message' => 'SSL certificate issued successfully. Valid until ' . ($expiryDate?->format('M d, Y') ?? 'unknown'),
                'expires_at' => $expiryDate?->toIso8601String(),
            ];
        } catch (Exception $e) {
            Log::error('Exception while issuing certificate', [
                'reseller_id' => $reseller->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return [
                'success' => false,
                'message' => 'An error occurred: ' . $e->getMessage(),
            ];
        }
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

            if (!$this->isCertbotAvailable()) {
                return [
                    'success' => false,
                    'message' => 'certbot is not installed on this server.',
                ];
            }

            $command = "certbot renew --cert-name {$customDomain} --quiet 2>&1";

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
                    'message' => 'Failed to renew certificate: ' . $errorMsg,
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
                'message' => 'SSL certificate renewed successfully. Valid until ' . ($expiryDate?->format('M d, Y') ?? 'unknown'),
            ];
        } catch (Exception $e) {
            Log::error('Exception while renewing certificate', [
                'reseller_id' => $reseller->id,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => 'An error occurred: ' . $e->getMessage(),
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
        ];

        $ssl = $reseller->settings['branding']['ssl'] ?? [];
        return array_merge($defaults, $ssl);
    }

    private function updateSslStatus(User $reseller, array $statusData): void
    {
        $settings = $reseller->settings ?? [];

        if (!isset($settings['branding'])) {
            $settings['branding'] = [];
        }

        $settings['branding']['ssl'] = array_merge(
            $settings['branding']['ssl'] ?? [],
            $statusData
        );

        $reseller->update(['settings' => $settings]);
    }

    private function getCertificateExpiry(string $certPath): ?\Carbon\Carbon
    {
        try {
            if (!file_exists($certPath)) {
                return null;
            }

            exec("openssl x509 -enddate -noout -in {$certPath} 2>/dev/null", $output, $exitCode);

            if ($exitCode !== 0 || empty($output)) {
                return null;
            }

            $dateString = str_replace('notAfter=', '', $output[0]);
            return \Carbon\Carbon::createFromFormat('M d H:i:s Y T', $dateString);
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
        $lines = explode("\n", $output);

        foreach ($lines as $line) {
            if (stripos($line, 'error') !== false || stripos($line, 'failed') !== false) {
                return trim($line);
            }
        }

        return 'Unknown error. Check server logs for details.';
    }
}
