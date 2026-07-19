<?php

namespace App\Services\Provisioning;

use App\Models\ContainerDomain;
use App\Models\Node;
use App\Services\SSH\SSHService;
use Exception;

class NginxProxyService
{
    /**
     * Bind a domain to a container via nginx reverse proxy
     */
    public function bind(ContainerDomain $domain): void
    {
        $deployment = $domain->deployment;
        $node = $deployment->node;

        if (!$node) {
            throw new Exception('Container deployment has no assigned node');
        }

        try {
            // Generate nginx config. Preserve SSL server block when the domain
            // already has certificate paths configured.
            $withSsl = (bool) ($domain->ssl_enabled && $domain->ssl_certificate_path && $domain->ssl_key_path);
            $config = $this->generateConfig($domain, $withSsl);

            // Connect to node via SSH
            $ssh = SSHService::forNode($node);

            if (! $this->isNginxInstalled($ssh)) {
                throw new Exception(
                    "Nginx is not installed on node {$node->hostname} ({$node->ip_address}). " .
                    "Install nginx (and optionally grant sudo for nginx commands) before binding domains."
                );
            }

            $configDir = $this->resolveNginxConfigDir($ssh);
            $ssh->exec("mkdir -p " . escapeshellarg($configDir));

            // Upload config — path is built from domain name, must be escaped
            $safeConfPath = escapeshellarg("{$configDir}/{$domain->domain}.conf");
            $configPath = "{$configDir}/{$domain->domain}.conf";
            $ssh->upload($config, $configPath);

            try {
                $this->testAndReloadNginx($ssh, $node);
            } catch (Exception $nginxError) {
                // Config/test/reload failed; remove the config we just wrote.
                try {
                    $ssh->exec("rm -f {$safeConfPath}");
                } catch (Exception $cleanupError) {
                    \Log::warning("Failed to cleanup nginx config after bind failure", [
                        'node_id' => $node->id,
                        'domain' => $domain->domain,
                        'config_path' => $configPath,
                        'error' => $cleanupError->getMessage(),
                    ]);
                }
                throw new Exception("Failed to validate/reload nginx configuration: {$nginxError->getMessage()}");
            }

            // Update domain status
            $domain->update([
                'status' => 'active',
                'nginx_config_path' => $configPath,
                'verified_at' => now(),
            ]);

            $ssh->disconnect();
        } catch (Exception $e) {
            $domain->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Remove nginx reverse-proxy config for a domain (keeps the database record).
     */
    public function removeProxyConfig(ContainerDomain $domain): void
    {
        $domain->loadMissing('deployment.node');

        $deployment = $domain->deployment;
        $node = $deployment?->node;

        if (! $node) {
            return;
        }

        $ssh = SSHService::forNode($node);

        try {
            if ($domain->nginx_config_path) {
                @$ssh->exec('rm -f '.escapeshellarg($domain->nginx_config_path));
            }

            $fallbackDir = $this->resolveNginxConfigDir($ssh);
            @$ssh->exec('rm -f '.escapeshellarg("{$fallbackDir}/{$domain->domain}.conf"));

            if ($this->isNginxInstalled($ssh)) {
                try {
                    $this->reloadNginx($ssh, $node);
                } catch (Exception $e) {
                    \Log::warning("Failed to reload nginx on node {$node->id}: ".$e->getMessage());
                }
            }
        } finally {
            $ssh->disconnect();
        }
    }

    /**
     * Best-effort removal of a Let's Encrypt certificate for this domain.
     */
    public function cleanupSslCertificate(ContainerDomain $domain): void
    {
        if (! $domain->ssl_enabled) {
            return;
        }

        $domain->loadMissing('deployment.node');
        $node = $domain->deployment?->node;

        if (! $node) {
            return;
        }

        try {
            $ssh = SSHService::forNode($node);
            @$ssh->exec(
                'certbot delete --cert-name '.escapeshellarg($domain->domain).' --non-interactive 2>&1'
            );
            $ssh->disconnect();
        } catch (Exception $e) {
            \Log::warning("Failed to cleanup SSL certificate for {$domain->domain}: ".$e->getMessage());
        }
    }

    /**
     * Unbind a domain from nginx
     */
    public function unbind(ContainerDomain $domain): void
    {
        $domain->loadMissing('deployment.node');

        if (! $domain->deployment?->node) {
            $domain->delete();

            return;
        }

        try {
            $this->removeProxyConfig($domain);
            $this->cleanupSslCertificate($domain);
            $domain->delete();
        } catch (Exception $e) {
            \Log::error("Failed to unbind domain {$domain->domain}: ".$e->getMessage());
            throw $e;
        }
    }

    /**
     * Enable SSL for a domain using certbot
     */
    public function enableSsl(ContainerDomain $domain): void
    {
        $deployment = $domain->deployment;
        $node = $deployment->node;

        if (!$node) {
            throw new Exception('Container deployment has no assigned node');
        }

        try {
            $ssh = SSHService::forNode($node);

            // Get admin email from settings
            $adminEmail = setting('admin_email', 'admin@talksasa.cloud');

            // Run certbot to obtain certificate — escape all user-supplied values
            $certbotCmd = "certbot certonly --nginx -d " . escapeshellarg($domain->domain)
                . " --non-interactive --agree-tos --email " . escapeshellarg($adminEmail)
                . " --redirect 2>&1";
            $certbotResult = $ssh->exec($certbotCmd);

            if (strpos($certbotResult, 'error') !== false || strpos($certbotResult, 'Error') !== false) {
                throw new Exception("Certbot failed: {$certbotResult}");
            }

            // Set certificate paths — derived from domain name, escape in commands
            $certPath = "/etc/letsencrypt/live/{$domain->domain}/fullchain.pem";
            $keyPath = "/etc/letsencrypt/live/{$domain->domain}/privkey.pem";

            // Verify certificates exist
            $checkCmd = "[ -f " . escapeshellarg($certPath) . " ] && [ -f " . escapeshellarg($keyPath) . " ] && echo 'ok' || echo 'fail'";
            $checkResult = trim($ssh->exec($checkCmd));

            if ($checkResult !== 'ok') {
                throw new Exception('Certificate files not found after certbot execution');
            }

            // Update domain with SSL info
            $domain->update([
                'ssl_enabled' => true,
                'ssl_certificate_path' => $certPath,
                'ssl_key_path' => $keyPath,
                'verified_at' => now(),
            ]);

            // Regenerate config with SSL blocks
            $config = $this->generateConfig($domain, true);
            $configPath = $this->resolveNginxConfigDir($ssh) . "/{$domain->domain}.conf";
            $ssh->upload($config, $configPath); // configPath is used as upload destination (not in exec)

            if (! $this->isNginxInstalled($ssh)) {
                throw new Exception('Nginx is required to enable SSL via nginx reverse proxy, but it is not installed.');
            }

            $this->testAndReloadNginx($ssh, $node);

            $ssh->disconnect();
        } catch (Exception $e) {
            $domain->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Renew SSL certificate for a domain
     */
    public function renewSsl(ContainerDomain $domain): void
    {
        $deployment = $domain->deployment;
        $node = $deployment->node;

        if (!$node || !$domain->ssl_enabled) {
            return;
        }

        try {
            $ssh = SSHService::forNode($node);

            // Renew certificate — escape domain name to prevent injection
            $renewCmd = "certbot renew --cert-name " . escapeshellarg($domain->domain) . " --quiet 2>&1";
            $renewResult = $ssh->exec($renewCmd);

            if (strpos($renewResult, 'error') !== false) {
                \Log::warning("SSL renewal warning for {$domain->domain}: {$renewResult}");
            }

            $ssh->disconnect();
        } catch (Exception $e) {
            \Log::error("Failed to renew SSL for {$domain->domain}: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Generate nginx configuration for a domain
     */
    public function generateConfig(ContainerDomain $domain, bool $withSsl = false): string
    {
        $deployment = $domain->deployment;
        $node = $deployment->node;
        $port = $deployment->assigned_port;

        // Default nginx client_max_body_size is 1m — WordPress media uploads return 413 without this.
        $uploadLimit = $this->clientMaxBodySize();

        $httpBlock = <<<EOL
server {
    listen 80;
    server_name {$domain->domain};
    client_max_body_size {$uploadLimit};

    location / {
        proxy_pass http://127.0.0.1:{$port};
        proxy_set_header Host \$host;
        proxy_set_header X-Real-IP \$remote_addr;
        proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto \$scheme;
        proxy_redirect off;
        proxy_request_buffering off;
        proxy_read_timeout 300s;
        proxy_send_timeout 300s;
    }
}
EOL;

        if (!$withSsl) {
            return $httpBlock;
        }

        $certPath = $domain->ssl_certificate_path;
        $keyPath = $domain->ssl_key_path;

        return <<<EOL
server {
    listen 80;
    server_name {$domain->domain};
    return 301 https://\$server_name\$request_uri;
}

server {
    listen 443 ssl http2;
    server_name {$domain->domain};
    client_max_body_size {$uploadLimit};

    ssl_certificate {$certPath};
    ssl_certificate_key {$keyPath};
    ssl_protocols TLSv1.2 TLSv1.3;
    ssl_ciphers HIGH:!aNULL:!MD5;
    ssl_prefer_server_ciphers on;

    location / {
        proxy_pass http://127.0.0.1:{$port};
        proxy_set_header Host \$host;
        proxy_set_header X-Real-IP \$remote_addr;
        proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto \$scheme;
        proxy_redirect off;
        proxy_request_buffering off;
        proxy_read_timeout 300s;
        proxy_send_timeout 300s;
    }
}
EOL;
    }

    /**
     * Rewrite nginx vhost if it is missing client_max_body_size (legacy 1m default → WP 413).
     *
     * @return bool true when the config was rewritten
     */
    public function ensureUploadLimit(ContainerDomain $domain): bool
    {
        $domain->loadMissing('deployment.node');

        $node = $domain->deployment?->node;
        if (! $node || ! in_array($domain->status, ['active', 'pending'], true)) {
            return false;
        }

        $ssh = SSHService::forNode($node);

        try {
            if (! $this->isNginxInstalled($ssh)) {
                return false;
            }

            $configPath = $domain->nginx_config_path
                ?: $this->resolveNginxConfigDir($ssh).'/'.$domain->domain.'.conf';

            $existing = '';
            try {
                $existing = $ssh->downloadFile($configPath);
            } catch (\Throwable) {
                // Missing file — full bind will recreate it.
            }

            $required = 'client_max_body_size '.$this->clientMaxBodySize();
            if ($existing !== '' && str_contains($existing, $required)) {
                return false;
            }

            $this->bind($domain->fresh(['deployment.node']));

            return true;
        } finally {
            $ssh->disconnect();
        }
    }

    /**
     * Refresh upload limits for every active/pending container domain.
     *
     * @return array{checked: int, updated: int, failed: int}
     */
    public function ensureUploadLimitsForAllDomains(): array
    {
        $summary = ['checked' => 0, 'updated' => 0, 'failed' => 0];

        $domains = ContainerDomain::query()
            ->whereIn('status', ['active', 'pending'])
            ->with('deployment.node')
            ->get();

        foreach ($domains as $domain) {
            $summary['checked']++;
            try {
                if ($this->ensureUploadLimit($domain)) {
                    $summary['updated']++;
                }
            } catch (\Throwable $e) {
                $summary['failed']++;
                \Log::warning('Failed to refresh nginx upload limit for domain', [
                    'domain' => $domain->domain,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $summary;
    }

    /**
     * Max upload size for container reverse-proxy vhosts (WordPress media, etc.).
     */
    public function clientMaxBodySize(): string
    {
        $mb = (int) config('security.container_file_upload.max_size_mb', 100);
        if ($mb < 1) {
            $mb = 100;
        }

        return $mb.'M';
    }

    /**
     * Check if DNS A record points to the node IP
     */
    public function checkDns(string $domain, string $expectedIp): bool
    {
        try {
            $records = dns_get_record($domain, DNS_A);
            if (empty($records)) {
                return false;
            }

            foreach ($records as $record) {
                if ($record['ip'] === $expectedIp) {
                    return true;
                }
            }

            return false;
        } catch (Exception $e) {
            \Log::warning("Failed to check DNS for {$domain}: " . $e->getMessage());
            return false;
        }
    }

    private function isNginxInstalled(SSHService $ssh): bool
    {
        $result = trim($ssh->exec("if command -v nginx >/dev/null 2>&1; then echo yes; else echo no; fi"));
        return $result === 'yes';
    }

    private function resolveNginxConfigDir(SSHService $ssh): string
    {
        $result = trim($ssh->exec(
            "if [ -d /etc/nginx/sites-enabled ]; then echo /etc/nginx/sites-enabled; " .
            "elif [ -d /etc/nginx/conf.d ]; then echo /etc/nginx/conf.d; " .
            "else echo /etc/nginx/sites-enabled; fi"
        ));

        return $result !== '' ? $result : '/etc/nginx/sites-enabled';
    }

    private function testAndReloadNginx(SSHService $ssh, Node $node): void
    {
        $this->testNginxConfig($ssh, $node);
        $this->reloadNginx($ssh, $node);
    }

    private function testNginxConfig(SSHService $ssh, Node $node): void
    {
        try {
            $ssh->exec("nginx -t 2>&1");
            return;
        } catch (Exception $directError) {
            // Fall back to sudo if direct command is not permitted.
            try {
                $ssh->exec("sudo -n nginx -t 2>&1");
                return;
            } catch (Exception $sudoError) {
                throw new Exception(
                    "nginx -t failed on node {$node->id}. " .
                    "Direct error: {$directError->getMessage()} | Sudo error: {$sudoError->getMessage()}"
                );
            }
        }
    }

    private function reloadNginx(SSHService $ssh, Node $node): void
    {
        try {
            $ssh->exec("nginx -s reload");
            return;
        } catch (Exception $directError) {
            try {
                $ssh->exec("sudo -n nginx -s reload");
                return;
            } catch (Exception $sudoError) {
                throw new Exception(
                    "nginx reload failed on node {$node->id}. " .
                    "Direct error: {$directError->getMessage()} | Sudo error: {$sudoError->getMessage()}"
                );
            }
        }
    }
}
