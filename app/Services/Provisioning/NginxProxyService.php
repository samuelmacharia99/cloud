<?php

namespace App\Services\Provisioning;

use App\Models\ContainerDomain;
use App\Models\Node;
use App\Models\Service;
use App\Services\SSH\SSHService;
use Exception;

class NginxProxyService
{
    /**
     * Bump when the generated vhost changes so existing sites are rewritten.
     */
    public const VHOST_REVISION = 'v6';

    /**
     * Shared static pages on each container node. Suspended sites stop proxying
     * to a dead upstream (raw nginx 502) and serve these instead.
     */
    public const EDGE_PAGES_DIR = '/var/www/talksasa-edge';

    /**
     * Bind a domain to a container via nginx reverse proxy.
     *
     * `$suspended` parks the vhost on the shared edge page. Null means detect
     * from the service status so a bind during billing suspend stays parked.
     */
    public function bind(ContainerDomain $domain, ?bool $suspended = null): void
    {
        $deployment = $domain->deployment;
        $node = $deployment->node;

        if (! $node) {
            throw new Exception('Container deployment has no assigned node');
        }

        try {
            // Generate nginx config. Preserve SSL server block when the domain
            // already has certificate paths configured.
            $withSsl = (bool) ($domain->ssl_enabled && $domain->ssl_certificate_path && $domain->ssl_key_path);
            $suspended ??= $this->domainShouldServeSuspendedPage($domain);
            $config = $this->generateConfig($domain, $withSsl, $suspended);

            // Connect to node via SSH
            $ssh = SSHService::forNode($node);

            if (! $this->isNginxInstalled($ssh)) {
                throw new Exception(
                    "Nginx is not installed on node {$node->hostname} ({$node->ip_address}). ".
                    'Install nginx (and optionally grant sudo for nginx commands) before binding domains.'
                );
            }

            $this->ensureEdgePages($ssh);

            $configDir = $this->resolveNginxConfigDir($ssh);
            $ssh->exec('mkdir -p '.escapeshellarg($configDir));

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
                    \Log::warning('Failed to cleanup nginx config after bind failure', [
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

        if (! $node) {
            throw new Exception('Container deployment has no assigned node');
        }

        try {
            $ssh = SSHService::forNode($node);

            // Get admin email from settings
            $adminEmail = setting('admin_email', 'admin@talksasa.cloud');

            // Run certbot to obtain certificate — escape all user-supplied values
            $certbotCmd = 'certbot certonly --nginx -d '.escapeshellarg($domain->domain)
                .' --non-interactive --agree-tos --email '.escapeshellarg($adminEmail)
                .' --redirect 2>&1';
            $certbotResult = $ssh->exec($certbotCmd);

            if (strpos($certbotResult, 'error') !== false || strpos($certbotResult, 'Error') !== false) {
                throw new Exception("Certbot failed: {$certbotResult}");
            }

            // Set certificate paths — derived from domain name, escape in commands
            $certPath = "/etc/letsencrypt/live/{$domain->domain}/fullchain.pem";
            $keyPath = "/etc/letsencrypt/live/{$domain->domain}/privkey.pem";

            // Verify certificates exist
            $checkCmd = '[ -f '.escapeshellarg($certPath).' ] && [ -f '.escapeshellarg($keyPath)." ] && echo 'ok' || echo 'fail'";
            $checkResult = trim($ssh->exec($checkCmd));

            if ($checkResult !== 'ok') {
                throw new Exception('Certificate files not found after certbot execution');
            }

            // Update domain with SSL info
            $domain->update([
                'status' => 'active',
                'ssl_enabled' => true,
                'ssl_certificate_path' => $certPath,
                'ssl_key_path' => $keyPath,
                'verified_at' => now(),
                'error_message' => null,
            ]);

            // Regenerate config with SSL blocks (keep a parked page if suspended)
            $this->ensureEdgePages($ssh);
            $config = $this->generateConfig($domain, true);
            $configPath = $this->resolveNginxConfigDir($ssh)."/{$domain->domain}.conf";
            $ssh->upload($config, $configPath); // configPath is used as upload destination (not in exec)

            if (! $this->isNginxInstalled($ssh)) {
                throw new Exception('Nginx is required to enable SSL via nginx reverse proxy, but it is not installed.');
            }

            $this->testAndReloadNginx($ssh, $node);

            $ssh->disconnect();
        } catch (Exception $e) {
            $this->recordSslFailure($domain, $e);
            throw $e;
        }
    }

    /**
     * Persist an SSL issuance failure without taking down an already-bound vhost.
     */
    public function recordSslFailure(ContainerDomain $domain, \Throwable $e): void
    {
        $keepBound = $domain->status === 'active' || filled($domain->nginx_config_path);

        $domain->update([
            'status' => $keepBound ? 'active' : 'failed',
            'ssl_enabled' => false,
            'error_message' => $e->getMessage(),
        ]);
    }

    /**
     * Renew SSL certificate for a domain
     */
    public function renewSsl(ContainerDomain $domain): void
    {
        $deployment = $domain->deployment;
        $node = $deployment->node;

        if (! $node || ! $domain->ssl_enabled) {
            return;
        }

        try {
            $ssh = SSHService::forNode($node);

            // Renew certificate — escape domain name to prevent injection
            $renewCmd = 'certbot renew --cert-name '.escapeshellarg($domain->domain).' --quiet 2>&1';
            $renewResult = $ssh->exec($renewCmd);

            if (strpos($renewResult, 'error') !== false) {
                \Log::warning("SSL renewal warning for {$domain->domain}: {$renewResult}");
            }

            $ssh->disconnect();
        } catch (Exception $e) {
            \Log::error("Failed to renew SSL for {$domain->domain}: ".$e->getMessage());
            throw $e;
        }
    }

    /**
     * Generate nginx configuration for a domain.
     *
     * Suspended mode serves HTTP 503 from the shared edge page instead of
     * proxy_pass (a stopped container otherwise becomes the stock nginx 502).
     */
    public function generateConfig(ContainerDomain $domain, bool $withSsl = false, ?bool $suspended = null): string
    {
        $deployment = $domain->deployment;
        $port = $deployment->assigned_port;

        $suspended ??= $this->domainShouldServeSuspendedPage($domain);

        // Default nginx client_max_body_size is 1m — WordPress media uploads return 413 without this.
        $uploadLimit = $this->clientMaxBodySize();
        $revision = self::VHOST_REVISION;
        $modeMarker = $suspended ? '# talksasa-edge-suspended' : '# talksasa-edge-proxy';

        $location = $suspended
            ? $this->suspendedLocation()
            : $this->proxyPassLocation((int) $port)."\n".$this->unavailableErrorPageLocation();

        $httpBlock = <<<EOL
# talksasa-vhost {$revision}
{$modeMarker}
server {
    listen 80;
    server_name {$domain->domain};
    client_max_body_size {$uploadLimit};
    client_body_timeout 300s;

{$location}
}
EOL;

        if (! $withSsl) {
            return $httpBlock;
        }

        $certPath = $domain->ssl_certificate_path;
        $keyPath = $domain->ssl_key_path;

        return <<<EOL
# talksasa-vhost {$revision}
{$modeMarker}
server {
    listen 80;
    server_name {$domain->domain};
    return 301 https://\$server_name\$request_uri;
}

server {
    listen 443 ssl http2;
    server_name {$domain->domain};
    client_max_body_size {$uploadLimit};
    client_body_timeout 300s;

    ssl_certificate {$certPath};
    ssl_certificate_key {$keyPath};
    ssl_protocols TLSv1.2 TLSv1.3;
    ssl_ciphers HIGH:!aNULL:!MD5;
    ssl_prefer_server_ciphers on;

{$location}
}
EOL;
    }

    /**
     * Cookie sessions (Ultimate POS /home login) send a large encrypted Set-Cookie.
     * Default proxy_buffer_size is 4k/8k, which 502s that route while GET / still works.
     *
     * Hermes / OpenClaw / n8n chat UIs need a real WebSocket upgrade. Clearing
     * Connection (HTTP keepalive) makes the browser see close code 1006.
     */
    public function proxyPassLocation(int $port): string
    {
        return <<<EOL
    location / {
        proxy_pass http://127.0.0.1:{$port};
        proxy_http_version 1.1;
        proxy_set_header Host \$host;
        proxy_set_header X-Real-IP \$remote_addr;
        proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Host \$host;
        proxy_set_header X-Forwarded-Proto \$scheme;
        proxy_set_header Upgrade \$http_upgrade;
        proxy_set_header Connection "upgrade";
        proxy_redirect off;
        proxy_read_timeout 3600s;
        proxy_send_timeout 3600s;
        proxy_buffer_size 128k;
        proxy_buffers 4 256k;
        proxy_busy_buffers_size 256k;
    }
EOL;
    }

    /**
     * Park the hostname: keep TLS, stop proxying, return 503 with the owner CTA.
     */
    public function suspendedLocation(): string
    {
        $root = self::EDGE_PAGES_DIR;

        return <<<EOL
    error_page 503 /suspended.html;
    location = /suspended.html {
        root {$root};
        internal;
        default_type text/html;
        add_header Retry-After 3600 always;
        add_header Cache-Control "no-store" always;
    }
    location / {
        return 503;
    }
EOL;
    }

    /**
     * Replace the stock nginx 502/504 page when the container is down unexpectedly.
     */
    public function unavailableErrorPageLocation(): string
    {
        $root = self::EDGE_PAGES_DIR;

        return <<<EOL
    error_page 502 503 504 /unavailable.html;
    location = /unavailable.html {
        root {$root};
        internal;
        default_type text/html;
        add_header Cache-Control "no-store" always;
    }
EOL;
    }

    public function domainShouldServeSuspendedPage(ContainerDomain $domain): bool
    {
        $domain->loadMissing('deployment.service');

        return $domain->deployment?->service?->isSuspended() === true;
    }

    /**
     * Rewrite bound vhosts to the parked 503 page. Called before docker stop so
     * visitors never see the default nginx 502 while the container is dying.
     */
    public function applySuspendedVhosts(Service $service): int
    {
        return $this->refreshBoundDomainVhosts($service, force: true, suspended: true, throwOnFailure: true);
    }

    /**
     * Restore proxy_pass after the stack is running again.
     */
    public function restoreProxyVhosts(Service $service): int
    {
        return $this->refreshBoundDomainVhosts($service, force: true, suspended: false, throwOnFailure: true);
    }

    /**
     * Upload the shared edge HTML once per node so error_page has a real file.
     */
    public function ensureEdgePages($ssh): void
    {
        $dir = self::EDGE_PAGES_DIR;
        $ssh->exec('mkdir -p '.escapeshellarg($dir));
        $ssh->upload($this->suspendedPageHtml(), $dir.'/suspended.html');
        $ssh->upload($this->unavailablePageHtml(), $dir.'/unavailable.html');
    }

    public function suspendedPageHtml(): string
    {
        return $this->edgePageHtml(
            title: 'This website is paused',
            heading: 'This website is paused',
            body: 'The hosting account for this site is currently paused. If you are the owner, sign in to see why and restore it.',
            cta: 'Sign in to restore this site',
        );
    }

    public function unavailablePageHtml(): string
    {
        return $this->edgePageHtml(
            title: 'This website is temporarily unavailable',
            heading: 'This website is temporarily unavailable',
            body: 'The site could not be reached just now. Please try again shortly. If you are the owner, check the service status in your hosting account.',
            cta: 'Open your hosting account',
        );
    }

    private function edgePageHtml(string $title, string $heading, string $body, string $cta): string
    {
        $company = htmlspecialchars((string) config('app.name', 'Talksasa Cloud'), ENT_QUOTES, 'UTF-8');
        $portal = htmlspecialchars(url('/login'), ENT_QUOTES, 'UTF-8');
        $title = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
        $heading = htmlspecialchars($heading, ENT_QUOTES, 'UTF-8');
        $body = htmlspecialchars($body, ENT_QUOTES, 'UTF-8');
        $cta = htmlspecialchars($cta, ENT_QUOTES, 'UTF-8');

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{$title}</title>
    <style>
        :root { color-scheme: light dark; }
        body { margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center;
            font-family: ui-sans-serif, system-ui, -apple-system, "Segoe UI", sans-serif;
            background: #0f172a; color: #e2e8f0; padding: 24px; }
        main { max-width: 36rem; width: 100%; background: #111827; border: 1px solid #334155;
            border-radius: 16px; padding: 2rem 1.75rem; box-shadow: 0 20px 40px rgba(0,0,0,.35); }
        .mark { display: inline-block; font-size: 11px; letter-spacing: .18em; text-transform: uppercase;
            color: #5eead4; margin-bottom: 1rem; }
        h1 { margin: 0 0 .75rem; font-size: 1.5rem; line-height: 1.3; color: #f8fafc; }
        p { margin: 0 0 1.5rem; line-height: 1.6; color: #cbd5e1; }
        a.cta { display: inline-block; background: #0f766e; color: #f8fafc; text-decoration: none;
            font-weight: 600; padding: .7rem 1.1rem; border-radius: 10px; }
        a.cta:hover { background: #0d9488; }
        .fine { margin: 1.25rem 0 0; font-size: .85rem; color: #94a3b8; }
    </style>
</head>
<body>
    <main>
        <div class="mark">{$company}</div>
        <h1>{$heading}</h1>
        <p>{$body}</p>
        <a class="cta" href="{$portal}">{$cta}</a>
        <p class="fine">If you reached this page by mistake, try again in a few minutes.</p>
    </main>
</body>
</html>
HTML;
    }

    /**
     * Rewrite nginx vhosts generated before the current template. Older files kept the
     * 1m body default (WordPress 413), proxied over HTTP/1.0 with request buffering
     * disabled, or used 4k/8k header buffers that 502 cookie-session login pages.
     *
     * @return bool true when the config was rewritten
     */
    public function ensureUploadLimit(ContainerDomain $domain, bool $force = false, ?bool $suspended = null): bool
    {
        $domain->loadMissing('deployment.node', 'deployment.service');

        $node = $domain->deployment?->node;
        if (! $node || ! in_array($domain->status, ['active', 'pending'], true)) {
            return false;
        }

        $suspended ??= $this->domainShouldServeSuspendedPage($domain);
        $ssh = SSHService::forNode($node);

        try {
            if (! $this->isNginxInstalled($ssh)) {
                return false;
            }

            $configPath = $this->vhostConfigPath($ssh, $domain);

            $existing = '';
            try {
                $existing = $ssh->downloadFile($configPath);
            } catch (\Throwable) {
                // Missing file — full bind will recreate it.
            }

            if (! $force && $existing !== '' && $this->vhostIsCurrent($existing, $suspended)) {
                return false;
            }

            $this->bind($domain->fresh(['deployment.node', 'deployment.service']), $suspended);

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
     * Rewrite this service's bound vhosts. `$force` rewrites even when the revision
     * marker already matches, so login 502s are not stuck on a silently skipped vhost.
     */
    public function refreshBoundDomainVhosts(Service $service, bool $force = false, ?bool $suspended = null, bool $throwOnFailure = false): int
    {
        $service->loadMissing('containerDeployment.domains');

        $domains = $service->containerDeployment?->domains;
        if (! $domains || $domains->isEmpty()) {
            return 0;
        }

        $updated = 0;
        foreach ($domains as $domain) {
            try {
                if ($this->ensureUploadLimit($domain, $force, $suspended)) {
                    $updated++;
                }
            } catch (\Throwable $e) {
                \Log::warning('Failed to refresh nginx vhost for domain', [
                    'service_id' => $service->id,
                    'domain' => $domain->domain,
                    'error' => $e->getMessage(),
                ]);

                if ($throwOnFailure) {
                    throw $e;
                }
            }
        }

        return $updated;
    }

    public function vhostConfigPath($ssh, ContainerDomain $domain): string
    {
        if (filled($domain->nginx_config_path)) {
            return (string) $domain->nginx_config_path;
        }

        return $this->resolveNginxConfigDir($ssh).'/'.$domain->domain.'.conf';
    }

    /**
     * A vhost is current when it carries this revision marker and matches the
     * expected mode (proxy vs parked). Leftover parked pages on an active
     * service must be rewritten back to proxy_pass.
     */
    public function vhostIsCurrent(string $config, ?bool $expectSuspended = null): bool
    {
        if (! str_contains($config, '# talksasa-vhost '.self::VHOST_REVISION)) {
            return false;
        }

        $isSuspendedVhost = str_contains($config, '# talksasa-edge-suspended');

        if ($expectSuspended === true) {
            return $isSuspendedVhost && $this->suspendedVhostHasCurrentDirectives($config);
        }

        if ($expectSuspended === false) {
            return ! $isSuspendedVhost && $this->proxyVhostHasCurrentDirectives($config);
        }

        return $isSuspendedVhost
            ? $this->suspendedVhostHasCurrentDirectives($config)
            : $this->proxyVhostHasCurrentDirectives($config);
    }

    public function proxyVhostHasCurrentDirectives(string $config): bool
    {
        return str_contains($config, 'client_max_body_size '.$this->clientMaxBodySize())
            && str_contains($config, 'proxy_buffer_size 128k')
            && str_contains($config, 'proxy_set_header Upgrade')
            && str_contains($config, 'proxy_set_header Connection "upgrade"')
            && str_contains($config, 'error_page 502 503 504');
    }

    public function suspendedVhostHasCurrentDirectives(string $config): bool
    {
        return str_contains($config, 'return 503')
            && str_contains($config, self::EDGE_PAGES_DIR)
            && str_contains($config, '/suspended.html');
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
            \Log::warning("Failed to check DNS for {$domain}: ".$e->getMessage());

            return false;
        }
    }

    private function isNginxInstalled(SSHService $ssh): bool
    {
        $result = trim($ssh->exec('if command -v nginx >/dev/null 2>&1; then echo yes; else echo no; fi'));

        return $result === 'yes';
    }

    private function resolveNginxConfigDir(SSHService $ssh): string
    {
        $result = trim($ssh->exec(
            'if [ -d /etc/nginx/sites-enabled ]; then echo /etc/nginx/sites-enabled; '.
            'elif [ -d /etc/nginx/conf.d ]; then echo /etc/nginx/conf.d; '.
            'else echo /etc/nginx/sites-enabled; fi'
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
            $ssh->exec('nginx -t 2>&1');

            return;
        } catch (Exception $directError) {
            // Fall back to sudo if direct command is not permitted.
            try {
                $ssh->exec('sudo -n nginx -t 2>&1');

                return;
            } catch (Exception $sudoError) {
                throw new Exception(
                    "nginx -t failed on node {$node->id}. ".
                    "Direct error: {$directError->getMessage()} | Sudo error: {$sudoError->getMessage()}"
                );
            }
        }
    }

    private function reloadNginx(SSHService $ssh, Node $node): void
    {
        try {
            $ssh->exec('nginx -s reload');

            return;
        } catch (Exception $directError) {
            try {
                $ssh->exec('sudo -n nginx -s reload');

                return;
            } catch (Exception $sudoError) {
                throw new Exception(
                    "nginx reload failed on node {$node->id}. ".
                    "Direct error: {$directError->getMessage()} | Sudo error: {$sudoError->getMessage()}"
                );
            }
        }
    }
}
