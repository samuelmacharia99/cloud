<?php

namespace App\Services\Provisioning;

use App\Models\ContainerDomain;
use App\Models\Node;
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
            // Generate nginx config
            $config = $this->generateConfig($domain, false);

            // Connect to node via SSH
            $ssh = new SSHService();
            $ssh->connect($node->ip_address, $node->ssh_username, $node->ssh_private_key);

            // Create sites-enabled directory if needed
            $ssh->exec("mkdir -p /etc/nginx/sites-enabled");

            // Upload config
            $configPath = "/etc/nginx/sites-enabled/{$domain->domain}.conf";
            $ssh->exec("cat > {$configPath}", $config);

            // Test nginx configuration
            $testResult = $ssh->exec("nginx -t 2>&1");
            if (strpos($testResult, 'successful') === false && strpos($testResult, 'ok') === false) {
                // Config test failed, remove the bad config
                $ssh->exec("rm -f {$configPath}");
                throw new Exception("Nginx configuration test failed: {$testResult}");
            }

            // Reload nginx
            $ssh->exec("nginx -s reload");

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
     * Unbind a domain from nginx
     */
    public function unbind(ContainerDomain $domain): void
    {
        $deployment = $domain->deployment;
        $node = $deployment->node;

        if (!$node) {
            throw new Exception('Container deployment has no assigned node');
        }

        try {
            $ssh = new SSHService();
            $ssh->connect($node->ip_address, $node->ssh_username, $node->ssh_private_key);

            // Remove config file
            if ($domain->nginx_config_path) {
                $ssh->exec("rm -f {$domain->nginx_config_path}");
            } else {
                $ssh->exec("rm -f /etc/nginx/sites-enabled/{$domain->domain}.conf");
            }

            // Reload nginx
            $ssh->exec("nginx -s reload");

            $ssh->disconnect();

            // Delete domain record
            $domain->delete();
        } catch (Exception $e) {
            \Log::error("Failed to unbind domain {$domain->domain}: " . $e->getMessage());
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
            $ssh = new SSHService();
            $ssh->connect($node->ip_address, $node->ssh_username, $node->ssh_private_key);

            // Get admin email from settings
            $adminEmail = setting('admin_email', 'admin@talksasa.cloud');

            // Run certbot to obtain certificate
            $certbotCmd = "certbot certonly --nginx -d {$domain->domain} --non-interactive --agree-tos --email {$adminEmail} --redirect 2>&1";
            $certbotResult = $ssh->exec($certbotCmd);

            if (strpos($certbotResult, 'error') !== false || strpos($certbotResult, 'Error') !== false) {
                throw new Exception("Certbot failed: {$certbotResult}");
            }

            // Set certificate paths
            $certPath = "/etc/letsencrypt/live/{$domain->domain}/fullchain.pem";
            $keyPath = "/etc/letsencrypt/live/{$domain->domain}/privkey.pem";

            // Verify certificates exist
            $checkCmd = "[ -f {$certPath} ] && [ -f {$keyPath} ] && echo 'ok' || echo 'fail'";
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
            $configPath = "/etc/nginx/sites-enabled/{$domain->domain}.conf";
            $ssh->exec("cat > {$configPath}", $config);

            // Test and reload
            $testResult = $ssh->exec("nginx -t 2>&1");
            if (strpos($testResult, 'successful') === false && strpos($testResult, 'ok') === false) {
                throw new Exception("Nginx SSL config test failed: {$testResult}");
            }

            $ssh->exec("nginx -s reload");
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
            $ssh = new SSHService();
            $ssh->connect($node->ip_address, $node->ssh_username, $node->ssh_private_key);

            // Renew certificate
            $renewCmd = "certbot renew --cert-name {$domain->domain} --quiet 2>&1";
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

        $httpBlock = <<<EOL
server {
    listen 80;
    server_name {$domain->domain};

    location / {
        proxy_pass http://127.0.0.1:{$port};
        proxy_set_header Host \$host;
        proxy_set_header X-Real-IP \$remote_addr;
        proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto \$scheme;
        proxy_redirect off;
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
    }
}
EOL;
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
}
