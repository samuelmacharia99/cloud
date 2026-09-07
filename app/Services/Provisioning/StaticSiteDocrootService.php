<?php

namespace App\Services\Provisioning;

use App\Services\SSH\SSHService;
use Symfony\Component\Yaml\Yaml;

/**
 * DirectAdmin static imports bind the host app dir over nginx's html root.
 * Vanilla nginx:alpine then 403s GET / when there is no index.html at that root
 * (nested public_html/dist, or a PHP index.php the static image will not run).
 * The same bind mount also publishes `.env` as a static file.
 */
class StaticSiteDocrootService
{
    /**
     * @return list<string>
     */
    public function nestedWebRootNames(): array
    {
        return ['public_html', 'public', 'dist', 'build', 'www', 'htdocs', 'html', 'web'];
    }

    public function nginxConfigContents(): string
    {
        return <<<'NGINX'
server {
    listen 80;
    server_name localhost;
    root /usr/share/nginx/html;
    index index.html index.htm;

    location ~ /\. {
        deny all;
        access_log off;
        log_not_found off;
    }

    location / {
        try_files $uri $uri/ /index.html;
    }
}
NGINX;
    }

    public function nginxConfigHostPath(string $containerName): string
    {
        return ContainerDeploymentService::CONTAINER_BASE_PATH.'/'.$containerName.'/nginx-static.conf';
    }

    public function nginxConfigVolumeMount(string $containerName): string
    {
        return $this->nginxConfigHostPath($containerName).':/etc/nginx/conf.d/default.conf:ro';
    }

    public function ensureNginxConfigFile(SSHService $ssh, string $containerName): void
    {
        $hostPath = $this->nginxConfigHostPath($containerName);
        $ssh->exec('mkdir -p '.escapeshellarg(dirname($hostPath)), 15);
        $ssh->upload($this->nginxConfigContents(), $hostPath);
    }

    /**
     * Lift nested DirectAdmin / SPA output so nginx sees index.html at the bind mount root.
     */
    public function flattenWebRootCommand(string $hostAppPath): string
    {
        $root = escapeshellarg(rtrim($hostAppPath, '/'));
        $names = implode(' ', array_map('escapeshellarg', $this->nestedWebRootNames()));

        return 'ROOT='.$root.'; '
            .'if [ ! -d "$ROOT" ]; then exit 0; fi; '
            .'has_html() { [ -f "$1/index.html" ] || [ -f "$1/index.htm" ]; }; '
            .'if has_html "$ROOT"; then exit 0; fi; '
            .'NESTED=""; '
            .'for d in '.$names.'; do '
            .'  if has_html "$ROOT/$d"; then NESTED="$ROOT/$d"; break; fi; '
            .'done; '
            .'if [ -z "$NESTED" ]; then '
            .'  kids=$(find "$ROOT" -mindepth 1 -maxdepth 1 -type d ! -name ".*" 2>/dev/null | wc -l); '
            .'  if [ "$kids" -eq 1 ]; then '
            .'    only=$(find "$ROOT" -mindepth 1 -maxdepth 1 -type d ! -name ".*" 2>/dev/null | head -n 1); '
            .'    if has_html "$only"; then NESTED="$only"; fi; '
            .'  fi; '
            .'fi; '
            .'if [ -z "$NESTED" ]; then exit 0; fi; '
            .'(cd "$NESTED" && tar cf - .) | (cd "$ROOT" && tar xf -)';
    }

    public function flattenWebRoot(SSHService $ssh, string $hostAppPath): void
    {
        $ssh->exec($this->flattenWebRootCommand($hostAppPath), 120);
    }

    public function patchComposeNginxConfigMount(string $yaml, string $containerName): string
    {
        $compose = Yaml::parse($yaml);
        if (! is_array($compose) || ! is_array($compose['services'] ?? null)) {
            return $yaml;
        }

        $key = app(ContainerDeploymentService::class)->resolveComposeAppServiceKey($compose, $containerName);
        if ($key === null || ! is_array($compose['services'][$key] ?? null)) {
            return $yaml;
        }

        $mount = $this->nginxConfigVolumeMount($containerName);
        $volumes = $compose['services'][$key]['volumes'] ?? [];
        if (! is_array($volumes)) {
            $volumes = [];
        }
        if (in_array($mount, $volumes, true)) {
            return $yaml;
        }

        $volumes[] = $mount;
        $compose['services'][$key]['volumes'] = $volumes;

        return Yaml::dump($compose, 10, 2);
    }

    public function persistNginxConfigOnCompose(
        SSHService $ssh,
        string $containerPath,
        string $containerName
    ): void {
        $this->ensureNginxConfigFile($ssh, $containerName);
        $composePath = $containerPath.'/docker-compose.yml';
        try {
            $yaml = trim((string) $ssh->exec('cat '.escapeshellarg($composePath), 15));
        } catch (\Throwable) {
            return;
        }
        if ($yaml === '') {
            return;
        }

        $patched = $this->patchComposeNginxConfigMount($yaml, $containerName);
        if ($patched === $yaml) {
            return;
        }

        $ssh->upload($patched, $composePath);
    }
}
