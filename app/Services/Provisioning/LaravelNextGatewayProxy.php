<?php

namespace App\Services\Provisioning;

/**
 * Tiny Node reverse proxy for Laravel + Next stacks.
 *
 * Sidecar mode (preferred): edge container proxies to Docker DNS names
 * `frontend` and `backend`. Legacy loopback mode kept for older single-container stacks.
 */
class LaravelNextGatewayProxy
{
    public const EDGE_INTERNAL_PORT = 8080;

    public const BACKEND_SERVICE = 'backend';

    public const FRONTEND_SERVICE = 'frontend';

    public const EDGE_SERVICE = 'edge';

    public const BACKEND_PORT = 8000;

    public const FRONTEND_PORT = 3000;

    public static function scriptPathInContainer(): string
    {
        return '/app/.talksasa-next-gateway.js';
    }

    /**
     * @return string Absolute host path for upload, or empty if no host app path.
     */
    public static function hostScriptPath(string $hostAppPath): string
    {
        return rtrim($hostAppPath, '/').'/.talksasa-next-gateway.js';
    }

    /**
     * Edge / gateway script. Uses BACKEND_HOST / FRONTEND_HOST when set (sidecar DNS).
     */
    public static function scriptContents(
        int $publicPort = self::EDGE_INTERNAL_PORT,
        int $laravelApiPort = self::BACKEND_PORT,
        int $nextPort = self::FRONTEND_PORT,
    ): string {
        // Keep this dependency-free (Node http only).
        return <<<JS
'use strict';
const http = require('http');
const publicPort = Number(process.env.GATEWAY_PUBLIC_PORT || {$publicPort});
const backendHost = process.env.BACKEND_HOST || '127.0.0.1';
const backendPort = Number(process.env.BACKEND_PORT || process.env.LARAVEL_API_PORT || {$laravelApiPort});
const frontendHost = process.env.FRONTEND_HOST || '127.0.0.1';
const frontendPort = Number(process.env.FRONTEND_PORT || process.env.NEXT_PORT || {$nextPort});

function isLaravelPath(urlPath) {
  return (
    urlPath === '/up' ||
    urlPath === '/health' ||
    urlPath === '/healthz' ||
    urlPath.startsWith('/api') ||
    urlPath.startsWith('/sanctum') ||
    urlPath.startsWith('/broadcasting') ||
    urlPath.startsWith('/storage') ||
    urlPath.startsWith('/livewire') ||
    urlPath.startsWith('/vendor/livewire') ||
    urlPath.startsWith('/_ignition') ||
    urlPath.startsWith('/horizon') ||
    urlPath.startsWith('/telescope')
  );
}

function proxy(req, res, hostname, port) {
  const headers = { ...req.headers };
  const opts = {
    hostname,
    port,
    path: req.url,
    method: req.method,
    headers,
  };
  const upstream = http.request(opts, (upRes) => {
    res.writeHead(upRes.statusCode || 502, upRes.headers);
    upRes.pipe(res);
  });
  upstream.on('error', (err) => {
    if (!res.headersSent) {
      res.writeHead(502, { 'content-type': 'text/plain; charset=utf-8' });
    }
    res.end('Bad gateway (' + hostname + ':' + port + '): ' + err.message);
  });
  req.pipe(upstream);
}

http.createServer((req, res) => {
  const urlPath = String(req.url || '/').split('?')[0] || '/';
  if (isLaravelPath(urlPath)) {
    proxy(req, res, backendHost, backendPort);
  } else {
    proxy(req, res, frontendHost, frontendPort);
  }
}).listen(publicPort, '0.0.0.0', () => {
  console.log(
    'Talksasa edge listening on :' + publicPort
    + ' (frontend ' + frontendHost + ':' + frontendPort
    + ', backend ' + backendHost + ':' + backendPort + ')'
  );
});
JS;
    }

    /**
     * Start command for the Next.js frontend sidecar (bind all interfaces).
     */
    public static function frontendComposeCommand(string $frontendDir, int $port = self::FRONTEND_PORT): array
    {
        // Stay running while waiting for a build on the shared volume (avoids crash-loop).
        $start = 'set -e; '
            .'export HOME=/tmp NPM_CONFIG_CACHE=/tmp/.npm npm_config_cache=/tmp/.npm; '
            .'mkdir -p /tmp/.npm; '
            .'cd '.escapeshellarg($frontendDir).'; '
            .'for i in $(seq 1 60); do '
            .'  if [ -f .next/standalone/server.js ]; then '
            .'    exec env HOSTNAME=0.0.0.0 PORT='.$port.' node .next/standalone/server.js; '
            .'  elif [ -f .next/standalone/frontend/server.js ]; then '
            .'    exec env HOSTNAME=0.0.0.0 PORT='.$port.' node .next/standalone/frontend/server.js; '
            .'  elif [ -d .next ] && { [ -x node_modules/.bin/next ] || [ -f node_modules/next/dist/bin/next ]; }; then '
            .'    break; '
            .'  fi; '
            .'  echo "Waiting for Next.js build artifacts in '.$frontendDir.' ($i/60)..."; '
            .'  sleep 5; '
            .'done; '
            .'if [ -x node_modules/.bin/next ]; then '
            .'  exec node_modules/.bin/next start -H 0.0.0.0 -p '.$port.'; '
            .'elif [ -f node_modules/next/dist/bin/next ]; then '
            .'  exec node node_modules/next/dist/bin/next start -H 0.0.0.0 -p '.$port.'; '
            .'else '
            .'  exec npx next start -H 0.0.0.0 -p '.$port.'; '
            .'fi';

        return ['sh', '-lc', $start];
    }

    /**
     * Start command for the Laravel backend sidecar.
     */
    public static function backendComposeCommand(string $documentRoot, int $port = self::BACKEND_PORT): array
    {
        $start = 'set -e; '
            .'BACKEND_DIR=$(dirname '.escapeshellarg($documentRoot).'); '
            .'if [ -f "$BACKEND_DIR/artisan" ]; then '
            .'  cd "$BACKEND_DIR" && exec php artisan serve --host=0.0.0.0 --port='.$port.'; '
            .'else '
            .'  exec php -S 0.0.0.0:'.$port.' -t '.escapeshellarg($documentRoot)
            .' '.escapeshellarg(rtrim($documentRoot, '/').'/index.php').'; '
            .'fi';

        return ['sh', '-lc', $start];
    }

    /**
     * @deprecated Single-container gateway; prefer sidecar stack via renderCompose.
     */
    public static function composeCommand(
        string $documentRoot,
        string $frontendDir,
        int $publicPort,
        int $laravelApiPort,
        int $nextPort = self::FRONTEND_PORT,
    ): array {
        $gateway = self::scriptPathInContainer();

        $start = 'set -e; '
            .'export HOME=/tmp NPM_CONFIG_CACHE=/tmp/.npm npm_config_cache=/tmp/.npm; '
            .'mkdir -p /tmp/.npm; '
            .'BACKEND_DIR=$(dirname '.escapeshellarg($documentRoot).'); '
            .'if [ -f "$BACKEND_DIR/artisan" ]; then '
            .'  (cd "$BACKEND_DIR" && php artisan serve --host=127.0.0.1 --port='.$laravelApiPort.') >/tmp/laravel-api.log 2>&1 & '
            .'else '
            .'  php -S 127.0.0.1:'.$laravelApiPort.' -t '.escapeshellarg($documentRoot)
            .' '.escapeshellarg(rtrim($documentRoot, '/').'/index.php')
            .' >/tmp/laravel-api.log 2>&1 & '
            .'fi; '
            .'cd '.escapeshellarg($frontendDir).'; '
            .'( '
            .'  if [ -f .next/standalone/server.js ]; then '
            .'    HOSTNAME=127.0.0.1 PORT='.$nextPort.' node .next/standalone/server.js; '
            .'  elif [ -f .next/standalone/frontend/server.js ]; then '
            .'    HOSTNAME=127.0.0.1 PORT='.$nextPort.' node .next/standalone/frontend/server.js; '
            .'  elif [ -x node_modules/.bin/next ]; then '
            .'    node_modules/.bin/next start -H 127.0.0.1 -p '.$nextPort.'; '
            .'  elif [ -f node_modules/next/dist/bin/next ]; then '
            .'    node node_modules/next/dist/bin/next start -H 127.0.0.1 -p '.$nextPort.'; '
            .'  else '
            .'    npx next start -H 127.0.0.1 -p '.$nextPort.'; '
            .'  fi '
            .') >/tmp/next.log 2>&1 & '
            .'export GATEWAY_PUBLIC_PORT='.$publicPort
            .' LARAVEL_API_PORT='.$laravelApiPort
            .' NEXT_PORT='.$nextPort
            .' BACKEND_HOST=127.0.0.1 FRONTEND_HOST=127.0.0.1'
            .' BACKEND_PORT='.$laravelApiPort
            .' FRONTEND_PORT='.$nextPort.'; '
            .'exec node '.escapeshellarg($gateway);

        return ['sh', '-lc', $start];
    }

    public static function frontendContainerName(string $appContainerName): string
    {
        return $appContainerName.'-frontend';
    }

    public static function edgeContainerName(string $appContainerName): string
    {
        return $appContainerName.'-edge';
    }
}
