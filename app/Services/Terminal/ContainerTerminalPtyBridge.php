<?php

namespace App\Services\Terminal;

use App\Models\ContainerTerminalLog;
use App\Models\ContainerTerminalSession;
use Laravel\Reverb\Servers\Reverb\Connection as WebSocketConnection;

class ContainerTerminalPtyBridge
{
    private TerminalSecurityGuard $guard;

    private ?SSHInteractiveSession $ssh = null;

    private string $inputLineBuffer = '';

    public function __construct(
        private readonly ContainerTerminalSession $session,
        private readonly WebSocketConnection $connection,
    ) {
        $this->guard = new TerminalSecurityGuard;
    }

    public function start(int $cols, int $rows): void
    {
        $deployment = $this->session->deployment()->with('node')->first();

        if (! $deployment || ! $deployment->node) {
            throw new \RuntimeException('Deployment node is not available.');
        }

        if ($deployment->status !== 'running') {
            throw new \RuntimeException('Container is not running.');
        }

        $this->ssh = SSHInteractiveSession::connect($deployment->node);

        $this->session->loadMissing('service.product.containerTemplate');
        $templateSlug = $this->session->service?->product?->containerTemplate?->slug;
        $execUser = ContainerDockerExecUserResolver::execUser($templateSlug);

        $session = $this->session;
        $ws = $this->connection;

        $this->ssh->startDockerShell(
            $deployment->container_name,
            $cols,
            $rows,
            function (string $output) use ($session, $ws) {
                $ws->send($output);
                $session->extendExpiry();
            },
            $execUser,
        );

        $this->session->update([
            'last_activity_at' => now(),
        ]);
    }

    public function handleInput(string $payload): void
    {
        if ($this->tryHandleControlMessage($payload)) {
            return;
        }

        $forward = '';

        for ($i = 0, $len = strlen($payload); $i < $len; $i++) {
            $char = $payload[$i];

            if ($char === "\r" || $char === "\n") {
                $line = trim($this->inputLineBuffer);
                $this->inputLineBuffer = '';

                if ($line !== '') {
                    $validation = $this->guard->validate($line);
                    if (! $validation['allowed']) {
                        $this->logBlockedCommand($line, (string) $validation['reason']);
                        $this->connection->send("\r\n\x1b[31mCommand blocked: {$validation['reason']}\x1b[0m\r\n");
                        $this->ssh?->write("\x03");

                        continue;
                    }

                    $this->logAllowedCommand($line);
                }

                $forward .= $char;
            } else {
                $this->inputLineBuffer .= $char;
                $forward .= $char;
            }
        }

        if ($forward !== '') {
            $this->ssh?->write($forward);
            $this->session->extendExpiry();
        }
    }

    public function close(): void
    {
        $this->ssh?->close();
        $this->ssh = null;
    }

    private function tryHandleControlMessage(string $payload): bool
    {
        if (! str_starts_with(trim($payload), '{')) {
            return false;
        }

        $json = json_decode($payload, true);
        if (! is_array($json)) {
            return false;
        }

        if (($json['type'] ?? null) === 'resize') {
            $cols = (int) ($json['cols'] ?? config('terminal.pty.default_cols', 120));
            $rows = (int) ($json['rows'] ?? config('terminal.pty.default_rows', 30));
            $this->ssh?->resize($cols, $rows);

            return true;
        }

        return false;
    }

    private function logAllowedCommand(string $command): void
    {
        ContainerTerminalLog::create([
            'session_id' => $this->session->id,
            'user_id' => $this->session->user_id,
            'service_id' => $this->session->service_id,
            'command' => $command,
            'sanitized_command' => $command,
            'output' => '[pty session]',
            'exit_code' => 0,
            'cwd' => $this->session->cwd,
            'ip_address' => $this->session->ip_address,
            'is_blocked' => false,
        ]);

        $this->session->increment('command_count');
        $this->session->addToHistory($command);
    }

    private function logBlockedCommand(string $command, string $reason): void
    {
        ContainerTerminalLog::create([
            'session_id' => $this->session->id,
            'user_id' => $this->session->user_id,
            'service_id' => $this->session->service_id,
            'command' => $command,
            'sanitized_command' => $command,
            'output' => "Blocked: {$reason}",
            'exit_code' => 1,
            'cwd' => $this->session->cwd,
            'ip_address' => $this->session->ip_address,
            'is_blocked' => true,
            'block_reason' => $reason,
        ]);
    }
}
