<?php

namespace App\Services\Provisioning;

class NodeWebGatewayProxy
{
    public const BACKEND_SERVICE = 'backend';

    public const FRONTEND_SERVICE = 'frontend';

    public const EDGE_SERVICE = 'edge';

    public const EDGE_PORT = 8080;

    /**
     * Set on the edge container to hold the site on a setup notice while the
     * API waits for values only the customer can supply. Carries the missing
     * variable names, comma separated, so the page can list them.
     */
    public const SETUP_REQUIRED_ENV = 'GATEWAY_SETUP_REQUIRED';

    public static function scriptPath(string $hostAppPath): string
    {
        return rtrim($hostAppPath, '/').'/.talksasa-node-gateway.js';
    }

    public static function containerScriptPath(): string
    {
        return '/gateway/server.js';
    }

    public static function frontendContainerName(string $backendName): string
    {
        return $backendName.'-frontend';
    }

    public static function edgeContainerName(string $backendName): string
    {
        return $backendName.'-edge';
    }

    /**
     * In-stack API origin for compose DNS. Browser apps keep relative /api via the edge;
     * build-time and container-to-container calls use this absolute URL.
     */
    public static function internalApiUrl(int $port = ContainerNodeWorkloadTopologyService::BACKEND_PORT): string
    {
        return 'http://'.self::BACKEND_SERVICE.':'.$port;
    }

    public static function viteConfigPath(string $hostAppPath): string
    {
        return rtrim($hostAppPath, '/').'/.talksasa-vite-nginx.conf';
    }

    public static function viteConfig(): string
    {
        return <<<'NGINX'
server {
    listen 3000;
    server_name _;
    root /usr/share/nginx/html;
    index index.html;
    server_tokens off;
    add_header X-Content-Type-Options nosniff always;
    add_header Referrer-Policy strict-origin-when-cross-origin always;
    location / {
        try_files $uri $uri/ /index.html;
    }
}
NGINX;
    }

    public static function scriptContents(): string
    {
        return <<<'JS'
'use strict';
const http = require('http');

const port = Number(process.env.GATEWAY_PORT || 8080);
const backendHost = process.env.BACKEND_HOST || 'backend';
const backendPort = Number(process.env.BACKEND_PORT || 8000);
const frontendHost = process.env.FRONTEND_HOST || 'frontend';
const frontendPort = Number(process.env.FRONTEND_PORT || 3000);

// Non-empty while the API is held for configuration. The app container is
// stopped in that state, so proxying anywhere would only serve 502s.
const setupRequired = String(process.env.GATEWAY_SETUP_REQUIRED || '').trim();
const setupVariables = setupRequired
  .split(',')
  .map((name) => name.trim())
  .filter(Boolean);

function escapeHtml(value) {
  return String(value).replace(/[&<>"']/g, (character) => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
  })[character]);
}

function setupPage() {
  const items = setupVariables.length
    ? '<ul>' + setupVariables.map((name) => '<li><code>' + escapeHtml(name) + '</code></li>').join('') + '</ul>'
    : '';
  return '<!doctype html><html lang="en"><head><meta charset="utf-8">'
    + '<meta name="viewport" content="width=device-width, initial-scale=1">'
    + '<title>Configuration required</title><style>'
    + ':root{color-scheme:light dark}'
    + 'body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;'
    + 'font:16px/1.6 system-ui,-apple-system,Segoe UI,Roboto,sans-serif;background:#0f172a;color:#e2e8f0;padding:24px}'
    + 'main{max-width:34rem}h1{font-size:1.5rem;margin:0 0 .75rem}'
    + 'p{margin:0 0 1rem;color:#94a3b8}ul{margin:0 0 1rem;padding-left:1.25rem}'
    + 'li{margin:.25rem 0}code{background:#1e293b;color:#f8fafc;padding:.15rem .4rem;border-radius:.25rem;font-size:.9em}'
    + '</style></head><body><main>'
    + '<h1>Configuration required</h1>'
    + '<p>This application is deployed but not started. It needs a few settings before it can run.</p>'
    + items
    + '<p>Add them under <strong>Environment</strong> in your hosting console, then apply. '
    + 'The site starts as soon as they are saved.</p>'
    + '</main></body></html>';
}

function serveSetupPage(res) {
  const body = setupPage();
  res.writeHead(503, {
    'content-type': 'text/html; charset=utf-8',
    'content-length': Buffer.byteLength(body),
    'cache-control': 'no-store',
    'retry-after': '120',
    'x-talksasa-upstream': 'setup',
  });
  res.end(body);
}

function targetsBackend(path) {
  const hasPrefix = (prefix) => path === prefix || path.startsWith(prefix + '/');
  return path === '/health' || path === '/healthz' || path === '/up'
    || hasPrefix('/api') || hasPrefix('/graphql')
    || hasPrefix('/socket.io') || hasPrefix('/ws');
}

function target(req) {
  const path = String(req.url || '/').split('?')[0] || '/';
  return targetsBackend(path)
    ? { hostname: backendHost, port: backendPort, role: 'backend' }
    : { hostname: frontendHost, port: frontendPort, role: 'frontend' };
}

const server = http.createServer((req, res) => {
  if (setupRequired) {
    serveSetupPage(res);
    return;
  }
  const upstreamTarget = target(req);
  const { role, ...connection } = upstreamTarget;
  const forwardedFor = [req.headers['x-forwarded-for'], req.socket.remoteAddress].filter(Boolean).join(', ');
  const upstream = http.request({
    ...connection,
    path: req.url,
    method: req.method,
    headers: {
      ...req.headers,
      'x-forwarded-for': forwardedFor,
      'x-forwarded-host': req.headers.host || '',
      'x-forwarded-proto': req.headers['x-forwarded-proto'] || 'http',
    },
  }, (upstreamResponse) => {
    res.writeHead(upstreamResponse.statusCode || 502, {
      ...upstreamResponse.headers,
      'x-talksasa-upstream': role,
    });
    upstreamResponse.pipe(res);
  });
  upstream.on('error', (error) => {
    if (!res.headersSent) {
      res.writeHead(502, {
        'content-type': 'text/plain; charset=utf-8',
        'x-talksasa-upstream': role,
      });
    }
    res.end('Bad gateway: ' + error.message);
  });
  upstream.setTimeout(4000, () => upstream.destroy(new Error('Upstream timeout')));
  req.pipe(upstream);
});

server.on('upgrade', (req, socket, head) => {
  if (setupRequired) {
    socket.destroy();
    return;
  }
  const upstreamTarget = target(req);
  const { role, ...connection } = upstreamTarget;
  const upstream = http.request({
    ...connection,
    path: req.url,
    method: req.method,
    headers: req.headers,
  });
  upstream.on('upgrade', (response, upstreamSocket, upstreamHead) => {
    socket.write('HTTP/1.1 101 Switching Protocols\r\n'
      + Object.entries(response.headers).map(([key, value]) => key + ': ' + value).join('\r\n')
      + '\r\n\r\n');
    if (head.length) upstreamSocket.write(head);
    if (upstreamHead.length) socket.write(upstreamHead);
    upstreamSocket.pipe(socket).pipe(upstreamSocket);
  });
  upstream.on('response', () => socket.destroy());
  upstream.on('error', () => socket.destroy());
  upstream.setTimeout(15000, () => upstream.destroy());
  upstream.end();
});

server.listen(port, '0.0.0.0', () => {
  console.log(`Talksasa Node gateway listening on :${port}`);
});
server.requestTimeout = 65000;
server.headersTimeout = 66000;
server.keepAliveTimeout = 5000;
for (const signal of ['SIGTERM', 'SIGINT']) {
  process.on(signal, () => server.close(() => process.exit(0)));
}
JS;
    }
}
