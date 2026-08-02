<?php

namespace App\Services\Provisioning;

/**
 * Tiny Node reverse proxy for Laravel + Next monorepos in one container.
 * Public port → Next; /api, /sanctum, /storage, etc. → Laravel on loopback.
 */
class LaravelNextGatewayProxy
{
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

    public static function scriptContents(int $publicPort, int $laravelApiPort, int $nextPort): string
    {
        // Keep this dependency-free (Node http only).
        return <<<JS
'use strict';
const http = require('http');
const publicPort = Number(process.env.GATEWAY_PUBLIC_PORT || {$publicPort});
const laravelPort = Number(process.env.LARAVEL_API_PORT || {$laravelApiPort});
const nextPort = Number(process.env.NEXT_PORT || {$nextPort});

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

function proxy(req, res, port) {
  const headers = { ...req.headers, host: '127.0.0.1:' + port };
  const opts = {
    hostname: '127.0.0.1',
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
    res.end('Bad gateway (' + port + '): ' + err.message);
  });
  req.pipe(upstream);
}

http.createServer((req, res) => {
  const urlPath = String(req.url || '/').split('?')[0] || '/';
  const port = isLaravelPath(urlPath) ? laravelPort : nextPort;
  proxy(req, res, port);
}).listen(publicPort, '0.0.0.0', () => {
  console.log('Talksasa gateway listening on :' + publicPort + ' (Next :' + nextPort + ', Laravel :' + laravelPort + ')');
});
JS;
    }

    /**
     * Shell fragment that starts Laravel + Next + gateway on the public port.
     */
    public static function composeCommand(
        string $documentRoot,
        string $frontendDir,
        int $publicPort,
        int $laravelApiPort,
        int $nextPort = 3000,
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
            .'export GATEWAY_PUBLIC_PORT='.$publicPort.' LARAVEL_API_PORT='.$laravelApiPort.' NEXT_PORT='.$nextPort.'; '
            .'exec node '.escapeshellarg($gateway);

        return ['sh', '-lc', $start];
    }
}
