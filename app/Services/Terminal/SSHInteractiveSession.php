<?php

namespace App\Services\Terminal;

use App\Exceptions\SSH\SSHConnectionException;
use App\Models\Node;
use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\Net\SSH2;

class SSHInteractiveSession
{
    private SSH2 $ssh;

    private bool $connected = false;

    private function __construct(private readonly Node $node)
    {
        $this->ssh = new SSH2($this->node->ip_address, (int) $this->node->ssh_port);
        $this->ssh->setTimeout(0);
    }

    public static function connect(Node $node): self
    {
        $session = new self($node);
        $session->authenticate();

        return $session;
    }

    public function startDockerShell(
        string $containerName,
        int $cols,
        int $rows,
        callable $onOutput,
        ?string $execUser = null,
    ): void {
        $this->ssh->enablePTY();
        $this->ssh->setWindowSize($cols, $rows);

        $preferredShell = preg_replace('/[^a-zA-Z0-9_\/.-]/', '', (string) config('terminal.pty.shell', '/bin/bash')) ?: '/bin/bash';
        $fallbackShell = preg_replace('/[^a-zA-Z0-9_\/.-]/', '', (string) config('terminal.pty.shell_fallback', '/bin/sh')) ?: '/bin/sh';
        $userFlag = $execUser !== null ? '-u '.escapeshellarg($execUser).' ' : '';

        // Prefer bash when present so history/completion feel like a normal terminal.
        $shellBootstrap = "if [ -x {$preferredShell} ]; then exec {$preferredShell} -l; elif [ -x {$fallbackShell} ]; then exec {$fallbackShell} -l; else exec sh -l; fi";

        $command = sprintf(
            'docker exec -i %s-w /app -e TERM=xterm-256color -e COLORTERM=truecolor -e PATH=/usr/local/bin:/usr/bin:/bin -e HOME=/tmp -e NPM_CONFIG_CACHE=/tmp/.npm -e npm_config_cache=/tmp/.npm %s /bin/sh -c %s',
            $userFlag,
            escapeshellarg($containerName),
            escapeshellarg($shellBootstrap)
        );

        $this->ssh->exec($command, function (string $output) use ($onOutput) {
            if ($output !== '') {
                $onOutput($output);
            }
        });
    }

    public function write(string $data): void
    {
        $this->ssh->write($data);
    }

    public function resize(int $cols, int $rows): void
    {
        if ($cols < 10 || $rows < 3) {
            return;
        }

        $this->ssh->setWindowSize($cols, $rows);
    }

    public function close(): void
    {
        if ($this->connected) {
            @$this->ssh->disconnect();
            $this->connected = false;
        }
    }

    private function authenticate(): void
    {
        $authenticated = false;

        if ($this->node->ssh_password) {
            $authenticated = @$this->ssh->login(
                $this->node->ssh_username,
                $this->node->ssh_password
            );
        }

        if (! $authenticated && $this->node->da_login_key) {
            $key = PublicKeyLoader::load($this->node->da_login_key);
            $authenticated = @$this->ssh->login($this->node->ssh_username, $key);
        }

        if (! $authenticated) {
            throw new SSHConnectionException($this->node->ip_address, 'SSH authentication failed');
        }

        $this->connected = true;
    }

    public function __destruct()
    {
        $this->close();
    }
}
