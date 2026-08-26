<?php

namespace Tests\Unit\Provisioning;

use App\Services\Provisioning\NodeMysqlUnixSocketFixer;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class NodeMysqlUnixSocketFixerTest extends TestCase
{
    #[Test]
    public function it_rewrites_hardcoded_mysql_socketpath_to_tcp_host(): void
    {
        $source = <<<'JS'
const mysql = require('mysql');
const db = mysql.createConnection({
  host: 'localhost',
  user: 'sigtuna',
  password: 'secret',
  database: 'sigtuna',
  socketPath: '/var/lib/mysql/mysql.sock'
});
JS;

        $rewritten = app(NodeMysqlUnixSocketFixer::class)
            ->rewriteFileContent($source, 'user-67-service-179-nodejs-db');

        $this->assertStringNotContainsString('mysql.sock', $rewritten);
        $this->assertStringContainsString('user-67-service-179-nodejs-db', $rewritten);
        $this->assertStringContainsString('process.env.DB_HOST', $rewritten);
    }

    #[Test]
    public function it_rewrites_directadmin_env_socket_host(): void
    {
        $env = "DB_HOST=localhost:/var/lib/mysql/mysql.sock\nDB_SOCKET=/var/lib/mysql/mysql.sock\n";

        $rewritten = app(NodeMysqlUnixSocketFixer::class)
            ->rewriteFileContent($env, 'user-67-service-179-nodejs-db');

        $this->assertStringNotContainsString('mysql.sock', $rewritten);
        $this->assertStringContainsString('DB_HOST=user-67-service-179-nodejs-db', $rewritten);
        $this->assertStringNotContainsString('DB_SOCKET=', $rewritten);
    }

    #[Test]
    public function it_injects_the_shim_before_exec_and_not_before_npm_install(): void
    {
        $command = 'cd /app && export PORT=${PORT:-3000} && [ -f package.json ] && npm install --omit=dev && exec npm start';

        $injected = app(NodeMysqlUnixSocketFixer::class)->injectShimBeforeExec($command);

        $this->assertMatchesRegularExpression('/npm install --omit=dev && export NODE_OPTIONS=.* && exec npm start/', $injected);
        $this->assertStringContainsString(NodeMysqlUnixSocketFixer::SHIM_CONTAINER_PATH, $injected);
        $this->assertSame($injected, app(NodeMysqlUnixSocketFixer::class)->injectShimBeforeExec($injected));
    }

    #[Test]
    public function shim_source_is_an_iife_that_node_can_parse(): void
    {
        $source = app(NodeMysqlUnixSocketFixer::class)->shimSource();

        $this->assertStringContainsString('(function ()', $source);
        $this->assertStringContainsString("request === 'mysql2'", $source);
        $this->assertStringNotContainsString('return;', substr($source, 0, 80));
    }

    #[Test]
    public function it_patches_compose_command_list(): void
    {
        $yaml = <<<'YAML'
services:
  user-67-service-179-nodejs:
    container_name: user-67-service-179-nodejs
    command:
      - sh
      - -lc
      - cd /app && export PORT=${PORT:-3000} && npm install && exec npm start
  db:
    image: mysql:8.0
YAML;

        $patched = app(NodeMysqlUnixSocketFixer::class)
            ->patchComposeCommand($yaml, 'user-67-service-179-nodejs');

        $this->assertStringContainsString('--require /app/.talksasa-mysql-tcp-shim.cjs', $patched);
        $this->assertStringContainsString('exec npm start', $patched);
    }
}
