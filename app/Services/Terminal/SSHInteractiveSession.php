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
        string $workDir = '/app',
    ): void {
        $this->ssh->enablePTY();
        $this->ssh->setWindowSize($cols, $rows);

        $command = self::buildDockerShellCommand($containerName, $execUser, $workDir);

        $this->ssh->exec($command, function (string $output) use ($onOutput) {
            if ($output !== '') {
                $onOutput($output);
            }
        });
    }

    /**
     * Interactive docker exec used by the browser PTY.
     *
     * The SSH session already has a PTY. We put that PTY in raw/-echo so the
     * container bash is the only layer that echoes, then allocate a TTY inside
     * the container (`docker exec -it`) so prompts, tab completion, and editors work.
     */
    public static function buildDockerShellCommand(
        string $containerName,
        ?string $execUser = null,
        string $workDir = '/app',
    ): string {
        $preferredShell = preg_replace('/[^a-zA-Z0-9_\/.-]/', '', (string) config('terminal.pty.shell', '/bin/bash')) ?: '/bin/bash';
        $fallbackShell = preg_replace('/[^a-zA-Z0-9_\/.-]/', '', (string) config('terminal.pty.shell_fallback', '/bin/sh')) ?: '/bin/sh';
        $userFlag = $execUser !== null ? '-u '.escapeshellarg($execUser).' ' : '';
        $workDir = preg_replace('#^/+#', '/', trim($workDir)) ?: '/app';
        if ($workDir === '/' || ! str_starts_with($workDir, '/')) {
            $workDir = '/app';
        }

        // Interactive, not login: login shells often cd $HOME and ignore docker -w.
        $shellBootstrap = "if [ -x {$preferredShell} ]; then exec {$preferredShell} -i; elif [ -x {$fallbackShell} ]; then exec {$fallbackShell} -i; else exec sh -i; fi";

        $docker = sprintf(
            'docker exec -i -t %s-w %s -e TERM=xterm-256color -e COLORTERM=truecolor -e LANG=C.UTF-8 -e PS1=%s -e PATH=/usr/local/bin:/usr/bin:/bin -e HOME=/tmp -e HISTFILE=/tmp/.bash_history -e NPM_CONFIG_CACHE=/tmp/.npm -e npm_config_cache=/tmp/.npm %s /bin/sh -c %s',
            $userFlag,
            escapeshellarg($workDir),
            escapeshellarg('\\u@\\h:\\w\\$ '),
            escapeshellarg($containerName),
            escapeshellarg($shellBootstrap)
        );

        return 'stty raw -echo 2>/dev/null || true; '.$docker;
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
