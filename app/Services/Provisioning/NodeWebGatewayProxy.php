<?php

namespace App\Services\Provisioning;

class NodeWebGatewayProxy
{
    public const BACKEND_SERVICE = 'backend';

    public const FRONTEND_SERVICE = 'frontend';

    public const EDGE_SERVICE = 'edge';

    public const EDGE_PORT = 8080;

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
