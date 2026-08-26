<?php

namespace App\Services\Provisioning;

use App\Models\ContainerDeployment;
use App\Models\Service;
use App\Services\SSH\SSHService;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Yaml\Yaml;

/**
 * DirectAdmin Node apps often hardcode socketPath=/var/lib/mysql/mysql.sock.
 * Compose DB_HOST is ignored. Rewrite source and preload a mysql/mysql2 TCP shim.
 */
class NodeMysqlUnixSocketFixer
{
    public const SHIM_CONTAINER_PATH = '/app/.talksasa-mysql-tcp-shim.cjs';

    public const SHIM_RELATIVE_PATH = '.talksasa-mysql-tcp-shim.cjs';

    public function apply(SSHService $ssh, Service $service, ContainerDeployment $deployment, string $uniqueHost): string
    {
        $hostAppPath = app(ContainerAppDirectoryService::class)->hostAppPath($deployment);
        $this->installShim($ssh, $hostAppPath);
        $this->rewriteAppTree($ssh, $hostAppPath, $uniqueHost);

        return $uniqueHost;
    }

    public function shimSource(): string
    {
        return <<<'JS'
'use strict';

(function () {
  try {
    var Module = require('module');
    if (Module.__talksasaMysqlTcpHook) {
      return;
    }
    Module.__talksasaMysqlTcpHook = true;

    function target() {
      var host = String(process.env.TALKSASA_DB_DNS || process.env.DB_HOST || process.env.MYSQL_HOST || '').trim();
      if (!host || host.indexOf('/') !== -1 || /mysql\.sock/i.test(host)) {
        return null;
      }
      var port = parseInt(String(process.env.DB_PORT || process.env.MYSQL_PORT || '3306'), 10);
      return { host: host, port: isFinite(port) ? port : 3306 };
    }

    function looksLikeSocket(config) {
      if (!config || typeof config !== 'object') {
        return false;
      }
      var socket = String(config.socketPath || config.socket || '');
      var host = String(config.host || '');
      return Boolean(socket) || /mysql\.sock|\/var\/lib\/mysql|^localhost$|^127\.0\.0\.1$/i.test(host + ' ' + socket);
    }

    function patchConfig(config) {
      var to = target();
      if (!to) {
        return config;
      }
      if (typeof config === 'string') {
        if (!/mysql\.sock|\/var\/lib\/mysql|@localhost[:/]/i.test(config)) {
          return config;
        }
        try {
          var u = new URL(config);
          u.hostname = to.host;
          u.port = String(to.port);
          u.searchParams.delete('socket');
          u.searchParams.delete('socketPath');
          return u.toString();
        } catch (e) {
          return config;
        }
      }
      if (!looksLikeSocket(config)) {
        return config;
      }
      var next = Object.assign({}, config);
      delete next.socketPath;
      delete next.socket;
      next.host = to.host;
      next.port = next.port || to.port;
      if (next.dialectOptions && typeof next.dialectOptions === 'object') {
        next.dialectOptions = Object.assign({}, next.dialectOptions);
        delete next.dialectOptions.socketPath;
        delete next.dialectOptions.socket;
      }
      return next;
    }

    function wrap(mod) {
      if (!mod || mod.__talksasaMysqlTcp) {
        return mod;
      }
      mod.__talksasaMysqlTcp = true;
      ['createConnection', 'createPool', 'createPoolCluster'].forEach(function (fn) {
        if (typeof mod[fn] !== 'function') {
          return;
        }
        var orig = mod[fn];
        mod[fn] = function () {
          var args = Array.prototype.slice.call(arguments);
          if (args.length) {
            args[0] = patchConfig(args[0]);
          }
          return orig.apply(this, args);
        };
      });
      return mod;
    }

    var origLoad = Module._load;
    Module._load = function (request) {
      var loaded = origLoad.apply(this, arguments);
      if (request === 'mysql' || request === 'mysql2' || request === 'mysql2/promise') {
        return wrap(loaded);
      }
      return loaded;
    };
  } catch (e) {}
})();
JS;
    }

    public function installShim(SSHService $ssh, string $hostAppPath): void
    {
        $path = rtrim($hostAppPath, '/').'/'.self::SHIM_RELATIVE_PATH;
        $ssh->upload($this->shimSource(), $path);
    }

    /**
     * Insert the mysql TCP preload immediately before `exec` so npm install does not load it.
     */
    public function injectShimBeforeExec(string $command): string
    {
        if (str_contains($command, self::SHIM_CONTAINER_PATH)) {
            return $command;
        }

        $export = 'export NODE_OPTIONS="--require '.self::SHIM_CONTAINER_PATH.'"';
        if (preg_match('/\bexec\b/', $command) === 1) {
            return preg_replace('/\bexec\b/', $export.' && exec', $command, 1) ?? $command;
        }

        return rtrim($command).' && '.$export;
    }

    public function patchComposeCommand(string $yaml, string $serviceName): string
    {
        $compose = Yaml::parse($yaml);
        if (! is_array($compose) || ! is_array($compose['services'] ?? null)) {
            return $yaml;
        }

        $key = app(ContainerDeploymentService::class)->resolveComposeAppServiceKey($compose, $serviceName);
        if ($key === null || ! is_array($compose['services'][$key] ?? null)) {
            return $yaml;
        }

        $command = $compose['services'][$key]['command'] ?? null;
        if (is_array($command) && isset($command[2]) && is_string($command[2])) {
            $command[2] = $this->injectShimBeforeExec($command[2]);
            $compose['services'][$key]['command'] = $command;
        } elseif (is_string($command)) {
            $compose['services'][$key]['command'] = $this->injectShimBeforeExec($command);
        }

        return Yaml::dump($compose, 10, 2);
    }

    public function rewriteFileContent(string $content, string $uniqueHost): string
    {
        if ($content === '' || ! preg_match('/mysql\.sock|\/var\/lib\/mysql/i', $content)) {
            return $content;
        }

        $hostLiteral = str_replace(['\\', "'"], ['\\\\', "\\'"], $uniqueHost);
        $rewritten = preg_replace(
            '/([\'"]?(?:socketPath|socket)[\'"]?\s*:\s*)[\'"][^\'"]*mysql\.sock[^\'"]*[\'"]/i',
            '$1process.env.DB_HOST || \''.$hostLiteral.'\'',
            $content
        ) ?? $content;

        $rewritten = str_replace('localhost:/var/lib/mysql/mysql.sock', $uniqueHost, $rewritten);
        $rewritten = preg_replace(
            '/^(DB_SOCKET|MYSQL_UNIX_SOCKET|MYSQL_SOCKET|SOCKET)=.*$/m',
            '',
            $rewritten
        ) ?? $rewritten;
        $rewritten = preg_replace(
            '/[\'"]\/var\/lib\/mysql\/mysql\.sock[\'"]/',
            "'".$hostLiteral."'",
            $rewritten
        ) ?? $rewritten;
        $rewritten = str_replace('/var/lib/mysql/mysql.sock', $uniqueHost, $rewritten);

        $rewritten = preg_replace(
            '/\bhost\s*:\s*[\'"]localhost[\'"]/',
            'host: process.env.DB_HOST || \''.$hostLiteral.'\'',
            $rewritten
        ) ?? $rewritten;
        $rewritten = preg_replace(
            '/\bhost\s*:\s*[\'"]127\.0\.0\.1[\'"]/',
            'host: process.env.DB_HOST || \''.$hostLiteral.'\'',
            $rewritten
        ) ?? $rewritten;
        $rewritten = preg_replace(
            '/([\'"]host[\'"]\s*:\s*)[\'"]localhost[\'"]/',
            '$1"'.$uniqueHost.'"',
            $rewritten
        ) ?? $rewritten;

        return $rewritten;
    }

    public function rewriteAppTree(SSHService $ssh, string $hostAppPath, string $uniqueHost): int
    {
        $list = trim((string) $ssh->exec(
            'grep -RIl --exclude-dir=node_modules --exclude-dir=.git --exclude-dir=vendor '
            .'-E '.escapeshellarg('mysql\\.sock|/var/lib/mysql').' '
            .escapeshellarg($hostAppPath).' 2>/dev/null | head -50',
            30
        ));
        if ($list === '') {
            return 0;
        }

        $changed = 0;
        foreach (preg_split('/\r\n|\n|\r/', $list) ?: [] as $path) {
            $path = trim($path);
            if ($path === '' || str_ends_with($path, self::SHIM_RELATIVE_PATH)) {
                continue;
            }
            try {
                $original = (string) $ssh->exec('cat '.escapeshellarg($path), 20);
                $updated = $this->rewriteFileContent($original, $uniqueHost);
                if ($updated !== $original) {
                    $ssh->upload($updated, $path);
                    $changed++;
                }
            } catch (\Throwable $e) {
                Log::warning('Could not rewrite Node MySQL unix socket file', [
                    'path' => $path,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $changed;
    }
}
